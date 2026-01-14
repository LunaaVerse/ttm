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
    }
    return null;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: ../login.php');
    exit();
}

// Allow both ADMIN and TANOD roles to access
$allowed_roles = ['ADMIN', 'TANOD', 'EMPLOYEE'];
if (!in_array($_SESSION['role'], $allowed_roles)) {
    header('Location: ../login.php');
    exit();
}

// Get current user data
$user_id = $_SESSION['user_id'];
$user = getUserData($pdo, $user_id);

// Check if user exists and is verified
if (!$user) {
    header('Location: ../login.php');
    exit();
}

$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'incident-logging';

// Process form submissions
$success_message = '';
$error_message = '';

// Add new incident - Using tanod_field_reports table since incident_logging doesn't exist
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_incident'])) {
    try {
        // Generate unique report code
        $report_code = 'INC-' . date('Ymd') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $sql = "INSERT INTO tanod_field_reports (
            tanod_id, tanod_name, report_type, report_date, location, barangay, 
            latitude, longitude, condition_type, severity, description, image_path,
            status, priority, is_urgent, needs_dispatch, dispatch_team
        ) VALUES (
            :tanod_id, :tanod_name, :report_type, :report_date, :location, :barangay,
            :latitude, :longitude, :condition_type, :severity, :description, :image_path,
            :status, :priority, :is_urgent, :needs_dispatch, :dispatch_team
        )";
        
        $stmt = $pdo->prepare($sql);
        
        // Handle file upload
        $image_path = null;
        if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../../uploads/incidents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['evidence']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check file size (max 10MB)
            if ($_FILES['evidence']['size'] > 10000000) {
                throw new Exception('File is too large. Maximum size is 10MB.');
            }
            
            // Check file type
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            $file_type = mime_content_type($_FILES['evidence']['tmp_name']);
            
            if (!in_array($file_type, $allowed_types)) {
                throw new Exception('Invalid file type. Only images (JPEG, PNG, GIF) are allowed.');
            }
            
            if (move_uploaded_file($_FILES['evidence']['tmp_name'], $target_file)) {
                $image_path = 'uploads/incidents/' . $file_name;
            }
        }
        
        // Map incident types to condition types
        $condition_type_map = [
            'Dispute' => 'Other',
            'Congestion' => 'Traffic Jam',
            'Safety Issue' => 'Other',
            'Blocked Route' => 'Road Collapse',
            'Route Change' => 'Other',
            'Other' => 'Other'
        ];
        
        $condition_type = $condition_type_map[$_POST['log_type']] ?? 'Other';
        
        $stmt->execute([
            'tanod_id' => $user_id,
            'tanod_name' => $full_name,
            'report_type' => 'Quick Incident',
            'report_date' => $_POST['start_time'],
            'location' => $_POST['location'],
            'barangay' => $_POST['barangay_name'] ?? 'Unknown',
            'latitude' => $_POST['latitude'] ?: null,
            'longitude' => $_POST['longitude'] ?: null,
            'condition_type' => $condition_type,
            'severity' => $_POST['severity'],
            'description' => $_POST['description'] . "\n\nIncident Type: " . $_POST['log_type'] . 
                            "\nAffected Vehicles: " . ($_POST['affected_vehicle_type'] ?? 'N/A') . 
                            "\nTemporary Action: " . ($_POST['temporary_action'] ?? 'None'),
            'image_path' => $image_path,
            'status' => 'Submitted',
            'priority' => $_POST['priority'],
            'is_urgent' => ($_POST['severity'] === 'Emergency' || $_POST['severity'] === 'High') ? 1 : 0,
            'needs_dispatch' => isset($_POST['needs_assistance']) ? 1 : 0,
            'dispatch_team' => $_POST['assistance_type'] ?: null
        ]);
        
        $incident_id = $pdo->lastInsertId();
        
        // Also create a follow-up log entry
        $follow_up_code = 'FUL-' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $sql_follow = "INSERT INTO follow_up_logs (
            log_code, incident_id, incident_type, barangay_id, location, temporary_action,
            action_taken_by, action_date, status_before, status_after, priority_level,
            needs_permanent_solution, created_by
        ) VALUES (
            :log_code, :incident_id, :incident_type, :barangay_id, :location, :temporary_action,
            :action_taken_by, :action_date, :status_before, :status_after, :priority_level,
            :needs_permanent_solution, :created_by
        )";
        
        $stmt_follow = $pdo->prepare($sql_follow);
        $stmt_follow->execute([
            'log_code' => $follow_up_code,
            'incident_id' => $incident_id,
            'incident_type' => 'Road Condition',
            'barangay_id' => $_POST['barangay_id'],
            'location' => $_POST['location'],
            'temporary_action' => $_POST['temporary_action'] ?: 'Incident reported, awaiting action',
            'action_taken_by' => $user_id,
            'action_date' => $_POST['start_time'],
            'status_before' => 'Reported',
            'status_after' => 'Under Review',
            'priority_level' => $_POST['priority'],
            'needs_permanent_solution' => ($_POST['severity'] === 'High' || $_POST['severity'] === 'Emergency') ? 1 : 0,
            'created_by' => $user_id
        ]);
        
        $success_message = 'Incident logged successfully! Report Code: ' . $report_code;
        
    } catch (Exception $e) {
        $error_message = 'Error logging incident: ' . $e->getMessage();
    }
}

