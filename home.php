<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';
$uid = $_SESSION['user_id'];
$featured = $conn->query("SELECT m.*, GROUP_CONCAT(DISTINCT g.GenreName SEPARATOR ', ') AS Genres FROM Movie m LEFT JOIN MovieGenre mg ON m.MovieID=mg.MovieID LEFT JOIN Genre g ON mg.GenreID=g.GenreID WHERE m.ThumbnailPath IS NOT NULL AND m.ThumbnailPath != '' GROUP BY m.MovieID ORDER BY m.Rating DESC, m.MovieID DESC LIMIT 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>

<?php if ($featured): ?>
<div class="hero-banner">
    <?php if ($featured['ThumbnailPath']): ?><img class="hero-bg" src="uploads/thumbnails/<?= htmlspecialchars(basename($featured['ThumbnailPath'])) ?>" alt=""><?php endif; ?>
    <div class="hero-gradient"></div>
    <div class="hero-content">
        
        <h1 class="hero-title"><?= htmlspecialchars($featured['Title']) ?></h1>
        <div class="hero-meta"><?= htmlspecialchars($featured['Genres'] ?? '') ?> &bull; <?= $featured['ReleaseYear'] ?></div>
        <p class="hero-synopsis"><?= htmlspecialchars($featured['Synopsis'] ?? '') ?></p>
        <div class="hero-actions">
            <a href="watch.php?id=<?= $featured['MovieID'] ?>" class="btn-primary"> Watch Now</a>
            <a href="movie.php?id=<?= $featured['MovieID'] ?>" class="btn-outline">More Info</a>
        </div>
    </div>
</div>
<?php else: ?>
<div class="hero-banner"><div class="hero-gradient"></div><div class="hero-content"><h1 class="hero-title">Welcome to StreamFlix</h1><p class="hero-synopsis">Add movies via the admin panel to get started.</p></div></div>
<?php endif; ?>

<div class="row-section">
    <div class="row-header">
        <h2 class="row-title">Trending Now</h2>
        <div class="filter-bar" id="genreFilters"></div>
    </div>
    <div class="row-viewport" id="trendingViewport">
        <div class="movies-scroll" id="movieGrid">
            <?php for($i=0;$i<8;$i++): ?><div class="movie-card"><div class="movie-poster skeleton" style="aspect-ratio:2/3"></div><div class="movie-info"><div class="skeleton" style="height:12px;width:80%;margin-bottom:6px;border-radius:3px"></div><div class="skeleton" style="height:10px;width:50%;border-radius:3px"></div></div></div><?php endfor; ?>
        </div>
    </div>
</div>

<div class="row-section">
    <div class="row-header">
        <h2 class="row-title"><span>Top Rated</span></h2>
    </div>
    <div class="row-viewport" id="topRatedViewport">
        <div class="movies-scroll" id="topRatedGrid">
            <?php for($i=0;$i<8;$i++): ?><div class="movie-card"><div class="movie-poster skeleton" style="aspect-ratio:2/3"></div><div class="movie-info"><div class="skeleton" style="height:12px;width:80%;margin-bottom:6px;border-radius:3px"></div></div></div><?php endfor; ?>
        </div>
    </div>
</div>

<script>
const API='api/movies.php',GAPI='api/genres.php';
fetch(GAPI).then(r=>r.json()).then(res=>{
    const bar=document.getElementById('genreFilters');
    const all=chip('All',true,()=>loadMovies(null,all));bar.appendChild(all);
    (res.data||[]).forEach(g=>{const b=chip(g.GenreName,false,()=>loadMovies(g.GenreName,b));bar.appendChild(b);});
});
function chip(label,active,fn){const b=document.createElement('button');b.className='filter-chip'+(active?' active':'');b.textContent=label;b.onclick=fn;return b;}
function loadMovies(genre,btn){
    if(btn){document.querySelectorAll('.filter-chip').forEach(b=>b.classList.remove('active'));btn.classList.add('active');}
    fetch(genre?`${API}?genre=${encodeURIComponent(genre)}`:API).then(r=>r.json()).then(res=>renderScroll('movieGrid',res.data||[]));
}
function renderScroll(id,movies){
    const g=document.getElementById(id);
    if(!movies.length){g.innerHTML='<p style="color:#666;font-size:13px;padding:20px 0">No movies found.</p>';return;}
    g.innerHTML=movies.map(m=>card(m)).join('');
}
function card(m){
    const stars=Array.from({length:5},(_,i)=>`<span class="star ${i<Math.round(m.rating)?'filled':''}">&#9733;</span>`).join('');
    const thumb=m.thumbnail_url?`<img src="${m.thumbnail_url}" alt="${h(m.title)}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=no-thumb>No Image</div>'">`:'<div class="no-thumb">No Image</div>';
    return `<div class="movie-card" onclick="location.href='movie.php?id=${m.movie_id}'">
        <div class="movie-poster">${thumb}${m.has_video?'<div class="play-overlay"><div class="play-btn-circle">PLAY</div></div>':''}</div>
        <div class="movie-info">
            <div class="movie-title">${h(m.title)}</div>
            <div class="movie-year">${m.release_year}</div>
            <div class="genre-tags">${(m.genres||[]).slice(0,2).map(g=>`<span class="genre-tag">${h(g)}</span>`).join('')}</div>
            <div class="stars">${stars}</div>
        </div></div>`;
}
function h(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ── REAL-TIME UPDATES — lightweight poll every 3 seconds ────────────────
// Checks if the movie count or latest MovieID changed since last check.
// Only re-renders the grids when something actually changed.
// Each request is a tiny single-query hit — no open connections held.

var knownCount  = -1;
var knownLatest = -1;

function checkForUpdates() {
    fetch('api/events.php?lightweight=1')
        .then(function(r){ return r.json(); })
        .then(function(d) {
            if (d.count === undefined) return;
            if (knownCount === -1) {
                // First load — just store the baseline
                knownCount  = d.count;
                knownLatest = d.latest;
                return;
            }
            if (d.count !== knownCount || d.latest !== knownLatest) {
                knownCount  = d.count;
                knownLatest = d.latest;
                // Something changed — refresh grids
                var activeChip  = document.querySelector('.filter-chip.active');
                var activeGenre = (activeChip && activeChip.textContent !== 'All') ? activeChip.textContent : null;
                loadMovies(activeGenre, null);
                fetch(`${API}?recommended=1`).then(r=>r.json()).then(res=>renderScroll('topRatedGrid',res.data||[]));
            }
        })
        .catch(function(){});
}

document.addEventListener('visibilitychange', function() {
    if (document.visibilityState !== 'hidden') {
        loadMovies(null);
        fetch(`${API}?recommended=1`).then(r=>r.json()).then(res=>renderScroll('topRatedGrid',res.data||[]));
    }
});

// Scroll a row left (-1) or right (+1) by roughly one viewport width
function scrollRow(id, dir) {
    var el = document.getElementById(id);
    var amount = el.clientWidth * 0.85;
    el.scrollBy({ left: dir * amount, behavior: 'smooth' });
}

// Drag-to-scroll on all rows (like Netflix)
// hasDragged flag prevents card click firing after a drag
document.querySelectorAll('.movies-scroll').forEach(function(el) {
    var isDragging = false, startX, scrollLeft, hasDragged = false;
    el.addEventListener('mousedown', function(e) {
        isDragging = true;
        hasDragged = false;
        startX = e.pageX - el.offsetLeft;
        scrollLeft = el.scrollLeft;
        el.style.cursor = 'grabbing';
    });
    document.addEventListener('mouseup', function() {
        isDragging = false;
        el.style.cursor = 'grab';
        // Reset hasDragged after a short delay so click handler can check it
        setTimeout(function() { hasDragged = false; }, 50);
    });
    el.addEventListener('mouseleave', function() { isDragging = false; el.style.cursor = 'grab'; });
    el.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var x = e.pageX - el.offsetLeft;
        var walk = x - startX;
        if (Math.abs(walk) > 5) {
            hasDragged = true;
            e.preventDefault();
            el.scrollLeft = scrollLeft - walk * 1.5;
        }
    });
    // Block click on child cards after a drag
    el.addEventListener('click', function(e) {
        if (hasDragged) e.stopPropagation();
    }, true);
});

loadMovies(null);
fetch(`${API}?recommended=1`).then(r=>r.json()).then(res=>renderScroll('topRatedGrid',res.data||[]));
setInterval(checkForUpdates, 3000);
checkForUpdates();
</script>
</body>
</html>