<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_GET['id'])) {
    echo json_encode(null);
    exit();
}

$associationId = $_GET['id'];

try {
    $sql = "SELECT * FROM tricycle_associations WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $associationId]);
    $association = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($association);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>