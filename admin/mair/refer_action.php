<?php
session_start();
require_once '../config/db_connection.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action_id'])) {
    $action_id = intval($_POST['action_id']);
    $referral_agency = $_POST['referral_agency'] ?? '';
    $referral_contact = $_POST['referral_contact'] ?? '';
    $referral_reason = $_POST['referral_reason'] ?? '';
    
    try {
        $pdo->beginTransaction();
        
        // Update the action
        $sql = "UPDATE action_referral_management SET 
                current_status = 'referred',
                referral_agency = :agency,
                referral_contact = :contact,
                referral_reason = :reason,
                referral_date = NOW(),
                referral_status = 'sent',
                updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'agency' => $referral_agency,
            'contact' => $referral_contact,
            'reason' => $referral_reason,
            'id' => $action_id
        ]);
        
        // Log the referral
        $log_sql = "INSERT INTO action_history_logs (action_id, action_type, new_value, description, acted_by) 
                   VALUES (:action_id, 'referral', :agency, 'Action referred to external agency', :acted_by)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([
            'action_id' => $action_id,
            'agency' => $referral_agency,
            'acted_by' => $_SESSION['user_id']
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Action referred successfully'
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
}
?>