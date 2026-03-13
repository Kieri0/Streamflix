<?php session_start(); if(!isset($_SESSION['user_id'])){header('Location: login.php');exit;} require_once __DIR__.'/php/db.php';
$cats=$conn->query("SELECT c.CategoryID,c.CategoryName,COUNT(mc.MovieID) AS MovieCount FROM Category c LEFT JOIN MovieCategory mc ON c.CategoryID=mc.CategoryID GROUP BY c.CategoryID ORDER BY c.CategoryName")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Category – StreamFlix</title><link rel="stylesheet" href="css/style.css"></head>
<body>
<?php include __DIR__ . '/php/navbar.php'; ?>
<div class="section"><h2 class="section-title">Browse by <span>Category</span></h2>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:32px">
<?php foreach($cats as $c): ?>
<div onclick="loadCat(<?=$c['CategoryID']?>,this)" style="background:var(--dark-card);border:1px solid var(--border);border-radius:8px;padding:20px;cursor:pointer;transition:all .2s" onmouseover="this.style.borderColor='var(--yellow)'" onmouseout="if(!this.classList.contains('active-cat'))this.style.borderColor='var(--border)'">
    <div style="font-size:15px;font-weight:700"><?=htmlspecialchars($c['CategoryName'])?></div>
    <div style="font-size:12px;color:var(--text-muted);margin-top:4px"><?=$c['MovieCount']?> movies</div>
</div>
<?php endforeach; ?>
</div>
<h3 style="font-size:16px;font-weight:700;margin-bottom:14px" id="catLabel">All Movies</h3>
<div class="movies-grid" id="catGrid"><p style="color:#666;font-size:13px">Select a category above</p></div>
</div>
<script>
function loadCat(id,el){
    document.querySelectorAll('[onclick*="loadCat"]').forEach(e=>{e.style.borderColor='var(--border)';e.classList.remove('active-cat');});
    el.style.borderColor='var(--yellow)';el.classList.add('active-cat');
    document.getElementById('catLabel').textContent=el.querySelector('div').textContent;
    fetch('api/movies.php?category='+id).then(r=>r.json()).then(res=>{
        const g=document.getElementById('catGrid');
        const movies=res.data||[];
        if(!movies.length){g.innerHTML='<p style="color:#666;font-size:13px">No movies in this category.</p>';return;}
        g.innerHTML=movies.map(m=>{
            const thumb=m.thumbnail_url?`<img src="${m.thumbnail_url}" loading="lazy" onerror="this.parentElement.innerHTML='<div class=no-thumb>[No Image]</div>'">`:'<div class="no-thumb">[No Image]</div>';
            return `<div class="movie-card" onclick="location.href='movie.php?id=${m.movie_id}'"><div class="movie-poster">${thumb}</div><div class="movie-info"><div class="movie-title">${m.title}</div><div class="movie-year">${m.release_year}</div></div></div>`;
        }).join('');
    });
}
</script></body></html>
