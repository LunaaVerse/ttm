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

// Check if user is ADMIN
if ($user['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'tricycle-routes';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new route
    if (isset($_POST['add_route'])) {
        try {
            $sql = "INSERT INTO tricycle_routes (route_code, route_name, description, start_point, end_point, barangay_id, distance_km, estimated_time_min, fare_regular, fare_special, operating_hours_start, operating_hours_end, status, created_by) 
                    VALUES (:route_code, :route_name, :description, :start_point, :end_point, :barangay_id, :distance_km, :estimated_time_min, :fare_regular, :fare_special, :operating_hours_start, :operating_hours_end, :status, :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'route_code' => $_POST['route_code'],
                'route_name' => $_POST['route_name'],
                'description' => $_POST['description'],
                'start_point' => $_POST['start_point'],
                'end_point' => $_POST['end_point'],
                'barangay_id' => $_POST['barangay_id'],
                'distance_km' => $_POST['distance_km'],
                'estimated_time_min' => $_POST['estimated_time_min'],
                'fare_regular' => $_POST['fare_regular'],
                'fare_special' => $_POST['fare_special'],
                'operating_hours_start' => $_POST['operating_hours_start'],
                'operating_hours_end' => $_POST['operating_hours_end'],
                'status' => 'Under Review',
                'created_by' => $user_id
            ]);
            
            $route_id = $pdo->lastInsertId();
            
            // Add to approval log
            $sql_log = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                       VALUES (:route_id, 'Created', 'New route created', :acted_by)";
            $stmt_log = $pdo->prepare($sql_log);
            $stmt_log->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
            
            $_SESSION['success'] = "Route created successfully and is pending approval!";
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error creating route: " . $e->getMessage();
        }
    }
    
    // Add new terminal
    if (isset($_POST['add_terminal'])) {
        try {
            $sql = "INSERT INTO route_terminals (terminal_code, terminal_name, terminal_type, barangay_id, location, latitude, longitude, capacity, operating_hours, contact_person, contact_number, amenities, status, created_by) 
                    VALUES (:terminal_code, :terminal_name, :terminal_type, :barangay_id, :location, :latitude, :longitude, :capacity, :operating_hours, :contact_person, :contact_number, :amenities, :status, :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'terminal_code' => $_POST['terminal_code'],
                'terminal_name' => $_POST['terminal_name'],
                'terminal_type' => $_POST['terminal_type'],
                'barangay_id' => $_POST['barangay_id'],
                'location' => $_POST['location'],
                'latitude' => $_POST['latitude'],
                'longitude' => $_POST['longitude'],
                'capacity' => $_POST['capacity'],
                'operating_hours' => $_POST['operating_hours'],
                'contact_person' => $_POST['contact_person'],
                'contact_number' => $_POST['contact_number'],
                'amenities' => $_POST['amenities'],
                'status' => 'Operational',
                'created_by' => $user_id
            ]);
            
            $_SESSION['success'] = "Terminal added successfully!";
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=terminals');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error adding terminal: " . $e->getMessage();
        }
    }
    
    // Add restriction
    if (isset($_POST['add_restriction'])) {
        try {
            $sql = "INSERT INTO route_restrictions (route_id, restriction_type, road_name, barangay_id, location, description, effective_date, expiry_date, restriction_time_start, restriction_time_end, penalty_amount, status, enforcement_level, created_by) 
                    VALUES (:route_id, :restriction_type, :road_name, :barangay_id, :location, :description, :effective_date, :expiry_date, :restriction_time_start, :restriction_time_end, :penalty_amount, :status, :enforcement_level, :created_by)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'route_id' => $_POST['route_id'] ?: NULL,
                'restriction_type' => $_POST['restriction_type'],
                'road_name' => $_POST['road_name'],
                'barangay_id' => $_POST['barangay_id'],
                'location' => $_POST['location'],
                'description' => $_POST['description'],
                'effective_date' => $_POST['effective_date'],
                'expiry_date' => $_POST['expiry_date'] ?: NULL,
                'restriction_time_start' => $_POST['restriction_time_start'] ?: NULL,
                'restriction_time_end' => $_POST['restriction_time_end'] ?: NULL,
                'penalty_amount' => $_POST['penalty_amount'],
                'status' => 'Active',
                'enforcement_level' => $_POST['enforcement_level'],
                'created_by' => $user_id
            ]);
            
            $_SESSION['success'] = "Restriction added successfully!";
            header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=restrictions');
            exit();
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error adding restriction: " . $e->getMessage();
        }
    }
}

