<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is EMPLOYEE
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'EMPLOYEE') {
    header("Location: ../login.php");
    exit();
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
    } else {
        $sql = "SELECT * FROM users WHERE is_verified = 1 LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Get user data
$user_id = $_SESSION['user_id'];
$user = getUserData($pdo, $user_id);

// If no user found, create a fallback user
if (!$user) {
    $user = [
        'first_name' => 'Staff',
        'middle_name' => '',
        'last_name' => 'User',
        'email' => 'staff@commonwealth.com',
        'role' => 'EMPLOYEE'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page
$current_page = 'route-management';

// Initialize variables
$success_message = '';
$error_message = '';
$routes = [];
$terminals = [];
$loading_zones = [];
$barangays = [];

// Fetch barangays for dropdown
try {
    $stmt = $pdo->query("SELECT * FROM barangays ORDER BY name");
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error loading barangays: " . $e->getMessage();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        // Add new route
        if (isset($_POST['add_route'])) {
            $route_code = 'TR-' . strtoupper(uniqid());
            $route_name = $_POST['route_name'];
            $description = $_POST['description'];
            $start_point = $_POST['start_point'];
            $end_point = $_POST['end_point'];
            $barangay_id = $_POST['barangay_id'];
            $distance_km = $_POST['distance_km'];
            $estimated_time_min = $_POST['estimated_time_min'];
            $fare_regular = $_POST['fare_regular'];
            $fare_special = $_POST['fare_special'];
            $operating_hours_start = $_POST['operating_hours_start'];
            $operating_hours_end = $_POST['operating_hours_end'];
            $resolution_number = $_POST['resolution_number'];
            $resolution_date = $_POST['resolution_date'];
            $max_vehicles = $_POST['max_vehicles'];
            
            $sql = "INSERT INTO tricycle_routes 
                    (route_code, route_name, description, start_point, end_point, barangay_id, 
                     distance_km, estimated_time_min, fare_regular, fare_special, 
                     operating_hours_start, operating_hours_end, resolution_number, 
                     resolution_date, max_vehicles, status, created_by) 
                    VALUES 
                    (:route_code, :route_name, :description, :start_point, :end_point, :barangay_id,
                     :distance_km, :estimated_time_min, :fare_regular, :fare_special,
                     :operating_hours_start, :operating_hours_end, :resolution_number,
                     :resolution_date, :max_vehicles, 'Under Review', :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'route_code' => $route_code,
                'route_name' => $route_name,
                'description' => $description,
                'start_point' => $start_point,
                'end_point' => $end_point,
                'barangay_id' => $barangay_id,
                'distance_km' => $distance_km,
                'estimated_time_min' => $estimated_time_min,
                'fare_regular' => $fare_regular,
                'fare_special' => $fare_special,
                'operating_hours_start' => $operating_hours_start,
                'operating_hours_end' => $operating_hours_end,
                'resolution_number' => $resolution_number,
                'resolution_date' => $resolution_date,
                'max_vehicles' => $max_vehicles,
                'created_by' => $user_id
            ]);
            
            $route_id = $pdo->lastInsertId();
            
            // Log the creation
            $log_sql = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                       VALUES (:route_id, 'Created', 'New route created by staff', :acted_by)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
            
            // Set success message for SweetAlert
            $_SESSION['success_message'] = "Route added successfully! Route Code: " . $route_code;
            $_SESSION['success_type'] = 'route';
        }
        
        // Add new terminal
        elseif (isset($_POST['add_terminal'])) {
            $terminal_code = 'TERM-' . strtoupper(uniqid());
            $terminal_name = $_POST['terminal_name'];
            $terminal_type = $_POST['terminal_type'];
            $barangay_id = $_POST['barangay_id'];
            $location = $_POST['location'];
            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];
            $capacity = $_POST['capacity'];
            $operating_hours = $_POST['operating_hours'];
            $contact_person = $_POST['contact_person'];
            $contact_number = $_POST['contact_number'];
            $attendant_name = $_POST['attendant_name'];
            $attendant_contact = $_POST['attendant_contact'];
            $amenities = $_POST['amenities'];
            
            $sql = "INSERT INTO route_terminals 
                    (terminal_code, terminal_name, terminal_type, barangay_id, location,
                     latitude, longitude, capacity, operating_hours, contact_person,
                     contact_number, attendant_name, attendant_contact, amenities,
                     status, created_by) 
                    VALUES 
                    (:terminal_code, :terminal_name, :terminal_type, :barangay_id, :location,
                     :latitude, :longitude, :capacity, :operating_hours, :contact_person,
                     :contact_number, :attendant_name, :attendant_contact, :amenities,
                     'Operational', :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'terminal_code' => $terminal_code,
                'terminal_name' => $terminal_name,
                'terminal_type' => $terminal_type,
                'barangay_id' => $barangay_id,
                'location' => $location,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'capacity' => $capacity,
                'operating_hours' => $operating_hours,
                'contact_person' => $contact_person,
                'contact_number' => $contact_number,
                'attendant_name' => $attendant_name,
                'attendant_contact' => $attendant_contact,
                'amenities' => $amenities,
                'created_by' => $user_id
            ]);
            
            // Set success message for SweetAlert
            $_SESSION['success_message'] = "Terminal added successfully! Terminal Code: " . $terminal_code;
            $_SESSION['success_type'] = 'terminal';
        }
        
        // Add new loading zone
        elseif (isset($_POST['add_loading_zone'])) {
            $zone_code = 'LZ-' . strtoupper(uniqid());
            $zone_name = $_POST['zone_name'];
            $barangay_id = $_POST['barangay_id'];
            $location = $_POST['location'];
            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];
            $capacity = $_POST['capacity'];
            $allowed_vehicle_types = $_POST['allowed_vehicle_types'];
            $operating_hours = $_POST['operating_hours'];
            $restrictions = $_POST['restrictions'];
            
            $sql = "INSERT INTO loading_zones 
                    (zone_code, zone_name, barangay_id, location, latitude, longitude,
                     capacity, allowed_vehicle_types, operating_hours, restrictions,
                     status, created_by) 
                    VALUES 
                    (:zone_code, :zone_name, :barangay_id, :location, :latitude, :longitude,
                     :capacity, :allowed_vehicle_types, :operating_hours, :restrictions,
                     'Active', :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'zone_code' => $zone_code,
                'zone_name' => $zone_name,
                'barangay_id' => $barangay_id,
                'location' => $location,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'capacity' => $capacity,
                'allowed_vehicle_types' => $allowed_vehicle_types,
                'operating_hours' => $operating_hours,
                'restrictions' => $restrictions,
                'created_by' => $user_id
            ]);
            
            // Set success message for SweetAlert
            $_SESSION['success_message'] = "Loading zone added successfully! Zone Code: " . $zone_code;
            $_SESSION['success_type'] = 'zone';
        }
        
        // Add route stops
        elseif (isset($_POST['add_stop'])) {
            $route_id = $_POST['route_id'];
            $stop_name = $_POST['stop_name'];
            $location = $_POST['location'];
            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];
            $landmarks = $_POST['landmarks'];
            $estimated_time = $_POST['estimated_time'];
            $fare_from_start = $_POST['fare_from_start'];
            $is_terminal = isset($_POST['is_terminal']) ? 1 : 0;
            $terminal_id = $_POST['terminal_id'] ?? null;
            
            // Get current max stop number for this route
            $sql = "SELECT MAX(stop_number) as max_stop FROM route_stops WHERE route_id = :route_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['route_id' => $route_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stop_number = ($result['max_stop'] ?: 0) + 1;
            
            $sql = "INSERT INTO route_stops 
                    (route_id, stop_number, stop_name, location, latitude, longitude,
                     landmarks, estimated_time_from_start, fare_from_start, is_terminal, terminal_id) 
                    VALUES 
                    (:route_id, :stop_number, :stop_name, :location, :latitude, :longitude,
                     :landmarks, :estimated_time, :fare_from_start, :is_terminal, :terminal_id)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'route_id' => $route_id,
                'stop_number' => $stop_number,
                'stop_name' => $stop_name,
                'location' => $location,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'landmarks' => $landmarks,
                'estimated_time' => $estimated_time,
                'fare_from_start' => $fare_from_start,
                'is_terminal' => $is_terminal,
                'terminal_id' => $terminal_id
            ]);
            
            // Set success message for SweetAlert
            $_SESSION['success_message'] = "Route stop added successfully!";
            $_SESSION['success_type'] = 'stop';
            
            // Redirect to prevent form resubmission
            header("Location: staff_route_management.php");
            exit();
        }
        
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Fetch existing data
try {
    // Fetch routes
    $stmt = $pdo->query("SELECT tr.*, b.name as barangay_name 
                         FROM tricycle_routes tr 
                         LEFT JOIN barangays b ON tr.barangay_id = b.id 
                         ORDER BY tr.created_at DESC");
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch terminals
    $stmt = $pdo->query("SELECT rt.*, b.name as barangay_name 
                         FROM route_terminals rt 
                         LEFT JOIN barangays b ON rt.barangay_id = b.id 
                         ORDER BY rt.created_at DESC");
    $terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch loading zones if table exists
    $stmt = $pdo->query("SHOW TABLES LIKE 'loading_zones'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("SELECT lz.*, b.name as barangay_name 
                            FROM loading_zones lz 
                            LEFT JOIN barangays b ON lz.barangay_id = b.id 
                            ORDER BY lz.created_at DESC");
        $loading_zones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $error_message .= " Error loading data: " . $e->getMessage();
}

// Function to get route stops
function getRouteStops($pdo, $route_id) {
    $sql = "SELECT * FROM route_stops WHERE route_id = :route_id ORDER BY stop_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['route_id' => $route_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Check for success message from session
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    $success_type = $_SESSION['success_type'] ?? '';
    unset($_SESSION['success_message']);
    unset($_SESSION['success_type']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route & Terminal Management - Staff Module</title>
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
        
        /* Module specific styles */
        .module-content {
            padding: 32px;
        }
        
        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        
        .module-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-color);
        }
        
        .module-subtitle {
            color: var(--text-light);
            margin-top: 8px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }
        
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            border-radius: 8px;
            color: var(--text-light);
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .tab-btn:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .tab-btn:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .tab-btn.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        /* Tab content */
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Forms */
        .form-container {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }
        
        .form-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
            color: var(--text-color);
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
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .required::after {
            content: " *";
            color: #ef4444;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid var(--border-color);
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
            display: flex;
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
        
        /* Data tables */
        .data-table {
            width: 100%;
            background-color: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--border-color);
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
        
        .table-search {
            width: 300px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode thead {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
        }
        
        tbody tr:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode tbody tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        /* Status badges */
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .status-active {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
        }
        
        .status-pending {
            background-color: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .status-inactive {
            background-color: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        .status-review {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        /* Action buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-icon-view {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .btn-icon-edit {
            background-color: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .btn-icon-delete {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .btn-icon:hover {
            opacity: 0.8;
        }
        
        /* Map container */
        .map-container {
            width: 100%;
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            background-color: var(--background-color);
            margin-bottom: 20px;
        }
        
        .map-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
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
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .table-search {
                width: 200px;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--background-color);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-light);
        }
        
        /* Loading */
        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid var(--border-color);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Utility classes */
        .mt-4 { margin-top: 16px; }
        .mb-4 { margin-bottom: 16px; }
        .ml-2 { margin-left: 8px; }
        .text-center { text-align: center; }
        .hidden { display: none; }
        
        /* Dashboard Content specific styles */
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
                <a href="../lrcr/employee_create_report.php" class="submenu-item">Create Report</a>
                <a href="../lrcr/employee_road_status_monitoring.php" class="submenu-item">Action Assignment</a>
            </div>
            
            <!-- 1.2 Barangay Tricycle Route Management -->
            <div class="menu-item active" onclick="toggleSubmenu('road-monitoring')">
                <div class="icon-box icon-box-route-config">
                    <i class='bx bxs-map-alt'></i>
                </div>
                <span class="font-medium">Barangay Tricycle Route Management</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="road-monitoring" class="submenu">
                <a href="staff_route_management.php" class="submenu-item active">Route & Terminal Encoding</a>
                <a href="btrm/staff_complaints.php" class="submenu-item">Complaints Management</a>
                <a href="btrm/staff_violations.php" class="submenu-item">Violations Tracking</a>
                <a href="btrm/staff_reports.php" class="submenu-item">Reports & Analytics</a>
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
                            <input type="text" placeholder="Search routes, terminals, or stops" class="search-input">
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
                                <p class="user-role"><?php echo htmlspecialchars($role); ?> • Staff</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Module Content -->
            <div class="module-content">
                <!-- Module Header -->
                <div class="module-header">
                    <div>
                        <h1 class="module-title">Route & Terminal Management</h1>
                        <p class="module-subtitle">Encode approved routes, register terminals, stops, and loading zones</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="showAddForm()">
                            <span style="font-size: 20px;">+</span>
                            Add New
                        </button>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('routes')">
                        <i class='bx bx-map'></i>
                        Tricycle Routes
                    </button>
                    <button class="tab-btn" onclick="switchTab('terminals')">
                        <i class='bx bx-building'></i>
                        Terminals
                    </button>
                    <button class="tab-btn" onclick="switchTab('loading-zones')">
                        <i class='bx bx-map-pin'></i>
                        Loading Zones
                    </button>
                    <button class="tab-btn" onclick="switchTab('stops')">
                        <i class='bx bx-map-pin'></i>
                        Route Stops
                    </button>
                </div>
                
                <!-- Routes Tab -->
                <div id="routes-tab" class="tab-content active">
                    <!-- Add Route Form -->
                    <div class="form-container">
                        <h3 class="form-title">Add New Tricycle Route</h3>
                        <form method="POST" id="addRouteForm" onsubmit="return validateRouteForm()">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="route_name" class="required">Route Name</label>
                                    <input type="text" id="route_name" name="route_name" required placeholder="e.g., Poblacion-San Isidro Route">
                                </div>
                                
                                <div class="form-group">
                                    <label for="barangay_id" class="required">Barangay</label>
                                    <select id="barangay_id" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="start_point" class="required">Start Point</label>
                                    <input type="text" id="start_point" name="start_point" required placeholder="e.g., Poblacion Market">
                                </div>
                                
                                <div class="form-group">
                                    <label for="end_point" class="required">End Point</label>
                                    <input type="text" id="end_point" name="end_point" required placeholder="e.g., San Isidro Terminal">
                                </div>
                                
                                <div class="form-group">
                                    <label for="distance_km" class="required">Distance (km)</label>
                                    <input type="number" id="distance_km" name="distance_km" step="0.01" required placeholder="4.50">
                                </div>
                                
                                <div class="form-group">
                                    <label for="estimated_time_min" class="required">Estimated Time (minutes)</label>
                                    <input type="number" id="estimated_time_min" name="estimated_time_min" required placeholder="20">
                                </div>
                                
                                <div class="form-group">
                                    <label for="fare_regular" class="required">Regular Fare (₱)</label>
                                    <input type="number" id="fare_regular" name="fare_regular" step="0.01" required placeholder="15.00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="fare_special" class="required">Special Fare (₱)</label>
                                    <input type="number" id="fare_special" name="fare_special" step="0.01" required placeholder="20.00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="operating_hours_start" class="required">Operating Hours Start</label>
                                    <input type="time" id="operating_hours_start" name="operating_hours_start" required value="06:00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="operating_hours_end" class="required">Operating Hours End</label>
                                    <input type="time" id="operating_hours_end" name="operating_hours_end" required value="22:00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="resolution_number">Resolution Number</label>
                                    <input type="text" id="resolution_number" name="resolution_number" placeholder="e.g., RES-2024-001">
                                </div>
                                
                                <div class="form-group">
                                    <label for="resolution_date">Resolution Date</label>
                                    <input type="date" id="resolution_date" name="resolution_date">
                                </div>
                                
                                <div class="form-group">
                                    <label for="max_vehicles">Maximum Vehicles</label>
                                    <input type="number" id="max_vehicles" name="max_vehicles" value="10" min="1">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="description">Description</label>
                                    <textarea id="description" name="description" rows="3" placeholder="Route description..."></textarea>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="add_route" class="btn btn-primary">
                                    <i class='bx bx-save'></i>
                                    Save Route
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Routes List -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3 class="table-title">Existing Routes</h3>
                            <div class="table-search">
                                <input type="text" id="searchRoutes" placeholder="Search routes...">
                            </div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Route Code</th>
                                    <th>Route Name</th>
                                    <th>Barangay</th>
                                    <th>Start - End</th>
                                    <th>Fare</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="routesTableBody">
                                <?php foreach ($routes as $route): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($route['route_code']); ?></td>
                                        <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                                        <td><?php echo htmlspecialchars($route['barangay_name']); ?></td>
                                        <td><?php echo htmlspecialchars($route['start_point'] . ' to ' . $route['end_point']); ?></td>
                                        <td>₱<?php echo number_format($route['fare_regular'], 2); ?></td>
                                        <td>
                                            <?php
                                            $status_class = 'status-pending';
                                            $status_text = 'Under Review';
                                            if ($route['status'] == 'Active') {
                                                $status_class = 'status-active';
                                                $status_text = 'Active';
                                            } elseif ($route['status'] == 'Inactive') {
                                                $status_class = 'status-inactive';
                                                $status_text = 'Inactive';
                                            } elseif ($route['status'] == 'Suspended') {
                                                $status_class = 'status-pending';
                                                $status_text = 'Suspended';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-view" onclick="viewRoute(<?php echo $route['id']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-edit" onclick="editRoute(<?php echo $route['id']; ?>)">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-delete" onclick="deleteRoute(<?php echo $route['id']; ?>, '<?php echo htmlspecialchars($route['route_name']); ?>')">
                                                    <i class='bx bx-trash'></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Terminals Tab -->
                <div id="terminals-tab" class="tab-content">
                    <!-- Add Terminal Form -->
                    <div class="form-container">
                        <h3 class="form-title">Register New Terminal</h3>
                        <form method="POST" id="addTerminalForm" onsubmit="return validateTerminalForm()">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="terminal_name" class="required">Terminal Name</label>
                                    <input type="text" id="terminal_name" name="terminal_name" required placeholder="e.g., Poblacion Main Terminal">
                                </div>
                                
                                <div class="form-group">
                                    <label for="terminal_type" class="required">Terminal Type</label>
                                    <select id="terminal_type" name="terminal_type" required>
                                        <option value="">Select Type</option>
                                        <option value="Main Terminal">Main Terminal</option>
                                        <option value="Secondary Terminal">Secondary Terminal</option>
                                        <option value="Waiting Area">Waiting Area</option>
                                        <option value="Loading Zone">Loading Zone</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="barangay_id_terminal" class="required">Barangay</label>
                                    <select id="barangay_id_terminal" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="location" class="required">Location</label>
                                    <input type="text" id="location" name="location" required placeholder="e.g., Near Public Market, Poblacion">
                                </div>
                                
                                <div class="form-group">
                                    <label for="latitude">Latitude</label>
                                    <input type="number" id="latitude" name="latitude" step="any" placeholder="14.68683200">
                                </div>
                                
                                <div class="form-group">
                                    <label for="longitude">Longitude</label>
                                    <input type="number" id="longitude" name="longitude" step="any" placeholder="121.09662300">
                                </div>
                                
                                <div class="form-group">
                                    <label for="capacity" class="required">Capacity (vehicles)</label>
                                    <input type="number" id="capacity" name="capacity" required placeholder="50">
                                </div>
                                
                                <div class="form-group">
                                    <label for="operating_hours" class="required">Operating Hours</label>
                                    <input type="text" id="operating_hours" name="operating_hours" required placeholder="e.g., 24/7 or 5:00 AM - 10:00 PM">
                                </div>
                                
                                <div class="form-group">
                                    <label for="contact_person">Contact Person</label>
                                    <input type="text" id="contact_person" name="contact_person" placeholder="Juan Dela Cruz">
                                </div>
                                
                                <div class="form-group">
                                    <label for="contact_number">Contact Number</label>
                                    <input type="text" id="contact_number" name="contact_number" placeholder="09123456789">
                                </div>
                                
                                <div class="form-group">
                                    <label for="attendant_name">Attendant Name</label>
                                    <input type="text" id="attendant_name" name="attendant_name" placeholder="Pedro Reyes">
                                </div>
                                
                                <div class="form-group">
                                    <label for="attendant_contact">Attendant Contact</label>
                                    <input type="text" id="attendant_contact" name="attendant_contact" placeholder="09123456791">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="amenities">Amenities</label>
                                    <textarea id="amenities" name="amenities" rows="2" placeholder="e.g., Waiting shed, Restroom, Food stalls"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="add_terminal" class="btn btn-primary">
                                    <i class='bx bx-save'></i>
                                    Save Terminal
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Terminals List -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3 class="table-title">Registered Terminals</h3>
                            <div class="table-search">
                                <input type="text" id="searchTerminals" placeholder="Search terminals...">
                            </div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Terminal Code</th>
                                    <th>Terminal Name</th>
                                    <th>Type</th>
                                    <th>Barangay</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="terminalsTableBody">
                                <?php foreach ($terminals as $terminal): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($terminal['terminal_code']); ?></td>
                                        <td><?php echo htmlspecialchars($terminal['terminal_name']); ?></td>
                                        <td><?php echo htmlspecialchars($terminal['terminal_type']); ?></td>
                                        <td><?php echo htmlspecialchars($terminal['barangay_name']); ?></td>
                                        <td><?php echo htmlspecialchars($terminal['capacity']); ?> vehicles</td>
                                        <td>
                                            <?php
                                            $status_class = 'status-active';
                                            $status_text = 'Operational';
                                            if ($terminal['status'] == 'Maintenance') {
                                                $status_class = 'status-pending';
                                                $status_text = 'Maintenance';
                                            } elseif ($terminal['status'] == 'Closed') {
                                                $status_class = 'status-inactive';
                                                $status_text = 'Closed';
                                            }
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo $status_text; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn-icon btn-icon-view" onclick="viewTerminal(<?php echo $terminal['id']; ?>)">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="btn-icon btn-icon-edit" onclick="editTerminal(<?php echo $terminal['id']; ?>)">
                                                    <i class='bx bx-edit'></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Loading Zones Tab -->
                <div id="loading-zones-tab" class="tab-content">
                    <!-- Add Loading Zone Form -->
                    <div class="form-container">
                        <h3 class="form-title">Add Loading Zone</h3>
                        <form method="POST" id="addLoadingZoneForm" onsubmit="return validateZoneForm()">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="zone_name" class="required">Loading Zone Name</label>
                                    <input type="text" id="zone_name" name="zone_name" required placeholder="e.g., Market Loading Zone">
                                </div>
                                
                                <div class="form-group">
                                    <label for="barangay_id_zone" class="required">Barangay</label>
                                    <select id="barangay_id_zone" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="location_zone" class="required">Location</label>
                                    <input type="text" id="location_zone" name="location" required placeholder="e.g., In front of Public Market">
                                </div>
                                
                                <div class="form-group">
                                    <label for="latitude_zone">Latitude</label>
                                    <input type="number" id="latitude_zone" name="latitude" step="any" placeholder="14.68683200">
                                </div>
                                
                                <div class="form-group">
                                    <label for="longitude_zone">Longitude</label>
                                    <input type="number" id="longitude_zone" name="longitude" step="any" placeholder="121.09662300">
                                </div>
                                
                                <div class="form-group">
                                    <label for="capacity_zone" class="required">Capacity (vehicles)</label>
                                    <input type="number" id="capacity_zone" name="capacity" required placeholder="10">
                                </div>
                                
                                <div class="form-group">
                                    <label for="allowed_vehicle_types">Allowed Vehicle Types</label>
                                    <input type="text" id="allowed_vehicle_types" name="allowed_vehicle_types" value="Tricycle" placeholder="Tricycle, Jeepney, etc.">
                                </div>
                                
                                <div class="form-group">
                                    <label for="operating_hours_zone">Operating Hours</label>
                                    <input type="text" id="operating_hours_zone" name="operating_hours" placeholder="e.g., 6:00 AM - 9:00 PM">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="restrictions">Restrictions</label>
                                    <textarea id="restrictions" name="restrictions" rows="2" placeholder="e.g., No parking beyond 15 minutes, For tricycles only"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="add_loading_zone" class="btn btn-primary">
                                    <i class='bx bx-save'></i>
                                    Save Loading Zone
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Loading Zones List -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3 class="table-title">Loading Zones</h3>
                            <div class="table-search">
                                <input type="text" id="searchZones" placeholder="Search loading zones...">
                            </div>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Zone Code</th>
                                    <th>Zone Name</th>
                                    <th>Barangay</th>
                                    <th>Location</th>
                                    <th>Capacity</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="zonesTableBody">
                                <?php if (!empty($loading_zones)): ?>
                                    <?php foreach ($loading_zones as $zone): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($zone['zone_code']); ?></td>
                                            <td><?php echo htmlspecialchars($zone['zone_name']); ?></td>
                                            <td><?php echo htmlspecialchars($zone['barangay_name']); ?></td>
                                            <td><?php echo htmlspecialchars($zone['location']); ?></td>
                                            <td><?php echo htmlspecialchars($zone['capacity']); ?> vehicles</td>
                                            <td>
                                                <?php
                                                $status_class = 'status-active';
                                                $status_text = 'Active';
                                                if ($zone['status'] == 'Inactive') {
                                                    $status_class = 'status-inactive';
                                                    $status_text = 'Inactive';
                                                } elseif ($zone['status'] == 'Under Maintenance') {
                                                    $status_class = 'status-pending';
                                                    $status_text = 'Maintenance';
                                                }
                                                ?>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon btn-icon-view" onclick="viewZone(<?php echo $zone['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                    </button>
                                                    <button class="btn-icon btn-icon-edit" onclick="editZone(<?php echo $zone['id']; ?>)">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" class="text-center">No loading zones registered yet.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Route Stops Tab -->
                <div id="stops-tab" class="tab-content">
                    <!-- Add Route Stop Form -->
                    <div class="form-container">
                        <h3 class="form-title">Add Route Stop</h3>
                        <form method="POST" id="addStopForm" onsubmit="return validateStopForm()">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="route_id" class="required">Select Route</label>
                                    <select id="route_id" name="route_id" required onchange="loadTerminals()">
                                        <option value="">Select Route</option>
                                        <?php foreach ($routes as $route): ?>
                                            <option value="<?php echo $route['id']; ?>">
                                                <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label for="stop_name" class="required">Stop Name</label>
                                    <input type="text" id="stop_name" name="stop_name" required placeholder="e.g., Market Entrance">
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="location_stop" class="required">Location</label>
                                    <input type="text" id="location_stop" name="location" required placeholder="e.g., Market Entrance, Main Street">
                                </div>
                                
                                <div class="form-group">
                                    <label for="latitude_stop">Latitude</label>
                                    <input type="number" id="latitude_stop" name="latitude" step="any" placeholder="14.68683200">
                                </div>
                                
                                <div class="form-group">
                                    <label for="longitude_stop">Longitude</label>
                                    <input type="number" id="longitude_stop" name="longitude" step="any" placeholder="121.09662300">
                                </div>
                                
                                <div class="form-group">
                                    <label for="estimated_time">Estimated Time from Start (minutes)</label>
                                    <input type="number" id="estimated_time" name="estimated_time" placeholder="5">
                                </div>
                                
                                <div class="form-group">
                                    <label for="fare_from_start">Fare from Start (₱)</label>
                                    <input type="number" id="fare_from_start" name="fare_from_start" step="0.01" placeholder="5.00">
                                </div>
                                
                                <div class="form-group">
                                    <label for="is_terminal">
                                        <input type="checkbox" id="is_terminal" name="is_terminal" onchange="toggleTerminalSelect()">
                                        Is this a terminal?
                                    </label>
                                </div>
                                
                                <div class="form-group" id="terminalSelectGroup" style="display: none;">
                                    <label for="terminal_id">Select Terminal</label>
                                    <select id="terminal_id" name="terminal_id">
                                        <option value="">Select Terminal</option>
                                        <?php foreach ($terminals as $terminal): ?>
                                            <option value="<?php echo $terminal['id']; ?>">
                                                <?php echo htmlspecialchars($terminal['terminal_code'] . ' - ' . $terminal['terminal_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group full-width">
                                    <label for="landmarks">Landmarks</label>
                                    <textarea id="landmarks" name="landmarks" rows="2" placeholder="e.g., Near Public Market, beside Church"></textarea>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="add_stop" class="btn btn-primary">
                                    <i class='bx bx-save'></i>
                                    Add Stop
                                </button>
                                <button type="reset" class="btn btn-secondary">
                                    <i class='bx bx-reset'></i>
                                    Reset Form
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Route Stops List -->
                    <div class="data-table">
                        <div class="table-header">
                            <h3 class="table-title">Route Stops</h3>
                            <div class="table-search">
                                <input type="text" id="searchStops" placeholder="Search stops...">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label for="filterRoute">Filter by Route:</label>
                            <select id="filterRoute" onchange="filterStops()">
                                <option value="">All Routes</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo $route['id']; ?>">
                                        <?php echo htmlspecialchars($route['route_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <table>
                            <thead>
                                <tr>
                                    <th>Stop #</th>
                                    <th>Stop Name</th>
                                    <th>Route</th>
                                    <th>Location</th>
                                    <th>Is Terminal</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="stopsTableBody">
                                <?php foreach ($routes as $route): ?>
                                    <?php $stops = getRouteStops($pdo, $route['id']); ?>
                                    <?php foreach ($stops as $stop): ?>
                                        <tr data-route="<?php echo $route['id']; ?>">
                                            <td><?php echo $stop['stop_number']; ?></td>
                                            <td><?php echo htmlspecialchars($stop['stop_name']); ?></td>
                                            <td><?php echo htmlspecialchars($route['route_code']); ?></td>
                                            <td><?php echo htmlspecialchars($stop['location']); ?></td>
                                            <td>
                                                <?php if ($stop['is_terminal']): ?>
                                                    <span class="status-badge status-active">Terminal</span>
                                                <?php else: ?>
                                                    <span class="status-badge status-inactive">Stop</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="btn-icon btn-icon-view" onclick="viewStop(<?php echo $stop['id']; ?>)">
                                                        <i class='bx bx-show'></i>
                                                    </button>
                                                    <button class="btn-icon btn-icon-edit" onclick="editStop(<?php echo $stop['id']; ?>)">
                                                        <i class='bx bx-edit'></i>
                                                    </button>
                                                    <button class="btn-icon btn-icon-delete" onclick="deleteStop(<?php echo $stop['id']; ?>, '<?php echo htmlspecialchars($stop['stop_name']); ?>')">
                                                        <i class='bx bx-trash'></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        // Show add form with animation
        function showAddForm() {
            const formContainer = document.querySelector('.form-container');
            if (formContainer) {
                formContainer.style.opacity = '0';
                formContainer.style.transform = 'translateY(-20px)';
                formContainer.scrollIntoView({ behavior: 'smooth' });
                
                setTimeout(() => {
                    formContainer.style.opacity = '1';
                    formContainer.style.transform = 'translateY(0)';
                }, 100);
            }
        }
        
        // Toggle terminal select
        function toggleTerminalSelect() {
            const isTerminal = document.getElementById('is_terminal').checked;
            const terminalSelectGroup = document.getElementById('terminalSelectGroup');
            terminalSelectGroup.style.display = isTerminal ? 'block' : 'none';
        }
        
        // Load terminals based on selected route's barangay
        function loadTerminals() {
            const routeId = document.getElementById('route_id').value;
            if (!routeId) return;
            
            // In a real implementation, you would fetch terminals via AJAX
            // based on the selected route's barangay
            console.log('Loading terminals for route:', routeId);
        }
        
        // Filter stops by route
        function filterStops() {
            const routeId = document.getElementById('filterRoute').value;
            const rows = document.querySelectorAll('#stopsTableBody tr');
            
            rows.forEach(row => {
                if (!routeId || row.getAttribute('data-route') == routeId) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }
        
        // Search functionality
        document.getElementById('searchRoutes').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#routesTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        document.getElementById('searchTerminals').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#terminalsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        document.getElementById('searchZones').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#zonesTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        document.getElementById('searchStops').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#stopsTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // View functions with SweetAlert
        function viewRoute(routeId) {
            Swal.fire({
                title: 'Route Details',
                html: '<div class="loading"></div>',
                showConfirmButton: false,
                didOpen: () => {
                    // In real implementation, fetch route details via AJAX
                    setTimeout(() => {
                        Swal.getHtmlContainer().innerHTML = `
                            <div style="text-align: left;">
                                <p><strong>Route Code:</strong> TR-001</p>
                                <p><strong>Route Name:</strong> Poblacion-San Isidro Route</p>
                                <p><strong>Barangay:</strong> Barangay Poblacion</p>
                                <p><strong>Distance:</strong> 4.50 km</p>
                                <p><strong>Fare:</strong> ₱15.00 regular / ₱20.00 special</p>
                                <p><strong>Status:</strong> <span class="status-badge status-active">Active</span></p>
                            </div>
                        `;
                    }, 500);
                }
            });
        }
        
        function viewTerminal(terminalId) {
            Swal.fire({
                title: 'Terminal Details',
                html: '<div class="loading"></div>',
                showConfirmButton: false,
                didOpen: () => {
                    // In real implementation, fetch terminal details via AJAX
                    setTimeout(() => {
                        Swal.getHtmlContainer().innerHTML = `
                            <div style="text-align: left;">
                                <p><strong>Terminal Code:</strong> TERM-001</p>
                                <p><strong>Terminal Name:</strong> Poblacion Main Terminal</p>
                                <p><strong>Type:</strong> Main Terminal</p>
                                <p><strong>Capacity:</strong> 50 vehicles</p>
                                <p><strong>Operating Hours:</strong> 24/7</p>
                                <p><strong>Status:</strong> <span class="status-badge status-active">Operational</span></p>
                            </div>
                        `;
                    }, 500);
                }
            });
        }
        
        function viewZone(zoneId) {
            Swal.fire({
                title: 'Loading Zone Details',
                html: '<div class="loading"></div>',
                showConfirmButton: false,
                didOpen: () => {
                    // In real implementation, fetch zone details via AJAX
                    setTimeout(() => {
                        Swal.getHtmlContainer().innerHTML = `
                            <div style="text-align: left;">
                                <p><strong>Zone Code:</strong> LZ-001</p>
                                <p><strong>Zone Name:</strong> Market Loading Zone</p>
                                <p><strong>Capacity:</strong> 10 vehicles</p>
                                <p><strong>Operating Hours:</strong> 6:00 AM - 9:00 PM</p>
                                <p><strong>Status:</strong> <span class="status-badge status-active">Active</span></p>
                            </div>
                        `;
                    }, 500);
                }
            });
        }
        
        function viewStop(stopId) {
            Swal.fire({
                title: 'Stop Details',
                html: '<div class="loading"></div>',
                showConfirmButton: false,
                didOpen: () => {
                    // In real implementation, fetch stop details via AJAX
                    setTimeout(() => {
                        Swal.getHtmlContainer().innerHTML = `
                            <div style="text-align: left;">
                                <p><strong>Stop Name:</strong> Market Entrance</p>
                                <p><strong>Route:</strong> TR-001</p>
                                <p><strong>Location:</strong> Market Entrance, Main Street</p>
                                <p><strong>Landmarks:</strong> Near Public Market</p>
                                <p><strong>Fare from Start:</strong> ₱0.00</p>
                            </div>
                        `;
                    }, 500);
                }
            });
        }
        
        // Delete functions with SweetAlert confirmation
        function deleteRoute(routeId, routeName) {
            Swal.fire({
                title: 'Delete Route?',
                text: `Are you sure you want to delete "${routeName}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In real implementation, send delete request via AJAX
                    Swal.fire(
                        'Deleted!',
                        'The route has been deleted.',
                        'success'
                    ).then(() => {
                        // Reload the page or remove the row
                        location.reload();
                    });
                }
            });
        }
        
        function deleteStop(stopId, stopName) {
            Swal.fire({
                title: 'Delete Stop?',
                text: `Are you sure you want to delete "${stopName}"?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // In real implementation, send delete request via AJAX
                    Swal.fire(
                        'Deleted!',
                        'The stop has been deleted.',
                        'success'
                    ).then(() => {
                        // Reload the page or remove the row
                        location.reload();
                    });
                }
            });
        }
        
        // Edit functions
        function editRoute(routeId) {
            Swal.fire({
                title: 'Edit Route',
                text: 'Edit functionality would open a form here.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        function editTerminal(terminalId) {
            Swal.fire({
                title: 'Edit Terminal',
                text: 'Edit functionality would open a form here.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        function editZone(zoneId) {
            Swal.fire({
                title: 'Edit Loading Zone',
                text: 'Edit functionality would open a form here.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        function editStop(stopId) {
            Swal.fire({
                title: 'Edit Stop',
                text: 'Edit functionality would open a form here.',
                icon: 'info',
                confirmButtonText: 'OK'
            });
        }
        
        // Form validation functions
        function validateRouteForm() {
            const routeName = document.getElementById('route_name').value;
            const barangayId = document.getElementById('barangay_id').value;
            const startPoint = document.getElementById('start_point').value;
            const endPoint = document.getElementById('end_point').value;
            const distance = document.getElementById('distance_km').value;
            const fareRegular = document.getElementById('fare_regular').value;
            
            if (!routeName || !barangayId || !startPoint || !endPoint || !distance || !fareRegular) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            if (parseFloat(fareRegular) <= 0) {
                Swal.fire({
                    title: 'Invalid Fare',
                    text: 'Fare must be greater than 0.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            return true;
        }
        
        function validateTerminalForm() {
            const terminalName = document.getElementById('terminal_name').value;
            const terminalType = document.getElementById('terminal_type').value;
            const barangayId = document.getElementById('barangay_id_terminal').value;
            const location = document.getElementById('location').value;
            const capacity = document.getElementById('capacity').value;
            
            if (!terminalName || !terminalType || !barangayId || !location || !capacity) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            if (parseInt(capacity) <= 0) {
                Swal.fire({
                    title: 'Invalid Capacity',
                    text: 'Capacity must be greater than 0.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            return true;
        }
        
        function validateZoneForm() {
            const zoneName = document.getElementById('zone_name').value;
            const barangayId = document.getElementById('barangay_id_zone').value;
            const location = document.getElementById('location_zone').value;
            const capacity = document.getElementById('capacity_zone').value;
            
            if (!zoneName || !barangayId || !location || !capacity) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            return true;
        }
        
        function validateStopForm() {
            const routeId = document.getElementById('route_id').value;
            const stopName = document.getElementById('stop_name').value;
            const location = document.getElementById('location_stop').value;
            
            if (!routeId || !stopName || !location) {
                Swal.fire({
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.',
                    icon: 'warning',
                    confirmButtonText: 'OK'
                });
                return false;
            }
            
            return true;
        }
        
        // Initialize date fields with today's date
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const resolutionDate = document.getElementById('resolution_date');
            if (resolutionDate) {
                resolutionDate.value = today;
                resolutionDate.max = today;
            }
            
            // Show success message if exists
            <?php if ($success_message): ?>
                Swal.fire({
                    title: 'Success!',
                    text: '<?php echo addslashes($success_message); ?>',
                    icon: 'success',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
            
            // Show error message if exists
            <?php if ($error_message): ?>
                Swal.fire({
                    title: 'Error!',
                    text: '<?php echo addslashes($error_message); ?>',
                    icon: 'error',
                    confirmButtonText: 'OK'
                });
            <?php endif; ?>
        });
        
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
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
            const timeDisplay = document.getElementById('current-time');
            if (timeDisplay) {
                timeDisplay.textContent = timeString;
            }
        }
        
        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);
    </script>
</body>
</html>