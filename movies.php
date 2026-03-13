<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
if (!empty($_SESSION['is_admin'])) { header('Location: admin/dashboard.php'); exit; }
require_once __DIR__ . '/php/db.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Movies – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>

<div class="section">
    <h2 class="section-title">All Movies</h2>
    <div class="search-wrap">
        <input type="text" id="searchInput" placeholder="Search movies by title..." oninput="onSearch()">
    </div>
    <div class="filter-bar" id="genreFilters"></div>
    <div class="movies-grid" id="movieGrid">
        <?php for($i=0;$i<12;$i++): ?><div class="movie-card"><div class="movie-poster skeleton" style="aspect-ratio:2/3"></div><div class="movie-info"><div class="skeleton" style="height:12px;width:80%;margin-bottom:6px;border-radius:3px"></div><div class="skeleton" style="height:10px;width:50%;border-radius:3px"></div></div></div><?php endfor; ?>
    </div>
</div>

<script>
const API='api/movies.php',GAPI='api/genres.php';
let activeGenre=null,searchTerm='',timer;
fetch(GAPI).then(r=>r.json()).then(res=>{
    const bar=document.getElementById('genreFilters');
    const all=chip('All',true,()=>setGenre(null,all));bar.appendChild(all);
    (res.data||[]).forEach(g=>{const b=chip(g.GenreName,false,()=>setGenre(g.GenreName,b));bar.appendChild(b);});
});
function chip(label,active,fn){const b=document.createElement('button');b.className='filter-chip'+(active?' active':'');b.textContent=label;b.onclick=fn;return b;}
function setGenre(g,btn){activeGenre=g;document.querySelectorAll('.filter-chip').forEach(b=>b.classList.remove('active'));btn.classList.add('active');load();}
function onSearch(){searchTerm=document.getElementById('searchInput').value.trim();clearTimeout(timer);timer=setTimeout(load,300);}
function load(){
    let url=API,p=[];
    if(searchTerm)p.push('search='+encodeURIComponent(searchTerm));
    if(activeGenre)p.push('genre='+encodeURIComponent(activeGenre));
    if(p.length)url+='?'+p.join('&');
    fetch(url).then(r=>r.json()).then(res=>render(res.data||[]));
}
function render(movies){
    const g=document.getElementById('movieGrid');
    if(!movies.length){g.innerHTML='<p style="color:#666;font-size:13px;grid-column:1/-1;padding:20px 0">No movies found.</p>';return;}
    g.innerHTML=movies.map(m=>{
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
    }).join('');
}
function h(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}
load();
</script>
</body>
</html>
