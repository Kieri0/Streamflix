<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header(!empty($_SESSION['is_admin']) ? 'Location: admin/dashboard.php' : 'Location: home.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
    <style>
        .landing { min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; background: #0a0a0a; }
        .landing h1 { font-size: 56px; font-weight: 900; letter-spacing: 4px; color: var(--yellow); margin-bottom: 12px; }
        .landing p { color: var(--text-muted); font-size: 16px; margin-bottom: 32px; }
        .landing-actions { display: flex; gap: 14px; }
    </style>
</head>
<body>
<div class="landing">
    <img src="uploads/logo.png" style="height:64px;margin-bottom:20px">
    <h1>STREAMFLIX</h1>
    <p>Your favorite movies, all in one place.</p>
    <div class="landing-actions">
        <a href="login.php" class="btn-primary" style="font-size:15px;padding:12px 32px">Login</a>
        <a href="register.php" class="btn-outline" style="font-size:15px;padding:12px 32px">Sign Up</a>
    </div>
</div>
</body>
</html>