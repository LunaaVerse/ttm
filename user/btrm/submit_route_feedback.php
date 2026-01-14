<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../index.php');
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
    header('Location: ../../index.php');
    exit();
}

$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Get barangays for dropdown
$barangays = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM barangays ORDER BY name");
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load barangays: " . $e->getMessage();
}

// Get routes for dropdown
$routes = [];
try {
    $stmt = $pdo->query("SELECT id, route_name FROM tricycle_routes WHERE status = 'Active' ORDER BY route_name");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load routes: " . $e->getMessage();
}

// Handle form submission
$success = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate and sanitize input
    $feedback_type = $_POST['feedback_type'] ?? '';
    $sub_type = $_POST['sub_type'] ?? '';
    $barangay_id = $_POST['barangay_id'] ?? '';
    $route_id = $_POST['route_id'] ?? '';
    $driver_id = $_POST['driver_id'] ?? '';
    $vehicle_number = trim($_POST['vehicle_number'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $user_contact = trim($_POST['user_contact'] ?? $user['contact']);
    
    // Validate required fields
    if (empty($feedback_type)) {
        $errors[] = "Please select feedback type";
    }
    if (empty($barangay_id)) {
        $errors[] = "Please select barangay";
    }
    if (empty($subject)) {
        $errors[] = "Please enter subject";
    }
    if (empty($description)) {
        $errors[] = "Please enter description";
    }
    
    // If no errors, insert into database
    if (empty($errors)) {
        try {
            // Generate unique feedback code
            $feedback_code = 'RFC-' . date('Y') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
            
            // Handle file upload (evidence)
            $evidence_path = null;
            if (isset($_FILES['evidence']) && $_FILES['evidence']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../uploads/route_feedback/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['evidence']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'mp4', 'avi', 'mov'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $filename = uniqid('evidence_', true) . '.' . $file_extension;
                    $target_path = $upload_dir . $filename;
                    
                    if (move_uploaded_file($_FILES['evidence']['tmp_name'], $target_path)) {
                        $evidence_path = 'uploads/route_feedback/' . $filename;
                    }
                }
            }
            
            // Insert feedback/complaint
            $sql = "INSERT INTO route_feedback_complaints 
                    (feedback_code, user_id, user_name, user_contact, feedback_type, sub_type, 
                     route_id, driver_id, operator_id, vehicle_number, barangay_id, location, 
                     subject, description, evidence_path, status, priority, reported_date) 
                    VALUES 
                    (:feedback_code, :user_id, :user_name, :user_contact, :feedback_type, :sub_type,
                     :route_id, :driver_id, :operator_id, :vehicle_number, :barangay_id, :location,
                     :subject, :description, :evidence_path, 'pending', :priority, NOW())";
            
            $stmt = $pdo->prepare($sql);
            
            // Get operator_id from driver_id if provided
            $operator_id = null;
            if (!empty($driver_id)) {
                $driver_stmt = $pdo->prepare("SELECT operator_id FROM tricycle_drivers WHERE id = :driver_id");
                $driver_stmt->execute(['driver_id' => $driver_id]);
                $driver = $driver_stmt->fetch(PDO::FETCH_ASSOC);
                $operator_id = $driver['operator_id'] ?? null;
            }
            
            // Set priority based on feedback type
            $priority = 'medium';
            if ($sub_type === 'reckless_driving' || $sub_type === 'safety_issue') {
                $priority = 'high';
            } elseif ($feedback_type === 'complaint') {
                $priority = 'medium';
            } else {
                $priority = 'low';
            }
            
            $stmt->execute([
                'feedback_code' => $feedback_code,
                'user_id' => $user_id,
                'user_name' => $full_name,
                'user_contact' => $user_contact,
                'feedback_type' => $feedback_type,
                'sub_type' => $sub_type,
                'route_id' => $route_id ?: null,
                'driver_id' => $driver_id ?: null,
                'operator_id' => $operator_id,
                'vehicle_number' => $vehicle_number ?: null,
                'barangay_id' => $barangay_id,
                'location' => $location ?: null,
                'subject' => $subject,
                'description' => $description,
                'evidence_path' => $evidence_path,
                'priority' => $priority
            ]);
            
            // Send notification to admins (you can implement email or SMS notification here)
            $success = true;
            
            // Store success message in session for SweetAlert
            $_SESSION['success_message'] = "Your feedback/complaint has been submitted successfully! Reference: " . $feedback_code;
            
            // Redirect to prevent form resubmission
            header('Location: submit_route_feedback.php?success=1');
            exit();
            
        } catch (PDOException $e) {
            $errors[] = "Failed to submit feedback: " . $e->getMessage();
        }
    }
}

