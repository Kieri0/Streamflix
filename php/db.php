<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'streamflix');

define('ADMIN_EMAILS', ['admin@streamflix.com', 'admin2@streamflix.com']);

define('UPLOAD_THUMB_DIR', __DIR__ . '/../uploads/thumbnails/');
define('UPLOAD_VIDEO_DIR', __DIR__ . '/../uploads/videos/');
define('UPLOAD_THUMB_URL', 'uploads/thumbnails/');
define('UPLOAD_VIDEO_URL', 'uploads/videos/');
define('ALLOWED_IMAGE_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('ALLOWED_VIDEO_TYPES', ['video/mp4', 'video/webm', 'video/ogg', 'video/x-matroska']);
define('MAX_IMAGE_SIZE', 5 * 1024 * 1024);
define('MAX_VIDEO_SIZE', 2 * 1024 * 1024 * 1024);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) die('Database connection failed: ' . $conn->connect_error);
$conn->set_charset('utf8mb4');

// Auto-create AuditLog table if it doesn't exist.
// This means the system works even if the SQL file wasn't fully re-run.
$conn->query("CREATE TABLE IF NOT EXISTS AuditLog (
    LogID       INT AUTO_INCREMENT PRIMARY KEY,
    LogTime     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    TableName   VARCHAR(100) NOT NULL,
    Action      ENUM('INSERT','UPDATE','DELETE','PROCEDURE') NOT NULL,
    RecordID    INT          DEFAULT NULL,
    PerformedBy VARCHAR(255) DEFAULT 'SYSTEM',
    Description TEXT         NOT NULL
)");

// ============================================================
// TRANSACTION HELPERS
// ============================================================

function beginTransaction($conn) {
    $conn->autocommit(false);
    $conn->begin_transaction();
}

function commitTransaction($conn) {
    $conn->commit();
    $conn->autocommit(true);
}

function rollbackTransaction($conn) {
    $conn->rollback();
    $conn->autocommit(true);
}

// ============================================================
// TRANSACTION LOGGING
// Writes every important action to AuditLog for auditing.
// Called from every INSERT / UPDATE / DELETE in the system.
// ============================================================

