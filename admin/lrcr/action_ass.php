<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is ADMIN
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
}

// Require database connection
require_once '../../config/db_connection.php';

// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = :user_id LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page
$current_page = 'road-assignment';

// Pagination
$limit = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// Filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$assigned_to_filter = isset($_GET['assigned_to']) ? $_GET['assigned_to'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$barangay_filter = isset($_GET['barangay']) ? $_GET['barangay'] : '';

// Build query with filters
$where_conditions = ["r.status IN ('Verified', 'Assigned', 'In Progress')"];
$params = [];

if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "r.status = :status";
    $params[':status'] = $status_filter;
}

if ($assigned_to_filter && $assigned_to_filter !== 'all') {
    if ($assigned_to_filter === 'unassigned') {
        $where_conditions[] = "r.assigned_to IS NULL";
    } else {
        $where_conditions[] = "r.assigned_to = :assigned_to";
        $params[':assigned_to'] = $assigned_to_filter;
    }
}

if ($priority_filter && $priority_filter !== 'all') {
    $where_conditions[] = "r.priority = :priority";
    $params[':priority'] = $priority_filter;
}

if ($barangay_filter && $barangay_filter !== 'all') {
    $where_conditions[] = "r.barangay = :barangay";
    $params[':barangay'] = $barangay_filter;
}

