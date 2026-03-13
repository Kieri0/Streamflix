<?php require_once __DIR__ . '/auth_guard.php';
$msg         = '';
$performedBy = $_SESSION['email'] ?? 'SYSTEM';

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $did  = (int) $_GET['delete'];
    $infoStmt = $conn->prepare("SELECT GenreName FROM Genre WHERE GenreID=?");
    $infoStmt->bind_param("i", $did);
    $infoStmt->execute();
    $info = $infoStmt->get_result()->fetch_assoc();
    $infoStmt->close();
    beginTransaction($conn);
    try {
        $s = $conn->prepare("DELETE FROM Genre WHERE GenreID=?");
        $s->bind_param("i", $did);
        if (!$s->execute()) throw new Exception($conn->error);
        $s->close();
        auditLog($conn, 'Genre', 'DELETE', $did, $performedBy,
            "Admin deleted genre: {$info['GenreName']} (ID={$did})");
        commitTransaction($conn);
        $msg = 'Genre deleted.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error deleting genre.';
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['genre_name']);
    $desc = trim($_POST['description']);
    beginTransaction($conn);
    try {
        $ins = $conn->prepare("INSERT INTO Genre (GenreName,Description) VALUES (?,?)");
        $ins->bind_param("ss", $name, $desc);
        if (!$ins->execute()) throw new Exception($conn->error);
        $newId = $conn->insert_id;
        $ins->close();
        auditLog($conn, 'Genre', 'INSERT', $newId, $performedBy,
            "Admin added genre: {$name}");
        commitTransaction($conn);
        $msg = 'Genre added.';
    } catch (Exception $e) {
        rollbackTransaction($conn);
        $msg = 'Error adding genre.';
    }
}
$genres = $conn->query("SELECT * FROM Genre ORDER BY GenreID DESC")->fetch_all(MYSQLI_ASSOC); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Genres – Admin | StreamFlix</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>
    <div class="admin-layout"><?php include __DIR__ . '/sidebar.php'; ?>
        <main class="admin-main">
            <div class="admin-topbar">
                <h2> Genres</h2>
            </div>
            <?php if ($msg): ?>
                <div class="alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
            <div class="form-section">
                <h3>Add Genre</h3>
                <form method="POST">
                    <div class="form-row">
                        <div class="field"><label>Genre Name</label><input type="text" name="genre_name" required></div>
                        <div class="field"><label>Description</label><input type="text" name="description"></div>
                    </div><button type="submit" class="btn-primary">Add Genre</button>
                </form>
            </div>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($genres as $g): ?>
                            <tr>
                                <td><?= $g['GenreID'] ?></td>
                                <td><?= htmlspecialchars($g['GenreName']) ?></td>
                                <td><?= htmlspecialchars($g['Description'] ?? '—') ?></td>
                                <td><a href="?delete=<?= $g['GenreID'] ?>" class="btn-danger"
                                        onclick="return confirm('Delete?')">Delete</a></td>
                            </tr><?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>

</html>