// Handle approval actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $route_id = $_GET['id'];
    
    try {
        switch ($_GET['action']) {
            case 'approve':
                $sql = "UPDATE tricycle_routes SET status = 'Active', approved_by = :approved_by, approved_date = NOW() WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $route_id, 'approved_by' => $user_id]);
                
                // Add to approval log
                $sql_log = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                           VALUES (:route_id, 'Approved', 'Route approved by administrator', :acted_by)";
                $stmt_log = $pdo->prepare($sql_log);
                $stmt_log->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
                
                $_SESSION['success'] = "Route approved successfully!";
                break;
                
            case 'reject':
                $sql = "UPDATE tricycle_routes SET status = 'Inactive' WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $route_id]);
                
                // Add to approval log
                $sql_log = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                           VALUES (:route_id, 'Rejected', 'Route rejected by administrator', :acted_by)";
                $stmt_log = $pdo->prepare($sql_log);
                $stmt_log->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
                
                $_SESSION['success'] = "Route rejected successfully!";
                break;
                
            case 'suspend':
                $sql = "UPDATE tricycle_routes SET status = 'Suspended' WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $route_id]);
                
                // Add to approval log
                $sql_log = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                           VALUES (:route_id, 'Suspended', 'Route suspended by administrator', :acted_by)";
                $stmt_log = $pdo->prepare($sql_log);
                $stmt_log->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
                
                $_SESSION['success'] = "Route suspended successfully!";
                break;
                
            case 'activate':
                $sql = "UPDATE tricycle_routes SET status = 'Active' WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $route_id]);
                
                // Add to approval log
                $sql_log = "INSERT INTO route_approval_logs (route_id, action, remarks, acted_by) 
                           VALUES (:route_id, 'Reactivated', 'Route reactivated by administrator', :acted_by)";
                $stmt_log = $pdo->prepare($sql_log);
                $stmt_log->execute(['route_id' => $route_id, 'acted_by' => $user_id]);
                
                $_SESSION['success'] = "Route activated successfully!";
                break;
        }
        
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error performing action: " . $e->getMessage();
    }
}

// Fetch data for display
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'routes';

// Fetch barangays for dropdowns
$sql_barangays = "SELECT * FROM barangays ORDER BY name";
$stmt_barangays = $pdo->query($sql_barangays);
$barangays = $stmt_barangays->fetchAll(PDO::FETCH_ASSOC);

// Fetch routes - using aliases to avoid reserved keyword issues
$sql_routes = "SELECT tr.*, b.name as barangay_name, 
               CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
               CONCAT(au.first_name, ' ', au.last_name) as approved_by_name
               FROM tricycle_routes tr
               LEFT JOIN barangays b ON tr.barangay_id = b.id
               LEFT JOIN users u ON tr.created_by = u.id
               LEFT JOIN users au ON tr.approved_by = au.id
               ORDER BY tr.created_at DESC";
$stmt_routes = $pdo->query($sql_routes);
$routes = $stmt_routes->fetchAll(PDO::FETCH_ASSOC);

// Fetch terminals
$sql_terminals = "SELECT rt.*, b.name as barangay_name, 
                  CONCAT(u.first_name, ' ', u.last_name) as created_by_name
                  FROM route_terminals rt
                  LEFT JOIN barangays b ON rt.barangay_id = b.id
                  LEFT JOIN users u ON rt.created_by = u.id
                  ORDER BY rt.created_at DESC";
$stmt_terminals = $pdo->query($sql_terminals);
$terminals = $stmt_terminals->fetchAll(PDO::FETCH_ASSOC);

// Fetch restrictions
$sql_restrictions = "SELECT rr.*, b.name as barangay_name, tr.route_name,
                     CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                     CONCAT(au.first_name, ' ', au.last_name) as approved_by_name
                     FROM route_restrictions rr
                     LEFT JOIN barangays b ON rr.barangay_id = b.id
                     LEFT JOIN tricycle_routes tr ON rr.route_id = tr.id
                     LEFT JOIN users u ON rr.created_by = u.id
                     LEFT JOIN users au ON rr.approved_by = au.id
                     ORDER BY rr.effective_date DESC";
$stmt_restrictions = $pdo->query($sql_restrictions);
$restrictions = $stmt_restrictions->fetchAll(PDO::FETCH_ASSOC);

// Fetch operators - FIXED: using alias 'o' instead of 'to'
$sql_operators = "SELECT o.*, tr.route_name, tr.route_code
                  FROM tricycle_operators o
                  LEFT JOIN tricycle_routes tr ON o.route_id = tr.id
                  ORDER BY o.created_at DESC";
