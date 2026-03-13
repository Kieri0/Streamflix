<?php require_once __DIR__ . '/auth_guard.php';

// ── MUST BE FIRST: create AuditLog if it doesn't exist ──────
// This runs before ANY function call so nothing can crash on a missing table.
$conn->query("CREATE TABLE IF NOT EXISTS AuditLog (
    LogID       INT AUTO_INCREMENT PRIMARY KEY,
    LogTime     DATETIME     DEFAULT CURRENT_TIMESTAMP,
    TableName   VARCHAR(100) NOT NULL,
    Action      ENUM('INSERT','UPDATE','DELETE','PROCEDURE') NOT NULL,
    RecordID    INT          DEFAULT NULL,
    PerformedBy VARCHAR(255) DEFAULT 'SYSTEM',
    Description TEXT         NOT NULL
)");

$performedBy = $_SESSION['email'] ?? 'SYSTEM';
$expireMsg   = '';

// Admin can manually trigger subscription expiry (stored procedure equivalent)
if (isset($_POST['run_expire'])) {
    try {
        $count     = expireSubscriptions($conn, $performedBy);
        $expireMsg = "Done — {$count} subscription(s) expired.";
    } catch (Exception $e) {
        $expireMsg = "Error running expiry: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Dashboard – Admin | StreamFlix</title><link rel="stylesheet" href="../css/style.css"></head>
<body>
<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <main class="admin-main">
        <div class="admin-topbar">
            <h2>Dashboard</h2>
            <span class="nav-user">Admin: <?= htmlspecialchars($_SESSION['full_name']) ?></span>
        </div>

        <?php
        $totalUsers  = $conn->query("SELECT COUNT(*) FROM User")->fetch_row()[0];
        $totalMovies = $conn->query("SELECT COUNT(*) FROM Movie")->fetch_row()[0];
        $totalSubs   = $conn->query("SELECT COUNT(*) FROM Subscription")->fetch_row()[0];
        $totalViews  = $conn->query("SELECT COUNT(*) FROM ViewingHistory")->fetch_row()[0];
        $withVideo   = $conn->query("SELECT COUNT(*) FROM Movie WHERE VideoPath IS NOT NULL AND VideoPath != ''")->fetch_row()[0];
        $withThumb   = $conn->query("SELECT COUNT(*) FROM Movie WHERE ThumbnailPath IS NOT NULL AND ThumbnailPath != ''")->fetch_row()[0];
        $totalLogs   = $conn->query("SELECT COUNT(*) FROM AuditLog")->fetch_row()[0];
        ?>

        <div class="stats-grid">
            <div class="stat-card"><div class="stat-label">Users</div><div class="stat-value"><?= $totalUsers ?></div></div>
            <div class="stat-card"><div class="stat-label">Movies</div><div class="stat-value"><?= $totalMovies ?></div></div>
            <div class="stat-card"><div class="stat-label">With Video</div><div class="stat-value"><?= $withVideo ?></div></div>
            <div class="stat-card"><div class="stat-label">With Thumbnail</div><div class="stat-value"><?= $withThumb ?></div></div>
            <div class="stat-card"><div class="stat-label">Subscriptions</div><div class="stat-value"><?= $totalSubs ?></div></div>
            <div class="stat-card"><div class="stat-label">Views Logged</div><div class="stat-value"><?= $totalViews ?></div></div>
            <div class="stat-card"><div class="stat-label">Audit Entries</div><div class="stat-value"><?= $totalLogs ?></div></div>
        </div>

        <!-- STORED PROCEDURE: Expire Subscriptions -->
        <div class="page-header"><h3>Maintenance</h3></div>
        <div class="form-section" style="padding:16px 24px">
            <?php if ($expireMsg): ?><div class="alert-success" style="margin-bottom:12px"><?= htmlspecialchars($expireMsg) ?></div><?php endif; ?>
            <form method="POST" style="display:inline">
                <button type="submit" name="run_expire" class="btn-primary" onclick="return confirm('Run subscription expiry now?')">
                    Run Expire Subscriptions
                </button>
            </form>
            <p style="font-size:12px;color:var(--text-muted);margin-top:8px">
                Marks users with passed EndDate as inactive. Logs result to AuditLog.
            </p>
        </div>

        <!-- Recent Users -->
        <div class="page-header"><h3>Recent Users</h3><a href="users.php" class="btn-secondary">View All</a></div>
        <div class="table-wrapper"><table>
            <thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th></tr></thead>
            <tbody>
            <?php $rows=$conn->query("SELECT UserID,FullName,Email,SubscriptionStatus FROM User ORDER BY UserID DESC LIMIT 5");
            while($r=$rows->fetch_assoc()): ?>
            <tr>
                <td><?=$r['UserID']?></td>
                <td><?=htmlspecialchars($r['FullName'])?></td>
                <td><?=htmlspecialchars($r['Email'])?></td>
                <td><span class="badge badge-<?=$r['SubscriptionStatus']?>"><?=strtoupper($r['SubscriptionStatus'])?></span></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>

        <!-- Recent Movies -->
        <div class="page-header"><h3>Recent Movies</h3><a href="movies.php" class="btn-secondary">View All</a></div>
        <div class="table-wrapper"><table>
            <thead><tr><th>ID</th><th>Thumbnail</th><th>Title</th><th>Year</th><th>Video</th></tr></thead>
            <tbody>
            <?php $mrows=$conn->query("SELECT MovieID,Title,ReleaseYear,ThumbnailPath,VideoPath FROM Movie ORDER BY MovieID DESC LIMIT 5");
            while($m=$mrows->fetch_assoc()): ?>
            <tr>
                <td><?=$m['MovieID']?></td>
                <td><?= $m['ThumbnailPath'] ? '<img src="../uploads/thumbnails/'.htmlspecialchars(basename($m['ThumbnailPath'])).'\" class=\"thumb-preview\">' : '<span style="color:#555">—</span>' ?></td>
                <td><?=htmlspecialchars($m['Title'])?></td>
                <td><?=$m['ReleaseYear']?></td>
                <td><?= $m['VideoPath'] ? '<span style="color:var(--green);font-size:12px">&#9679; Uploaded</span>' : '<span style="color:#555;font-size:12px">None</span>' ?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>

        <!-- TRANSACTION LOG / AUDIT LOG TABLE -->
        <div class="page-header"><h3>Recent Audit Log</h3></div>
        <div class="table-wrapper"><table>
            <thead><tr><th>Time</th><th>Table</th><th>Action</th><th>Record ID</th><th>Performed By</th><th>Description</th></tr></thead>
            <tbody>
            <?php $logs=$conn->query("SELECT * FROM AuditLog ORDER BY LogTime DESC LIMIT 20");
            while($log=$logs->fetch_assoc()): ?>
            <tr>
                <td style="font-size:11px;white-space:nowrap"><?=htmlspecialchars($log['LogTime'])?></td>
                <td><?=htmlspecialchars($log['TableName'])?></td>
                <td><span class="badge badge-active" style="font-size:10px"><?=htmlspecialchars($log['Action'])?></span></td>
                <td><?=$log['RecordID'] ?? '—'?></td>
                <td style="font-size:11px"><?=htmlspecialchars($log['PerformedBy'])?></td>
                <td style="font-size:11px"><?=htmlspecialchars($log['Description'])?></td>
            </tr>
            <?php endwhile; ?>
            </tbody>
        </table></div>

    </main>
</div>
</body></html>
