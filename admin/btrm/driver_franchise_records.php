<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Check if user is logged in and is ADMIN
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../index.php');
    exit();
}

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

// Get current user
$user_id = $_SESSION['user_id'];
$user = getUserData($pdo, $user_id);

if (!$user) {
    header('Location: ../index.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'driver-franchise';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_driver':
                addDriver($pdo);
                break;
            case 'edit_driver':
                editDriver($pdo);
                break;
            case 'delete_driver':
                deleteDriver($pdo);
                break;
            case 'add_association':
                addAssociation($pdo);
                break;
            case 'edit_association':
                editAssociation($pdo);
                break;
            case 'delete_association':
                deleteAssociation($pdo);
                break;
        }
    }
}

// Function to add driver
function addDriver($pdo) {
    try {
        $sql = "INSERT INTO tricycle_drivers (
            driver_code, first_name, middle_name, last_name, contact_number, address, 
            date_of_birth, license_number, license_expiry, association_id, operator_id, 
            vehicle_number, route_id, status, years_of_service
        ) VALUES (
            :driver_code, :first_name, :middle_name, :last_name, :contact_number, :address,
            :date_of_birth, :license_number, :license_expiry, :association_id, :operator_id,
            :vehicle_number, :route_id, :status, :years_of_service
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'driver_code' => $_POST['driver_code'],
            'first_name' => $_POST['first_name'],
            'middle_name' => $_POST['middle_name'] ?? null,
            'last_name' => $_POST['last_name'],
            'contact_number' => $_POST['contact_number'],
            'address' => $_POST['address'],
            'date_of_birth' => $_POST['date_of_birth'],
            'license_number' => $_POST['license_number'],
            'license_expiry' => $_POST['license_expiry'],
            'association_id' => $_POST['association_id'] ?: null,
            'operator_id' => $_POST['operator_id'],
            'vehicle_number' => $_POST['vehicle_number'],
            'route_id' => $_POST['route_id'],
            'status' => $_POST['status'],
            'years_of_service' => $_POST['years_of_service'] ?: 0
        ]);
        
        $_SESSION['success'] = "Driver added successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error adding driver: " . $e->getMessage();
    }
}

// Function to edit driver
function editDriver($pdo) {
    try {
        $sql = "UPDATE tricycle_drivers SET
            first_name = :first_name,
            middle_name = :middle_name,
            last_name = :last_name,
            contact_number = :contact_number,
            address = :address,
            date_of_birth = :date_of_birth,
            license_number = :license_number,
            license_expiry = :license_expiry,
            association_id = :association_id,
            operator_id = :operator_id,
            vehicle_number = :vehicle_number,
            route_id = :route_id,
            status = :status,
            years_of_service = :years_of_service,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'id' => $_POST['driver_id'],
            'first_name' => $_POST['first_name'],
            'middle_name' => $_POST['middle_name'] ?? null,
            'last_name' => $_POST['last_name'],
            'contact_number' => $_POST['contact_number'],
            'address' => $_POST['address'],
            'date_of_birth' => $_POST['date_of_birth'],
            'license_number' => $_POST['license_number'],
            'license_expiry' => $_POST['license_expiry'],
            'association_id' => $_POST['association_id'] ?: null,
            'operator_id' => $_POST['operator_id'],
            'vehicle_number' => $_POST['vehicle_number'],
            'route_id' => $_POST['route_id'],
            'status' => $_POST['status'],
            'years_of_service' => $_POST['years_of_service'] ?: 0
        ]);
        
        $_SESSION['success'] = "Driver updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error updating driver: " . $e->getMessage();
    }
}

// Function to delete driver
function deleteDriver($pdo) {
    try {
        $sql = "DELETE FROM tricycle_drivers WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $_POST['driver_id']]);
        $_SESSION['success'] = "Driver deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error deleting driver: " . $e->getMessage();
    }
}

// Function to add association
function addAssociation($pdo) {
    try {
        $sql = "INSERT INTO tricycle_associations (
            association_code, association_name, description, barangay_id, 
            contact_person, contact_number, address, total_members, status
        ) VALUES (
            :association_code, :association_name, :description, :barangay_id,
            :contact_person, :contact_number, :address, :total_members, :status
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'association_code' => $_POST['association_code'],
            'association_name' => $_POST['association_name'],
            'description' => $_POST['description'] ?? null,
            'barangay_id' => $_POST['barangay_id'],
            'contact_person' => $_POST['contact_person'],
            'contact_number' => $_POST['contact_number'],
            'address' => $_POST['address'],
            'total_members' => $_POST['total_members'] ?: 0,
            'status' => $_POST['status']
        ]);
        
        $_SESSION['success'] = "Association added successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error adding association: " . $e->getMessage();
    }
}

