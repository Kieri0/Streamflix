<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$msgType     = '';
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

// Flash message from redirect after delete
if (isset($_GET['msg']) && $_GET['msg'] === 'deleted') {
    $msg     = 'Movie deleted.';
    $msgType = 'success';
}

// DELETE movie — with transaction, FOR UPDATE lock, and audit log
// LOCKING: Uses SELECT FOR UPDATE to acquire an exclusive lock on the Movie row.
// This blocks the delete if any concurrent session holds a LOCK IN SHARE MODE
// on the same row (e.g., recordWatchSession() in watch.php).
// The delete only proceeds when no active watch session holds that shared lock.
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int) $_GET['delete'];

    // ── CONCURRENCY GUARD ────────────────────────────────────────────────────
    // HTTP requests are stateless — a PHP LOCK IN SHARE MODE lasts milliseconds,
    // not for the entire time a user is watching a video. Instead we check whether
    // any user started a watch session for this movie in the last 10 minutes.
    // If so, we treat the movie as "in use" and refuse the delete.
    $activeCheck = $conn->prepare(
        "SELECT COUNT(*) AS cnt FROM ViewingHistory
         WHERE MovieID = ? AND WatchDate >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)"
    );
    $activeCheck->bind_param("i", $did);
    $activeCheck->execute();
    $activeRow = $activeCheck->get_result()->fetch_assoc();
    $activeCheck->close();

    if ($activeRow['cnt'] > 0) {
        $msg     = 'Cannot delete: this movie has active viewers (session started in the last 10 minutes). Please try again shortly.';
        $msgType = 'error';
    } else {
    beginTransaction($conn);
    try {
        // LOCKING: acquire exclusive lock on this Movie row.
        // If any watch session holds LOCK IN SHARE MODE, this FOR UPDATE waits.
        $lockStmt = $conn->prepare("SELECT MovieID, Title, ThumbnailPath, VideoPath FROM Movie WHERE MovieID = ? FOR UPDATE");
        $lockStmt->bind_param("i", $did);
        if (!$lockStmt->execute()) throw new Exception($conn->error);
        $row = $lockStmt->get_result()->fetch_assoc();
        $lockStmt->close();

        if (!$row) {
            rollbackTransaction($conn);
            $msg = 'Movie not found.'; $msgType = 'error';
        } else {
            if ($row['ThumbnailPath']) @unlink(UPLOAD_THUMB_DIR . basename($row['ThumbnailPath']));
            if ($row['VideoPath'])     @unlink(UPLOAD_VIDEO_DIR . basename($row['VideoPath']));

            $s = $conn->prepare("DELETE FROM Movie WHERE MovieID=?");
            $s->bind_param("i", $did);
            if (!$s->execute()) throw new Exception($conn->error);
            $s->close();

            // TRANSACTION LOGGING
            auditLog($conn, 'Movie', 'DELETE', $did, $performedBy,
                "Admin deleted movie: {$row['Title']} (ID={$did})");

            commitTransaction($conn);
            $msg     = 'Movie deleted.';
            $msgType = 'success';
        }
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg     = 'Could not delete movie — it may be in use. Please try again.';
        $msgType = 'error';
        error_log("Movie delete failed for ID={$did}: " . $e->getMessage());
    }
    } // end active-session guard else
    // Redirect to clean URL so ?delete= is gone from address bar
    if ($msgType === 'success') {
        header('Location: movies.php?msg=deleted');
        exit;
    }
}

