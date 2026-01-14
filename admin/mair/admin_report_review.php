<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Function to get user data
function getUserData($pdo, $user_id = null) {
    if ($user_id) {
        $sql = "SELECT * FROM users WHERE id = :user_id AND is_verified = 1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $sql = "SELECT * FROM users WHERE is_verified = 1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user = getUserData($pdo, $user_id);
} else {
    header('Location: ../login.php');
    exit();
}

// Check if user is admin
if ($user['role'] !== 'ADMIN') {
    header('Location: ../unauthorized.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page
$current_page = basename($_SERVER['PHP_SELF']);

// Handle actions for report review
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $report_id = $_POST['report_id'] ?? 0;
        $action = $_POST['action'];
        $notes = $_POST['notes'] ?? '';
        
        switch ($action) {
            case 'verify':
                $stmt = $pdo->prepare("UPDATE road_condition_reports SET status = 'Verified', verified_by = ?, verified_date = NOW() WHERE id = ?");
                $stmt->execute([$user_id, $report_id]);
                $_SESSION['success'] = "Report verified successfully!";
                break;
                
            case 'assign':
                $assigned_to = $_POST['assigned_to'] ?? null;
                if ($assigned_to) {
                    $stmt = $pdo->prepare("UPDATE road_condition_reports SET status = 'Assigned', assigned_to = ?, assigned_date = NOW() WHERE id = ?");
                    $stmt->execute([$assigned_to, $report_id]);
                    $_SESSION['success'] = "Report assigned successfully!";
                }
                break;
                
            case 'reject':
                $stmt = $pdo->prepare("UPDATE road_condition_reports SET status = 'Rejected', resolution_notes = ? WHERE id = ?");
                $stmt->execute([$notes, $report_id]);
                $_SESSION['success'] = "Report rejected successfully!";
                break;
                
            case 'resolve':
                $stmt = $pdo->prepare("UPDATE road_condition_reports SET status = 'Resolved', resolved_date = NOW(), resolution_notes = ? WHERE id = ?");
                $stmt->execute([$notes, $report_id]);
                $_SESSION['success'] = "Report marked as resolved!";
                break;
                
            case 'archive':
                // Create an archive record - we'll use a simple flag since we don't have archive_categories table
                $stmt = $pdo->prepare("UPDATE road_condition_reports SET status = 'Archived', is_archived = 1 WHERE id = ?");
                $stmt->execute([$report_id]);
                $_SESSION['success'] = "Report archived successfully!";
                break;
                
            case 'unarchive':
                $stmt = $pdo->prepare("UPDATE road_condition_reports SET is_archived = 0 WHERE id = ?");
                $stmt->execute([$report_id]);
                $_SESSION['success'] = "Report unarchived successfully!";
                break;
                
            case 'delete':
                // First get report info for message
                $stmt = $pdo->prepare("SELECT id FROM road_condition_reports WHERE id = ?");
                $stmt->execute([$report_id]);
                $report = $stmt->fetch();
                
                // Delete report
                $stmt = $pdo->prepare("DELETE FROM road_condition_reports WHERE id = ?");
                $stmt->execute([$report_id]);
                
                $_SESSION['success'] = "Report permanently deleted!";
                break;
        }
        
        // Log the action in enforcement_logs
        if (in_array($action, ['verify', 'assign', 'reject', 'resolve', 'archive', 'unarchive'])) {
            $action_map = [
                'verify' => 'Verified',
                'assign' => 'Assigned',
                'reject' => 'Rejected',
                'resolve' => 'Resolved',
                'archive' => 'Archived',
                'unarchive' => 'Unarchived'
            ];
            
            $log_action = $action_map[$action] ?? $action;
            $stmt = $pdo->prepare("INSERT INTO enforcement_logs (log_type, reference_id, action, details, acted_by) VALUES ('Violation', ?, ?, ?, ?)");
            $stmt->execute([$report_id, $log_action, $notes, $user_id]);
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    }
}

// Check if we're viewing archives
$is_archive_page = strpos($current_page, 'archive') !== false;

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_barangay = $_GET['barangay'] ?? '';
$filter_date_from = $_GET['date_from'] ?? '';
$filter_date_to = $_GET['date_to'] ?? '';
$filter_severity = $_GET['severity'] ?? '';
$filter_incident_type = $_GET['incident_type'] ?? '';
$filter_keyword = $_GET['keyword'] ?? '';
$show_archived = isset($_GET['show_archived']) && $_GET['show_archived'] == '1';

// Build query based on page type
if ($is_archive_page) {
    // For archive page, we're showing archived road condition reports
    $query = "SELECT r.*, b.name as barangay_name, 
              r.reporter_name as reporter_fullname,
              CONCAT(u.first_name, ' ', u.last_name) as assigned_name
              FROM road_condition_reports r
              LEFT JOIN barangays b ON r.barangay = b.name
              LEFT JOIN users u ON r.assigned_to = u.id
              WHERE r.status = 'Archived' OR r.is_tanod_report = 1";
              
    $params = [];
    
    if ($filter_date_from) {
        $query .= " AND DATE(r.report_date) >= ?";
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $query .= " AND DATE(r.report_date) <= ?";
        $params[] = $filter_date_to;
    }
    
    if ($filter_keyword) {
        $query .= " AND (r.location LIKE ? OR r.description LIKE ?)";
        $search_term = "%{$filter_keyword}%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $query .= " ORDER BY r.report_date DESC";
} else {
    // For regular review page
    $query = "SELECT r.*, b.name as barangay_name, 
              r.reporter_name as reporter_fullname,
              CONCAT(u.first_name, ' ', u.last_name) as assigned_name
              FROM road_condition_reports r
              LEFT JOIN barangays b ON r.barangay = b.name
              LEFT JOIN users u ON r.assigned_to = u.id
              WHERE 1=1";
              
    $params = [];
    
    if ($filter_status) {
        $query .= " AND r.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_barangay) {
        $query .= " AND r.barangay = ?";
        $params[] = $filter_barangay;
    }
    
    if ($filter_date_from) {
        $query .= " AND DATE(r.report_date) >= ?";
        $params[] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $query .= " AND DATE(r.report_date) <= ?";
        $params[] = $filter_date_to;
    }
    
    if ($filter_severity) {
        $query .= " AND r.severity = ?";
        $params[] = $filter_severity;
    }
    
    if ($filter_incident_type) {
        $query .= " AND r.incident_type = ?";
        $params[] = $filter_incident_type;
    }
    
    if (!$show_archived) {
        $query .= " AND r.status != 'Archived'";
    }
    
    $query .= " ORDER BY r.report_date DESC";
}

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$reports = $stmt->fetchAll();

// Get barangays for filter
$stmt = $pdo->query("SELECT * FROM barangays ORDER BY name");
$barangays = $stmt->fetchAll();

// Get employees for assignment
$stmt = $pdo->query("SELECT id, CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE role IN ('EMPLOYEE', 'ADMIN', 'TANOD') ORDER BY first_name");
$employees = $stmt->fetchAll();

// Statistics
if ($is_archive_page) {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total_archived,
        MIN(report_date) as oldest_archive,
        MAX(report_date) as newest_archive
        FROM road_condition_reports 
        WHERE status = 'Archived' OR is_tanod_report = 1");
    $stats = $stmt->fetch();
} else {
    $stmt = $pdo->query("SELECT 
        COUNT(*) as total_reports,
        SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'Verified' THEN 1 ELSE 0 END) as verified,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN severity = 'Emergency' THEN 1 ELSE 0 END) as emergency
        FROM road_condition_reports WHERE status != 'Archived'");
    $stats = $stmt->fetch();
}

// Fetch dashboard statistics for sidebar
try {
    $sql_active_incidents = "SELECT COUNT(*) as count FROM road_condition_reports WHERE status IN ('Pending', 'Verified', 'Assigned', 'In Progress')";
    $stmt = $pdo->query($sql_active_incidents);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $dashboard_stats['active_incidents'] = $row['count'] ?? 0;

    $sql_total_incidents = "SELECT COUNT(*) as count FROM road_condition_reports";
    $stmt = $pdo->query($sql_total_incidents);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $dashboard_stats['total_incidents'] = $row['count'] ?? 0;

    $sql_patrol_routes = "SELECT COUNT(*) as count FROM tricycle_routes WHERE status = 'Active'";
    $stmt = $pdo->query($sql_patrol_routes);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $dashboard_stats['patrol_routes'] = $row['count'] ?? 0;
} catch (Exception $e) {
    $dashboard_stats['active_incidents'] = 0;
    $dashboard_stats['total_incidents'] = 0;
    $dashboard_stats['patrol_routes'] = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $is_archive_page ? 'Official Records & Archiving' : 'Report Review & Validation'; ?> - TTM Admin</title>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* All your existing CSS styles remain the same */
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
            --icon-review: #8b5cf6;
            --icon-archive: #10b981;
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
        
        /* Sidebar */
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
        
        .icon-box-review {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--icon-review);
        }
        
        .icon-box-archive {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--icon-archive);
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
        
        /* Separator */
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
        
        /* Header */
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
        
        /* Module Content */
        .module-content {
            padding: 32px;
        }
        
        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .module-header h1 {
            color: var(--primary-color);
            font-size: 28px;
        }
        
        /* Statistics */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .stat-card .subtext {
            font-size: 12px;
            color: var(--text-light);
            margin-top: 5px;
        }
        
        /* Filters */
        .filters {
            background: var(--card-bg);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--text-light);
        }
        
        select, input, textarea {
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 5px;
            font-size: 14px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background-color 0.2s;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-secondary:hover {
            background: var(--secondary-dark);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .btn-outline:hover {
            background: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .btn-outline:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-warning:hover {
            background: #d97706;
        }
        
        .btn-danger {
            background: #dc2626;
            color: white;
        }
        
        .btn-danger:hover {
            background: #b91c1c;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-info {
            background: #3b82f6;
            color: white;
        }
        
        .btn-info:hover {
            background: #2563eb;
        }
        
        /* Reports Table */
        .reports-table {
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--primary-color);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 500;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .dark-mode tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }
        
        /* Badges */
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-verified { background: #dbeafe; color: #1e40af; }
        .status-assigned { background: #e0e7ff; color: #3730a3; }
        .status-inprogress { background: #fef3c7; color: #92400e; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        .status-rejected { background: #fee2e2; color: #991b1b; }
        .status-returned { background: #f3e8ff; color: #6b21a8; }
        .status-archived { background: #f5f5f5; color: #737373; }
        
        .dark-mode .status-pending { background: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .status-verified { background: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .dark-mode .status-assigned { background: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
        .dark-mode .status-inprogress { background: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .status-resolved { background: rgba(16, 185, 129, 0.2); color: #86efac; }
        .dark-mode .status-rejected { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .dark-mode .status-returned { background: rgba(168, 85, 247, 0.2); color: #d8b4fe; }
        .dark-mode .status-archived { background: rgba(115, 115, 115, 0.2); color: #d4d4d4; }
        
        .severity-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .severity-low { background: #d1fae5; color: #065f46; }
        .severity-medium { background: #fef3c7; color: #92400e; }
        .severity-high { background: #fed7d7; color: #991b1b; }
        .severity-emergency { background: #fecaca; color: #7f1d1d; }
        
        .dark-mode .severity-low { background: rgba(16, 185, 129, 0.2); color: #86efac; }
        .dark-mode .severity-medium { background: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .severity-high { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        .dark-mode .severity-emergency { background: rgba(127, 29, 29, 0.2); color: #fca5a5; }
        
        .deletion-warning {
            background: #fee2e2;
            color: #991b1b;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .dark-mode .deletion-warning {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .category-badge {
            padding: 3px 8px;
            background: #e0e7ff;
            color: #3730a3;
            border-radius: 12px;
            font-size: 12px;
        }
        
        .dark-mode .category-badge {
            background: rgba(99, 102, 241, 0.2);
            color: #a5b4fc;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            transition: background-color 0.2s;
        }
        
        /* Alert */
        .alert-warning {
            background: #fef3c7;
            border: 1px solid #f59e0b;
            color: #92400e;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .dark-mode .alert-warning {
            background: rgba(245, 158, 11, 0.2);
            border: 1px solid rgba(245, 158, 11, 0.3);
            color: #fcd34d;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--card-bg);
            padding: 30px;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .modal-header h3 {
            color: var(--primary-color);
        }
        
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-light);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .module-content {
                padding: 16px;
            }
            
            .module-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
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
                white-space: nowrap;
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
                    <img src="../../img/ttm.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <span class="logo-text"> Traffic & Transport Management</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard -->
                    <a href="admin_dashboard.php" class="menu-item <?php echo $current_page == 'admin_dashboard.php' ? 'active' : ''; ?>">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('route-setup')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu">
                        <a href="lrcr/admin_road_condition.php" class="submenu-item">Road Condition Reports</a>
                        <a href="lrcr/action_ass.php" class="submenu-item">Action Assignment</a>
                        <a href="lrcr/admin_road_condition_analytics.php" class="submenu-item">Historical Records & Analytics</a>
                    </div>
                    
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
                        <a href="btrm/tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="btrm/driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="btrm/approval_enforcement.php" class="submenu-item">Enforcement Rules & Analytics</a>
                    </div>
                    
                    <!-- 1.3 Minor Accident & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-reporting')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Minor Accident & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-reporting" class="submenu">
                        <a href="mair/admin_report_review.php" class="submenu-item">Report Review & Validation</a>
                        <a href="mair/admin_archive.php" class="submenu-item <?php echo $is_archive_page ? 'active' : ''; ?>">Official Records & Archiving</a>
                        <a href="mair/admin_analytics.php" class="submenu-item">Incident Analytics</a>
                    </div>
                    
                    <!-- 1.4 Tanod Patrol Logs for Traffic -->
                    <div class="menu-item" onclick="toggleSubmenu('patrol-logs')">
                        <div class="icon-box icon-box-tanod">
                            <i class='bx bxs-notepad'></i>
                        </div>
                        <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="patrol-logs" class="submenu">
                        <a href="tpl/admin_patrol_logs.php" class="submenu-item">Patrol Logs</a>
                        <a href="tpl/admin_shift_management.php" class="submenu-item">Shift Management</a>
                        <a href="tpl/admin_patrol_analytics.php" class="submenu-item">Patrol Analytics</a>
                    </div>
                    
                    <!-- 1.5 Permit and Local Regulation Tracking -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-tracking')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit and Local Regulation Tracking</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-tracking" class="submenu">
                        <a href="plrt/admin_permit_management.php" class="submenu-item">Permit Management</a>
                        <a href="plrt/admin_regulation_tracking.php" class="submenu-item">Regulation Tracking</a>
                        <a href="plrt/admin_compliance_reports.php" class="submenu-item">Compliance Reports</a>
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
                        <a href="cfp/admin_feedback_review.php" class="submenu-item">Feedback Review</a>
                        <a href="cfp/admin_issue_escalation.php" class="submenu-item">Issue Escalation</a>
                        <a href="cfp/admin_feedback_reports.php" class="submenu-item">Feedback Reports</a>
                    </div>
                </div>
                
                <!-- Separator -->
                <div class="menu-separator"></div>
                
                <!-- Bottom Menu Section -->
                <div class="menu-items">
                    <a href="settings.php" class="menu-item">
                        <div class="icon-box icon-box-settings">
                            <i class='bx bxs-cog'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="help_support.php" class="menu-item">
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
                            <input type="text" placeholder="Search reports or archives" class="search-input" id="globalSearch">
                            <kbd class="search-shortcut">🔍</kbd>
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
            
            <!-- Module Content -->
            <div class="module-content">
                <!-- Module Header -->
                <div class="module-header">
                    <h1>
                        <i class='bx <?php echo $is_archive_page ? 'bx-archive' : 'bx-task'; ?>'></i>
                        <?php echo $is_archive_page ? 'Official Records & Archiving' : 'Report Review & Validation'; ?>
                    </h1>
                    <div>
                        <?php if ($is_archive_page): ?>
                            <a href="admin_report_review.php" class="btn btn-primary">
                                <i class='bx bx-task'></i> Report Review
                            </a>
                        <?php else: ?>
                            <a href="admin_archive.php" class="btn btn-secondary">
                                <i class='bx bx-archive'></i> View Archives
                            </a>
                        <?php endif; ?>
                        <a href="admin_dashboard.php" class="btn btn-outline">
                            <i class='bx bx-arrow-back'></i> Back to Dashboard
                        </a>
                    </div>
                </div>
                
                <?php if ($is_archive_page && isset($upcoming_deletions) && $upcoming_deletions > 0): ?>
                    <div class="alert-warning">
                        <i class='bx bx-time-five'></i>
                        <strong>Note:</strong> <?php echo $upcoming_deletions; ?> records are scheduled for deletion within 30 days.
                        <a href="?show_upcoming=1" style="margin-left: auto;">View upcoming deletions</a>
                    </div>
                <?php endif; ?>
                
                <!-- Statistics -->
                <div class="stats-grid">
                    <?php if ($is_archive_page): ?>
                        <div class="stat-card">
                            <h3>Total Archived Records</h3>
                            <div class="value"><?php echo $stats['total_archived'] ?? 0; ?></div>
                            <div class="subtext">Across all categories</div>
                        </div>
                        <div class="stat-card">
                            <h3>Categories Used</h3>
                            <div class="value"><?php echo $stats['categories_used'] ?? 0; ?></div>
                            <div class="subtext">Different archive categories</div>
                        </div>
                        <div class="stat-card">
                            <h3>Oldest Archive</h3>
                            <div class="value">
                                <?php if (isset($stats['oldest_archive']) && $stats['oldest_archive']): 
                                    echo date('M d, Y', strtotime($stats['oldest_archive']));
                                else: 
                                    echo 'N/A';
                                endif; ?>
                            </div>
                            <div class="subtext">First archived record</div>
                        </div>
                        <div class="stat-card">
                            <h3>Newest Archive</h3>
                            <div class="value">
                                <?php if (isset($stats['newest_archive']) && $stats['newest_archive']): 
                                    echo date('M d, Y', strtotime($stats['newest_archive']));
                                else: 
                                    echo 'N/A';
                                endif; ?>
                            </div>
                            <div class="subtext">Most recent archive</div>
                        </div>
                    <?php else: ?>
                        <div class="stat-card">
                            <h3>Total Reports</h3>
                            <div class="value"><?php echo $stats['total_reports'] ?? 0; ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Pending Review</h3>
                            <div class="value"><?php echo $stats['pending'] ?? 0; ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Verified</h3>
                            <div class="value"><?php echo $stats['verified'] ?? 0; ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Resolved</h3>
                            <div class="value"><?php echo $stats['resolved'] ?? 0; ?></div>
                        </div>
                        <div class="stat-card">
                            <h3>Emergency Cases</h3>
                            <div class="value"><?php echo $stats['emergency'] ?? 0; ?></div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Filters -->
                <div class="filters">
                    <form method="GET" id="filterForm">
                        <div class="filter-row">
                            <?php if ($is_archive_page): ?>
                                <div class="filter-group">
                                    <label>Archive Category</label>
                                    <select name="category">
                                        <option value="">All Categories</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>" <?php echo $filter_category == $category['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($category['category_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Archive Date From</label>
                                    <input type="date" name="date_from" value="<?php echo $filter_date_from; ?>">
                                </div>
                                <div class="filter-group">
                                    <label>Archive Date To</label>
                                    <input type="date" name="date_to" value="<?php echo $filter_date_to; ?>">
                                </div>
                            <?php else: ?>
                                <div class="filter-group">
                                    <label>Status</label>
                                    <select name="status">
                                        <option value="">All Status</option>
                                        <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                        <option value="Verified" <?php echo $filter_status == 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                        <option value="Assigned" <?php echo $filter_status == 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                                        <option value="In Progress" <?php echo $filter_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                        <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                        <option value="Rejected" <?php echo $filter_status == 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                                        <option value="Returned" <?php echo $filter_status == 'Returned' ? 'selected' : ''; ?>>Returned</option>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Barangay</label>
                                    <select name="barangay">
                                        <option value="">All Barangays</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" <?php echo $filter_barangay == $barangay['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="filter-group">
                                    <label>Severity</label>
                                    <select name="severity">
                                        <option value="">All Severity</option>
                                        <option value="Low" <?php echo $filter_severity == 'Low' ? 'selected' : ''; ?>>Low</option>
                                        <option value="Medium" <?php echo $filter_severity == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                        <option value="High" <?php echo $filter_severity == 'High' ? 'selected' : ''; ?>>High</option>
                                        <option value="Emergency" <?php echo $filter_severity == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                    </select>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="filter-row">
                            <?php if ($is_archive_page): ?>
                                <div class="filter-group">
                                    <label>Search Keyword</label>
                                    <input type="text" name="keyword" value="<?php echo htmlspecialchars($filter_keyword); ?>" placeholder="Search incident code, location...">
                                </div>
                                <div class="filter-group">
                                    <label>&nbsp;</label>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" id="show_upcoming" name="show_upcoming" value="1" <?php echo isset($_GET['show_upcoming']) ? 'checked' : ''; ?>>
                                        <label for="show_upcoming" style="margin: 0;">Show upcoming deletions only</label>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="filter-group">
                                    <label>Date From</label>
                                    <input type="date" name="date_from" value="<?php echo $filter_date_from; ?>">
                                </div>
                                <div class="filter-group">
                                    <label>Date To</label>
                                    <input type="date" name="date_to" value="<?php echo $filter_date_to; ?>">
                                </div>
                                <div class="filter-group">
                                    <label>&nbsp;</label>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <input type="checkbox" id="show_archived" name="show_archived" value="1" <?php echo $show_archived ? 'checked' : ''; ?>>
                                        <label for="show_archived" style="margin: 0;">Show Archived Reports</label>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-filter-alt'></i> Apply Filters
                            </button>
                            <button type="button" onclick="resetFilters()" class="btn btn-outline">
                                <i class='bx bx-reset'></i> Reset Filters
                            </button>
                            <?php if ($is_archive_page): ?>
                                <a href="?export=pdf" class="btn btn-danger">
                                    <i class='bx bx-printer'></i> Generate Report
                                </a>
                            <?php else: ?>
                                <a href="?export=csv" class="btn btn-success">
                                    <i class='bx bx-download'></i> Export CSV
                                </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Reports Table -->
                <div class="reports-table">
                    <?php if (empty($reports)): ?>
                        <div class="empty-state">
                            <i class='bx <?php echo $is_archive_page ? 'bx-archive-out' : 'bx-clipboard'; ?>'></i>
                            <h3>No <?php echo $is_archive_page ? 'archived records' : 'reports'; ?> found</h3>
                            <p>Try adjusting your filters or check back later for new <?php echo $is_archive_page ? 'archives' : 'reports'; ?>.</p>
                        </div>
                    <?php else: ?>
                        <table>
                            <thead>
                                <tr>
                                    <?php if ($is_archive_page): ?>
                                        <th>Incident Code</th>
                                        <th>Category</th>
                                        <th>Location</th>
                                        <th>Archived Date</th>
                                        <th>Scheduled Deletion</th>
                                        <th>Archived By</th>
                                        <th>Actions</th>
                                    <?php else: ?>
                                        <th>Incident Code</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Barangay</th>
                                        <th>Severity</th>
                                        <th>Status</th>
                                        <th>Reported Date</th>
                                        <th>Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): 
                                    if ($is_archive_page) {
                                        $days_until_deletion = ceil((strtotime($report['scheduled_deletion']) - time()) / (60 * 60 * 24));
                                    }
                                ?>
                                    <tr>
                                        <?php if ($is_archive_page): ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($report['incident_code']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($report['incident_type']); ?></small>
                                            </td>
                                            <td>
                                                <span class="category-badge"><?php echo htmlspecialchars($report['category_name']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['location']); ?><br>
                                                <small><?php echo htmlspecialchars($report['barangay_name']); ?></small>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($report['archived_date'])); ?></td>
                                            <td>
                                                <?php echo date('M d, Y', strtotime($report['scheduled_deletion'])); ?>
                                                <?php if ($days_until_deletion <= 30): ?>
                                                    <br><span class="deletion-warning">Deletes in <?php echo $days_until_deletion; ?> days</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['archived_by_name']); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button onclick="viewReport(<?php echo $report['id']; ?>)" class="action-btn btn-info">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                    <button onclick="unarchiveReport(<?php echo $report['id']; ?>)" class="action-btn btn-warning">
                                                        <i class='bx bx-archive-out'></i> Unarchive
                                                    </button>
                                                    <button onclick="deleteReport(<?php echo $report['id']; ?>)" class="action-btn btn-danger">
                                                        <i class='bx bx-trash'></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        <?php else: ?>
                                            <td>
                                                <strong><?php echo htmlspecialchars($report['incident_code']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($report['reporter_fullname']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['incident_type']); ?></td>
                                            <td><?php echo htmlspecialchars($report['location']); ?></td>
                                            <td><?php echo htmlspecialchars($report['barangay_name']); ?></td>
                                            <td>
                                                <span class="severity-badge severity-<?php echo strtolower($report['severity']); ?>">
                                                    <?php echo htmlspecialchars($report['severity']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $report['status'])); ?>">
                                                    <?php echo htmlspecialchars($report['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M d, Y h:i A', strtotime($report['reported_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button onclick="viewReport(<?php echo $report['id']; ?>)" class="action-btn btn-info">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                    
                                                    <?php if ($report['status'] == 'Pending'): ?>
                                                        <button onclick="verifyReport(<?php echo $report['id']; ?>)" class="action-btn btn-success">
                                                            <i class='bx bx-check'></i> Verify
                                                        </button>
                                                        <button onclick="rejectReport(<?php echo $report['id']; ?>)" class="action-btn btn-danger">
                                                            <i class='bx bx-x'></i> Reject
                                                        </button>
                                                    <?php elseif ($report['status'] == 'Verified'): ?>
                                                        <button onclick="assignReport(<?php echo $report['id']; ?>)" class="action-btn btn-warning">
                                                            <i class='bx bx-user-plus'></i> Assign
                                                        </button>
                                                    <?php elseif ($report['status'] == 'In Progress'): ?>
                                                        <button onclick="resolveReport(<?php echo $report['id']; ?>)" class="action-btn btn-success">
                                                            <i class='bx bx-check-circle'></i> Resolve
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($report['status'] == 'Resolved' && !$report['is_archived']): ?>
                                                        <button onclick="archiveReport(<?php echo $report['id']; ?>)" class="action-btn btn-secondary">
                                                            <i class='bx bx-archive'></i> Archive
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <?php if (!$is_archive_page): ?>
        <!-- Verify Modal -->
        <div id="verifyModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitVerify()">
                    <input type="hidden" name="report_id" id="verifyReportId">
                    <input type="hidden" name="action" value="verify">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-check'></i> Verify Report</h3>
                        <button type="button" class="close-btn" onclick="closeModal('verifyModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Verification Notes</label>
                        <textarea name="notes" placeholder="Add verification notes..." required></textarea>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('verifyModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">Verify Report</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Assign Modal -->
        <div id="assignModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitAssign()">
                    <input type="hidden" name="report_id" id="assignReportId">
                    <input type="hidden" name="action" value="assign">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-user-plus'></i> Assign Report</h3>
                        <button type="button" class="close-btn" onclick="closeModal('assignModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Assign To</label>
                        <select name="assigned_to" required>
                            <option value="">Select Employee</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo $employee['id']; ?>">
                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Assignment Notes</label>
                        <textarea name="notes" placeholder="Add assignment notes..."></textarea>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('assignModal')">Cancel</button>
                        <button type="submit" class="btn btn-warning">Assign Report</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Reject Modal -->
        <div id="rejectModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitReject()">
                    <input type="hidden" name="report_id" id="rejectReportId">
                    <input type="hidden" name="action" value="reject">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-x'></i> Reject Report</h3>
                        <button type="button" class="close-btn" onclick="closeModal('rejectModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Rejection Reason</label>
                        <textarea name="notes" placeholder="Explain why this report is being rejected..." required></textarea>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('rejectModal')">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject Report</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Archive Modal -->
        <div id="archiveModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitArchive()">
                    <input type="hidden" name="report_id" id="archiveReportId">
                    <input type="hidden" name="action" value="archive">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-archive'></i> Archive Report</h3>
                        <button type="button" class="close-btn" onclick="closeModal('archiveModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Archive Category</label>
                        <select name="category_id" required>
                            <option value="">Select Category</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['category_name']); ?>
                                    (<?php echo $category['retention_period']; ?> days retention)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Archive Notes</label>
                        <textarea name="notes" placeholder="Add archive notes..."></textarea>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('archiveModal')">Cancel</button>
                        <button type="submit" class="btn btn-secondary">Archive Report</button>
                    </div>
                </form>
            </div>
        </div>
    <?php else: ?>
        <!-- Unarchive Modal -->
        <div id="unarchiveModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitUnarchive()">
                    <input type="hidden" name="report_id" id="unarchiveReportId">
                    <input type="hidden" name="action" value="unarchive">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-archive-out'></i> Unarchive Report</h3>
                        <button type="button" class="close-btn" onclick="closeModal('unarchiveModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <label>Unarchive Reason</label>
                        <textarea name="notes" placeholder="Explain why this report is being unarchived..." required></textarea>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('unarchiveModal')">Cancel</button>
                        <button type="submit" class="btn btn-warning">Unarchive Report</button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Delete Modal -->
        <div id="deleteModal" class="modal">
            <div class="modal-content">
                <form method="POST" onsubmit="return submitDelete()">
                    <input type="hidden" name="report_id" id="deleteReportId">
                    <input type="hidden" name="action" value="delete">
                    
                    <div class="modal-header">
                        <h3><i class='bx bx-trash'></i> Delete Permanently</h3>
                        <button type="button" class="close-btn" onclick="closeModal('deleteModal')">&times;</button>
                    </div>
                    
                    <div class="form-group">
                        <p style="color: #dc2626; font-weight: 500;">
                            <i class='bx bx-error'></i> Warning: This action cannot be undone!
                        </p>
                        <p>This report will be permanently deleted from the database. Are you sure you want to proceed?</p>
                    </div>
                    
                    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('deleteModal')">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete Permanently</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Set active menu item
        document.querySelectorAll('.menu-item').forEach(item => {
            item.addEventListener('click', function() {
                // Remove active class from all menu items
                document.querySelectorAll('.menu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                // Add active class to clicked menu item
                this.classList.add('active');
            });
        });
        
        // Set active submenu item
        document.querySelectorAll('.submenu-item').forEach(item => {
            item.addEventListener('click', function() {
                // Remove active class from all submenu items
                document.querySelectorAll('.submenu-item').forEach(i => {
                    i.classList.remove('active');
                });
                
                // Add active class to clicked submenu item
                this.classList.add('active');
            });
        });
        
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
        
        // Real-time GMT+8 clock
        function updateTime() {
            const now = new Date();
            // Convert to GMT+8
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            
            const timeString = `${hours}:${minutes}:${seconds} UTC+8`;
            document.getElementById('current-time').textContent = timeString;
        }
        
        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);
        
        // Filter functions
        function resetFilters() {
            document.getElementById('filterForm').reset();
            document.getElementById('filterForm').submit();
        }
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Report Review Functions
        <?php if (!$is_archive_page): ?>
            function verifyReport(reportId) {
                document.getElementById('verifyReportId').value = reportId;
                openModal('verifyModal');
            }
            
            function assignReport(reportId) {
                document.getElementById('assignReportId').value = reportId;
                openModal('assignModal');
            }
            
            function rejectReport(reportId) {
                document.getElementById('rejectReportId').value = reportId;
                openModal('rejectModal');
            }
            
            function resolveReport(reportId) {
                Swal.fire({
                    title: 'Resolve Report',
                    input: 'textarea',
                    inputLabel: 'Resolution Notes',
                    inputPlaceholder: 'Enter resolution details...',
                    showCancelButton: true,
                    confirmButtonText: 'Mark as Resolved',
                    cancelButtonText: 'Cancel',
                    preConfirm: (notes) => {
                        if (!notes) {
                            Swal.showValidationMessage('Please enter resolution notes');
                        }
                        return notes;
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const formData = new FormData();
                        formData.append('report_id', reportId);
                        formData.append('action', 'resolve');
                        formData.append('notes', result.value);
                        
                        fetch('<?php echo $_SERVER['PHP_SELF']; ?>', {
                            method: 'POST',
                            body: formData
                        }).then(response => {
                            if (response.ok) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Report Resolved!',
                                    text: 'The report has been marked as resolved.',
                                    timer: 2000,
                                    showConfirmButton: false
                                }).then(() => {
                                    location.reload();
                                });
                            }
                        });
                    }
                });
            }
            
            function archiveReport(reportId) {
                document.getElementById('archiveReportId').value = reportId;
                openModal('archiveModal');
            }
            
            // Form submission functions
            function submitVerify() {
                return true;
            }
            
            function submitAssign() {
                return true;
            }
            
            function submitReject() {
                Swal.fire({
                    title: 'Are you sure?',
                    text: "This report will be rejected and the reporter will be notified.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#d33',
                    cancelButtonColor: '#3085d6',
                    confirmButtonText: 'Yes, reject it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.forms[1].submit();
                    }
                });
                return false;
            }
            
            function submitArchive() {
                Swal.fire({
                    title: 'Archive Report?',
                    text: "This report will be moved to archives and removed from active lists.",
                    icon: 'info',
                    showCancelButton: true,
                    confirmButtonColor: '#0d9488',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, archive it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.forms[4].submit();
                    }
                });
                return false;
            }
        <?php else: ?>
            // Archive Functions
            function unarchiveReport(reportId) {
                document.getElementById('unarchiveReportId').value = reportId;
                openModal('unarchiveModal');
            }
            
            function deleteReport(reportId) {
                document.getElementById('deleteReportId').value = reportId;
                openModal('deleteModal');
            }
            
            function submitUnarchive() {
                Swal.fire({
                    title: 'Unarchive Report?',
                    text: "This report will be moved back to active review.",
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#f59e0b',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, unarchive it!'
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.forms[1].submit();
                    }
                });
                return false;
            }
            
            function submitDelete() {
                Swal.fire({
                    title: 'Are you absolutely sure?',
                    text: "This will permanently delete the report and all associated records. This action cannot be undone!",
                    icon: 'error',
                    showCancelButton: true,
                    confirmButtonColor: '#dc2626',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, delete permanently!',
                    reverseButtons: true
                }).then((result) => {
                    if (result.isConfirmed) {
                        document.forms[2].submit();
                    }
                });
                return false;
            }
        <?php endif; ?>
        
        // Common function to view report details
        function viewReport(reportId) {
            window.open(`admin_report_detail.php?id=${reportId}`, '_blank');
        }
        
        // SweetAlert notifications for success/error messages
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
        
        // Global search functionality
        document.getElementById('globalSearch').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.trim();
                if (searchTerm) {
                    if (<?php echo $is_archive_page ? 'true' : 'false'; ?>) {
                        window.location.href = `?keyword=${encodeURIComponent(searchTerm)}`;
                    } else {
                        // For non-archive pages, you might want to implement different search
                        // For now, just alert
                        Swal.fire({
                            title: 'Search',
                            text: `Searching for: ${searchTerm}`,
                            icon: 'info',
                            timer: 1500,
                            showConfirmButton: false
                        });
                    }
                }
            }
        });
        
        // Add hover effects to cards
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
                this.style.boxShadow = '0 10px 25px rgba(0, 0, 0, 0.1)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
                this.style.boxShadow = '0 2px 10px rgba(0,0,0,0.1)';
            });
        });
        
        // Animate table rows on hover
        document.querySelectorAll('tbody tr').forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f9fafb';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
    </script>
</body>
</html>