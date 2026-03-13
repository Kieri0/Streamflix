<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';

$uid         = $_SESSION['user_id'];
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

// Remove from watchlist — with transaction and audit log
if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
    $mid = (int)$_GET['remove'];
    beginTransaction($conn);
    try {
        $del = $conn->prepare("DELETE FROM Watchlist WHERE UserID=? AND MovieID=?");
        $del->bind_param("ii", $uid, $mid);
        if (!$del->execute()) throw new Exception($conn->error);
        $del->close();

        // TRANSACTION LOGGING
        auditLog($conn, 'Watchlist', 'DELETE', $mid, $performedBy,
            "UserID={$uid} removed MovieID={$mid} from watchlist");

        commitTransaction($conn);
    } catch (Exception $e) {
        rollbackTransaction($conn);
    }
}

// Add to watchlist — with transaction and audit log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_movie_id'])) {
    $mid = (int)$_POST['add_movie_id'];
    beginTransaction($conn);
    try {
        $ins = $conn->prepare(
            "INSERT IGNORE INTO Watchlist (UserID, MovieID) VALUES (?, ?)"
        );
        $ins->bind_param("ii", $uid, $mid);
        if (!$ins->execute()) throw new Exception($conn->error);
        $ins->close();

        // TRANSACTION LOGGING
        auditLog($conn, 'Watchlist', 'INSERT', $mid, $performedBy,
            "UserID={$uid} added MovieID={$mid} to watchlist");

        commitTransaction($conn);
    } catch (Exception $e) {
        rollbackTransaction($conn);
    }
}

$stmt = $conn->prepare("
    SELECT m.MovieID, m.Title, m.ThumbnailPath, m.ReleaseYear, m.Rating,
           GROUP_CONCAT(DISTINCT g.GenreName SEPARATOR ',') AS Genres
    FROM Watchlist w
    JOIN Movie m ON w.MovieID=m.MovieID
    LEFT JOIN MovieGenre mg ON m.MovieID=mg.MovieID
    LEFT JOIN Genre g ON mg.GenreID=g.GenreID
    WHERE w.UserID=?
    GROUP BY m.MovieID
    ORDER BY w.AddedDate DESC
");
$stmt->bind_param("i", $uid); $stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Watchlist – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>
<div class="section">
    <h2 class="section-title">My <span>Watchlist</span></h2>
    <?php if ($items): ?>
    <div class="movies-grid">
        <?php foreach ($items as $m):
            $genres = $m['Genres'] ? explode(',', $m['Genres']) : [];
        ?>
        <div class="movie-card" style="position:relative">
            <div class="movie-poster" onclick="location.href='movie.php?id=<?= $m['MovieID'] ?>'">
                <?php if ($m['ThumbnailPath']): ?><img src="uploads/thumbnails/<?= htmlspecialchars(basename($m['ThumbnailPath'])) ?>" alt="" loading="lazy"><?php else: ?><div class="no-thumb">[No Image]</div><?php endif; ?>
                <div class="play-overlay"><div class="play-btn-circle">PLAY</div></div>
            </div>
            <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($m['Title']) ?></div>
                <div class="movie-year"><?= $m['ReleaseYear'] ?></div>
                <div class="genre-tags"><?php foreach(array_slice($genres,0,2) as $g) echo '<span class="genre-tag">'.htmlspecialchars($g).'</span>'; ?></div>
                <a href="?remove=<?= $m['MovieID'] ?>" style="font-size:11px;color:#ff6b6b;display:inline-block;margin-top:6px" onclick="return confirm('Remove from watchlist?')">X Remove</a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:80px 0;color:var(--text-muted)">
        <div style="font-size:16px;margin-bottom:16px">No movies saved yet</div>
        <p>Your watchlist is empty.</p>
        <a href="movies.php" class="btn-primary" style="margin-top:16px;display:inline-flex">Browse Movies</a>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
