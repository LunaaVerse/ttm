<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
}

// Require database connection
require_once '../../config/db_connection.php';

// Get user data
$user_id = $_SESSION['user_id'];
$sql_user = "SELECT * FROM users WHERE id = :user_id";
$stmt_user = $pdo->prepare($sql_user);
$stmt_user->execute(['user_id' => $user_id]);
$user = $stmt_user->fetch(PDO::FETCH_ASSOC);

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'action_referral';

// Initialize variables for form actions
$action_id = isset($_GET['action_id']) ? intval($_GET['action_id']) : 0;
$action = isset($_GET['action']) ? $_GET['action'] : 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add_action'])) {
        // Add new action
        $action_code = 'ARM-' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $sql = "INSERT INTO action_referral_management 
                (action_code, incident_id, incident_type, incident_table, barangay_id, location, description, 
                 current_status, priority_level, action_type, assigned_to, assigned_by, assigned_date, 
                 resolution_plan, resolution_deadline, is_urgent, requires_follow_up, requires_approval, 
                 cost_estimate, notes, created_by) 
                VALUES 
                (:action_code, :incident_id, :incident_type, :incident_table, :barangay_id, :location, :description,
                 :current_status, :priority_level, :action_type, :assigned_to, :assigned_by, :assigned_date,
                 :resolution_plan, :resolution_deadline, :is_urgent, :requires_follow_up, :requires_approval,
                 :cost_estimate, :notes, :created_by)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'action_code' => $action_code,
            'incident_id' => $_POST['incident_id'] ?: NULL,
            'incident_type' => $_POST['incident_type'],
            'incident_table' => $_POST['incident_table'],
            'barangay_id' => $user['barangay_id'] ?: 1,
            'location' => $_POST['location'],
            'description' => $_POST['description'],
            'current_status' => $_POST['current_status'],
            'priority_level' => $_POST['priority_level'],
            'action_type' => $_POST['action_type'],
            'assigned_to' => $_POST['assigned_to'] ?: NULL,
            'assigned_by' => $user_id,
            'assigned_date' => $_POST['assigned_date'] ? date('Y-m-d H:i:s', strtotime($_POST['assigned_date'])) : NULL,
            'resolution_plan' => $_POST['resolution_plan'],
            'resolution_deadline' => $_POST['resolution_deadline'] ? date('Y-m-d', strtotime($_POST['resolution_deadline'])) : NULL,
            'is_urgent' => isset($_POST['is_urgent']) ? 1 : 0,
            'requires_follow_up' => isset($_POST['requires_follow_up']) ? 1 : 0,
            'requires_approval' => isset($_POST['requires_approval']) ? 1 : 0,
            'cost_estimate' => $_POST['cost_estimate'] ?: 0,
            'notes' => $_POST['notes'],
            'created_by' => $user_id
        ]);
        
        if ($result) {
            $action_id = $pdo->lastInsertId();
            // Log the action
            $log_sql = "INSERT INTO action_history_logs (action_id, action_type, new_value, description, acted_by) 
                        VALUES (:action_id, 'status_change', :status, 'New action created', :acted_by)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                'action_id' => $action_id,
                'status' => $_POST['current_status'],
                'acted_by' => $user_id
            ]);
            
            $_SESSION['success'] = "Action created successfully!";
            header("Location: action_referral_management.php?action=view&action_id=$action_id");
            exit();
        } else {
            $_SESSION['error'] = "Failed to create action. Please try again.";
        }
    }
    
    if (isset($_POST['update_action'])) {
        // Update existing action
        $sql = "UPDATE action_referral_management SET 
                current_status = :current_status,
                priority_level = :priority_level,
                action_type = :action_type,
                assigned_to = :assigned_to,
                resolution_plan = :resolution_plan,
                resolution_deadline = :resolution_deadline,
                referral_agency = :referral_agency,
                referral_contact = :referral_contact,
                referral_date = :referral_date,
                referral_reason = :referral_reason,
                referral_status = :referral_status,
                monitoring_schedule = :monitoring_schedule,
                last_monitoring_date = :last_monitoring_date,
                next_monitoring_date = :next_monitoring_date,
                monitoring_notes = :monitoring_notes,
                is_urgent = :is_urgent,
                requires_follow_up = :requires_follow_up,
                cost_estimate = :cost_estimate,
                actual_cost = :actual_cost,
                notes = :notes,
                updated_at = CURRENT_TIMESTAMP
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'current_status' => $_POST['current_status'],
            'priority_level' => $_POST['priority_level'],
            'action_type' => $_POST['action_type'],
            'assigned_to' => $_POST['assigned_to'] ?: NULL,
            'resolution_plan' => $_POST['resolution_plan'],
            'resolution_deadline' => $_POST['resolution_deadline'] ? date('Y-m-d', strtotime($_POST['resolution_deadline'])) : NULL,
            'referral_agency' => $_POST['referral_agency'],
            'referral_contact' => $_POST['referral_contact'],
            'referral_date' => $_POST['referral_date'] ? date('Y-m-d H:i:s', strtotime($_POST['referral_date'])) : NULL,
            'referral_reason' => $_POST['referral_reason'],
            'referral_status' => $_POST['referral_status'],
            'monitoring_schedule' => $_POST['monitoring_schedule'],
            'last_monitoring_date' => $_POST['last_monitoring_date'] ? date('Y-m-d', strtotime($_POST['last_monitoring_date'])) : NULL,
            'next_monitoring_date' => $_POST['next_monitoring_date'] ? date('Y-m-d', strtotime($_POST['next_monitoring_date'])) : NULL,
            'monitoring_notes' => $_POST['monitoring_notes'],
            'is_urgent' => isset($_POST['is_urgent']) ? 1 : 0,
            'requires_follow_up' => isset($_POST['requires_follow_up']) ? 1 : 0,
            'cost_estimate' => $_POST['cost_estimate'] ?: 0,
            'actual_cost' => $_POST['actual_cost'] ?: 0,
            'notes' => $_POST['notes'],
            'id' => $action_id
        ]);
        
        if ($result) {
            // Log the status change if it changed
            if (isset($_POST['old_status']) && $_POST['old_status'] != $_POST['current_status']) {
                $log_sql = "INSERT INTO action_history_logs (action_id, action_type, old_value, new_value, description, acted_by) 
                            VALUES (:action_id, 'status_change', :old_value, :new_value, 'Status updated', :acted_by)";
                $log_stmt = $pdo->prepare($log_sql);
                $log_stmt->execute([
                    'action_id' => $action_id,
                    'old_value' => $_POST['old_status'],
                    'new_value' => $_POST['current_status'],
                    'acted_by' => $user_id
                ]);
            }
            
            $_SESSION['success'] = "Action updated successfully!";
            header("Location: action_referral_management.php?action=view&action_id=$action_id");
            exit();
        } else {
            $_SESSION['error'] = "Failed to update action. Please try again.";
        }
    }
    
    if (isset($_POST['add_referral_agency'])) {
        // Add new referral agency
        $agency_code = 'REF-' . substr($_POST['agency_type'], 0, 3) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $sql = "INSERT INTO referral_agencies 
                (agency_code, agency_name, agency_type, contact_person, contact_number, email, 
                 address, service_hours, response_time_hours, coordination_protocol, 
                 barangay_id, status, notes, created_by) 
                VALUES 
                (:agency_code, :agency_name, :agency_type, :contact_person, :contact_number, :email,
                 :address, :service_hours, :response_time_hours, :coordination_protocol,
                 :barangay_id, :status, :notes, :created_by)";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'agency_code' => $agency_code,
            'agency_name' => $_POST['agency_name'],
            'agency_type' => $_POST['agency_type'],
            'contact_person' => $_POST['contact_person'],
            'contact_number' => $_POST['contact_number'],
            'email' => $_POST['email'],
            'address' => $_POST['address'],
            'service_hours' => $_POST['service_hours'],
            'response_time_hours' => $_POST['response_time_hours'] ?: 24,
            'coordination_protocol' => $_POST['coordination_protocol'],
            'barangay_id' => $user['barangay_id'] ?: 1,
            'status' => $_POST['status'],
            'notes' => $_POST['notes'],
            'created_by' => $user_id
        ]);
        
        if ($result) {
            $_SESSION['success'] = "Referral agency added successfully!";
            header("Location: action_referral_management.php?action=agencies");
            exit();
        } else {
            $_SESSION['error'] = "Failed to add referral agency. Please try again.";
        }
    }
}