// Function to edit association
function editAssociation($pdo) {
    try {
        $sql = "UPDATE tricycle_associations SET
            association_name = :association_name,
            description = :description,
            barangay_id = :barangay_id,
            contact_person = :contact_person,
            contact_number = :contact_number,
            address = :address,
            total_members = :total_members,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'id' => $_POST['association_id'],
            'association_name' => $_POST['association_name'],
            'description' => $_POST['description'] ?? null,
            'barangay_id' => $_POST['barangay_id'],
            'contact_person' => $_POST['contact_person'],
            'contact_number' => $_POST['contact_number'],
            'address' => $_POST['address'],
            'total_members' => $_POST['total_members'] ?: 0,
            'status' => $_POST['status']
        ]);
        
        $_SESSION['success'] = "Association updated successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error updating association: " . $e->getMessage();
    }
}

// Function to delete association
function deleteAssociation($pdo) {
    try {
        // First, remove association from drivers
        $updateSql = "UPDATE tricycle_drivers SET association_id = NULL WHERE association_id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute(['id' => $_POST['association_id']]);
        
        // Then delete the association
        $sql = "DELETE FROM tricycle_associations WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $_POST['association_id']]);
        
        $_SESSION['success'] = "Association deleted successfully!";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error deleting association: " . $e->getMessage();
    }
}

// Fetch data for display
try {
    // Fetch all drivers with related data
    $driversSql = "SELECT d.*, a.association_name, o.operator_code, o.first_name as operator_first_name, 
                   o.last_name as operator_last_name, r.route_name, b.name as barangay_name
                   FROM tricycle_drivers d
                   LEFT JOIN tricycle_associations a ON d.association_id = a.id
                   LEFT JOIN tricycle_operators o ON d.operator_id = o.id
                   LEFT JOIN tricycle_routes r ON d.route_id = r.id
                   LEFT JOIN barangays b ON r.barangay_id = b.id
                   ORDER BY d.created_at DESC";
    $driversStmt = $pdo->query($driversSql);
    $drivers = $driversStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch all associations
    $associationsSql = "SELECT a.*, b.name as barangay_name 
                        FROM tricycle_associations a
                        LEFT JOIN barangays b ON a.barangay_id = b.id
                        ORDER BY a.created_at DESC";
    $associationsStmt = $pdo->query($associationsSql);
    $associations = $associationsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch barangays for dropdowns
    $barangaysSql = "SELECT * FROM barangays ORDER BY name";
    $barangaysStmt = $pdo->query($barangaysSql);
    $barangays = $barangaysStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch operators for dropdowns
    $operatorsSql = "SELECT * FROM tricycle_operators WHERE status = 'Active' ORDER BY first_name, last_name";
    $operatorsStmt = $pdo->query($operatorsSql);
    $operators = $operatorsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch routes for dropdowns
    $routesSql = "SELECT * FROM tricycle_routes WHERE status = 'Active' ORDER BY route_name";
    $routesStmt = $pdo->query($routesSql);
    $routes = $routesStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $drivers = [];
    $associations = [];
    $barangays = [];
    $operators = [];
    $routes = [];
}

// Generate unique codes
function generateDriverCode() {
    return 'DRV-' . strtoupper(uniqid());
}