// ADD movie — with transaction, locking, and audit log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title    = trim($_POST['title'] ?? '');
    $year     = (int) ($_POST['release_year'] ?? 0);
    $synopsis = trim($_POST['synopsis'] ?? '');
    $rating   = min(5, max(0, (float) ($_POST['rating'] ?? 0)));

    if (!$title || !$year) {
        $msg = 'Title and Release Year are required.'; $msgType = 'error';
    } else {
        $uploadError = '';
        $thumbName   = handleUpload('thumbnail', UPLOAD_THUMB_DIR, ALLOWED_IMAGE_TYPES, MAX_IMAGE_SIZE, $uploadError);
        if ($uploadError) { $msg = $uploadError; $msgType = 'error'; }

        if (!$msg) {
            $uploadError = '';
            $videoName   = handleUpload('video', UPLOAD_VIDEO_DIR, ALLOWED_VIDEO_TYPES, MAX_VIDEO_SIZE, $uploadError);
            if ($uploadError) { $msg = $uploadError; $msgType = 'error'; }
        }

        if (!$msg) {
            beginTransaction($conn);
            try {
                $ins = $conn->prepare("INSERT INTO Movie (Title, ReleaseYear, Synopsis, ThumbnailPath, VideoPath, Rating) VALUES (?,?,?,?,?,?)");
                $ins->bind_param("sisssd", $title, $year, $synopsis, $thumbName, $videoName, $rating);
                if (!$ins->execute()) throw new Exception($conn->error);
                $newId = $conn->insert_id;
                $ins->close();

                foreach (($_POST['genres'] ?? []) as $gid) {
                    $gs = $conn->prepare("INSERT IGNORE INTO MovieGenre (MovieID,GenreID) VALUES (?,?)");
                    $gs->bind_param("ii", $newId, $gid);
                    if (!$gs->execute()) throw new Exception($conn->error);
                    $gs->close();
                }
                foreach (($_POST['categories'] ?? []) as $cid) {
                    $cs = $conn->prepare("INSERT IGNORE INTO MovieCategory (MovieID,CategoryID) VALUES (?,?)");
                    $cs->bind_param("ii", $newId, $cid);
                    if (!$cs->execute()) throw new Exception($conn->error);
                    $cs->close();
                }

                // TRANSACTION LOGGING
                auditLog($conn, 'Movie', 'INSERT', $newId, $performedBy,
                    "Admin added movie: {$title} ({$year}) | ID={$newId}");

                commitTransaction($conn);
                $msg     = 'Movie "' . htmlspecialchars($title) . '" added successfully.';
                $msgType = 'success';

            } catch (Exception $e) {
                rollbackTransaction($conn);
                if ($thumbName) @unlink(UPLOAD_THUMB_DIR . $thumbName);
                if ($videoName) @unlink(UPLOAD_VIDEO_DIR . $videoName);
                $msg     = 'Database error: ' . $e->getMessage();
                $msgType = 'error';
            }
        }
    }
}

