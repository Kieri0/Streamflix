<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/php/db.php';

$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
if (!$id) { header('Location: home.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM Movie WHERE MovieID=?");
$stmt->bind_param("i", $id); $stmt->execute();
$movie = $stmt->get_result()->fetch_assoc();
if (!$movie || !$movie['VideoPath']) { header('Location: movie.php?id='.$id); exit; }

$uid         = $_SESSION['user_id'];
$performedBy = $_SESSION['email'] ?? 'SYSTEM';
$ratingMsg   = '';

// ── SUBSCRIPTION GATE ─────────────────────────────────────────────────────
// Users without an active subscription cannot watch any movie.
// Check the User's SubscriptionStatus directly from the database
// (not the session, which could be stale).
$subCheck = $conn->prepare("SELECT SubscriptionStatus FROM User WHERE UserID = ?");
$subCheck->bind_param("i", $uid);
$subCheck->execute();
$subRow = $subCheck->get_result()->fetch_assoc();
$subCheck->close();

if (!$subRow || $subRow['SubscriptionStatus'] !== 'active') {
    // Redirect to subscription page with a message
    header('Location: subscription.php?required=1');
    exit;
}
// ─────────────────────────────────────────────────────────────────────────

// Handle rating submission (includes watched_duration from JS tracker)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_rating'])) {
    $userRating  = min(5, max(1, (int) $_POST['user_rating']));
    $watchedMins = max(0, (int) ($_POST['watched_duration'] ?? 0));
    // STORED PROCEDURE EQUIVALENT + LOCKING + TRANSACTION LOGGING + TRIGGER
    try {
        recordWatchSession($conn, $uid, $id, $watchedMins, $userRating, $performedBy);
        $ratingMsg = 'success';
    } catch (Exception $e) {
        $ratingMsg = 'error';
        error_log("recordWatchSession failed: " . $e->getMessage());
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_duration'])) {
    // Called automatically via sendBeacon when user leaves the page
    $watchedMins = max(0, (int) ($_POST['watched_duration'] ?? 0));
    try {
        recordWatchSession($conn, $uid, $id, $watchedMins, 0, $performedBy);
    } catch (Exception $e) {
        error_log("recordWatchSession duration beacon failed: " . $e->getMessage());
    }
    exit;
} else {
    // Log watch session on first page load (0 duration, updated later)
    try {
        recordWatchSession($conn, $uid, $id, 0, 0, $performedBy);
    } catch (Exception $e) {
        error_log("recordWatchSession failed: " . $e->getMessage());
    }
}

// Get current user's existing rating for this movie if any
$existingRating = 0;
$rStmt = $conn->prepare("SELECT UserRating FROM ViewingHistory WHERE UserID=? AND MovieID=?");
$rStmt->bind_param("ii", $uid, $id);
$rStmt->execute();
$rRow = $rStmt->get_result()->fetch_assoc();
if ($rRow) $existingRating = (int) $rRow['UserRating'];

// Refresh movie rating after possible update
$stmt2 = $conn->prepare("SELECT Rating FROM Movie WHERE MovieID=?");
$stmt2->bind_param("i", $id);
$stmt2->execute();
$freshRating = (float) $stmt2->get_result()->fetch_assoc()['Rating'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Watch: <?= htmlspecialchars($movie['Title']) ?> – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .watch-page { background: #000; min-height: 100vh; }
        .video-container { width: 100%; background: #000; }
        video { width: 100%; max-height: 80vh; display: block; }
        .watch-info { padding: 24px 48px; background: var(--dark); }
        .watch-back { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); font-size: 13px; margin-bottom: 16px; cursor: pointer; }
        .watch-back:hover { color: #fff; text-decoration: none; }
    </style>
</head>
<body class="watch-page">
<?php include __DIR__ . '/php/navbar.php'; ?>
<div class="video-container">
    <video controls autoplay
           poster="<?= $movie['ThumbnailPath'] ? 'uploads/thumbnails/'.htmlspecialchars(basename($movie['ThumbnailPath'])) : '' ?>"
           src="uploads/videos/<?= htmlspecialchars(basename($movie['VideoPath'])) ?>">
        Your browser does not support HTML5 video.
    </video>
</div>
<div class="watch-info">
    <a href="movie.php?id=<?= $id ?>" class="watch-back"> Back to movie details</a>
    <h2 style="font-size:22px;font-weight:800;margin-bottom:6px"><?= htmlspecialchars($movie['Title']) ?></h2>
    <p style="color:var(--text-muted);font-size:13px"><?= $movie['ReleaseYear'] ?></p>
    <?php if ($movie['Synopsis']): ?>
    <p style="font-size:13px;color:#bbb;margin-top:12px;line-height:1.7;max-width:700px"><?= htmlspecialchars($movie['Synopsis']) ?></p>
    <?php endif; ?>

    <!-- RATING SECTION — triggers recordWatchSession() with a real rating -->
    <!-- This activates: TRIGGER (auto rating recalc) + STORED PROCEDURE + LOCKING + LOGGING -->
    <div style="margin-top:28px;padding-top:24px;border-top:1px solid #333;max-width:500px">
        <p style="font-size:13px;color:var(--text-muted);margin-bottom:10px;text-transform:uppercase;letter-spacing:1px">Rate This Movie</p>

        <?php if ($ratingMsg === 'success'): ?>
        <div style="background:#1a3a2a;border:1px solid #2ecc71;color:#2ecc71;padding:10px 16px;border-radius:6px;font-size:13px;margin-bottom:14px">
            ✓ Rating submitted! Movie average updated automatically.
        </div>
        <?php elseif ($ratingMsg === 'error'): ?>
        <div style="background:#3a1a1a;border:1px solid #e74c3c;color:#e74c3c;padding:10px 16px;border-radius:6px;font-size:13px;margin-bottom:14px">
            Could not save rating. Please try again.
        </div>
        <?php endif; ?>

        <!-- Current movie average rating display -->
        <p style="font-size:12px;color:#888;margin-bottom:12px">
            Current average:
            <?php for($i=1;$i<=5;$i++) echo '<span class="avg-star" style="color:'.($i<=$freshRating?'#f1c40f':'#555').';font-size:16px">&#9733;</span>'; ?>
            <span style="color:#aaa;font-size:12px">(<span id="avgRatingText"><?= number_format($freshRating,1) ?>/5</span>)</span>
        </p>

        <!-- Star rating form -->
        <form method="POST" style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
            <input type="hidden" name="watched_duration" id="watchedDurationInput" value="0">
            <div class="star-rating" style="display:flex;gap:4px;flex-direction:row-reverse">
                <?php for($s=5;$s>=1;$s--): ?>
                <label style="cursor:pointer">
                    <input type="radio" name="user_rating" value="<?= $s ?>"
                           style="display:none"
                           <?= $existingRating === $s ? 'checked' : '' ?>>
                    <span class="star-btn" data-val="<?= $s ?>"
                          style="font-size:28px;color:<?= $existingRating>=$s?'#f1c40f':'#444' ?>;transition:color 0.15s">&#9733;</span>
                </label>
                <?php endfor; ?>
            </div>
            <button type="submit"
                    style="background:#e50914;color:#fff;border:none;padding:8px 20px;border-radius:6px;font-size:13px;font-weight:700;cursor:pointer">
                <?= $existingRating > 0 ? 'Update Rating' : 'Submit Rating' ?>
            </button>
            <?php if ($existingRating > 0): ?>
            <span style="font-size:12px;color:#888">Your current rating: <?= $existingRating ?>/5</span>
            <?php endif; ?>
        </form>
        <p style="font-size:11px;color:#555;margin-top:10px" id="durationDisplay">Duration watched: 0 min</p>
    </div>
</div>

<script>
// ── WATCH DURATION TRACKER ──────────────────────────────────
// Tracks actual minutes watched and stores in hidden form field.
// Also auto-saves via sendBeacon when user leaves the page.
var video       = document.querySelector('video');
var totalSecs   = 0;
var lastTime    = 0;
var trackingUrl = 'watch.php?id=<?= $id ?>';

if (video) {
    video.addEventListener('timeupdate', function() {
        if (!video.paused && lastTime > 0) {
            var delta = video.currentTime - lastTime;
            if (delta > 0 && delta < 5) totalSecs += delta; // ignore big jumps (seeks)
        }
        lastTime = video.currentTime;
        var mins = Math.round(totalSecs / 60);
        document.getElementById('watchedDurationInput').value = mins;
        document.getElementById('durationDisplay').textContent = 'Duration watched: ' + mins + ' min';
    });

    // Auto-save duration when user closes tab / navigates away
    document.addEventListener('visibilitychange', function() {
        if (document.visibilityState === 'hidden' && totalSecs > 10) {
            var mins = Math.round(totalSecs / 60);
            var data = new FormData();
            data.append('save_duration', '1');
            data.append('watched_duration', mins);
            navigator.sendBeacon(trackingUrl, data);
        }
    });
}

// ── STAR HOVER EFFECTS ──────────────────────────────────────
document.querySelectorAll('.star-btn').forEach(function(star) {
    star.addEventListener('mouseover', function() {
        var val = parseInt(this.getAttribute('data-val'));
        document.querySelectorAll('.star-btn').forEach(function(s) {
            s.style.color = parseInt(s.getAttribute('data-val')) <= val ? '#f1c40f' : '#444';
        });
    });
});
document.querySelector('.star-rating').addEventListener('mouseleave', function() {
    var checked = document.querySelector('input[name="user_rating"]:checked');
    var checkedVal = checked ? parseInt(checked.value) : 0;
    document.querySelectorAll('.star-btn').forEach(function(s) {
        s.style.color = parseInt(s.getAttribute('data-val')) <= checkedVal ? '#f1c40f' : '#444';
    });
});

// ── REAL-TIME RATING UPDATES ─────────────────────────────────
// Polls the current movie's average rating every 15 seconds.
// Updates the display live so viewers see rating changes from
// other users without needing to refresh the page.
var movieId = <?= $id ?>;
var ratingPollTimer = null;

function pollRating() {
    fetch('api/movies.php?rating=1&id=' + movieId)
        .then(function(r){ return r.json(); })
        .then(function(res){
            if(res.status !== 'success') return;
            var r = res.rating;
            // Update the star display
            var starEls = document.querySelectorAll('.avg-star');
            starEls.forEach(function(s, i){
                s.style.color = (i + 1) <= r ? '#f1c40f' : '#555';
            });
            // Update the text
            var avgText = document.getElementById('avgRatingText');
            if(avgText) avgText.textContent = r.toFixed(1) + '/5';
        })
        .catch(function(){});
}

// Start polling only when tab is visible
function startRatingPoll(){
    ratingPollTimer = setInterval(pollRating, 15000);
}
document.addEventListener('visibilitychange', function(){
    if(document.visibilityState === 'hidden'){
        clearInterval(ratingPollTimer);
    } else {
        pollRating();
        startRatingPoll();
    }
});
startRatingPoll();
</script>
</body>
</html>
