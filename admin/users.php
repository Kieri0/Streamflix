<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$msgType     = 'success';
$performedBy = \$_SESSION['email'] ?? 'SYSTEM';

// DELETE user — with transaction and audit log
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int) $_GET['delete'];
    if ($did !== (int) $_SESSION['user_id']) {
        beginTransaction($conn);
        try {
            // Capture user info before deletion for the audit log
            $info = $conn->prepare("SELECT FullName, Email FROM User WHERE UserID = ?");
            $info->bind_param("i", $did);
            $info->execute();
            $deleted = $info->get_result()->fetch_assoc();
            $info->close();

            $s = $conn->prepare("DELETE FROM User WHERE UserID=?");
            $s->bind_param("i", $did);
            if (!$s->execute()) throw new Exception($conn->error);
            $s->close();

            // TRANSACTION LOGGING: record deletion before cascade removes the data
            auditLog($conn, 'User', 'DELETE', $did, $performedBy,
                "Admin deleted user: {$deleted['FullName']} ({$deleted['Email']})");

            commitTransaction($conn);
            $msg = 'User deleted.';
        } catch (Exception $e) {
            rollbackTransaction($conn);
            $msg = 'Could not delete user.';
        $msgType = 'error';
        }
    } else {
        $msg = 'Cannot delete your own account.';
    $msgType = 'error';
    }
}

// ADD user — with transaction, locking, and audit log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $fn  = trim($_POST['full_name']);
    $em  = trim($_POST['email']);
    $pw  = $_POST['password'];
    $ss  = $_POST['subscription_status'];
    $hpw = password_hash($pw, PASSWORD_DEFAULT);

    beginTransaction($conn);
    try {
        // LOCKING: lock any existing row with this email to prevent duplicates
        $chk = $conn->prepare("SELECT UserID FROM User WHERE Email = ? FOR UPDATE");
        $chk->bind_param("s", $em);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            rollbackTransaction($conn);
            $msg = 'Error: Email already exists.';
            $msgType = 'error';
        } else {
            $chk->close();
            $ins = $conn->prepare("INSERT INTO User (FullName,Email,Password,SubscriptionStatus) VALUES (?,?,?,?)");
            $ins->bind_param("ssss", $fn, $em, $hpw, $ss);
            if (!$ins->execute()) throw new Exception($conn->error);
            $newId = $conn->insert_id;
            $ins->close();

            // TRANSACTION LOGGING
            auditLog($conn, 'User', 'INSERT', $newId, $performedBy,
                "Admin added user: {$fn} ({$em}) | Status: {$ss}");

            commitTransaction($conn);
            $msg = 'User added.';
        }
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error: ' . \$e->getMessage();
        \$msgType = 'error';
    }
}

$users = $conn->query("SELECT UserID,FullName,Email,SubscriptionStatus FROM User ORDER BY UserID DESC")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users – Admin | StreamFlix</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="admin-layout"><?php include __DIR__ . '/sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-topbar"><h2> Users</h2></div>
            <?php if ($msg): ?><div class="alert-<?= $msgType ?>"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <div class="form-section">
                <h3>Add User</h3>
                <form method="POST"><input type="hidden" name="action" value="add">
                    <div class="form-row">
                        <div class="field"><label>Full Name</label><input type="text" name="full_name" required></div>
                        <div class="field"><label>Email</label><input type="email" name="email" required></div>
                    </div>
                    <div class="form-row">
                        <div class="field"><label>Password</label><input type="password" name="password" required></div>
                        <div class="field"><label>Subscription Status</label><select name="subscription_status">
                            <option value="none">None</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select></div>
                    </div>
                    <button type="submit" class="btn-primary">Add User</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>ID</th><th>Full Name</th><th>Email</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?= $u['UserID'] ?></td>
                            <td><?= htmlspecialchars($u['FullName']) ?></td>
                            <td><?= htmlspecialchars($u['Email']) ?></td>
                            <td><span class="badge badge-<?= $u['SubscriptionStatus'] ?>"><?= strtoupper($u['SubscriptionStatus']) ?></span></td>
                            <td><a href="?delete=<?= $u['UserID'] ?>" class="btn-danger" onclick="return confirm('Delete?')">Delete</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
