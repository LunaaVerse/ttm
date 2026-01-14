<?php
// employee_road_status_monitoring.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../config/db_connection.php';

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

// Check if user is logged in and is an employee
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user = getUserData($pdo, $user_id);
    
    // Check if user is employee
    if ($user['role'] !== 'EMPLOYEE') {
        header('Location: ../access_denied.php');
        exit();
    }
} else {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];
$employee_id = $user['id'];

// Set current page for active menu highlighting
$current_page = 'status-monitoring';

// Process form submissions
$message = '';
$message_type = '';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $report_id = $_POST['report_id'];
        $new_status = $_POST['status'];
        $resolution_notes = isset($_POST['resolution_notes']) ? $_POST['resolution_notes'] : null;
        $follow_up_notes = isset($_POST['follow_up_notes']) ? $_POST['follow_up_notes'] : null;
        
        // Update the report
        $sql = "UPDATE road_condition_reports SET 
                status = :status,
                updated_at = NOW()";
        
        if ($new_status === 'Resolved' && $resolution_notes) {
            $sql .= ", resolution_notes = :resolution_notes, resolved_date = NOW()";
        }
        
        if ($follow_up_notes) {
            $sql .= ", follow_up_notes = :follow_up_notes";
        }
        
        $sql .= " WHERE id = :report_id AND assigned_to = :employee_id";
        
        $stmt = $pdo->prepare($sql);
        $params = [
            'status' => $new_status,
            'report_id' => $report_id,
            'employee_id' => $employee_id
        ];
        
        if ($new_status === 'Resolved' && $resolution_notes) {
            $params['resolution_notes'] = $resolution_notes;
        }
        
        if ($follow_up_notes) {
            $params['follow_up_notes'] = $follow_up_notes;
        }
        
        $stmt->execute($params);
        
        // Log the status change in assignment_logs
        $log_sql = "INSERT INTO assignment_logs (report_id, assigned_by, assigned_to, assignment_date, notes) 
                    VALUES (:report_id, :assigned_by, :assigned_to, NOW(), :notes)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([
            'report_id' => $report_id,
            'assigned_by' => $employee_id,
            'assigned_to' => $employee_id,
            'notes' => "Status updated to: $new_status" . ($resolution_notes ? " - $resolution_notes" : "")
        ]);
        
        $message = "Report status updated successfully!";
        $message_type = "success";
        
        // Store success message in session for SweetAlert
        $_SESSION['success_message'] = $message;
        
        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $message = "Error updating report: " . $e->getMessage();
        $message_type = "error";
    }
}

// Fetch assigned reports for this employee
$assigned_reports = [];
$reports_query = "SELECT r.*, b.name as barangay_name 
                  FROM road_condition_reports r 
                  LEFT JOIN barangays b ON r.barangay = b.name 
                  WHERE r.assigned_to = :employee_id 
                  ORDER BY 
                    CASE r.priority 
                        WHEN 'Emergency' THEN 1
                        WHEN 'High' THEN 2
                        WHEN 'Medium' THEN 3
                        WHEN 'Low' THEN 4
                    END,
                    r.created_at DESC";
$stmt = $pdo->prepare($reports_query);
$stmt->execute(['employee_id' => $employee_id]);
$assigned_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all reports for monitoring (including those assigned to others if employee has permission)
$all_reports_query = "SELECT r.*, b.name as barangay_name, 
                      CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
                      FROM road_condition_reports r 
                      LEFT JOIN barangays b ON r.barangay = b.name 
                      LEFT JOIN users u ON r.assigned_to = u.id 
                      WHERE r.status != 'Resolved' 
                      ORDER BY 
                        CASE r.priority 
                            WHEN 'Emergency' THEN 1
                            WHEN 'High' THEN 2
                            WHEN 'Medium' THEN 3
                            WHEN 'Low' THEN 4
                        END,
                        r.created_at DESC";
$all_reports_stmt = $pdo->prepare($all_reports_query);
$all_reports_stmt->execute();
$all_reports = $all_reports_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangays for filtering
$barangays_query = "SELECT id, name FROM barangays ORDER BY name";
$barangays_stmt = $pdo->prepare($barangays_query);
$barangays_stmt->execute();
$barangays = $barangays_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter reports if requested
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_priority = isset($_GET['priority']) ? $_GET['priority'] : '';

