<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Incident ID required']);
    exit();
}

$incident_id = intval($_GET['id']);

try {
    $sql = "SELECT il.*, 
            b.name as barangay_name,
            tr.route_name,
            CONCAT(u1.first_name, ' ', u1.last_name) as reporter_name,
            CONCAT(u2.first_name, ' ', u2.last_name) as verified_by_name,
            CONCAT(u3.first_name, ' ', u3.last_name) as resolved_by_name
            FROM incident_logging il
            LEFT JOIN barangays b ON il.barangay_id = b.id
            LEFT JOIN tricycle_routes tr ON il.route_id = tr.id
            LEFT JOIN users u1 ON il.reported_by = u1.id
            LEFT JOIN users u2 ON il.verified_by = u2.id
            LEFT JOIN users u3 ON il.resolved_by = u3.id
            WHERE il.id = :id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $incident_id]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($incident) {
        echo json_encode(['success' => true, 'incident' => $incident]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Incident not found']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}