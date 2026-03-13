<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
if (!$id) { header('Location: home.php'); exit; }

$stmt = $conn->prepare("
    SELECT m.*, 
           GROUP_CONCAT(DISTINCT g.GenreName ORDER BY g.GenreName SEPARATOR ',') AS Genres,
           GROUP_CONCAT(DISTINCT c.CategoryName SEPARATOR ',') AS Categories
    FROM Movie m
    LEFT JOIN MovieGenre mg ON m.MovieID=mg.MovieID
    LEFT JOIN Genre g ON mg.GenreID=g.GenreID
    LEFT JOIN MovieCategory mc ON m.MovieID=mc.MovieID
    LEFT JOIN Category c ON mc.CategoryID=c.CategoryID
    WHERE m.MovieID=? GROUP BY m.MovieID
");
$stmt->bind_param("i", $id); $stmt->execute();
$movie = $stmt->get_result()->fetch_assoc();
if (!$movie) { header('Location: home.php'); exit; }

$uid = $_SESSION['user_id'];
$genres     = $movie['Genres']     ? explode(',', $movie['Genres'])     : [];
$categories = $movie['Categories'] ? explode(',', $movie['Categories']) : [];

// ── SUBSCRIPTION CHECK ──────────────────────────────────────────────────────
// Check directly from DB (not session) so stale session data cannot bypass it.
$subQ = $conn->prepare("SELECT SubscriptionStatus FROM User WHERE UserID = ?");
$subQ->bind_param("i", $uid);
$subQ->execute();
$subRow = $subQ->get_result()->fetch_assoc();
$subQ->close();
$isSubscribed = ($subRow && $subRow['SubscriptionStatus'] === 'active');
// ─────────────────────────────────────────────────────────────────────────────

// Check watchlist
$wl = $conn->prepare("SELECT WatchlistID FROM Watchlist WHERE UserID=? AND MovieID=?");
$wl->bind_param("ii", $uid, $id); $wl->execute();
$inWatchlist = $wl->get_result()->num_rows > 0;

// Handle watchlist toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action      = $_POST['action'];
    $performedBy = $_SESSION['email'] ?? 'SYSTEM';

    if ($action === 'add_watchlist') {
        beginTransaction($conn);
        try {
            $ins = $conn->prepare("INSERT IGNORE INTO Watchlist (UserID,MovieID) VALUES (?,?)");
            $ins->bind_param("ii", $uid, $id);
            if (!$ins->execute()) throw new Exception($conn->error);
            $ins->close();
            commitTransaction($conn);
            auditLog($conn, 'Watchlist', 'INSERT', $uid, $performedBy,
                "UserID={$uid} added MovieID={$id} to watchlist");
            $inWatchlist = true;
        } catch (Exception $e) {
            rollbackTransaction($conn);
        }

    } elseif ($action === 'remove_watchlist') {
        beginTransaction($conn);
        try {
            $del = $conn->prepare("DELETE FROM Watchlist WHERE UserID=? AND MovieID=?");
            $del->bind_param("ii", $uid, $id);
            if (!$del->execute()) throw new Exception($conn->error);
            $del->close();
            commitTransaction($conn);
            auditLog($conn, 'Watchlist', 'DELETE', $uid, $performedBy,
                "UserID={$uid} removed MovieID={$id} from watchlist");
            $inWatchlist = false;
        } catch (Exception $e) {
            rollbackTransaction($conn);
        }

    } elseif ($action === 'mark_watched') {
        try {
            recordWatchSession($conn, $uid, $id, 0, 0, $performedBy);
        } catch (Exception $e) {
            // non-fatal
        }
    }
}

// More to watch (other movies)
$moreStmt = $conn->prepare("SELECT MovieID, Title, ThumbnailPath FROM Movie WHERE MovieID != ? ORDER BY RAND() LIMIT 10");
$moreStmt->bind_param("i", $id);
$moreStmt->execute();
$more = $moreStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$moreStmt->close();

$tab = $_GET['tab'] ?? 'storyline';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($movie['Title']) ?> – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

<?php include __DIR__ . '/php/navbar.php'; ?>

