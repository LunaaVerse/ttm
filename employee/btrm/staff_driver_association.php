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
        'first_name' => 'Admin',
        'middle_name' => '',
        'last_name' => 'User',
        'email' => 'admin@commonwealth.com',
        'role' => 'ADMIN'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'driver_association';

// Initialize variables
$search = '';
$barangay_filter = '';
$status_filter = '';
$records_per_page = 10;
$page = 1;

// Handle search and filters
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

if (isset($_GET['barangay'])) {
    $barangay_filter = $_GET['barangay'];
}

if (isset($_GET['status'])) {
    $status_filter = $_GET['status'];
}

if (isset($_GET['page']) && is_numeric($_GET['page'])) {
    $page = max(1, intval($_GET['page']));
}

// Get barangays for filter
$barangays = [];
$sql_barangays = "SELECT id, name FROM barangays ORDER BY name";
$stmt_barangays = $pdo->query($sql_barangays);
$barangays = $stmt_barangays->fetchAll(PDO::FETCH_ASSOC);

// Handle record actions (Add, Edit, Delete)
$action_message = '';
$action_type = '';
$record_id = null;

// Add new record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_record'])) {
    try {
        $pdo->beginTransaction();
        
        $record_code = 'REC-' . strtoupper(uniqid());
        $record_type = $_POST['record_type'];
        $driver_id = $_POST['driver_id'] ?: null;
        $association_id = $_POST['association_id'] ?: null;
        $operator_id = $_POST['operator_id'] ?: null;
        $vehicle_number = $_POST['vehicle_number'] ?: null;
        $barangay_id = $_POST['barangay_id'];
        $route_id = $_POST['route_id'] ?: null;
        $date_registered = $_POST['date_registered'];
        $date_expiry = $_POST['date_expiry'] ?: null;
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?: null;
        
        $sql_insert = "INSERT INTO driver_association_records 
                      (record_code, record_type, driver_id, association_id, operator_id, vehicle_number, 
                       barangay_id, route_id, date_registered, date_expiry, status, notes, created_by) 
                      VALUES 
                      (:record_code, :record_type, :driver_id, :association_id, :operator_id, :vehicle_number,
                       :barangay_id, :route_id, :date_registered, :date_expiry, :status, :notes, :created_by)";
        
        $stmt_insert = $pdo->prepare($sql_insert);
        $stmt_insert->execute([
            'record_code' => $record_code,
            'record_type' => $record_type,
            'driver_id' => $driver_id,
            'association_id' => $association_id,
            'operator_id' => $operator_id,
            'vehicle_number' => $vehicle_number,
            'barangay_id' => $barangay_id,
            'route_id' => $route_id,
            'date_registered' => $date_registered,
            'date_expiry' => $date_expiry,
            'status' => $status,
            'notes' => $notes,
            'created_by' => $user_id
        ]);
        
        $record_id = $pdo->lastInsertId();
        
        // Handle file uploads
        if (isset($_FILES['documents']) && !empty($_FILES['documents']['name'][0])) {
            $upload_dir = '../../uploads/driver_documents/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            foreach ($_FILES['documents']['name'] as $key => $name) {
                if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_tmp = $_FILES['documents']['tmp_name'][$key];
                    $file_size = $_FILES['documents']['size'][$key];
                    $file_ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $file_name = 'doc_' . $record_id . '_' . time() . '_' . $key . '.' . $file_ext;
                    $file_path = $upload_dir . $file_name;
                    
                    // Allowed file types
                    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
                    
                    if (in_array($file_ext, $allowed_ext) && $file_size <= 5242880) { // 5MB limit
                        if (move_uploaded_file($file_tmp, $file_path)) {
                            $sql_file = "INSERT INTO driver_attachments 
                                        (record_id, attachment_type, file_name, file_path, file_size, uploaded_by) 
                                        VALUES 
                                        (:record_id, :attachment_type, :file_name, :file_path, :file_size, :uploaded_by)";
                            
                            $stmt_file = $pdo->prepare($sql_file);
                            $stmt_file->execute([
                                'record_id' => $record_id,
                                'attachment_type' => 'Other', // Default type
                                'file_name' => $name,
                                'file_path' => 'uploads/driver_documents/' . $file_name,
                                'file_size' => $file_size,
                                'uploaded_by' => $user_id
                            ]);
                        }
                    }
                }
            }
        }
        
        $pdo->commit();
        $action_message = 'Record added successfully!';
        $action_type = 'success';
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $action_message = 'Error adding record: ' . $e->getMessage();
        $action_type = 'error';
    }
}

