<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../config/db_connection.php';

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

// Check if user is staff/employee
if ($user['role'] !== 'EMPLOYEE' && $user['role'] !== 'ADMIN') {
    header('Location: ../index.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'status-tracking';

// Get barangay_id from user or session
$barangay_id = isset($_SESSION['barangay_id']) ? $_SESSION['barangay_id'] : 1; // Default to 1 if not set

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['submit_for_approval'])) {
        $route_id = $_POST['route_id'] ?? null;
        $submission_type = $_POST['submission_type'];
        $details = $_POST['details'] ?? '';
        
        // Generate submission code
        $submission_code = 'SUB-' . date('Ymd') . '-' . strtoupper(uniqid());
        
        try {
            $pdo->beginTransaction();
            
            // Insert submission record
            $sql = "INSERT INTO route_submissions 
                    (submission_code, route_id, submission_type, submitted_by, barangay_id, details, status) 
                    VALUES (:submission_code, :route_id, :submission_type, :submitted_by, :barangay_id, :details, 'Pending')";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'submission_code' => $submission_code,
                'route_id' => $route_id ?: null,
                'submission_type' => $submission_type,
                'submitted_by' => $user_id,
                'barangay_id' => $barangay_id,
                'details' => $details
            ]);
            
            $submission_id = $pdo->lastInsertId();
            
            // Update route status if route_id exists
            if ($route_id) {
                $update_sql = "UPDATE tricycle_routes 
                              SET submission_status = 'Submitted', 
                                  submission_id = :submission_id 
                              WHERE id = :route_id";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([
                    'submission_id' => $submission_id,
                    'route_id' => $route_id
                ]);
            }
            
            $pdo->commit();
            
            $_SESSION['success_message'] = "Submission sent for admin approval successfully!";
            header('Location: staff_status_tracking.php');
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error_message'] = "Error submitting for approval: " . $e->getMessage();
        }
    }
    
    // Handle file upload for document submission
    if (isset($_POST['submit_document']) && isset($_FILES['document_file'])) {
        $document_type = $_POST['document_type'];
        $document_title = $_POST['document_title'];
        $description = $_POST['document_description'] ?? '';
        
        // File upload handling
        $target_dir = "../uploads/documents/";
        if (!is_dir($target_dir)) {
            mkdir($target_dir, 0777, true);
        }
        
        $file_name = time() . '_' . basename($_FILES["document_file"]["name"]);
        $target_file = $target_dir . $file_name;
        $file_type = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));
        
        // Check file size (5MB max)
        if ($_FILES["document_file"]["size"] > 5000000) {
            $_SESSION['error_message'] = "File is too large. Maximum size is 5MB.";
        } elseif (in_array($file_type, ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'])) {
            if (move_uploaded_file($_FILES["document_file"]["tmp_name"], $target_file)) {
                // Generate submission code
                $submission_code = 'DOC-' . date('Ymd') . '-' . strtoupper(uniqid());
                
                // Insert document submission
                $sql = "INSERT INTO route_submissions 
                        (submission_code, submission_type, submitted_by, barangay_id, details, document_path, status) 
                        VALUES (:submission_code, :submission_type, :submitted_by, :barangay_id, :details, :document_path, 'Pending')";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'submission_code' => $submission_code,
                    'submission_type' => 'Document Upload',
                    'submitted_by' => $user_id,
                    'barangay_id' => $barangay_id,
                    'details' => json_encode([
                        'title' => $document_title,
                        'type' => $document_type,
                        'description' => $description
                    ]),
                    'document_path' => $target_file
                ]);
                
                $_SESSION['success_message'] = "Document submitted for approval successfully!";
                header('Location: staff_status_tracking.php');
                exit();
            } else {
                $_SESSION['error_message'] = "Error uploading file.";
            }
        } else {
            $_SESSION['error_message'] = "Invalid file type. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG.";
        }
    }
}