$stmt_operators = $pdo->query($sql_operators);
$operators = $stmt_operators->fetchAll(PDO::FETCH_ASSOC);

// Get stats
$sql_stats = "SELECT 
              (SELECT COUNT(*) FROM tricycle_routes WHERE status = 'Active') as active_routes,
              (SELECT COUNT(*) FROM route_terminals WHERE status = 'Operational') as operational_terminals,
              (SELECT COUNT(*) FROM route_restrictions WHERE status = 'Active' AND (expiry_date IS NULL OR expiry_date >= CURDATE())) as active_restrictions,
              (SELECT COUNT(*) FROM tricycle_operators WHERE status = 'Active') as active_operators";
$stmt_stats = $pdo->query($sql_stats);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tricycle Route Management - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* All your existing CSS styles from admin_dashboard.php */
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
        
        /* NEW STYLES FOR TRICYCLE ROUTE MANAGEMENT */
        
        .tab-container {
            background-color: var(--card-bg);
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 24px;
        }
        
        .tab-header {
            display: flex;
            background-color: var(--background-color);
            border-bottom: 1px solid var(--border-color);
            overflow-x: auto;
        }
        
        .tab-button {
            padding: 16px 24px;
            background: none;
            border: none;
            font-size: 14px;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.2s;
            white-space: nowrap;
        }
        
        .tab-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .tab-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .tab-button.active {
            color: var(--primary-color);
            border-bottom: 2px solid var(--primary-color);
            background-color: var(--card-bg);
        }
        
        .tab-content {
            display: none;
            padding: 24px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card-small {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: transform 0.2s;
        }
        
        .stat-card-small:hover {
            transform: translateY(-2px);
        }
        
        .stat-icon-small {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
            font-size: 20px;
        }
        
        .stat-value-small {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label-small {
            font-size: 12px;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background-color: var(--background-color);
            color: var(--text-color);
            font-weight: 600;
            text-align: left;
            padding: 12px 16px;
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
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active { background-color: #dcfce7; color: #166534; }
        .status-inactive { background-color: #f3f4f6; color: #6b7280; }
        .status-review { background-color: #fef3c7; color: #92400e; }
        .status-suspended { background-color: #fee2e2; color: #991b1b; }
        
        .dark-mode .status-active { background-color: rgba(16, 185, 129, 0.2); color: #86efac; }
        .dark-mode .status-inactive { background-color: rgba(107, 114, 128, 0.2); color: #d1d5db; }
        .dark-mode .status-review { background-color: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .status-suspended { background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-button {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        
        .btn-approve { background-color: #10b981; color: white; }
        .btn-approve:hover { background-color: #059669; }
        
        .btn-reject { background-color: #ef4444; color: white; }
        .btn-reject:hover { background-color: #dc2626; }
        
        .btn-suspend { background-color: #f59e0b; color: white; }
        .btn-suspend:hover { background-color: #d97706; }
        
        .btn-activate { background-color: #3b82f6; color: white; }
        .btn-activate:hover { background-color: #2563eb; }
        
        .btn-view { background-color: #8b5cf6; color: white; }
        .btn-view:hover { background-color: #7c3aed; }
        
        .btn-edit { background-color: #06b6d4; color: white; }
        .btn-edit:hover { background-color: #0891b2; }
        
        .btn-delete { background-color: #6b7280; color: white; }
        .btn-delete:hover { background-color: #4b5563; }
        
        .form-container {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 24px;
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
        
        .form-input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-height: 100px;
            resize: vertical;
            transition: border-color 0.2s;
        }
        
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .submit-button {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
            grid-column: 1 / -1;
            justify-self: start;
        }
        
        .submit-button:hover {
            background-color: var(--primary-dark);
        }
        
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
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
        
        .search-box-tab {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .search-input-tab {
            flex: 1;
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .filter-select {
            padding: 10px 14px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .toggle-form-button {
            background-color: var(--secondary-color);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .toggle-form-button:hover {
            background-color: var(--secondary-dark);
        }
        
        .toggle-form-button.active {
            background-color: var(--primary-color);
        }
        
        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
        
        @media (max-width: 480px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }
            
            .tab-button {
                padding: 12px 16px;
                font-size: 12px;
            }
        }
        
        /* Responsive Design for existing elements */
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
                        <a href="lrcr/admin_road_condition.php" class="submenu-item">Road Condition Reports</a>
                        <a href="lrcr/action_ass.php" class="submenu-item">Action Assignment</a>
                        <a href="lrcr/admin_road_condition_analytics.php" class="submenu-item">Historical Records & Analytics</a>
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
                        <a href="btrm/tricycle_route_management.php" class="submenu-item active">Route Definition</a>
                        <a href="btrm/driver_franchise_records.php?tab=terminals" class="submenu-item">Terminal Management</a>
                        <a href="btrm/tricycle_route_management.php?tab=restrictions" class="submenu-item">Restricted Roads</a>
                        <a href="btrm/tricycle_route_management.php?tab=operators" class="submenu-item">Operator Management</a>
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
                        <h1 class="dashboard-title">Tricycle Route Management</h1>
                        <p class="dashboard-subtitle">Manage tricycle routes, terminals, and road restrictions across barangays.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.print()">
                            <i class='bx bxs-printer'></i>
                            Print Report
                        </button>
                        <button class="secondary-button" onclick="window.location.href='export_routes.php'">
                            <i class='bx bxs-download'></i>
                            Export Data
                        </button>
                    </div>
                </div>
                
                <!-- Display alerts -->
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="alert alert-success">
                        <i class='bx bxs-check-circle'></i>
                        <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="alert alert-error">
                        <i class='bx bxs-error-circle'></i>
                        <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="stats-cards">
                    <div class="stat-card-small">
                        <div class="stat-icon-small" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981;">
                            <i class='bx bxs-map'></i>
                        </div>
                        <div class="stat-value-small"><?php echo $stats['active_routes'] ?? 0; ?></div>
                        <div class="stat-label-small">Active Routes</div>
                    </div>
                    
                    <div class="stat-card-small">
                        <div class="stat-icon-small" style="background-color: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                            <i class='bx bxs-building'></i>
                        </div>
                        <div class="stat-value-small"><?php echo $stats['operational_terminals'] ?? 0; ?></div>
                        <div class="stat-label-small">Operational Terminals</div>
                    </div>
                    
                    <div class="stat-card-small">
                        <div class="stat-icon-small" style="background-color: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                            <i class='bx bxs-no-entry'></i>
                        </div>
                        <div class="stat-value-small"><?php echo $stats['active_restrictions'] ?? 0; ?></div>
                        <div class="stat-label-small">Active Restrictions</div>
                    </div>
                    
                    <div class="stat-card-small">
                        <div class="stat-icon-small" style="background-color: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                            <i class='bx bxs-user-badge'></i>
                        </div>
                        <div class="stat-value-small"><?php echo $stats['active_operators'] ?? 0; ?></div>
                        <div class="stat-label-small">Active Operators</div>
                    </div>
                </div>
                
                <!-- Tab Container -->
                <div class="tab-container">
                    <div class="tab-header">
                        <button class="tab-button <?php echo $active_tab === 'routes' ? 'active' : ''; ?>" onclick="switchTab('routes')">
                            <i class='bx bxs-map'></i> Route Management
                        </button>
                        <button class="tab-button <?php echo $active_tab === 'terminals' ? 'active' : ''; ?>" onclick="switchTab('terminals')">
                            <i class='bx bxs-building'></i> Terminal Management
                        </button>
                        <button class="tab-button <?php echo $active_tab === 'restrictions' ? 'active' : ''; ?>" onclick="switchTab('restrictions')">
                            <i class='bx bxs-no-entry'></i> Road Restrictions
                        </button>
                        <button class="tab-button <?php echo $active_tab === 'operators' ? 'active' : ''; ?>" onclick="switchTab('operators')">
                            <i class='bx bxs-user-badge'></i> Operator Management
                        </button>
                    </div>
                    
                    <!-- Routes Tab -->
                    <div id="tab-routes" class="tab-content <?php echo $active_tab === 'routes' ? 'active' : ''; ?>">
                        <div class="card-header">
                            <h2 class="card-title">Tricycle Route Management</h2>
                            <button class="toggle-form-button" onclick="toggleRouteForm()">
                                <i class='bx bx-plus'></i> Add New Route
                            </button>
                        </div>
                        
                        <!-- Add Route Form (Hidden by default) -->
                        <div id="route-form" class="form-container" style="display: none;">
                            <h3 style="margin-bottom: 20px; color: var(--text-color);">Add New Tricycle Route</h3>
                            <form method="POST" action="">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Route Code *</label>
                                        <input type="text" name="route_code" class="form-input" required 
                                               placeholder="e.g., TR-001">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Route Name *</label>
                                        <input type="text" name="route_name" class="form-input" required 
                                               placeholder="e.g., Poblacion-San Isidro Route">
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-textarea" 
                                                  placeholder="Route description..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Start Point *</label>
                                        <input type="text" name="start_point" class="form-input" required 
                                               placeholder="e.g., Poblacion Market">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">End Point *</label>
                                        <input type="text" name="end_point" class="form-input" required 
                                               placeholder="e.g., San Isidro Terminal">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Barangay *</label>
                                        <select name="barangay_id" class="form-select" required>
                                            <option value="">Select Barangay</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo $barangay['id']; ?>">
                                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Distance (km)</label>
                                        <input type="number" name="distance_km" class="form-input" step="0.01" 
                                               placeholder="e.g., 5.25">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Estimated Time (minutes)</label>
                                        <input type="number" name="estimated_time_min" class="form-input" 
                                               placeholder="e.g., 25">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Regular Fare (₱)</label>
                                        <input type="number" name="fare_regular" class="form-input" step="0.01" 
                                               placeholder="e.g., 15.00">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Special Fare (₱)</label>
                                        <input type="number" name="fare_special" class="form-input" step="0.01" 
                                               placeholder="e.g., 20.00">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Operating Hours Start *</label>
                                        <input type="time" name="operating_hours_start" class="form-input" required 
                                               value="06:00">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Operating Hours End *</label>
                                        <input type="time" name="operating_hours_end" class="form-input" required 
                                               value="22:00">
                                    </div>
                                    
                                    <button type="submit" name="add_route" class="submit-button">
                                        <i class='bx bxs-save'></i> Create Route
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Search and Filter -->
                        <div class="search-box-tab">
                            <input type="text" placeholder="Search routes..." class="search-input-tab" id="searchRoutes">
                            <select class="filter-select" id="filterStatus">
                                <option value="">All Status</option>
                                <option value="Active">Active</option>
                                <option value="Under Review">Under Review</option>
                                <option value="Suspended">Suspended</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                            <select class="filter-select" id="filterBarangay">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>">
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Routes Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Route Code</th>
                                        <th>Route Name</th>
                                        <th>Barangay</th>
                                        <th>Start Point</th>
                                        <th>End Point</th>
                                        <th>Distance</th>
                                        <th>Fare</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="routesTableBody">
                                    <?php foreach ($routes as $route): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($route['route_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($route['route_name']); ?></td>
                                            <td><?php echo htmlspecialchars($route['barangay_name']); ?></td>
                                            <td><?php echo htmlspecialchars($route['start_point']); ?></td>
                                            <td><?php echo htmlspecialchars($route['end_point']); ?></td>
                                            <td><?php echo $route['distance_km'] ? $route['distance_km'] . ' km' : 'N/A'; ?></td>
                                            <td>₱<?php echo number_format($route['fare_regular'], 2); ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $route['status'])); ?>">
                                                    <?php echo $route['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <?php if ($route['status'] === 'Under Review'): ?>
                                                        <button class="action-button btn-approve" 
                                                                onclick="window.location.href='?action=approve&id=<?php echo $route['id']; ?>'">
                                                            Approve
                                                        </button>
                                                        <button class="action-button btn-reject" 
                                                                onclick="window.location.href='?action=reject&id=<?php echo $route['id']; ?>'">
                                                            Reject
                                                        </button>
                                                    <?php elseif ($route['status'] === 'Active'): ?>
                                                        <button class="action-button btn-suspend" 
                                                                onclick="window.location.href='?action=suspend&id=<?php echo $route['id']; ?>'">
                                                            Suspend
                                                        </button>
                                                    <?php elseif ($route['status'] === 'Suspended'): ?>
                                                        <button class="action-button btn-activate" 
                                                                onclick="window.location.href='?action=activate&id=<?php echo $route['id']; ?>'">
                                                            Activate
                                                        </button>
                                                    <?php endif; ?>
                                                    <button class="action-button btn-view" 
                                                            onclick="viewRouteDetails(<?php echo $route['id']; ?>)">
                                                        View
                                                    </button>
                                                    <button class="action-button btn-edit" 
                                                            onclick="editRoute(<?php echo $route['id']; ?>)">
                                                        Edit
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
                    <div id="tab-terminals" class="tab-content <?php echo $active_tab === 'terminals' ? 'active' : ''; ?>">
                        <div class="card-header">
                            <h2 class="card-title">Terminal & Stop Management</h2>
                            <button class="toggle-form-button" onclick="toggleTerminalForm()">
                                <i class='bx bx-plus'></i> Add New Terminal
                            </button>
                        </div>
                        
                        <!-- Add Terminal Form (Hidden by default) -->
                        <div id="terminal-form" class="form-container" style="display: none;">
                            <h3 style="margin-bottom: 20px; color: var(--text-color);">Add New Terminal</h3>
                            <form method="POST" action="">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Terminal Code *</label>
                                        <input type="text" name="terminal_code" class="form-input" required 
                                               placeholder="e.g., TERM-001">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Terminal Name *</label>
                                        <input type="text" name="terminal_name" class="form-input" required 
                                               placeholder="e.g., Poblacion Main Terminal">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Terminal Type *</label>
                                        <select name="terminal_type" class="form-select" required>
                                            <option value="">Select Type</option>
                                            <option value="Main Terminal">Main Terminal</option>
                                            <option value="Secondary Terminal">Secondary Terminal</option>
                                            <option value="Waiting Area">Waiting Area</option>
                                            <option value="Loading Zone">Loading Zone</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Barangay *</label>
                                        <select name="barangay_id" class="form-select" required>
                                            <option value="">Select Barangay</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo $barangay['id']; ?>">
                                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Location *</label>
                                        <input type="text" name="location" class="form-input" required 
                                               placeholder="e.g., Near Poblacion Market, Main Road">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Latitude</label>
                                        <input type="number" name="latitude" class="form-input" step="0.00000001" 
                                               placeholder="e.g., 14.68683200">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Longitude</label>
                                        <input type="number" name="longitude" class="form-input" step="0.00000001" 
                                               placeholder="e.g., 121.09662300">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Capacity (vehicles)</label>
                                        <input type="number" name="capacity" class="form-input" 
                                               placeholder="e.g., 50">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Operating Hours</label>
                                        <input type="text" name="operating_hours" class="form-input" 
                                               placeholder="e.g., 6:00 AM - 10:00 PM">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Contact Person</label>
                                        <input type="text" name="contact_person" class="form-input" 
                                               placeholder="e.g., Juan Dela Cruz">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Contact Number</label>
                                        <input type="text" name="contact_number" class="form-input" 
                                               placeholder="e.g., 09123456789">
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Amenities</label>
                                        <textarea name="amenities" class="form-textarea" 
                                                  placeholder="List of amenities (comma separated)"></textarea>
                                    </div>
                                    
                                    <button type="submit" name="add_terminal" class="submit-button">
                                        <i class='bx bxs-save'></i> Add Terminal
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Terminals Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Terminal Code</th>
                                        <th>Terminal Name</th>
                                        <th>Type</th>
                                        <th>Barangay</th>
                                        <th>Location</th>
                                        <th>Capacity</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($terminals as $terminal): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($terminal['terminal_code']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($terminal['terminal_name']); ?></td>
                                            <td><?php echo htmlspecialchars($terminal['terminal_type']); ?></td>
                                            <td><?php echo htmlspecialchars($terminal['barangay_name']); ?></td>
                                            <td><?php echo htmlspecialchars($terminal['location']); ?></td>
                                            <td><?php echo $terminal['capacity'] ?: 'N/A'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $terminal['status'])); ?>">
                                                    <?php echo $terminal['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button btn-view" 
                                                            onclick="viewTerminalDetails(<?php echo $terminal['id']; ?>)">
                                                        View
                                                    </button>
                                                    <button class="action-button btn-edit" 
                                                            onclick="editTerminal(<?php echo $terminal['id']; ?>)">
                                                        Edit
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Restrictions Tab -->
                    <div id="tab-restrictions" class="tab-content <?php echo $active_tab === 'restrictions' ? 'active' : ''; ?>">
                        <div class="card-header">
                            <h2 class="card-title">Restricted Road Management</h2>
                            <button class="toggle-form-button" onclick="toggleRestrictionForm()">
                                <i class='bx bx-plus'></i> Add Restriction
                            </button>
                        </div>
                        
                        <!-- Add Restriction Form (Hidden by default) -->
                        <div id="restriction-form" class="form-container" style="display: none;">
                            <h3 style="margin-bottom: 20px; color: var(--text-color);">Add Road Restriction</h3>
                            <form method="POST" action="">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label">Restriction Type *</label>
                                        <select name="restriction_type" class="form-select" required>
                                            <option value="">Select Type</option>
                                            <option value="No Entry">No Entry</option>
                                            <option value="No Parking">No Parking</option>
                                            <option value="One Way">One Way</option>
                                            <option value="No Stopping">No Stopping</option>
                                            <option value="Speed Limit">Speed Limit</option>
                                            <option value="Time Restricted">Time Restricted</option>
                                            <option value="Vehicle Type Restriction">Vehicle Type Restriction</option>
                                            <option value="Weight Limit">Weight Limit</option>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Route (if applicable)</label>
                                        <select name="route_id" class="form-select">
                                            <option value="">Not Route Specific</option>
                                            <?php foreach ($routes as $route): ?>
                                                <option value="<?php echo $route['id']; ?>">
                                                    <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Road Name *</label>
                                        <input type="text" name="road_name" class="form-input" required 
                                               placeholder="e.g., Main Street">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Barangay *</label>
                                        <select name="barangay_id" class="form-select" required>
                                            <option value="">Select Barangay</option>
                                            <?php foreach ($barangays as $barangay): ?>
                                                <option value="<?php echo $barangay['id']; ?>">
                                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Location *</label>
                                        <input type="text" name="location" class="form-input" required 
                                               placeholder="e.g., Between Street A and Street B">
                                    </div>
                                    
                                    <div class="form-group full-width">
                                        <label class="form-label">Description</label>
                                        <textarea name="description" class="form-textarea" 
                                                  placeholder="Restriction details..."></textarea>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Effective Date *</label>
                                        <input type="date" name="effective_date" class="form-input" required 
                                               value="<?php echo date('Y-m-d'); ?>">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Expiry Date</label>
                                        <input type="date" name="expiry_date" class="form-input">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Restriction Time Start</label>
                                        <input type="time" name="restriction_time_start" class="form-input">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Restriction Time End</label>
                                        <input type="time" name="restriction_time_end" class="form-input">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Penalty Amount (₱)</label>
                                        <input type="number" name="penalty_amount" class="form-input" step="0.01" 
                                               placeholder="e.g., 500.00">
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label">Enforcement Level</label>
                                        <select name="enforcement_level" class="form-select">
                                            <option value="Strict">Strict</option>
                                            <option value="Moderate">Moderate</option>
                                            <option value="Warning Only">Warning Only</option>
                                        </select>
                                    </div>
                                    
                                    <button type="submit" name="add_restriction" class="submit-button">
                                        <i class='bx bxs-save'></i> Add Restriction
                                    </button>
                                </div>
                            </form>
                        </div>
                        
                        <!-- Restrictions Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Road Name</th>
                                        <th>Barangay</th>
                                        <th>Location</th>
                                        <th>Route</th>
                                        <th>Effective Date</th>
                                        <th>Expiry Date</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($restrictions as $restriction): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($restriction['restriction_type']); ?></td>
                                            <td><?php echo htmlspecialchars($restriction['road_name']); ?></td>
                                            <td><?php echo htmlspecialchars($restriction['barangay_name']); ?></td>
                                            <td><?php echo htmlspecialchars($restriction['location']); ?></td>
                                            <td><?php echo $restriction['route_name'] ?: 'General'; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($restriction['effective_date'])); ?></td>
                                            <td><?php echo $restriction['expiry_date'] ? date('M d, Y', strtotime($restriction['expiry_date'])) : 'None'; ?></td>
                                            <td>
                                                <?php 
                                                $status_class = 'status-active';
                                                if ($restriction['expiry_date'] && strtotime($restriction['expiry_date']) < time()) {
                                                    $status_class = 'status-inactive';
                                                }
                                                ?>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $restriction['expiry_date'] && strtotime($restriction['expiry_date']) < time() ? 'Expired' : $restriction['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button btn-view" 
                                                            onclick="viewRestrictionDetails(<?php echo $restriction['id']; ?>)">
                                                        View
                                                    </button>
                                                    <button class="action-button btn-edit" 
                                                            onclick="editRestriction(<?php echo $restriction['id']; ?>)">
                                                        Edit
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Operators Tab -->
                    <div id="tab-operators" class="tab-content <?php echo $active_tab === 'operators' ? 'active' : ''; ?>">
                        <div class="card-header">
                            <h2 class="card-title">Tricycle Operator Management</h2>
                            <button class="toggle-form-button" onclick="toggleOperatorForm()">
                                <i class='bx bx-plus'></i> Register Operator
                            </button>
                        </div>
                        
                        <!-- Operators Table -->
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Operator Code</th>
                                        <th>Operator Name</th>
                                        <th>Contact Number</th>
                                        <th>Route</th>
                                        <th>Vehicle Number</th>
                                        <th>Franchise No.</th>
                                        <th>Franchise Expiry</th>
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
                                            <td><?php echo htmlspecialchars($operator['route_code'] . ' - ' . $operator['route_name']); ?></td>
                                            <td><?php echo htmlspecialchars($operator['vehicle_number']); ?></td>
                                            <td><?php echo htmlspecialchars($operator['franchise_number'] ?: 'N/A'); ?></td>
                                            <td><?php echo $operator['franchise_expiry'] ? date('M d, Y', strtotime($operator['franchise_expiry'])) : 'N/A'; ?></td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower($operator['status']); ?>">
                                                    <?php echo $operator['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-button btn-view" 
                                                            onclick="viewOperatorDetails(<?php echo $operator['id']; ?>)">
                                                        View
                                                    </button>
                                                    <button class="action-button btn-edit" 
                                                            onclick="editOperator(<?php echo $operator['id']; ?>)">
                                                        Edit
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
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
        
        // Tab switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Add active class to clicked tab button
            event.target.classList.add('active');
            
            // Update URL without page reload
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);
            
            // Hide all forms when switching tabs
            hideAllForms();
        }
        
        // Form toggling
        function toggleRouteForm() {
            const form = document.getElementById('route-form');
            const button = event.target;
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                button.innerHTML = '<i class="bx bx-x"></i> Cancel';
                button.classList.add('active');
            } else {
                form.style.display = 'none';
                button.innerHTML = '<i class="bx bx-plus"></i> Add New Route';
                button.classList.remove('active');
            }
        }
        
        function toggleTerminalForm() {
            const form = document.getElementById('terminal-form');
            const button = event.target;
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                button.innerHTML = '<i class="bx bx-x"></i> Cancel';
                button.classList.add('active');
            } else {
                form.style.display = 'none';
                button.innerHTML = '<i class="bx bx-plus"></i> Add New Terminal';
                button.classList.remove('active');
            }
        }
        
        function toggleRestrictionForm() {
            const form = document.getElementById('restriction-form');
            const button = event.target;
            
            if (form.style.display === 'none') {
                form.style.display = 'block';
                button.innerHTML = '<i class="bx bx-x"></i> Cancel';
                button.classList.add('active');
            } else {
                form.style.display = 'none';
                button.innerHTML = '<i class="bx bx-plus"></i> Add Restriction';
                button.classList.remove('active');
            }
        }
        
        function toggleOperatorForm() {
            alert('Operator registration form will be implemented here');
        }
        
        function hideAllForms() {
            document.getElementById('route-form').style.display = 'none';
            document.getElementById('terminal-form').style.display = 'none';
            document.getElementById('restriction-form').style.display = 'none';
            
            // Reset buttons
            document.querySelectorAll('.toggle-form-button').forEach(button => {
                if (button.textContent.includes('Cancel')) {
                    if (button.textContent.includes('Route')) {
                        button.innerHTML = '<i class="bx bx-plus"></i> Add New Route';
                    } else if (button.textContent.includes('Terminal')) {
                        button.innerHTML = '<i class="bx bx-plus"></i> Add New Terminal';
                    } else if (button.textContent.includes('Restriction')) {
                        button.innerHTML = '<i class="bx bx-plus"></i> Add Restriction';
                    }
                    button.classList.remove('active');
                }
            });
        }
        
        // Search functionality for routes
        document.getElementById('searchRoutes').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#routesTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Filter by status
        document.getElementById('filterStatus').addEventListener('change', function(e) {
            const status = e.target.value;
            const rows = document.querySelectorAll('#routesTableBody tr');
            
            rows.forEach(row => {
                if (!status) {
                    row.style.display = '';
                    return;
                }
                
                const statusBadge = row.querySelector('.status-badge');
                if (statusBadge && statusBadge.textContent.trim() === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
        
        // Placeholder functions for view/edit actions
        function viewRouteDetails(routeId) {
            alert('View route details for ID: ' + routeId);
        }
        
        function editRoute(routeId) {
            alert('Edit route for ID: ' + routeId);
        }
        
        function viewTerminalDetails(terminalId) {
            alert('View terminal details for ID: ' + terminalId);
        }
        
        function editTerminal(terminalId) {
            alert('Edit terminal for ID: ' + terminalId);
        }
        
        function viewRestrictionDetails(restrictionId) {
            alert('View restriction details for ID: ' + restrictionId);
        }
        
        function editRestriction(restrictionId) {
            alert('Edit restriction for ID: ' + restrictionId);
        }
        
        function viewOperatorDetails(operatorId) {
            alert('View operator details for ID: ' + operatorId);
        }
        
        function editOperator(operatorId) {
            alert('Edit operator for ID: ' + operatorId);
        }
        
        // Initialize based on URL parameter
        window.addEventListener('DOMContentLoaded', function() {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');
            
            if (tab) {
                // Find and click the corresponding tab button
                const tabButton = document.querySelector(`.tab-button[onclick*="${tab}"]`);
                if (tabButton) {
                    tabButton.click();
                }
            }
        });
    </script>
</body>
</html>