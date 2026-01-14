<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
}

try {
    $sql = "SELECT il.*, 
            b.name as barangay_name,
            tr.route_name,
            CONCAT(u1.first_name, ' ', u1.last_name) as reporter_name
            FROM incident_logging il
            LEFT JOIN barangays b ON il.barangay_id = b.id
            LEFT JOIN tricycle_routes tr ON il.route_id = tr.id
            LEFT JOIN users u1 ON il.reported_by = u1.id
            ORDER BY il.created_at DESC";
    
    $stmt = $pdo->query($sql);
    $incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $format = isset($_GET['format']) ? $_GET['format'] : 'csv';
    
    if ($format === 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="incidents_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, [
            'Log Code', 'Title', 'Type', 'Severity', 'Priority', 'Barangay', 'Location',
            'Status', 'Start Time', 'Estimated End', 'Reported By', 'Reported Date'
        ]);
        
        foreach ($incidents as $incident) {
            fputcsv($output, [
                $incident['log_code'],
                $incident['title'],
                $incident['log_type'],
                $incident['severity'],
                $incident['priority'],
                $incident['barangay_name'],
                $incident['location'],
                $incident['status'],
                $incident['start_time'],
                $incident['estimated_end_time'],
                $incident['reporter_name'],
                $incident['created_at']
            ]);
        }
        
        fclose($output);
    }
    // Add Excel export if needed
} catch (Exception $e) {
    echo "Error exporting data: " . $e->getMessage();
}