if ($filter_status || $filter_barangay || $filter_priority) {
    $filter_query = "SELECT r.*, b.name as barangay_name, 
                     CONCAT(u.first_name, ' ', u.last_name) as assigned_to_name
                     FROM road_condition_reports r 
                     LEFT JOIN barangays b ON r.barangay = b.name 
                     LEFT JOIN users u ON r.assigned_to = u.id 
                     WHERE 1=1";
    
    $filter_params = [];
    
    if ($filter_status) {
        $filter_query .= " AND r.status = :status";
        $filter_params['status'] = $filter_status;
    }
    
    if ($filter_barangay) {
        $filter_query .= " AND r.barangay = :barangay";
        $filter_params['barangay'] = $filter_barangay;
    }
    
    if ($filter_priority) {
        $filter_query .= " AND r.priority = :priority";
        $filter_params['priority'] = $filter_priority;
    }
    
    $filter_query .= " ORDER BY 
                      CASE r.priority 
                          WHEN 'Emergency' THEN 1
                          WHEN 'High' THEN 2
                          WHEN 'Medium' THEN 3
                          WHEN 'Low' THEN 4
                      END,
                      r.created_at DESC";
    
    $filter_stmt = $pdo->prepare($filter_query);
    $filter_stmt->execute($filter_params);
    $filtered_reports = $filter_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $filtered_reports = $all_reports;
}