// Update record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_record'])) {
    try {
        $record_id = $_POST['record_id'];
        $record_type = $_POST['record_type'];
        $driver_id = $_POST['driver_id'] ?: null;
        $association_id = $_POST['association_id'] ?: null;
        $operator_id = $_POST['operator_id'] ?: null;
        $vehicle_number = $_POST['vehicle_number'] ?: null;
        $barangay_id = $_POST['barangay_id'];
        $route_id = $_POST['route_id'] ?: null;
        $date_registered = $_POST['date_registered'];
        $date_expiry = $_POST['date_expiry'] ?: null;
        $status = $_POST['status'];
        $notes = $_POST['notes'] ?: null;
        
        $sql_update = "UPDATE driver_association_records 
                      SET record_type = :record_type,
                          driver_id = :driver_id,
                          association_id = :association_id,
                          operator_id = :operator_id,
                          vehicle_number = :vehicle_number,
                          barangay_id = :barangay_id,
                          route_id = :route_id,
                          date_registered = :date_registered,
                          date_expiry = :date_expiry,
                          status = :status,
                          notes = :notes,
                          updated_at = CURRENT_TIMESTAMP
                      WHERE id = :id AND created_by = :created_by";
        
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([
            'record_type' => $record_type,
            'driver_id' => $driver_id,
            'association_id' => $association_id,
            'operator_id' => $operator_id,
            'vehicle_number' => $vehicle_number,
            'barangay_id' => $barangay_id,
            'route_id' => $route_id,
            'date_registered' => $date_registered,
            'date_expiry' => $date_expiry,
            'status' => $status,
            'notes' => $notes,
            'id' => $record_id,
            'created_by' => $user_id
        ]);
        
        $action_message = 'Record updated successfully!';
        $action_type = 'success';
        
    } catch (Exception $e) {
        $action_message = 'Error updating record: ' . $e->getMessage();
        $action_type = 'error';
    }
}

// Delete record
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        $delete_id = $_GET['delete'];
        
        // Check if record exists and user has permission
        $sql_check = "SELECT id FROM driver_association_records WHERE id = :id AND created_by = :created_by";
        $stmt_check = $pdo->prepare($sql_check);
        $stmt_check->execute(['id' => $delete_id, 'created_by' => $user_id]);
        
        if ($stmt_check->rowCount() > 0) {
            $sql_delete = "DELETE FROM driver_association_records WHERE id = :id";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->execute(['id' => $delete_id]);
            
            $action_message = 'Record deleted successfully!';
            $action_type = 'success';
        } else {
            $action_message = 'Record not found or you do not have permission to delete it!';
            $action_type = 'error';
        }
        
    } catch (Exception $e) {
        $action_message = 'Error deleting record: ' . $e->getMessage();
        $action_type = 'error';
    }
}

// Get records for display with pagination
$where_clauses = [];
$params = [];
$param_types = [];

// Build WHERE clause based on filters
if (!empty($search)) {
    $where_clauses[] = "(record_code LIKE :search OR 
                        vehicle_number LIKE :search OR 
                        notes LIKE :search)";
    $params[':search'] = "%$search%";
    $param_types[':search'] = PDO::PARAM_STR;
}

if (!empty($barangay_filter)) {
    $where_clauses[] = "barangay_id = :barangay";
    $params[':barangay'] = $barangay_filter;
    $param_types[':barangay'] = PDO::PARAM_INT;
}

if (!empty($status_filter)) {
    $where_clauses[] = "status = :status";
    $params[':status'] = $status_filter;
    $param_types[':status'] = PDO::PARAM_STR;
}