// Update incident status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    try {
        $sql = "UPDATE tanod_field_reports SET 
                status = :status,
                verified_by = :verified_by,
                verified_date = NOW(),
                updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'status' => $_POST['status'],
            'verified_by' => $user_id,
            'id' => $_POST['incident_id']
        ]);
        
        $success_message = 'Incident status updated successfully!';
        
    } catch (Exception $e) {
        $error_message = 'Error updating incident: ' . $e->getMessage();
    }
}

// Resolve incident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_incident'])) {
    try {
        $sql = "UPDATE tanod_field_reports SET 
                status = 'Resolved',
                resolution_notes = :resolution_notes,
                resolved_date = NOW(),
                updated_at = NOW()
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'resolution_notes' => $_POST['resolution_notes'],
            'id' => $_POST['incident_id']
        ]);
        
        // Update corresponding follow-up log
        $sql_follow = "UPDATE follow_up_logs SET 
                      status_after = 'Resolved',
                      follow_up_notes = :follow_up_notes,
                      updated_at = NOW()
                      WHERE incident_id = :incident_id AND incident_type = 'Road Condition'";
        
        $stmt_follow = $pdo->prepare($sql_follow);
        $stmt_follow->execute([
            'follow_up_notes' => $_POST['resolution_notes'],
            'incident_id' => $_POST['incident_id']
        ]);
        
        $success_message = 'Incident resolved successfully!';
        
    } catch (Exception $e) {
        $error_message = 'Error resolving incident: ' . $e->getMessage();
    }
}

