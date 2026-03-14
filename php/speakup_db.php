<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');           // Default XAMPP password is empty
define('DB_NAME', 'speakup_db');
define('DB_PORT', 3306);         // Default MySQL port

define('ADMIN_EMAILS', ['admin@speakup.edu']);

define('UPLOAD_IMAGE_DIR', __DIR__ . '/../uploads/complaints/');
define('UPLOAD_IMAGE_URL', 'uploads/complaints/');
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024); // 5 MB

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
if ($conn->connect_error) die('Database connection failed: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');

// Auto-create audit_log table if it doesn't exist.
// This means the system works even if the SQL file wasn't fully re-run.
$conn->query("CREATE TABLE IF NOT EXISTS audit_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ============================================================
// TRANSACTION HELPERS
// ============================================================

if (!function_exists('beginTransaction')) {
    function beginTransaction($conn) {
        $conn->autocommit(false);
        $conn->begin_transaction();
    }
}

if (!function_exists('commitTransaction')) {
    function commitTransaction($conn) {
        $conn->commit();
        $conn->autocommit(true);
    }
}

if (!function_exists('rollbackTransaction')) {
    function rollbackTransaction($conn) {
        $conn->rollback();
        $conn->autocommit(true);
    }
}

// ============================================================
// TRANSACTION LOGGING
// Writes every important action to audit_log for auditing.
// Called from every INSERT / UPDATE / DELETE in the system.
// ============================================================

function auditLog($conn, $table, $action, $recordId, $performedBy, $description) {
    $stmt = $conn->prepare(
        "INSERT INTO audit_log (table_name, action, record_id, performed_by, description)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        $stmt->bind_param("ssiss", $table, $action, $recordId, $performedBy, $description);
        @$stmt->execute(); // @ suppresses errors — logging must never crash the app
        $stmt->close();
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 1 — submitComplaint()
// Inserts a new complaint atomically with a shared user row lock.
// Prevents submitting complaints for a user that is being deleted
// (concurrency control).
//
// LOCKING:      SELECT LOCK IN SHARE MODE on users row
// TRANSACTION:  START → lock → insert → log → COMMIT
// LOGGING:      audit_log on success AND failure
// ============================================================

function submitComplaint($conn, $userId, $number, $category, $priority, $description, $isAnonymous = 0, $performedBy = 'SYSTEM') {
    beginTransaction($conn);
    try {
        // LOCKING: shared lock on the user row — readers allowed, DELETE blocked
        $lock = $conn->prepare("SELECT id FROM users WHERE id = ? LOCK IN SHARE MODE");
        $lock->bind_param("i", $userId);
        $lock->execute();
        if ($lock->get_result()->num_rows === 0) throw new Exception("User not found.");
        $lock->close();

        $ins = $conn->prepare(
            "INSERT INTO complaints
                (complaint_number, user_id, category, priority, description, is_anonymous)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("sisssi", $number, $userId, $category, $priority, $description, $isAnonymous);
        if (!$ins->execute()) throw new Exception($conn->error);
        $complaintId = $ins->insert_id;
        $ins->close();

        commitTransaction($conn);

        // TRANSACTION LOGGING — after commit so log failure never rolls back the complaint
        auditLog($conn, 'complaints', 'INSERT', $complaintId, $performedBy,
            "Complaint submitted | UserID={$userId} | #{$number} | Priority={$priority}");

        return $complaintId;

    } catch (Exception $e) {
        rollbackTransaction($conn);
        // TRANSACTION LOGGING: failure recorded after rollback
        auditLog($conn, 'complaints', 'PROCEDURE', $userId, $performedBy,
            "submitComplaint FAILED for UserID={$userId} | #{$number} | " . $e->getMessage());
        throw $e;
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 2 — updateComplaintStatus()
// Updates complaint status and admin note atomically with an
// exclusive row lock.
// Prevents two admin sessions from updating the same complaint
// simultaneously (race condition / concurrency control).
//
// LOCKING:      SELECT FOR UPDATE on complaints row
// TRANSACTION:  START → lock → update → log → COMMIT
// LOGGING:      audit_log on every status change
// ============================================================

function updateComplaintStatus($conn, $complaintId, $status, $adminNote = null, $performedBy = 'SYSTEM') {
    beginTransaction($conn);
    try {
        // LOCKING: exclusive lock — no other session can read or write this row until COMMIT
        $lock = $conn->prepare("SELECT complaint_number FROM complaints WHERE id = ? FOR UPDATE");
        $lock->bind_param("i", $complaintId);
        $lock->execute();
        $row = $lock->get_result()->fetch_assoc();
        if (!$row) throw new Exception("Complaint not found.");
        $number = $row['complaint_number'];
        $lock->close();

        $upd = $conn->prepare(
            "UPDATE complaints SET status = ?, admin_note = ? WHERE id = ?"
        );
        $upd->bind_param("ssi", $status, $adminNote, $complaintId);
        if (!$upd->execute()) throw new Exception($conn->error);
        $upd->close();

        commitTransaction($conn);

        // TRANSACTION LOGGING — after commit so log failure never rolls back the update
        auditLog($conn, 'complaints', 'UPDATE', $complaintId, $performedBy,
            "Status updated | #{$number} → {$status}");

        return true;

    } catch (Exception $e) {
        rollbackTransaction($conn);
        throw $e;
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 3 — archiveResolvedComplaints()
// Batch-archives all resolved or dismissed complaints.
// Designed to be called manually from the admin dashboard
// or via a server-side cron job.
//
// TRANSACTION: atomic batch update with full rollback on error
// LOGGING:     records how many complaints were archived
// ============================================================

function archiveResolvedComplaints($conn, $performedBy = 'SYSTEM') {
    beginTransaction($conn);
    try {
        $upd = $conn->prepare(
            "UPDATE complaints
             SET is_archived = 1
             WHERE status IN ('resolved', 'dismissed')
               AND is_archived = 0"
        );
        $upd->execute();
        $count = $conn->affected_rows;
        $upd->close();

        commitTransaction($conn);

        // TRANSACTION LOGGING — after commit so log failure never rolls back the archive
        auditLog($conn, 'complaints', 'PROCEDURE', null, $performedBy,
            "archiveResolvedComplaints: {$count} complaint(s) archived.");

        return $count;
    } catch (Exception $e) {
        rollbackTransaction($conn);
        throw $e;
    }
}

// ============================================================
// FILE UPLOAD HELPER
// ============================================================

if (!function_exists('handleUpload')) {
    function handleUpload($fieldName, $destDir, $allowed, $maxSize, &$error) {
        if (empty($_FILES[$fieldName]['name'])) return null;
        $file    = $_FILES[$fieldName];
        $tmpPath = $file['tmp_name'];
        $size    = $file['size'];
        $mime    = mime_content_type($tmpPath);
        if ($file['error'] !== UPLOAD_ERR_OK)    { $error = "Upload error for $fieldName.";             return null; }
        if (!in_array($mime, $allowed))           { $error = "Invalid file type for $fieldName: $mime."; return null; }
        if ($size > $maxSize)                     { $error = "File too large for $fieldName.";            return null; }
        $ext      = pathinfo(basename($file['name']), PATHINFO_EXTENSION);
        $safeName = uniqid('su_', true) . '.' . strtolower($ext);
        if (!move_uploaded_file($tmpPath, $destDir . $safeName)) { $error = "Failed to save $fieldName."; return null; }
        return $safeName;
    }
}
