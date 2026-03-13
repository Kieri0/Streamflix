<?php
// Lightweight catalogue check — one query, instant response, no open connections.
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }
require_once __DIR__ . '/../php/db.php';
header('Content-Type: application/json');
$row = $conn->query("SELECT COUNT(*) AS cnt, MAX(MovieID) AS latest FROM Movie")->fetch_assoc();
echo json_encode([
    'count'  => (int)($row['cnt']    ?? 0),
    'latest' => (int)($row['latest'] ?? 0),
]);
