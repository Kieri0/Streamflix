<?php

$initials = strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1));
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<nav class="navbar">
   <a href="home.php" class="nav-brand" style="text-decoration:none"><img src="uploads/logo.png" style="height:32px;width:auto"> STREAMFLIX</a>
    <ul class="nav-links">
        <li><a href="home.php"     <?= $currentPage==='home.php'     ?'class="active"':'' ?>>Home</a></li>
        <li><a href="movies.php"   <?= $currentPage==='movies.php'   ?'class="active"':'' ?>>Movies</a></li>
        <li><a href="category.php" <?= $currentPage==='category.php' ?'class="active"':'' ?>>Category</a></li>
        <li><a href="genre.php"    <?= $currentPage==='genre.php'    ?'class="active"':'' ?>>Genre</a></li>
    </ul>
    <div class="nav-actions">
        <button class="nav-icon" onclick="location.href='movies.php'" title="Search">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </button>
        <button class="nav-icon" onclick="location.href='watchlist.php'" title="Watchlist">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        </button>
        <div class="nav-avatar" id="navAvatar">
            <?= $initials ?>
            <div class="nav-dropdown" id="navDropdown">
                <a href="subscription.php">Subscription</a>
                <a href="history.php">View History</a>
                <a href="logout.php" class="sep" style="color:#ff6b6b">Logout</a>
            </div>
        </div>
    </div>
</nav>
<script>
(function(){
    const avatar = document.getElementById('navAvatar');
    const dropdown = document.getElementById('navDropdown');
    if (!avatar) return;

  
    avatar.addEventListener('click', function(e) {
        e.stopPropagation();
        avatar.classList.toggle('open');
    });


    document.addEventListener('click', function(e) {
        if (!avatar.contains(e.target)) {
            avatar.classList.remove('open');
        }
    });

    dropdown.addEventListener('click', function(e) {
        e.stopPropagation();
    });
})();
</script>
