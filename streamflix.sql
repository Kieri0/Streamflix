CREATE DATABASE IF NOT EXISTS streamflix;
USE streamflix;

CREATE TABLE IF NOT EXISTS User (
    UserID INT AUTO_INCREMENT PRIMARY KEY,
    FullName VARCHAR(100) NOT NULL,
    Email VARCHAR(100) NOT NULL UNIQUE,
    Password VARCHAR(255) NOT NULL,
    SubscriptionStatus ENUM('active','inactive','none') DEFAULT 'none'
);

CREATE TABLE IF NOT EXISTS Subscription (
    SubscriptionID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    PlanName VARCHAR(100),
    Price DECIMAL(10,2),
    Duration INT COMMENT 'Duration in days',
    StartDate DATE,
    EndDate DATE,
    FOREIGN KEY (UserID) REFERENCES User(UserID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Genre (
    GenreID INT AUTO_INCREMENT PRIMARY KEY,
    GenreName VARCHAR(100) NOT NULL,
    Description TEXT
);

CREATE TABLE IF NOT EXISTS Category (
    CategoryID INT AUTO_INCREMENT PRIMARY KEY,
    CategoryName VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS Movie (
    MovieID INT AUTO_INCREMENT PRIMARY KEY,
    Title VARCHAR(255) NOT NULL,
    ReleaseYear INT,
    Synopsis TEXT,
    ThumbnailPath VARCHAR(255),
    VideoPath VARCHAR(255),
    Rating DECIMAL(2,1) DEFAULT 0 COMMENT '0-5 star rating'
);

CREATE TABLE IF NOT EXISTS MovieGenre (
    MovieID INT NOT NULL,
    GenreID INT NOT NULL,
    PRIMARY KEY (MovieID, GenreID),
    FOREIGN KEY (MovieID) REFERENCES Movie(MovieID) ON DELETE CASCADE,
    FOREIGN KEY (GenreID) REFERENCES Genre(GenreID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS MovieCategory (
    MovieID INT NOT NULL,
    CategoryID INT NOT NULL,
    PRIMARY KEY (MovieID, CategoryID),
    FOREIGN KEY (MovieID) REFERENCES Movie(MovieID) ON DELETE CASCADE,
    FOREIGN KEY (CategoryID) REFERENCES Category(CategoryID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ViewingHistory (
    HistoryID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    MovieID INT NOT NULL,
    WatchDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    WatchDuration INT COMMENT 'Duration watched in minutes',
    UserRating TINYINT DEFAULT 0 COMMENT '0-5 stars given by user',
    Watched TINYINT(1) DEFAULT 1,
    FOREIGN KEY (UserID) REFERENCES User(UserID) ON DELETE CASCADE,
    FOREIGN KEY (MovieID) REFERENCES Movie(MovieID) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS Watchlist (
    WatchlistID INT AUTO_INCREMENT PRIMARY KEY,
    UserID INT NOT NULL,
    MovieID INT NOT NULL,
    AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_watchlist (UserID, MovieID),
    FOREIGN KEY (UserID) REFERENCES User(UserID) ON DELETE CASCADE,
    FOREIGN KEY (MovieID) REFERENCES Movie(MovieID) ON DELETE CASCADE
);

-- ============================================================
-- UPGRADING EXISTING DB? Run these instead:
-- ALTER TABLE Movie ADD COLUMN IF NOT EXISTS Rating DECIMAL(2,1) DEFAULT 0 AFTER VideoPath;
-- ALTER TABLE ViewingHistory ADD COLUMN IF NOT EXISTS UserRating TINYINT DEFAULT 0 AFTER WatchDuration;
-- ALTER TABLE ViewingHistory ADD COLUMN IF NOT EXISTS Watched TINYINT(1) DEFAULT 1 AFTER UserRating;
-- ALTER TABLE Subscription ADD COLUMN IF NOT EXISTS StartDate DATE AFTER Duration;
-- ALTER TABLE Subscription ADD COLUMN IF NOT EXISTS EndDate DATE AFTER StartDate;
-- CREATE TABLE IF NOT EXISTS Watchlist (WatchlistID INT AUTO_INCREMENT PRIMARY KEY, UserID INT NOT NULL, MovieID INT NOT NULL, AddedDate DATETIME DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY unique_watchlist (UserID, MovieID), FOREIGN KEY (UserID) REFERENCES User(UserID) ON DELETE CASCADE, FOREIGN KEY (MovieID) REFERENCES Movie(MovieID) ON DELETE CASCADE);
-- ============================================================

INSERT INTO User (FullName, Email, Password, SubscriptionStatus) VALUES
('Administrator', 'admin@streamflix.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'active');

INSERT INTO Genre (GenreName, Description) VALUES
('Action', 'High-energy films with exciting sequences'),
('Drama', 'Character-driven emotional narratives'),
('Comedy', 'Light-hearted and humorous films'),
('Horror', 'Scary and suspenseful films'),
('Sci-Fi', 'Science fiction and futuristic stories'),
('Animation', 'Animated feature films'),
('Adventure', 'Epic adventure stories'),
('Fantasy', 'Fantasy and magical worlds'),
('Thriller', 'Suspenseful and tense films');

INSERT INTO Category (CategoryName) VALUES
('Trending'), ('New Releases'), ('Top Rated'), ('My List'), ('Recommended');

-- ============================================================
-- AUDIT LOG TABLE (required by the PHP-layer implementation)
-- Run this once after the original schema is created.
-- ============================================================
CREATE TABLE IF NOT EXISTS AuditLog (
    LogID        INT AUTO_INCREMENT PRIMARY KEY,
    LogTime      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    TableName    VARCHAR(100) NOT NULL,
    Action       ENUM('INSERT','UPDATE','DELETE','PROCEDURE') NOT NULL,
    RecordID     INT          DEFAULT NULL,
    PerformedBy  VARCHAR(255) DEFAULT 'SYSTEM',
    Description  TEXT         NOT NULL
);

-- ============================================================
-- ADVANCED DATABASE FEATURES — PHP-INTEGRATED VERSION
-- Run this AFTER the base streamflix.sql tables above.
-- Only the AuditLog table is needed here — all procedure logic,
-- locking, and concurrency control live in php/db.php.
-- ============================================================

CREATE TABLE IF NOT EXISTS AuditLog (
    LogID        INT AUTO_INCREMENT PRIMARY KEY,
    LogTime      DATETIME     DEFAULT CURRENT_TIMESTAMP,
    TableName    VARCHAR(100) NOT NULL,
    Action       ENUM('INSERT','UPDATE','DELETE','PROCEDURE') NOT NULL,
    RecordID     INT          DEFAULT NULL,
    PerformedBy  VARCHAR(100) DEFAULT 'SYSTEM',
    Description  TEXT         NOT NULL,
    INDEX idx_logtime (LogTime),
    INDEX idx_table   (TableName),
    INDEX idx_action  (Action)
);


-- ============================================================
-- OBJECTIVE 1: TRIGGERS
-- Two triggers that fire automatically on database events.
-- ============================================================

DELIMITER $$

-- TRIGGER 1: After a new ViewingHistory row is inserted or updated,
-- automatically recalculate and update the Movie's average rating.
-- This fires WITHOUT being called — pure database-level automation.
DROP TRIGGER IF EXISTS trg_after_rating_insert$$
CREATE TRIGGER trg_after_rating_insert
AFTER INSERT ON ViewingHistory
FOR EACH ROW
BEGIN
    IF NEW.UserRating > 0 THEN
        UPDATE Movie
        SET Rating = (
            SELECT ROUND(AVG(UserRating), 1)
            FROM ViewingHistory
            WHERE MovieID = NEW.MovieID
              AND UserRating > 0
        )
        WHERE MovieID = NEW.MovieID;
    END IF;
END$$

-- TRIGGER 2: After a rating is updated in ViewingHistory,
-- recalculate Movie.Rating again to keep it current.
DROP TRIGGER IF EXISTS trg_after_rating_update$$
CREATE TRIGGER trg_after_rating_update
AFTER UPDATE ON ViewingHistory
FOR EACH ROW
BEGIN
    IF NEW.UserRating <> OLD.UserRating AND NEW.UserRating > 0 THEN
        UPDATE Movie
        SET Rating = (
            SELECT ROUND(AVG(UserRating), 1)
            FROM ViewingHistory
            WHERE MovieID = NEW.MovieID
              AND UserRating > 0
        )
        WHERE MovieID = NEW.MovieID;
    END IF;
END$$

-- TRIGGER 3: After a new Subscription is inserted,
-- automatically set the User's SubscriptionStatus to 'active'.
DROP TRIGGER IF EXISTS trg_after_subscription_insert$$
CREATE TRIGGER trg_after_subscription_insert
AFTER INSERT ON Subscription
FOR EACH ROW
BEGIN
    UPDATE User
    SET SubscriptionStatus = 'active'
    WHERE UserID = NEW.UserID;
END$$

DELIMITER ;


-- ============================================================
-- OBJECTIVE 2: STORED PROCEDURES
-- Three reusable procedures that perform multi-step operations.
-- ============================================================

DELIMITER $$

-- PROCEDURE 1: ProcessSubscription
-- Creates a subscription and activates the user atomically.
-- Uses TRANSACTION + locking to prevent race conditions.
DROP PROCEDURE IF EXISTS ProcessSubscription$$
CREATE PROCEDURE ProcessSubscription(
    IN p_uid      INT,
    IN p_plan     VARCHAR(100),
    IN p_price    DECIMAL(10,2),
    IN p_days     INT,
    IN p_by       VARCHAR(255)
)
BEGIN
    DECLARE v_start DATE DEFAULT CURDATE();
    DECLARE v_end   DATE DEFAULT DATE_ADD(CURDATE(), INTERVAL p_days DAY);
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
        VALUES ('Subscription', 'PROCEDURE', p_uid, p_by,
                CONCAT('ProcessSubscription FAILED for UserID=', p_uid));
    END;

    START TRANSACTION;

        -- LOCKING: exclusive lock on User row prevents double-subscription
        SELECT UserID FROM User WHERE UserID = p_uid FOR UPDATE;

        INSERT INTO Subscription (UserID, PlanName, Price, Duration, StartDate, EndDate)
        VALUES (p_uid, p_plan, p_price, p_days, v_start, v_end);

        UPDATE User SET SubscriptionStatus = 'active' WHERE UserID = p_uid;

    COMMIT;

    -- TRANSACTION LOGGING: after commit so log failure never rolls back the subscription
    INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
    VALUES ('Subscription', 'INSERT', p_uid, p_by,
            CONCAT('Subscription created | UserID=', p_uid,
                   ' | Plan=', p_plan,
                   ' | ', v_start, ' to ', v_end));
END$$


-- PROCEDURE 2: RecordWatchSession
-- Logs a watch event and recalculates Movie.Rating if rated.
-- Uses TRANSACTION to ensure watch + rating update are atomic.
DROP PROCEDURE IF EXISTS RecordWatchSession$$
CREATE PROCEDURE RecordWatchSession(
    IN p_uid      INT,
    IN p_movieId  INT,
    IN p_duration INT,
    IN p_rating   TINYINT,
    IN p_by       VARCHAR(255)
)
BEGIN
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        -- LOCKING: shared lock — multiple users can watch simultaneously
        -- but nobody can delete the movie while sessions are being written
        SELECT MovieID FROM Movie WHERE MovieID = p_movieId LOCK IN SHARE MODE;

        INSERT INTO ViewingHistory (UserID, MovieID, WatchDate, WatchDuration, UserRating, Watched)
        VALUES (p_uid, p_movieId, NOW(), p_duration, p_rating, 1)
        ON DUPLICATE KEY UPDATE
            WatchDate     = NOW(),
            WatchDuration = p_duration,
            UserRating    = p_rating;

        -- Rating recalculation is handled automatically by trg_after_rating_insert
        -- (trigger fires on the INSERT above)

    COMMIT;

    INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
    VALUES ('ViewingHistory', 'INSERT', p_uid, p_by,
            CONCAT('WatchSession | UserID=', p_uid,
                   ' | MovieID=', p_movieId,
                   ' | Duration=', p_duration,
                   ' | Rating=', p_rating));
END$$


-- PROCEDURE 3: ExpireSubscriptions
-- Batch-deactivates all users whose subscription EndDate has passed.
-- Designed to run on a schedule or triggered manually.
DROP PROCEDURE IF EXISTS ExpireSubscriptions$$
CREATE PROCEDURE ExpireSubscriptions(
    IN p_by VARCHAR(255)
)
BEGIN
    DECLARE v_count INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
    END;

    START TRANSACTION;

        UPDATE User u
        JOIN Subscription s ON s.UserID = u.UserID
        SET u.SubscriptionStatus = 'inactive'
        WHERE s.EndDate < CURDATE()
          AND u.SubscriptionStatus = 'active';

        SET v_count = ROW_COUNT();

    COMMIT;

    INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
    VALUES ('User', 'PROCEDURE', NULL, p_by,
            CONCAT('ExpireSubscriptions: ', v_count, ' account(s) deactivated.'));
END$$

DELIMITER ;


-- ============================================================
-- OBJECTIVE 3: TRANSACTION LOGGING
-- AuditLog table already defined above.
-- Below are example direct SQL transaction + log patterns.
-- ============================================================

-- Example: Logging a user registration using pure SQL transaction
-- (This is what register.php replicates in PHP)
--
-- START TRANSACTION;
--     INSERT INTO User (FullName, Email, Password, SubscriptionStatus)
--     VALUES ('Juan Dela Cruz', 'juan@email.com', 'hashed_pw', 'none');
--     INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
--     VALUES ('User', 'INSERT', LAST_INSERT_ID(), 'juan@email.com',
--             'New user registered: Juan Dela Cruz (juan@email.com)');
-- COMMIT;


-- ============================================================
-- OBJECTIVE 4: LOCKING MECHANISMS
-- Demonstrated inside the stored procedures above.
-- Reference queries shown here for documentation.
-- ============================================================

-- Exclusive lock (FOR UPDATE) — used in ProcessSubscription:
--   SELECT UserID FROM User WHERE UserID = 5 FOR UPDATE;
--   Blocks all other sessions from reading or writing UserID=5
--   until the transaction COMMITs.

-- Shared lock (LOCK IN SHARE MODE) — used in RecordWatchSession:
--   SELECT MovieID FROM Movie WHERE MovieID = 3 LOCK IN SHARE MODE;
--   Allows multiple sessions to hold this lock simultaneously.
--   Blocks DELETE or UPDATE on MovieID=3 until all locks are released.


-- ============================================================
-- OBJECTIVE 5: CONCURRENCY CONTROL
-- Demonstrated inside the stored procedures above.
-- Reference transaction pattern shown here.
-- ============================================================

-- Full concurrency control pattern used throughout the system:
--
-- START TRANSACTION;                          -- disable autocommit
--     SELECT ... FOR UPDATE;                  -- acquire lock
--     INSERT INTO ...;                        -- step 1
--     UPDATE ...;                             -- step 2
-- COMMIT;                                     -- save all steps atomically
--
-- If any step fails, MySQL rolls back automatically via EXIT HANDLER.
--
-- Isolation level used for subscription creation:
--   SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
--   Prevents phantom reads where two concurrent sessions both see
--   no existing subscription and both proceed to create one.
