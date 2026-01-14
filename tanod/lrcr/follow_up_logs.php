<?php
// follow_up_logs.php
// Follow-Up Logs for Barangay Tricycle Route Management (BTRM)

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Function to get user data
function getUserData($pdo, $user_id = null) {
    if ($user_id) {
        // Get specific user by ID
        $sql = "SELECT * FROM users WHERE id = :user_id AND is_verified = 1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Get first verified user as fallback
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
    // Redirect to login if not logged in
    header('Location: ../login.php');
    exit();
}

// Check if user has TANOD role
if ($user['role'] != 'TANOD') {
    // Redirect to appropriate dashboard based on role
    if ($user['role'] == 'ADMIN') {
        header('Location: ../admin/admin_dashboard.php');
    } elseif ($user['role'] == 'EMPLOYEE') {
        header('Location: ../employee/employee_dashboard.php');
    } else {
        header('Location: ../user/user_dashboard.php');
    }
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'follow-up-logs';

// Initialize variables for form
$action_success = false;
$action_message = '';
$log_data = [];

// Handle form submission for new follow-up log
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_follow_up'])) {
    try {
        // Generate log code
        $log_code = 'FUL-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // Prepare data for insertion
        $data = [
            'log_code' => $log_code,
            'incident_id' => $_POST['incident_id'] ?: NULL,
            'incident_type' => $_POST['incident_type'],
            'barangay_id' => $_POST['barangay_id'],
            'location' => $_POST['location'],
            'temporary_action' => $_POST['temporary_action'],
            'action_taken_by' => $user_id,
            'action_date' => date('Y-m-d H:i:s'),
            'follow_up_notes' => $_POST['follow_up_notes'],
            'status_before' => $_POST['status_before'],
            'status_after' => $_POST['status_after'],
            'priority_level' => $_POST['priority_level'],
            'needs_permanent_solution' => isset($_POST['needs_permanent_solution']) ? 1 : 0,
            'estimated_completion_date' => $_POST['estimated_completion_date'] ?: NULL,
            'created_by' => $user_id
        ];
        
        // Insert into database
        $sql = "INSERT INTO follow_up_logs (
            log_code, incident_id, incident_type, barangay_id, location, 
            temporary_action, action_taken_by, action_date, follow_up_notes, 
            status_before, status_after, priority_level, needs_permanent_solution, 
            estimated_completion_date, created_by
        ) VALUES (
            :log_code, :incident_id, :incident_type, :barangay_id, :location, 
            :temporary_action, :action_taken_by, :action_date, :follow_up_notes, 
            :status_before, :status_after, :priority_level, :needs_permanent_solution, 
            :estimated_completion_date, :created_by
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($data);
        
        $action_success = true;
        $action_message = 'Follow-up log created successfully! Log Code: ' . $log_code;
        
        // Update related incident status if incident_id is provided
        if ($_POST['incident_id']) {
            $update_sql = "";
            $incident_type = $_POST['incident_type'];
            
            if ($incident_type == 'Road Condition') {
                $update_sql = "UPDATE road_condition_reports SET status = :status WHERE id = :incident_id";
            } elseif ($incident_type == 'Traffic Incident') {
                $update_sql = "UPDATE tanod_field_reports SET status = :status WHERE id = :incident_id";
            }
            
            if ($update_sql) {
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    'status' => $_POST['status_after'],
                    'incident_id' => $_POST['incident_id']
                ]);
            }
        }
        
    } catch (Exception $e) {
        $action_success = false;
        $action_message = 'Error creating follow-up log: ' . $e->getMessage();
    }
}

// Handle search/filter
$search_query = '';
$filter_barangay = '';
$filter_priority = '';
$filter_date_from = '';
$filter_date_to = '';

if ($_SERVER['REQUEST_METHOD'] == 'GET' && (isset($_GET['search']) || isset($_GET['filter']))) {
    $search_query = $_GET['search'] ?? '';
    $filter_barangay = $_GET['barangay'] ?? '';
    $filter_priority = $_GET['priority'] ?? '';
    $filter_date_from = $_GET['date_from'] ?? '';
    $filter_date_to = $_GET['date_to'] ?? '';
}

