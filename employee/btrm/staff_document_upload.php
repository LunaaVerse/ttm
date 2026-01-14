<?php
// staff_document_upload.php
session_start();
require_once '../config/db_connection.php';

// Check if user is logged in and has EMPLOYEE role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'EMPLOYEE') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Get user data
$sql = "SELECT * FROM users WHERE id = :user_id LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../login.php');
    exit();
}

$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Get barangay of the employee
$barangay_id = 1; // Default, you should get this from user profile or session

// Handle file upload
$upload_success = false;
$upload_message = '';
$upload_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    // Validate form data
    $title = trim($_POST['title']);
    $document_type = $_POST['document_type'];
    $description = trim($_POST['description']);
    $route_id = !empty($_POST['route_id']) ? $_POST['route_id'] : null;
    $association_id = !empty($_POST['association_id']) ? $_POST['association_id'] : null;
    $driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
    $operator_id = !empty($_POST['operator_id']) ? $_POST['operator_id'] : null;
    $valid_from = !empty($_POST['valid_from']) ? $_POST['valid_from'] : null;
    $valid_until = !empty($_POST['valid_until']) ? $_POST['valid_until'] : null;
    
    // Generate document code
    $document_code = 'DOC-' . strtoupper(substr($document_type, 0, 3)) . '-' . date('Ymd') . '-' . rand(100, 999);
    
    // Handle file upload
    if (isset($_FILES['document_file']) && $_FILES['document_file']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['document_file'];
        
        // Validate file type
        $allowed_types = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'
        ];
        
        $file_type = $file['type'];
        if (!array_key_exists($file_type, $allowed_types)) {
            $upload_error = 'Invalid file type. Allowed types: PDF, JPEG, PNG, GIF, DOC, DOCX, XLS, XLSX';
        } else {
            // Validate file size (max 10MB)
            $max_size = 10 * 1024 * 1024; // 10MB
            if ($file['size'] > $max_size) {
                $upload_error = 'File size too large. Maximum size is 10MB.';
            } else {
                // Create upload directory if not exists
                $upload_dir = '../uploads/route_documents/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                // Generate unique filename
                $file_extension = $allowed_types[$file_type];
                $file_name = 'doc_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_extension;
                $file_path = $upload_dir . $file_name;
                
                // Move uploaded file
                if (move_uploaded_file($file['tmp_name'], $file_path)) {
                    // Insert into database
                    $sql = "INSERT INTO route_documents 
                            (document_code, document_type, title, description, route_id, association_id, 
                             driver_id, operator_id, barangay_id, file_name, file_path, file_size, 
                             file_type, uploaded_by, uploaded_at, valid_from, valid_until) 
                            VALUES 
                            (:document_code, :document_type, :title, :description, :route_id, :association_id, 
                             :driver_id, :operator_id, :barangay_id, :file_name, :file_path, :file_size, 
                             :file_type, :uploaded_by, NOW(), :valid_from, :valid_until)";
                    
                    $stmt = $pdo->prepare($sql);
                    $params = [
                        'document_code' => $document_code,
                        'document_type' => $document_type,
                        'title' => $title,
                        'description' => $description,
                        'route_id' => $route_id,
                        'association_id' => $association_id,
                        'driver_id' => $driver_id,
                        'operator_id' => $operator_id,
                        'barangay_id' => $barangay_id,
                        'file_name' => $file_name,
                        'file_path' => $file_path,
                        'file_size' => $file['size'],
                        'file_type' => $file_type,
                        'uploaded_by' => $user_id,
                        'valid_from' => $valid_from,
                        'valid_until' => $valid_until
                    ];
                    
                    if ($stmt->execute($params)) {
                        $upload_success = true;
                        $upload_message = 'Document uploaded successfully!';
                        
                        // Log the upload
                        $log_sql = "INSERT INTO enforcement_logs 
                                   (log_type, reference_id, action, details, acted_by, acted_at) 
                                   VALUES 
                                   ('Driver Check', :doc_id, 'Document Uploaded', :description, :user_id, NOW())";
                        $log_stmt = $pdo->prepare($log_sql);
                        $log_stmt->execute([
                            'doc_id' => $pdo->lastInsertId(),
                            'description' => "Uploaded {$document_type}: {$title}",
                            'user_id' => $user_id
                        ]);
                    } else {
                        $upload_error = 'Failed to save document details to database.';
                        // Remove uploaded file
                        unlink($file_path);
                    }
                } else {
                    $upload_error = 'Failed to upload file. Please try again.';
                }
            }
        }
    } else {
        $upload_error = 'Please select a file to upload.';
    }
}

