<?php
// employee_create_report.php

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

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user = getUserData($pdo, $user_id);
} else {
    // Get first verified user as fallback
    $user = getUserData($pdo);
}

// If no user found, create a fallback user
if (!$user) {
    $user = [
        'first_name' => 'Employee',
        'middle_name' => '',
        'last_name' => 'User',
        'email' => 'employee@commonwealth.com',
        'role' => 'EMPLOYEE'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'create_report';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo->beginTransaction();
        
        // Handle file upload
        $image_path = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/road_reports/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file_ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $file_name = 'report_' . time() . '_' . uniqid() . '.' . $file_ext;
            $target_path = $upload_dir . $file_name;
            
            // Check file type
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_ext, $allowed_types)) {
                throw new Exception('Only JPG, PNG, and GIF files are allowed.');
            }
            
            // Check file size (max 5MB)
            if ($_FILES['image']['size'] > 5 * 1024 * 1024) {
                throw new Exception('File size must be less than 5MB.');
            }
            
            if (move_uploaded_file($_FILES['image']['tmp_name'], $target_path)) {
                $image_path = 'uploads/road_reports/' . $file_name;
            }
        }
        
        // Get reporter name
        $reporter_name = trim($user['first_name'] . ' ' . ($user['middle_name'] ? $user['middle_name'] . ' ' : '') . $user['last_name']);
        
        // Insert report into database
        $stmt = $pdo->prepare("
            INSERT INTO road_condition_reports (
                reporter_id, reporter_name, reporter_role, report_date, 
                location, barangay, road_type, condition_type, severity, 
                description, image_path, status, priority, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        $stmt->execute([
            $user['id'],
            $reporter_name,
            'EMPLOYEE', // Always set as EMPLOYEE for this module
            $_POST['report_date'],
            $_POST['location'],
            $_POST['barangay'],
            $_POST['road_type'],
            $_POST['condition_type'],
            $_POST['severity'],
            $_POST['description'],
            $image_path,
            'Pending',
            $_POST['priority']
        ]);
        
        $report_id = $pdo->lastInsertId();
        
        // Insert into assignment logs
        $stmt = $pdo->prepare("
            INSERT INTO assignment_logs (report_id, assigned_by, assigned_to, assignment_date, notes)
            VALUES (?, ?, ?, NOW(), ?)
        ");
        $stmt->execute([$report_id, $user['id'], $user['id'], 'Initial report created by employee']);
        
        $pdo->commit();
        
        $success_message = "Report submitted successfully! Reference ID: #" . str_pad($report_id, 6, '0', STR_PAD_LEFT);
        
        // Store success message in session for SweetAlert
        $_SESSION['success_message'] = $success_message;
        
        // Clear form data after successful submission
        $_POST = array();
        
        // Redirect to avoid form resubmission
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

$sql = "SELECT id, name FROM barangays ORDER BY name";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Road Condition Report - Traffic & Transport Management</title>
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
        
        /* Form Styles */
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            transition: background-color 0.3s, border-color 0.3s;
        }
        
        .form-section {
            margin-bottom: 32px;
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
            color: var(--primary-color);
            padding-bottom: 12px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: span 2;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-label.required::after {
            content: " *";
            color: #ef4444;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s, background-color 0.3s;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
            font-family: 'Inter', sans-serif;
        }
        
        /* File Upload Styles */
        .file-upload-container {
            border: 2px dashed var(--border-color);
            border-radius: 12px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s, background-color 0.3s;
        }
        
        .file-upload-container:hover {
            border-color: var(--primary-color);
            background-color: rgba(13, 148, 136, 0.05);
        }
        
        .file-upload-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto 16px;
            color: var(--text-light);
        }
        
        .file-upload-text {
            margin-bottom: 8px;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .file-upload-subtext {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 16px;
        }
        
        .file-input {
            display: none;
        }
        
        .file-preview {
            margin-top: 20px;
            display: none;
        }
        
        .file-preview.active {
            display: block;
        }
        
        .preview-image {
            max-width: 300px;
            max-height: 200px;
            border-radius: 8px;
            margin: 0 auto;
            display: block;
        }
        
        /* Severity Indicators */
        .severity-options {
            display: flex;
            gap: 12px;
        }
        
        .severity-option {
            flex: 1;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .severity-option:hover {
            border-color: var(--primary-color);
        }
        
        .severity-option.selected {
            border-color: var(--primary-color);
            background-color: rgba(13, 148, 136, 0.1);
        }
        
        .severity-label {
            font-weight: 500;
            margin-bottom: 4px;
        }
        
        .severity-description {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .severity-low.selected {
            border-color: #10b981;
            background-color: rgba(16, 185, 129, 0.1);
        }
        
        .severity-medium.selected {
            border-color: #f59e0b;
            background-color: rgba(245, 158, 11, 0.1);
        }
        
        .severity-high.selected {
            border-color: #ef4444;
            background-color: rgba(239, 68, 68, 0.1);
        }
        
        .severity-emergency.selected {
            border-color: #dc2626;
            background-color: rgba(220, 38, 38, 0.1);
        }
        
        /* Form Actions */
        .form-actions {
            display: flex;
            gap: 16px;
            justify-content: flex-end;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
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
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Alert Messages */
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .dark-mode .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        
        .dark-mode .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
        
        .alert-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
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
            
            .dashboard-content {
                padding: 16px;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .form-group.full-width {
                grid-column: span 1;
            }
            
            .severity-options {
                flex-direction: column;
            }
            
            .form-actions {
                flex-direction: column;
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
                justify-content: center;
            }
            
            .time-display {
                min-width: 140px;
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
                        <a href="employee_create_report.php" class="submenu-item active">Create Report</a>
                        <a href="employee_road_status_monitoring.php" class="submenu-item">Action Assignment</a>
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
                            <input type="text" placeholder="Search reports" class="search-input">
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Title -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Create Road Condition Report</h1>
                        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Encode reports received in person or by phone. Attach photos and descriptions.</p>
                    </div>
                
                </div>
                <!-- Quick Tips Section -->
                <div class="form-card">
                    <h2 class="form-title" style="font-size: 18px;">📋 Reporting Guidelines</h2>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 16px; margin-top: 16px;">
                        <div style="padding: 12px; background-color: rgba(13, 148, 136, 0.1); border-radius: 8px;">
                            <div style="font-weight: 600; margin-bottom: 4px;">📍 Location Accuracy</div>
                            <div style="font-size: 14px; color: var(--text-light);">
                                Provide specific landmarks or addresses for quick identification.
                            </div>
                        </div>
                        <div style="padding: 12px; background-color: rgba(245, 158, 11, 0.1); border-radius: 8px;">
                            <div style="font-weight: 600; margin-bottom: 4px;">📸 Photo Requirements</div>
                            <div style="font-size: 14px; color: var(--text-light);">
                                Clear, well-lit photos showing the issue and surrounding area.
                            </div>
                        </div>
                        <div style="padding: 12px; background-color: rgba(239, 68, 68, 0.1); border-radius: 8px;">
                            <div style="font-weight: 600; margin-bottom: 4px;">⚠️ Emergency Cases</div>
                            <div style="font-size: 14px; color: var(--text-light);">
                                For emergencies, also call the barangay office immediately.
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Form Container -->
                <div class="form-container">
                    <form action="" method="POST" enctype="multipart/form-data" id="reportForm">
                        <div class="form-card">
                            <!-- Basic Information Section -->
                            <div class="form-section">
                                <h2 class="form-title">Basic Information</h2>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">Report Date</label>
                                        <input type="date" name="report_date" class="form-input" 
                                               value="<?php echo isset($_POST['report_date']) ? htmlspecialchars($_POST['report_date']) : date('Y-m-d'); ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Barangay</label>
                                        <select name="barangay" class="form-select" required>
                                            <option value="">Select Barangay</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo htmlspecialchars($barangay); ?>" 
                                                    <?php echo (isset($_POST['barangay']) && $_POST['barangay'] == $barangay) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($barangay); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Location / Landmark</label>
                                        <input type="text" name="location" class="form-input" 
                                               placeholder="e.g., 123 Main Street near Market" 
                                               value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Road Type</label>
                                        <select name="road_type" class="form-select" required>
                                            <option value="">Select Road Type</option>
                                            <option value="Major Road" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Major Road') ? 'selected' : ''; ?>>Major Road</option>
                                            <option value="Minor Road" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Minor Road') ? 'selected' : ''; ?>>Minor Road</option>
                                            <option value="Alley" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Alley') ? 'selected' : ''; ?>>Alley</option>
                                            <option value="Bridge" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Bridge') ? 'selected' : ''; ?>>Bridge</option>
                                            <option value="Other" <?php echo (isset($_POST['road_type']) && $_POST['road_type'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Condition Details Section -->
                            <div class="form-section">
                                <h2 class="form-title">Condition Details</h2>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label required">Condition Type</label>
                                        <select name="condition_type" class="form-select" required>
                                            <option value="">Select Condition Type</option>
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
                                    
                                    <div class="form-group">
                                        <label class="form-label required">Priority Level</label>
                                        <select name="priority" class="form-select" required>
                                            <option value="Low" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                                            <option value="Medium" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                            <option value="High" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                            <option value="Emergency" <?php echo (isset($_POST['priority']) && $_POST['priority'] == 'Emergency') ? 'selected' : ''; ?>>Emergency</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label required">Description</label>
                                        <textarea name="description" class="form-textarea" 
                                                  placeholder="Provide detailed description of the road condition issue..."
                                                  required><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                                        <small style="color: var(--text-light); margin-top: 8px; display: block;">
                                            Include details like size, depth, affected area, and any safety concerns.
                                        </small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Severity Assessment -->
                            <div class="form-section">
                                <h2 class="form-title">Severity Assessment</h2>
                                <div class="severity-options" id="severityOptions">
                                    <div class="severity-option severity-low" data-value="Low">
                                        <div class="severity-label">Low</div>
                                        <div class="severity-description">Minor issue, no immediate danger</div>
                                    </div>
                                    <div class="severity-option severity-medium" data-value="Medium">
                                        <div class="severity-label">Medium</div>
                                        <div class="severity-description">Moderate risk, needs attention</div>
                                    </div>
                                    <div class="severity-option severity-high" data-value="High">
                                        <div class="severity-label">High</div>
                                        <div class="severity-description">Serious issue, requires prompt action</div>
                                    </div>
                                    <div class="severity-option severity-emergency" data-value="Emergency">
                                        <div class="severity-label">Emergency</div>
                                        <div class="severity-description">Critical, immediate action needed</div>
                                    </div>
                                </div>
                                <input type="hidden" name="severity" id="severityInput" value="<?php echo isset($_POST['severity']) ? htmlspecialchars($_POST['severity']) : 'Medium'; ?>" required>
                            </div>
                            
                            <!-- Media Upload Section -->
                            <div class="form-section">
                                <h2 class="form-title">Media Upload</h2>
                                <div class="form-group full-width">
                                    <div class="file-upload-container" id="fileUploadContainer">
                                        <div class="file-upload-icon">
                                            <i class='bx bxs-cloud-upload' style="font-size: 64px;"></i>
                                        </div>
                                        <div class="file-upload-text">Upload Photo or Video</div>
                                        <div class="file-upload-subtext">
                                            Drag & drop or click to browse (Max 5MB, JPG, PNG, GIF)
                                        </div>
                                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('fileInput').click()">
                                            <i class='bx bx-upload'></i> Browse Files
                                        </button>
                                        <input type="file" id="fileInput" name="image" class="file-input" accept="image/*">
                                    </div>
                                    
                                    <div class="file-preview" id="filePreview">
                                        <img src="" alt="Preview" class="preview-image" id="previewImage">
                                    </div>
                                    
                                    <small style="color: var(--text-light); margin-top: 8px; display: block;">
                                        Note: Photos help assess the condition accurately. Video clips are also accepted.
                                    </small>
                                </div>
                            </div>
                            
                            <!-- Form Actions -->
                            <div class="form-actions">
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='employee_dashboard.php'">
                                    <i class='bx bx-x'></i> Cancel
                                </button>
                                <button type="submit" class="btn btn-primary" id="submitBtn">
                                    <i class='bx bx-check'></i> Submit Report
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            
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
        
        // Severity Selection
        const severityOptions = document.querySelectorAll('.severity-option');
        const severityInput = document.getElementById('severityInput');
        
        // Set initial selected severity
        const initialSeverity = '<?php echo isset($_POST['severity']) ? $_POST['severity'] : "Medium"; ?>';
        document.querySelector(`.severity-option[data-value="${initialSeverity}"]`).classList.add('selected');
        
        severityOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Remove selected class from all options
                severityOptions.forEach(opt => opt.classList.remove('selected'));
                
                // Add selected class to clicked option
                this.classList.add('selected');
                
                // Update hidden input value
                severityInput.value = this.dataset.value;
            });
        });
        
        // File Upload Preview
        const fileInput = document.getElementById('fileInput');
        const filePreview = document.getElementById('filePreview');
        const previewImage = document.getElementById('previewImage');
        const fileUploadContainer = document.getElementById('fileUploadContainer');
        
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    filePreview.classList.add('active');
                };
                
                reader.readAsDataURL(file);
            } else {
                filePreview.classList.remove('active');
            }
        });
        
        // Drag and drop functionality
        fileUploadContainer.addEventListener('dragover', function(e) {
            e.preventDefault();
            this.style.borderColor = 'var(--primary-color)';
            this.style.backgroundColor = 'rgba(13, 148, 136, 0.05)';
        });
        
        fileUploadContainer.addEventListener('dragleave', function(e) {
            e.preventDefault();
            this.style.borderColor = '';
            this.style.backgroundColor = '';
        });
        
        fileUploadContainer.addEventListener('drop', function(e) {
            e.preventDefault();
            this.style.borderColor = '';
            this.style.backgroundColor = '';
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                
                // Trigger change event
                const event = new Event('change');
                fileInput.dispatchEvent(event);
            }
        });
        
        // Auto-expand textarea
        const textarea = document.querySelector('textarea[name="description"]');
        textarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = (this.scrollHeight) + 'px';
        });
        
        // Initialize textarea height
        setTimeout(() => {
            textarea.style.height = 'auto';
            textarea.style.height = (textarea.scrollHeight) + 'px';
        }, 100);
        
        // Show SweetAlert on successful submission
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
        
        // Form validation with SweetAlert
        document.getElementById('reportForm').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('[required]');
            let valid = true;
            let firstInvalidField = null;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    valid = false;
                    field.style.borderColor = '#ef4444';
                    
                    if (!firstInvalidField) {
                        firstInvalidField = field;
                    }
                } else {
                    field.style.borderColor = '';
                }
            });
            
            if (!valid) {
                e.preventDefault();
                
                // Scroll to first error
                if (firstInvalidField) {
                    firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstInvalidField.focus();
                }
                
                Swal.fire({
                    icon: 'warning',
                    title: 'Required Fields Missing',
                    text: 'Please fill in all required fields marked with *',
                    showConfirmButton: true,
                    confirmButtonColor: '#f59e0b'
                });
                return false;
            }
            
            // Show loading SweetAlert
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bx bx-loader bx-spin"></i> Submitting...';
            
            // Show processing message
            let timerInterval;
            Swal.fire({
                title: 'Submitting Report',
                html: 'Please wait while we save your report...',
                timer: 2000,
                timerProgressBar: true,
                didOpen: () => {
                    Swal.showLoading();
                    timerInterval = setInterval(() => {
                        const content = Swal.getHtmlContainer();
                        if (content) {
                            const b = Swal.getTimerLeft();
                            content.querySelector('b').textContent = b;
                        }
                    }, 100);
                },
                willClose: () => {
                    clearInterval(timerInterval);
                }
            }).then((result) => {
                // Continue with form submission
                return true;
            });
        });
    </script>
</html>
</body>