$where_sql = '';
if (!empty($where_clauses)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where_clauses);
}

// Get total count for pagination
$sql_count = "SELECT COUNT(*) as total FROM driver_association_records $where_sql";
$stmt_count = $pdo->prepare($sql_count);
foreach ($params as $key => $value) {
    $stmt_count->bindValue($key, $value, $param_types[$key]);
}
$stmt_count->execute();
$total_records = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);
$offset = ($page - 1) * $records_per_page;

// Get records with pagination
$sql_records = "SELECT dar.*, 
                b.name as barangay_name,
                tr.route_name,
                td.first_name as driver_first_name,
                td.last_name as driver_last_name,
                ta.association_name,
                op.first_name as operator_first_name,
                op.last_name as operator_last_name,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name
                FROM driver_association_records dar
                LEFT JOIN barangays b ON dar.barangay_id = b.id
                LEFT JOIN tricycle_routes tr ON dar.route_id = tr.id
                LEFT JOIN tricycle_drivers td ON dar.driver_id = td.id
                LEFT JOIN tricycle_associations ta ON dar.association_id = ta.id
                LEFT JOIN tricycle_operators op ON dar.operator_id = op.id
                LEFT JOIN users u ON dar.created_by = u.id
                $where_sql
                ORDER BY dar.created_at DESC
                LIMIT :offset, :limit";

$stmt_records = $pdo->prepare($sql_records);
foreach ($params as $key => $value) {
    $stmt_records->bindValue($key, $value, $param_types[$key]);
}
$stmt_records->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt_records->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt_records->execute();
$records = $stmt_records->fetchAll(PDO::FETCH_ASSOC);

// Get related data for dropdowns
$drivers = [];
$associations = [];
$operators = [];
$routes = [];

// Get drivers
$sql_drivers = "SELECT id, CONCAT(first_name, ' ', last_name) as name, license_number 
                FROM tricycle_drivers 
                WHERE status = 'Active' 
                ORDER BY last_name, first_name";
$stmt_drivers = $pdo->query($sql_drivers);
$drivers = $stmt_drivers->fetchAll(PDO::FETCH_ASSOC);

// Get associations
$sql_associations = "SELECT id, association_name as name 
                     FROM tricycle_associations 
                     WHERE status = 'Active' 
                     ORDER BY association_name";
$stmt_associations = $pdo->query($sql_associations);
$associations = $stmt_associations->fetchAll(PDO::FETCH_ASSOC);

// Get operators
$sql_operators = "SELECT id, CONCAT(first_name, ' ', last_name) as name, franchise_number 
                  FROM tricycle_operators 
                  WHERE status = 'Active' 
                  ORDER BY last_name, first_name";
$stmt_operators = $pdo->query($sql_operators);
$operators = $stmt_operators->fetchAll(PDO::FETCH_ASSOC);

// Get routes
$sql_routes = "SELECT id, route_name as name, route_code 
               FROM tricycle_routes 
               WHERE status = 'Active' 
               ORDER BY route_name";