if ($date_from) {
    $where_conditions[] = "r.report_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "r.report_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM road_condition_reports r $where_clause";
$stmt = $pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Get reports with filters and pagination
$sql = "SELECT r.*, 
               u.first_name as reporter_first_name, 
               u.last_name as reporter_last_name,
               u.email as reporter_email,
               v.first_name as verifier_first_name,
               v.last_name as verifier_last_name,
               a.first_name as assignee_first_name,
               a.last_name as assignee_last_name,
               t.first_name as tanod_first_name,
               t.last_name as tanod_last_name
        FROM road_condition_reports r
        LEFT JOIN users u ON r.reporter_id = u.id
        LEFT JOIN users v ON r.verified_by = v.id
        LEFT JOIN users a ON r.assigned_to = a.id
        LEFT JOIN users t ON r.tanod_follow_up = t.id
        $where_clause
        ORDER BY 
            CASE r.priority 
                WHEN 'Emergency' THEN 1
                WHEN 'High' THEN 2
                WHEN 'Medium' THEN 3
                WHEN 'Low' THEN 4
                ELSE 5
            END,
            r.report_date DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique barangays for filter dropdown
$barangay_sql = "SELECT DISTINCT barangay FROM road_condition_reports WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
$barangay_stmt = $pdo->query($barangay_sql);
$barangays = $barangay_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get all staff and tanod for assignment
$staff_sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name, role FROM users WHERE role IN ('EMPLOYEE', 'ADMIN') AND is_verified = 1 ORDER BY first_name";
$staff_stmt = $pdo->query($staff_sql);
$staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$tanod_sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE role = 'TANOD' AND is_verified = 1 ORDER BY first_name";
$tanod_stmt = $pdo->query($tanod_sql);
$tanods = $tanod_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available personnel for filter dropdown
$all_personnel = array_merge($staff_members, $tanods);

// Get statistics
try {
    $stats_sql = "SELECT 
        SUM(CASE WHEN assigned_to IS NULL THEN 1 ELSE 0 END) as unassigned,
        SUM(CASE WHEN assigned_to IS NOT NULL THEN 1 ELSE 0 END) as assigned,
        SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN priority = 'Emergency' AND status IN ('Verified', 'Assigned', 'In Progress') THEN 1 ELSE 0 END) as emergency_pending,
        SUM(CASE WHEN priority = 'High' AND status IN ('Verified', 'Assigned', 'In Progress') THEN 1 ELSE 0 END) as high_pending,
        SUM(CASE WHEN status = 'Assigned' AND assigned_date <= DATE_SUB(NOW(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) as overdue
        FROM road_condition_reports
        WHERE status IN ('Verified', 'Assigned', 'In Progress')";
    
    $stats_stmt = $pdo->query($stats_sql);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    $stats = array_merge([
        'unassigned' => 0,
        'assigned' => 0,
        'in_progress' => 0,
        'emergency_pending' => 0,
        'high_pending' => 0,
        'overdue' => 0
    ], $stats ?: []);
    
} catch (PDOException $e) {
    $stats = [
        'unassigned' => 0,
        'assigned' => 0,
        'in_progress' => 0,
        'emergency_pending' => 0,
        'high_pending' => 0,
        'overdue' => 0
    ];
    error_log("Stats query error: " . $e->getMessage());
}

// Handle form actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $report_id = $_POST['report_id'];
        $response = ['success' => false, 'message' => ''];
        
        try {
            switch ($_POST['action']) {
                case 'assign':
                    $sql = "UPDATE road_condition_reports 
                            SET status = 'Assigned', 
                                assigned_to = :assigned_to, 
                                assigned_date = NOW(),
                                priority = :priority,
                                updated_at = NOW()
                            WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $success = $stmt->execute([
                        ':assigned_to' => $_POST['assigned_to'],
                        ':priority' => $_POST['priority'],
                        ':id' => $report_id
                    ]);
                    
                    if ($success) {
                        // Log assignment
                        $log_sql = "INSERT INTO assignment_logs 
                                   (report_id, assigned_by, assigned_to, assignment_date, notes)
                                   VALUES (:report_id, :assigned_by, :assigned_to, NOW(), :notes)";
                        $log_stmt = $pdo->prepare($log_sql);
                        $log_stmt->execute([
                            ':report_id' => $report_id,
                            ':assigned_by' => $user_id,
                            ':assigned_to' => $_POST['assigned_to'],
                            ':notes' => $_POST['assignment_notes'] ?? 'Assigned by admin'
                        ]);
                        
                        $response = ['success' => true, 'message' => 'Report assigned successfully'];
                    }
                    break;
                    
                case 'bulk_assign':
                    $report_ids = explode(',', $_POST['report_ids']);
                    $assigned_to = $_POST['assigned_to'];
                    $priority = $_POST['priority'];
                    $notes = $_POST['bulk_notes'] ?? 'Bulk assignment by admin';
                    
                    $pdo->beginTransaction();
                    
                    foreach ($report_ids as $id) {
                        if (!empty(trim($id))) {
                            $sql = "UPDATE road_condition_reports 
                                    SET status = 'Assigned', 
                                        assigned_to = :assigned_to, 
                                        assigned_date = NOW(),
                                        priority = :priority,
                                        updated_at = NOW()
                                    WHERE id = :id";
                            $stmt = $pdo->prepare($sql);
                            $stmt->execute([
                                ':assigned_to' => $assigned_to,
                                ':priority' => $priority,
                                ':id' => $id
                            ]);
                            
                            // Log each assignment
                            $log_sql = "INSERT INTO assignment_logs 
                                       (report_id, assigned_by, assigned_to, assignment_date, notes)
                                       VALUES (:report_id, :assigned_by, :assigned_to, NOW(), :notes)";
                            $log_stmt = $pdo->prepare($log_sql);
                            $log_stmt->execute([
                                ':report_id' => $id,
                                ':assigned_by' => $user_id,
                                ':assigned_to' => $assigned_to,
                                ':notes' => $notes
                            ]);
                        }
                    }
                    
                    $pdo->commit();
                    $response = ['success' => true, 'message' => 'Bulk assignment completed'];
                    break;
                    
                case 'update_status':
                    $new_status = $_POST['status'];
                    $sql = "UPDATE road_condition_reports 
                            SET status = :status,
                                updated_at = NOW()";
                    
                    if ($new_status == 'In Progress') {
                        $sql .= ", assigned_date = NOW()";
                    }
                    
                    $sql .= " WHERE id = :id";
                    
                    $stmt = $pdo->prepare($sql);
                    $success = $stmt->execute([
                        ':status' => $new_status,
                        ':id' => $report_id
                    ]);
                    
                    $response = ['success' => $success, 'message' => 'Status updated successfully'];
                    break;
                    
                case 'reassign':
                    $sql = "UPDATE road_condition_reports 
                            SET assigned_to = :assigned_to, 
                                assigned_date = NOW(),
                                updated_at = NOW()
                            WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $success = $stmt->execute([
                        ':assigned_to' => $_POST['assigned_to'],
                        ':id' => $report_id
                    ]);
                    
                    if ($success) {
                        // Log reassignment
                        $log_sql = "INSERT INTO assignment_logs 
                                   (report_id, assigned_by, assigned_to, assignment_date, notes)
                                   VALUES (:report_id, :assigned_by, :assigned_to, NOW(), :notes)";
                        $log_stmt = $pdo->prepare($log_sql);
                        $log_stmt->execute([
                            ':report_id' => $report_id,
                            ':assigned_by' => $user_id,
                            ':assigned_to' => $_POST['assigned_to'],
                            ':notes' => $_POST['reassignment_notes'] ?? 'Reassigned by admin'
                        ]);
                        
                        $response = ['success' => true, 'message' => 'Report reassigned successfully'];
                    }
                    break;
                    
                case 'set_dispatch':
                    $sql = "UPDATE road_condition_reports 
                            SET dispatch_notes = :notes,
                                dispatch_time = NOW(),
                                updated_at = NOW()
                            WHERE id = :id";
                    $stmt = $pdo->prepare($sql);
                    $success = $stmt->execute([
                        ':notes' => $_POST['dispatch_notes'],
                        ':id' => $report_id
                    ]);
                    
                    $response = ['success' => $success, 'message' => 'Dispatch notes saved'];
                    break;
            }
            
            echo json_encode($response);
            exit();
            
        } catch (PDOException $e) {
            $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
            echo json_encode($response);
            exit();
        }
    }
}