// Fetch data for display
try {
    // Fetch pending routes (Draft status)
    $sql_routes = "SELECT r.*, b.name as barangay_name 
                   FROM tricycle_routes r 
                   LEFT JOIN barangays b ON r.barangay_id = b.id 
                   WHERE r.barangay_id = :barangay_id 
                   AND r.submission_status IN ('Draft', 'Submitted')
                   ORDER BY r.created_at DESC";
    $stmt_routes = $pdo->prepare($sql_routes);
    $stmt_routes->execute(['barangay_id' => $barangay_id]);
    $routes = $stmt_routes->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch approved routes
    $sql_approved_routes = "SELECT r.*, b.name as barangay_name 
                           FROM tricycle_routes r 
                           LEFT JOIN barangays b ON r.barangay_id = b.id 
                           WHERE r.barangay_id = :barangay_id 
                           AND r.submission_status = 'Approved'
                           AND r.status = 'Active'
                           ORDER BY r.approved_date DESC";
    $stmt_approved = $pdo->prepare($sql_approved_routes);
    $stmt_approved->execute(['barangay_id' => $barangay_id]);
    $approved_routes = $stmt_approved->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch user submissions
    $sql_submissions = "SELECT s.*, 
                       u.first_name as reviewer_first, 
                       u.last_name as reviewer_last,
                       r.route_name,
                       r.route_code
                       FROM route_submissions s
                       LEFT JOIN users u ON s.reviewed_by = u.id
                       LEFT JOIN tricycle_routes r ON s.route_id = r.id
                       WHERE s.submitted_by = :user_id
                       AND s.barangay_id = :barangay_id
                       ORDER BY s.created_at DESC";
    $stmt_submissions = $pdo->prepare($sql_submissions);
    $stmt_submissions->execute(['user_id' => $user_id, 'barangay_id' => $barangay_id]);
    $submissions = $stmt_submissions->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch barangay data
    $sql_barangay = "SELECT * FROM barangays WHERE id = :barangay_id";
    $stmt_barangay = $pdo->prepare($sql_barangay);
    $stmt_barangay->execute(['barangay_id' => $barangay_id]);
    $barangay = $stmt_barangay->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $routes = [];
    $approved_routes = [];
    $submissions = [];
    $barangay = ['name' => 'Unknown Barangay'];
}

// Function to get status badge class
function getStatusBadge($status) {
    $classes = [
        'Pending' => 'status-pending',
        'Under Review' => 'status-progress',
        'Approved' => 'status-completed',
        'Rejected' => 'status-rejected',
        'Returned for Revision' => 'status-warning',
        'Draft' => 'status-draft',
        'Submitted' => 'status-progress',
        'Active' => 'status-completed',
        'Inactive' => 'status-inactive'
    ];
    
    return $classes[$status] ?? 'status-pending';
}