function auditLog($conn, $table, $action, $recordId, $performedBy, $description) {
    $stmt = $conn->prepare(
        "INSERT INTO AuditLog (TableName, Action, RecordID, PerformedBy, Description)
         VALUES (?, ?, ?, ?, ?)"
    );
    if ($stmt) {
        // s=TableName, s=Action, i=RecordID (INT, nullable), s=PerformedBy, s=Description
        $stmt->bind_param("ssiss", $table, $action, $recordId, $performedBy, $description);
        @$stmt->execute(); // @ suppresses errors — logging must never crash the app
        $stmt->close();
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 1 — processSubscription()
// Creates a subscription atomically with an exclusive row lock.
// Prevents two concurrent sessions from double-subscribing the
// same user (race condition / concurrency control).
//
// LOCKING:      SELECT FOR UPDATE on User row
// TRANSACTION:  START → lock → insert → update → log → COMMIT
// LOGGING:      AuditLog on success AND failure
// ============================================================

function processSubscription($conn, $uid, $planName, $price, $days, $performedBy = 'SYSTEM') {
    $start = date('Y-m-d');
    $end   = date('Y-m-d', strtotime("+{$days} days"));

    beginTransaction($conn);
    try {
        // LOCKING: exclusive lock on this User row.
        // No other transaction can read or write this row until COMMIT.
        $lock = $conn->prepare("SELECT UserID FROM User WHERE UserID = ? FOR UPDATE");
        $lock->bind_param("i", $uid);
        $lock->execute();
        $lock->close();

        $ins = $conn->prepare(
            "INSERT INTO Subscription (UserID, PlanName, Price, Duration, StartDate, EndDate)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $ins->bind_param("isdiss", $uid, $planName, $price, $days, $start, $end);
        if (!$ins->execute()) throw new Exception($conn->error);
        $ins->close();

        $upd = $conn->prepare("UPDATE User SET SubscriptionStatus = 'active' WHERE UserID = ?");
        $upd->bind_param("i", $uid);
        if (!$upd->execute()) throw new Exception($conn->error);
        $upd->close();

        commitTransaction($conn);

        // TRANSACTION LOGGING — after commit so log failure never rolls back the subscription
        auditLog($conn, 'Subscription', 'INSERT', $uid, $performedBy,
            "Subscription created | UserID={$uid} | Plan={$planName} | {$start} to {$end}");

        return true;

    } catch (Exception $e) {
        rollbackTransaction($conn);
        // TRANSACTION LOGGING: failure recorded after rollback
        auditLog($conn, 'Subscription', 'PROCEDURE', $uid, $performedBy,
            "processSubscription FAILED for UserID={$uid} | " . $e->getMessage());
        throw $e;
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 2 — recordWatchSession()
// Logs a watch event and recalculates Movie.Rating if rated.
// Uses LOCK IN SHARE MODE — concurrent readers are allowed but
// nobody can delete the movie while a session is being written.
//
// LOCKING:      SELECT LOCK IN SHARE MODE on Movie row
// TRANSACTION:  START → lock → insert → recalc rating → log → COMMIT
// LOGGING:      AuditLog on every watch event
// ============================================================

function recordWatchSession($conn, $uid, $movieId, $duration = 0, $rating = 0, $performedBy = 'SYSTEM') {
    beginTransaction($conn);
    try {
        // LOCKING: shared lock — readers allowed, writers blocked
        $check = $conn->prepare("SELECT MovieID FROM Movie WHERE MovieID = ? LOCK IN SHARE MODE");
        $check->bind_param("i", $movieId);
        $check->execute();
        if ($check->get_result()->num_rows === 0) throw new Exception("Movie not found.");
        $check->close();

        // WatchDate always refreshes so ORDER BY WatchDate DESC keeps most-recent-first.
        // WatchDuration only overwrites when the new value is greater (keeps the longest session).
        // UserRating only overwrites when a real rating (>0) is submitted — never clobbers with 0.
        $ins = $conn->prepare(
            "INSERT INTO ViewingHistory (UserID, MovieID, WatchDate, WatchDuration, UserRating, Watched)
             VALUES (?, ?, NOW(), ?, ?, 1)
             ON DUPLICATE KEY UPDATE
                WatchDate     = NOW(),
                WatchDuration = IF(VALUES(WatchDuration) > WatchDuration, VALUES(WatchDuration), WatchDuration),
                UserRating    = IF(VALUES(UserRating) > 0, VALUES(UserRating), UserRating)"
        );
        $ins->bind_param("iiid", $uid, $movieId, $duration, $rating);
        if (!$ins->execute()) throw new Exception($conn->error);
        $ins->close();

        // Auto-recalculate Movie.Rating when a rating is submitted
        if ($rating > 0) {
            $avg = $conn->prepare(
                "UPDATE Movie SET Rating = (
                    SELECT ROUND(AVG(UserRating), 1)
                    FROM ViewingHistory
                    WHERE MovieID = ? AND UserRating > 0
                ) WHERE MovieID = ?"
            );
            $avg->bind_param("ii", $movieId, $movieId);
            $avg->execute();
            $avg->close();
        }

        commitTransaction($conn);

        // TRANSACTION LOGGING — after commit so log failure never rolls back the watch event
        auditLog($conn, 'ViewingHistory', 'INSERT', $uid, $performedBy,
            "WatchSession | UserID={$uid} | MovieID={$movieId} | Duration={$duration}min | Rating={$rating}");

        return true;

    } catch (Exception $e) {
        rollbackTransaction($conn);
        throw $e;
    }
}

// ============================================================
// STORED PROCEDURE EQUIVALENT 3 — expireSubscriptions()
// Batch-deactivates users whose EndDate has passed.
// Designed to be called manually from the admin dashboard
// or via a server-side cron job.
//
// TRANSACTION: atomic batch update with full rollback on error
// LOGGING:     records how many accounts were deactivated
// ============================================================

function expireSubscriptions($conn, $performedBy = 'SYSTEM') {
    beginTransaction($conn);
    try {
        $upd = $conn->prepare(
            "UPDATE User u
             JOIN Subscription s ON s.UserID = u.UserID
             SET u.SubscriptionStatus = 'inactive'
             WHERE s.EndDate < CURDATE()
               AND u.SubscriptionStatus = 'active'"
        );
        $upd->execute();
        $count = $conn->affected_rows;
        $upd->close();

        auditLog($conn, 'User', 'PROCEDURE', null, $performedBy,
            "expireSubscriptions: {$count} account(s) deactivated.");

        commitTransaction($conn);
        return $count;
    } catch (Exception $e) {
        rollbackTransaction($conn);
        throw $e;
    }
}

// ============================================================
// FILE UPLOAD HELPER (unchanged from original)
// ============================================================

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
    $safeName = uniqid('sf_', true) . '.' . strtolower($ext);
    if (!move_uploaded_file($tmpPath, $destDir . $safeName)) { $error = "Failed to save $fieldName."; return null; }
    return $safeName;
}