$stmt_routes = $pdo->query($sql_routes);
$routes = $stmt_routes->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver & Association Records - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <style>
        /* Import existing styles from dashboard */
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
        
        /* Status badges */
        .status-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .status-completed {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-completed {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .status-progress {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-progress {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-pending {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .status-pending {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
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
        }
        
        /* ===== MODULE SPECIFIC STYLES ===== */
        .module-container {
            padding: 32px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .module-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .module-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .module-subtitle {
            font-size: 16px;
            color: var(--text-light);
            margin-top: 5px;
        }
        
        .module-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
            font-size: 14px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #0da271;
        }
        
        .btn-danger {
            background-color: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .filters-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 14px;
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
        
        .table-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background-color: rgba(0, 0, 0, 0.02);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .dark-mode .data-table th {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .status-inactive {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        .status-suspended {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .status-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .status-expired {
            background-color: #f3f4f6;
            color: #6b7280;
            text-decoration: line-through;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }
        
        .page-link {
            padding: 8px 16px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            font-size: 14px;
            transition: all 0.2s;
        }
        
        .page-link:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .page-link.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-info {
            font-size: 14px;
            color: var(--text-light);
        }
        
        /* Modal styles */
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
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-light);
            cursor: pointer;
            padding: 0;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }
        
        .modal-close:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }
        
        .form-full {
            grid-column: 1 / -1;
        }
        
        .file-upload {
            border: 2px dashed var(--border-color);
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: border-color 0.2s;
        }
        
        .file-upload:hover {
            border-color: var(--primary-color);
        }
        
        .file-upload input {
            display: none;
        }
        
        .file-upload-icon {
            font-size: 48px;
            color: var(--text-light);
            margin-bottom: 16px;
        }
        
        .file-list {
            margin-top: 16px;
        }
        
        .file-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px;
            background-color: rgba(0, 0, 0, 0.02);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .file-name {
            font-size: 14px;
            color: var(--text-color);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
        }
        
        .file-remove {
            background: none;
            border: none;
            color: var(--text-light);
            cursor: pointer;
            padding: 4px;
            border-radius: 4px;
        }
        
        .file-remove:hover {
            color: #ef4444;
            background-color: rgba(239, 68, 68, 0.1);
        }
        
        .modal-footer {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        @media (max-width: 768px) {
            .module-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .module-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
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
                    <!-- Dashboard Button (Added as first item) -->
                    <a href="../admin_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
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
                        <a href="../lrcr/employee_create_report.php" class="submenu-item">Create Report</a>
                        <a href="../lrcr/employee_road_status_monitoring.php" class="submenu-item">Action Assignment</a>
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
                        <a href="staff_driver_association.php" class="submenu-item active">Driver & Association Records</a>
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
            
            <!-- Driver & Association Records Module Content -->
            <div class="module-container">
                <!-- Module Header -->
                <div class="module-header">
                    <div>
                        <h1 class="module-title">Driver & Association Records</h1>
                        <p class="module-subtitle">Manage tricycle drivers, operators, associations, and vehicle records</p>
                    </div>
                    <div class="module-actions">
                        <button class="btn btn-primary" onclick="openAddModal()">
                            <i class='bx bx-plus'></i>
                            Add New Record
                        </button>
                        <button class="btn btn-secondary" onclick="exportToExcel()">
                            <i class='bx bx-download'></i>
                            Export Excel
                        </button>
                        <button class="btn btn-secondary" onclick="printRecords()">
                            <i class='bx bx-printer'></i>
                            Print
                        </button>
                    </div>
                </div>
                
                <!-- Filters Card -->
                <div class="filters-card">
                    <form method="GET" action="" id="filterForm">
                        <div class="filters-grid">
                            <div class="form-group">
                                <label class="form-label">Search</label>
                                <input type="text" name="search" class="form-control" placeholder="Search by code, vehicle, or notes..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Barangay</label>
                                <select name="barangay" class="form-control">
                                    <option value="">All Barangays</option>
                                    <?php foreach ($barangays as $barangay): ?>
                                        <option value="<?php echo $barangay['id']; ?>" <?php echo $barangay_filter == $barangay['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($barangay['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-control">
                                    <option value="">All Status</option>
                                    <option value="Active" <?php echo $status_filter == 'Active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="Inactive" <?php echo $status_filter == 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="Suspended" <?php echo $status_filter == 'Suspended' ? 'selected' : ''; ?>>Suspended</option>
                                    <option value="Pending" <?php echo $status_filter == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Expired" <?php echo $status_filter == 'Expired' ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" class="btn btn-primary" style="width: 100%;">
                                    <i class='bx bx-search'></i>
                                    Apply Filters
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Records Table -->
                <div class="table-container">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Record Code</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                    <th>Barangay</th>
                                    <th>Dates</th>
                                    <th>Status</th>
                                    <th>Created By</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($records)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-state">
                                            <div class="empty-state-icon">
                                                <i class='bx bx-file'></i>
                                            </div>
                                            <h3>No records found</h3>
                                            <p>Try adjusting your filters or add a new record</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($records as $record): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($record['record_code']); ?></strong>
                                            </td>
                                            <td>
                                                <?php 
                                                $type_icons = [
                                                    'Driver' => 'bx bx-user',
                                                    'Association' => 'bx bx-group',
                                                    'Operator' => 'bx bx-briefcase',
                                                    'Vehicle' => 'bx bx-car'
                                                ];
                                                $icon = $type_icons[$record['record_type']] ?? 'bx bx-file';
                                                ?>
                                                <i class='<?php echo $icon; ?>'></i>
                                                <?php echo htmlspecialchars($record['record_type']); ?>
                                            </td>
                                            <td>
                                                <?php if ($record['record_type'] == 'Driver' && $record['driver_first_name']): ?>
                                                    <?php echo htmlspecialchars($record['driver_first_name'] . ' ' . $record['driver_last_name']); ?>
                                                <?php elseif ($record['record_type'] == 'Association' && $record['association_name']): ?>
                                                    <?php echo htmlspecialchars($record['association_name']); ?>
                                                <?php elseif ($record['record_type'] == 'Operator' && $record['operator_first_name']): ?>
                                                    <?php echo htmlspecialchars($record['operator_first_name'] . ' ' . $record['operator_last_name']); ?>
                                                <?php elseif ($record['record_type'] == 'Vehicle' && $record['vehicle_number']): ?>
                                                    <?php echo htmlspecialchars($record['vehicle_number']); ?>
                                                <?php endif; ?>
                                                <?php if ($record['route_name']): ?>
                                                    <br><small>Route: <?php echo htmlspecialchars($record['route_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['barangay_name']); ?>
                                            </td>
                                            <td>
                                                <small>Reg: <?php echo date('M d, Y', strtotime($record['date_registered'])); ?></small>
                                                <?php if ($record['date_expiry']): ?>
                                                    <br><small>Exp: <?php echo date('M d, Y', strtotime($record['date_expiry'])); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $status_class = 'status-badge status-' . strtolower($record['status']);
                                                ?>
                                                <span class="<?php echo $status_class; ?>">
                                                    <?php echo htmlspecialchars($record['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($record['creator_first_name'] . ' ' . $record['creator_last_name']); ?>
                                                <br><small><?php echo date('M d, Y', strtotime($record['created_at'])); ?></small>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn btn-secondary btn-sm" onclick="viewRecord(<?php echo $record['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                    </button>
                                                    <button class="btn btn-secondary btn-sm" onclick="editRecord(<?php echo $record['id']; ?>)">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                    <button class="btn btn-danger btn-sm" onclick="deleteRecord(<?php echo $record['id']; ?>, '<?php echo htmlspecialchars($record['record_code']); ?>')">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&barangay=<?php echo $barangay_filter; ?>&status=<?php echo $status_filter; ?>" class="page-link">
                                    <i class='bx bx-chevron-left'></i> Previous
                                </a>
                            <?php endif; ?>
                            
                            <span class="page-info">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                            </span>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&barangay=<?php echo $barangay_filter; ?>&status=<?php echo $status_filter; ?>" class="page-link">
                                    Next <i class='bx bx-chevron-right'></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Record Modal -->
    <div class="modal" id="recordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Record</h2>
                <button class="modal-close" onclick="closeModal()">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data" id="recordForm">
                <div class="modal-body">
                    <input type="hidden" name="record_id" id="recordId">
                    <input type="hidden" name="action_type" id="actionType" value="add">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Record Type *</label>
                            <select name="record_type" id="recordType" class="form-control" required onchange="updateFormFields()">
                                <option value="">Select Type</option>
                                <option value="Driver">Driver</option>
                                <option value="Association">Association</option>
                                <option value="Operator">Operator</option>
                                <option value="Vehicle">Vehicle</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="driverField">
                            <label class="form-label">Driver</label>
                            <select name="driver_id" id="driverId" class="form-control">
                                <option value="">Select Driver</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>">
                                        <?php echo htmlspecialchars($driver['name'] . ' (' . $driver['license_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="associationField">
                            <label class="form-label">Association</label>
                            <select name="association_id" id="associationId" class="form-control">
                                <option value="">Select Association</option>
                                <?php foreach ($associations as $association): ?>
                                    <option value="<?php echo $association['id']; ?>">
                                        <?php echo htmlspecialchars($association['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="operatorField">
                            <label class="form-label">Operator</label>
                            <select name="operator_id" id="operatorId" class="form-control">
                                <option value="">Select Operator</option>
                                <?php foreach ($operators as $operator): ?>
                                    <option value="<?php echo $operator['id']; ?>">
                                        <?php echo htmlspecialchars($operator['name'] . ' (' . $operator['franchise_number'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group" id="vehicleField">
                            <label class="form-label">Vehicle Number</label>
                            <input type="text" name="vehicle_number" id="vehicleNumber" class="form-control" placeholder="e.g., TRC-123">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Barangay *</label>
                            <select name="barangay_id" id="barangayId" class="form-control" required>
                                <option value="">Select Barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>">
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Route</label>
                            <select name="route_id" id="routeId" class="form-control">
                                <option value="">Select Route</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo $route['id']; ?>">
                                        <?php echo htmlspecialchars($route['name'] . ' (' . $route['route_code'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date Registered *</label>
                            <input type="date" name="date_registered" id="dateRegistered" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Date Expiry</label>
                            <input type="date" name="date_expiry" id="dateExpiry" class="form-control">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Status *</label>
                            <select name="status" id="status" class="form-control" required>
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                                <option value="Suspended">Suspended</option>
                                <option value="Pending">Pending</option>
                                <option value="Expired">Expired</option>
                            </select>
                        </div>
                        
                        <div class="form-group form-full">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                        </div>
                        
                        <div class="form-group form-full" id="fileUploadSection">
                            <label class="form-label">Documents (Optional)</label>
                            <div class="file-upload" onclick="document.getElementById('fileInput').click()">
                                <div class="file-upload-icon">
                                    <i class='bx bx-cloud-upload'></i>
                                </div>
                                <p>Click to upload documents</p>
                                <p><small>Max file size: 5MB each (PDF, JPG, PNG, DOC)</small></p>
                                <input type="file" name="documents[]" id="fileInput" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" onchange="displayFileNames()">
                            </div>
                            <div class="file-list" id="fileList"></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" name="add_record" id="submitBtn">Add Record</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- JavaScript Libraries -->
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
        
        // Modal functions
        function openAddModal() {
            document.getElementById('recordForm').reset();
            document.getElementById('modalTitle').textContent = 'Add New Record';
            document.getElementById('submitBtn').name = 'add_record';
            document.getElementById('submitBtn').textContent = 'Add Record';
            document.getElementById('actionType').value = 'add';
            document.getElementById('recordId').value = '';
            
            // Show all fields initially
            updateFormFields();
            
            document.getElementById('recordModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('recordModal').classList.remove('active');
        }
        
        function updateFormFields() {
            const recordType = document.getElementById('recordType').value;
            
            // Hide all optional fields
            document.getElementById('driverField').style.display = 'none';
            document.getElementById('associationField').style.display = 'none';
            document.getElementById('operatorField').style.display = 'none';
            document.getElementById('vehicleField').style.display = 'none';
            
            // Show relevant fields based on record type
            if (recordType === 'Driver') {
                document.getElementById('driverField').style.display = 'block';
                document.getElementById('vehicleField').style.display = 'block';
            } else if (recordType === 'Association') {
                document.getElementById('associationField').style.display = 'block';
            } else if (recordType === 'Operator') {
                document.getElementById('operatorField').style.display = 'block';
                document.getElementById('vehicleField').style.display = 'block';
            } else if (recordType === 'Vehicle') {
                document.getElementById('vehicleField').style.display = 'block';
            }
        }
        
        function displayFileNames() {
            const fileInput = document.getElementById('fileInput');
            const fileList = document.getElementById('fileList');
            fileList.innerHTML = '';
            
            if (fileInput.files.length > 0) {
                for (let i = 0; i < fileInput.files.length; i++) {
                    const file = fileInput.files[i];
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    fileItem.innerHTML = `
                        <span class="file-name">${file.name}</span>
                        <button type="button" class="file-remove" onclick="removeFile(${i})">
                            <i class='bx bx-x'></i>
                        </button>
                    `;
                    fileList.appendChild(fileItem);
                }
            }
        }
        
        function removeFile(index) {
            const fileInput = document.getElementById('fileInput');
            const dt = new DataTransfer();
            
            for (let i = 0; i < fileInput.files.length; i++) {
                if (i !== index) {
                    dt.items.add(fileInput.files[i]);
                }
            }
            
            fileInput.files = dt.files;
            displayFileNames();
        }
        
        function viewRecord(id) {
            // In a real implementation, this would fetch record details via AJAX
            Swal.fire({
                title: 'View Record',
                text: 'View functionality would show detailed record information here.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        async function editRecord(id) {
            try {
                const response = await fetch(`ajax/get_record.php?id=${id}`);
                const record = await response.json();
                
                if (record) {
                    document.getElementById('modalTitle').textContent = 'Edit Record';
                    document.getElementById('submitBtn').name = 'update_record';
                    document.getElementById('submitBtn').textContent = 'Update Record';
                    document.getElementById('actionType').value = 'edit';
                    document.getElementById('recordId').value = record.id;
                    
                    // Populate form fields
                    document.getElementById('recordType').value = record.record_type;
                    document.getElementById('driverId').value = record.driver_id || '';
                    document.getElementById('associationId').value = record.association_id || '';
                    document.getElementById('operatorId').value = record.operator_id || '';
                    document.getElementById('vehicleNumber').value = record.vehicle_number || '';
                    document.getElementById('barangayId').value = record.barangay_id;
                    document.getElementById('routeId').value = record.route_id || '';
                    document.getElementById('dateRegistered').value = record.date_registered;
                    document.getElementById('dateExpiry').value = record.date_expiry || '';
                    document.getElementById('status').value = record.status;
                    document.getElementById('notes').value = record.notes || '';
                    
                    updateFormFields();
                    document.getElementById('recordModal').classList.add('active');
                }
            } catch (error) {
                Swal.fire({
                    title: 'Error',
                    text: 'Failed to load record details',
                    icon: 'error'
                });
            }
        }
        
        function deleteRecord(id, code) {
            Swal.fire({
                title: 'Delete Record?',
                text: `Are you sure you want to delete record ${code}? This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?delete=${id}&search=<?php echo urlencode($search); ?>&barangay=<?php echo $barangay_filter; ?>&status=<?php echo $status_filter; ?>`;
                }
            });
        }
        
        function exportToExcel() {
            Swal.fire({
                title: 'Export to Excel',
                text: 'This feature would export all filtered records to an Excel file.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        function printRecords() {
            window.print();
        }
        
        // Close modal when clicking outside
        document.getElementById('recordModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        // Show action message if exists
        <?php if ($action_message): ?>
            document.addEventListener('DOMContentLoaded', function() {
                Swal.fire({
                    title: '<?php echo $action_type === "success" ? "Success" : "Error"; ?>',
                    text: '<?php echo addslashes($action_message); ?>',
                    icon: '<?php echo $action_type; ?>',
                    confirmButtonText: 'OK'
                }).then(() => {
                    // Remove message parameters from URL
                    const url = new URL(window.location);
                    url.searchParams.delete('delete');
                    window.history.replaceState({}, '', url);
                });
            });
        <?php endif; ?>
        
        // Form validation
        document.getElementById('recordForm').addEventListener('submit', function(e) {
            const recordType = document.getElementById('recordType').value;
            const barangayId = document.getElementById('barangayId').value;
            const dateRegistered = document.getElementById('dateRegistered').value;
            const status = document.getElementById('status').value;
            
            if (!recordType || !barangayId || !dateRegistered || !status) {
                e.preventDefault();
                Swal.fire({
                    title: 'Validation Error',
                    text: 'Please fill in all required fields (*).',
                    icon: 'error'
                });
            }
        });
    </script>
</body>
</html>