// Function to get status text
function getStatusText($status) {
    $texts = [
        'Pending' => 'Pending Review',
        'Under Review' => 'Under Review',
        'Approved' => 'Approved',
        'Rejected' => 'Rejected',
        'Returned for Revision' => 'Needs Revision',
        'Draft' => 'Draft',
        'Submitted' => 'Submitted',
        'Active' => 'Active',
        'Inactive' => 'Inactive'
    ];
    
    return $texts[$status] ?? $status;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Status Tracking - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
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
        
        /* Status Tracking Specific Styles */
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
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-title {
            font-size: 14px;
            font-weight: 500;
        }
        
        /* Card Styles */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: background-color 0.3s, border-color 0.3s;
            margin-bottom: 24px;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Status badge styles */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-pending {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-progress {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .status-progress {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .status-completed {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-completed {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .status-rejected {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .status-rejected {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .status-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-warning {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-draft {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .dark-mode .status-draft {
            background-color: rgba(107, 114, 128, 0.2);
            color: #d1d5db;
        }
        
        .status-inactive {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        .dark-mode .status-inactive {
            background-color: rgba(107, 114, 128, 0.2);
            color: #9ca3af;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background-color: var(--background-color);
            padding: 12px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .data-table .actions {
            display: flex;
            gap: 8px;
        }
        
        /* Button Styles */
        .btn {
            padding: 8px 16px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .btn-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--secondary-dark);
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
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-icon {
            width: 16px;
            height: 16px;
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
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-select {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            cursor: pointer;
        }
        
        .form-textarea {
            min-height: 100px;
            resize: vertical;
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
            padding: 32px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        .modal-close:hover {
            color: var(--text-color);
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
            gap: 4px;
        }
        
        .tab {
            padding: 12px 24px;
            cursor: pointer;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .tab:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .tab.active {
            border-bottom-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
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
            opacity: 0.5;
        }
        
        .text-muted {
            color: var(--text-light);
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
            
            .dashboard-content {
                padding: 16px;
            }
            
            .dashboard-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
            }
            
            .dashboard-actions {
                width: 100%;
                justify-content: space-between;
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
            
            .tabs {
                overflow-x: auto;
                flex-wrap: nowrap;
            }
            
            .tab {
                padding: 12px 16px;
                font-size: 14px;
            }
            
            .data-table {
                display: block;
            }
            
            .data-table thead {
                display: none;
            }
            
            .data-table tbody, .data-table tr, .data-table td {
                display: block;
                width: 100%;
            }
            
            .data-table tr {
                margin-bottom: 16px;
                border: 1px solid var(--border-color);
                border-radius: 8px;
                padding: 12px;
            }
            
            .data-table td {
                padding: 8px;
                border: none;
                position: relative;
                padding-left: 50%;
            }
            
            .data-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 12px;
                width: 45%;
                padding-right: 10px;
                font-weight: 600;
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
                    <img src="../img/ttm.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <span class="logo-text"> Traffic & Transport Management</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button -->
                    <a href="admin_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
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
                        <a href="lrcr/employee_create_report.php" class="submenu-item">Create Report</a>
                        <a href="lrcr/employee_road_status_monitoring.php" class="submenu-item">Action Assignment</a>
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
                    <div id="road-monitoring" class="submenu active">
                        <a href="staff_route_management.php" class="submenu-item">Route & Terminal Encoding</a>
                        <a href="staff_driver_association.php" class="submenu-item">Driver Association</a>
                        <a href="staff_document_upload.php" class="submenu-item">Document Upload</a>
                        <a href="staff_status_tracking.php" class="submenu-item active">Status Tracking</a>
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
                            <input type="text" placeholder="Search traffic data" class="search-input">
                            <kbd class="search-shortcut">🚦</kbd>
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
                <!-- Title and Actions -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Status Tracking / Forward to Admin</h1>
                        <p class="dashboard-subtitle">
                            <?php echo htmlspecialchars($barangay['name'] ?? 'Your Barangay'); ?> | 
                            Monitor route approval and implementation status
                        </p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="openSubmissionModal()">
                            <span style="font-size: 20px;">+</span>
                            Submit for Approval
                        </button>
                        <button class="secondary-button" onclick="openDocumentModal()">
                            <i class='bx bxs-file-plus'></i>
                            Upload Document
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-value"><?php echo count(array_filter($submissions, fn($s) => $s['status'] === 'Pending')); ?></div>
                        <div class="stat-title">Pending Submissions</div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-value"><?php echo count(array_filter($submissions, fn($s) => $s['status'] === 'Approved')); ?></div>
                        <div class="stat-title">Approved</div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-value"><?php echo count(array_filter($submissions, fn($s) => $s['status'] === 'Under Review')); ?></div>
                        <div class="stat-title">Under Review</div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-value"><?php echo count($approved_routes); ?></div>
                        <div class="stat-title">Active Routes</div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="tabs">
                    <div class="tab active" onclick="switchTab('my-submissions')">My Submissions</div>
                    <div class="tab" onclick="switchTab('pending-routes')">Pending Routes</div>
                    <div class="tab" onclick="switchTab('approved-routes')">Approved Routes</div>
                </div>
                
                <!-- My Submissions Tab -->
                <div id="my-submissions" class="tab-content active">
                    <div class="card">
                        <h2 class="card-title"><i class='bx bxs-paper-plane'></i> My Submissions</h2>
                        
                        <?php if (empty($submissions)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bxs-inbox'></i>
                                </div>
                                <h3>No Submissions Yet</h3>
                                <p>You haven't submitted any routes or documents for approval yet.</p>
                                <button class="btn btn-primary" onclick="openSubmissionModal()" style="margin-top: 16px;">
                                    Make Your First Submission
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Submission Code</th>
                                            <th>Type</th>
                                            <th>Route</th>
                                            <th>Date Submitted</th>
                                            <th>Status</th>
                                            <th>Review Date</th>
                                            <th>Reviewer</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($submissions as $submission): ?>
                                            <tr>
                                                <td data-label="Code"><?php echo htmlspecialchars($submission['submission_code']); ?></td>
                                                <td data-label="Type"><?php echo htmlspecialchars($submission['submission_type']); ?></td>
                                                <td data-label="Route">
                                                    <?php if ($submission['route_code']): ?>
                                                        <?php echo htmlspecialchars($submission['route_code'] . ' - ' . $submission['route_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Submitted"><?php echo date('M d, Y H:i', strtotime($submission['created_at'])); ?></td>
                                                <td data-label="Status">
                                                    <span class="status-badge <?php echo getStatusBadge($submission['status']); ?>">
                                                        <?php echo getStatusText($submission['status']); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Review Date">
                                                    <?php if ($submission['review_date']): ?>
                                                        <?php echo date('M d, Y', strtotime($submission['review_date'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Reviewer">
                                                    <?php if ($submission['reviewer_first']): ?>
                                                        <?php echo htmlspecialchars($submission['reviewer_first'] . ' ' . $submission['reviewer_last']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td data-label="Actions" class="actions">
                                                    <button class="btn btn-secondary btn-sm" 
                                                            onclick="viewSubmissionDetails(<?php echo htmlspecialchars(json_encode($submission)); ?>)">
                                                        <i class='bx bx-show btn-icon'></i> View
                                                    </button>
                                                    <?php if ($submission['status'] === 'Returned for Revision'): ?>
                                                        <button class="btn btn-primary btn-sm" 
                                                                onclick="resubmitSubmission(<?php echo $submission['id']; ?>)">
                                                            <i class='bx bx-edit btn-icon'></i> Resubmit
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Pending Routes Tab -->
                <div id="pending-routes" class="tab-content">
                    <div class="card">
                        <h2 class="card-title"><i class='bx bxs-time'></i> Pending Routes</h2>
                        
                        <?php if (empty($routes)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bxs-check-circle'></i>
                                </div>
                                <h3>No Pending Routes</h3>
                                <p>All routes are either approved or you haven't created any drafts yet.</p>
                                <button class="btn btn-primary" onclick="window.location.href='staff_route_management.php'" style="margin-top: 16px;">
                                    Create New Route
                                </button>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Route Code</th>
                                            <th>Route Name</th>
                                            <th>Start Point</th>
                                            <th>End Point</th>
                                            <th>Status</th>
                                            <th>Created Date</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($routes as $route): ?>
                                            <tr>
                                                <td data-label="Code"><?php echo htmlspecialchars($route['route_code']); ?></td>
                                                <td data-label="Name"><?php echo htmlspecialchars($route['route_name']); ?></td>
                                                <td data-label="Start"><?php echo htmlspecialchars($route['start_point']); ?></td>
                                                <td data-label="End"><?php echo htmlspecialchars($route['end_point']); ?></td>
                                                <td data-label="Status">
                                                    <span class="status-badge <?php echo getStatusBadge($route['submission_status']); ?>">
                                                        <?php echo getStatusText($route['submission_status']); ?>
                                                    </span>
                                                </td>
                                                <td data-label="Created"><?php echo date('M d, Y', strtotime($route['created_at'])); ?></td>
                                                <td data-label="Actions" class="actions">
                                                    <?php if ($route['submission_status'] === 'Draft'): ?>
                                                        <button class="btn btn-primary btn-sm" 
                                                                onclick="submitRouteForApproval(<?php echo $route['id']; ?>)">
                                                            <i class='bx bxs-send btn-icon'></i> Submit
                                                        </button>
                                                        <button class="btn btn-secondary btn-sm" 
                                                                onclick="window.location.href='staff_route_management.php?edit=<?php echo $route['id']; ?>'">
                                                            <i class='bx bx-edit btn-icon'></i> Edit
                                                        </button>
                                                    <?php elseif ($route['submission_status'] === 'Submitted'): ?>
                                                        <span class="text-muted">Awaiting Review</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Approved Routes Tab -->
                <div id="approved-routes" class="tab-content">
                    <div class="card">
                        <h2 class="card-title"><i class='bx bxs-check-circle'></i> Approved Routes</h2>
                        
                        <?php if (empty($approved_routes)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class='bx bxs-map'></i>
                                </div>
                                <h3>No Approved Routes</h3>
                                <p>No routes have been approved yet. Submit routes for approval to get started.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-container">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Route Code</th>
                                            <th>Route Name</th>
                                            <th>Start Point</th>
                                            <th>End Point</th>
                                            <th>Distance</th>
                                            <th>Fare</th>
                                            <th>Operating Hours</th>
                                            <th>Approval Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($approved_routes as $route): ?>
                                            <tr>
                                                <td data-label="Code"><?php echo htmlspecialchars($route['route_code']); ?></td>
                                                <td data-label="Name"><?php echo htmlspecialchars($route['route_name']); ?></td>
                                                <td data-label="Start"><?php echo htmlspecialchars($route['start_point']); ?></td>
                                                <td data-label="End"><?php echo htmlspecialchars($route['end_point']); ?></td>
                                                <td data-label="Distance"><?php echo htmlspecialchars($route['distance_km'] ?? 'N/A'); ?> km</td>
                                                <td data-label="Fare">₱<?php echo htmlspecialchars($route['fare_regular'] ?? '0.00'); ?></td>
                                                <td data-label="Hours">
                                                    <?php echo date('g:i A', strtotime($route['operating_hours_start'])) . ' - ' . 
                                                           date('g:i A', strtotime($route['operating_hours_end'])); ?>
                                                </td>
                                                <td data-label="Approved">
                                                    <?php if ($route['approved_date']): ?>
                                                        <?php echo date('M d, Y', strtotime($route['approved_date'])); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">--</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Submission Modal -->
    <div id="submissionModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Submit for Admin Approval</h2>
                <button class="modal-close" onclick="closeSubmissionModal()">&times;</button>
            </div>
            <form id="submissionForm" method="POST" action="">
                <div class="form-group">
                    <label class="form-label" for="submission_type">Submission Type</label>
                    <select class="form-select" name="submission_type" id="submission_type" required onchange="updateRouteSelection()">
                        <option value="">Select Type</option>
                        <option value="New Route">New Route Submission</option>
                        <option value="Route Update">Route Update</option>
                        <option value="Terminal Update">Terminal Update</option>
                        <option value="Restriction Update">Restriction Update</option>
                        <option value="Document Upload">Document Upload</option>
                    </select>
                </div>
                
                <div class="form-group" id="routeSelectionGroup">
                    <label class="form-label" for="route_id">Select Route (Optional)</label>
                    <select class="form-select" name="route_id" id="route_id">
                        <option value="">-- Select Route --</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?php echo $route['id']; ?>">
                                <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="details">Details / Description</label>
                    <textarea class="form-control form-textarea" name="details" id="details" 
                              placeholder="Provide details about this submission..." required></textarea>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="submit_for_approval" class="btn btn-primary" style="width: 100%;">
                        <i class='bx bxs-send'></i> Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Document Upload Modal -->
    <div id="documentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Upload Document for Approval</h2>
                <button class="modal-close" onclick="closeDocumentModal()">&times;</button>
            </div>
            <form id="documentForm" method="POST" action="" enctype="multipart/form-data">
                <div class="form-group">
                    <label class="form-label" for="document_type">Document Type</label>
                    <select class="form-select" name="document_type" id="document_type" required>
                        <option value="">Select Type</option>
                        <option value="Route Map">Route Map</option>
                        <option value="Resolution">Barangay Resolution</option>
                        <option value="Franchise">Franchise Application</option>
                        <option value="License">Driver License</option>
                        <option value="Certificate">Certificate</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="document_title">Document Title</label>
                    <input type="text" class="form-control" name="document_title" id="document_title" 
                           placeholder="Enter document title" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="document_description">Description</label>
                    <textarea class="form-control form-textarea" name="document_description" id="document_description" 
                              placeholder="Describe the document..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="document_file">Upload File</label>
                    <input type="file" class="form-control" name="document_file" id="document_file" 
                           accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" required>
                    <small style="color: var(--text-light);">Max file size: 5MB. Allowed: PDF, DOC, DOCX, JPG, JPEG, PNG</small>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="submit_document" class="btn btn-primary" style="width: 100%;">
                        <i class='bx bxs-upload'></i> Upload & Submit
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Details Modal -->
    <div id="detailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Submission Details</h2>
                <button class="modal-close" onclick="closeDetailsModal()">&times;</button>
            </div>
            <div id="detailsContent"></div>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Modal functions
        function openSubmissionModal() {
            document.getElementById('submissionModal').style.display = 'flex';
        }
        
        function closeSubmissionModal() {
            document.getElementById('submissionModal').style.display = 'none';
        }
        
        function openDocumentModal() {
            document.getElementById('documentModal').style.display = 'flex';
        }
        
        function closeDocumentModal() {
            document.getElementById('documentModal').style.display = 'none';
        }
        
        function openDetailsModal() {
            document.getElementById('detailsModal').style.display = 'flex';
        }
        
        function closeDetailsModal() {
            document.getElementById('detailsModal').style.display = 'none';
        }
        
        // Update route selection based on submission type
        function updateRouteSelection() {
            const submissionType = document.getElementById('submission_type').value;
            const routeGroup = document.getElementById('routeSelectionGroup');
            
            if (submissionType === 'New Route') {
                routeGroup.style.display = 'none';
                document.getElementById('route_id').required = false;
            } else {
                routeGroup.style.display = 'block';
                document.getElementById('route_id').required = true;
            }
        }
        
        // Submit route for approval
        function submitRouteForApproval(routeId) {
            Swal.fire({
                title: 'Submit for Approval?',
                text: 'Are you sure you want to submit this route for admin approval?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#10b981',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, Submit',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create a form and submit
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const routeIdInput = document.createElement('input');
                    routeIdInput.type = 'hidden';
                    routeIdInput.name = 'route_id';
                    routeIdInput.value = routeId;
                    
                    const typeInput = document.createElement('input');
                    typeInput.type = 'hidden';
                    typeInput.name = 'submission_type';
                    typeInput.value = 'New Route';
                    
                    const detailsInput = document.createElement('input');
                    detailsInput.type = 'hidden';
                    detailsInput.name = 'details';
                    detailsInput.value = 'Route submission for approval';
                    
                    const submitInput = document.createElement('input');
                    submitInput.type = 'hidden';
                    submitInput.name = 'submit_for_approval';
                    submitInput.value = '1';
                    
                    form.appendChild(routeIdInput);
                    form.appendChild(typeInput);
                    form.appendChild(detailsInput);
                    form.appendChild(submitInput);
                    
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // View submission details
        function viewSubmissionDetails(submission) {
            let detailsHtml = `
                <div style="margin-bottom: 20px;">
                    <h3 style="color: var(--primary-color); margin-bottom: 10px;">${submission.submission_code}</h3>
                    <p><strong>Type:</strong> ${submission.submission_type}</p>
                    <p><strong>Status:</strong> <span class="status-badge ${getStatusBadgeClass(submission.status)}">${getStatusText(submission.status)}</span></p>
                    <p><strong>Submitted:</strong> ${new Date(submission.created_at).toLocaleString()}</p>
            `;
            
            if (submission.route_code) {
                detailsHtml += `<p><strong>Route:</strong> ${submission.route_code} - ${submission.route_name}</p>`;
            }
            
            if (submission.details) {
                try {
                    const detailsObj = JSON.parse(submission.details);
                    if (typeof detailsObj === 'object') {
                        detailsHtml += `<p><strong>Title:</strong> ${detailsObj.title || 'N/A'}</p>`;
                        detailsHtml += `<p><strong>Description:</strong> ${detailsObj.description || 'N/A'}</p>`;
                    } else {
                        detailsHtml += `<p><strong>Details:</strong> ${submission.details}</p>`;
                    }
                } catch (e) {
                    detailsHtml += `<p><strong>Details:</strong> ${submission.details}</p>`;
                }
            }
            
            if (submission.review_notes) {
                detailsHtml += `<p><strong>Review Notes:</strong> ${submission.review_notes}</p>`;
            }
            
            if (submission.reviewer_first) {
                detailsHtml += `<p><strong>Reviewed By:</strong> ${submission.reviewer_first} ${submission.reviewer_last}</p>`;
                detailsHtml += `<p><strong>Review Date:</strong> ${new Date(submission.review_date).toLocaleDateString()}</p>`;
            }
            
            detailsHtml += `</div>`;
            
            document.getElementById('detailsContent').innerHTML = detailsHtml;
            openDetailsModal();
        }
        
        // Helper functions for status
        function getStatusBadgeClass(status) {
            const classes = {
                'Pending': 'status-pending',
                'Under Review': 'status-progress',
                'Approved': 'status-completed',
                'Rejected': 'status-rejected',
                'Returned for Revision': 'status-warning',
                'Draft': 'status-draft',
                'Submitted': 'status-progress'
            };
            return classes[status] || 'status-pending';
        }
        
        function getStatusText(status) {
            const texts = {
                'Pending': 'Pending Review',
                'Under Review': 'Under Review',
                'Approved': 'Approved',
                'Rejected': 'Rejected',
                'Returned for Revision': 'Needs Revision',
                'Draft': 'Draft',
                'Submitted': 'Submitted'
            };
            return texts[status] || status;
        }
        
        // Show success/error messages
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success_message']; ?>',
                timer: 3000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo $_SESSION['error_message']; ?>'
            });
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        }
        
        // Initialize dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        if (themeToggle) {
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
        }
        
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
        
        // Set active submenu on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Activate the road-monitoring submenu
            const roadMonitoringMenu = document.querySelector('.menu-item[onclick*="road-monitoring"]');
            const roadMonitoringSubmenu = document.getElementById('road-monitoring');
            if (roadMonitoringMenu && roadMonitoringSubmenu) {
                roadMonitoringSubmenu.classList.add('active');
                const arrow = roadMonitoringMenu.querySelector('.dropdown-arrow');
                if (arrow) arrow.classList.add('rotated');
            }
        });
    </script>
</body>
</html>     