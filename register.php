<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header(!empty($_SESSION['is_admin']) ? 'Location: admin/dashboard.php' : 'Location: home.php');
    exit;
}
require_once __DIR__ . '/php/db.php';
$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fn  = trim($_POST['full_name'] ?? '');
    $em  = trim($_POST['email'] ?? '');
    $pw  = $_POST['password'] ?? '';
    $cpw = $_POST['confirm_password'] ?? '';

    if (!$fn || !$em || !$pw || !$cpw)
        $error = 'Please fill in all fields.';
    elseif ($pw !== $cpw)
        $error = 'Passwords do not match.';
    elseif (strlen($pw) < 6)
        $error = 'Password must be at least 6 characters.';
    else {
        // CONCURRENCY CONTROL: transaction prevents duplicate email race condition
        beginTransaction($conn);
        try {
            // LOCKING: FOR UPDATE locks the email check row so two simultaneous
            // registrations with the same email cannot both pass this gate
            $chk = $conn->prepare("SELECT UserID FROM User WHERE Email = ? FOR UPDATE");
            $chk->bind_param("s", $em);
            $chk->execute();
            $chk->store_result();

            if ($chk->num_rows > 0) {
                $chk->close();
                rollbackTransaction($conn);
                $error = 'An account with this email already exists.';
            } else {
                $chk->close();
                $hash = password_hash($pw, PASSWORD_DEFAULT);
                $ins  = $conn->prepare(
                    "INSERT INTO User (FullName, Email, Password, SubscriptionStatus) VALUES (?,?,?,'none')"
                );
                $ins->bind_param("sss", $fn, $em, $hash);
                if (!$ins->execute()) throw new Exception($conn->error);
                $newId = $conn->insert_id;
                $ins->close();

                commitTransaction($conn);

                // TRANSACTION LOGGING — after commit so a log failure
                // never rolls back a successful registration
                auditLog($conn, 'User', 'INSERT', $newId, $em,
                    "New user registered: {$fn} ({$em})");

                $success = 'Account created! <a href="login.php">Login here</a>.';
            }
        } catch (Exception $e) {
            rollbackTransaction($conn);
            $error = 'Registration failed. Please try again.';
        }
    }
}

$thumbs = $conn->query("SELECT ThumbnailPath FROM Movie WHERE ThumbnailPath IS NOT NULL AND ThumbnailPath != '' ORDER BY RAND() LIMIT 8")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register – StreamFlix</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="auth-bg">
    <?php if ($thumbs): ?>
        <?php foreach ($thumbs as $i => $t): ?>
            <div class="slide-bg <?= $i === 0 ? 'active' : '' ?>"
                style="background-image:url('uploads/thumbnails/<?= htmlspecialchars(basename($t['ThumbnailPath'])) ?>')">
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="slide-bg active" style="background:#0a0a0a"></div>
    <?php endif; ?>

    <div class="bg-overlay"></div>
    <div class="auth-modal register-modal">
        <div class="modal-header">
            <div class="brand"><img src="uploads/logo.png" style="height:28px;width:auto"><span class="brand-name">STREAMFLIX</span></div>
            <a href="index.php" class="close-btn">CLOSE</a>
        </div>
        <p class="modal-subtitle">Register to enjoy the features</p>
        <?php if ($error): ?><div class="alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert-success"><?= $success ?></div><?php endif; ?>
        <form method="POST">
            <div class="form-group"><label>Full Name</label><input type="text" name="full_name" placeholder="Full Name" required></div>
            <div class="form-group"><label>Email</label><input type="email" name="email" placeholder="Email" required></div>
            <div class="form-group"><label>Password</label><input type="password" name="password" placeholder="Password" required></div>
            <div class="form-group">
                <label>Confirm Password</label>
                <div class="password-wrap">
                    <input type="password" name="confirm_password" id="cpw" placeholder="Confirm Password" required>
                    <span class="toggle-pw" onclick="togglePw()">Show</span>
                </div>
            </div>
            <button type="submit" class="btn-primary full-width">Sign Up</button>
        </form>
        <p class="switch-auth">Already have an account? <a href="login.php">Login</a></p>
    </div>
    <script>
        function togglePw() { const i = document.getElementById('cpw'); i.type = i.type === 'password' ? 'text' : 'password'; }
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
