<aside class="sidebar">
    <div class="sidebar-brand"><img src="../uploads/logo.png" style="height:28px;width:auto"> STREAMFLIX</div>
    <ul class="sidebar-nav">
        <li><a href="dashboard.php"       <?= basename($_SERVER['PHP_SELF'])==='dashboard.php'       ?'class="active"':'' ?>> Dashboard</a></li>
        <li><a href="users.php"           <?= basename($_SERVER['PHP_SELF'])==='users.php'           ?'class="active"':'' ?>> Users</a></li>
        <li><a href="movies.php"          <?= basename($_SERVER['PHP_SELF'])==='movies.php'          ?'class="active"':'' ?>> Movies</a></li>
        <li><a href="genres.php"          <?= basename($_SERVER['PHP_SELF'])==='genres.php'          ?'class="active"':'' ?>> Genres</a></li>
        <li><a href="categories.php"      <?= basename($_SERVER['PHP_SELF'])==='categories.php'      ?'class="active"':'' ?>> Categories</a></li>
        <li><a href="subscriptions.php"   <?= basename($_SERVER['PHP_SELF'])==='subscriptions.php'   ?'class="active"':'' ?>> Subscriptions</a></li>
        <li><a href="viewing_history.php" <?= basename($_SERVER['PHP_SELF'])==='viewing_history.php' ?'class="active"':'' ?>> Viewing History</a></li>
        
        <li style="margin-top:20px"><a href="../logout.php" style="color:#ff6b6b"> Logout</a></li>
    </ul>
</aside>
