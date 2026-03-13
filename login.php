<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header(!empty($_SESSION['is_admin']) ? 'Location: admin/dashboard.php' : 'Location: home.php');
    exit;
}
require_once __DIR__ . '/php/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) {
        $error = 'Please fill in all fields.';
    } else {
        $stmt = $conn->prepare("SELECT UserID, FullName, Email, Password, SubscriptionStatus FROM User WHERE Email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if ($user && password_verify($password, $user['Password'])) {
            $_SESSION['user_id'] = $user['UserID'];
            $_SESSION['full_name'] = $user['FullName'];
            $_SESSION['email'] = $user['Email'];
            $_SESSION['sub_status'] = $user['SubscriptionStatus'];
            $_SESSION['is_admin'] = in_array($user['Email'], ADMIN_EMAILS);
            header(!empty($_SESSION['is_admin']) ? 'Location: admin/dashboard.php' : 'Location: home.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

// Fetch thumbnails for slideshow
$thumbs = $conn->query("SELECT ThumbnailPath FROM Movie WHERE ThumbnailPath IS NOT NULL AND ThumbnailPath != '' ORDER BY RAND() LIMIT 8")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>

<body class="auth-bg">

    <!-- Slideshow backgrounds -->
    <?php if ($thumbs): ?>
        <?php foreach ($thumbs as $i => $t): ?>
            <div class="slide-bg <?= $i === 0 ? 'active' : '' ?>"
                style="background-image:url('uploads/thumbnails/<?= htmlspecialchars(basename($t['ThumbnailPath'])) ?>')">
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <!-- Fallback if no thumbnails uploaded yet -->
        <div class="slide-bg active" style="background:#0a0a0a"></div>
    <?php endif; ?>

    <div class="bg-overlay"></div>
    <div class="auth-modal">
        <div class="modal-header">
            <div class="brand"><img src="uploads/logo.png" style="height:28px;width:auto"><span
                    class="brand-name">STREAMFLIX</span></div>
            <a href="index.php" class="close-btn">CLOSE</a>
        </div>
        <p class="modal-subtitle">Login to your account</p>
        <?php if ($error): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="Email" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <div class="password-wrap">
                    <input type="password" name="password" id="pw" placeholder="Password" required>
                    <span class="toggle-pw" onclick="togglePw()">Show</span>
                </div>
            </div>
            <button type="submit" class="btn-primary full-width">Login</button>
        </form>
        <p class="switch-auth">Don't have an account? <a href="register.php">Sign up</a></p>
    </div>

    <script>
        function togglePw() { const i = document.getElementById('pw'); i.type = i.type === 'password' ? 'text' : 'password'; }

        // Slideshow
        const slides = document.querySelectorAll('.slide-bg');
        let current = 0;
        if (slides.length > 1) {
            setInterval(() => {
                slides[current].classList.remove('active');
                current = (current + 1) % slides.length;
                slides[current].classList.add('active');
            }, 4000);
        }
    </script>
</body>

</html>