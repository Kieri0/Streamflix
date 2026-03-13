<?php session_start(); if(!isset($_SESSION['user_id'])){header('Location: login.php');exit;} require_once __DIR__.'/php/db.php';
$genres=$conn->query("SELECT g.GenreID,g.GenreName,g.Description,COUNT(mg.MovieID) AS MovieCount FROM Genre g LEFT JOIN MovieGenre mg ON g.GenreID=mg.GenreID GROUP BY g.GenreID ORDER BY g.GenreName")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Genre – StreamFlix</title><link rel="stylesheet" href="css/style.css"></head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>
<div class="section"><h2 class="section-title">Browse by <span>Genre</span></h2>
<div class="filter-bar" id="genreChips"></div>
<h3 style="font-size:16px;font-weight:700;margin-bottom:14px" id="genreLabel">Select a genre</h3>
<div class="movies-grid" id="genreGrid"><p style="color:#666;font-size:13px">Select a genre above to browse movies.</p></div>
</div>
<script>
const genres=<?=json_encode($genres)?>;
const bar=document.getElementById('genreChips');
genres.forEach(g=>{
    const b=document.createElement('button');
    b.className='filter-chip';
    b.textContent=g.GenreName+' ('+g.MovieCount+')';
    b.onclick=()=>{document.querySelectorAll('.filter-chip').forEach(x=>x.classList.remove('active'));b.classList.add('active');loadGenre(g.GenreName);};
    bar.appendChild(b);
});
function loadGenre(name){
    document.getElementById('genreLabel').textContent=name;
    fetch('api/movies.php?genre='+encodeURIComponent(name)).then(r=>r.json()).then(res=>{
        const g=document.getElementById('genreGrid');
        const movies=res.data||[];
        if(!movies.length){g.innerHTML='<p style="color:#666;font-size:13px">No movies found.</p>';return;}
        g.innerHTML=movies.map(m=>{
            const thumb=m.thumbnail_url?`<img src="${m.thumbnail_url}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=no-thumb>[No Image]</div>'">`:'<div class="no-thumb">[No Image]</div>';
            const stars=Array.from({length:5},(_,i)=>`<span class="star ${i<Math.round(m.rating)?'filled':''}">&#9733;</span>`).join('');
            return `<div class="movie-card" onclick="location.href='movie.php?id=${m.movie_id}'"><div class="movie-poster">${thumb}</div><div class="movie-info"><div class="movie-title">${m.title}</div><div class="movie-year">${m.release_year}</div><div class="stars">${stars}</div></div></div>`;
        }).join('');
    });
}
</script></body></html>
