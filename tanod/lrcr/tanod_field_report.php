<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Check if user is logged in and is a TANOD
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'TANOD') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Function to get user data
function getUserData($pdo, $user_id) {
    $sql = "SELECT * FROM users WHERE id = :user_id AND is_verified = 1 LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get user data
$user = getUserData($pdo, $user_id);
if (!$user) {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Get active patrol log
$current_patrol = null;
$sql_patrol = "SELECT * FROM tanod_patrol_logs WHERE tanod_id = :user_id AND status = 'Active' ORDER BY created_at DESC LIMIT 1";
$stmt = $pdo->prepare($sql_patrol);
$stmt->execute(['user_id' => $user_id]);
$current_patrol = $stmt->fetch(PDO::FETCH_ASSOC);

// Get barangays for dropdown
$sql_barangays = "SELECT id, name FROM barangays ORDER BY name";
$stmt = $pdo->query($sql_barangays);
$barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get my recent reports
$sql_my_reports = "SELECT r.*, b.name as barangay_name 
                   FROM road_condition_reports r 
                   LEFT JOIN barangays b ON r.barangay = b.name
                   WHERE r.reporter_id = :user_id 
                   ORDER BY r.created_at DESC 
                   LIMIT 10";
$stmt = $pdo->prepare($sql_my_reports);
$stmt->execute(['user_id' => $user_id]);
$my_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch dashboard statistics
$stats = [];

try {
    // Fetch today's reports count
    $sql_today_reports = "SELECT COUNT(*) as count FROM road_condition_reports 
                         WHERE reporter_id = :user_id AND DATE(report_date) = CURDATE()";
    $stmt = $pdo->prepare($sql_today_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_reports'] = $row['count'] ?? 0;

    // Fetch total reports
    $sql_total_reports = "SELECT COUNT(*) as count FROM road_condition_reports WHERE reporter_id = :user_id";
    $stmt = $pdo->prepare($sql_total_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_reports'] = $row['count'] ?? 0;

    // Fetch urgent reports
    $sql_urgent_reports = "SELECT COUNT(*) as count FROM road_condition_reports 
                          WHERE reporter_id = :user_id AND priority = 'Emergency'";
    $stmt = $pdo->prepare($sql_urgent_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['urgent_reports'] = $row['count'] ?? 0;

    // Fetch resolved reports
    $sql_resolved_reports = "SELECT COUNT(*) as count FROM road_condition_reports 
                            WHERE reporter_id = :user_id AND status = 'Resolved'";
    $stmt = $pdo->prepare($sql_resolved_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['resolved_reports'] = $row['count'] ?? 0;

} catch (Exception $e) {
    $stats['today_reports'] = 0;
    $stats['total_reports'] = 0;
    $stats['urgent_reports'] = 0;
    $stats['resolved_reports'] = 0;
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate required fields
        $required = ['location', 'barangay_id', 'road_type', 'condition_type', 'severity', 'description'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Please fill in all required fields");
            }
        }
        
        // Get barangay name
        $barangay_name = '';
        foreach ($barangays as $b) {
            if ($b['id'] == $_POST['barangay_id']) {
                $barangay_name = $b['name'];
                break;
            }
        }
        
        if (empty($barangay_name)) {
            throw new Exception("Invalid barangay selected");
        }
        
        // Handle file upload
        $image_path = null;
        if (isset($_FILES['incident_image']) && $_FILES['incident_image']['error'] == UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/road_reports/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_name = time() . '_' . basename($_FILES['incident_image']['name']);
            $target_file = $upload_dir . $file_name;
            
            // Check if image file is actual image
            $check = getimagesize($_FILES['incident_image']['tmp_name']);
            if ($check === false) {
                throw new Exception("File is not an image");
            }
            
            // Check file size (5MB max)
            if ($_FILES['incident_image']['size'] > 5000000) {
                throw new Exception("File is too large. Maximum size is 5MB");
            }
            
            // Allow certain file formats
            $imageFileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
            if (!in_array($imageFileType, ['jpg', 'jpeg', 'png', 'gif'])) {
                throw new Exception("Only JPG, JPEG, PNG & GIF files are allowed");
            }
            
            if (move_uploaded_file($_FILES['incident_image']['tmp_name'], $target_file)) {
                $image_path = 'uploads/road_reports/' . $file_name;
            }
        }
        
        // Determine if urgent hazard
        $is_urgent = isset($_POST['urgent_hazard']) ? 1 : 0;
        $priority = $is_urgent ? 'Emergency' : $_POST['severity'];
        
        // Insert report
        $sql = "INSERT INTO road_condition_reports (
            reporter_id, 
            reporter_name, 
            reporter_role, 
            report_date, 
            location, 
            barangay, 
            road_type, 
            condition_type, 
            severity, 
            description, 
            image_path, 
            status, 
            priority, 
            is_tanod_report,
            created_at,
            updated_at
        ) VALUES (
            :reporter_id, 
            :reporter_name, 
            :reporter_role, 
            :report_date, 
            :location, 
            :barangay, 
            :road_type, 
            :condition_type, 
            :severity, 
            :description, 
            :image_path, 
            :status, 
            :priority, 
            :is_tanod_report,
            NOW(),
            NOW()
        )";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            'reporter_id' => $user_id,
            'reporter_name' => $full_name,
            'reporter_role' => 'TANOD',
            'report_date' => date('Y-m-d'),
            'location' => $_POST['location'],
            'barangay' => $barangay_name,
            'road_type' => $_POST['road_type'],
            'condition_type' => $_POST['condition_type'],
            'severity' => $_POST['severity'],
            'description' => $_POST['description'],
            'image_path' => $image_path,
            'status' => 'Pending',
            'priority' => $priority,
            'is_tanod_report' => 1
        ]);
        
        if ($result) {
            $report_id = $pdo->lastInsertId();
            
            // Add to assignment logs
            $sql_log = "INSERT INTO assignment_logs (
                report_id, 
                assigned_by, 
                assigned_to, 
                assignment_date, 
                notes, 
                created_at
            ) VALUES (
                :report_id, 
                :assigned_by, 
                :assigned_to, 
                NOW(), 
                :notes, 
                NOW()
            )";
            
            $stmt_log = $pdo->prepare($sql_log);
            $stmt_log->execute([
                'report_id' => $report_id,
                'assigned_by' => $user_id,
                'assigned_to' => $user_id,
                'notes' => 'Initial report created by TANOD during patrol'
            ]);
            
            $success_message = "Report submitted successfully! Report ID: TANOD-" . $report_id;
            
            // Clear POST data
            $_POST = [];
            
            // Refresh recent reports
            $stmt = $pdo->prepare($sql_my_reports);
            $stmt->execute(['user_id' => $user_id]);
            $my_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } else {
            throw new Exception("Failed to submit report");
        }
        
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

// Set current page
$current_page = 'field_observation';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Field Road Observation - Tanod Patrol Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
        
        .dashboard-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .dashboard-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .dashboard-subtitle {
            color: var(--text-light);
        }
        
        .dashboard-actions {
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
        
        .stat-button {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .stat-button-primary {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .stat-button-primary:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .stat-button-white {
            background-color: var(--background-color);
        }
        
        .stat-button-white:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .stat-button-white {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .dark-mode .stat-button-white:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        
        .stat-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-info {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
        }
        
        .stat-icon {
            width: 16px;
            height: 16px;
        }
        
        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        /* Card Styles */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: background-color 0.3s, border-color 0.3s;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        
        /* Two Column Grid */
        .two-column-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        .action-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .action-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.3);
        }
        
        .action-icon {
            width: 32px;
            height: 32px;
            margin-bottom: 12px;
            color: var(--primary-color);
        }
        
        .action-label {
            font-size: 14px;
            font-weight: 500;
            text-align: center;
        }
        
        /* Alert Messages */
        .alert-message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: none;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .dark-mode .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .dark-mode .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        /* Report Form Styles */
        .report-form {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
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
        
        .required::after {
            content: " *";
            color: #ef4444;
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
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .image-upload {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        
        .image-upload:hover {
            border-color: var(--primary-color);
        }
        
        .image-upload input {
            display: none;
        }
        
        .image-preview {
            margin-top: 20px;
            max-width: 300px;
            display: none;
        }
        
        .image-preview img {
            max-width: 100%;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .urgent-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            padding: 15px;
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
        }
        
        .urgent-checkbox input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: #ef4444;
        }
        
        .urgent-label {
            color: #ef4444;
            font-weight: 600;
            font-size: 16px;
        }
        
        .submit-btn {
            background-color: var(--secondary-color);
            color: white;
            padding: 16px 32px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            width: 100%;
            transition: background-color 0.3s;
        }
        
        .submit-btn:hover {
            background-color: var(--secondary-dark);
        }
        
        .submit-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Recent Reports */
        .recent-reports {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
        }
        
        .report-item {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }
        
        .report-item:last-child {
            border-bottom: none;
        }
        
        .report-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .report-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .report-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
        }
        
        .report-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .report-details {
            flex: 1;
        }
        
        .report-title {
            font-weight: 600;
            margin-bottom: 4px;
            color: var(--text-color);
        }
        
        .report-meta {
            display: flex;
            gap: 12px;
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 8px;
        }
        
        .report-description {
            font-size: 14px;
            color: var(--text-color);
            line-height: 1.5;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-verified {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-assigned {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-resolved {
            background-color: #dcfce7;
            color: #166534;
        }
        
        /* Camera Button */
        .camera-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            transition: transform 0.3s;
        }
        
        .camera-btn:hover {
            transform: scale(1.1);
        }
        
        .camera-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.9);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .camera-container {
            width: 90%;
            max-width: 800px;
            background-color: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
        }
        
        #camera-preview {
            width: 100%;
            height: 400px;
            background-color: #000;
        }
        
        .camera-controls {
            padding: 20px;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        
        .camera-btn-action {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }
        
        .btn-capture {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-cancel {
            background-color: var(--border-color);
            color: var(--text-color);
        }
        
        /* Patrol Status */
        .patrol-status {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 24px;
        }
        
        .patrol-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .patrol-badge {
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        /* Alerts */
        .alert-card {
            background-color: #f0fdfa;
            border: 1px solid #ccfbf1;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 16px;
            transition: transform 0.2s;
        }
        
        .dark-mode .alert-card {
            background-color: rgba(20, 184, 166, 0.1);
            border: 1px solid rgba(20, 184, 166, 0.3);
        }
        
        .alert-card:hover {
            transform: translateY(-2px);
        }
        
        .alert-title {
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .alert-time {
            font-size: 14px;
            color: var(--text-light);
            margin-bottom: 12px;
        }
        
        .alert-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.2s;
            font-size: 14px;
            width: auto;
            margin: 0 auto;
        }
        
        .alert-button:hover {
            background-color: var(--secondary-dark);
        }
        
        .button-icon {
            width: 18px;
            height: 18px;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .two-column-grid {
                grid-template-columns: 1fr;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
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
            
            .dashboard-content {
                padding: 16px;
            }
            
            .dashboard-header {
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
            
            .patrol-info {
                flex-direction: column;
                gap: 15px;
                align-items: flex-start;
            }
            
            .report-form {
                padding: 20px;
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
                    <!-- Dashboard -->
                    <a href="../tanod_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                   
                    
                   
                    
                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item active">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-condition" class="submenu active">
                        <a href="../lrcr/tanod_field_report.php" class="submenu-item active">Report Condition</a>
                        <a href="../lrcr/follow_up_logs.php" class="submenu-item">Follow-Up Logs</a>
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
                        <a href="../btrm/incident_logging.php" class="submenu-item">Incident Logging</a>
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
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div class="search-container">
                        <div class="search-box">
                            <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                            <input type="text" placeholder="Search reports or locations..." class="search-input">
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Patrol Status -->
                <?php if ($current_patrol): ?>
                <div class="patrol-status">
                    <div class="patrol-info">
                        <div>
                            <h3 style="margin-bottom: 8px; font-size: 20px;">🚓 Currently on Patrol</h3>
                            <p style="margin-bottom: 4px; opacity: 0.9;">Location: <?php echo htmlspecialchars($current_patrol['location']); ?></p>
                            <p style="opacity: 0.9;">Started: <?php echo date('h:i A', strtotime($current_patrol['start_time'])); ?></p>
                        </div>
                        <div class="patrol-badge">
                            <i class='bx bx-walk'></i> ACTIVE PATROL
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Title and Actions -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Field Road Observation</h1>
                        <p class="dashboard-subtitle">Report road conditions, incidents, and hazards during your patrol</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='road_condition/my_reports.php'">
                            <i class='bx bx-history'></i>
                            My Reports
                        </button>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                <div class="alert-message alert-success" id="successAlert">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class='bx bx-check-circle' style="font-size: 20px;"></i>
                        <span><?php echo htmlspecialchars($success_message); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                <div class="alert-message alert-error" id="errorAlert">
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <i class='bx bx-error-circle' style="font-size: 20px;"></i>
                        <span><?php echo htmlspecialchars($error_message); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Today's Reports</span>
                            <button class="stat-button stat-button-primary" onclick="window.location.href='road_condition/my_reports.php?filter=today'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['today_reports']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Reports today</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Total Reports</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='road_condition/my_reports.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_reports']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>All reports</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Urgent Reports</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='road_condition/urgent_hazards.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['urgent_reports']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Emergency priority</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Resolved</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='road_condition/my_reports.php?filter=resolved'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo $stats['resolved_reports']; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Issues resolved</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Left Column (2/3) -->
                    <div class="left-column">
                        <!-- Quick Actions -->
                        <div class="card">
                            <h2 class="card-title">Quick Actions</h2>
                            <div class="quick-actions">
                                <div class="action-button" onclick="useCurrentLocation()">
                                    <div class="icon-box icon-box-tanod" style="width: 48px; height: 48px;">
                                        <i class='bx bxs-current-location' style="font-size: 24px;"></i>
                                    </div>
                                    <span class="action-label">Use Current Location</span>
                                </div>
                                <div class="action-button" onclick="openCamera()">
                                    <div class="icon-box icon-box-road-condition" style="width: 48px; height: 48px;">
                                        <i class='bx bxs-camera' style="font-size: 24px;"></i>
                                    </div>
                                    <span class="action-label">Take Photo</span>
                                </div>
                                <div class="action-button" onclick="markAsUrgent()">
                                    <div class="icon-box icon-box-incident" style="width: 48px; height: 48px;">
                                        <i class='bx bxs-error' style="font-size: 24px;"></i>
                                    </div>
                                    <span class="action-label">Mark as Urgent</span>
                                </div>
                                <div class="action-button" onclick="clearForm()">
                                    <div class="icon-box icon-box-settings" style="width: 48px; height: 48px;">
                                        <i class='bx bxs-reset' style="font-size: 24px;"></i>
                                    </div>
                                    <span class="action-label">Clear Form</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Report Form -->
                        <form id="reportForm" method="POST" enctype="multipart/form-data" class="report-form">
                            <div class="form-grid">
                                <!-- Location -->
                                <div class="form-group">
                                    <label for="location" class="form-label required"> Location</label>
                                    <input type="text" id="location" name="location" class="form-input" 
                                           placeholder="e.g., Main Street near Market" 
                                           value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>"
                                           required>
                                    <div style="margin-top: 5px; display: flex; gap: 10px;">
                                        <button type="button" onclick="useCurrentLocation()" class="secondary-button" style="padding: 6px 12px; font-size: 12px;">
                                            <i class='bx bx-current-location'></i> Current Location
                                        </button>
                                        <button type="button" onclick="openMap()" class="secondary-button" style="padding: 6px 12px; font-size: 12px;">
                                            <i class='bx bxs-map'></i> Select on Map
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Barangay -->
                                <div class="form-group">
                                    <label for="barangay_id" class="form-label required">Barangay</label>
                                    <select id="barangay_id" name="barangay_id" class="form-select" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                        <option value="<?php echo $barangay['id']; ?>" 
                                            <?php echo (isset($_POST['barangay_id']) && $_POST['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($barangay['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <!-- Road Type -->
                                <div class="form-group">
                                    <label for="road_type" class="form-label required">Road Type</label>
                                    <select id="road_type" name="road_type" class="form-select" required>
                                        <option value="">Select Road Type</option>
                                        <option value="Major Road" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Major Road') ? 'selected' : ''; ?>>Major Road</option>
                                        <option value="Minor Road" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Minor Road') ? 'selected' : ''; ?>>Minor Road</option>
                                        <option value="Alley" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Alley') ? 'selected' : ''; ?>>Alley</option>
                                        <option value="Bridge" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Bridge') ? 'selected' : ''; ?>>Bridge</option>
                                        <option value="Other" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                
                                <!-- Condition Type -->
                                <div class="form-group">
                                    <label for="condition_type" class="form-label required"> Condition Type</label>
                                    <select id="condition_type" name="condition_type" class="form-select" required>
                                        <option value="">Select Condition</option>
                                        <option value="Pothole" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Pothole') ? 'selected' : ''; ?>>Pothole</option>
                                        <option value="Flooding" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Flooding') ? 'selected' : ''; ?>>Flooding</option>
                                        <option value="Debris" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Debris') ? 'selected' : ''; ?>>Debris</option>
                                        <option value="Damaged Pavement" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Damaged Pavement') ? 'selected' : ''; ?>>Damaged Pavement</option>
                                        <option value="Slippery Surface" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Slippery Surface') ? 'selected' : ''; ?>>Slippery Surface</option>
                                        <option value="Missing Signage" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Missing Signage') ? 'selected' : ''; ?>>Missing Signage</option>
                                        <option value="Poor Drainage" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Poor Drainage') ? 'selected' : ''; ?>>Poor Drainage</option>
                                        <option value="Vegetation Overgrowth" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Vegetation Overgrowth') ? 'selected' : ''; ?>>Vegetation Overgrowth</option>
                                        <option value="Other" <?php echo (isset($_POST['condition_type']) && $_POST['condition_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                                
                                <!-- Severity -->
                                <div class="form-group">
                                    <label for="severity" class="form-label required"> Severity Level</label>
                                    <select id="severity" name="severity" class="form-select" required>
                                        <option value="">Select Severity</option>
                                        <option value="Low" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                                        <option value="Medium" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                        <option value="High" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'High') ? 'selected' : ''; ?>>High</option>
                                        <option value="Emergency" <?php echo (isset($_POST['severity']) && $_POST['severity'] == 'Emergency') ? 'selected' : ''; ?>>Emergency</option>
                                    </select>
                                </div>
                                
                                <!-- Urgent Hazard Checkbox -->
                                <div class="form-group full-width urgent-checkbox">
                                    <input type="checkbox" id="urgent_hazard" name="urgent_hazard" value="1" 
                                           <?php echo (isset($_POST['urgent_hazard']) && $_POST['urgent_hazard'] == '1') ? 'checked' : ''; ?>>
                                    <label for="urgent_hazard" class="urgent-label">
                                        ⚠️ Mark as URGENT HAZARD (Fallen trees, road collapse, immediate danger)
                                    </label>
                                </div>
                                
                                <!-- Description -->
                                <div class="form-group full-width">
                                    <label for="description" class="form-label required"> Description</label>
                                    <textarea id="description" name="description" class="form-textarea" 
                                              placeholder="Provide detailed description of the issue..." 
                                              required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                    <div id="charCount" style="font-size: 12px; color: var(--text-light); text-align: right; margin-top: 5px;">
                                        0/500 characters
                                    </div>
                                </div>
                                
                                <!-- Image Upload -->
                                <div class="form-group full-width">
                                    <label class="form-label">📸 Photo Evidence</label>
                                    <div class="image-upload" onclick="document.getElementById('incident_image').click()">
                                        <i class='bx bxs-camera' style="font-size: 48px; color: var(--text-light); margin-bottom: 16px;"></i>
                                        <p style="color: var(--text-light); margin-bottom: 8px;">Click to upload photo or use camera</p>
                                        <p style="font-size: 12px; color: var(--text-light);">Supports JPG, PNG, GIF (Max 5MB)</p>
                                        <input type="file" id="incident_image" name="incident_image" accept="image/*" onchange="previewImage(event)">
                                    </div>
                                    <div id="imagePreview" class="image-preview">
                                        <img id="previewImg" src="#" alt="Preview">
                                        <button type="button" onclick="removeImage()" style="margin-top: 10px; padding: 8px 16px; background: #ef4444; color: white; border: none; border-radius: 4px; cursor: pointer;">
                                            Remove Image
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Submit Button -->
                            <button type="submit" class="submit-btn" id="submitBtn">
                                <i class='bx bxs-send'></i>
                                Submit Road Condition Report
                            </button>
                        </form>
                    </div>
                    
                    <!-- Right Column (1/3) -->
                    <div class="right-column">
                        <!-- Recent Reports -->
                        <div class="recent-reports">
                            <div class="assignment-header">
                                <h2 class="card-title">My Recent Reports</h2>
                                <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="window.location.href='road_condition/my_reports.php'">View All</button>
                            </div>
                            <?php if (empty($my_reports)): ?>
                                <div class="alert-card">
                                    <h3 class="alert-title">No Reports Yet</h3>
                                    <p class="alert-time">Submit your first road condition report</p>
                                </div>
                            <?php else: ?>
                                <div class="incident-list">
                                    <?php foreach ($my_reports as $report): 
                                        $status_badge_class = 'badge-' . strtolower($report['status']);
                                        $time_ago = time_elapsed_string($report['created_at']);
                                    ?>
                                    <div class="report-item" onclick="window.location.href='road_condition/view_report.php?id=<?php echo $report['id']; ?>'">
                                        <?php if ($report['image_path']): ?>
                                        <div class="report-image">
                                            <img src="../<?php echo htmlspecialchars($report['image_path']); ?>" alt="Report Image" style="object-fit: cover;">
                                        </div>
                                        <?php else: ?>
                                        <div class="report-image" style="background-color: var(--border-color); display: flex; align-items: center; justify-content: center;">
                                            <i class='bx bxs-image' style="font-size: 32px; color: var(--text-light);"></i>
                                        </div>
                                        <?php endif; ?>
                                        <div class="report-details">
                                            <div class="report-title">
                                                <?php echo htmlspecialchars($report['condition_type']); ?>
                                            </div>
                                            <div class="report-meta">
                                                <span><i class='bx bxs-map'></i> <?php echo htmlspecialchars($report['barangay_name'] ?? $report['barangay']); ?></span>
                                                <span><i class='bx bxs-time'></i> <?php echo $time_ago; ?></span>
                                            </div>
                                            <div class="report-description">
                                                <?php echo htmlspecialchars(substr($report['description'], 0, 100)); ?>...
                                            </div>
                                        </div>
                                        <div>
                                            <span class="status-badge <?php echo $status_badge_class; ?>">
                                                <?php echo htmlspecialchars($report['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Emergency Alerts -->
                        <div class="card">
                            <h2 class="card-title">Common Hazards</h2>
                            <div class="alert-card">
                                <h3 class="alert-title">Fallen Trees</h3>
                                <p class="alert-time">After heavy rains or storms</p>
                                <button class="alert-button" onclick="setConditionType('Debris', 'Fallen tree blocking road')">
                                    <i class='bx bxs-tree button-icon'></i>
                                    Report This
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Flooding</h3>
                                <p class="alert-time">During heavy rainfall</p>
                                <button class="alert-button" onclick="setConditionType('Flooding', 'Road flooded, impassable')">
                                    <i class='bx bxs-droplet button-icon'></i>
                                    Report This
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Potholes</h3>
                                <p class="alert-time">Road surface damage</p>
                                <button class="alert-button" onclick="setConditionType('Pothole', 'Large pothole causing damage')">
                                    <i class='bx bxs-error button-icon'></i>
                                    Report This
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Camera Button -->
    <div class="camera-btn" onclick="openCamera()">
        <i class='bx bxs-camera' style="font-size: 24px;"></i>
    </div>
    
    <!-- Camera Modal -->
    <div id="cameraModal" class="camera-modal">
        <div class="camera-container">
            <video id="camera-preview" autoplay playsinline></video>
            <div class="camera-controls">
                <button type="button" class="camera-btn-action btn-capture" onclick="capturePhoto()">
                    <i class='bx bxs-camera'></i> Capture
                </button>
                <button type="button" class="camera-btn-action btn-cancel" onclick="closeCamera()">
                    <i class='bx bx-x'></i> Cancel
                </button>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu?.previousElementSibling?.querySelector('.dropdown-arrow');
            
            if (submenu && arrow) {
                submenu.classList.toggle('active');
                arrow.classList.toggle('rotated');
            }
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
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        });
        
        // Load saved theme
        const savedTheme = localStorage.getItem('theme');
        if (savedTheme === 'dark') {
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
        
        // Character counter for description
        const description = document.getElementById('description');
        const charCount = document.getElementById('charCount');
        
        description.addEventListener('input', function() {
            const length = this.value.length;
            charCount.textContent = `${length}/500 characters`;
            
            if (length > 500) {
                charCount.style.color = '#ef4444';
                this.style.borderColor = '#ef4444';
            } else if (length > 400) {
                charCount.style.color = '#f59e0b';
                this.style.borderColor = '#f59e0b';
            } else {
                charCount.style.color = 'var(--text-light)';
                this.style.borderColor = 'var(--border-color)';
            }
        });
        
        // Image preview
        function previewImage(event) {
            const input = event.target;
            const preview = document.getElementById('previewImg');
            const previewContainer = document.getElementById('imagePreview');
            
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    preview.src = e.target.result;
                    previewContainer.style.display = 'block';
                }
                
                reader.readAsDataURL(input.files[0]);
            }
        }
        
        function removeImage() {
            document.getElementById('incident_image').value = '';
            document.getElementById('imagePreview').style.display = 'none';
            document.getElementById('previewImg').src = '#';
        }
        
        // Camera functionality
        let cameraStream = null;
        
        function openCamera() {
            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('camera-preview');
            
            modal.style.display = 'flex';
            
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: 'environment',
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            })
            .then(stream => {
                cameraStream = stream;
                video.srcObject = stream;
            })
            .catch(err => {
                console.error("Camera error: ", err);
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access camera. Please check permissions.'
                });
                closeCamera();
            });
        }
        
        function closeCamera() {
            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('camera-preview');
            
            if (cameraStream) {
                cameraStream.getTracks().forEach(track => track.stop());
                cameraStream = null;
            }
            
            if (video.srcObject) {
                video.srcObject = null;
            }
            
            modal.style.display = 'none';
        }
        
        function capturePhoto() {
            const video = document.getElementById('camera-preview');
            const canvas = document.createElement('canvas');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            canvas.toBlob(blob => {
                const file = new File([blob], `camera_${Date.now()}.jpg`, { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);
                
                document.getElementById('incident_image').files = dataTransfer.files;
                
                // Trigger preview
                const event = new Event('change', { bubbles: true });
                document.getElementById('incident_image').dispatchEvent(event);
                
                closeCamera();
                
                Swal.fire({
                    icon: 'success',
                    title: 'Photo Captured!',
                    text: 'Image has been attached to your report.',
                    timer: 2000,
                    showConfirmButton: false
                });
            }, 'image/jpeg', 0.8);
        }
        
        // Use current location
        function useCurrentLocation() {
            if (navigator.geolocation) {
                Swal.fire({
                    title: 'Getting Location...',
                    text: 'Please wait while we get your current location.',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                navigator.geolocation.getCurrentPosition(
                    position => {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        // Reverse geocoding - in a real app, you would use a geocoding service
                        const locationText = `Lat: ${lat.toFixed(6)}, Lng: ${lng.toFixed(6)}`;
                        document.getElementById('location').value = locationText;
                        
                        Swal.close();
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Location Captured!',
                            text: 'Your current location has been filled in.',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    },
                    error => {
                        Swal.close();
                        
                        let errorMessage = 'Unable to get your location.';
                        switch(error.code) {
                            case error.PERMISSION_DENIED:
                                errorMessage = 'Location permission denied. Please enable location services.';
                                break;
                            case error.POSITION_UNAVAILABLE:
                                errorMessage = 'Location information unavailable.';
                                break;
                            case error.TIMEOUT:
                                errorMessage = 'Location request timed out.';
                                break;
                        }
                        
                        Swal.fire({
                            icon: 'error',
                            title: 'Location Error',
                            text: errorMessage
                        });
                    },
                    { 
                        enableHighAccuracy: true, 
                        timeout: 10000, 
                        maximumAge: 0 
                    }
                );
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Supported',
                    text: 'Geolocation is not supported by your browser.'
                });
            }
        }
        
        // Open map for location selection
        function openMap() {
            Swal.fire({
                title: 'Select Location',
                html: `
                    <div style="text-align: center;">
                        <p>Enter address or landmark:</p>
                        <input type="text" id="mapLocation" class="swal2-input" placeholder="e.g., Main Street near Market">
                        <div style="margin-top: 15px; background-color: #f3f4f6; padding: 10px; border-radius: 8px;">
                            <p style="margin: 0; font-size: 14px; color: #6b7280;">Or click quick locations:</p>
                            <div style="display: flex; gap: 10px; margin-top: 10px; justify-content: center;">
                                <button type="button" onclick="setQuickLocation('Market Area')" class="swal2-confirm swal2-styled" style="padding: 5px 10px; font-size: 12px;">Market</button>
                                <button type="button" onclick="setQuickLocation('School Zone')" class="swal2-confirm swal2-styled" style="padding: 5px 10px; font-size: 12px;">School</button>
                                <button type="button" onclick="setQuickLocation('Barangay Hall')" class="swal2-confirm swal2-styled" style="padding: 5px 10px; font-size: 12px;">Barangay Hall</button>
                            </div>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Use Location',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    const location = document.getElementById('mapLocation').value;
                    if (!location) {
                        Swal.showValidationMessage('Please enter a location');
                        return false;
                    }
                    return location;
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('location').value = result.value;
                }
            });
        }
        
        function setQuickLocation(location) {
            document.getElementById('mapLocation').value = location;
        }
        
        // Mark as urgent
        function markAsUrgent() {
            document.getElementById('urgent_hazard').checked = true;
            document.getElementById('severity').value = 'Emergency';
            
            Swal.fire({
                icon: 'warning',
                title: 'Marked as Urgent!',
                html: `
                    <div style="text-align: left;">
                        <p>This report will be marked as <strong>URGENT</strong> with <strong>EMERGENCY</strong> priority.</p>
                        <p>Administrators will be notified immediately.</p>
                    </div>
                `,
                timer: 3000,
                showConfirmButton: false
            });
        }
        
        // Set condition type from common hazards
        function setConditionType(type, description) {
            document.getElementById('condition_type').value = type;
            document.getElementById('description').value = description;
            document.getElementById('severity').value = 'High';
            
            Swal.fire({
                icon: 'success',
                title: 'Form Updated!',
                text: `Condition type set to "${type}"`,
                timer: 2000,
                showConfirmButton: false
            });
        }
        
        // Clear form
        function clearForm() {
            Swal.fire({
                title: 'Clear Form?',
                text: 'All entered data will be cleared.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, clear it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('reportForm').reset();
                    removeImage();
                    charCount.textContent = '0/500 characters';
                    charCount.style.color = 'var(--text-light)';
                    
                    Swal.fire(
                        'Cleared!',
                        'The form has been cleared.',
                        'success'
                    );
                }
            });
        }
        
        // Form submission with SweetAlert
        document.getElementById('reportForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const submitBtn = document.getElementById('submitBtn');
            const originalText = submitBtn.innerHTML;
            
            // Show loading
            submitBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Submitting...';
            submitBtn.disabled = true;
            
            // Validate form
            const requiredFields = ['location', 'barangay_id', 'road_type', 'condition_type', 'severity', 'description'];
            let isValid = true;
            let errorMessage = '';
            
            requiredFields.forEach(field => {
                const element = document.querySelector(`[name="${field}"]`);
                if (!element.value.trim()) {
                    isValid = false;
                    errorMessage = `Please fill in ${field.replace('_', ' ')}`;
                    element.focus();
                    return;
                }
            });
            
            // Check description length
            if (description.value.length > 500) {
                isValid = false;
                errorMessage = 'Description must be 500 characters or less';
                description.focus();
            }
            
            if (!isValid) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Missing Information',
                    text: errorMessage
                });
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                return;
            }
            
            // Check if urgent hazard is checked
            const urgentHazard = document.getElementById('urgent_hazard').checked;
            if (urgentHazard) {
                // Ask for confirmation for urgent hazards
                const { isConfirmed } = await Swal.fire({
                    icon: 'warning',
                    title: 'Urgent Hazard Alert',
                    html: `
                        <div style="text-align: left;">
                            <p>You are about to report an <strong style="color: #ef4444;">URGENT HAZARD</strong>.</p>
                            <p>This will:</p>
                            <ul>
                                <li>Set priority to EMERGENCY</li>
                                <li>Notify administrators immediately</li>
                                <li>Require immediate attention</li>
                            </ul>
                            <p>Are you sure this is an urgent hazard?</p>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Yes, report as urgent',
                    cancelButtonText: 'No, regular report',
                    confirmButtonColor: '#ef4444',
                    cancelButtonColor: '#6b7280'
                });
                
                if (isConfirmed) {
                    document.getElementById('severity').value = 'Emergency';
                } else {
                    document.getElementById('urgent_hazard').checked = false;
                }
            }
            
            // Show confirmation
            const { isConfirmed: confirmSubmit } = await Swal.fire({
                title: 'Submit Report?',
                html: `
                    <div style="text-align: left;">
                        <p><strong>Location:</strong> ${document.getElementById('location').value}</p>
                        <p><strong>Condition:</strong> ${document.getElementById('condition_type').value}</p>
                        <p><strong>Severity:</strong> ${document.getElementById('severity').value}</p>
                    </div>
                `,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, submit report',
                cancelButtonText: 'Review again'
            });
            
            if (!confirmSubmit) {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
                return;
            }
            
            // Submit form
            this.submit();
        });
        
        // Show alert messages
        window.addEventListener('load', function() {
            const successAlert = document.getElementById('successAlert');
            const errorAlert = document.getElementById('errorAlert');
            
            if (successAlert) {
                successAlert.style.display = 'block';
                setTimeout(() => {
                    successAlert.style.display = 'none';
                }, 5000);
            }
            
            if (errorAlert) {
                errorAlert.style.display = 'block';
                setTimeout(() => {
                    errorAlert.style.display = 'none';
                }, 5000);
            }
            
            // Auto-save form data to localStorage
            const form = document.getElementById('reportForm');
            const formFields = form.querySelectorAll('input, select, textarea');
            
            // Load saved data
            formFields.forEach(field => {
                if (field.type !== 'file') {
                    const savedValue = localStorage.getItem(`field_report_${field.name}`);
                    if (savedValue !== null) {
                        if (field.type === 'checkbox') {
                            field.checked = savedValue === 'true';
                        } else {
                            field.value = savedValue;
                        }
                    }
                }
                
                field.addEventListener('input', function() {
                    if (this.type !== 'file') {
                        const value = this.type === 'checkbox' ? this.checked : this.value;
                        localStorage.setItem(`field_report_${this.name}`, value);
                    }
                });
            });
            
            // Clear saved data on successful submit if there was a success message
            <?php if ($success_message): ?>
            formFields.forEach(field => {
                localStorage.removeItem(`field_report_${field.name}`);
            });
            <?php endif; ?>
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + Enter to submit form
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('reportForm').dispatchEvent(new Event('submit'));
            }
            
            // Escape to close camera
            if (e.key === 'Escape') {
                const modal = document.getElementById('cameraModal');
                if (modal.style.display === 'flex') {
                    closeCamera();
                }
            }
            
            // Ctrl + L to use current location
            if ((e.ctrlKey || e.metaKey) && e.key === 'l') {
                e.preventDefault();
                useCurrentLocation();
            }
            
            // Ctrl + C to open camera
            if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
                e.preventDefault();
                openCamera();
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

<?php
// Helper function to format time elapsed
function time_elapsed_string($datetime, $full = false) {
    $now = new DateTime;
    $ago = new DateTime($datetime);
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'just now';
}
?>