// Fetch follow-up logs with filters
try {
    $sql = "SELECT fl.*, 
                   b.name as barangay_name,
                   CONCAT(u.first_name, ' ', u.last_name) as action_taker_name,
                   u.role as action_taker_role
            FROM follow_up_logs fl
            LEFT JOIN barangays b ON fl.barangay_id = b.id
            LEFT JOIN users u ON fl.action_taken_by = u.id
            WHERE fl.action_taken_by = :user_id";
    
    $params = ['user_id' => $user_id];
    
    // Add search filters
    if ($search_query) {
        $sql .= " AND (fl.log_code LIKE :search OR fl.location LIKE :search OR fl.temporary_action LIKE :search)";
        $params['search'] = "%$search_query%";
    }
    
    if ($filter_barangay) {
        $sql .= " AND fl.barangay_id = :barangay_id";
        $params['barangay_id'] = $filter_barangay;
    }
    
    if ($filter_priority) {
        $sql .= " AND fl.priority_level = :priority";
        $params['priority'] = $filter_priority;
    }
    
    if ($filter_date_from) {
        $sql .= " AND DATE(fl.action_date) >= :date_from";
        $params['date_from'] = $filter_date_from;
    }
    
    if ($filter_date_to) {
        $sql .= " AND DATE(fl.action_date) <= :date_to";
        $params['date_to'] = $filter_date_to;
    }
    
    $sql .= " ORDER BY fl.action_date DESC, fl.priority_level DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $follow_up_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $follow_up_logs = [];
    error_log("Error fetching follow-up logs: " . $e->getMessage());
}

// Fetch barangays for filter dropdown
try {
    $barangay_sql = "SELECT id, name FROM barangays ORDER BY name";
    $barangay_stmt = $pdo->prepare($barangay_sql);
    $barangay_stmt->execute();
    $barangays = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $barangays = [];
}