$movies     = $conn->query("SELECT m.MovieID, m.Title, m.ReleaseYear, m.ThumbnailPath, m.VideoPath, m.Rating, GROUP_CONCAT(DISTINCT g.GenreName SEPARATOR ', ') AS Genres FROM Movie m LEFT JOIN MovieGenre mg ON m.MovieID=mg.MovieID LEFT JOIN Genre g ON mg.GenreID=g.GenreID GROUP BY m.MovieID ORDER BY m.MovieID DESC")->fetch_all(MYSQLI_ASSOC);
$genres     = $conn->query("SELECT GenreID, GenreName FROM Genre ORDER BY GenreName")->fetch_all(MYSQLI_ASSOC);
$categories = $conn->query("SELECT CategoryID, CategoryName FROM Category ORDER BY CategoryName")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Movies – Admin | StreamFlix</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="admin-layout">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-topbar"><h2>Movies</h2></div>
            <?php if ($msg): ?><div class="alert-<?= $msgType === 'success' ? 'success' : 'error' ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <div class="form-section">
                <h3>Add New Movie</h3>
                <form method="POST" action="movies.php" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="field"><label>Title *</label><input type="text" name="title" placeholder="Enter movie title"></div>
                        <div class="field"><label>Release Year *</label><input type="number" name="release_year" placeholder="e.g. 2024" min="1900" max="2100"></div>
                    </div>
                    <div class="field"><label>Synopsis</label><textarea name="synopsis" placeholder="Write a short description..."></textarea></div>
                    <div class="form-row">
                        <div class="field"><label>Rating (0-5)</label><input type="number" name="rating" placeholder="e.g. 4.5" min="0" max="5" step="0.1"></div>
                        <div class="field"></div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label>Thumbnail / Poster Image</label>
                            <input type="file" name="thumbnail" accept="image/jpeg,image/png,image/webp,image/gif">
                            <div class="file-hint">JPG, PNG, WEBP &bull; Max 5 MB</div>
                            <div id="thumbPreviewWrap" style="margin-top:8px;display:none"><img id="thumbPreview" style="height:90px;border-radius:4px;object-fit:cover"></div>
                        </div>
                        <div class="field">
                            <label>Video File</label>
                            <input type="file" name="video" id="videoInput" accept="video/mp4,video/webm,video/ogg,video/x-matroska">
                            <div class="file-hint">MP4, WEBM, MKV &bull; Max 1500 MB</div>
                            <div id="videoNameDisplay" style="margin-top:6px;font-size:12px;color:var(--yellow)"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="field">
                            <label>Genres</label>
                            <div class="toggle-group">
                                <?php foreach ($genres as $g): ?>
                                <label class="toggle-btn"><input type="checkbox" name="genres[]" value="<?= $g['GenreID'] ?>"><?= htmlspecialchars($g['GenreName']) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="field">
                            <label>Categories</label>
                            <div class="toggle-group">
                                <?php foreach ($categories as $c): ?>
                                <label class="toggle-btn"><input type="checkbox" name="categories[]" value="<?= $c['CategoryID'] ?>"><?= htmlspecialchars($c['CategoryName']) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"> Add Movie</button>
                </form>
            </div>
            <div class="page-header"><h3>All Movies (<?= count($movies) ?>)</h3></div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>ID</th><th>Poster</th><th>Title</th><th>Year</th><th>Rating</th><th>Video</th><th>Genres</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php if ($movies): ?>
                        <?php foreach ($movies as $m): ?>
                        <tr>
                            <td><?= $m['MovieID'] ?></td>
                            <td><?= $m['ThumbnailPath'] ? '<img src="../uploads/thumbnails/'.htmlspecialchars(basename($m['ThumbnailPath'])).'" class="thumb-preview" onerror="this.style.display=\'none\'">' : '<span style="color:#444;font-size:20px">No Image</span>' ?></td>
                            <td><strong><?= htmlspecialchars($m['Title']) ?></strong></td>
                            <td><?= $m['ReleaseYear'] ?></td>
                            <td><?= $m['Rating'] ? number_format($m['Rating'], 1) . ' stars' : '—' ?></td>
                            <td><?= $m['VideoPath'] ? '<span style="color:var(--green);font-size:12px"> Yes</span>' : '<span style="color:#555;font-size:12px">None</span>' ?></td>
                            <td><?= htmlspecialchars($m['Genres'] ?? '—') ?></td>
                            <td><a href="?delete=<?= $m['MovieID'] ?>" class="btn-danger" onclick="return confirm('Delete \'<?= addslashes($m['Title']) ?>\'?')">Delete</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:30px">No movies added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
    <script>
        document.querySelector('input[name="thumbnail"]').addEventListener('change', function () {
            const wrap = document.getElementById('thumbPreviewWrap');
            const img  = document.getElementById('thumbPreview');
            if (this.files && this.files[0]) { img.src = URL.createObjectURL(this.files[0]); wrap.style.display = 'block'; }
            else { wrap.style.display = 'none'; }
        });
        document.getElementById('videoInput').addEventListener('change', function () {
            document.getElementById('videoNameDisplay').textContent = this.files[0] ? '[v] ' + this.files[0].name : '';
        });
    </script>
</body>
</html>
