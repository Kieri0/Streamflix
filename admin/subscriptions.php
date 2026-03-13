<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

// DELETE subscription — with transaction and audit log
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int) $_GET['delete'];
    beginTransaction($conn);
    try {
        // Capture info before deletion
        $info = $conn->prepare("SELECT s.*, u.FullName FROM Subscription s JOIN User u ON s.UserID=u.UserID WHERE s.SubscriptionID=?");
        $info->bind_param("i", $did);
        $info->execute();
        $deleted = $info->get_result()->fetch_assoc();
        $info->close();

        $s = $conn->prepare("DELETE FROM Subscription WHERE SubscriptionID=?");
        $s->bind_param("i", $did);
        if (!$s->execute()) throw new Exception($conn->error);
        $s->close();

        // TRANSACTION LOGGING
        auditLog($conn, 'Subscription', 'DELETE', $did, $performedBy,
            "Admin deleted subscription ID={$did} for {$deleted['FullName']} | Plan={$deleted['PlanName']}");

        commitTransaction($conn);
        $msg = 'Deleted.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error deleting subscription.';
    }
}

// ADD subscription — uses processSubscription() for locking + transaction + logging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid   = (int) $_POST['user_id'];
    $plan  = trim($_POST['plan_name']);
    $price = (float) $_POST['price'];
    $dur   = (int) $_POST['duration'];

    try {
        // STORED PROCEDURE EQUIVALENT: processSubscription() handles
        // FOR UPDATE lock, transaction, status update, and AuditLog write
        processSubscription($conn, $uid, $plan, $price, $dur, $performedBy);
        $msg = 'Subscription added.';
    } catch (Exception $e) {
        $msg = 'Error: ' . $e->getMessage();
    }
}

$subs  = $conn->query("SELECT s.*,u.FullName,u.Email FROM Subscription s JOIN User u ON s.UserID=u.UserID ORDER BY s.SubscriptionID DESC")->fetch_all(MYSQLI_ASSOC);
$users = $conn->query("SELECT UserID,FullName,Email FROM User ORDER BY FullName")->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Subscriptions – Admin | StreamFlix</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="admin-layout"><?php include __DIR__ . '/sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-topbar"><h2> Subscriptions</h2></div>
            <?php if ($msg): ?><div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <div class="form-section">
                <h3>Add Subscription</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="field"><label>User</label><select name="user_id" required>
                            <option value="">Select...</option>
                            <?php foreach ($users as $u): ?>
                            <option value="<?= $u['UserID'] ?>"><?= htmlspecialchars($u['FullName']) ?> (<?= htmlspecialchars($u['Email']) ?>)</option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="field"><label>Plan Name</label><input type="text" name="plan_name" placeholder="Basic / Premium" required></div>
                        <div class="field"><label>Price ($)</label><input type="number" name="price" step="0.01" min="0" required></div>
                        <div class="field"><label>Duration (days)</label><input type="number" name="duration" min="1" required></div>
                    </div>
                    <button type="submit" class="btn-primary">Add Subscription</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead><tr><th>ID</th><th>User</th><th>Plan</th><th>Price</th><th>Duration</th><th>Start</th><th>End</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($subs as $s): ?>
                        <tr>
                            <td><?= $s['SubscriptionID'] ?></td>
                            <td><?= htmlspecialchars($s['FullName']) ?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($s['Email']) ?></small></td>
                            <td><?= htmlspecialchars($s['PlanName']) ?></td>
                            <td>$<?= number_format($s['Price'], 2) ?></td>
                            <td><?= $s['Duration'] ?> days</td>
                            <td><?= htmlspecialchars($s['StartDate'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($s['EndDate'] ?? '—') ?></td>
                            <td><a href="?delete=<?= $s['SubscriptionID'] ?>" class="btn-danger" onclick="return confirm('Delete?')">Delete</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>