// Get assignment logs for modal
function getAssignmentLogs($pdo, $report_id) {
    $sql = "SELECT al.*, 
                   u1.first_name as assigned_by_first, 
                   u1.last_name as assigned_by_last,
                   u2.first_name as assigned_to_first, 
                   u2.last_name as assigned_to_last
            FROM assignment_logs al
            LEFT JOIN users u1 ON al.assigned_by = u1.id
            LEFT JOIN users u2 ON al.assigned_to = u2.id
            WHERE al.report_id = :report_id
            ORDER BY al.assignment_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':report_id' => $report_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic & Transport Management - Action Assignment</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary-color: #0d9488;
            --primary-dark: #0f766e;
            --secondary-color: #047857;
            --secondary-dark: #065f46;
            --background-color: #f3f4f6;
            --text-color: #1f2937;
            --text-light: #6b7280;
            --border-color: #e5e7eb;
            --card-bg: #ffffff;
            --sidebar-bg: #ffffff;
            
            /* Icon colors */
            --icon-dashboard: #0d9488;
            --icon-route-config: #3b82f6;
            --icon-road-condition: #f59e0b;
            --icon-incident: #ef4444;
            --icon-tanod: #8b5cf6;
            --icon-permit: #10b981;
            --icon-feedback: #f97316;
            --icon-integration: #6366f1;
            --icon-settings: #6b7280;
            --icon-help: #6b7280;
            --icon-logout: #6b7280;
        }
        
        /* Dark mode variables */
        .dark-mode {
            --primary-color: #14b8a6;
            --primary-dark: #0d9488;
            --secondary-color: #10b981;
            --secondary-dark: #047857;
            --background-color: #0f172a;
            --text-color: #f1f5f9;
            --text-light: #94a3b8;
            --border-color: #334155;
            --card-bg: #1e293b;
            --sidebar-bg: #1e293b;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            color: var(--text-color);
            transition: background-color 0.3s, color 0.3s;
        }
        
        /* Layout */
        .container {
            display: flex;
            height: 100vh;
        }
        
        /* Sidebar - Same as previous */
        .sidebar {
            width: 256px;
            background-color: var(--sidebar-bg);
            padding: 24px;
            display: flex;
            flex-direction: column;
            overflow-y: auto;
            transition: background-color 0.3s;
            border-right: 1px solid var(--border-color);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 40px;
        }
        
        .logo-icon {
            width: 50px;
            height: 40px;
            background-color: var(--primary-color);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .logo-text {
            font-size: 20px;
            font-weight: 600;
            font-family: 'Inter';
        }
        
        .menu-section {
            flex: 1;
        }
        
        .menu-title {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.025em;
            margin-bottom: 16px;
        }
        
        .menu-items {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .menu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 8px;
            transition: background-color 0.2s;
            cursor: pointer;
        }
        
        .menu-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .menu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .menu-item.active {
            background-color: #f0fdfa;
            color: var(--primary-color);
            border-left: 4px solid var(--primary-color);
        }
        
        .dark-mode .menu-item.active {
            background-color: rgba(20, 184, 166, 0.1);
        }
        
        .menu-item-badge {
            margin-left: auto;
            background-color: var(--primary-color);
            color: white;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        .menu-icon {
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .icon-box {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .icon-box-dashboard {
            background-color: rgba(13, 148, 136, 0.1);
            color: var(--icon-dashboard);
        }
        
        .icon-box-route-config {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--icon-route-config);
        }
        
        .icon-box-road-condition {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--icon-road-condition);
        }
        
        .icon-box-incident {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--icon-incident);
        }
        
        .icon-box-tanod {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--icon-tanod);
        }
        
        .icon-box-permit {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--icon-permit);
        }
        
        .icon-box-feedback {
            background-color: rgba(249, 115, 22, 0.1);
            color: var(--icon-feedback);
        }
        
        .icon-box-integration {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--icon-integration);
        }
        
        .icon-box-settings {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--icon-settings);
        }
        
        .icon-box-help {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--icon-help);
        }
        
        .icon-box-logout {
            background-color: rgba(107, 114, 128, 0.1);
            color: var(--icon-logout);
        }
        
        .submenu {
            display: none;
            margin-left: 20px;
            margin-top: 8px;
            padding-left: 12px;
            border-left: 2px solid var(--border-color);
        }
        
        .submenu.active {
            display: block;
        }
        
        .submenu-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 12px;
            color: var(--text-color);
            text-decoration: none;
            border-radius: 6px;
            transition: background-color 0.2s;
            font-size: 14px;
        }
        
        .submenu-item:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .submenu-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .submenu-item.active {
            background-color: #f0fdfa;
            color: var(--primary-color);
        }
        
        .dark-mode .submenu-item.active {
            background-color: rgba(20, 184, 166, 0.1);
        }
        
        .dropdown-arrow {
            margin-left: auto;
            transition: transform 0.3s;
        }
        
        .dropdown-arrow.rotated {
            transform: rotate(180deg);
        }
        
        .menu-separator {
            height: 1px;
            background-color: var(--border-color);
            margin: 20px 0;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            overflow: auto;
        }
        
        /* Header - Same as previous */
        .header {
            background-color: var(--card-bg);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 32px;
            transition: background-color 0.3s, border-color 0.3s;
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .search-container {
            display: flex;
            align-items: center;
            gap: 16px;
            flex: 1;
            max-width: 384px;
        }
        
        .search-box {
            position: relative;
            flex: 1;
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: var(--text-light);
        }
        
        .search-input {
            width: 100%;
            padding: 8px 40px 8px 40px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            outline: none;
            background-color: var(--card-bg);
            color: var(--text-color);
            transition: border-color 0.3s, background-color 0.3s;
        }
        
        .search-input:focus {
            border-color: var(--primary-color);
        }
        
        .search-shortcut {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            padding: 2px 8px;
            background-color: var(--background-color);
            color: var(--text-light);
            font-size: 12px;
            border-radius: 4px;
            border: 1px solid var(--border-color);
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .header-button {
            padding: 8px;
            background: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
            color: var(--text-color);
        }
        
        .header-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .header-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .header-button-icon {
            width: 24px;
            height: 24px;
            color: var(--text-color);
        }
        
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-color);
            transition: background-color 0.2s;
        }
        
        .theme-toggle:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .theme-toggle:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .time-display {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            background: none;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            color: var(--text-color);
            font-size: 14px;
            font-weight: 500;
            min-width: 160px;
            justify-content: center;
        }
        
        .time-icon {
            width: 16px;
            height: 16px;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-size: 14px;
            font-weight: 600;
        }
        
        .user-email {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .user-role {
            font-size: 11px;
            color: var(--primary-color);
            font-weight: 500;
            margin-top: 2px;
        }
        
        /* Action Assignment Specific Styles */
        .dashboard-content {
            padding: 32px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary-color);
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .stat-warning {
            border-left-color: #f59e0b;
        }
        
        .stat-danger {
            border-left-color: #ef4444;
        }
        
        .stat-success {
            border-left-color: #10b981;
        }
        
        .filter-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--text-light);
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #0da271;
        }
        
        .btn-warning {
            background-color: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            background-color: #d97706;
        }
        
        .btn-danger {
            background-color: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
        }
        
        .btn-info {
            background-color: #3b82f6;
            color: white;
        }
        
        .btn-info:hover {
            background-color: #2563eb;
        }
        
        .bulk-actions {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            display: none;
        }
        
        .bulk-actions.active {
            display: block;
        }
        
        .bulk-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .bulk-count {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .bulk-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .reports-table {
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background-color: var(--background-color);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        tr:hover {
            background-color: var(--background-color);
        }
        
        .report-selected {
            background-color: rgba(13, 148, 136, 0.1);
        }
        
        .checkbox-cell {
            width: 40px;
            text-align: center;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-verified { background-color: #d1fae5; color: #065f46; }
        .status-assigned { background-color: #dbeafe; color: #1e40af; }
        .status-in-progress { background-color: #e0e7ff; color: #3730a3; }
        
        .severity-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .severity-low { background-color: #d1fae5; color: #065f46; }
        .severity-medium { background-color: #fef3c7; color: #92400e; }
        .severity-high { background-color: #fee2e2; color: #991b1b; }
        .severity-emergency { background-color: #fecaca; color: #7f1d1d; }
        
        .priority-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .priority-low { background-color: #d1fae5; color: #065f46; }
        .priority-medium { background-color: #fef3c7; color: #92400e; }
        .priority-high { background-color: #fee2e2; color: #991b1b; }
        .priority-emergency { background-color: #fecaca; color: #7f1d1d; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 4px 12px;
            font-size: 12px;
        }
        
        .assignee-info {
            font-size: 13px;
            line-height: 1.4;
        }
        
        .assignee-name {
            font-weight: 600;
        }
        
        .assignee-date {
            color: var(--text-light);
            font-size: 11px;
        }
        
        .overdue {
            color: #ef4444;
            font-weight: 600;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.7; }
            100% { opacity: 1; }
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: var(--card-bg);
            margin: 5% auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            border: 1px solid var(--border-color);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
            padding: 20px;
        }
        
        .page-link {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .page-link:hover {
            background-color: var(--background-color);
        }
        
        .page-link.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .notes-box {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            resize: vertical;
            min-height: 100px;
            margin-top: 10px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        .timeline {
            margin: 20px 0;
        }
        
        .timeline-item {
            padding: 10px 0;
            border-left: 2px solid var(--border-color);
            margin-left: 10px;
            padding-left: 20px;
            position: relative;
        }
        
        .timeline-item:before {
            content: '';
            position: absolute;
            left: -6px;
            top: 15px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: var(--primary-color);
        }
        
        .timeline-date {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .timeline-content {
            margin-top: 5px;
        }
        
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .alert-warning {
            background-color: #fef3c7;
            color: #92400e;
            border: 1px solid #fde68a;
        }
        
        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-container {
                grid-template-columns: repeat(3, 1fr);
            }
            
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                padding: 16px;
            }
            
            .stats-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .dashboard-content {
                padding: 16px;
            }
            
            .header-content {
                flex-direction: column;
                gap: 16px;
            }
            
            .search-container {
                max-width: 100%;
            }
            
            .header-actions {
                flex-wrap: wrap;
                justify-content: flex-end;
            }
            
            .time-display {
                min-width: 140px;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 480px) {
            .stats-container {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <i class='bx bxs-traffic' style="color: white;"></i>
                </div>
                <span class="logo-text">TTM System</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button -->
                    <a href="../admin_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item active" onclick="toggleSubmenu('route-setup')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu active">
                        <a href="admin_road_condition.php" class="submenu-item">Road Condition Dashboard</a>
                        <a href="admin_road_assignment.php" class="submenu-item active">Action Assignment</a>
                        <a href="admin_road_condition_analytics.php" class="submenu-item">Historical Records & Analytics</a>
                    </div>
                    
                    <!-- Other menu items remain the same -->
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item" onclick="toggleSubmenu('road-monitoring')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-monitoring" class="submenu">
                        <a href="../btrm/tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="../btrm/driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="../btrm/approval_enforcement.php" class="submenu-item">Enforcement Rules & Analytics</a>
                    </div>
                    
                    <!-- 1.3 Minor Accident & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('patrol-oversight')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Minor Accident & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="patrol-oversight" class="submenu">
                        <a href="#" class="submenu-item">Patrol Maps</a>
                        <a href="#" class="submenu-item">Tanod Shift</a>
                        <a href="#" class="submenu-item">Patrol Efficiency</a>
                    </div>
                    
                    <!-- 1.4 Tanod Patrol Logs for Traffic -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-control')">
                        <div class="icon-box icon-box-tanod">
                            <i class='bx bxs-notepad'></i>
                        </div>
                        <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-control" class="submenu">
                        <a href="#" class="submenu-item">Transport Permits</a>
                        <a href="#" class="submenu-item">Regulatory Updates</a>
                        <a href="#" class="submenu-item">Compliance Verification</a>
                    </div>
                    
                    <!-- 1.5 Permit and Local Regulation Tracking -->
                    <div class="menu-item" onclick="toggleSubmenu('feedback-center')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit and Local Regulation Tracking</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="feedback-center" class="submenu">
                        <a href="#" class="submenu-item">Review Feedback</a>
                        <a href="#" class="submenu-item">Escalate Issues</a>
                        <a href="#" class="submenu-item">Generate Reports</a>
                    </div>
                    
                     <!-- 1.6 Community Feedback Portal -->
                    <div class="menu-item" onclick="toggleSubmenu('community-feedback')">
                        <div class="icon-box icon-box-feedback">
                            <i class='bx bxs-chat'></i>
                        </div>
                        <span class="font-medium">Community Feedback Portal</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="community-feedback" class="submenu">
                        <a href="#" class="submenu-item">Review Feedback</a>
                        <a href="#" class="submenu-item">Escalate Issues</a>
                        <a href="#" class="submenu-item">Generate Reports</a>
                    </div>
                
                     <!-- 1.7 Integration with City & Neighboring Barangays -->
                    <div class="menu-item" onclick="toggleSubmenu('integration')">
                        <div class="icon-box icon-box-integration">
                            <i class='bx bxs-network-chart'></i>
                        </div>
                        <span class="font-medium">Integration with City & Neighboring Barangays</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="integration" class="submenu">
                        <a href="#" class="submenu-item">Coordination</a>
                        <a href="#" class="submenu-item">Data Sharing</a>
                        <a href="#" class="submenu-item">Joint Operations</a>
                    </div>
                </div>
                
                <!-- Separator -->
                <div class="menu-separator"></div>
                
                <!-- Bottom Menu Section -->
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-box-settings">
                            <i class='bx bxs-cog'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../help_support.php" class="menu-item">
                        <div class="icon-box icon-box-help">
                            <i class='bx bxs-help-circle'></i>
                        </div>
                        <span class="font-medium">Help & Support</span>
                    </a>
                    
                    <a href="../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-box-logout">
                            <i class='bx bx-log-out'></i>
                        </div>
                        <span class="font-medium">Logout</span>
                    </a>
                </div>
            </div>
        </div>
        
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
                            <input type="text" placeholder="Search assignments..." class="search-input" id="searchInput">
                            <kbd class="search-shortcut">⌘K</kbd>
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
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                            </svg>
                        </button>
                        <button class="header-button">
                            <svg class="header-button-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                        </button>
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
            
            <!-- Action Assignment Content -->
            <div class="dashboard-content">
                <div class="page-header">
                    <h1 class="page-title">Action Assignment</h1>
                    <p class="page-subtitle">Assign, dispatch, and monitor road condition reports for follow-up</p>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-container">
                    <div class="stat-card stat-warning" onclick="filterByStatus('Verified')">
                        <div class="stat-value"><?php echo $stats['unassigned'] ?? 0; ?></div>
                        <div class="stat-label">Unassigned Reports</div>
                    </div>
                    <div class="stat-card stat-success" onclick="filterByAssigned()">
                        <div class="stat-value"><?php echo $stats['assigned'] ?? 0; ?></div>
                        <div class="stat-label">Assigned Reports</div>
                    </div>
                    <div class="stat-card stat-info" onclick="filterByStatus('In Progress')">
                        <div class="stat-value"><?php echo $stats['in_progress'] ?? 0; ?></div>
                        <div class="stat-label">In Progress</div>
                    </div>
                    <div class="stat-card stat-danger" onclick="filterByPriority('Emergency')">
                        <div class="stat-value"><?php echo $stats['emergency_pending'] ?? 0; ?></div>
                        <div class="stat-label">Emergency Pending</div>
                    </div>
                    <div class="stat-card stat-warning" onclick="filterByPriority('High')">
                        <div class="stat-value"><?php echo $stats['high_pending'] ?? 0; ?></div>
                        <div class="stat-label">High Priority Pending</div>
                    </div>
                    <div class="stat-card stat-danger" onclick="filterByOverdue()">
                        <div class="stat-value"><?php echo $stats['overdue'] ?? 0; ?></div>
                        <div class="stat-label">Overdue Assignments</div>
                    </div>
                </div>
                
                <!-- Bulk Actions Panel -->
                <div class="bulk-actions" id="bulkActions">
                    <div class="bulk-header">
                        <h3>Bulk Actions <span class="bulk-count" id="selectedCount">0</span> reports selected</h3>
                        <button class="btn btn-secondary btn-sm" onclick="clearSelection()">Clear Selection</button>
                    </div>
                    <form method="POST" id="bulkForm" class="bulk-form" onsubmit="return submitBulkAssignment()">
                        <input type="hidden" name="action" value="bulk_assign">
                        <input type="hidden" name="report_ids" id="bulkReportIds">
                        
                        <div class="form-group">
                            <label>Assign To</label>
                            <select name="assigned_to" class="form-control" required>
                                <option value="">Select Person</option>
                                <?php foreach ($staff_members as $staff): ?>
                                    <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?> (Staff)</option>
                                <?php endforeach; ?>
                                <?php foreach ($tanods as $tanod): ?>
                                    <option value="<?php echo $tanod['id']; ?>"><?php echo htmlspecialchars($tanod['name']); ?> (Tanod)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Priority Level</label>
                            <select name="priority" class="form-control" required>
                                <option value="Low">Low</option>
                                <option value="Medium" selected>Medium</option>
                                <option value="High">High</option>
                                <option value="Emergency">Emergency</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Assignment Notes</label>
                            <textarea name="bulk_notes" class="notes-box" placeholder="Enter notes for this bulk assignment..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-user-plus'></i> Assign Selected
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-container">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status" class="form-control">
                                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="Verified" <?php echo $status_filter == 'Verified' ? 'selected' : ''; ?>>Verified (Unassigned)</option>
                                <option value="Assigned" <?php echo $status_filter == 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="In Progress" <?php echo $status_filter == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Assigned To</label>
                            <select name="assigned_to" class="form-control">
                                <option value="all">All Assignments</option>
                                <option value="unassigned" <?php echo $assigned_to_filter == 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
                                <?php foreach ($all_personnel as $person): ?>
                                    <option value="<?php echo $person['id']; ?>" <?php echo $assigned_to_filter == $person['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($person['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Priority</label>
                            <select name="priority" class="form-control">
                                <option value="all">All Priorities</option>
                                <option value="Emergency" <?php echo $priority_filter == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                <option value="High" <?php echo $priority_filter == 'High' ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo $priority_filter == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo $priority_filter == 'Low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Barangay</label>
                            <select name="barangay" class="form-control">
                                <option value="all">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $barangay_filter == $barangay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>">
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="admin_road_assignment.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Reports Table -->
                <div class="reports-table">
                    <?php if (empty($reports)): ?>
                        <div class="empty-state">
                            <div class="empty-icon">
                                <i class='bx bx-user-check'></i>
                            </div>
                            <h3>No reports to assign</h3>
                            <p>All verified reports have been assigned or no reports match your filters.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <th class="checkbox-cell">
                                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                                    </th>
                                    <th>ID</th>
                                    <th>Report Date</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Condition</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Assigned To</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): 
                                    $isOverdue = false;
                                    if ($report['assigned_date']) {
                                        $assignedDate = new DateTime($report['assigned_date']);
                                        $now = new DateTime();
                                        $interval = $assignedDate->diff($now);
                                        $isOverdue = $interval->days > 3;
                                    }
                                ?>
                                    <tr id="row-<?php echo $report['id']; ?>" data-report-id="<?php echo $report['id']; ?>">
                                        <td class="checkbox-cell">
                                            <input type="checkbox" class="report-checkbox" value="<?php echo $report['id']; ?>" onchange="updateSelection()">
                                        </td>
                                        <td>#<?php echo $report['id']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($report['location']); ?></td>
                                        <td><?php echo htmlspecialchars($report['barangay']); ?></td>
                                        <td><?php echo htmlspecialchars($report['condition_type']); ?></td>
                                        <td>
                                            <span class="severity-badge severity-<?php echo strtolower($report['severity']); ?>">
                                                <?php echo $report['severity']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $report['status'])); ?>">
                                                <?php echo $report['status']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="priority-badge priority-<?php echo strtolower($report['priority']); ?>">
                                                <?php echo $report['priority']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($report['assignee_first_name']): ?>
                                                <div class="assignee-info">
                                                    <span class="assignee-name"><?php echo htmlspecialchars($report['assignee_first_name'] . ' ' . $report['assignee_last_name']); ?></span>
                                                    <div class="assignee-date">
                                                        Assigned: <?php echo date('M d', strtotime($report['assigned_date'])); ?>
                                                        <?php if ($isOverdue): ?>
                                                            <span class="overdue">(Overdue)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Not assigned</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-sm btn-info" onclick="viewReport(<?php echo $report['id']; ?>)">
                                                    <i class='bx bx-show'></i> View
                                                </button>
                                                
                                                <?php if ($report['status'] == 'Verified'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="showAssignModal(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-user-plus'></i> Assign
                                                    </button>
                                                <?php elseif ($report['status'] == 'Assigned'): ?>
                                                    <button class="btn btn-sm btn-warning" onclick="showReassignModal(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-transfer'></i> Reassign
                                                    </button>
                                                    <button class="btn btn-sm btn-info" onclick="showStatusModal(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-edit'></i> Status
                                                    </button>
                                                <?php elseif ($report['status'] == 'In Progress'): ?>
                                                    <button class="btn btn-sm btn-success" onclick="showDispatchModal(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-send'></i> Dispatch
                                                    </button>
                                                <?php endif; ?>
                                                
                                                <button class="btn btn-sm btn-info" onclick="showAssignmentHistory(<?php echo $report['id']; ?>)">
                                                    <i class='bx bx-history'></i> History
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                            <div class="pagination">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($_GET); ?>" class="page-link">Previous</a>
                                <?php endif; ?>
                                
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>" class="page-link <?php echo $i == $page ? 'active' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($_GET); ?>" class="page-link">Next</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Report Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Report Details</h2>
                <button class="close-modal" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="reportDetails">
                <!-- Details loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <!-- Assign Modal -->
    <div id="assignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Assign Report</h2>
                <button class="close-modal" onclick="closeModal('assignModal')">&times;</button>
            </div>
            <form method="POST" id="assignForm" onsubmit="return submitAssignment(event)">
                <input type="hidden" name="report_id" id="assign_report_id">
                <input type="hidden" name="action" value="assign">
                
                <div class="form-group">
                    <label>Assign To (Staff/Tanod)</label>
                    <select name="assigned_to" class="form-control" required id="assign_to_select">
                        <option value="">Select Person</option>
                        <?php foreach ($staff_members as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?> (Staff)</option>
                        <?php endforeach; ?>
                        <?php foreach ($tanods as $tanod): ?>
                            <option value="<?php echo $tanod['id']; ?>"><?php echo htmlspecialchars($tanod['name']); ?> (Tanod)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Priority Level</label>
                    <select name="priority" class="form-control" required>
                        <option value="Low">Low</option>
                        <option value="Medium" selected>Medium</option>
                        <option value="High">High</option>
                        <option value="Emergency">Emergency</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Assignment Notes</label>
                    <textarea name="assignment_notes" class="notes-box" placeholder="Enter any special instructions..."></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Assign Report</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Reassign Modal -->
    <div id="reassignModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Reassign Report</h2>
                <button class="close-modal" onclick="closeModal('reassignModal')">&times;</button>
            </div>
            <form method="POST" id="reassignForm" onsubmit="return submitReassignment(event)">
                <input type="hidden" name="report_id" id="reassign_report_id">
                <input type="hidden" name="action" value="reassign">
                
                <div class="form-group">
                    <label>Reassign To</label>
                    <select name="assigned_to" class="form-control" required>
                        <option value="">Select Person</option>
                        <?php foreach ($staff_members as $staff): ?>
                            <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?> (Staff)</option>
                        <?php endforeach; ?>
                        <?php foreach ($tanods as $tanod): ?>
                            <option value="<?php echo $tanod['id']; ?>"><?php echo htmlspecialchars($tanod['name']); ?> (Tanod)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Reassignment Reason</label>
                    <textarea name="reassignment_notes" class="notes-box" placeholder="Why is this report being reassigned?" required></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Reassign Report</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('reassignModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Status Update Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Update Report Status</h2>
                <button class="close-modal" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <form method="POST" id="statusForm" onsubmit="return updateStatus(event)">
                <input type="hidden" name="report_id" id="status_report_id">
                <input type="hidden" name="action" value="update_status">
                
                <div class="form-group">
                    <label>New Status</label>
                    <select name="status" class="form-control" required>
                        <option value="Assigned">Assigned</option>
                        <option value="In Progress">In Progress</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Update Status</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Dispatch Modal -->
    <div id="dispatchModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Dispatch Instructions</h2>
                <button class="close-modal" onclick="closeModal('dispatchModal')">&times;</button>
            </div>
            <form method="POST" id="dispatchForm" onsubmit="return submitDispatch(event)">
                <input type="hidden" name="report_id" id="dispatch_report_id">
                <input type="hidden" name="action" value="set_dispatch">
                
                <div class="form-group">
                    <label>Dispatch Notes</label>
                    <textarea name="dispatch_notes" class="notes-box" placeholder="Enter dispatch instructions, equipment needed, safety precautions..." required></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" class="btn btn-success">
                        <i class='bx bx-send'></i> Send Dispatch
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('dispatchModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Assignment History Modal -->
    <div id="historyModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Assignment History</h2>
                <button class="close-modal" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <div id="historyContent">
                <!-- History loaded via AJAX -->
            </div>
        </div>
    </div>
    
    <script>
        // Selected reports for bulk actions
        let selectedReports = new Set();
        
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        function viewReport(reportId) {
            fetch(`get_report_details.php?id=${reportId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('reportDetails').innerHTML = `
                            <div class="report-details">
                                <div class="detail-row">
                                    <span class="detail-label">Report ID:</span>
                                    <span class="detail-value">#${data.report.id}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Location:</span>
                                    <span class="detail-value">${data.report.location}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Barangay:</span>
                                    <span class="detail-value">${data.report.barangay}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Condition:</span>
                                    <span class="detail-value">${data.report.condition_type}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Description:</span>
                                    <span class="detail-value">${data.report.description}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Status:</span>
                                    <span class="detail-value">${data.report.status}</span>
                                </div>
                                <div class="detail-row">
                                    <span class="detail-label">Priority:</span>
                                    <span class="detail-value">${data.report.priority}</span>
                                </div>
                            </div>
                        `;
                    } else {
                        document.getElementById('reportDetails').innerHTML = `
                            <div class="alert alert-error">
                                <i class='bx bx-error'></i> ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('reportDetails').innerHTML = `
                        <div class="alert alert-error">
                            <i class='bx bx-error'></i> Error loading report details
                        </div>
                    `;
                });
            
            openModal('viewModal');
        }
        
        function showAssignModal(reportId) {
            document.getElementById('assign_report_id').value = reportId;
            openModal('assignModal');
        }
        
        function showReassignModal(reportId) {
            document.getElementById('reassign_report_id').value = reportId;
            openModal('reassignModal');
        }
        
        function showStatusModal(reportId) {
            document.getElementById('status_report_id').value = reportId;
            openModal('statusModal');
        }
        
        function showDispatchModal(reportId) {
            document.getElementById('dispatch_report_id').value = reportId;
            openModal('dispatchModal');
        }
        
        function showAssignmentHistory(reportId) {
            fetch(`get_assignment_history.php?report_id=${reportId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        let historyHTML = '<div class="timeline">';
                        if (data.history.length > 0) {
                            data.history.forEach(log => {
                                historyHTML += `
                                    <div class="timeline-item">
                                        <div class="timeline-date">${log.assignment_date}</div>
                                        <div class="timeline-content">
                                            <strong>Assigned by:</strong> ${log.assigned_by_name}<br>
                                            <strong>Assigned to:</strong> ${log.assigned_to_name}<br>
                                            <strong>Notes:</strong> ${log.notes || 'No notes'}
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            historyHTML += '<p>No assignment history found.</p>';
                        }
                        historyHTML += '</div>';
                        document.getElementById('historyContent').innerHTML = historyHTML;
                    } else {
                        document.getElementById('historyContent').innerHTML = `
                            <div class="alert alert-error">
                                <i class='bx bx-error'></i> ${data.message}
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    document.getElementById('historyContent').innerHTML = `
                        <div class="alert alert-error">
                            <i class='bx bx-error'></i> Error loading history
                        </div>
                    `;
                });
            
            openModal('historyModal');
        }
        
        // Bulk selection functions
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.report-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                if (checkbox.checked) {
                    selectedReports.add(cb.value);
                    document.getElementById(`row-${cb.value}`).classList.add('report-selected');
                } else {
                    selectedReports.delete(cb.value);
                    document.getElementById(`row-${cb.value}`).classList.remove('report-selected');
                }
            });
            updateSelection();
        }
        
        function updateSelection() {
            selectedReports.clear();
            const checkboxes = document.querySelectorAll('.report-checkbox:checked');
            checkboxes.forEach(cb => {
                selectedReports.add(cb.value);
                document.getElementById(`row-${cb.value}`).classList.add('report-selected');
            });
            
            const allCheckboxes = document.querySelectorAll('.report-checkbox');
            allCheckboxes.forEach(cb => {
                if (!cb.checked) {
                    document.getElementById(`row-${cb.value}`).classList.remove('report-selected');
                }
            });
            
            document.getElementById('selectedCount').textContent = selectedReports.size;
            document.getElementById('bulkReportIds').value = Array.from(selectedReports).join(',');
            
            if (selectedReports.size > 0) {
                document.getElementById('bulkActions').classList.add('active');
            } else {
                document.getElementById('bulkActions').classList.remove('active');
            }
            
            // Update select all checkbox
            document.getElementById('selectAll').checked = selectedReports.size === allCheckboxes.length;
        }
        
        function clearSelection() {
            selectedReports.clear();
            document.querySelectorAll('.report-checkbox').forEach(cb => {
                cb.checked = false;
                document.getElementById(`row-${cb.value}`).classList.remove('report-selected');
            });
            document.getElementById('selectAll').checked = false;
            updateSelection();
        }
        
        // Form submission functions
        function submitAssignment(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeModal('assignModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred');
            });
            
            return false;
        }
        
        function submitBulkAssignment() {
            if (selectedReports.size === 0) {
                showAlert('warning', 'Please select at least one report');
                return false;
            }
            
            const formData = new FormData(document.getElementById('bulkForm'));
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    clearSelection();
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.message);
                }
            })
            .catch(error => {
                showAlert('error', 'An error occurred');
            });
            
            return false;
        }
        
        function submitReassignment(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeModal('reassignModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.message);
                }
            });
            
            return false;
        }
        
        function updateStatus(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeModal('statusModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.message);
                }
            });
            
            return false;
        }
        
        function submitDispatch(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', data.message);
                    closeModal('dispatchModal');
                    setTimeout(() => location.reload(), 1500);
                } else {
                    showAlert('error', data.message);
                }
            });
            
            return false;
        }
        
        // Filter functions
        function filterByStatus(status) {
            window.location.href = `?status=${status}`;
        }
        
        function filterByAssigned() {
            window.location.href = `?assigned_to=all`;
        }
        
        function filterByPriority(priority) {
            window.location.href = `?priority=${priority}`;
        }
        
        function filterByOverdue() {
            // This would need a custom filter implementation
            showAlert('info', 'Showing overdue assignments');
        }
        
        // Alert function
        function showAlert(type, message) {
            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.innerHTML = `
                <i class='bx ${type === 'success' ? 'bx-check-circle' : type === 'error' ? 'bx-error' : 'bx-info-circle'}'></i>
                ${message}
            `;
            
            document.querySelector('.dashboard-content').prepend(alert);
            
            setTimeout(() => {
                alert.remove();
            }, 5000);
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            }
        }
        
        // Dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');
        const themeText = themeToggle.querySelector('span');
        
        themeToggle.addEventListener('click', function() {
            document.body.classList.toggle('dark-mode');
            
            if (document.body.classList.contains('dark-mode')) {
                themeIcon.className = 'bx bx-sun';
                themeText.textContent = 'Light Mode';
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        });
        
        // Check for saved theme preference
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            themeIcon.className = 'bx bx-sun';
            themeText.textContent = 'Light Mode';
        }
        
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
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.trim();
                if (searchTerm) {
                    // Implement search
                    alert(`Searching for: ${searchTerm}`);
                    this.value = '';
                }
                e.preventDefault();
            }
        });
        
        // Add hover effects to cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>