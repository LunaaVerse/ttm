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
        'first_name' => 'Barangay',
        'middle_name' => '',
        'last_name' => 'Resident',
        'email' => 'resident@barangay.com',
        'role' => 'RESIDENT'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'btrm';

// Get user's barangay from their address or default to first barangay
$barangay_id = 1; // Default - You can implement logic to detect user's barangay based on address

// Check if database tables exist, create them if not
function checkAndCreateTables($pdo) {
    // Check for route_updates_advisories table
    $check = $pdo->query("SHOW TABLES LIKE 'route_updates_advisories'")->fetch();
    if (!$check) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `route_updates_advisories` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `update_code` varchar(20) NOT NULL,
            `update_type` enum('Route Change','Terminal Change','Restriction Update','Service Advisory','Emergency Update','Maintenance Notice') DEFAULT 'Service Advisory',
            `title` varchar(255) NOT NULL,
            `description` text NOT NULL,
            `affected_route_id` int(11) DEFAULT NULL,
            `affected_barangay_id` int(11) NOT NULL,
            `effective_date` date DEFAULT NULL,
            `end_date` date DEFAULT NULL,
            `status` enum('Active','Expired','Upcoming','Cancelled') DEFAULT 'Active',
            `priority` enum('Normal','High','Emergency') DEFAULT 'Normal',
            `created_by` int(11) NOT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `update_code` (`update_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }
    
    // Check for route_feedback table
    $check = $pdo->query("SHOW TABLES LIKE 'route_feedback'")->fetch();
    if (!$check) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `route_feedback` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `feedback_code` varchar(20) NOT NULL,
            `user_id` int(11) NOT NULL,
            `feedback_type` enum('Route Suggestion','Complaint','Compliment','Question','Report Issue') DEFAULT 'Route Suggestion',
            `route_id` int(11) DEFAULT NULL,
            `driver_id` int(11) DEFAULT NULL,
            `vehicle_number` varchar(20) DEFAULT NULL,
            `barangay_id` int(11) NOT NULL,
            `subject` varchar(255) NOT NULL,
            `message` text NOT NULL,
            `location` varchar(255) DEFAULT NULL,
            `incident_date` datetime DEFAULT NULL,
            `status` enum('Pending','Under Review','Acknowledged','Resolved','Closed') DEFAULT 'Pending',
            `priority` enum('Low','Medium','High') DEFAULT 'Low',
            `admin_notes` text DEFAULT NULL,
            `resolved_by` int(11) DEFAULT NULL,
            `resolved_date` datetime DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `feedback_code` (`feedback_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }
    
    // Check for user_saved_routes table
    $check = $pdo->query("SHOW TABLES LIKE 'user_saved_routes'")->fetch();
    if (!$check) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_saved_routes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `route_id` int(11) NOT NULL,
            `barangay_id` int(11) NOT NULL,
            `is_favorite` tinyint(1) DEFAULT 0,
            `notes` text DEFAULT NULL,
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `user_route` (`user_id`,`route_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;");
    }
}

// Create tables if they don't exist
checkAndCreateTables($pdo);

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    switch ($_POST['action']) {
        case 'toggle_favorite':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not authenticated']);
                exit();
            }
            
            if (!isset($_POST['route_id'])) {
                echo json_encode(['success' => false, 'message' => 'Missing route_id']);
                exit();
            }
            
            $user_id = $_SESSION['user_id'];
            $route_id = intval($_POST['route_id']);
            
            try {
                // Check if already favorited
                $stmt = $pdo->prepare("SELECT id FROM user_saved_routes WHERE user_id = ? AND route_id = ?");
                $stmt->execute([$user_id, $route_id]);
                
                if ($stmt->rowCount() === 0) {
                    // Get barangay_id from route
                    $stmt = $pdo->prepare("SELECT barangay_id FROM tricycle_routes WHERE id = ?");
                    $stmt->execute([$route_id]);
                    $route = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($route) {
                        $stmt = $pdo->prepare("INSERT INTO user_saved_routes (user_id, route_id, barangay_id, is_favorite) VALUES (?, ?, ?, 1)");
                        $stmt->execute([$user_id, $route_id, $route['barangay_id']]);
                        echo json_encode(['success' => true, 'action' => 'added']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Route not found']);
                    }
                } else {
                    $stmt = $pdo->prepare("DELETE FROM user_saved_routes WHERE user_id = ? AND route_id = ?");
                    $stmt->execute([$user_id, $route_id]);
                    echo json_encode(['success' => true, 'action' => 'removed']);
                }
            } catch (PDOException $e) {
                echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            }
            exit();
            
        case 'submit_feedback':
            if (!isset($_SESSION['user_id'])) {
                echo json_encode(['success' => false, 'message' => 'Not authenticated']);
                exit();
            }
            
            // Generate unique feedback code
            $feedback_code = 'FB-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            try {
                // Prepare data
                $data = [
                    'feedback_code' => $feedback_code,
                    'user_id' => $_SESSION['user_id'],
                    'feedback_type' => $_POST['feedback_type'],
                    'route_id' => !empty($_POST['route_id']) ? $_POST['route_id'] : null,
                    'barangay_id' => $barangay_id,
                    'subject' => $_POST['subject'],
                    'message' => $_POST['message'],
                    'location' => !empty($_POST['location']) ? $_POST['location'] : null,
                    'incident_date' => !empty($_POST['incident_date']) ? $_POST['incident_date'] : null,
                    'vehicle_number' => !empty($_POST['vehicle_number']) ? $_POST['vehicle_number'] : null,
                    'status' => 'Pending',
                    'priority' => 'Low'
                ];

                // Insert into database
                $sql = "INSERT INTO route_feedback 
                        (feedback_code, user_id, feedback_type, route_id, barangay_id, subject, message, 
                         location, incident_date, vehicle_number, status, priority, created_at) 
                        VALUES 
                        (:feedback_code, :user_id, :feedback_type, :route_id, :barangay_id, :subject, :message, 
                         :location, :incident_date, :vehicle_number, :status, :priority, NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($data);

                echo json_encode([
                    'success' => true,
                    'feedback_code' => $feedback_code,
                    'message' => 'Feedback submitted successfully'
                ]);
                
            } catch (PDOException $e) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to submit feedback: ' . $e->getMessage()
                ]);
            }
            exit();
    }
}

// Get all active tricycle routes for the user's barangay
$stmt = $pdo->prepare("
    SELECT tr.*, b.name as barangay_name, 
           COUNT(rs.id) as total_stops,
           (SELECT COUNT(*) FROM tricycle_operators WHERE route_id = tr.id AND status = 'Active') as active_operators,
           (SELECT COUNT(*) FROM tricycle_drivers WHERE route_id = tr.id AND status = 'Active') as active_drivers
    FROM tricycle_routes tr
    JOIN barangays b ON tr.barangay_id = b.id
    LEFT JOIN route_stops rs ON tr.id = rs.route_id
    WHERE tr.status = 'Active' 
    AND tr.barangay_id = :barangay_id
    AND tr.submission_status IN ('Approved', 'Active')
    GROUP BY tr.id
    ORDER BY tr.route_name
");
$stmt->execute(['barangay_id' => $barangay_id]);
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get route stops for each route
$route_stops = [];
foreach ($routes as $route) {
    $stmt = $pdo->prepare("
        SELECT * FROM route_stops 
        WHERE route_id = :route_id 
        ORDER BY stop_number
    ");
    $stmt->execute(['route_id' => $route['id']]);
    $route_stops[$route['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get terminals for the barangay
$stmt = $pdo->prepare("
    SELECT * FROM route_terminals 
    WHERE barangay_id = :barangay_id 
    AND status = 'Operational'
    ORDER BY terminal_name
");
$stmt->execute(['barangay_id' => $barangay_id]);
$terminals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get loading zones for the barangay
$stmt = $pdo->prepare("
    SELECT * FROM loading_zones 
    WHERE barangay_id = :barangay_id 
    AND status = 'Active'
    ORDER BY zone_name
");
$stmt->execute(['barangay_id' => $barangay_id]);
$loading_zones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get route restrictions for the barangay
$stmt = $pdo->prepare("
    SELECT rr.*, tr.route_name, b.name as barangay_name
    FROM route_restrictions rr
    JOIN barangays b ON rr.barangay_id = b.id
    LEFT JOIN tricycle_routes tr ON rr.route_id = tr.id
    WHERE rr.barangay_id = :barangay_id 
    AND rr.status = 'Active'
    AND (rr.expiry_date IS NULL OR rr.expiry_date >= CURDATE())
    ORDER BY rr.restriction_type, rr.road_name
");
$stmt->execute(['barangay_id' => $barangay_id]);
$restrictions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get service updates/advisories for the barangay
$stmt = $pdo->prepare("
    SELECT rua.*, tr.route_name, b.name as barangay_name
    FROM route_updates_advisories rua
    JOIN barangays b ON rua.affected_barangay_id = b.id
    LEFT JOIN tricycle_routes tr ON rua.affected_route_id = tr.id
    WHERE rua.affected_barangay_id = :barangay_id 
    AND rua.status = 'Active'
    AND (rua.end_date IS NULL OR rua.end_date >= CURDATE())
    ORDER BY rua.priority DESC, rua.effective_date DESC
    LIMIT 10
");
$stmt->execute(['barangay_id' => $barangay_id]);
$updates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if routes are saved as favorites
$saved_routes = [];
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT route_id FROM user_saved_routes WHERE user_id = :user_id");
    $stmt->execute(['user_id' => $_SESSION['user_id']]);
    $saved = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($saved as $route_id) {
        $saved_routes[$route_id] = true;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Tricycle Route Management - Barangay Resident Portal</title>
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
        
        /* BTRM Specific Styles */
        .tab-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .tab-header {
            display: flex;
            background-color: var(--background-color);
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap;
        }
        
        .tab-button {
            padding: 15px 25px;
            background: none;
            border: none;
            font-size: 16px;
            font-weight: 500;
            color: var(--text-light);
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            white-space: nowrap;
        }
        
        .tab-button:hover {
            color: var(--primary-color);
            background-color: rgba(13, 148, 136, 0.1);
        }
        
        .tab-button.active {
            color: var(--primary-color);
            background-color: var(--card-bg);
        }
        
        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background-color: var(--primary-color);
        }
        
        .tab-content {
            padding: 25px;
        }
        
        .tab-pane {
            display: none;
        }
        
        .tab-pane.active {
            display: block;
            animation: fadeIn 0.5s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .route-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .route-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border-color);
            transition: all 0.3s ease;
            position: relative;
        }
        
        .route-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            border-color: var(--primary-color);
        }
        
        .dark-mode .route-card:hover {
            box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        }
        
        .route-card-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .route-code {
            background-color: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .route-name {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        .route-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .meta-item i {
            font-size: 16px;
        }
        
        .route-details {
            margin-bottom: 20px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .detail-label {
            color: var(--text-light);
            font-size: 14px;
        }
        
        .detail-value {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .route-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .btn {
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--background-color);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        
        .btn-outline {
            background-color: transparent;
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: var(--primary-color);
            color: white;
        }
        
        .favorite-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: none;
            border: none;
            font-size: 20px;
            color: #e0e0e0;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .favorite-btn.active {
            color: #ff4757;
        }
        
        .favorite-btn:hover {
            transform: scale(1.2);
        }
        
        .stops-list {
            margin-top: 15px;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .stop-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background-color: var(--background-color);
            border-radius: 8px;
            margin-bottom: 8px;
        }
        
        .stop-number {
            background-color: var(--primary-color);
            color: white;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            font-weight: 600;
            margin-right: 12px;
            flex-shrink: 0;
        }
        
        .stop-info {
            flex: 1;
        }
        
        .stop-name {
            font-weight: 500;
            margin-bottom: 3px;
        }
        
        .stop-location {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .terminal-badge {
            display: inline-block;
            background-color: #f0fdfa;
            color: var(--primary-color);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 8px;
        }
        
        .dark-mode .terminal-badge {
            background-color: rgba(20, 184, 166, 0.2);
        }
        
        .restriction-item {
            background-color: var(--background-color);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid #ef4444;
        }
        
        .restriction-type {
            font-weight: 600;
            color: #ef4444;
            margin-bottom: 5px;
        }
        
        .restriction-location {
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .restriction-details {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .update-item {
            background-color: #f0fdfa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            border-left: 4px solid var(--primary-color);
        }
        
        .dark-mode .update-item {
            background-color: rgba(20, 184, 166, 0.1);
        }
        
        .update-type {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .update-title {
            font-size: 16px;
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .update-meta {
            display: flex;
            gap: 15px;
            font-size: 12px;
            color: var(--text-light);
            margin-bottom: 10px;
        }
        
        .search-box-btrm {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-box-btrm input {
            width: 100%;
            padding: 12px 20px 12px 45px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 16px;
        }
        
        .search-box-btrm i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-light);
            font-size: 20px;
        }
        
        .no-data {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-light);
        }
        
        .no-data i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
        
        .feedback-form {
            background-color: var(--card-bg);
            padding: 25px;
            border-radius: 12px;
            margin-top: 30px;
            border: 1px solid var(--border-color);
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
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 16px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(13, 148, 136, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
        }
        
        .info-banner {
            background-color: #f0fdfa;
            border: 1px solid #ccfbf1;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .dark-mode .info-banner {
            background-color: rgba(20, 184, 166, 0.1);
            border-color: rgba(20, 184, 166, 0.3);
        }
        
        .info-banner i {
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .info-content h3 {
            margin: 0 0 5px 0;
            font-size: 16px;
            color: var(--text-color);
        }
        
        .info-content p {
            margin: 0;
            font-size: 14px;
            color: var(--text-light);
        }
        
        .route-search-container {
            margin-bottom: 20px;
        }
        
        .alert-banner {
            background-color: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .dark-mode .alert-banner {
            background-color: rgba(245, 158, 11, 0.1);
            border-color: rgba(245, 158, 11, 0.3);
        }
        
        .alert-banner i {
            color: #f59e0b;
            font-size: 20px;
        }
        
        @media (max-width: 768px) {
            .route-grid {
                grid-template-columns: 1fr;
            }
            
            .tab-header {
                flex-wrap: wrap;
            }
            
            .tab-button {
                flex: 1;
                min-width: 120px;
                text-align: center;
                padding: 12px 15px;
                font-size: 14px;
            }
            
            .route-meta {
                flex-wrap: wrap;
                gap: 10px;
            }
            
            .route-actions {
                flex-wrap: wrap;
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
                <span class="logo-text"> Barangay Resident Portal</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY SERVICES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button (Added as first item) -->
                    <a href="user_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- 2.1 View Route Information -->
                    <div class="menu-item active" onclick="toggleSubmenu('route-info')">
                        <div class="icon-box icon-box-route-info">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Tricycle Route Info</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-info" class="submenu active">
                        <a href="btrm/tricycle_route_info.php" class="submenu-item active">View Routes & Info</a>
                        <a href="user_routes/schedule.php" class="submenu-item">Schedule Info</a>
                    </div>
                    
                    <!-- 2.2 Report Road Condition -->
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
                        <a href="user_road_condition/report_condition.php" class="submenu-item">Submit Report</a>
                        <a href="user_road_condition/my_reports.php" class="submenu-item">My Reports</a>
                    </div>
                    
                    <!-- 2.3 Report Incident -->
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
                        <a href="user_incidents/report_incident.php" class="submenu-item">Submit Incident</a>
                        <a href="user_incidents/my_reports.php" class="submenu-item">My Reports</a>
                        <a href="user_incidents/emergency_contacts.php" class="submenu-item">Emergency Contacts</a>
                    </div>
                    
                    <!-- 2.4 Submit Feedback -->
                    <div class="menu-item" onclick="toggleSubmenu('community-feedback')">
                        <div class="icon-box icon-box-feedback">
                            <i class='bx bxs-chat'></i>
                        </div>
                        <span class="font-medium">Community Feedback</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="community-feedback" class="submenu">
                        <a href="user_feedback/submit_feedback.php" class="submenu-item">Submit Feedback</a>
                        <a href="user_feedback/my_feedback.php" class="submenu-item">My Feedback</a>
                    </div>
                    
                    <!-- 2.5 Check Permit Status -->
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
                        <a href="user_permits/check_permit.php" class="submenu-item">Check Permit Status</a>
                        <a href="user_permits/regulation_info.php" class="submenu-item">Regulation Information</a>
                    </div>
                    
                    <!-- 2.6 Emergency Services -->
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
                        <a href="user_emergency/contacts.php" class="submenu-item">Emergency Contacts</a>
                        <a href="user_emergency/report.php" class="submenu-item">Emergency Report</a>
                    </div>
                </div>
                
                <!-- Separator -->
                <div class="menu-separator"></div>
                
                <!-- Bottom Menu Section -->
                <div class="menu-items">
                    <a href="user_profile.php" class="menu-item">
                        <div class="icon-box icon-box-profile">
                            <i class='bx bxs-user-circle'></i>
                        </div>
                        <span class="font-medium">My Profile</span>
                    </a>
                    
                    <a href="user_settings.php" class="menu-item">
                        <div class="icon-box icon-box-settings">
                            <i class='bx bxs-cog'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="user_help_support.php" class="menu-item">
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
                            <input type="text" placeholder="Search routes or services" class="search-input">
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
            
            <!-- BTRM Content -->
            <div class="dashboard-content">
                <!-- Title and Actions -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Barangay Tricycle Route Management</h1>
                        <p class="dashboard-subtitle">View approved routes, terminals, restrictions, and service updates in your barangay</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="switchTab('feedback')">
                            <i class='bx bxs-message-alt-add'></i>
                            Submit Feedback
                        </button>
                        <button class="secondary-button" onclick="printPage()">
                            <i class='bx bxs-printer'></i>
                            Print Info
                        </button>
                    </div>
                </div>

                <!-- Info Banner -->
                <div class="info-banner">
                    <i class='bx bx-info-circle'></i>
                    <div class="info-content">
                        <h3>Important Information</h3>
                        <p>All tricycle routes are regulated by the Barangay Transport Management. Report any violations or issues using the feedback form.</p>
                    </div>
                </div>

                <!-- Alert Banner for Route Changes -->
                <?php if (!empty($updates)): ?>
                <div class="alert-banner">
                    <i class='bx bxs-bell-ring'></i>
                    <div>
                        <strong>Service Updates Available:</strong> There are <?php echo count($updates); ?> active service updates or advisories for your barangay.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Tab Container -->
                <div class="tab-container">
                    <div class="tab-header">
                        <button class="tab-button active" onclick="switchTab('routes')">
                            <i class='bx bx-map'></i> Approved Routes
                        </button>
                        <button class="tab-button" onclick="switchTab('terminals')">
                            <i class='bx bx-buildings'></i> Terminals & Stops
                        </button>
                        <button class="tab-button" onclick="switchTab('restrictions')">
                            <i class='bx bx-no-entry'></i> Route Restrictions
                        </button>
                        <button class="tab-button" onclick="switchTab('updates')">
                            <i class='bx bx-news'></i> Service Updates
                            <?php if (!empty($updates)): ?>
                                <span style="background-color: #ef4444; color: white; padding: 2px 6px; border-radius: 10px; font-size: 12px; margin-left: 5px;">
                                    <?php echo count($updates); ?>
                                </span>
                            <?php endif; ?>
                        </button>
                        <button class="tab-button" onclick="switchTab('feedback')">
                            <i class='bx bx-message-square'></i> Submit Feedback
                        </button>
                    </div>

                    <div class="tab-content">
                        <!-- Tab 1: Approved Routes -->
                        <div id="routes" class="tab-pane active">
                            <div class="route-search-container">
                                <div class="search-box-btrm">
                                    <i class='bx bx-search'></i>
                                    <input type="text" id="route-search" placeholder="Search routes by name or code...">
                                </div>
                            </div>
                            
                            <?php if (empty($routes)): ?>
                                <div class="no-data">
                                    <i class='bx bx-map-alt'></i>
                                    <h3>No Active Routes Found</h3>
                                    <p>There are currently no approved tricycle routes in your barangay.</p>
                                </div>
                            <?php else: ?>
                                <div class="route-grid" id="route-list">
                                    <?php foreach ($routes as $route): ?>
                                        <div class="route-card" data-route-name="<?php echo strtolower($route['route_name']); ?>" data-route-code="<?php echo strtolower($route['route_code']); ?>">
                                            <?php if (isset($_SESSION['user_id'])): ?>
                                            <button class="favorite-btn <?php echo isset($saved_routes[$route['id']]) ? 'active' : ''; ?>" 
                                                    data-route-id="<?php echo $route['id']; ?>"
                                                    onclick="toggleFavorite(<?php echo $route['id']; ?>)">
                                                <i class='bx <?php echo isset($saved_routes[$route['id']]) ? 'bxs-heart' : 'bx-heart'; ?>'></i>
                                            </button>
                                            <?php endif; ?>
                                            
                                            <div class="route-card-header">
                                                <div>
                                                    <div class="route-code"><?php echo htmlspecialchars($route['route_code']); ?></div>
                                                    <h3 class="route-name"><?php echo htmlspecialchars($route['route_name']); ?></h3>
                                                    <div class="route-meta">
                                                        <div class="meta-item">
                                                            <i class='bx bx-time'></i>
                                                            <span><?php echo htmlspecialchars($route['estimated_time_min']); ?> min</span>
                                                        </div>
                                                        <div class="meta-item">
                                                            <i class='bx bx-road'></i>
                                                            <span><?php echo htmlspecialchars($route['distance_km']); ?> km</span>
                                                        </div>
                                                        <div class="meta-item">
                                                            <i class='bx bx-car'></i>
                                                            <span><?php echo htmlspecialchars($route['active_operators']); ?> operators</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="route-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Start Point:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($route['start_point']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">End Point:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($route['end_point']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Regular Fare:</span>
                                                    <span class="detail-value">₱<?php echo number_format($route['fare_regular'], 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Special Fare:</span>
                                                    <span class="detail-value">₱<?php echo number_format($route['fare_special'], 2); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Operating Hours:</span>
                                                    <span class="detail-value"><?php echo date('g:i A', strtotime($route['operating_hours_start'])); ?> - <?php echo date('g:i A', strtotime($route['operating_hours_end'])); ?></span>
                                                </div>
                                            </div>

                                            <div class="route-actions">
                                                <button class="btn btn-primary" onclick="viewRouteStops(<?php echo $route['id']; ?>)">
                                                    <i class='bx bx-list-ul'></i> View Stops
                                                </button>
                                                <button class="btn btn-secondary" onclick="showRouteDetails(<?php echo $route['id']; ?>, '<?php echo htmlspecialchars($route['route_name']); ?>')">
                                                    <i class='bx bx-detail'></i> Details
                                                </button>
                                            </div>

                                            <!-- Hidden stops list -->
                                            <div class="stops-list" id="stops-<?php echo $route['id']; ?>" style="display: none;">
                                                <?php if (isset($route_stops[$route['id']])): ?>
                                                    <?php foreach ($route_stops[$route['id']] as $stop): ?>
                                                        <div class="stop-item">
                                                            <div class="stop-number"><?php echo $stop['stop_number']; ?></div>
                                                            <div class="stop-info">
                                                                <div class="stop-name">
                                                                    <?php echo htmlspecialchars($stop['stop_name']); ?>
                                                                    <?php if ($stop['is_terminal']): ?>
                                                                        <span class="terminal-badge">Terminal</span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                <div class="stop-location"><?php echo htmlspecialchars($stop['location']); ?></div>
                                                                <?php if ($stop['fare_from_start'] > 0): ?>
                                                                    <div class="stop-fare" style="font-size: 12px; color: var(--primary-color); margin-top: 3px;">
                                                                        Fare: ₱<?php echo number_format($stop['fare_from_start'], 2); ?>
                                                                    </div>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <div class="no-data" style="padding: 20px;">
                                                        <i class='bx bx-map-pin'></i>
                                                        <p>No stops defined for this route</p>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tab 2: Terminals & Stops -->
                        <div id="terminals" class="tab-pane">
                            <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 20px; color: var(--text-color);">Terminals</h2>
                            <?php if (empty($terminals)): ?>
                                <div class="no-data">
                                    <i class='bx bx-building-house'></i>
                                    <h3>No Terminals Found</h3>
                                    <p>There are currently no active terminals in your barangay.</p>
                                </div>
                            <?php else: ?>
                                <div class="route-grid">
                                    <?php foreach ($terminals as $terminal): ?>
                                        <div class="route-card">
                                            <div class="route-card-header">
                                                <div>
                                                    <div class="route-code"><?php echo htmlspecialchars($terminal['terminal_code']); ?></div>
                                                    <h3 class="route-name"><?php echo htmlspecialchars($terminal['terminal_name']); ?></h3>
                                                    <div class="route-meta">
                                                        <div class="meta-item">
                                                            <i class='bx bx-buildings'></i>
                                                            <span><?php echo htmlspecialchars($terminal['terminal_type']); ?></span>
                                                        </div>
                                                        <div class="meta-item">
                                                            <i class='bx bx-car'></i>
                                                            <span>Capacity: <?php echo htmlspecialchars($terminal['capacity']); ?> vehicles</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="route-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Location:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($terminal['location']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Operating Hours:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($terminal['operating_hours']); ?></span>
                                                </div>
                                                <?php if ($terminal['contact_person']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Contact Person:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($terminal['contact_person']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($terminal['contact_number']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Contact Number:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($terminal['contact_number']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                                <?php if ($terminal['amenities']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Amenities:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($terminal['amenities']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <h2 style="font-size: 24px; font-weight: 600; margin-top: 40px; margin-bottom: 20px; color: var(--text-color);">Loading Zones</h2>
                            <?php if (empty($loading_zones)): ?>
                                <div class="no-data">
                                    <i class='bx bx-map-pin'></i>
                                    <h3>No Loading Zones Found</h3>
                                    <p>There are currently no active loading zones in your barangay.</p>
                                </div>
                            <?php else: ?>
                                <div class="route-grid">
                                    <?php foreach ($loading_zones as $zone): ?>
                                        <div class="route-card">
                                            <div class="route-card-header">
                                                <div>
                                                    <div class="route-code"><?php echo htmlspecialchars($zone['zone_code']); ?></div>
                                                    <h3 class="route-name"><?php echo htmlspecialchars($zone['zone_name']); ?></h3>
                                                    <div class="route-meta">
                                                        <div class="meta-item">
                                                            <i class='bx bx-map-pin'></i>
                                                            <span>Loading Zone</span>
                                                        </div>
                                                        <div class="meta-item">
                                                            <i class='bx bx-car'></i>
                                                            <span>Capacity: <?php echo htmlspecialchars($zone['capacity']); ?> vehicles</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="route-details">
                                                <div class="detail-item">
                                                    <span class="detail-label">Location:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($zone['location']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Operating Hours:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($zone['operating_hours']); ?></span>
                                                </div>
                                                <div class="detail-item">
                                                    <span class="detail-label">Allowed Vehicles:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($zone['allowed_vehicle_types']); ?></span>
                                                </div>
                                                <?php if ($zone['restrictions']): ?>
                                                <div class="detail-item">
                                                    <span class="detail-label">Restrictions:</span>
                                                    <span class="detail-value"><?php echo htmlspecialchars($zone['restrictions']); ?></span>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Tab 3: Route Restrictions -->
                        <div id="restrictions" class="tab-pane">
                            <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 10px; color: var(--text-color);">Active Route Restrictions</h2>
                            <p style="color: var(--text-light); margin-bottom: 20px;">Roads where tricycles are not allowed or have special restrictions</p>
                            
                            <?php if (empty($restrictions)): ?>
                                <div class="no-data">
                                    <i class='bx bx-no-entry'></i>
                                    <h3>No Active Restrictions</h3>
                                    <p>There are currently no active route restrictions in your barangay.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($restrictions as $restriction): ?>
                                    <div class="restriction-item">
                                        <div class="restriction-type">
                                            <?php echo htmlspecialchars($restriction['restriction_type']); ?>
                                            <?php if ($restriction['route_name']): ?>
                                                <span style="color: var(--text-light); font-size: 14px; margin-left: 10px;">
                                                    (Route: <?php echo htmlspecialchars($restriction['route_name']); ?>)
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="restriction-location">
                                            <strong><?php echo htmlspecialchars($restriction['road_name']); ?></strong> - 
                                            <?php echo htmlspecialchars($restriction['location']); ?>
                                        </div>
                                        <div class="restriction-details">
                                            <?php echo htmlspecialchars($restriction['description']); ?>
                                        </div>
                                        <div style="margin-top: 10px; font-size: 14px;">
                                            <span style="color: var(--text-light);">
                                                Effective: <?php echo date('M d, Y', strtotime($restriction['effective_date'])); ?>
                                                <?php if ($restriction['expiry_date']): ?>
                                                    to <?php echo date('M d, Y', strtotime($restriction['expiry_date'])); ?>
                                                <?php endif; ?>
                                            </span>
                                            <?php if ($restriction['restriction_time_start']): ?>
                                                <span style="color: var(--text-light); margin-left: 15px;">
                                                    Time: <?php echo date('g:i A', strtotime($restriction['restriction_time_start'])); ?> 
                                                    to <?php echo date('g:i A', strtotime($restriction['restriction_time_end'])); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($restriction['penalty_amount'] > 0): ?>
                                                <span style="color: #ef4444; margin-left: 15px; font-weight: 600;">
                                                    Penalty: ₱<?php echo number_format($restriction['penalty_amount'], 2); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Tab 4: Service Updates -->
                        <div id="updates" class="tab-pane">
                            <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 10px; color: var(--text-color);">Service Updates & Advisories</h2>
                            <p style="color: var(--text-light); margin-bottom: 20px;">Latest route changes, advisories, and important notices</p>
                            
                            <?php if (empty($updates)): ?>
                                <div class="no-data">
                                    <i class='bx bx-news'></i>
                                    <h3>No Updates Available</h3>
                                    <p>There are currently no service updates or advisories for your barangay.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($updates as $update): ?>
                                    <div class="update-item">
                                        <div class="update-type">
                                            <?php echo htmlspecialchars($update['update_type']); ?>
                                            <?php if ($update['route_name']): ?>
                                                <span style="color: var(--text-light); font-size: 14px; margin-left: 10px;">
                                                    (Affected Route: <?php echo htmlspecialchars($update['route_name']); ?>)
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($update['priority'] == 'High'): ?>
                                                <span style="color: #f59e0b; margin-left: 10px;">
                                                    <i class='bx bxs-error'></i> High Priority
                                                </span>
                                            <?php elseif ($update['priority'] == 'Emergency'): ?>
                                                <span style="color: #ef4444; margin-left: 10px;">
                                                    <i class='bx bxs-error-circle'></i> Emergency
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <h3 class="update-title"><?php echo htmlspecialchars($update['title']); ?></h3>
                                        <div class="update-meta">
                                            <span>
                                                <i class='bx bx-calendar'></i> 
                                                Effective: <?php echo date('M d, Y', strtotime($update['effective_date'])); ?>
                                            </span>
                                            <?php if ($update['end_date']): ?>
                                                <span>
                                                    <i class='bx bx-calendar-event'></i> 
                                                    Until: <?php echo date('M d, Y', strtotime($update['end_date'])); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="update-details">
                                            <?php echo nl2br(htmlspecialchars($update['description'])); ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Tab 5: Submit Feedback -->
                        <div id="feedback" class="tab-pane">
                            <h2 style="font-size: 24px; font-weight: 600; margin-bottom: 10px; color: var(--text-color);">Submit Route Feedback</h2>
                            <p style="color: var(--text-light); margin-bottom: 20px;">Report issues, suggest improvements, or provide feedback about tricycle services</p>
                            
                            <div class="feedback-form">
                                <form id="routeFeedbackForm">
                                    <div class="form-group">
                                        <label class="form-label">Feedback Type *</label>
                                        <select class="form-control" name="feedback_type" id="feedback_type" required>
                                            <option value="">Select type</option>
                                            <option value="Route Suggestion">Route Suggestion</option>
                                            <option value="Complaint">Complaint</option>
                                            <option value="Compliment">Compliment</option>
                                            <option value="Question">Question</option>
                                            <option value="Report Issue">Report Issue</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Related Route (Optional)</label>
                                        <select class="form-control" name="route_id" id="route_id">
                                            <option value="">Select route (if applicable)</option>
                                            <?php foreach ($routes as $route): ?>
                                                <option value="<?php echo $route['id']; ?>">
                                                    <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Subject *</label>
                                        <input type="text" class="form-control" name="subject" id="subject" 
                                               placeholder="Brief description of your feedback" required>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Message *</label>
                                        <textarea class="form-control" name="message" id="message" 
                                                  placeholder="Provide detailed information about your feedback, including location, time, and any other relevant details..." 
                                                  required></textarea>
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Location (Optional)</label>
                                        <input type="text" class="form-control" name="location" id="location" 
                                               placeholder="Where did this happen?">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Incident Date (Optional)</label>
                                        <input type="datetime-local" class="form-control" name="incident_date" id="incident_date">
                                    </div>

                                    <div class="form-group">
                                        <label class="form-label">Vehicle Number (Optional)</label>
                                        <input type="text" class="form-control" name="vehicle_number" id="vehicle_number" 
                                               placeholder="e.g., TRC-123">
                                    </div>

                                    <div class="form-group" style="margin-top: 30px;">
                                        <button type="submit" class="btn btn-primary" style="padding: 12px 30px;">
                                            <i class='bx bx-send'></i> Submit Feedback
                                        </button>
                                        <button type="reset" class="btn btn-secondary" style="margin-left: 10px;">
                                            <i class='bx bx-reset'></i> Clear Form
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
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
        
        // Check for saved theme preference
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            themeIcon.className = 'bx bx-sun';
            themeText.textContent = 'Light Mode';
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

        // BTRM Specific Functions
        
        // Tab switching function
        function switchTab(tabId) {
            // Hide all tab panes
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Remove active class from all tab buttons
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('active');
            });
            
            // Show selected tab pane
            document.getElementById(tabId).classList.add('active');
            
            // Add active class to clicked tab button
            event.currentTarget.classList.add('active');
        }

        // Search functionality for routes
        document.getElementById('route-search')?.addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const routeCards = document.querySelectorAll('.route-card');
            
            routeCards.forEach(card => {
                const routeName = card.getAttribute('data-route-name');
                const routeCode = card.getAttribute('data-route-code');
                
                if (routeName.includes(searchTerm) || routeCode.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });

        // Toggle favorite status
        function toggleFavorite(routeId) {
            const button = document.querySelector(`.favorite-btn[data-route-id="${routeId}"]`);
            const icon = button.querySelector('i');
            const isFavorite = button.classList.contains('active');
            
            // Send AJAX request to update favorite status
            const formData = new FormData();
            formData.append('action', 'toggle_favorite');
            formData.append('route_id', routeId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (data.action === 'added') {
                        button.classList.add('active');
                        icon.className = 'bx bxs-heart';
                        Swal.fire({
                            icon: 'success',
                            title: 'Added to Favorites',
                            text: 'Route has been added to your favorites',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        button.classList.remove('active');
                        icon.className = 'bx bx-heart';
                        Swal.fire({
                            icon: 'info',
                            title: 'Removed from Favorites',
                            text: 'Route has been removed from your favorites',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    }
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to update favorite status'
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to update favorite status'
                });
            });
        }

        // View route stops
        function viewRouteStops(routeId) {
            const stopsDiv = document.getElementById(`stops-${routeId}`);
            const button = event.currentTarget;
            
            if (stopsDiv.style.display === 'none' || stopsDiv.style.display === '') {
                stopsDiv.style.display = 'block';
                button.innerHTML = '<i class="bx bx-list-minus"></i> Hide Stops';
            } else {
                stopsDiv.style.display = 'none';
                button.innerHTML = '<i class="bx bx-list-ul"></i> View Stops';
            }
        }

        // Show route details modal
        function showRouteDetails(routeId, routeName) {
            // Get route details from the card (simplified version)
            const card = document.querySelector(`.route-card[data-route-id="${routeId}"]`) || 
                        document.querySelector(`.route-card:has(.favorite-btn[data-route-id="${routeId}"])`);
            
            if (card) {
                const routeCode = card.querySelector('.route-code').textContent;
                const details = card.querySelectorAll('.detail-item');
                
                let detailsHtml = `<div style="text-align: left; max-height: 400px; overflow-y: auto;">`;
                details.forEach(detail => {
                    const label = detail.querySelector('.detail-label').textContent;
                    const value = detail.querySelector('.detail-value').textContent;
                    detailsHtml += `<p><strong>${label}</strong> ${value}</p>`;
                });
                detailsHtml += `</div>`;
                
                Swal.fire({
                    title: `${routeCode} - ${routeName}`,
                    html: detailsHtml,
                    confirmButtonText: 'Close',
                    width: 600,
                    showCloseButton: true
                });
            }
        }

        // Handle feedback form submission
        document.getElementById('routeFeedbackForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'submit_feedback');
            
            // Validate form
            if (!formData.get('feedback_type') || !formData.get('subject') || !formData.get('message')) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please fill in all required fields (marked with *)',
                    confirmButtonText: 'OK'
                });
                return;
            }
            
            // Show loading state
            Swal.fire({
                title: 'Submitting Feedback...',
                text: 'Please wait while we process your feedback',
                allowOutsideClick: false,
                showConfirmButton: false,
                willOpen: () => {
                    Swal.showLoading();
                }
            });
            
            // Submit via AJAX
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                Swal.close();
                
                if (data.success) {
                    Swal.fire({
                        icon: 'success',
                        title: 'Feedback Submitted!',
                        html: `
                            <p>Thank you for your feedback. Your reference number is: <strong>${data.feedback_code}</strong></p>
                            <p>We will review your submission and get back to you if needed.</p>
                        `,
                        confirmButtonText: 'OK'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            // Reset form
                            document.getElementById('routeFeedbackForm').reset();
                            // Switch back to routes tab
                            switchTab('routes');
                        }
                    });
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Submission Failed',
                        text: data.message || 'Failed to submit feedback. Please try again.',
                        confirmButtonText: 'OK'
                    });
                }
            })
            .catch(error => {
                Swal.close();
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Submission Failed',
                    text: 'Failed to submit feedback. Please try again.',
                    confirmButtonText: 'OK'
                });
            });
        });

        // Print page function
        function printPage() {
            const printContent = document.querySelector('.tab-pane.active').innerHTML;
            const originalContent = document.body.innerHTML;
            
            document.body.innerHTML = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Barangay Tricycle Route Information - Print</title>
                    <style>
                        body { font-family: Arial, sans-serif; margin: 20px; }
                        h1 { color: #0d9488; }
                        .route-card { border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 8px; }
                        .route-code { background-color: #0d9488; color: white; padding: 4px 8px; border-radius: 4px; display: inline-block; }
                        .detail-item { display: flex; justify-content: space-between; margin-bottom: 5px; }
                        .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #0d9488; padding-bottom: 10px; }
                        .print-footer { text-align: center; margin-top: 30px; font-size: 12px; color: #666; }
                    </style>
                </head>
                <body>
                    <div class="print-header">
                        <h1>Barangay Tricycle Route Management</h1>
                        <p>Printed on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                    </div>
                    ${printContent}
                    <div class="print-footer">
                        <p>Barangay Resident Portal - Traffic and Transport Management System</p>
                    </div>
                </body>
                </html>
            `;
            
            window.print();
            document.body.innerHTML = originalContent;
            location.reload(); // Reload to restore functionality
        }

        // Initialize the page
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there's a hash in URL to switch tab
            const hash = window.location.hash.substring(1);
            if (hash && document.getElementById(hash)) {
                switchTab(hash);
            }
            
            // Add data-route-id attribute to route cards for easier selection
            document.querySelectorAll('.route-card').forEach((card, index) => {
                const routeId = card.querySelector('.favorite-btn')?.getAttribute('data-route-id');
                if (routeId) {
                    card.setAttribute('data-route-id', routeId);
                }
            });
        });
    </script>
</body>
</html>