<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did = (int) $_GET['delete'];
    $infoStmt = $conn->prepare("SELECT CategoryName FROM Category WHERE CategoryID=?");
    $infoStmt->bind_param("i", $did);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();
    beginTransaction($conn);
    try {
        $s = $conn->prepare("DELETE FROM Category WHERE CategoryID=?");
        $s->bind_param("i", $did);
        if (!$s->execute()) throw new Exception($conn->error);
        $s->close();
        auditLog($conn, 'Category', 'DELETE', $did, $performedBy,
            "Admin deleted category: {$info['CategoryName']} (ID={$did})");
        commitTransaction($conn);
        $msg = 'Category deleted.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error deleting category.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['category_name']);
    beginTransaction($conn);
    try {
        $ins = $conn->prepare("INSERT INTO Category (CategoryName) VALUES (?)");
        $ins->bind_param("s", $name);
        if (!$ins->execute()) throw new Exception($conn->error);
        $newId = $conn->insert_id;
        $ins->close();
        auditLog($conn, 'Category', 'INSERT', $newId, $performedBy,
            "Admin added category: {$name}");
        commitTransaction($conn);
        $msg = 'Category added.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error adding category.';
    }
}
$cats = $conn->query("SELECT * FROM Category ORDER BY CategoryID DESC")->fetch_all(MYSQLI_ASSOC); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Categories – Admin | StreamFlix</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>
    <div class="admin-layout"><?php include __DIR__ . '/sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-topbar">
                <h2> Categories</h2>
            </div>
            <?php if ($msg): ?>
                <div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <div class="form-section">
                <h3>Add Category</h3>
                <form method="POST">
                    <div class="field" style="max-width:320px"><label>Category Name</label><input type="text"
                            name="category_name" required></div><button type="submit" class="btn-primary"
                        style="margin-top:8px">Add Category</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cats as $c): ?>
                            <tr>
                                <td><?= $c['CategoryID'] ?></td>
                                <td><?= htmlspecialchars($c['CategoryName']) ?></td>
                                <td><a href="?delete=<?= $c['CategoryID'] ?>" class="btn-danger"
                                        onclick="return confirm('Delete?')">Delete</a></td>
                            </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>

</html>