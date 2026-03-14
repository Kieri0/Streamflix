CREATE DATABASE IF NOT EXISTS speakup_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE speakup_db;

-- ── USERS ─────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id          INT          NOT NULL AUTO_INCREMENT,
    full_name   VARCHAR(255) NOT NULL,
    email       VARCHAR(255) NOT NULL,
    password    VARCHAR(255) NOT NULL,       -- store bcrypt hash via PHP password_hash()
    phone       VARCHAR(30)  DEFAULT NULL,
    role        ENUM('user','admin') NOT NULL DEFAULT 'user',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── COMPLAINTS ────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS complaints (
    id               INT          NOT NULL AUTO_INCREMENT,
    complaint_number VARCHAR(30)  NOT NULL,
    user_id          INT          NOT NULL,
    category         VARCHAR(100) NOT NULL,
    priority         VARCHAR(20)  NOT NULL,
    status           VARCHAR(30)  NOT NULL DEFAULT 'pending',
    description      TEXT         NOT NULL,
    is_anonymous     TINYINT(1)   NOT NULL DEFAULT 0,
    image_path       VARCHAR(255) DEFAULT NULL,
    admin_note       TEXT         DEFAULT NULL,
    is_archived      TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_number (complaint_number),
    KEY idx_user_id  (user_id),
    KEY idx_status   (status),
    KEY idx_priority (priority),
    KEY idx_category (category),
    CONSTRAINT fk_complaints_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── UPLOADED FILES ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS uploaded_files (
    id            INT          NOT NULL AUTO_INCREMENT,
    complaint_id  INT          NOT NULL,
    file_name     VARCHAR(255) NOT NULL,
    file_path     VARCHAR(255) NOT NULL,
    file_type     VARCHAR(50)  NOT NULL,       -- mime type (image/jpeg, image/png, etc.)
    file_size     BIGINT       NOT NULL,       -- file size in bytes
    uploaded_by   INT          NOT NULL,       -- user_id who uploaded
    is_referenced TINYINT(1)   NOT NULL DEFAULT 0,  -- if it's the main reference image
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_complaint_id (complaint_id),
    KEY idx_uploaded_by  (uploaded_by),
    CONSTRAINT fk_uploaded_files_complaint
        FOREIGN KEY (complaint_id) REFERENCES complaints (id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_uploaded_files_user
        FOREIGN KEY (uploaded_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── AUDIT LOG ─────────────────────────────────────────────────
-- Required by the PHP-layer implementation (speakup_db.php).
-- Every important action is recorded here for admin review.
CREATE TABLE IF NOT EXISTS audit_log (
    id           INT          NOT NULL AUTO_INCREMENT,
    log_time     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    table_name   VARCHAR(100) NOT NULL,
    action       ENUM('INSERT','UPDATE','DELETE','PROCEDURE') NOT NULL,
    record_id    INT          DEFAULT NULL,
    performed_by VARCHAR(255) DEFAULT 'SYSTEM',
    description  TEXT         NOT NULL,
    PRIMARY KEY (id),
    KEY idx_log_time   (log_time),
    KEY idx_table_name (table_name),
    KEY idx_action     (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- TRIGGERS
-- Two triggers that fire automatically on database events.
-- ============================================================

DELIMITER $$

-- TRIGGER 1: After a complaint is inserted, write an audit entry.
-- This fires WITHOUT being called — pure database-level automation.
DROP TRIGGER IF EXISTS trg_after_complaint_insert$$
CREATE TRIGGER trg_after_complaint_insert
AFTER INSERT ON complaints
FOR EACH ROW
BEGIN
    INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
    VALUES (
        'complaints', 'INSERT', NEW.id, NEW.user_id,
        CONCAT('Complaint filed | #', NEW.complaint_number,
               ' | Category=', NEW.category,
               ' | Priority=', NEW.priority)
    );
END$$

-- TRIGGER 2: After a complaint's status is updated, record the change.
DROP TRIGGER IF EXISTS trg_after_complaint_update$$
CREATE TRIGGER trg_after_complaint_update
AFTER UPDATE ON complaints
FOR EACH ROW
BEGIN
    IF NEW.status <> OLD.status THEN
        INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
        VALUES (
            'complaints', 'UPDATE', NEW.id, 'SYSTEM',
            CONCAT('Status changed | #', NEW.complaint_number,
                   ' | ', OLD.status, ' → ', NEW.status)
        );
    END IF;
END$$

DELIMITER ;


-- ============================================================
-- STORED PROCEDURES
-- ============================================================

DELIMITER $$

-- PROCEDURE 1: SubmitComplaint
-- Inserts a new complaint atomically.
-- Uses TRANSACTION to ensure the insert and audit log are written together.
DROP PROCEDURE IF EXISTS SubmitComplaint$$
CREATE PROCEDURE SubmitComplaint(
    IN p_user_id      INT,
    IN p_number       VARCHAR(30),
    IN p_category     VARCHAR(100),
    IN p_priority     VARCHAR(20),
    IN p_description  TEXT,
    IN p_anonymous    TINYINT(1),
    IN p_by           VARCHAR(255)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
        VALUES ('complaints', 'PROCEDURE', p_user_id, p_by,
                CONCAT('SubmitComplaint FAILED for UserID=', p_user_id,
                       ' | #', p_number));
    END;

    START TRANSACTION;

        -- LOCKING: shared lock — verify user exists before inserting
        SELECT id FROM users WHERE id = p_user_id LOCK IN SHARE MODE;

        INSERT INTO complaints
            (complaint_number, user_id, category, priority, description, is_anonymous)
        VALUES
            (p_number, p_user_id, p_category, p_priority, p_description, p_anonymous);

    COMMIT;

    -- TRANSACTION LOGGING: after commit so log failure never rolls back the complaint
    INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
    VALUES ('complaints', 'INSERT', LAST_INSERT_ID(), p_by,
            CONCAT('Complaint submitted | UserID=', p_user_id,
                   ' | #', p_number,
                   ' | Priority=', p_priority));
END$$


-- PROCEDURE 2: UpdateComplaintStatus
-- Updates complaint status and admin note atomically.
-- Uses exclusive row lock to prevent concurrent status conflicts.
DROP PROCEDURE IF EXISTS UpdateComplaintStatus$$
CREATE PROCEDURE UpdateComplaintStatus(
    IN p_complaint_id INT,
    IN p_status       VARCHAR(30),
    IN p_admin_note   TEXT,
    IN p_by           VARCHAR(255)
)
BEGIN
    DECLARE v_number VARCHAR(30);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        -- LOCKING: exclusive lock — prevents two admins from updating the same complaint
        SELECT complaint_number INTO v_number
        FROM complaints
        WHERE id = p_complaint_id FOR UPDATE;

        UPDATE complaints
        SET status     = p_status,
            admin_note = p_admin_note
        WHERE id = p_complaint_id;

    COMMIT;

    INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
    VALUES ('complaints', 'UPDATE', p_complaint_id, p_by,
            CONCAT('Status updated | #', v_number, ' → ', p_status));
END$$


-- PROCEDURE 3: ArchiveResolvedComplaints
-- Batch-archives all complaints with status 'resolved' or 'dismissed'.
-- Designed to run on a schedule or triggered manually by an admin.
DROP PROCEDURE IF EXISTS ArchiveResolvedComplaints$$
CREATE PROCEDURE ArchiveResolvedComplaints(
    IN p_by VARCHAR(255)
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        UPDATE complaints
        SET is_archived = 1
        WHERE status IN ('resolved', 'dismissed')
          AND is_archived = 0;

        SET v_count = ROW_COUNT();

    COMMIT;

    INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
    VALUES ('complaints', 'PROCEDURE', NULL, p_by,
            CONCAT('ArchiveResolvedComplaints: ', v_count, ' complaint(s) archived.'));
END$$

DELIMITER ;


-- ============================================================
-- TRANSACTION LOGGING
-- AuditLog table already defined above.
-- Below is an example direct SQL transaction + log pattern.
-- ============================================================

-- Example: Registering a new user with pure SQL transaction
-- (This is what register.php replicates in PHP)
--
-- START TRANSACTION;
--     INSERT INTO users (full_name, email, password, role)
--     VALUES ('Juan Dela Cruz', 'juan@student.edu', 'hashed_pw', 'user');
--     INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
--     VALUES ('users', 'INSERT', LAST_INSERT_ID(), 'juan@student.edu',
--             'New user registered: Juan Dela Cruz (juan@student.edu)');
-- COMMIT;


-- ============================================================
-- LOCKING MECHANISMS
-- Demonstrated inside the stored procedures above.
-- Reference queries shown here for documentation.
-- ============================================================

-- Exclusive lock (FOR UPDATE) — used in UpdateComplaintStatus:
--   SELECT id FROM complaints WHERE id = 5 FOR UPDATE;
--   Blocks all other sessions from reading or writing that complaint row
--   until the transaction COMMITs.

-- Shared lock (LOCK IN SHARE MODE) — used in SubmitComplaint:
--   SELECT id FROM users WHERE id = 3 LOCK IN SHARE MODE;
--   Allows multiple sessions to hold this lock simultaneously.
--   Blocks DELETE or UPDATE on that user row until all locks are released.


-- ============================================================
-- CONCURRENCY CONTROL
-- Full pattern used throughout the procedures above.
-- ============================================================

-- START TRANSACTION;                          -- disable autocommit
--     SELECT ... FOR UPDATE;                  -- acquire exclusive lock
--     INSERT INTO ...;                        -- step 1
--     UPDATE ...;                             -- step 2
-- COMMIT;                                     -- save all steps atomically
--
-- If any step fails, MySQL rolls back automatically via EXIT HANDLER.


-- ── SEED: Default Admin Account ───────────────────────────────
-- Password: Admin@123  (bcrypt hash generated by PHP password_hash())
-- Change this password immediately after first login!
INSERT IGNORE INTO users (full_name, email, password, role)
VALUES (
    'Administrator',
    'admin@speakup.edu',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);

-- ── SAMPLE DATA (optional — remove for production) ────────────
INSERT IGNORE INTO users (full_name, email, password, role) VALUES
    ('Juan Dela Cruz',  'juan@student.edu',  '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user'),
    ('Maria Santos',    'maria@student.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'user');

INSERT IGNORE INTO complaints
    (complaint_number, user_id, category, priority, status, description, is_anonymous, admin_note)
VALUES
    ('#COM-2026-01', 2, 'Bullying', 'high', 'in_review',
     'A classmate has been repeatedly making offensive remarks during group activities. This has been ongoing for three weeks and is affecting my ability to participate in class.',
     0, 'Under investigation. We have spoken with the parties involved.'),
    ('#COM-2026-02', 3, 'Facility', 'medium', 'pending',
     'The water fountain on the second floor has been broken for over two months. Students have been going down to the first floor just to get water, which wastes time between classes.',
     0, NULL),
    ('#COM-2026-03', 2, 'Academic', 'critical', 'pending',
     'A teacher publicly shared a student''s failing grade in front of the entire class without consent. This is a clear violation of student privacy and has caused significant distress.',
     1, NULL);
