<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$user_id = $_SESSION['user_id'];
$check_id = $_GET['id'] ?? 0;

try {
    // Get compliance check details
    $check_sql = "SELECT rcc.*, b.name as barangay_name
                  FROM route_compliance_checks rcc
                  JOIN barangays b ON rcc.barangay_id = b.id
                  WHERE rcc.id = :check_id AND rcc.tanod_id = :tanod_id";
    
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute(['check_id' => $check_id, 'tanod_id' => $user_id]);
    $check = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$check) {
        echo json_encode(['success' => false, 'message' => 'Compliance check not found or access denied']);
        exit();
    }
    
    // Get violation for this check
    $violation_sql = "SELECT cv.*, rv.violation_name
                      FROM compliance_violations cv
                      LEFT JOIN route_violations rv ON cv.violation_id = rv.id
                      WHERE cv.check_id = :check_id";
    
    $violation_stmt = $pdo->prepare($violation_sql);
    $violation_stmt->execute(['check_id' => $check_id]);
    $violation = $violation_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get evidence for violation
    $evidence = [];
    if ($violation) {
        $evidence_sql = "SELECT * FROM violation_evidence WHERE violation_id = :violation_id";
        $evidence_stmt = $pdo->prepare($evidence_sql);
        $evidence_stmt->execute(['violation_id' => $violation['id']]);
        $evidence = $evidence_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Format date for datetime-local input
    $check['check_date'] = date('Y-m-d\TH:i', strtotime($check['check_date']));
    
    echo json_encode([
        'success' => true,
        'check' => $check,
        'violation' => $violation,
        'evidence' => $evidence
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>