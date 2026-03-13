<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/../php/db.php';
$id = isset($_GET['id']) && is_numeric($_GET['id']) ? (int)$_GET['id'] : null;
if ($id) {
    $stmt = $conn->prepare("SELECT GenreID, GenreName, Description FROM Genre WHERE GenreID = ?");
    $stmt->bind_param("i", $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    echo json_encode($row ? ['status'=>'success','data'=>$row] : ['status'=>'error','message'=>'Not found.']);
} else {
    $rows = $conn->query("SELECT GenreID, GenreName, Description FROM Genre ORDER BY GenreName")->fetch_all(MYSQLI_ASSOC);
    echo json_encode(['status'=>'success','count'=>count($rows),'data'=>$rows]);
}
