<?php
session_start();
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Route ID is required']);
    exit();
}

$route_id = $_GET['id'];

try {
    $sql = "SELECT tr.*, b.name as barangay_name 
            FROM tricycle_routes tr
            LEFT JOIN barangays b ON tr.barangay_id = b.id
            WHERE tr.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $route_id]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($route) {
        echo json_encode($route);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Route not found']);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}