// Fetch data for dropdowns
$routes = $associations = $drivers = $operators = [];

try {
    // Fetch routes
    $sql = "SELECT id, route_code, route_name FROM tricycle_routes WHERE barangay_id = :barangay_id AND status = 'Active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['barangay_id' => $barangay_id]);
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch associations
    $sql = "SELECT id, association_code, association_name FROM tricycle_associations WHERE barangay_id = :barangay_id AND status = 'Active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['barangay_id' => $barangay_id]);
    $associations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch drivers
    $sql = "SELECT id, driver_code, first_name, last_name FROM tricycle_drivers WHERE status = 'Active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch operators
    $sql = "SELECT id, operator_code, first_name, last_name FROM tricycle_operators WHERE status = 'Active'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $operators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch recent documents
    $sql = "SELECT rd.*, 
                   tr.route_name, 
                   ta.association_name,
                   CONCAT(td.first_name, ' ', td.last_name) as driver_name,
                   CONCAT(to_op.first_name, ' ', to_op.last_name) as operator_name,
                   u.first_name as uploaded_by_name
            FROM route_documents rd
            LEFT JOIN tricycle_routes tr ON rd.route_id = tr.id
            LEFT JOIN tricycle_associations ta ON rd.association_id = ta.id
            LEFT JOIN tricycle_drivers td ON rd.driver_id = td.id
            LEFT JOIN tricycle_operators to_op ON rd.operator_id = to_op.id
            LEFT JOIN users u ON rd.uploaded_by = u.id
            WHERE rd.barangay_id = :barangay_id
            ORDER BY rd.uploaded_at DESC 
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['barangay_id' => $barangay_id]);
    $recent_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Upload | Traffic & Transport Management</title>
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
        
        /* Document Upload Specific Styles */
        .document-container {
            padding: 30px;
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .document-header {
            margin-bottom: 30px;
        }
        
        .document-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }
        
        .document-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .upload-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .dark-mode .upload-section {
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--primary-color);
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 25px;
        }
        
        @media (max-width: 1024px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
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
        
        .form-control {
            width: 100%;
            padding: 12px 15px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        select.form-control {
            cursor: pointer;
        }
        
        .file-upload-container {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.3s, background-color 0.3s;
            position: relative;
        }
        
        .file-upload-container:hover {
            border-color: var(--primary-color);
        }
        
        .file-upload-container.dragover {
            border-color: var(--primary-color);
            background: rgba(13, 148, 136, 0.05);
        }
        
        .dark-mode .file-upload-container.dragover {
            background: rgba(20, 184, 166, 0.1);
        }
        
        .file-input {
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            opacity: 0;
            cursor: pointer;
        }
        
        .upload-icon {
            font-size: 48px;
            color: var(--primary-color);
            margin-bottom: 15px;
        }
        
        .upload-text {
            font-size: 16px;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .upload-hint {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .selected-file {
            margin-top: 15px;
            padding: 10px;
            background: rgba(13, 148, 136, 0.1);
            border-radius: 6px;
            display: none;
            align-items: center;
            justify-content: space-between;
        }
        
        .dark-mode .selected-file {
            background: rgba(20, 184, 166, 0.2);
        }
        
        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            overflow: hidden;
        }
        
        .file-icon {
            font-size: 24px;
            color: var(--primary-color);
            flex-shrink: 0;
        }
        
        .file-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 300px;
        }
        
        .remove-file {
            background: none;
            border: none;
            color: #ef4444;
            cursor: pointer;
            font-size: 20px;
            flex-shrink: 0;
            padding: 5px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }
        
        .remove-file:hover {
            background: rgba(239, 68, 68, 0.1);
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .documents-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 30px;
            margin-top: 30px;
            transition: all 0.3s;
        }
        
        .dark-mode .documents-section {
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .documents-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .documents-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .documents-table th {
            text-align: left;
            padding: 15px;
            border-bottom: 2px solid var(--border-color);
            font-weight: 600;
            color: var(--text-color);
            background: rgba(0,0,0,0.02);
        }
        
        .dark-mode .documents-table th {
            background: rgba(255, 255, 255, 0.05);
        }
        
        .documents-table td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .documents-table tr:hover {
            background: rgba(0,0,0,0.02);
        }
        
        .dark-mode .documents-table tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .document-type-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            display: inline-block;
        }
        
        .badge-route-map {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .badge-route-map {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .badge-resolution {
            background: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .badge-resolution {
            background: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .badge-approval {
            background: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .badge-approval {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .badge-franchise {
            background: #e9d5ff;
            color: #7c3aed;
        }
        
        .dark-mode .badge-franchise {
            background: rgba(139, 92, 246, 0.2);
            color: #c4b5fd;
        }
        
        .badge-license {
            background: #cffafe;
            color: #0e7490;
        }
        
        .dark-mode .badge-license {
            background: rgba(6, 182, 212, 0.2);
            color: #67e8f9;
        }
        
        .badge-other {
            background: #f1f5f9;
            color: #475569;
        }
        
        .dark-mode .badge-other {
            background: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.3s;
            text-decoration: none;
        }
        
        .btn-view {
            background: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .btn-view {
            background: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .btn-view:hover {
            background: #bfdbfe;
        }
        
        .dark-mode .btn-view:hover {
            background: rgba(59, 130, 246, 0.3);
        }
        
        .btn-download {
            background: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .btn-download {
            background: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .btn-download:hover {
            background: #bbf7d0;
        }
        
        .dark-mode .btn-download:hover {
            background: rgba(16, 185, 129, 0.3);
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-active {
            background: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-active {
            background: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-pending {
            background: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-expired {
            background: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .status-expired {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .status-archived {
            background: #f1f5f9;
            color: #475569;
        }
        
        .dark-mode .status-archived {
            background: rgba(148, 163, 184, 0.2);
            color: #cbd5e1;
        }
        
        .no-documents {
            text-align: center;
            padding: 50px 20px;
            color: var(--text-light);
        }
        
        .no-documents i {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        /* Alert Messages */
        .alert-success {
            background: #dcfce7;
            color: #166534;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #bbf7d0;
        }
        
        .dark-mode .alert-success {
            background: rgba(16, 185, 129, 0.2);
            color: #86efac;
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid #fecaca;
        }
        
        .dark-mode .alert-error {
            background: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                width: 100%;
                height: auto;
                padding: 16px;
            }
            
            .header {
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
                justify-content: center;
            }
            
            .time-display {
                min-width: 140px;
            }
            
            .document-container {
                padding: 15px;
            }
            
            .upload-section,
            .documents-section {
                padding: 20px;
            }
            
            .documents-table {
                display: block;
                overflow-x: auto;
            }
            
            .documents-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .action-buttons {
                flex-direction: column;
            }
            
            .btn-action {
                width: 100%;
                justify-content: center;
            }
            
            .file-name {
                max-width: 200px;
            }
        }
        
        /* Loading Spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
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
                    <a href="admin_dashboard.php" class="menu-item">
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
                    <div class="menu-item active">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-monitoring" class="submenu active">
                        <a href="staff_route_management.php" class="submenu-item">Route & Terminal Encoding</a>
                        <a href="staff_driver_association.php" class="submenu-item">Traffic & Incident</a>
                        <a href="staff_document_upload.php" class="submenu-item active">Document Upload</a>
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
                            <input type="text" placeholder="Search documents" class="search-input">
                            <kbd class="search-shortcut">📄</kbd>
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
            
            <!-- Document Upload Content -->
            <div class="document-container">
                <div class="document-header">
                    <h1 class="document-title">Route Document Upload</h1>
                    <p class="document-subtitle">Upload route maps, resolutions, approvals, and other related documents</p>
                </div>
                
                <!-- Upload Form Section -->
                <div class="upload-section">
                    <h2 class="section-title">
                        <i class='bx bx-upload'></i>
                        Upload New Document
                    </h2>
                    
                    <?php if ($upload_success): ?>
                        <div class="alert-success">
                            <i class='bx bx-check-circle'></i> <?php echo $upload_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($upload_error): ?>
                        <div class="alert-error">
                            <i class='bx bx-error-circle'></i> <?php echo $upload_error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="form-grid">
                            <!-- Document Details -->
                            <div class="form-group">
                                <label class="form-label">Document Title *</label>
                                <input type="text" name="title" class="form-control" required 
                                       placeholder="e.g., Route Map - Poblacion to San Isidro">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Document Type *</label>
                                <select name="document_type" class="form-control" required>
                                    <option value="">Select Document Type</option>
                                    <option value="Route Map">Route Map</option>
                                    <option value="Resolution">Resolution</option>
                                    <option value="Approval">Approval</option>
                                    <option value="Franchise">Franchise</option>
                                    <option value="License">License</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                            
                            <!-- Associated Entities -->
                            <div class="form-group">
                                <label class="form-label">Associated Route</label>
                                <select name="route_id" class="form-control">
                                    <option value="">Select Route (Optional)</option>
                                    <?php foreach ($routes as $route): ?>
                                        <option value="<?php echo $route['id']; ?>">
                                            <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Association</label>
                                <select name="association_id" class="form-control">
                                    <option value="">Select Association (Optional)</option>
                                    <?php foreach ($associations as $assoc): ?>
                                        <option value="<?php echo $assoc['id']; ?>">
                                            <?php echo htmlspecialchars($assoc['association_code'] . ' - ' . $assoc['association_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Driver and Operator -->
                            <div class="form-group">
                                <label class="form-label">Driver</label>
                                <select name="driver_id" class="form-control">
                                    <option value="">Select Driver (Optional)</option>
                                    <?php foreach ($drivers as $driver): ?>
                                        <option value="<?php echo $driver['id']; ?>">
                                            <?php echo htmlspecialchars($driver['driver_code'] . ' - ' . $driver['first_name'] . ' ' . $driver['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Operator</label>
                                <select name="operator_id" class="form-control">
                                    <option value="">Select Operator (Optional)</option>
                                    <?php foreach ($operators as $operator): ?>
                                        <option value="<?php echo $operator['id']; ?>">
                                            <?php echo htmlspecialchars($operator['operator_code'] . ' - ' . $operator['first_name'] . ' ' . $operator['last_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <!-- Validity Dates -->
                            <div class="form-group">
                                <label class="form-label">Valid From</label>
                                <input type="date" name="valid_from" class="form-control">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Valid Until</label>
                                <input type="date" name="valid_until" class="form-control">
                            </div>
                            
                            <!-- Description -->
                            <div class="form-group full-width">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="3" 
                                          placeholder="Add description or notes about this document..."></textarea>
                            </div>
                            
                            <!-- File Upload -->
                            <div class="form-group full-width">
                                <label class="form-label">Document File *</label>
                                <div class="file-upload-container" id="fileUploadContainer">
                                    <input type="file" name="document_file" id="documentFile" class="file-input" accept=".pdf,.jpg,.jpeg,.png,.gif,.doc,.docx,.xls,.xlsx" required>
                                    <div class="upload-icon">
                                        <i class='bx bxs-cloud-upload'></i>
                                    </div>
                                    <div class="upload-text">Click to upload or drag and drop</div>
                                    <div class="upload-hint">Max file size: 10MB. Supported formats: PDF, JPG, PNG, GIF, DOC, DOCX, XLS, XLSX</div>
                                    <div id="selectedFile" class="selected-file">
                                        <div class="file-info">
                                            <i class='bx bxs-file file-icon'></i>
                                            <span id="fileName" class="file-name"></span>
                                            <span id="fileSize" class="upload-hint"></span>
                                        </div>
                                        <button type="button" class="remove-file" onclick="clearFileSelection()">
                                            <i class='bx bx-x'></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div style="text-align: center;">
                            <button type="submit" name="upload_document" class="btn-primary" id="submitBtn">
                                <i class='bx bx-upload'></i>
                                Upload Document
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Recent Documents Section -->
                <div class="documents-section">
                    <div class="documents-header">
                        <h2 class="section-title">
                            <i class='bx bxs-file'></i>
                            Recent Documents
                        </h2>
                        <a href="staff_document_list.php" class="btn-primary">
                            <i class='bx bx-list-ul'></i>
                            View All Documents
                        </a>
                    </div>
                    
                    <?php if (!empty($recent_documents)): ?>
                        <table class="documents-table">
                            <thead>
                                <tr>
                                    <th>Document Code</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Associated With</th>
                                    <th>Uploaded By</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_documents as $doc): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($doc['document_code']); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($doc['title']); ?>
                                            <?php if ($doc['description']): ?>
                                                <br><small style="color: var(--text-light);"><?php echo substr(htmlspecialchars($doc['description']), 0, 50); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-' . strtolower(str_replace(' ', '-', $doc['document_type']));
                                            ?>
                                            <span class="document-type-badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($doc['document_type']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php
                                            $associated_with = [];
                                            if ($doc['route_name']) $associated_with[] = 'Route: ' . $doc['route_name'];
                                            if ($doc['association_name']) $associated_with[] = 'Assoc: ' . $doc['association_name'];
                                            if ($doc['driver_name']) $associated_with[] = 'Driver: ' . $doc['driver_name'];
                                            if ($doc['operator_name']) $associated_with[] = 'Operator: ' . $doc['operator_name'];
                                            echo $associated_with ? implode('<br>', $associated_with) : '—';
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($doc['uploaded_by_name']); ?></td>
                                        <td>
                                            <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                            <br><small style="color: var(--text-light);"><?php echo date('h:i A', strtotime($doc['uploaded_at'])); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $status_class = 'status-' . strtolower($doc['status']);
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($doc['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-action btn-view" onclick="viewDocument(<?php echo $doc['id']; ?>)">
                                                    <i class='bx bx-show'></i> View
                                                </button>
                                                <button class="btn-action btn-download" onclick="downloadDocument(<?php echo $doc['id']; ?>)">
                                                    <i class='bx bx-download'></i> Download
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-documents">
                            <i class='bx bx-folder-open'></i>
                            <h3>No Documents Uploaded Yet</h3>
                            <p>Upload your first document using the form above.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // File upload handling
        const fileInput = document.getElementById('documentFile');
        const fileUploadContainer = document.getElementById('fileUploadContainer');
        const selectedFileDiv = document.getElementById('selectedFile');
        const fileNameSpan = document.getElementById('fileName');
        const fileSizeSpan = document.getElementById('fileSize');
        const submitBtn = document.getElementById('submitBtn');
        
        // Format file size
        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }
        
        // Handle file selection
        fileInput.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                fileNameSpan.textContent = file.name;
                fileSizeSpan.textContent = formatFileSize(file.size);
                selectedFileDiv.style.display = 'flex';
                
                // Validate file size (10MB max)
                const maxSize = 10 * 1024 * 1024; // 10MB
                if (file.size > maxSize) {
                    Swal.fire({
                        icon: 'error',
                        title: 'File Too Large',
                        text: 'Maximum file size is 10MB. Please select a smaller file.',
                        confirmButtonColor: '#0d9488'
                    });
                    clearFileSelection();
                }
            }
        });
        
        // Drag and drop handling
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            fileUploadContainer.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            fileUploadContainer.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            fileUploadContainer.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight() {
            fileUploadContainer.classList.add('dragover');
        }
        
        function unhighlight() {
            fileUploadContainer.classList.remove('dragover');
        }
        
        fileUploadContainer.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            
            if (files.length > 0) {
                fileInput.files = files;
                const changeEvent = new Event('change', { bubbles: true });
                fileInput.dispatchEvent(changeEvent);
            }
        }
        
        function clearFileSelection() {
            fileInput.value = '';
            selectedFileDiv.style.display = 'none';
        }
        
        // Document actions
        function viewDocument(docId) {
            Swal.fire({
                title: 'View Document',
                text: 'This feature will open the document in a new tab.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Open Document',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#0d9488'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In a real implementation, you would fetch the document URL
                    // window.open(`view_document.php?id=${docId}`, '_blank');
                    Swal.fire({
                        title: 'Coming Soon',
                        text: 'Document viewer will be available in the next update.',
                        icon: 'info',
                        confirmButtonColor: '#0d9488'
                    });
                }
            });
        }
        
        function downloadDocument(docId) {
            Swal.fire({
                title: 'Download Document',
                text: 'Are you sure you want to download this document?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Download',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#0d9488'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In a real implementation, you would trigger the download
                    // window.location.href = `download_document.php?id=${docId}`;
                    Swal.fire({
                        title: 'Coming Soon',
                        text: 'Document download will be available in the next update.',
                        icon: 'info',
                        confirmButtonColor: '#0d9488'
                    });
                }
            });
        }
        
        // Form validation
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            const file = fileInput.files[0];
            const title = document.querySelector('input[name="title"]').value;
            const docType = document.querySelector('select[name="document_type"]').value;
            
            // Basic validation
            if (!title.trim()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Title',
                    text: 'Please enter a document title.',
                    confirmButtonColor: '#0d9488'
                });
                return;
            }
            
            if (!docType) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Document Type',
                    text: 'Please select a document type.',
                    confirmButtonColor: '#0d9488'
                });
                return;
            }
            
            if (!file) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Missing File',
                    text: 'Please select a document file to upload.',
                    confirmButtonColor: '#0d9488'
                });
                return;
            }
            
            const maxSize = 10 * 1024 * 1024; // 10MB
            if (file.size > maxSize) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'File Too Large',
                    text: 'Maximum file size is 10MB. Please select a smaller file.',
                    confirmButtonColor: '#0d9488'
                });
                return;
            }
            
            // Show loading indicator
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Uploading...';
            
            // You can optionally add a timeout to re-enable the button if upload takes too long
            setTimeout(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="bx bx-upload"></i> Upload Document';
            }, 30000); // 30 seconds timeout
        });
        
        // Auto-show success message if upload was successful
        <?php if ($upload_success): ?>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'Document Uploaded!',
                text: 'Your document has been uploaded successfully.',
                confirmButtonColor: '#0d9488',
                timer: 3000,
                timerProgressBar: true
            });
        });
        <?php endif; ?>
        
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = submenu.previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Dark mode toggle
        const themeToggle = document.getElementById('theme-toggle');
        const themeIcon = themeToggle.querySelector('i');
        const themeText = themeToggle.querySelector('span');
        
        // Check for saved theme preference or default to light mode
        const currentTheme = localStorage.getItem('theme') || 'light';
        if (currentTheme === 'dark') {
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
        
        // Add hover effects to cards
        document.querySelectorAll('.upload-section, .documents-section').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Initialize submenus
        document.addEventListener('DOMContentLoaded', function() {
            // Set active submenu for the current module
            const currentModule = document.querySelector('.menu-item.active');
            if (currentModule) {
                const submenuId = currentModule.getAttribute('onclick')?.match(/toggleSubmenu\('([^']+)'\)/)?.[1];
                if (submenuId) {
                    const submenu = document.getElementById(submenuId);
                    const arrow = currentModule.querySelector('.dropdown-arrow');
                    if (submenu) {
                        submenu.classList.add('active');
                        arrow.classList.add('rotated');
                    }
                }
            }
        });
    </script>
</body>
</html>