<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_GET['route_id'])) {
    echo json_encode([]);
    exit();
}

$route_id = $_GET['route_id'];

try {
    // Get route info
    $sql = "SELECT route_code, route_name FROM tricycle_routes WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $route_id]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get stops
    $sql = "SELECT * FROM route_stops WHERE route_id = :route_id ORDER BY stop_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['route_id' => $route_id]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'routeName' => $route['route_code'] . ' - ' . $route['route_name'],
        'stops' => $stops
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>