// Fetch recent incidents for dropdown in create form
try {
    $incident_sql = "SELECT id, location, condition_type, severity, status 
                     FROM tanod_field_reports 
                     WHERE tanod_id = :user_id 
                     AND status IN ('Submitted', 'Under Investigation')
                     ORDER BY report_date DESC 
                     LIMIT 10";
    $incident_stmt = $pdo->prepare($incident_sql);
    $incident_stmt->execute(['user_id' => $user_id]);
    $recent_incidents = $incident_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recent_incidents = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Follow-Up Logs - Traffic and Transport Management</title>
    
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        /* All your existing CSS styles from the dashboard */
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
            
            /* Status colors */
            --status-emergency: #ef4444;
            --status-high: #f97316;
            --status-medium: #f59e0b;
            --status-low: #10b981;
            --status-controlled: #3b82f6;
            --status-completed: #10b981;
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
        
        /* Page Content */
        .page-content {
            padding: 32px;
        }
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .page-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            color: var(--text-light);
        }
        
        .page-actions {
            display: flex;
            gap: 12px;
        }
        
        .primary-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        
        .primary-button:hover {
            background-color: var(--secondary-dark);
        }
        
        .secondary-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .secondary-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .secondary-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Alert Messages */
        .alert-message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .alert-success {
            background-color: #d1fae5;
            border: 1px solid #a7f3d0;
            color: #065f46;
        }
        
        .dark-mode .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            border: 1px solid rgba(16, 185, 129, 0.3);
            color: #86efac;
        }
        
        .alert-error {
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            color: #991b1b;
        }
        
        .dark-mode .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }
        
        .alert-close {
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            font-size: 20px;
            padding: 0;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        /* Filter Section */
        .filter-section {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filter-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-color);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
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
        
        .filter-input,
        .filter-select {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }
        
        .filter-input:focus,
        .filter-select:focus {
            border-color: var(--primary-color);
        }
        
        .filter-button {
            background-color: var(--primary-color);
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
            align-self: flex-end;
        }
        
        .filter-button:hover {
            background-color: var(--primary-dark);
        }
        
        /* Table Styles */
        .table-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .table-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .table-actions {
            display: flex;
            gap: 8px;
        }
        
        .export-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 8px 16px;
            border-radius: 6px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        
        .export-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .export-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            background-color: var(--background-color);
            color: var(--text-light);
            font-weight: 600;
            text-align: left;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .table td {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .table tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .table tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .table tbody tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-align: center;
        }
        
        .status-emergency {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--status-emergency);
        }
        
        .status-high {
            background-color: rgba(249, 115, 22, 0.1);
            color: var(--status-high);
        }
        
        .status-medium {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--status-medium);
        }
        
        .status-low {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--status-low);
        }
        
        .status-controlled {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--status-controlled);
        }
        
        .status-completed {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--status-completed);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .view-button:hover {
            background-color: rgba(59, 130, 246, 0.2);
        }
        
        .edit-button {
            background-color: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .edit-button:hover {
            background-color: rgba(245, 158, 11, 0.2);
        }
        
        .delete-button {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .delete-button:hover {
            background-color: rgba(239, 68, 68, 0.2);
        }
        
        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .modal {
            background-color: var(--card-bg);
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-light);
            font-size: 24px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .modal-body {
            padding: 20px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            outline: none;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .form-check-input {
            width: 18px;
            height: 18px;
        }
        
        .form-check-label {
            font-size: 14px;
            color: var(--text-color);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state-icon {
            font-size: 48px;
            color: var(--text-light);
            margin-bottom: 16px;
        }
        
        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }
        
        .empty-state-description {
            color: var(--text-light);
            margin-bottom: 24px;
            max-width: 400px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
            }
            
            .page-content {
                padding: 20px;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 768px) {
            .page-header {
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
            }
            
            .time-display {
                min-width: 140px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar - Exact same as your dashboard -->
        <div class="sidebar">
            <!-- Logo -->
            <div class="logo">
                <div class="logo-icon">
                    <img src="../../img/ttm.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <span class="logo-text"> Tanod Patrol Management</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button -->
                    <a href="../tanod_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>

                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('road-condition')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-condition" class="submenu">
                        <a href="../lrcr/tanod_field_report.php" class="submenu-item">Report Condition</a>
                        <a href="../lrcr/follow_up_logs.php" class="submenu-item active">Follow-Up Logs</a>
                    </div>
                      
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item" onclick="toggleSubmenu('route-management')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-management" class="submenu">
                        <a href="../btrm/route_compliance.php" class="submenu-item">Route Compliance</a>
                        <a href="routes/route_map.php" class="submenu-item">Incident Logging</a>
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
                        <a href="incidents/report_incident.php" class="submenu-item">Report Incident</a>
                        <a href="incidents/my_reports.php" class="submenu-item">My Reports</a>
                        <a href="incidents/emergency_contacts.php" class="submenu-item">Emergency Contacts</a>
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
                        <a href="patrol_logs/create_log.php" class="submenu-item">Create Patrol Log</a>
                        <a href="patrol_logs/my_logs.php" class="submenu-item">My Patrol Logs</a>
                        <a href="patrol_logs/shift_schedule.php" class="submenu-item">Shift Schedule</a>
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
                        <a href="permits/verify_permit.php" class="submenu-item">Verify Permit</a>
                        <a href="permits/regulation_guide.php" class="submenu-item">Regulation Guide</a>
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
                        <a href="feedback/submit_feedback.php" class="submenu-item">Submit Feedback</a>
                        <a href="feedback/view_feedback.php" class="submenu-item">View Feedback</a>
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
            <!-- Header - Exact same as your dashboard -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search patrol logs or incidents" class="search-input" id="global-search">
                            <kbd class="search-shortcut">🛡️</kbd>
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
            
            <!-- Page Content -->
            <div class="page-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Follow-Up Logs</h1>
                        <p class="page-subtitle">Track and update temporary actions taken on incidents (e.g., barricades placed)</p>
                    </div>
                    <div class="page-actions">
                        <button class="secondary-button" onclick="printFollowUpLogs()">
                            <i class='bx bx-printer'></i>
                            Print
                        </button>
                        <button class="primary-button" onclick="showCreateModal()">
                            <i class='bx bx-plus'></i>
                            New Follow-Up Log
                        </button>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if ($action_message): ?>
                    <div class="alert-message <?php echo $action_success ? 'alert-success' : 'alert-error'; ?>">
                        <span><?php echo htmlspecialchars($action_message); ?></span>
                        <button class="alert-close" onclick="this.parentElement.style.display='none'">×</button>
                    </div>
                <?php endif; ?>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <h3 class="filter-title">Filter Logs</h3>
                    <form method="GET" class="filter-form">
                        <div class="filter-group">
                            <label class="filter-label">Search</label>
                            <input type="text" name="search" class="filter-input" placeholder="Search by location or action..." value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Barangay</label>
                            <select name="barangay" class="filter-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>" <?php echo $filter_barangay == $barangay['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Priority Level</label>
                            <select name="priority" class="filter-select">
                                <option value="">All Priorities</option>
                                <option value="Emergency" <?php echo $filter_priority == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                <option value="High" <?php echo $filter_priority == 'High' ? 'selected' : ''; ?>>High</option>
                                <option value="Medium" <?php echo $filter_priority == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="Low" <?php echo $filter_priority == 'Low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label class="filter-label">Date Range</label>
                            <div style="display: flex; gap: 8px;">
                                <input type="date" name="date_from" class="filter-input" style="flex: 1;" value="<?php echo htmlspecialchars($filter_date_from); ?>">
                                <span style="align-self: center;">to</span>
                                <input type="date" name="date_to" class="filter-input" style="flex: 1;" value="<?php echo htmlspecialchars($filter_date_to); ?>">
                            </div>
                        </div>
                        <button type="submit" class="filter-button">
                            <i class='bx bx-filter'></i>
                            Apply Filters
                        </button>
                    </form>
                </div>
                
                <!-- Table Section -->
                <div class="table-container">
                    <div class="table-header">
                        <h2 class="table-title">Follow-Up Logs</h2>
                        <div class="table-actions">
                            <button class="export-button" onclick="exportToCSV()">
                                <i class='bx bx-export'></i>
                                Export CSV
                            </button>
                        </div>
                    </div>
                    
                    <?php if (empty($follow_up_logs)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class='bx bx-clipboard'></i>
                            </div>
                            <h3 class="empty-state-title">No Follow-Up Logs Found</h3>
                            <p class="empty-state-description">
                                Start creating follow-up logs to track temporary actions taken on incidents.
                            </p>
                            <button class="primary-button" onclick="showCreateModal()">
                                <i class='bx bx-plus'></i>
                                Create Your First Log
                            </button>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Log Code</th>
                                    <th>Location</th>
                                    <th>Temporary Action</th>
                                    <th>Status</th>
                                    <th>Priority</th>
                                    <th>Action Date</th>
                                    <th>Action Taken By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($follow_up_logs as $log): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($log['log_code']); ?></strong>
                                            <?php if ($log['needs_permanent_solution']): ?>
                                                <br><small style="color: var(--status-high);">⚠️ Needs Permanent Solution</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($log['location']); ?>
                                            <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($log['barangay_name']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars(substr($log['temporary_action'], 0, 100)); ?>
                                            <?php echo strlen($log['temporary_action']) > 100 ? '...' : ''; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-controlled">
                                                <?php echo htmlspecialchars($log['status_after']); ?>
                                            </span>
                                            <?php if ($log['status_before']): ?>
                                                <br><small style="color: var(--text-light);">From: <?php echo htmlspecialchars($log['status_before']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $priority_class = 'status-' . strtolower($log['priority_level']);
                                            ?>
                                            <span class="status-badge <?php echo $priority_class; ?>">
                                                <?php echo htmlspecialchars($log['priority_level']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($log['action_date'])); ?>
                                            <br><small style="color: var(--text-light);"><?php echo date('h:i A', strtotime($log['action_date'])); ?></small>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($log['action_taker_name']); ?>
                                            <br><small style="color: var(--text-light);"><?php echo htmlspecialchars($log['action_taker_role']); ?></small>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-button view-button" onclick="viewLogDetails(<?php echo $log['id']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                    View
                                                </button>
                                                <button class="action-button edit-button" onclick="editLog(<?php echo $log['id']; ?>)">
                                                    <i class='bx bx-edit'></i>
                                                    Edit
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
                
                <!-- Summary Stats -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-top: 24px;">
                    <div style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; color: var(--text-light);">Total Logs</div>
                        <div style="font-size: 24px; font-weight: 700;"><?php echo count($follow_up_logs); ?></div>
                    </div>
                    <div style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; color: var(--text-light);">Emergency Priority</div>
                        <div style="font-size: 24px; font-weight: 700; color: var(--status-emergency);">
                            <?php
                            $emergency_count = array_reduce($follow_up_logs, function($carry, $log) {
                                return $carry + ($log['priority_level'] == 'Emergency' ? 1 : 0);
                            }, 0);
                            echo $emergency_count;
                            ?>
                        </div>
                    </div>
                    <div style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; color: var(--text-light);">Need Permanent Solution</div>
                        <div style="font-size: 24px; font-weight: 700; color: var(--status-high);">
                            <?php
                            $permanent_count = array_reduce($follow_up_logs, function($carry, $log) {
                                return $carry + ($log['needs_permanent_solution'] ? 1 : 0);
                            }, 0);
                            echo $permanent_count;
                            ?>
                        </div>
                    </div>
                    <div style="background-color: var(--card-bg); border: 1px solid var(--border-color); border-radius: 8px; padding: 16px;">
                        <div style="font-size: 12px; color: var(--text-light);">This Month</div>
                        <div style="font-size: 24px; font-weight: 700;">
                            <?php
                            $this_month = date('Y-m');
                            $month_count = array_reduce($follow_up_logs, function($carry, $log) use ($this_month) {
                                return $carry + (substr($log['action_date'], 0, 7) == $this_month ? 1 : 0);
                            }, 0);
                            echo $month_count;
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Create/Edit Modal -->
    <div class="modal-overlay" id="createModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Create Follow-Up Log</h3>
                <button class="modal-close" onclick="hideCreateModal()">×</button>
            </div>
            <form method="POST" action="" id="followUpForm">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Link to Existing Incident (Optional)</label>
                        <select name="incident_id" class="form-control">
                            <option value="">-- Select Incident --</option>
                            <?php foreach ($recent_incidents as $incident): ?>
                                <option value="<?php echo $incident['id']; ?>">
                                    <?php echo htmlspecialchars($incident['condition_type'] . ' - ' . $incident['location'] . ' (' . $incident['status'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Incident Type</label>
                        <select name="incident_type" class="form-control" required>
                            <option value="Road Condition">Road Condition</option>
                            <option value="Traffic Incident">Traffic Incident</option>
                            <option value="Route Violation">Route Violation</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Barangay</label>
                        <select name="barangay_id" class="form-control" required>
                            <option value="">-- Select Barangay --</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>">
                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Location *</label>
                        <input type="text" name="location" class="form-control" required 
                               placeholder="e.g., Main Street near Market, Alley behind Elementary School">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Temporary Action Taken *</label>
                        <textarea name="temporary_action" class="form-control form-textarea" required 
                                  placeholder="Describe the temporary action taken (e.g., barricade placed, traffic diverted, warning signs installed)"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status Before Action</label>
                        <input type="text" name="status_before" class="form-control" 
                               placeholder="e.g., Emergency, Blocked, Hazardous">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status After Action *</label>
                        <input type="text" name="status_after" class="form-control" required 
                               value="Controlled" placeholder="e.g., Controlled, Passable, Safe">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority Level *</label>
                        <select name="priority_level" class="form-control" required>
                            <option value="Emergency">Emergency</option>
                            <option value="High" selected>High</option>
                            <option value="Medium">Medium</option>
                            <option value="Low">Low</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Follow-Up Notes</label>
                        <textarea name="follow_up_notes" class="form-control form-textarea" 
                                  placeholder="Additional notes for admin follow-up (e.g., needs permanent repair, coordinate with DPWH)"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="form-check">
                            <input type="checkbox" name="needs_permanent_solution" id="needs_permanent" class="form-check-input">
                            <label for="needs_permanent" class="form-check-label">Needs Permanent Solution</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Completion Date (If applicable)</label>
                        <input type="date" name="estimated_completion_date" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" onclick="hideCreateModal()">Cancel</button>
                    <button type="submit" name="create_follow_up" class="primary-button">
                        <i class='bx bx-save'></i>
                        Save Follow-Up Log
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div class="modal-overlay" id="viewModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">Follow-Up Log Details</h3>
                <button class="modal-close" onclick="hideViewModal()">×</button>
            </div>
            <div class="modal-body" id="viewModalContent">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="secondary-button" onclick="hideViewModal()">Close</button>
                <button type="button" class="primary-button" onclick="printLogDetails()">
                    <i class='bx bx-printer'></i>
                    Print Details
                </button>
            </div>
        </div>
    </div>
    
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
        
        // Modal Functions
        function showCreateModal() {
            document.getElementById('createModal').style.display = 'flex';
        }
        
        function hideCreateModal() {
            document.getElementById('createModal').style.display = 'none';
        }
        
        function showViewModal() {
            document.getElementById('viewModal').style.display = 'flex';
        }
        
        function hideViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        // View Log Details
        function viewLogDetails(logId) {
            // Show loading state
            document.getElementById('viewModalContent').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <div class="empty-state-icon">
                        <i class='bx bx-loader-circle bx-spin'></i>
                    </div>
                    <p>Loading details...</p>
                </div>
            `;
            
            showViewModal();
            
            // Fetch log details via AJAX
            fetch(`ajax/get_log_details.php?id=${logId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('viewModalContent').innerHTML = data;
                })
                .catch(error => {
                    document.getElementById('viewModalContent').innerHTML = `
                        <div style="text-align: center; padding: 40px;">
                            <div class="empty-state-icon" style="color: var(--status-emergency);">
                                <i class='bx bx-error'></i>
                            </div>
                            <h3>Error Loading Details</h3>
                            <p>${error.message}</p>
                        </div>
                    `;
                });
        }
        
        // Edit Log
        function editLog(logId) {
            Swal.fire({
                title: 'Edit Follow-Up Log',
                text: 'This feature is coming soon!',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        // Print Follow-Up Logs
        function printFollowUpLogs() {
            const printContent = document.querySelector('.table-container').outerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Follow-Up Logs - ${new Date().toLocaleDateString()}</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                        th { background-color: #f5f5f5; }
                        .header { text-align: center; margin-bottom: 20px; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Follow-Up Logs Report</h2>
                        <p>Generated on: ${new Date().toLocaleString()}</p>
                        <p>Generated by: <?php echo htmlspecialchars($full_name); ?></p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Print Log Details
        function printLogDetails() {
            const printContent = document.getElementById('viewModalContent').outerHTML;
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                <head>
                    <title>Follow-Up Log Details</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        .detail-item { margin-bottom: 10px; }
                        .detail-label { font-weight: bold; color: #666; }
                        .detail-value { margin-top: 5px; }
                        .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h2>Follow-Up Log Details</h2>
                        <p>Printed on: ${new Date().toLocaleString()}</p>
                    </div>
                    ${printContent}
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }
        
        // Export to CSV
        function exportToCSV() {
            Swal.fire({
                title: 'Export Options',
                html: `
                    <p>Select export format:</p>
                    <button onclick="exportAsCSV()" style="width: 100%; padding: 10px; margin: 5px 0; background: #0d9488; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class='bx bx-spreadsheet'></i> Export as CSV
                    </button>
                    <button onclick="exportAsExcel()" style="width: 100%; padding: 10px; margin: 5px 0; background: #047857; color: white; border: none; border-radius: 5px; cursor: pointer;">
                        <i class='bx bx-file'></i> Export as Excel
                    </button>
                `,
                showConfirmButton: false,
                showCloseButton: true
            });
        }
        
        function exportAsCSV() {
            const table = document.querySelector('.table');
            let csv = [];
            
            // Add headers
            const headers = [];
            table.querySelectorAll('th').forEach(th => {
                headers.push(th.textContent.trim());
            });
            csv.push(headers.join(','));
            
            // Add rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach((td, index) => {
                    if (index !== 6) { // Skip actions column
                        rowData.push(`"${td.textContent.trim().replace(/"/g, '""')}"`);
                    }
                });
                csv.push(rowData.join(','));
            });
            
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `follow_up_logs_${new Date().toISOString().split('T')[0]}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            
            Swal.fire({
                title: 'Success!',
                text: 'CSV file downloaded successfully.',
                icon: 'success',
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        function exportAsExcel() {
            Swal.fire({
                title: 'Coming Soon',
                text: 'Excel export feature is currently in development.',
                icon: 'info',
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        // Show notifications
        function showNotifications() {
            Swal.fire({
                title: 'Notifications',
                html: `
                    <div style="text-align: left; max-height: 300px; overflow-y: auto;">
                        <div style="padding: 10px; border-bottom: 1px solid #eee;">
                            <strong>New Incident Reported</strong>
                            <p style="margin: 5px 0 0 0; color: #666;">Market Street - 5 minutes ago</p>
                        </div>
                        <div style="padding: 10px; border-bottom: 1px solid #eee;">
                            <strong>Follow-Up Required</strong>
                            <p style="margin: 5px 0 0 0; color: #666;">Barricade needs maintenance - 1 hour ago</p>
                        </div>
                        <div style="padding: 10px;">
                            <strong>System Update</strong>
                            <p style="margin: 5px 0 0 0; color: #666;">New features added to Follow-Up Logs - 1 day ago</p>
                        </div>
                    </div>
                `,
                showConfirmButton: false,
                showCloseButton: true,
                width: 500
            });
        }
        
        // Global search
        document.getElementById('global-search').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchValue = this.value;
                if (searchValue.trim()) {
                    // Filter table rows
                    const rows = document.querySelectorAll('.table tbody tr');
                    rows.forEach(row => {
                        const rowText = row.textContent.toLowerCase();
                        if (rowText.includes(searchValue.toLowerCase())) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                }
            }
        });
        
        // Auto-close alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-message');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 5000);
            });
            
            // Show success message if form was submitted successfully
            <?php if ($action_success && $action_message): ?>
                Swal.fire({
                    title: 'Success!',
                    text: '<?php echo addslashes($action_message); ?>',
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false
                });
            <?php elseif (!$action_success && $action_message): ?>
                Swal.fire({
                    title: 'Error!',
                    text: '<?php echo addslashes($action_message); ?>',
                    icon: 'error',
                    timer: 3000,
                    showConfirmButton: false
                });
            <?php endif; ?>
        });
        
        // Form validation
        document.getElementById('followUpForm').addEventListener('submit', function(e) {
            const location = this.querySelector('[name="location"]').value;
            const temporaryAction = this.querySelector('[name="temporary_action"]').value;
            const statusAfter = this.querySelector('[name="status_after"]').value;
            
            if (!location.trim() || !temporaryAction.trim() || !statusAfter.trim()) {
                e.preventDefault();
                Swal.fire({
                    title: 'Validation Error',
                    text: 'Please fill in all required fields (marked with *).',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
            }
        });
        
        // Add hover effects to cards
        document.querySelectorAll('.card').forEach(card => {
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