// Check for success parameter
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Route Feedback/Complaint | Barangay Portal</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* ALL ORIGINAL CSS FROM user_dashboard.php - COPIED HERE */
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
            --icon-route-info: #3b82f6;
            --icon-road-condition: #f59e0b;
            --icon-incident: #ef4444;
            --icon-feedback: #8b5cf6;
            --icon-permit: #10b981;
            --icon-emergency: #f97316;
            --icon-profile: #6366f1;
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
        
        .icon-box-route-info {
            background-color: rgba(59, 130, 246, 0.1);
            color: var(--icon-route-info);
        }
        
        .icon-box-road-condition {
            background-color: rgba(245, 158, 11, 0.1);
            color: var(--icon-road-condition);
        }
        
        .icon-box-incident {
            background-color: rgba(239, 68, 68, 0.1);
            color: var(--icon-incident);
        }
        
        .icon-box-feedback {
            background-color: rgba(139, 92, 246, 0.1);
            color: var(--icon-feedback);
        }
        
        .icon-box-permit {
            background-color: rgba(16, 185, 129, 0.1);
            color: var(--icon-permit);
        }
        
        .icon-box-emergency {
            background-color: rgba(249, 115, 22, 0.1);
            color: var(--icon-emergency);
        }
        
        .icon-box-profile {
            background-color: rgba(99, 102, 241, 0.1);
            color: var(--icon-profile);
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
        
        /* CUSTOM FORM STYLES FOR FEEDBACK PAGE */
        .form-container {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-section {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .form-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .form-subtitle {
            color: var(--text-light);
            margin-bottom: 30px;
            font-size: 14px;
        }
        
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
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-dark);
        }
        
        .btn-secondary {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .error-message {
            color: #ef4444;
            background-color: #fee2e2;
            border: 1px solid #fecaca;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .success-message {
            color: #166534;
            background-color: #dcfce7;
            border: 1px solid #bbf7d0;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .info-box {
            background-color: #dbeafe;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .dark-mode .info-box {
            background-color: rgba(59, 130, 246, 0.1);
            border-color: rgba(59, 130, 246, 0.3);
        }
        
        .info-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: #1e40af;
        }
        
        .dark-mode .info-title {
            color: #93c5fd;
        }
        
        .info-text {
            font-size: 14px;
            color: #374151;
        }
        
        .dark-mode .info-text {
            color: #d1d5db;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .form-container {
                padding: 10px;
            }
            
            .form-section {
                padding: 20px;
            }
            
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
                <span class="logo-text"> Barangay Resident Portal</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY SERVICES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button -->
                    <a href="../user_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Tricycle Route Information -->
                    <div class="menu-item" onclick="toggleSubmenu('route-info')">
                        <div class="icon-box icon-box-route-info">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Tricycle Route Information</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-info" class="submenu">
                        <a href="../user_routes/view_routes.php" class="submenu-item">View Routes</a>
                        <a href="../user_routes/schedule.php" class="submenu-item">Schedule Information</a>
                    </div>
                    
                    <!-- Report Road Condition -->
                    <div class="menu-item" onclick="toggleSubmenu('road-condition')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Report Road Condition</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-condition" class="submenu">
                        <a href="../user_road_condition/report_condition.php" class="submenu-item">Submit Report</a>
                        <a href="../user_road_condition/my_reports.php" class="submenu-item">My Reports</a>
                    </div>
                    
                    <!-- Report Incident -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-reporting')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Report Incident</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-reporting" class="submenu">
                        <a href="../user_incidents/report_incident.php" class="submenu-item">Submit Incident</a>
                        <a href="../user_incidents/my_reports.php" class="submenu-item">My Reports</a>
                        <a href="../user_incidents/emergency_contacts.php" class="submenu-item">Emergency Contacts</a>
                    </div>
                    
                    <!-- Community Feedback - ACTIVE -->
                    <div class="menu-item active">
                        <div class="icon-box icon-box-feedback">
                            <i class='bx bxs-chat'></i>
                        </div>
                        <span class="font-medium">Route Feedback & Complaints</span>
                    </div>
                    <div id="community-feedback" class="submenu active">
                        <a href="submit_route_feedback.php" class="submenu-item active">Submit Feedback/Complaint</a>
                        <a href="my_route_feedback.php" class="submenu-item">My Feedback</a>
                    </div>
                    
                    <!-- Check Permit Status -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-tracking')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit Information</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-tracking" class="submenu">
                        <a href="../user_permits/check_permit.php" class="submenu-item">Check Permit Status</a>
                        <a href="../user_permits/regulation_info.php" class="submenu-item">Regulation Information</a>
                    </div>
                    
                    <!-- Emergency Services -->
                    <div class="menu-item" onclick="toggleSubmenu('emergency-services')">
                        <div class="icon-box icon-box-emergency">
                            <i class='bx bxs-first-aid'></i>
                        </div>
                        <span class="font-medium">Emergency Services</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="emergency-services" class="submenu">
                        <a href="../user_emergency/contacts.php" class="submenu-item">Emergency Contacts</a>
                        <a href="../user_emergency/report.php" class="submenu-item">Emergency Report</a>
                    </div>
                </div>
                
                <!-- Separator -->
                <div class="menu-separator"></div>
                
                <!-- Bottom Menu Section -->
                <div class="menu-items">
                    <a href="../user_profile.php" class="menu-item">
                        <div class="icon-box icon-box-profile">
                            <i class='bx bxs-user-circle'></i>
                        </div>
                        <span class="font-medium">My Profile</span>
                    </a>
                    
                    <a href="../user_settings.php" class="menu-item">
                        <div class="icon-box icon-box-settings">
                            <i class='bx bxs-cog'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../user_help_support.php" class="menu-item">
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
                            <input type="text" placeholder="Search reports or services" class="search-input">
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
            
            <!-- Feedback Form Content -->
            <div class="dashboard-content">
                <div class="form-container">
                    <div class="form-section">
                        <h1 class="form-title">Submit Route Feedback or Complaint</h1>
                        <p class="form-subtitle">Report illegal routing, overcharging, safety issues, or provide suggestions for tricycle route management.</p>
                        
                        <!-- Information Box -->
                        <div class="info-box">
                            <div class="info-title">Important Information</div>
                            <div class="info-text">
                                • All feedback/complaints are reviewed by barangay administrators<br>
                                • Please provide accurate details for proper investigation<br>
                                • You will be notified about the status of your report<br>
                                • Emergency issues will be prioritized for immediate action
                            </div>
                        </div>
                        
                        <!-- Error Messages -->
                        <?php if (!empty($errors)): ?>
                            <div class="error-message">
                                <strong>Please fix the following errors:</strong>
                                <ul>
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Success Message -->
                        <?php if ($success): ?>
                            <div class="success-message">
                                <strong>Success!</strong> Your feedback/complaint has been submitted successfully. You will receive updates via email or SMS.
                            </div>
                        <?php endif; ?>
                        
                        <!-- Feedback Form -->
                        <form action="" method="POST" enctype="multipart/form-data">
                            <!-- Basic Information -->
                            <div class="form-group">
                                <label class="form-label" for="feedback_type">Feedback Type *</label>
                                <select class="form-control" id="feedback_type" name="feedback_type" required>
                                    <option value="">Select Type</option>
                                    <option value="complaint" <?php echo (isset($_POST['feedback_type']) && $_POST['feedback_type'] == 'complaint') ? 'selected' : ''; ?>>Complaint</option>
                                    <option value="suggestion" <?php echo (isset($_POST['feedback_type']) && $_POST['feedback_type'] == 'suggestion') ? 'selected' : ''; ?>>Suggestion</option>
                                    <option value="compliment" <?php echo (isset($_POST['feedback_type']) && $_POST['feedback_type'] == 'compliment') ? 'selected' : ''; ?>>Compliment</option>
                                    <option value="report_issue" <?php echo (isset($_POST['feedback_type']) && $_POST['feedback_type'] == 'report_issue') ? 'selected' : ''; ?>>Report Issue</option>
                                </select>
                            </div>
                            
                            <!-- Sub-Type (for complaints) -->
                            <div class="form-group" id="sub_type_group">
                                <label class="form-label" for="sub_type">Issue Type *</label>
                                <select class="form-control" id="sub_type" name="sub_type">
                                    <option value="">Select Issue Type</option>
                                    <option value="illegal_routing" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'illegal_routing') ? 'selected' : ''; ?>>Illegal Routing</option>
                                    <option value="overcharging" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'overcharging') ? 'selected' : ''; ?>>Overcharging Fare</option>
                                    <option value="safety_issue" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'safety_issue') ? 'selected' : ''; ?>>Safety Issue</option>
                                    <option value="reckless_driving" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'reckless_driving') ? 'selected' : ''; ?>>Reckless Driving</option>
                                    <option value="overloading" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'overloading') ? 'selected' : ''; ?>>Overloading Passengers</option>
                                    <option value="route_deviation" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'route_deviation') ? 'selected' : ''; ?>>Route Deviation</option>
                                    <option value="other" <?php echo (isset($_POST['sub_type']) && $_POST['sub_type'] == 'other') ? 'selected' : ''; ?>>Other Issue</option>
                                </select>
                            </div>
                            
                            <!-- Two Column Row -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="barangay_id">Barangay *</label>
                                    <select class="form-control" id="barangay_id" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>" 
                                                <?php echo (isset($_POST['barangay_id']) && $_POST['barangay_id'] == $barangay['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="route_id">Route (Optional)</label>
                                    <select class="form-control" id="route_id" name="route_id">
                                        <option value="">Select Route</option>
                                        <?php foreach ($routes as $route): ?>
                                            <option value="<?php echo $route['id']; ?>"
                                                <?php echo (isset($_POST['route_id']) && $_POST['route_id'] == $route['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($route['route_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Vehicle Information -->
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="vehicle_number">Vehicle Number (Optional)</label>
                                    <input type="text" class="form-control" id="vehicle_number" name="vehicle_number" 
                                           placeholder="e.g., TRC-123" 
                                           value="<?php echo isset($_POST['vehicle_number']) ? htmlspecialchars($_POST['vehicle_number']) : ''; ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="location">Location/Place of Incident (Optional)</label>
                                    <input type="text" class="form-control" id="location" name="location" 
                                           placeholder="e.g., Near Market, In front of School" 
                                           value="<?php echo isset($_POST['location']) ? htmlspecialchars($_POST['location']) : ''; ?>">
                                </div>
                            </div>
                            
                            <!-- Contact Information -->
                            <div class="form-group">
                                <label class="form-label" for="user_contact">Contact Number *</label>
                                <input type="text" class="form-control" id="user_contact" name="user_contact" 
                                       required placeholder="e.g., 09123456789" 
                                       value="<?php echo isset($_POST['user_contact']) ? htmlspecialchars($_POST['user_contact']) : htmlspecialchars($user['contact']); ?>">
                            </div>
                            
                            <!-- Subject and Description -->
                            <div class="form-group">
                                <label class="form-label" for="subject">Subject/Title *</label>
                                <input type="text" class="form-control" id="subject" name="subject" 
                                       required placeholder="Brief description of your feedback/complaint" 
                                       value="<?php echo isset($_POST['subject']) ? htmlspecialchars($_POST['subject']) : ''; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="description">Detailed Description *</label>
                                <textarea class="form-control form-textarea" id="description" name="description" 
                                          required placeholder="Please provide detailed information about the issue, including date, time, and specific details..."><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                            </div>
                            
                            <!-- Evidence Upload -->
                            <div class="form-group">
                                <label class="form-label" for="evidence">Evidence (Optional)</label>
                                <input type="file" class="form-control" id="evidence" name="evidence" 
                                       accept=".jpg,.jpeg,.png,.gif,.pdf,.mp4,.avi,.mov">
                                <small style="color: var(--text-light);">You can upload photos, videos, or documents (Max 10MB)</small>
                            </div>
                            
                            <!-- Form Footer -->
                            <div class="form-footer">
                                <button type="button" class="btn-secondary" onclick="window.location.href='../user_dashboard.php'">
                                    Cancel
                                </button>
                                <button type="submit" class="btn-primary">
                                    <i class='bx bxs-send'></i> Submit Feedback/Complaint
                                </button>
                            </div>
                        </form>
                    </div>
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
        
        // Show/hide sub-type based on feedback type
        const feedbackType = document.getElementById('feedback_type');
        const subTypeGroup = document.getElementById('sub_type_group');
        
        function updateSubTypeVisibility() {
            if (feedbackType.value === 'complaint' || feedbackType.value === 'report_issue') {
                subTypeGroup.style.display = 'block';
                document.getElementById('sub_type').setAttribute('required', 'required');
            } else {
                subTypeGroup.style.display = 'none';
                document.getElementById('sub_type').removeAttribute('required');
            }
        }
        
        // Initialize on page load
        updateSubTypeVisibility();
        
        // Update when feedback type changes
        feedbackType.addEventListener('change', updateSubTypeVisibility);
        
        // Show success message with SweetAlert
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Submitted Successfully!',
                html: '<?php echo addslashes($_SESSION['success_message']); ?>',
                confirmButtonText: 'OK',
                confirmButtonColor: '#0d9488'
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const feedbackType = document.getElementById('feedback_type').value;
            const subType = document.getElementById('sub_type').value;
            
            if ((feedbackType === 'complaint' || feedbackType === 'report_issue') && !subType) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please select an issue type for your complaint.',
                    confirmButtonText: 'OK',
                    confirmButtonColor: '#ef4444'
                });
            }
        });
    </script>
</body>
</html>