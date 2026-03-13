<?php
session_start();
if (!isset($_SESSION['user_id']) || empty($_SESSION['is_admin'])) {
    header('Location: ../login.php'); exit;
}
require_once __DIR__ . '/../php/db.php';