<!-- HERO -->
<div class="detail-hero">
    <?php if ($movie['ThumbnailPath']): ?>
    <img class="detail-hero-bg" src="uploads/thumbnails/<?= htmlspecialchars(basename($movie['ThumbnailPath'])) ?>" alt="">
    <?php endif; ?>
    <div class="detail-hero-grad"></div>
    <div class="detail-hero-content">
        <?php if ($movie['ThumbnailPath']): ?>
        <div class="detail-poster">
            <img src="uploads/thumbnails/<?= htmlspecialchars(basename($movie['ThumbnailPath'])) ?>" alt="<?= htmlspecialchars($movie['Title']) ?>">
        </div>
        <?php endif; ?>
        <div class="detail-info">
            
            <h1 class="detail-title"><?= htmlspecialchars($movie['Title']) ?></h1>
            <div class="detail-meta">
                <?php if ($genres): ?>
                <span><?= htmlspecialchars(implode(', ', $genres)) ?></span>
                <span class="dot">•</span>
                <?php endif; ?>
                <span><?= $movie['ReleaseYear'] ?></span>
                <?php if ($movie['Rating']): ?>
                <span class="dot">•</span>
                <span>
                    <?php for($i=1;$i<=5;$i++) echo '<span class="star '.($i<=$movie['Rating']?'filled':'').'">&#9733;</span>'; ?>
                    (<?= number_format($movie['Rating'],1) ?>)
                </span>
                <?php endif; ?>
            </div>
            <div class="detail-genre-tags">
                <?php foreach ($genres as $g): ?>
                <span class="detail-genre-tag"><?= htmlspecialchars($g) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="detail-actions">
                <?php if ($movie['VideoPath']): ?>
                    <?php if ($isSubscribed): ?>
                    <a href="watch.php?id=<?= $id ?>" class="btn-primary"> Watch Now</a>
                    <?php else: ?>
                    <a href="subscription.php?required=1" class="btn-primary" style="background:#555;cursor:pointer" title="Subscribe to watch">
                         Subscribe to Watch
                    </a>
                    <?php endif; ?>
                <?php else: ?>
                <button class="btn-primary" disabled style="opacity:.5;cursor:not-allowed"> No Video Yet</button>
                <?php endif; ?>

                <form method="POST" style="display:inline">
                    <input type="hidden" name="action" value="<?= $inWatchlist ? 'remove_watchlist' : 'add_watchlist' ?>">
                    <button type="submit" class="detail-action-btn">
                        <?= $inWatchlist ? 'In Watchlist' : 'Add to Watchlist' ?>
                    </button>
                </form>

                <?php if ($movie['VideoPath'] && $isSubscribed): ?>
                <a href="uploads/videos/<?= htmlspecialchars(basename($movie['VideoPath'])) ?>" download class="detail-action-btn"> Download</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>




<div class="detail-body">
    <div id="tab-storyline" class="tab-pane" style="display:block">
        <h3 style="font-size:18px;font-weight:700;margin-bottom:14px">Sypnosis</h3>
        <p class="storyline"><?= htmlspecialchars($movie['Synopsis'] ?: 'No synopsis available for this movie.') ?></p>
        <?php if ($categories): ?>
        <div style="margin-top:20px">
            <p style="font-size:12px;color:var(--text-muted);margin-bottom:8px">CATEGORIES</p>
            <?php foreach ($categories as $cat): ?>
            <span class="detail-genre-tag"><?= htmlspecialchars($cat) ?></span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MORE TO WATCH -->
<?php if ($more): ?>
<div class="section" style="border-top:1px solid var(--border)">
    <h2 class="section-title">More To Watch</h2>
    <div class="movies-scroll">
        <?php foreach ($more as $m): ?>
        <div class="movie-card" onclick="location.href='movie.php?id=<?= $m['MovieID'] ?>'">
            <div class="movie-poster">
                <?php if ($m['ThumbnailPath']): ?>
                <img src="uploads/thumbnails/<?= htmlspecialchars(basename($m['ThumbnailPath'])) ?>" alt="<?= htmlspecialchars($m['Title']) ?>" loading="lazy">
                <?php else: ?>
                <div class="no-thumb">[No Image]</div>
                <?php endif; ?>
            </div>
            <div class="movie-info">
                <div class="movie-title"><?= htmlspecialchars($m['Title']) ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>


</body>
</html>
