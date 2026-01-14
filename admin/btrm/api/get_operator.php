<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_GET['id'])) {
    echo json_encode(null);
    exit();
}

$operatorId = $_GET['id'];

try {
    $sql = "SELECT * FROM tricycle_operators WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $operatorId]);
    $operator = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($operator);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>