// Fetch data based on action
switch ($action) {
    case 'view':
        if ($action_id) {
            // Fetch specific action
            $sql_action = "SELECT arm.*, b.name as barangay_name, 
                          u1.first_name as assigned_to_name, u1.last_name as assigned_to_last,
                          u2.first_name as created_by_name, u2.last_name as created_by_last
                          FROM action_referral_management arm
                          LEFT JOIN barangays b ON arm.barangay_id = b.id
                          LEFT JOIN users u1 ON arm.assigned_to = u1.id
                          LEFT JOIN users u2 ON arm.created_by = u2.id
                          WHERE arm.id = :action_id";
            $stmt_action = $pdo->prepare($sql_action);
            $stmt_action->execute(['action_id' => $action_id]);
            $action_data = $stmt_action->fetch(PDO::FETCH_ASSOC);
            
            // Fetch action history
            $sql_history = "SELECT ahl.*, u.first_name, u.last_name 
                           FROM action_history_logs ahl
                           LEFT JOIN users u ON ahl.acted_by = u.id
                           WHERE ahl.action_id = :action_id 
                           ORDER BY ahl.acted_at DESC";
            $stmt_history = $pdo->prepare($sql_history);
            $stmt_history->execute(['action_id' => $action_id]);
            $action_history = $stmt_history->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
        
    case 'agencies':
        // Fetch referral agencies
        $sql_agencies = "SELECT ra.*, b.name as barangay_name 
                        FROM referral_agencies ra
                        LEFT JOIN barangays b ON ra.barangay_id = b.id
                        WHERE ra.barangay_id = :barangay_id OR ra.barangay_id = 1
                        ORDER BY ra.agency_type, ra.agency_name";
        $stmt_agencies = $pdo->prepare($sql_agencies);
        $stmt_agencies->execute(['barangay_id' => $user['barangay_id'] ?: 1]);
        $agencies = $stmt_agencies->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'reports':
        // Fetch reports data
        $status_filter = isset($_GET['status']) ? $_GET['status'] : '';
        $priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
        $type_filter = isset($_GET['type']) ? $_GET['type'] : '';
        $date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
        $date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
        
        $sql_reports = "SELECT arm.*, b.name as barangay_name, 
                       u.first_name as assigned_to_name, u.last_name as assigned_to_last
                       FROM action_referral_management arm
                       LEFT JOIN barangays b ON arm.barangay_id = b.id
                       LEFT JOIN users u ON arm.assigned_to = u.id
                       WHERE 1=1";
        
        $params = [];
        
        if ($status_filter) {
            $sql_reports .= " AND arm.current_status = :status";
            $params['status'] = $status_filter;
        }
        
        if ($priority_filter) {
            $sql_reports .= " AND arm.priority_level = :priority";
            $params['priority'] = $priority_filter;
        }
        
        if ($type_filter) {
            $sql_reports .= " AND arm.action_type = :type";
            $params['type'] = $type_filter;
        }
        
        if ($date_from) {
            $sql_reports .= " AND DATE(arm.created_at) >= :date_from";
            $params['date_from'] = $date_from;
        }
        
        if ($date_to) {
            $sql_reports .= " AND DATE(arm.created_at) <= :date_to";
            $params['date_to'] = $date_to;
        }
        
        $sql_reports .= " ORDER BY arm.is_urgent DESC, arm.priority_level DESC, arm.created_at DESC";
        
        $stmt_reports = $pdo->prepare($sql_reports);
        $stmt_reports->execute($params);
        $reports = $stmt_reports->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    default:
        // List all actions
        $sql_actions = "SELECT arm.*, b.name as barangay_name, 
                       u.first_name as assigned_to_name, u.last_name as assigned_to_last
                       FROM action_referral_management arm
                       LEFT JOIN barangays b ON arm.barangay_id = b.id
                       LEFT JOIN users u ON arm.assigned_to = u.id
                       WHERE arm.barangay_id = :barangay_id OR arm.barangay_id = 1
                       ORDER BY arm.is_urgent DESC, arm.priority_level DESC, arm.created_at DESC
                       LIMIT 50";
        $stmt_actions = $pdo->prepare($sql_actions);
        $stmt_actions->execute(['barangay_id' => $user['barangay_id'] ?: 1]);
        $actions = $stmt_actions->fetchAll(PDO::FETCH_ASSOC);
        break;
}

// Fetch users for assignment dropdown
$sql_users = "SELECT id, CONCAT(first_name, ' ', last_name) as full_name, role 
              FROM users 
              WHERE role IN ('ADMIN', 'EMPLOYEE', 'TANOD') AND is_verified = 1
              ORDER BY role, first_name";
$stmt_users = $pdo->prepare($sql_users);
$stmt_users->execute();
$users_list = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangays
$sql_barangays = "SELECT id, name FROM barangays ORDER BY name";
$stmt_barangays = $pdo->prepare($sql_barangays);
$stmt_barangays->execute();
$barangays = $stmt_barangays->fetchAll(PDO::FETCH_ASSOC);

// Statistics for dashboard
$sql_stats = "SELECT 
              COUNT(*) as total_actions,
              SUM(CASE WHEN current_status IN ('pending', 'under_review') THEN 1 ELSE 0 END) as pending_actions,
              SUM(CASE WHEN is_urgent = 1 THEN 1 ELSE 0 END) as urgent_actions,
              SUM(CASE WHEN current_status = 'resolved' THEN 1 ELSE 0 END) as resolved_actions
              FROM action_referral_management 
              WHERE barangay_id = :barangay_id OR barangay_id = 1";
$stmt_stats = $pdo->prepare($sql_stats);
$stmt_stats->execute(['barangay_id' => $user['barangay_id'] ?: 1]);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Action & Referral Management | TTM</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Action & Referral Management Specific Styles */
        .action-management-content {
            padding: 24px;
        }
        
        .action-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .action-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .action-subtitle {
            color: var(--text-light);
            margin-top: 4px;
        }
        
        .action-actions {
            display: flex;
            gap: 12px;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-info {
            flex: 1;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .action-filters {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .action-table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .action-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .action-table thead {
            background: var(--background-color);
        }
        
        .action-table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            font-size: 14px;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        .action-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .action-table tbody tr:hover {
            background: var(--background-color);
        }
        
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-review { background: #dbeafe; color: #1e40af; }
        .status-inprogress { background: #f0fdf4; color: #166534; }
        .status-resolved { background: #dcfce7; color: #166534; }
        .status-referred { background: #e9d5ff; color: #6b21a8; }
        .status-closed { background: #f1f5f9; color: #475569; }
        
        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            display: inline-block;
        }
        
        .priority-low { background: #dcfce7; color: #166534; }
        .priority-medium { background: #fef3c7; color: #92400e; }
        .priority-high { background: #fee2e2; color: #991b1b; }
        .priority-critical { background: #fecaca; color: #7f1d1d; }
        .priority-emergency { background: #fca5a5; color: #450a0a; }
        
        .action-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            background: #e0e7ff;
            color: #3730a3;
        }
        
        .action-actions-small {
            display: flex;
            gap: 8px;
        }
        
        .btn-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .btn-edit {
            background: #f0fdf4;
            color: #166534;
        }
        
        .btn-refer {
            background: #e9d5ff;
            color: #6b21a8;
        }
        
        .btn-resolve {
            background: #dcfce7;
            color: #166534;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            padding: 20px;
            gap: 8px;
        }
        
        .page-link {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            cursor: pointer;
        }
        
        .page-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .action-detail-view {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
        }
        
        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 24px;
        }
        
        .detail-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .detail-subtitle {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }
        
        .detail-section {
            background: var(--background-color);
            padding: 20px;
            border-radius: 8px;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-color);
        }
        
        .detail-item {
            margin-bottom: 12px;
        }
        
        .detail-label {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 4px;
        }
        
        .detail-value {
            font-size: 15px;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .history-timeline {
            margin-top: 24px;
        }
        
        .timeline-item {
            display: flex;
            gap: 16px;
            padding: 16px;
            border-left: 3px solid var(--border-color);
            margin-bottom: 16px;
            background: var(--background-color);
            border-radius: 0 8px 8px 0;
        }
        
        .timeline-icon {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--primary-color);
            color: white;
            flex-shrink: 0;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .timeline-time {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-input, .form-select, .form-textarea {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .form-checkbox {
            width: 18px;
            height: 18px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary {
            background: var(--card-bg);
            color: var(--text-color);
            padding: 10px 24px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-danger {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px 24px;
            border-radius: 8px;
            border: 1px solid #fecaca;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab-container {
            margin-bottom: 24px;
        }
        
        .tab-buttons {
            display: flex;
            gap: 4px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-light);
            font-weight: 500;
            cursor: pointer;
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
            padding: 24px 0;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--border-color);
        }
    </style>
</head>
<body>
    <!-- Include your sidebar from admin_dashboard.php -->
    <?php include 'admin_dashboard.php'; ?>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <div class="search-container">
                    <div class="search-box">
                        <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                        </svg>
                        <input type="text" placeholder="Search actions..." class="search-input" id="search-input">
                        <kbd class="search-shortcut">/</kbd>
                    </div>
                </div>
                
                <div class="header-actions">
                    <button class="theme-toggle" id="theme-toggle">
                        <i class='bx bx-moon'></i>
                        <span>Dark Mode</span>
                    </button>
                    <div class="time-display" id="time-display">
                        <i class='bx bx-time time-icon'></i>
                        <span id="current-time">Loading...</span>
                    </div>
                    <div class="user-profile">
                        <div class="user-avatar">
                            <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                        </div>
                        <div class="user-info">
                            <p class="user-name"><?php echo htmlspecialchars($full_name); ?></p>
                            <p class="user-email"><?php echo htmlspecialchars($email); ?></p>
                            <p class="user-role"><?php echo htmlspecialchars($role); ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Action & Referral Management Content -->
        <div class="action-management-content">
            <!-- Header with Title and Actions -->
            <div class="action-header">
                <div>
                    <h1 class="action-title">Action & Referral Management</h1>
                    <p class="action-subtitle">Decide actions (monitor, resolve locally, endorse to police/traffic office)</p>
                </div>
                <div class="action-actions">
                    <?php if ($action == 'view'): ?>
                        <button class="btn-secondary" onclick="window.location.href='action_referral_management.php'">
                            <i class='bx bx-arrow-back'></i> Back to List
                        </button>
                        <button class="btn-primary" onclick="window.location.href='action_referral_management.php?action=add'">
                            <i class='bx bx-plus'></i> New Action
                        </button>
                    <?php elseif ($action == 'agencies'): ?>
                        <button class="btn-secondary" onclick="window.location.href='action_referral_management.php'">
                            <i class='bx bx-arrow-back'></i> Back to Actions
                        </button>
                        <button class="btn-primary" onclick="showAddAgencyModal()">
                            <i class='bx bx-building'></i> Add Agency
                        </button>
                    <?php elseif ($action == 'reports'): ?>
                        <button class="btn-secondary" onclick="window.location.href='action_referral_management.php'">
                            <i class='bx bx-arrow-back'></i> Back to Actions
                        </button>
                        <button class="btn-primary" onclick="window.print()">
                            <i class='bx bx-printer'></i> Print Report
                        </button>
                    <?php else: ?>
                        <button class="btn-primary" onclick="window.location.href='action_referral_management.php?action=add'">
                            <i class='bx bx-plus'></i> New Action
                        </button>
                        <button class="btn-secondary" onclick="window.location.href='action_referral_management.php?action=agencies'">
                            <i class='bx bx-building'></i> Agencies
                        </button>
                        <button class="btn-secondary" onclick="window.location.href='action_referral_management.php?action=reports'">
                            <i class='bx bx-report'></i> Reports
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics Cards -->
            <?php if ($action == 'list'): ?>
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #e0f2fe; color: #0369a1;">
                        <i class='bx bx-list-check'></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['total_actions'] ?? 0; ?></div>
                        <div class="stat-label">Total Actions</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fef3c7; color: #92400e;">
                        <i class='bx bx-time'></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['pending_actions'] ?? 0; ?></div>
                        <div class="stat-label">Pending Actions</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #fee2e2; color: #991b1b;">
                        <i class='bx bx-error'></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['urgent_actions'] ?? 0; ?></div>
                        <div class="stat-label">Urgent Actions</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #dcfce7; color: #166534;">
                        <i class='bx bx-check-circle'></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-value"><?php echo $stats['resolved_actions'] ?? 0; ?></div>
                        <div class="stat-label">Resolved Actions</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Content based on action -->
            <?php if ($action == 'add'): ?>
                <!-- Add New Action Form -->
                <div class="action-detail-view">
                    <div class="detail-header">
                        <div>
                            <h2 class="detail-title">Create New Action</h2>
                            <p class="detail-subtitle">Fill in the details below to create a new action or referral</p>
                        </div>
                    </div>
                    
                    <form method="POST" action="">
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Incident Type *</label>
                                <select name="incident_type" class="form-select" required>
                                    <option value="">Select incident type</option>
                                    <option value="RoadCondition">Road Condition</option>
                                    <option value="Patrol">Patrol Report</option>
                                    <option value="Violation">Violation</option>
                                    <option value="Complaint">Complaint</option>
                                    <option value="Accident">Accident</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Incident ID</label>
                                <input type="number" name="incident_id" class="form-input" placeholder="Optional: Related incident ID">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Location *</label>
                                <input type="text" name="location" class="form-input" required placeholder="Enter location">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Description *</label>
                                <textarea name="description" class="form-textarea" required placeholder="Describe the issue and required action"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Priority Level *</label>
                                <select name="priority_level" class="form-select" required>
                                    <option value="Medium">Medium</option>
                                    <option value="Low">Low</option>
                                    <option value="High">High</option>
                                    <option value="Critical">Critical</option>
                                    <option value="Emergency">Emergency</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Current Status *</label>
                                <select name="current_status" class="form-select" required>
                                    <option value="pending">Pending</option>
                                    <option value="under_review">Under Review</option>
                                    <option value="action_required">Action Required</option>
                                    <option value="in_progress">In Progress</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="referred">Referred</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Action Type *</label>
                                <select name="action_type" class="form-select" required>
                                    <option value="Monitor">Monitor</option>
                                    <option value="Resolve Locally">Resolve Locally</option>
                                    <option value="Endorse to Police">Endorse to Police</option>
                                    <option value="Refer to Traffic Office">Refer to Traffic Office</option>
                                    <option value="Escalate to Municipality">Escalate to Municipality</option>
                                    <option value="Coordinate with Barangay">Coordinate with Barangay</option>
                                    <option value="Issue Warning">Issue Warning</option>
                                    <option value="Impose Fine">Impose Fine</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Assign To</label>
                                <select name="assigned_to" class="form-select">
                                    <option value="">Select assignee</option>
                                    <?php foreach ($users_list as $u): ?>
                                        <option value="<?php echo $u['id']; ?>">
                                            <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['role'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Resolution Plan</label>
                                <textarea name="resolution_plan" class="form-textarea" placeholder="Describe the resolution plan"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Resolution Deadline</label>
                                <input type="date" name="resolution_deadline" class="form-input">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Cost Estimate</label>
                                <input type="number" step="0.01" name="cost_estimate" class="form-input" placeholder="0.00">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Notes</label>
                                <textarea name="notes" class="form-textarea" placeholder="Additional notes"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <div class="checkbox-group">
                                    <input type="checkbox" name="is_urgent" class="form-checkbox" id="is_urgent">
                                    <label for="is_urgent" class="form-label">Mark as Urgent</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="requires_follow_up" class="form-checkbox" id="requires_follow_up">
                                    <label for="requires_follow_up" class="form-label">Requires Follow-up</label>
                                </div>
                                <div class="checkbox-group">
                                    <input type="checkbox" name="requires_approval" class="form-checkbox" id="requires_approval">
                                    <label for="requires_approval" class="form-label">Requires Approval</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="button" class="btn-secondary" onclick="window.location.href='action_referral_management.php'">
                                Cancel
                            </button>
                            <button type="submit" name="add_action" class="btn-primary">
                                <i class='bx bx-save'></i> Create Action
                            </button>
                        </div>
                    </form>
                </div>
                
            <?php elseif ($action == 'view' && isset($action_data)): ?>
                <!-- View Action Details -->
                <div class="action-detail-view">
                    <div class="detail-header">
                        <div>
                            <h2 class="detail-title">Action: <?php echo htmlspecialchars($action_data['action_code']); ?></h2>
                            <p class="detail-subtitle">Created on <?php echo date('F j, Y', strtotime($action_data['created_at'])); ?></p>
                        </div>
                        <div class="action-actions">
                            <button class="btn-secondary" onclick="window.location.href='action_referral_management.php?action=edit&action_id=<?php echo $action_id; ?>'">
                                <i class='bx bx-edit'></i> Edit
                            </button>
                            <button class="btn-primary" onclick="printAction(<?php echo $action_id; ?>)">
                                <i class='bx bx-printer'></i> Print
                            </button>
                        </div>
                    </div>
                    
                    <div class="detail-grid">
                        <div class="detail-section">
                            <h3 class="section-title">Basic Information</h3>
                            <div class="detail-item">
                                <div class="detail-label">Action Code</div>
                                <div class="detail-value"><?php echo htmlspecialchars($action_data['action_code']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Status</div>
                                <div class="detail-value">
                                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $action_data['current_status'])); ?>">
                                        <?php echo htmlspecialchars($action_data['current_status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Priority</div>
                                <div class="detail-value">
                                    <span class="priority-badge priority-<?php echo strtolower($action_data['priority_level']); ?>">
                                        <?php echo htmlspecialchars($action_data['priority_level']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Action Type</div>
                                <div class="detail-value">
                                    <span class="action-badge"><?php echo htmlspecialchars($action_data['action_type']); ?></span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Location</div>
                                <div class="detail-value"><?php echo htmlspecialchars($action_data['location']); ?></div>
                            </div>
                        </div>
                        
                        <div class="detail-section">
                            <h3 class="section-title">Assignment & Resolution</h3>
                            <div class="detail-item">
                                <div class="detail-label">Assigned To</div>
                                <div class="detail-value">
                                    <?php if ($action_data['assigned_to_name']): ?>
                                        <?php echo htmlspecialchars($action_data['assigned_to_name'] . ' ' . $action_data['assigned_to_last']); ?>
                                    <?php else: ?>
                                        Not assigned
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Resolution Plan</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($action_data['resolution_plan'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Deadline</div>
                                <div class="detail-value">
                                    <?php echo $action_data['resolution_deadline'] ? date('F j, Y', strtotime($action_data['resolution_deadline'])) : 'Not set'; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Cost Estimate</div>
                                <div class="detail-value">₱ <?php echo number_format($action_data['cost_estimate'], 2); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($action_data['referral_agency']): ?>
                        <div class="detail-section">
                            <h3 class="section-title">Referral Information</h3>
                            <div class="detail-item">
                                <div class="detail-label">Referred To</div>
                                <div class="detail-value"><?php echo htmlspecialchars($action_data['referral_agency']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Contact</div>
                                <div class="detail-value"><?php echo htmlspecialchars($action_data['referral_contact']); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Referral Date</div>
                                <div class="detail-value">
                                    <?php echo $action_data['referral_date'] ? date('F j, Y H:i', strtotime($action_data['referral_date'])) : 'Not set'; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Referral Status</div>
                                <div class="detail-value">
                                    <span class="status-badge status-<?php echo strtolower($action_data['referral_status']); ?>">
                                        <?php echo htmlspecialchars($action_data['referral_status']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Referral Reason</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($action_data['referral_reason'])); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="detail-section">
                            <h3 class="section-title">Monitoring & Notes</h3>
                            <div class="detail-item">
                                <div class="detail-label">Monitoring Schedule</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($action_data['monitoring_schedule'])); ?></div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Last Monitoring</div>
                                <div class="detail-value">
                                    <?php echo $action_data['last_monitoring_date'] ? date('F j, Y', strtotime($action_data['last_monitoring_date'])) : 'Not monitored'; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Next Monitoring</div>
                                <div class="detail-value">
                                    <?php echo $action_data['next_monitoring_date'] ? date('F j, Y', strtotime($action_data['next_monitoring_date'])) : 'Not scheduled'; ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Notes</div>
                                <div class="detail-value"><?php echo nl2br(htmlspecialchars($action_data['notes'])); ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Action History -->
                    <?php if (!empty($action_history)): ?>
                    <div class="history-timeline">
                        <h3 class="section-title">Action History</h3>
                        <?php foreach ($action_history as $history): ?>
                        <div class="timeline-item">
                            <div class="timeline-icon">
                                <i class='bx bx-history'></i>
                            </div>
                            <div class="timeline-content">
                                <div class="timeline-title">
                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $history['action_type']))); ?>
                                    <?php if ($history['old_value']): ?>
                                        : <?php echo htmlspecialchars($history['old_value']); ?> → <?php echo htmlspecialchars($history['new_value']); ?>
                                    <?php else: ?>
                                        : <?php echo htmlspecialchars($history['new_value']); ?>
                                    <?php endif; ?>
                                </div>
                                <div class="timeline-time">
                                    <?php echo date('F j, Y H:i', strtotime($history['acted_at'])); ?> 
                                    by <?php echo htmlspecialchars($history['first_name'] . ' ' . $history['last_name']); ?>
                                </div>
                                <?php if ($history['description']): ?>
                                    <p><?php echo htmlspecialchars($history['description']); ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
            <?php elseif ($action == 'agencies'): ?>
                <!-- Referral Agencies List -->
                <div class="action-table-container">
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" onclick="switchTab('all-agencies')">All Agencies</button>
                            <button class="tab-button" onclick="switchTab('police-agencies')">Police</button>
                            <button class="tab-button" onclick="switchTab('traffic-agencies')">Traffic Office</button>
                            <button class="tab-button" onclick="switchTab('municipal-agencies')">Municipality</button>
                        </div>
                    </div>
                    
                    <div class="tab-content active" id="all-agencies">
                        <table class="action-table">
                            <thead>
                                <tr>
                                    <th>Agency Code</th>
                                    <th>Agency Name</th>
                                    <th>Type</th>
                                    <th>Contact Person</th>
                                    <th>Contact Number</th>
                                    <th>Response Time</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($agencies)): ?>
                                    <?php foreach ($agencies as $agency): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($agency['agency_code']); ?></td>
                                        <td><?php echo htmlspecialchars($agency['agency_name']); ?></td>
                                        <td>
                                            <span class="action-badge"><?php echo htmlspecialchars($agency['agency_type']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($agency['contact_person']); ?></td>
                                        <td><?php echo htmlspecialchars($agency['contact_number']); ?></td>
                                        <td><?php echo htmlspecialchars($agency['response_time_hours']); ?> hours</td>
                                        <td>
                                            <span class="status-badge <?php echo $agency['status'] == 'Active' ? 'status-inprogress' : 'status-pending'; ?>">
                                                <?php echo htmlspecialchars($agency['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-actions-small">
                                                <button class="btn-small btn-view" onclick="viewAgency(<?php echo $agency['id']; ?>)">
                                                    <i class='bx bx-show'></i> View
                                                </button>
                                                <button class="btn-small btn-edit" onclick="editAgency(<?php echo $agency['id']; ?>)">
                                                    <i class='bx bx-edit'></i> Edit
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8">
                                            <div class="empty-state">
                                                <div class="empty-icon">
                                                    <i class='bx bx-building'></i>
                                                </div>
                                                <h3>No Referral Agencies Found</h3>
                                                <p>Add referral agencies to enable external referrals</p>
                                                <button class="btn-primary" onclick="showAddAgencyModal()">
                                                    <i class='bx bx-plus'></i> Add Agency
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
            <?php elseif ($action == 'reports'): ?>
                <!-- Reports Section -->
                <div class="action-filters">
                    <h3>Filter Reports</h3>
                    <form method="GET" action="">
                        <input type="hidden" name="action" value="reports">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select">
                                    <option value="">All Status</option>
                                    <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="in_progress" <?php echo $status_filter == 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="resolved" <?php echo $status_filter == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="referred" <?php echo $status_filter == 'referred' ? 'selected' : ''; ?>>Referred</option>
                                    <option value="closed" <?php echo $status_filter == 'closed' ? 'selected' : ''; ?>>Closed</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Priority</label>
                                <select name="priority" class="filter-select">
                                    <option value="">All Priorities</option>
                                    <option value="Low" <?php echo $priority_filter == 'Low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="Medium" <?php echo $priority_filter == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="High" <?php echo $priority_filter == 'High' ? 'selected' : ''; ?>>High</option>
                                    <option value="Critical" <?php echo $priority_filter == 'Critical' ? 'selected' : ''; ?>>Critical</option>
                                    <option value="Emergency" <?php echo $priority_filter == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Action Type</label>
                                <select name="type" class="filter-select">
                                    <option value="">All Types</option>
                                    <option value="Monitor" <?php echo $type_filter == 'Monitor' ? 'selected' : ''; ?>>Monitor</option>
                                    <option value="Resolve Locally" <?php echo $type_filter == 'Resolve Locally' ? 'selected' : ''; ?>>Resolve Locally</option>
                                    <option value="Endorse to Police" <?php echo $type_filter == 'Endorse to Police' ? 'selected' : ''; ?>>Endorse to Police</option>
                                    <option value="Refer to Traffic Office" <?php echo $type_filter == 'Refer to Traffic Office' ? 'selected' : ''; ?>>Refer to Traffic Office</option>
                                </select>
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Date From</label>
                                <input type="date" name="date_from" class="filter-input" value="<?php echo $date_from; ?>">
                            </div>
                            <div class="filter-group">
                                <label class="filter-label">Date To</label>
                                <input type="date" name="date_to" class="filter-input" value="<?php echo $date_to; ?>">
                            </div>
                            <div class="filter-group">
                                <button type="submit" class="btn-primary" style="width: 100%;">
                                    <i class='bx bx-filter'></i> Apply Filters
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <div class="action-table-container">
                    <table class="action-table">
                        <thead>
                            <tr>
                                <th>Action Code</th>
                                <th>Description</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Action Type</th>
                                <th>Created Date</th>
                                <th>Deadline</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($reports)): ?>
                                <?php foreach ($reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['action_code']); ?></td>
                                    <td><?php echo htmlspecialchars(substr($report['description'], 0, 50)) . '...'; ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $report['current_status'])); ?>">
                                            <?php echo htmlspecialchars($report['current_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?php echo strtolower($report['priority_level']); ?>">
                                            <?php echo htmlspecialchars($report['priority_level']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="action-badge"><?php echo htmlspecialchars($report['action_type']); ?></span>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($report['created_at'])); ?></td>
                                    <td>
                                        <?php if ($report['resolution_deadline']): ?>
                                            <?php 
                                                $deadline = strtotime($report['resolution_deadline']);
                                                $today = strtotime('today');
                                                $class = $deadline < $today ? 'priority-critical' : 'priority-medium';
                                            ?>
                                            <span class="priority-badge <?php echo $class; ?>">
                                                <?php echo date('M j, Y', $deadline); ?>
                                            </span>
                                        <?php else: ?>
                                            Not set
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7">
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class='bx bx-report'></i>
                                            </div>
                                            <h3>No Reports Found</h3>
                                            <p>Try adjusting your filters</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
            <?php else: ?>
                <!-- Default List View -->
                <?php if (isset($_GET['filter'])): ?>
                <div class="action-filters">
                    <div class="filter-grid">
                        <div class="filter-group">
                            <label class="filter-label">Quick Filter</label>
                            <select class="filter-select" onchange="window.location.href='action_referral_management.php?filter=' + this.value">
                                <option value="">All Actions</option>
                                <option value="urgent" <?php echo $_GET['filter'] == 'urgent' ? 'selected' : ''; ?>>Urgent Only</option>
                                <option value="pending" <?php echo $_GET['filter'] == 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="referred" <?php echo $_GET['filter'] == 'referred' ? 'selected' : ''; ?>>Referred</option>
                                <option value="resolved" <?php echo $_GET['filter'] == 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="action-table-container">
                    <table class="action-table">
                        <thead>
                            <tr>
                                <th>Action Code</th>
                                <th>Description</th>
                                <th>Location</th>
                                <th>Status</th>
                                <th>Priority</th>
                                <th>Action Type</th>
                                <th>Assigned To</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($actions)): ?>
                                <?php foreach ($actions as $action_item): ?>
                                <tr>
                                    <td>
                                        <?php if ($action_item['is_urgent']): ?>
                                            <i class='bx bx-error' style="color: #ef4444; margin-right: 4px;"></i>
                                        <?php endif; ?>
                                        <?php echo htmlspecialchars($action_item['action_code']); ?>
                                    </td>
                                    <td><?php echo htmlspecialchars(substr($action_item['description'], 0, 60)) . '...'; ?></td>
                                    <td><?php echo htmlspecialchars(substr($action_item['location'], 0, 30)); ?></td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $action_item['current_status'])); ?>">
                                            <?php echo htmlspecialchars($action_item['current_status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="priority-badge priority-<?php echo strtolower($action_item['priority_level']); ?>">
                                            <?php echo htmlspecialchars($action_item['priority_level']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="action-badge"><?php echo htmlspecialchars($action_item['action_type']); ?></span>
                                    </td>
                                    <td>
                                        <?php if ($action_item['assigned_to_name']): ?>
                                            <?php echo htmlspecialchars($action_item['assigned_to_name'] . ' ' . $action_item['assigned_to_last']); ?>
                                        <?php else: ?>
                                            Not assigned
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($action_item['created_at'])); ?></td>
                                    <td>
                                        <div class="action-actions-small">
                                            <button class="btn-small btn-view" onclick="window.location.href='action_referral_management.php?action=view&action_id=<?php echo $action_item['id']; ?>'">
                                                <i class='bx bx-show'></i> View
                                            </button>
                                            <?php if ($action_item['action_type'] == 'Monitor'): ?>
                                                <button class="btn-small btn-edit" onclick="window.location.href='action_referral_management.php?action=update&action_id=<?php echo $action_item['id']; ?>'">
                                                    <i class='bx bx-edit'></i> Update
                                                </button>
                                            <?php endif; ?>
                                            <?php if ($action_item['current_status'] == 'pending' || $action_item['current_status'] == 'under_review'): ?>
                                                <button class="btn-small btn-refer" onclick="showReferModal(<?php echo $action_item['id']; ?>)">
                                                    <i class='bx bx-share-alt'></i> Refer
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9">
                                        <div class="empty-state">
                                            <div class="empty-icon">
                                                <i class='bx bx-list-check'></i>
                                            </div>
                                            <h3>No Actions Found</h3>
                                            <p>Create your first action or referral</p>
                                            <button class="btn-primary" onclick="window.location.href='action_referral_management.php?action=add'">
                                                <i class='bx bx-plus'></i> Create Action
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <div class="pagination">
                        <a href="#" class="page-link">«</a>
                        <a href="#" class="page-link active">1</a>
                        <a href="#" class="page-link">2</a>
                        <a href="#" class="page-link">3</a>
                        <a href="#" class="page-link">»</a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Check for success/error messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 3000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['success']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo $_SESSION['error']; ?>'
            });
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>
        
        // Tab switching function
        function switchTab(tabId) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabId).classList.add('active');
            
            // Activate clicked tab button
            event.target.classList.add('active');
        }
        
        // Show add agency modal
        function showAddAgencyModal() {
            Swal.fire({
                title: 'Add New Referral Agency',
                html: `
                    <form id="agencyForm">
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Agency Name *</label>
                            <input type="text" id="agency_name" class="swal2-input" placeholder="Enter agency name" required>
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Agency Type *</label>
                            <select id="agency_type" class="swal2-select" style="width: 100%;" required>
                                <option value="">Select type</option>
                                <option value="Police">Police</option>
                                <option value="Traffic Office">Traffic Office</option>
                                <option value="Municipality">Municipality</option>
                                <option value="Barangay Office">Barangay Office</option>
                                <option value="DPWH">DPWH</option>
                                <option value="MMDA">MMDA</option>
                                <option value="LTO">LTO</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Contact Person</label>
                            <input type="text" id="contact_person" class="swal2-input" placeholder="Enter contact person">
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Contact Number</label>
                            <input type="text" id="contact_number" class="swal2-input" placeholder="Enter contact number">
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Email</label>
                            <input type="email" id="email" class="swal2-input" placeholder="Enter email">
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Service Hours</label>
                            <input type="text" id="service_hours" class="swal2-input" placeholder="e.g., 8AM-5PM (Mon-Fri)">
                        </div>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Add Agency',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const agencyName = document.getElementById('agency_name').value;
                    const agencyType = document.getElementById('agency_type').value;
                    
                    if (!agencyName || !agencyType) {
                        Swal.showValidationMessage('Please fill in required fields');
                        return false;
                    }
                    
                    // Submit form via AJAX
                    const formData = new FormData();
                    formData.append('add_referral_agency', true);
                    formData.append('agency_name', agencyName);
                    formData.append('agency_type', agencyType);
                    formData.append('contact_person', document.getElementById('contact_person').value);
                    formData.append('contact_number', document.getElementById('contact_number').value);
                    formData.append('email', document.getElementById('email').value);
                    formData.append('service_hours', document.getElementById('service_hours').value);
                    formData.append('status', 'Active');
                    
                    return fetch('action_referral_management.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Failed to add agency');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Agency added successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }
        
        // Show refer modal
        function showReferModal(actionId) {
            Swal.fire({
                title: 'Refer Action to External Agency',
                html: `
                    <form id="referForm">
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Select Agency *</label>
                            <select id="referral_agency" class="swal2-select" style="width: 100%;" required>
                                <option value="">Select agency</option>
                                <option value="Commonwealth Police Station">Commonwealth Police Station</option>
                                <option value="Quezon City Traffic Office">Quezon City Traffic Office</option>
                                <option value="Barangay Hall">Barangay Hall</option>
                                <option value="DPWH">DPWH</option>
                                <option value="Other">Other (specify)</option>
                            </select>
                        </div>
                        <div style="text-align: left; margin-bottom: 15px; display: none;" id="otherAgencyDiv">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Other Agency Name</label>
                            <input type="text" id="other_agency" class="swal2-input" placeholder="Enter agency name">
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Contact Information</label>
                            <input type="text" id="referral_contact" class="swal2-input" placeholder="Enter contact person/number">
                        </div>
                        <div style="text-align: left; margin-bottom: 15px;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Referral Reason *</label>
                            <textarea id="referral_reason" class="swal2-textarea" placeholder="Explain why this needs to be referred" rows="3" required></textarea>
                        </div>
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Submit Referral',
                cancelButtonText: 'Cancel',
                didOpen: () => {
                    document.getElementById('referral_agency').addEventListener('change', function() {
                        document.getElementById('otherAgencyDiv').style.display = 
                            this.value === 'Other' ? 'block' : 'none';
                    });
                },
                preConfirm: () => {
                    let agency = document.getElementById('referral_agency').value;
                    if (agency === 'Other') {
                        agency = document.getElementById('other_agency').value;
                    }
                    const reason = document.getElementById('referral_reason').value;
                    
                    if (!agency || !reason) {
                        Swal.showValidationMessage('Please fill in required fields');
                        return false;
                    }
                    
                    // Submit referral via AJAX
                    const formData = new FormData();
                    formData.append('action_id', actionId);
                    formData.append('referral_agency', agency);
                    formData.append('referral_contact', document.getElementById('referral_contact').value);
                    formData.append('referral_reason', reason);
                    
                    return fetch('ajax/refer_action.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success) {
                            throw new Error(data.message || 'Failed to submit referral');
                        }
                        return data;
                    })
                    .catch(error => {
                        Swal.showValidationMessage(`Request failed: ${error}`);
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Success!',
                        text: 'Action referred successfully',
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.reload();
                    });
                }
            });
        }
        
        // Print action details
        function printAction(actionId) {
            const printWindow = window.open('print_action.php?id=' + actionId, '_blank');
            printWindow.focus();
        }
        
        // Search functionality
        document.getElementById('search-input').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.action-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Real-time GMT+8 clock
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
        }
        
        updateTime();
        setInterval(updateTime, 1000);
        
        // Dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');
        const themeText = themeToggle.querySelector('span');
        
        themeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
            }
        });
    </script>
</body>
</html>