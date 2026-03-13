<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

// DELETE record — with transaction and audit log
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int) $_GET['delete'];
    beginTransaction($conn);
    try {
        $info = $conn->prepare("SELECT vh.*, u.FullName, m.Title FROM ViewingHistory vh JOIN User u ON vh.UserID=u.UserID JOIN Movie m ON vh.MovieID=m.MovieID WHERE vh.HistoryID=?");
        $info->bind_param("i", $did);
        $info->execute();
        $deleted = $info->get_result()->fetch_assoc();
        $info->close();

        $s = $conn->prepare("DELETE FROM ViewingHistory WHERE HistoryID=?");
        $s->bind_param("i", $did);
        if (!$s->execute()) throw new Exception($conn->error);
        $s->close();

        // TRANSACTION LOGGING
        auditLog($conn, 'ViewingHistory', 'DELETE', $did, $performedBy,
            "Admin deleted history record ID={$did} | User={$deleted['FullName']} | Movie={$deleted['Title']}");

        commitTransaction($conn);
        $msg = 'Record deleted.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error deleting record.';
    }
}

// ADD record — uses recordWatchSession() for locking + transaction + logging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid  = (int) $_POST['user_id'];
    $mid  = (int) $_POST['movie_id'];
    $dur  = (int) $_POST['watch_duration'];

    try {
        // STORED PROCEDURE EQUIVALENT: recordWatchSession() handles
        // LOCK IN SHARE MODE, transaction, rating recalc, and AuditLog
        recordWatchSession($conn, $uid, $mid, $dur, 0, $performedBy);
        $msg = 'Record added.';
    } catch (Exception $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}

// GROUP BY UserID+MovieID so each user+movie combo appears only once.
// MAX(WatchDate) brings the most recently watched to the top.
// MAX(WatchDuration) keeps the longest session. MIN(HistoryID) for the delete link.
$history = $conn->query(
    "SELECT MIN(vh.HistoryID) AS HistoryID,
            u.FullName,
            m.Title,
            MAX(vh.WatchDate)     AS WatchDate,
            MAX(vh.WatchDuration) AS WatchDuration,
            MAX(vh.UserRating)    AS UserRating
     FROM ViewingHistory vh
     JOIN User u  ON vh.UserID  = u.UserID
     JOIN Movie m ON vh.MovieID = m.MovieID
     GROUP BY vh.UserID, vh.MovieID
     ORDER BY WatchDate DESC"
)->fetch_all(MYSQLI_ASSOC);
$users   = $conn->query("SELECT UserID,FullName FROM User ORDER BY FullName")->fetch_all(MYSQLI_ASSOC);
$movies  = $conn->query("SELECT MovieID,Title FROM Movie ORDER BY Title")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Viewing History – Admin | StreamFlix</title><link rel="stylesheet" href="../css/style.css"></head>
<body><div class="admin-layout"><?php include __DIR__.'/sidebar.php';?><main class="admin-main">
<div class="admin-topbar"><h2>Viewing History</h2></div>
<?php if($msg):?><div class="alert-success"><?=htmlspecialchars($msg)?></div><?php endif;?>
<div class="form-section"><h3>Add Record</h3><form method="POST"><div class="form-row">
<div class="field"><label>User</label><select name="user_id" required><option value="">Select...</option><?php foreach($users as $u):?><option value="<?=$u['UserID']?>"><?=htmlspecialchars($u['FullName'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Movie</label><select name="movie_id" required><option value="">Select...</option><?php foreach($movies as $m):?><option value="<?=$m['MovieID']?>"><?=htmlspecialchars($m['Title'])?></option><?php endforeach;?></select></div>
<div class="field"><label>Duration (min)</label><input type="number" name="watch_duration" min="0"></div>
</div><button type="submit" class="btn-primary">Add Record</button></form></div>
<div class="table-wrapper"><table><thead><tr><th>ID</th><th>User</th><th>Movie</th><th>Last Watched</th><th>Duration</th><th>Rating</th><th>Action</th></tr></thead><tbody>
<?php foreach($history as $h):?><tr>
<td><?=$h['HistoryID']?></td>
<td><?=htmlspecialchars($h['FullName'])?></td>
<td><?=htmlspecialchars($h['Title'])?></td>
<td><?=htmlspecialchars($h['WatchDate'])?></td>
<td><?=$h['WatchDuration']??'—'?> min</td>
<td><?= $h['UserRating'] > 0 ? str_repeat('★', (int)$h['UserRating']) . str_repeat('☆', 5 - (int)$h['UserRating']) : '—' ?></td>
<td><a href="?delete=<?=$h['HistoryID']?>" class="btn-danger" onclick="return confirm('Delete?')">Delete</a></td>
</tr><?php endforeach;?>
</tbody></table></div></main></div></body></html>