// Fetch data for dropdowns
$barangays = $pdo->query("SELECT id, name FROM barangays ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$routes = $pdo->query("SELECT id, route_code, route_name FROM tricycle_routes ORDER BY route_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch incidents with filters
$filter_where = ["tfr.report_type = 'Quick Incident'"];
$filter_params = [];

if (isset($_GET['filter_status']) && $_GET['filter_status'] !== '') {
    $filter_where[] = "tfr.status = :status";
    $filter_params['status'] = $_GET['filter_status'];
}

if (isset($_GET['filter_type']) && $_GET['filter_type'] !== '') {
    // Map filter type to condition_type
    $type_map = [
        'Dispute' => 'Other',
        'Congestion' => 'Traffic Jam',
        'Safety Issue' => 'Other',
        'Blocked Route' => 'Road Collapse',
        'Route Change' => 'Other'
    ];
    $condition_type = $type_map[$_GET['filter_type']] ?? $_GET['filter_type'];
    $filter_where[] = "tfr.condition_type = :condition_type";
    $filter_params['condition_type'] = $condition_type;
}

if (isset($_GET['filter_barangay']) && $_GET['filter_barangay'] !== '') {
    $filter_where[] = "b.id = :barangay_id";
    $filter_params['barangay_id'] = $_GET['filter_barangay'];
}

if (isset($_GET['filter_severity']) && $_GET['filter_severity'] !== '') {
    $filter_where[] = "tfr.severity = :severity";
    $filter_params['severity'] = $_GET['filter_severity'];
}

$where_clause = $filter_where ? "WHERE " . implode(" AND ", $filter_where) : "";

$sql = "SELECT tfr.*, 
        b.name as barangay_name,
        CONCAT(u.first_name, ' ', u.last_name) as reporter_name
        FROM tanod_field_reports tfr
        LEFT JOIN barangays b ON tfr.barangay = b.name
        LEFT JOIN users u ON tfr.tanod_id = u.id
        $where_clause
        ORDER BY tfr.report_date DESC
        LIMIT 50";

$stmt = $pdo->prepare($sql);
$stmt->execute($filter_params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch statistics
$sql_stats = "SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'Submitted' THEN 1 END) as reported,
        COUNT(CASE WHEN status = 'Verified' THEN 1 END) as verified,
        COUNT(CASE WHEN status = 'Resolved' THEN 1 END) as resolved,
        COUNT(CASE WHEN severity = 'Emergency' THEN 1 END) as emergency,
        COUNT(CASE WHEN severity = 'High' THEN 1 END) as high_severity,
        COUNT(CASE WHEN condition_type = 'Traffic Jam' THEN 1 END) as congestion,
        COUNT(CASE WHEN condition_type = 'Road Collapse' THEN 1 END) as blocked_routes
        FROM tanod_field_reports 
        WHERE report_type = 'Quick Incident'";

$stats = $pdo->query($sql_stats)->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Incident Logging - Traffic and Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
        
        /* Dashboard Content */
        .dashboard-content {
            padding: 32px;
        }
        
        /* Module Header */
        .module-header {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .module-icon {
            width: 64px;
            height: 64px;
            background-color: rgba(239, 68, 68, 0.1);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .module-title-section h1 {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .module-subtitle {
            color: var(--text-light);
        }
        
        /* Tab navigation */
        .tab-navigation {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            color: var(--text-color);
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .tab-button.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .tab-button:hover:not(.active) {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .tab-button:hover:not(.active) {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Alert Messages */
        .alert-card {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid;
            animation: slideIn 0.3s ease-out;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        /* Form styles */
        .form-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            animation: fadeIn 0.5s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(58, 134, 255, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-label {
            font-size: 14px;
            cursor: pointer;
        }
        
        /* Button styles */
        .form-buttons {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 148, 136, 0.3);
        }
        
        .btn-secondary {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
            transform: translateY(-1px);
        }
        
        .dark-mode .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Filter section */
        .filter-section {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
        }
        
        .filter-buttons {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        
        /* Statistics Cards */
        .stats-small-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-small-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-small-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .stat-small-card:hover {
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.3);
        }
        
        .stat-small-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-small-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        /* Incident list table */
        .table-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .table-container {
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
        }
        
        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .table-actions {
            display: flex;
            gap: 8px;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .incident-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .incident-table th {
            background-color: var(--background-color);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }
        
        .incident-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .incident-table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode .incident-table tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        /* Status badges */
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            white-space: nowrap;
        }
        
        .status-draft {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .dark-mode .status-draft {
            background-color: rgba(107, 114, 128, 0.2);
            color: #9ca3af;
        }
        
        .status-submitted {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-submitted {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-verified {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .status-verified {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .status-resolved {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-resolved {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        /* Severity indicators */
        .severity-low {
            color: #10b981;
        }
        
        .severity-medium {
            color: #f59e0b;
        }
        
        .severity-high {
            color: #ef4444;
        }
        
        .severity-emergency {
            color: #dc2626;
            font-weight: bold;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-view {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .btn-view:hover {
            background-color: #bfdbfe;
        }
        
        .btn-edit {
            background-color: #f0fdfa;
            color: #065f46;
        }
        
        .btn-edit:hover {
            background-color: #ccfbf1;
        }
        
        .btn-resolve {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .btn-resolve:hover {
            background-color: #bbf7d0;
        }
        
        /* Modal styles */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease-out;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            color: var(--text-color);
            cursor: pointer;
            font-size: 24px;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }
        
        .modal-close:hover {
            background-color: var(--background-color);
        }
        
        /* Responsive adjustments */
        @media (max-width: 1200px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .stats-small-grid {
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
            
            .stats-small-grid {
                grid-template-columns: 1fr;
            }
            
            .dashboard-content {
                padding: 16px;
            }
            
            .module-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-navigation {
                flex-wrap: wrap;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .form-card {
                padding: 24px;
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
        }
        
        @media (max-width: 576px) {
            .table-header {
                flex-direction: column;
                gap: 16px;
                align-items: flex-start;
            }
            
            .table-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .modal-content {
                padding: 24px 16px;
                width: 95%;
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
                <a href="../lrcr/follow_up_logs.php" class="submenu-item">Follow-Up Logs</a>
            </div>
            
            <!-- 1.2 Barangay Tricycle Route Management -->
            <div class="menu-item active" onclick="toggleSubmenu('route-management')">
                <div class="icon-box icon-box-route-config">
                    <i class='bx bxs-map-alt'></i>
                </div>
                <span class="font-medium">Barangay Tricycle Route Management</span>
                <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="route-management" class="submenu active">
                <a href="route_compliance.php" class="submenu-item">Route Compliance</a>
                <a href="incident_logging.php" class="submenu-item active">Incident Logging</a>
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
            
            <a href="../../includes/logout.php" class="menu-item">
                <div class="icon-box icon-box-logout">
                    <i class='bx bx-log-out'></i>
                </div>
                <span class="font-medium">Logout</span>
            </a>
        </div>
    </div>
</div>

<style>
/* Sidebar Styles */
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
</style>

<script>
// Toggle submenu function
function toggleSubmenu(id) {
    const submenu = document.getElementById(id);
    const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
    
    submenu.classList.toggle('active');
    arrow.classList.toggle('rotated');
}
</script>
        
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
                            <input type="text" placeholder="Search incidents or locations" class="search-input" id="searchInput">
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Module Header -->
                <div class="module-header">
                    <div class="module-icon">
                        <i class='bx bxs-error-alt' style="font-size: 32px; color: #ef4444;"></i>
                    </div>
                    <div class="module-title-section">
                        <h1>Incident Logging / Field Updates</h1>
                        <p class="module-subtitle">Log disputes, congestion, safety issues, and report blocked routes or temporary changes</p>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-small-grid">
                    <div class="stat-small-card">
                        <div class="stat-small-value"><?php echo $stats['total'] ?? 0; ?></div>
                        <div class="stat-small-label">Total Incidents</div>
                    </div>
                    <div class="stat-small-card">
                        <div class="stat-small-value"><?php echo $stats['reported'] ?? 0; ?></div>
                        <div class="stat-small-label">Reported</div>
                    </div>
                    <div class="stat-small-card">
                        <div class="stat-small-value"><?php echo $stats['emergency'] ?? 0; ?></div>
                        <div class="stat-small-label">Emergency</div>
                    </div>
                    <div class="stat-small-card">
                        <div class="stat-small-value"><?php echo $stats['blocked_routes'] ?? 0; ?></div>
                        <div class="stat-small-label">Blocked Routes</div>
                    </div>
                </div>
                
                <!-- Tab Navigation -->
                <div class="tab-navigation">
                    <button class="tab-button active" onclick="showTab('list-tab')">
                        <i class='bx bx-list-ul'></i> Incident List
                    </button>
                    <button class="tab-button" onclick="showTab('report-tab')">
                        <i class='bx bx-plus-circle'></i> Report New Incident
                    </button>
                </div>
                
                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                    <div id="success-message" class="alert-card" style="background-color: #dcfce7; border-color: #bbf7d0;">
                        <h3 class="alert-title">Success!</h3>
                        <p><?php echo htmlspecialchars($success_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div id="error-message" class="alert-card" style="background-color: #fee2e2; border-color: #fecaca;">
                        <h3 class="alert-title">Error!</h3>
                        <p><?php echo htmlspecialchars($error_message); ?></p>
                    </div>
                <?php endif; ?>
                
                <!-- Tab 1: Incident List -->
                <div id="list-tab" class="tab-content active">
                    <!-- Filter Section -->
                    <div class="filter-section">
                        <form method="GET" action="">
                            <div class="filter-grid">
                                <div class="form-group">
                                    <label class="form-label">Status</label>
                                    <select name="filter_status" class="form-select">
                                        <option value="">All Status</option>
                                        <option value="Draft" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'Draft') ? 'selected' : ''; ?>>Draft</option>
                                        <option value="Submitted" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'Submitted') ? 'selected' : ''; ?>>Submitted</option>
                                        <option value="Verified" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'Verified') ? 'selected' : ''; ?>>Verified</option>
                                        <option value="Resolved" <?php echo (isset($_GET['filter_status']) && $_GET['filter_status'] === 'Resolved') ? 'selected' : ''; ?>>Resolved</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Incident Type</label>
                                    <select name="filter_type" class="form-select">
                                        <option value="">All Types</option>
                                        <option value="Dispute" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === 'Dispute') ? 'selected' : ''; ?>>Dispute</option>
                                        <option value="Congestion" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === 'Congestion') ? 'selected' : ''; ?>>Congestion</option>
                                        <option value="Safety Issue" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === 'Safety Issue') ? 'selected' : ''; ?>>Safety Issue</option>
                                        <option value="Blocked Route" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === 'Blocked Route') ? 'selected' : ''; ?>>Blocked Route</option>
                                        <option value="Route Change" <?php echo (isset($_GET['filter_type']) && $_GET['filter_type'] === 'Route Change') ? 'selected' : ''; ?>>Route Change</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Barangay</label>
                                    <select name="filter_barangay" class="form-select">
                                        <option value="">All Barangays</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" <?php echo (isset($_GET['filter_barangay']) && $_GET['filter_barangay'] == $barangay['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Severity</label>
                                    <select name="filter_severity" class="form-select">
                                        <option value="">All Severity</option>
                                        <option value="Low" <?php echo (isset($_GET['filter_severity']) && $_GET['filter_severity'] === 'Low') ? 'selected' : ''; ?>>Low</option>
                                        <option value="Medium" <?php echo (isset($_GET['filter_severity']) && $_GET['filter_severity'] === 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                        <option value="High" <?php echo (isset($_GET['filter_severity']) && $_GET['filter_severity'] === 'High') ? 'selected' : ''; ?>>High</option>
                                        <option value="Emergency" <?php echo (isset($_GET['filter_severity']) && $_GET['filter_severity'] === 'Emergency') ? 'selected' : ''; ?>>Emergency</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="filter-buttons" style="margin-top: 16px;">
                                <button type="submit" class="btn-primary">
                                    <i class='bx bx-filter-alt'></i> Apply Filters
                                </button>
                                <a href="incident_logging.php" class="btn-secondary">Clear Filters</a>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Incident List Table -->
                    <div class="table-container">
                        <div class="table-header">
                            <h3 class="table-title">Recent Incidents (<?php echo count($incidents); ?>)</h3>
                            <div class="table-actions">
                                <button class="btn-primary" onclick="showTab('report-tab')">
                                    <i class='bx bx-plus'></i> New Incident
                                </button>
                            </div>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="incident-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Description</th>
                                        <th>Type</th>
                                        <th>Barangay</th>
                                        <th>Severity</th>
                                        <th>Status</th>
                                        <th>Reported</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="incidentTableBody">
                                    <?php if (empty($incidents)): ?>
                                        <tr>
                                            <td colspan="8" style="text-align: center; padding: 40px;">
                                                <div style="font-size: 48px; color: var(--text-light); margin-bottom: 16px;">
                                                    <i class='bx bx-clipboard'></i>
                                                </div>
                                                <p>No incidents found.</p>
                                                <button class="btn-primary" onclick="showTab('report-tab')" style="margin-top: 12px;">
                                                    <i class='bx bx-plus'></i> Report your first incident
                                                </button>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($incidents as $incident): ?>
                                            <tr data-search="<?php echo htmlspecialchars(strtolower($incident['description'] . ' ' . $incident['location'] . ' ' . $incident['id'])); ?>">
                                                <td>
                                                    <strong>#<?php echo htmlspecialchars($incident['id']); ?></strong>
                                                </td>
                                                <td>
                                                    <div style="font-weight: 500; margin-bottom: 4px;"><?php echo htmlspecialchars(substr($incident['description'], 0, 60)); ?>...</div>
                                                    <div style="font-size: 12px; color: var(--text-light);">
                                                        <i class='bx bx-map'></i> <?php echo htmlspecialchars(substr($incident['location'], 0, 50)); ?>
                                                    </div>
                                                </td>
                                                <td><?php echo htmlspecialchars($incident['condition_type']); ?></td>
                                                <td><?php echo htmlspecialchars($incident['barangay'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="severity-<?php echo strtolower($incident['severity']); ?>">
                                                        <i class='bx bxs-circle' style="font-size: 10px; margin-right: 4px;"></i>
                                                        <?php echo htmlspecialchars($incident['severity']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge status-<?php echo strtolower($incident['status']); ?>">
                                                        <?php echo htmlspecialchars($incident['status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div><?php echo date('M d, Y', strtotime($incident['report_date'])); ?></div>
                                                    <div style="font-size: 12px; color: var(--text-light);">
                                                        <?php echo date('h:i A', strtotime($incident['report_date'])); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="btn-sm btn-view" onclick="viewIncident(<?php echo $incident['id']; ?>)">
                                                            <i class='bx bx-show'></i> View
                                                        </button>
                                                        <?php if ($incident['status'] === 'Submitted' || $incident['status'] === 'Draft'): ?>
                                                            <button class="btn-sm btn-edit" onclick="updateStatus(<?php echo $incident['id']; ?>, '<?php echo $incident['status']; ?>')">
                                                                <i class='bx bx-edit'></i> Update
                                                            </button>
                                                        <?php endif; ?>
                                                        <?php if ($incident['status'] === 'Verified'): ?>
                                                            <button class="btn-sm btn-resolve" onclick="resolveIncident(<?php echo $incident['id']; ?>, '<?php echo $incident['status']; ?>')">
                                                                <i class='bx bx-check'></i> Resolve
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
                <!-- Tab 2: Report New Incident -->
                <div id="report-tab" class="tab-content" style="display: none;">
                    <div class="form-card">
                        <h2 class="form-title">Report New Incident / Field Update</h2>
                        
                        <form method="POST" action="" enctype="multipart/form-data" id="incidentForm">
                            <div class="form-grid">
                                <!-- Incident Details -->
                                <div class="form-group">
                                    <label class="form-label">Incident Type *</label>
                                    <select name="log_type" class="form-select" required>
                                        <option value="">Select Type</option>
                                        <option value="Dispute">Dispute (Driver/Passenger)</option>
                                        <option value="Congestion">Traffic Congestion</option>
                                        <option value="Safety Issue">Safety Issue</option>
                                        <option value="Blocked Route">Blocked Route</option>
                                        <option value="Route Change">Temporary Route Change</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Severity Level *</label>
                                    <select name="severity" class="form-select" required>
                                        <option value="Low">Low</option>
                                        <option value="Medium">Medium</option>
                                        <option value="High">High</option>
                                        <option value="Emergency">Emergency</option>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Title/Summary *</label>
                                    <input type="text" name="title" class="form-input" placeholder="Brief description of the incident" required>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label class="form-label">Detailed Description *</label>
                                    <textarea name="description" class="form-textarea" placeholder="Describe the incident in detail, include any relevant information..." required></textarea>
                                </div>
                                
                                <!-- Location Details -->
                                <div class="form-group">
                                    <label class="form-label">Barangay *</label>
                                    <select name="barangay_id" class="form-select" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" data-name="<?php echo htmlspecialchars($barangay['name']); ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="hidden" name="barangay_name" id="barangay_name">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Affected Vehicle Type</label>
                                    <select name="affected_vehicle_type" class="form-select">
                                        <option value="Tricycle">Tricycle</option>
                                        <option value="Jeepney">Jeepney</option>
                                        <option value="Private">Private Vehicle</option>
                                        <option value="Multiple">Multiple Types</option>
                                        <option value="All">All Vehicles</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Location *</label>
                                    <input type="text" name="location" class="form-input" placeholder="Exact location or landmark" required>
                                </div>
                                
                                <!-- Timing -->
                                <div class="form-group">
                                    <label class="form-label">Start Time *</label>
                                    <input type="datetime-local" name="start_time" class="form-input" required>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Estimated End Time</label>
                                    <input type="datetime-local" name="estimated_end_time" class="form-input">
                                </div>
                                
                                <!-- Temporary Action -->
                                <div class="form-group full-width">
                                    <label class="form-label">Temporary Action Taken</label>
                                    <textarea name="temporary_action" class="form-textarea" placeholder="What temporary measures have been implemented? (e.g., traffic diverted, signs placed, etc.)"></textarea>
                                </div>
                                
                                <!-- Additional Information -->
                                <div class="form-group">
                                    <label class="form-label">Priority</label>
                                    <select name="priority" class="form-select">
                                        <option value="Low">Low</option>
                                        <option value="Medium" selected>Medium</option>
                                        <option value="High">High</option>
                                        <option value="Emergency">Emergency</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Coordinates (Optional)</label>
                                    <div style="display: flex; gap: 8px;">
                                        <input type="text" name="latitude" class="form-input" placeholder="Latitude" style="flex: 1;">
                                        <input type="text" name="longitude" class="form-input" placeholder="Longitude" style="flex: 1;">
                                    </div>
                                    <small style="color: var(--text-light); font-size: 12px;">Format: 14.5995, 120.9842</small>
                                </div>
                                
                                <!-- Assistance Needed -->
                                <div class="form-group">
                                    <div class="form-checkbox">
                                        <input type="checkbox" name="needs_assistance" id="needs_assistance" class="checkbox-input">
                                        <label for="needs_assistance" class="checkbox-label">Needs Additional Assistance</label>
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Type of Assistance Needed</label>
                                    <input type="text" name="assistance_type" class="form-input" placeholder="e.g., Traffic Management, Medical, etc.">
                                </div>
                                
                                <!-- Evidence Upload -->
                                <div class="form-group full-width">
                                    <label class="form-label">Evidence (Photo)</label>
                                    <input type="file" name="evidence" class="form-input" accept="image/*" id="evidenceInput">
                                    <div id="filePreview" style="margin-top: 8px; display: none;">
                                        <img id="previewImage" src="" alt="Preview" style="max-width: 200px; max-height: 150px; border-radius: 8px;">
                                    </div>
                                    <small style="color: var(--text-light);">Upload photos of the incident (max 10MB)</small>
                                </div>
                            </div>
                            
                            <div class="form-buttons">
                                <button type="button" class="btn-secondary" onclick="showTab('list-tab')">
                                    Cancel
                                </button>
                                <button type="submit" name="add_incident" class="btn-primary" id="submitBtn">
                                    <i class='bx bx-save'></i> Log Incident
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Incident Details</h3>
                <button class="modal-close" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="incidentDetails"></div>
        </div>
    </div>
    
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Incident Status</h3>
                <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="incident_id" id="statusIncidentId">
                
                <div class="form-group">
                    <label class="form-label">New Status</label>
                    <select name="status" class="form-select" required>
                        <option value="Verified">Verified</option>
                        <option value="Resolved">Resolved</option>
                    </select>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn-secondary" onclick="closeModal('statusModal')">Cancel</button>
                    <button type="submit" name="update_status" class="btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <div id="resolveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Resolve Incident</h3>
                <button class="modal-close" onclick="closeModal('resolveModal')">&times;</button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="incident_id" id="resolveIncidentId">
                
                <div class="form-group">
                    <label class="form-label">Resolution Notes *</label>
                    <textarea name="resolution_notes" class="form-textarea" placeholder="How was the incident resolved? Include final actions taken..." required></textarea>
                </div>
                
                <div class="form-buttons">
                    <button type="button" class="btn-secondary" onclick="closeModal('resolveModal')">Cancel</button>
                    <button type="submit" name="resolve_incident" class="btn-primary">Mark as Resolved</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Show success/error messages with SweetAlert2
        <?php if ($success_message): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo addslashes($success_message); ?>',
                timer: 3000,
                showConfirmButton: false,
                position: 'top-end',
                toast: true
            });
        <?php endif; ?>
        
        <?php if ($error_message): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo addslashes($error_message); ?>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#ef476f'
            });
        <?php endif; ?>
        
        // Tab navigation functions
        function showTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabId).style.display = 'block';
            
            // Add active class to clicked button
            event.target.classList.add('active');
            
            // If showing report tab, set current time
            if (tabId === 'report-tab') {
                const now = new Date();
                now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                document.querySelector('input[name="start_time"]').value = now.toISOString().slice(0, 16);
                
                // Set default end time (1 hour from now)
                const endTime = new Date(now);
                endTime.setHours(endTime.getHours() + 1);
                document.querySelector('input[name="estimated_end_time"]').value = endTime.toISOString().slice(0, 16);
            }
            
            // Scroll to top of content
            document.querySelector('.dashboard-content').scrollTop = 0;
        }
        
        // Modal functions
        function viewIncident(id) {
            // Fetch incident details via AJAX
            fetch(`../../api/get_incident.php?id=${id}`)
                .then(response => response.json())
                .then(data => {
                    let html = `
                        <div class="incident-details">
                            <h4 style="margin-bottom: 16px; color: var(--primary-color);">Incident Details #${id}</h4>
                            
                            <div style="margin-bottom: 16px;">
                                <strong>Type:</strong> ${data.condition_type || 'N/A'}<br>
                                <strong>Severity:</strong> <span class="severity-${data.severity?.toLowerCase()}">${data.severity || 'N/A'}</span><br>
                                <strong>Status:</strong> <span class="status-badge status-${data.status?.toLowerCase()}">${data.status || 'N/A'}</span><br>
                                <strong>Location:</strong> ${data.location || 'N/A'}<br>
                                <strong>Barangay:</strong> ${data.barangay || 'N/A'}<br>
                                <strong>Reported:</strong> ${new Date(data.report_date).toLocaleString()}<br>
                            </div>
                            
                            <div style="margin-bottom: 16px;">
                                <strong>Description:</strong>
                                <p>${data.description || 'No description provided.'}</p>
                            </div>
                            
                            ${data.image_path ? `
                            <div style="margin-bottom: 16px;">
                                <strong>Evidence:</strong><br>
                                <img src="../../${data.image_path}" alt="Incident Evidence" style="max-width: 100%; border-radius: 8px; margin-top: 8px;">
                            </div>` : ''}
                        </div>
                    `;
                    
                    document.getElementById('incidentDetails').innerHTML = html;
                    document.getElementById('viewModal').classList.add('active');
                })
                .catch(error => {
                    console.error('Error fetching incident:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Could not load incident details. Please try again.'
                    });
                });
        }
        
        function updateStatus(id, oldStatus) {
            document.getElementById('statusIncidentId').value = id;
            document.getElementById('statusModal').classList.add('active');
        }
        
        function resolveIncident(id, oldStatus) {
            document.getElementById('resolveIncidentId').value = id;
            document.getElementById('resolveModal').classList.add('active');
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }
        
        // File preview for evidence upload
        document.getElementById('evidenceInput').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const previewDiv = document.getElementById('filePreview');
            const previewImg = document.getElementById('previewImage');
            
            if (file && file.type.startsWith('image/')) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    previewImg.src = e.target.result;
                    previewDiv.style.display = 'block';
                }
                reader.readAsDataURL(file);
            } else {
                previewDiv.style.display = 'none';
            }
        });
        
        // Form validation
        document.getElementById('incidentForm').addEventListener('submit', function(e) {
            const title = document.querySelector('input[name="title"]').value;
            const description = document.querySelector('textarea[name="description"]').value;
            
            if (!title.trim() || !description.trim()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: 'Please fill in all required fields',
                    confirmButtonColor: '#f59e0b'
                });
                return false;
            }
            
            // Update barangay name from select
            const barangaySelect = document.querySelector('select[name="barangay_id"]');
            const selectedOption = barangaySelect.options[barangaySelect.selectedIndex];
            document.getElementById('barangay_name').value = selectedOption.dataset.name;
            
            // Show loading state
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Logging Incident...';
            submitBtn.disabled = true;
        });
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#incidentTableBody tr');
            
            rows.forEach(row => {
                if (row.dataset.search) {
                    if (row.dataset.search.includes(searchTerm) || searchTerm === '') {
                        row.style.display = '';
                    } else {
                        row.style.display = 'none';
                    }
                }
            });
        });
        
        // Search shortcut
        document.addEventListener('keydown', function(e) {
            if (e.key === '/' && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
        });
        
        // Initialize time for form
        document.addEventListener('DOMContentLoaded', function() {
            // Auto-hide success/error messages after 5 seconds
            setTimeout(() => {
                const successMsg = document.getElementById('success-message');
                const errorMsg = document.getElementById('error-message');
                if (successMsg) successMsg.style.display = 'none';
                if (errorMsg) errorMsg.style.display = 'none';
            }, 5000);
        });
        
        // Toggle submenu function
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');
        const themeText = themeToggle.querySelector('span');
        
        // Check for saved theme preference or prefer-color-scheme
        const prefersDarkScheme = window.matchMedia('(prefers-color-scheme: dark)');
        const savedTheme = localStorage.getItem('theme');
        
        if (savedTheme === 'dark' || (!savedTheme && prefersDarkScheme.matches)) {
            document.body.classList.add('dark-mode');
            themeIcon.className = 'bx bx-sun';
            themeText.textContent = 'Light Mode';
        }
        
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
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
        
        // Confirmation for status changes
        document.querySelectorAll('form').forEach(form => {
            if (form.querySelector('button[name="update_status"]') || form.querySelector('button[name="resolve_incident"]')) {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const form = this;
                    const action = form.querySelector('button[name="update_status"]') ? 'update status' : 'resolve incident';
                    
                    Swal.fire({
                        title: `Confirm ${action}`,
                        text: `Are you sure you want to ${action}?`,
                        icon: 'question',
                        showCancelButton: true,
                        confirmButtonText: `Yes, ${action}`,
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#06d6a0'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            form.submit();
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>