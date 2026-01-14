<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_GET['id'])) {
    echo json_encode(null);
    exit();
}

$driverId = $_GET['id'];

try {
    $sql = "SELECT * FROM tricycle_drivers WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $driverId]);
    $driver = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($driver);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>