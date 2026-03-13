<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';
$uid = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT MAX(vh.HistoryID) AS HistoryID,
           MAX(vh.WatchDate)     AS WatchDate,
           MAX(vh.UserRating)    AS UserRating,
           MAX(vh.Watched)       AS Watched,
           m.MovieID, m.Title, m.ReleaseYear, m.ThumbnailPath, m.Rating,
           GROUP_CONCAT(DISTINCT g.GenreName SEPARATOR ',') AS Genres
    FROM ViewingHistory vh
    JOIN Movie m ON vh.MovieID = m.MovieID
    LEFT JOIN MovieGenre mg ON m.MovieID = mg.MovieID
    LEFT JOIN Genre g ON mg.GenreID = g.GenreID
    WHERE vh.UserID = ?
    GROUP BY m.MovieID
    ORDER BY WatchDate DESC
");
$stmt->bind_param("i", $uid); $stmt->execute();
$history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View History – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>

<div class="section">
    <h2 class="section-title">Movie <span>History</span></h2>

    <?php if ($history): ?>
    <div class="movies-grid">
        <?php foreach ($history as $h):
            $genres = $h['Genres'] ? explode(',', $h['Genres']) : [];
            $rating = (int)($h['UserRating'] ?: $h['Rating']);
        ?>
        <div class="history-card" onclick="location.href='movie.php?id=<?= $h['MovieID'] ?>'">
            <div class="history-poster">
                <?php if ($h['ThumbnailPath']): ?>
                <img src="uploads/thumbnails/<?= htmlspecialchars(basename($h['ThumbnailPath'])) ?>" alt="<?= htmlspecialchars($h['Title']) ?>" loading="lazy">
                <?php else: ?>
                <div class="no-thumb" style="height:225px;display:flex;align-items:center;justify-content:center;font-size:40px;color:#333">[No Image]</div>
                <?php endif; ?>
                <?php if ($h['Watched']): ?>
                <div class="history-watched-badge">✓ Watched</div>
                <?php endif; ?>
            </div>
            <div class="history-body">
                <div class="history-title"><?= htmlspecialchars($h['Title']) ?></div>
                <div class="genre-tags" style="margin-bottom:6px">
                    <?php foreach (array_slice($genres,0,2) as $g): ?>
                    <span class="genre-tag"><?= htmlspecialchars($g) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="stars">
                    <?php for($i=1;$i<=5;$i++) echo '<span class="star '.($i<=$rating?'filled':'').'">&#9733;</span>'; ?>
                </div>
                <div style="font-size:10px;color:var(--text-muted);margin-top:5px"><?= date('M d, Y', strtotime($h['WatchDate'])) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div style="text-align:center;padding:80px 0;color:var(--text-muted)">
        <div style="font-size:48px;margin-bottom:16px">[No Image]</div>
        <p style="font-size:16px">No viewing history yet.</p>
        <a href="home.php" class="btn-primary" style="margin-top:16px;display:inline-flex">Browse Movies</a>
    </div>
    <?php endif; ?>
</div>
</body>
</html>