function generateAssociationCode() {
    return 'ASSOC-' . strtoupper(uniqid());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver & Franchise Records - Traffic & Transport Management</title>
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
        
        /* Sidebar - Same as your existing sidebar */
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
        
        /* Tabs */
        .tabs-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }
        
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border-color);
        }
        
        .tab {
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        
        .tab:hover {
            color: var(--primary-color);
        }
        
        .tab.active {
            color: var(--primary-color);
        }
        
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-color: var(--primary-color);
            border-radius: 3px 3px 0 0;
        }
        
        .tab-content {
            display: none;
            padding: 24px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Cards */
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
        }
        
        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 16px;
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
        
        /* Status Badges */
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-active {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .status-suspended {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-suspended {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .status-expired {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .status-expired {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .status-on-leave {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .status-on-leave {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        
        .edit-btn {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .edit-btn:hover {
            background-color: #bfdbfe;
        }
        
        .delete-btn {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .delete-btn:hover {
            background-color: #fecaca;
        }
        
        /* Forms */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .form-group {
            margin-bottom: 16px;
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
        
        .full-width {
            grid-column: 1 / -1;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            color: var(--border-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .page-content {
                padding: 16px;
            }
            
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
                justify-content: flex-end;
            }
            
            .time-display {
                min-width: 140px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                border-bottom: 1px solid var(--border-color);
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
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
                    <a href="../admin_dashboard.php" class="menu-item">
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
                        <a href="../lrcr/admin_road_condition.php" class="submenu-item">Road Condition Reports</a>
                        <a href="../lrcr/action_ass.php" class="submenu-item">Action Assignment</a>
                        <a href="../lrcr/admin_road_condition_analytics.php" class="submenu-item">Historical Records & Analytics</a>
                    </div>
                    
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('road-monitoring')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-monitoring" class="submenu active">
                         <a href="tricycle_route_management.php" class="submenu-item ">Route Management</a>
                        <a href="driver_franchise_records.php" class="submenu-item active">Driver & Franchise Records</a>
                        <a href="approval_enforcement.php" class="submenu-item ">Enforcement Rules & Analytics</a>
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
                            <input type="text" placeholder="Search drivers, associations..." class="search-input" id="globalSearch">
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
            
            <!-- Page Content -->
            <div class="page-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Driver & Franchise Records</h1>
                        <p class="page-subtitle">Manage tricycle drivers, operators, and associations in your barangay</p>
                    </div>
                    <div class="page-actions">
                        <button class="primary-button" onclick="showAddDriverModal()">
                            <i class='bx bx-plus'></i>
                            Add Driver
                        </button>
                        <button class="secondary-button" onclick="showAddAssociationModal()">
                            <i class='bx bx-building'></i>
                            Add Association
                        </button>
                    </div>
                </div>
                
                <!-- Stats Overview -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($drivers); ?></div>
                        <div class="stat-label">Total Drivers</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count(array_filter($drivers, fn($d) => $d['status'] === 'Active')); ?></div>
                        <div class="stat-label">Active Drivers</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count($associations); ?></div>
                        <div class="stat-label">Associations</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo count(array_filter($associations, fn($a) => $a['status'] === 'Active')); ?></div>
                        <div class="stat-label">Active Associations</div>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="tabs-container">
                    <div class="tabs">
                        <button class="tab active" onclick="switchTab('drivers')">Tricycle Drivers</button>
                        <button class="tab" onclick="switchTab('associations')">Associations</button>
                        <button class="tab" onclick="switchTab('operators')">Operators</button>
                    </div>
                    
                    <!-- Drivers Tab -->
                    <div id="drivers-tab" class="tab-content active">
                        <div class="card">
                            <h2 class="card-title">Registered Tricycle Drivers</h2>
                            
                            <?php if (!empty($drivers)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Driver Code</th>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>License</th>
                                            <th>Association</th>
                                            <th>Route</th>
                                            <th>Vehicle</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($drivers as $driver): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($driver['driver_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($driver['contact_number']); ?></td>
                                                <td><?php echo htmlspecialchars($driver['license_number']); ?><br>
                                                    <small>Exp: <?php echo date('M d, Y', strtotime($driver['license_expiry'])); ?></small>
                                                </td>
                                                <td><?php echo $driver['association_name'] ? htmlspecialchars($driver['association_name']) : '<em>None</em>'; ?></td>
                                                <td><?php echo htmlspecialchars($driver['route_name']); ?></td>
                                                <td><?php echo htmlspecialchars($driver['vehicle_number']); ?></td>
                                                <td>
                                                    <?php 
                                                    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $driver['status']));
                                                    echo '<span class="status-badge ' . $statusClass . '">' . htmlspecialchars($driver['status']) . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="action-btn edit-btn" onclick="editDriver(<?php echo $driver['id']; ?>)">
                                                            <i class='bx bx-edit'></i> Edit
                                                        </button>
                                                        <button class="action-btn delete-btn" onclick="deleteDriver(<?php echo $driver['id']; ?>, '<?php echo htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']); ?>')">
                                                            <i class='bx bx-trash'></i> Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class='bx bx-user-x'></i>
                                    <h3>No drivers found</h3>
                                    <p>Add your first tricycle driver to get started.</p>
                                    <button class="primary-button" onclick="showAddDriverModal()" style="margin-top: 16px;">
                                        <i class='bx bx-plus'></i>
                                        Add Driver
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Associations Tab -->
                    <div id="associations-tab" class="tab-content">
                        <div class="card">
                            <h2 class="card-title">Tricycle Associations</h2>
                            
                            <?php if (!empty($associations)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Association Code</th>
                                            <th>Name</th>
                                            <th>Barangay</th>
                                            <th>Contact Person</th>
                                            <th>Members</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($associations as $association): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($association['association_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($association['association_name']); ?></td>
                                                <td><?php echo htmlspecialchars($association['barangay_name']); ?></td>
                                                <td><?php echo htmlspecialchars($association['contact_person']); ?><br>
                                                    <small><?php echo htmlspecialchars($association['contact_number']); ?></small>
                                                </td>
                                                <td><?php echo htmlspecialchars($association['total_members']); ?> members</td>
                                                <td>
                                                    <?php 
                                                    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $association['status']));
                                                    echo '<span class="status-badge ' . $statusClass . '">' . htmlspecialchars($association['status']) . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="action-btn edit-btn" onclick="editAssociation(<?php echo $association['id']; ?>)">
                                                            <i class='bx bx-edit'></i> Edit
                                                        </button>
                                                        <button class="action-btn delete-btn" onclick="deleteAssociation(<?php echo $association['id']; ?>, '<?php echo htmlspecialchars($association['association_name']); ?>')">
                                                            <i class='bx bx-trash'></i> Delete
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class='bx bx-building'></i>
                                    <h3>No associations found</h3>
                                    <p>Add your first tricycle association to get started.</p>
                                    <button class="primary-button" onclick="showAddAssociationModal()" style="margin-top: 16px;">
                                        <i class='bx bx-plus'></i>
                                        Add Association
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Operators Tab -->
                    <div id="operators-tab" class="tab-content">
                        <div class="card">
                            <h2 class="card-title">Tricycle Operators</h2>
                            
                            <?php if (!empty($operators)): ?>
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Operator Code</th>
                                            <th>Name</th>
                                            <th>Contact</th>
                                            <th>Vehicle</th>
                                            <th>Franchise No.</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($operators as $operator): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($operator['operator_code']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($operator['first_name'] . ' ' . $operator['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($operator['contact_number']); ?></td>
                                                <td><?php echo htmlspecialchars($operator['vehicle_number']); ?></td>
                                                <td><?php echo htmlspecialchars($operator['franchise_number']); ?><br>
                                                    <small>Exp: <?php echo date('M d, Y', strtotime($operator['franchise_expiry'])); ?></small>
                                                </td>
                                                <td>
                                                    <?php 
                                                    $statusClass = 'status-' . strtolower(str_replace(' ', '-', $operator['status']));
                                                    echo '<span class="status-badge ' . $statusClass . '">' . htmlspecialchars($operator['status']) . '</span>';
                                                    ?>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button class="action-btn edit-btn" onclick="viewOperatorDetails(<?php echo $operator['id']; ?>)">
                                                            <i class='bx bx-show'></i> View
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php else: ?>
                                <div class="empty-state">
                                    <i class='bx bx-user'></i>
                                    <h3>No operators found</h3>
                                    <p>No tricycle operators are currently registered.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add Driver Modal -->
    <div id="addDriverModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div class="modal-content" style="background-color: var(--card-bg); margin: 5% auto; padding: 20px; border-radius: 12px; width: 80%; max-width: 800px; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 24px; font-weight: 600;">Add New Driver</h2>
                <button onclick="closeModal('addDriverModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            
            <form id="driverForm" method="POST" action="">
                <input type="hidden" name="action" value="add_driver">
                <input type="hidden" name="driver_id" id="driverId">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Driver Code *</label>
                        <input type="text" name="driver_code" id="driverCode" class="form-control" required 
                               value="<?php echo generateDriverCode(); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Number *</label>
                        <input type="tel" name="contact_number" class="form-control" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Address *</label>
                        <textarea name="address" class="form-control" rows="2" required></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Date of Birth *</label>
                        <input type="date" name="date_of_birth" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">License Number *</label>
                        <input type="text" name="license_number" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">License Expiry *</label>
                        <input type="date" name="license_expiry" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Association</label>
                        <select name="association_id" class="form-control">
                            <option value="">Select Association</option>
                            <?php foreach ($associations as $association): ?>
                                <option value="<?php echo $association['id']; ?>">
                                    <?php echo htmlspecialchars($association['association_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Operator *</label>
                        <select name="operator_id" class="form-control" required>
                            <option value="">Select Operator</option>
                            <?php foreach ($operators as $operator): ?>
                                <option value="<?php echo $operator['id']; ?>">
                                    <?php echo htmlspecialchars($operator['first_name'] . ' ' . $operator['last_name'] . ' (' . $operator['vehicle_number'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Vehicle Number *</label>
                        <input type="text" name="vehicle_number" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Route *</label>
                        <select name="route_id" class="form-control" required>
                            <option value="">Select Route</option>
                            <?php foreach ($routes as $route): ?>
                                <option value="<?php echo $route['id']; ?>">
                                    <?php echo htmlspecialchars($route['route_name'] . ' (' . $route['route_code'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="Active">Active</option>
                            <option value="Suspended">Suspended</option>
                            <option value="Expired">Expired</option>
                            <option value="On Leave">On Leave</option>
                            <option value="Blacklisted">Blacklisted</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Years of Service</label>
                        <input type="number" name="years_of_service" class="form-control" min="0" max="50" value="0">
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeModal('addDriverModal')">Cancel</button>
                    <button type="submit" class="primary-button">Save Driver</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Association Modal -->
    <div id="addAssociationModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
        <div class="modal-content" style="background-color: var(--card-bg); margin: 5% auto; padding: 20px; border-radius: 12px; width: 80%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 24px; font-weight: 600;">Add New Association</h2>
                <button onclick="closeModal('addAssociationModal')" style="background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-light);">&times;</button>
            </div>
            
            <form id="associationForm" method="POST" action="">
                <input type="hidden" name="action" value="add_association">
                <input type="hidden" name="association_id" id="associationId">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Association Code *</label>
                        <input type="text" name="association_code" id="associationCode" class="form-control" required 
                               value="<?php echo generateAssociationCode(); ?>" readonly>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Association Name *</label>
                        <input type="text" name="association_name" class="form-control" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Barangay *</label>
                        <select name="barangay_id" class="form-control" required>
                            <option value="">Select Barangay</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>">
                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Person *</label>
                        <input type="text" name="contact_person" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Contact Number *</label>
                        <input type="tel" name="contact_number" class="form-control" required>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Total Members</label>
                        <input type="number" name="total_members" class="form-control" min="0" value="0">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Status *</label>
                        <select name="status" class="form-control" required>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                            <option value="Suspended">Suspended</option>
                            <option value="Under Review">Under Review</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeModal('addAssociationModal')">Cancel</button>
                    <button type="submit" class="primary-button">Save Association</button>
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
                document.querySelectorAll('.menu-item').forEach(i => {
                    i.classList.remove('active');
                });
                this.classList.add('active');
            });
        });
        
        // Set active submenu item
        document.querySelectorAll('.submenu-item').forEach(item => {
            item.addEventListener('click', function() {
                document.querySelectorAll('.submenu-item').forEach(i => {
                    i.classList.remove('active');
                });
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
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Activate selected tab button
            document.querySelectorAll('.tab').forEach(tab => {
                if (tab.textContent.includes(tabName.charAt(0).toUpperCase() + tabName.slice(1))) {
                    tab.classList.add('active');
                }
            });
        }
        
        // Modal functions
        function showAddDriverModal() {
            document.getElementById('addDriverModal').style.display = 'block';
            document.getElementById('driverForm').reset();
            document.getElementById('driverCode').value = 'DRV-' + Math.random().toString(36).substr(2, 9).toUpperCase();
            document.getElementById('driverId').value = '';
            document.getElementById('driverForm').querySelector('input[name="action"]').value = 'add_driver';
        }
        
        function showAddAssociationModal() {
            document.getElementById('addAssociationModal').style.display = 'block';
            document.getElementById('associationForm').reset();
            document.getElementById('associationCode').value = 'ASSOC-' + Math.random().toString(36).substr(2, 9).toUpperCase();
            document.getElementById('associationId').value = '';
            document.getElementById('associationForm').querySelector('input[name="action"]').value = 'add_association';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Edit driver
        async function editDriver(driverId) {
            try {
                const response = await fetch(`api/get_driver.php?id=${driverId}`);
                const driver = await response.json();
                
                if (driver) {
                    // Populate form
                    const form = document.getElementById('driverForm');
                    form.querySelector('input[name="action"]').value = 'edit_driver';
                    form.querySelector('input[name="driver_id"]').value = driver.id;
                    form.querySelector('input[name="driver_code"]').value = driver.driver_code;
                    form.querySelector('input[name="first_name"]').value = driver.first_name;
                    form.querySelector('input[name="middle_name"]').value = driver.middle_name || '';
                    form.querySelector('input[name="last_name"]').value = driver.last_name;
                    form.querySelector('input[name="contact_number"]').value = driver.contact_number;
                    form.querySelector('textarea[name="address"]').value = driver.address;
                    form.querySelector('input[name="date_of_birth"]').value = driver.date_of_birth;
                    form.querySelector('input[name="license_number"]').value = driver.license_number;
                    form.querySelector('input[name="license_expiry"]').value = driver.license_expiry;
                    form.querySelector('select[name="association_id"]').value = driver.association_id || '';
                    form.querySelector('select[name="operator_id"]').value = driver.operator_id;
                    form.querySelector('input[name="vehicle_number"]').value = driver.vehicle_number;
                    form.querySelector('select[name="route_id"]').value = driver.route_id;
                    form.querySelector('select[name="status"]').value = driver.status;
                    form.querySelector('input[name="years_of_service"]').value = driver.years_of_service;
                    
                    // Show modal
                    document.getElementById('addDriverModal').style.display = 'block';
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load driver data: ' + error.message
                });
            }
        }
        
        // Edit association
        async function editAssociation(associationId) {
            try {
                const response = await fetch(`api/get_association.php?id=${associationId}`);
                const association = await response.json();
                
                if (association) {
                    // Populate form
                    const form = document.getElementById('associationForm');
                    form.querySelector('input[name="action"]').value = 'edit_association';
                    form.querySelector('input[name="association_id"]').value = association.id;
                    form.querySelector('input[name="association_code"]').value = association.association_code;
                    form.querySelector('input[name="association_name"]').value = association.association_name;
                    form.querySelector('textarea[name="description"]').value = association.description || '';
                    form.querySelector('select[name="barangay_id"]').value = association.barangay_id;
                    form.querySelector('input[name="contact_person"]').value = association.contact_person;
                    form.querySelector('input[name="contact_number"]').value = association.contact_number;
                    form.querySelector('textarea[name="address"]').value = association.address || '';
                    form.querySelector('input[name="total_members"]').value = association.total_members;
                    form.querySelector('select[name="status"]').value = association.status;
                    
                    // Show modal
                    document.getElementById('addAssociationModal').style.display = 'block';
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load association data: ' + error.message
                });
            }
        }
        
        // Delete driver with confirmation
        function deleteDriver(driverId, driverName) {
            Swal.fire({
                title: 'Delete Driver',
                text: `Are you sure you want to delete ${driverName}? This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_driver';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'driver_id';
                    idInput.value = driverId;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // Delete association with confirmation
        function deleteAssociation(associationId, associationName) {
            Swal.fire({
                title: 'Delete Association',
                text: `Are you sure you want to delete ${associationName}? All drivers in this association will be unassigned.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';
                    
                    const actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'action';
                    actionInput.value = 'delete_association';
                    
                    const idInput = document.createElement('input');
                    idInput.type = 'hidden';
                    idInput.name = 'association_id';
                    idInput.value = associationId;
                    
                    form.appendChild(actionInput);
                    form.appendChild(idInput);
                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }
        
        // View operator details
        function viewOperatorDetails(operatorId) {
            // Fetch and show operator details
            fetch(`api/get_operator.php?id=${operatorId}`)
                .then(response => response.json())
                .then(operator => {
                    Swal.fire({
                        title: operator.first_name + ' ' + operator.last_name,
                        html: `
                            <div style="text-align: left;">
                                <p><strong>Operator Code:</strong> ${operator.operator_code}</p>
                                <p><strong>Contact:</strong> ${operator.contact_number}</p>
                                <p><strong>Address:</strong> ${operator.address}</p>
                                <p><strong>Vehicle Number:</strong> ${operator.vehicle_number}</p>
                                <p><strong>Franchise Number:</strong> ${operator.franchise_number}</p>
                                <p><strong>Franchise Expiry:</strong> ${new Date(operator.franchise_expiry).toLocaleDateString()}</p>
                                <p><strong>Status:</strong> ${operator.status}</p>
                            </div>
                        `,
                        icon: 'info'
                    });
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load operator details'
                    });
                });
        }
        
        // Global search functionality
        document.getElementById('globalSearch').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const tables = document.querySelectorAll('.data-table tbody');
            
            tables.forEach(table => {
                const rows = table.querySelectorAll('tr');
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(searchTerm) ? '' : 'none';
                });
            });
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        };
        
        // Show success/error messages
        <?php if (isset($_SESSION['success'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success']; ?>',
                timer: 3000
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
    </script>
</body>
</html>