// Get statistics
$stats = [];
try {
    // Total assigned reports
    $sql = "SELECT COUNT(*) as count FROM road_condition_reports WHERE assigned_to = :employee_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['employee_id' => $employee_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['assigned_reports'] = $row['count'];
    
    // Pending reports
    $sql = "SELECT COUNT(*) as count FROM road_condition_reports WHERE assigned_to = :employee_id AND status = 'Pending'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['employee_id' => $employee_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending'] = $row['count'];
    
    // In Progress reports
    $sql = "SELECT COUNT(*) as count FROM road_condition_reports WHERE assigned_to = :employee_id AND status = 'In Progress'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['employee_id' => $employee_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['in_progress'] = $row['count'];
    
    // Resolved reports
    $sql = "SELECT COUNT(*) as count FROM road_condition_reports WHERE assigned_to = :employee_id AND status = 'Resolved'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['employee_id' => $employee_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['resolved'] = $row['count'];
    
} catch (Exception $e) {
    $stats = [
        'assigned_reports' => 0,
        'pending' => 0,
        'in_progress' => 0,
        'resolved' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Monitoring - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
            
            /* Status colors */
            --status-pending: #f59e0b;
            --status-verified: #3b82f6;
            --status-assigned: #8b5cf6;
            --status-in-progress: #0ea5e9;
            --status-resolved: #10b981;
            --status-rejected: #ef4444;
            --status-needs-clarification: #f97316;
            
            /* Priority colors */
            --priority-low: #10b981;
            --priority-medium: #f59e0b;
            --priority-high: #ef4444;
            --priority-emergency: #dc2626;
            
            /* Severity colors */
            --severity-low: #10b981;
            --severity-medium: #f59e0b;
            --severity-high: #ef4444;
            --severity-emergency: #dc2626;
            
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
            
            /* Status colors - adjusted for dark mode */
            --status-pending: #f59e0b;
            --status-verified: #60a5fa;
            --status-assigned: #a78bfa;
            --status-in-progress: #38bdf8;
            --status-resolved: #34d399;
            --status-rejected: #f87171;
            --status-needs-clarification: #fb923c;
            
            /* Icon colors for dark mode */
            --icon-dashboard: #14b8a6;
            --icon-route-config: #60a5fa;
            --icon-road-condition: #f59e0b;
            --icon-incident: #f87171;
            --icon-tanod: #a78bfa;
            --icon-permit: #34d399;
            --icon-feedback: #fb923c;
            --icon-integration: #818cf8;
            --icon-settings: #94a3b8;
            --icon-help: #94a3b8;
            --icon-logout: #94a3b8;
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
            min-height: 100vh;
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
        
        /* Status Monitoring Content */
        .status-content {
            padding: 32px;
        }
        
        .status-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .status-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .status-subtitle {
            color: var(--text-light);
        }
        
        .status-actions {
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
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .stat-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .stat-card-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .stat-card-white {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .stat-title {
            font-size: 14px;
            font-weight: 500;
        }
        
        .stat-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        /* Filter Section */
        .filter-section {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
        }
        
        .filter-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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
            color: var(--text-light);
        }
        
        .filter-select, .filter-input {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .filter-button {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .filter-button:hover {
            background-color: var(--primary-dark);
        }
        
        .reset-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .reset-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .reset-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Reports Table */
        .reports-section {
            margin-bottom: 32px;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .reports-table {
            width: 100%;
            border-collapse: collapse;
            background-color: var(--card-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .reports-table th {
            background-color: var(--background-color);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
        }
        
        .reports-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .reports-table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode .reports-table tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .reports-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .status-pending-badge {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--status-pending);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .status-verified-badge {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--status-verified);
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .status-assigned-badge {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--status-assigned);
            border: 1px solid rgba(139, 92, 246, 0.2);
        }
        
        .status-in-progress-badge {
            background-color: rgba(14, 165, 233, 0.1);
            color: var(--status-in-progress);
            border: 1px solid rgba(14, 165, 233, 0.2);
        }
        
        .status-resolved-badge {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--status-resolved);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .status-rejected-badge {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--status-rejected);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .status-needs-clarification-badge {
            background-color: rgba(249, 115, 22, 0.1);
            color: var(--status-needs-clarification);
            border: 1px solid rgba(249, 115, 22, 0.2);
        }
        
        /* Priority Badges */
        .priority-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .priority-low-badge {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--priority-low);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .priority-medium-badge {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--priority-medium);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .priority-high-badge {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--priority-high);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .priority-emergency-badge {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--priority-emergency);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        /* Severity Badges */
        .severity-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.025em;
        }
        
        .severity-low-badge {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--severity-low);
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .severity-medium-badge {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--severity-medium);
            border: 1px solid rgba(245, 158, 11, 0.2);
        }
        
        .severity-high-badge {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--severity-high);
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .severity-emergency-badge {
            background-color: rgba(220, 38, 38, 0.1);
            color: var(--severity-emergency);
            border: 1px solid rgba(220, 38, 38, 0.2);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .action-button-small {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .view-button {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.2);
        }
        
        .view-button:hover {
            background-color: rgba(59, 130, 246, 0.2);
        }
        
        .update-button {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }
        
        .update-button:hover {
            background-color: rgba(16, 185, 129, 0.2);
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 24px 24px 16px;
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
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 16px 24px 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-select, .form-textarea, .form-input {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-select:focus, .form-textarea:focus, .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--border-color);
        }
        
        .empty-state-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .filter-form {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .reports-table {
                display: block;
                overflow-x: auto;
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
            
            .status-content {
                padding: 16px;
            }
            
            .status-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
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
            
            .reports-table th, 
            .reports-table td {
                padding: 12px 8px;
                font-size: 14px;
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
                    <!-- Dashboard Button -->
                    <a href="../employee_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
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
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu active">
                        <a href="employee_create_report.php" class="submenu-item">Create Report</a>
                        <a href="employee_road_status_monitoring.php" class="submenu-item active">Action Assignment</a>
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
                         <a href="../btrm/staff_route_management.php" class="submenu-item">Route & Terminal Encoding</a>
                        <a href="#" class="submenu-item">Traffic & Incident</a>
                        <a href="#" class="submenu-item">System-wide KPIs</a>
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
                    
                    <a href="../../includes/logout.php" class="menu-item">
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
                            <input type="text" placeholder="Search reports..." class="search-input" id="searchInput">
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
            
            <!-- Status Monitoring Content -->
            <div class="status-content">
                <!-- Title and Actions -->
                <div class="status-header">
                    <div>
                        <h1 class="status-title">Action Assignment</h1>
                        <p class="status-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Track and manage assigned road condition reports.</p>
                    </div>
                    <div class="status-actions">
                        <button class="primary-button" onclick="window.location.href='employee_create_report.php'">
                            <i class='bx bx-plus'></i>
                            Create New Report
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Assigned Reports</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['assigned_reports']; ?></div>
                        <div class="stat-info">
                            <i class='bx bx-clipboard'></i>
                            <span>Total assigned to you</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Pending</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['pending']; ?></div>
                        <div class="stat-info">
                            <i class='bx bx-time'></i>
                            <span>Awaiting action</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">In Progress</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['in_progress']; ?></div>
                        <div class="stat-info">
                            <i class='bx bx-cog'></i>
                            <span>Currently working on</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Resolved</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['resolved']; ?></div>
                        <div class="stat-info">
                            <i class='bx bx-check-circle'></i>
                            <span>Successfully resolved</span>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <h2 class="filter-title">Filter Reports</h2>
                    <form method="GET" action="" class="filter-form">
                        <div class="filter-group">
                            <label class="filter-label">Status</label>
                            <select name="status" class="filter-select">
                                <option value="">All Status</option>
                                <option value="Pending" <?php echo $filter_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="Verified" <?php echo $filter_status === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                <option value="Assigned" <?php echo $filter_status === 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                                <option value="In Progress" <?php echo $filter_status === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                <option value="Resolved" <?php echo $filter_status === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="Rejected" <?php echo $filter_status === 'Rejected' ? 'selected' : ''; ?>>Rejected</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Barangay</label>
                            <select name="barangay" class="filter-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo htmlspecialchars($barangay['name']); ?>" 
                                        <?php echo $filter_barangay === $barangay['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <label class="filter-label">Priority</label>
                            <select name="priority" class="filter-select">
                                <option value="">All Priorities</option>
                                <option value="Low" <?php echo $filter_priority === 'Low' ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo $filter_priority === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo $filter_priority === 'High' ? 'selected' : ''; ?>>High</option>
                                <option value="Emergency" <?php echo $filter_priority === 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                            </select>
                        </div>
                        
                        <div class="filter-group">
                            <button type="submit" class="filter-button">Apply Filters</button>
                            <button type="button" class="reset-button" onclick="window.location.href='employee_road_status_monitoring.php'">Reset</button>
                        </div>
                    </form>
                </div>
                
                <!-- My Assigned Reports -->
                <div class="reports-section">
                    <div class="section-header">
                        <h2 class="section-title">My Assigned Reports</h2>
                        <span class="filter-label"><?php echo count($assigned_reports); ?> reports</span>
                    </div>
                    
                    <?php if (count($assigned_reports) > 0): ?>
                        <div class="table-container">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Report ID</th>
                                        <th>Location</th>
                                        <th>Condition</th>
                                        <th>Severity</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Reported</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($assigned_reports as $report): ?>
                                        <tr>
                                            <td>#<?php echo $report['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($report['location']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($report['barangay']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['condition_type']); ?></td>
                                            <td>
                                                <?php 
                                                    $severity_class = 'severity-' . strtolower($report['severity']) . '-badge';
                                                    echo '<span class="severity-badge ' . $severity_class . '">' . $report['severity'] . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $priority_class = 'priority-' . strtolower($report['priority']) . '-badge';
                                                    echo '<span class="priority-badge ' . $priority_class . '">' . $report['priority'] . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $report['status'])) . '-badge';
                                                    echo '<span class="status-badge ' . $status_class . '">' . $report['status'] . '</span>';
                                                ?>
                                            </td>
                                            <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button-small view-button" onclick="viewReport(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                    <button class="action-button-small update-button" onclick="openUpdateModal(<?php echo $report['id']; ?>, '<?php echo $report['status']; ?>')">
                                                        <i class='bx bx-edit'></i> Update
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class='bx bx-clipboard'></i>
                            </div>
                            <h3 class="empty-state-title">No Assigned Reports</h3>
                            <p>You don't have any assigned road condition reports at the moment.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- All Active Reports -->
                <div class="reports-section">
                    <div class="section-header">
                        <h2 class="section-title">All Active Reports</h2>
                        <span class="filter-label"><?php echo count($filtered_reports); ?> reports</span>
                    </div>
                    
                    <?php if (count($filtered_reports) > 0): ?>
                        <div class="table-container">
                            <table class="reports-table">
                                <thead>
                                    <tr>
                                        <th>Report ID</th>
                                        <th>Location</th>
                                        <th>Condition</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Assigned To</th>
                                        <th>Reported</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($filtered_reports as $report): ?>
                                        <tr>
                                            <td>#<?php echo $report['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($report['location']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($report['barangay']); ?></small>
                                            </td>
                                            <td><?php echo htmlspecialchars($report['condition_type']); ?></td>
                                            <td>
                                                <?php 
                                                    $priority_class = 'priority-' . strtolower($report['priority']) . '-badge';
                                                    echo '<span class="priority-badge ' . $priority_class . '">' . $report['priority'] . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                    $status_class = 'status-' . strtolower(str_replace(' ', '-', $report['status'])) . '-badge';
                                                    echo '<span class="status-badge ' . $status_class . '">' . $report['status'] . '</span>';
                                                ?>
                                            </td>
                                            <td><?php echo $report['assigned_to_name'] ? htmlspecialchars($report['assigned_to_name']) : 'Not Assigned'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button-small view-button" onclick="viewReport(<?php echo $report['id']; ?>)">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                    <?php if ($report['assigned_to'] == $employee_id): ?>
                                                        <button class="action-button-small update-button" onclick="openUpdateModal(<?php echo $report['id']; ?>, '<?php echo $report['status']; ?>')">
                                                            <i class='bx bx-edit'></i> Update
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class='bx bx-search'></i>
                            </div>
                            <h3 class="empty-state-title">No Reports Found</h3>
                            <p>No road condition reports match your current filters.</p>
                        </div>
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
                <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Content will be loaded via AJAX -->
            </div>
            <div class="modal-footer">
                <button class="secondary-button" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <form method="POST" action="">
                <div class="modal-header">
                    <h2 class="modal-title">Update Report Status</h2>
                    <button type="button" class="modal-close" onclick="closeModal('updateModal')">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="report_id" id="updateReportId">
                    
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select" id="statusSelect" onchange="toggleResolutionNotes()">
                            <option value="Pending">Pending</option>
                            <option value="Verified">Verified</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Resolved">Resolved</option>
                            <option value="Needs Clarification">Needs Clarification</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="resolutionNotesGroup" style="display: none;">
                        <label class="form-label">Resolution Notes</label>
                        <textarea name="resolution_notes" class="form-textarea" placeholder="Describe how the issue was resolved..." rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Follow-up Notes (Optional)</label>
                        <textarea name="follow_up_notes" class="form-textarea" placeholder="Add any follow-up information..." rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="secondary-button" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_status" class="primary-button">Update Status</button>
                </div>
            </form>
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
        
        // Add hover effects to cards
        document.querySelectorAll('.card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
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
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.reports-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Modal functions
        function openModal(modalId) {
            document.getElementById(modalId).style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
        
        // View report details
        function viewReport(reportId) {
            // Show loading state
            document.getElementById('viewModalBody').innerHTML = `
                <div style="text-align: center; padding: 40px;">
                    <i class='bx bx-loader-circle bx-spin' style="font-size: 48px; color: var(--primary-color);"></i>
                    <p>Loading report details...</p>
                </div>
            `;
            
            openModal('viewModal');
            
            // Fetch report details via AJAX
            fetch(`get_report_details.php?id=${reportId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('viewModalBody').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('viewModalBody').innerHTML = `
                        <div style="text-align: center; padding: 40px; color: var(--text-light);">
                            <i class='bx bx-error-circle' style="font-size: 48px;"></i>
                            <p>Error loading report details. Please try again.</p>
                        </div>
                    `;
                });
        }
        
        // Open update modal
        function openUpdateModal(reportId, currentStatus) {
            document.getElementById('updateReportId').value = reportId;
            document.getElementById('statusSelect').value = currentStatus;
            toggleResolutionNotes();
            openModal('updateModal');
        }
        
        // Toggle resolution notes field
        function toggleResolutionNotes() {
            const status = document.getElementById('statusSelect').value;
            const notesGroup = document.getElementById('resolutionNotesGroup');
            
            if (status === 'Resolved') {
                notesGroup.style.display = 'block';
            } else {
                notesGroup.style.display = 'none';
            }
        }
        
        // Show SweetAlert for success
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success_message']; ?>',
                showConfirmButton: true,
                confirmButtonColor: '#0d9488',
                timer: 5000
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        // Show SweetAlert on error
        <?php if (isset($error_message)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo $error_message; ?>',
                showConfirmButton: true,
                confirmButtonColor: '#ef4444'
            });
        <?php endif; ?>
        
        // Confirm before submitting status update
        document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
            const status = document.getElementById('statusSelect').value;
            
            if (status === 'Resolved') {
                const resolutionNotes = document.querySelector('textarea[name="resolution_notes"]').value;
                
                if (!resolutionNotes.trim()) {
                    e.preventDefault();
                    Swal.fire({
                        icon: 'warning',
                        title: 'Resolution Required',
                        text: 'Please provide resolution notes when marking as Resolved.',
                        confirmButtonText: 'OK'
                    });
                    return;
                }
            }
            
            Swal.fire({
                title: 'Update Status?',
                text: `Are you sure you want to update this report to "${status}"?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, update it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (!result.isConfirmed) {
                    e.preventDefault();
                }
            });
        });
        
        // Auto-refresh page every 5 minutes
        setTimeout(function() {
            location.reload();
        }, 300000); // 5 minutes = 300000 milliseconds
    </script>
</body>
</html>