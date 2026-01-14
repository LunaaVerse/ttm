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
$current_page = 'dashboard';

// Fetch dashboard statistics from database
$stats = [];

try {
    // Fetch active incidents count
    $sql_active_incidents = "SELECT COUNT(*) as count FROM incident_reports WHERE status IN ('pending', 'under_review', 'action_taken')";
    $stmt = $pdo->query($sql_active_incidents);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['active_incidents'] = $row['count'] ?? 24; // Fallback

    // Fetch total incident reports count
    $sql_total_incidents = "SELECT COUNT(*) as count FROM incident_reports";
    $stmt = $pdo->query($sql_total_incidents);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['total_incidents'] = $row['count'] ?? 150; // Fallback

    // Fetch patrol routes count
    $sql_patrol_routes = "SELECT COUNT(*) as count FROM patrol_routes WHERE status = 'Active'";
    $stmt = $pdo->query($sql_patrol_routes);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['patrol_routes'] = $row['count'] ?? 15; // Fallback

    // Fetch recent incidents for the dashboard
    $sql_recent_incidents = "SELECT id, incident_type, location, description, severity, status, created_at 
                             FROM incident_reports 
                             ORDER BY created_at DESC 
                             LIMIT 5";
    $recent_incidents = [];
    $stmt = $pdo->query($sql_recent_incidents);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_incidents[] = $row;
    }
} catch (Exception $e) {
    // Use fallback values if database query fails
    $stats['active_incidents'] = 24;
    $stats['total_incidents'] = 150;
    $stats['patrol_routes'] = 15;
    $recent_incidents = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
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
        
        /* Traffic Flow Chart */
        .traffic-chart {
            display: flex;
            align-items: flex-end;
            justify-content: space-around;
            height: 256px;
        }
        
        .chart-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 8px;
        }
        
        .chart-bar-value {
            width: 64px;
            border-radius: 8px 8px 0 0;
            transition: height 0.5s ease;
        }
        
        .chart-bar-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .bar-striped {
            background: repeating-linear-gradient(
                45deg,
                var(--border-color),
                var(--border-color) 10px,
                var(--background-color) 10px,
                var(--background-color) 20px
            );
        }
        
        .bar-filled {
            background-color: var(--secondary-color);
        }
        
        .bar-highlight {
            position: relative;
        }
        
        .bar-highlight::before {
            content: "54%";
            position: absolute;
            top: -24px;
            font-size: 12px;
            color: var(--text-light);
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
        
        /* Incident Reports */
        .incident-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .incident-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .incident-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .incident-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .incident-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .incident-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .icon-blue {
            background-color: #dbeafe;
        }
        
        .dark-mode .icon-blue {
            background-color: rgba(59, 130, 246, 0.2);
        }
        
        .icon-cyan {
            background-color: #cffafe;
        }
        
        .dark-mode .icon-cyan {
            background-color: rgba(6, 182, 212, 0.2);
        }
        
        .icon-purple {
            background-color: #e9d5ff;
        }
        
        .dark-mode .icon-purple {
            background-color: rgba(139, 92, 246, 0.2);
        }
        
        .icon-yellow {
            background-color: #fef3c7;
        }
        
        .dark-mode .icon-yellow {
            background-color: rgba(245, 158, 11, 0.2);
        }
        
        .icon-indigo {
            background-color: #e0e7ff;
        }
        
        .dark-mode .icon-indigo {
            background-color: rgba(99, 102, 241, 0.2);
        }
        
        .icon-red {
            background-color: #fee2e2;
        }
        
        .dark-mode .icon-red {
            background-color: rgba(239, 68, 68, 0.2);
        }
        
        .incident-info {
            flex: 1;
            min-width: 0;
        }
        
        .incident-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .incident-location {
            font-size: 12px;
            color: var(--text-light);
        }
        
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
        
        /* Traffic Signal Status */
        .signal-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        
        .signal-circle {
            position: relative;
            width: 192px;
            height: 192px;
        }
        
        .signal-svg {
            transform: rotate(-90deg);
            width: 192px;
            height: 192px;
        }
        
        .signal-background {
            fill: none;
            stroke: var(--border-color);
            stroke-width: 16;
        }
        
        .signal-fill {
            fill: none;
            stroke: var(--secondary-color);
            stroke-width: 16;
            stroke-dasharray: 502;
            stroke-dashoffset: 295;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .signal-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .signal-value {
            font-size: 48px;
            font-weight: 700;
        }
        
        .signal-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .signal-legend {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 24px;
            font-size: 14px;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .dot-operational {
            background-color: var(--secondary-color);
        }
        
        .dot-maintenance {
            background-color: var(--text-color);
        }
        
        .dot-offline {
            background: repeating-linear-gradient(
                45deg,
                var(--border-color),
                var(--border-color) 10px,
                var(--background-color) 10px,
                var(--background-color) 20px
            );
            border-radius: 50%;
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
        
        /* Traffic Routes */
        .route-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .route-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .route-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .route-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .route-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .route-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .route-info {
            flex: 1;
            min-width: 0;
        }
        
        .route-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .route-details {
            font-size: 12px;
            color: var(--text-light);
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
                        <a href="btrm/tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="btrm/driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="btrm/approval_enforcement.php" class="submenu-item">Enforcement Rules & Analytics</a>
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
                        <a href="mair/admin_report_review.php" class="submenu-item">Report Review & Validation</a>
                        <a href="mair/severity_analytics.php" class="submenu-item">Severity & Safety Review</a>
                        <a href="mair/action_referrals.php" class="submenu-item">referrals</a>
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
                        <h1 class="dashboard-title">Traffic Dashboard</h1>
                        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Monitor, manage, and optimize traffic flow across the city.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='report_incident.php'">
                            <span style="font-size: 20px;">+</span>
                            Report Incident
                        </button>
                        <button class="secondary-button" onclick="window.location.href='export_data.php'">
                            Export Data
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Active Incidents</span>
                            <button class="stat-button stat-button-primary" onclick="window.location.href='active_incidents.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['active_incidents']) ? $stats['active_incidents'] : '24'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>From database</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Total Reports</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='all_incidents.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['total_incidents']) ? $stats['total_incidents'] : '150'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Incident reports total</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Active Patrol Routes</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='patrol_routes.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['patrol_routes']) ? $stats['patrol_routes'] : '15'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Currently active</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">System Status</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='system_status.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">98%</div>
                        <div class="stat-info">
                            <span>All systems operational</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Left Column (2/3) -->
                    <div class="left-column">
                        <!-- Traffic Flow Chart -->
                        <div class="card">
                            <h2 class="card-title">Traffic Flow Analysis</h2>
                            <div class="traffic-chart">
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 35%;"></div>
                                    <span class="chart-bar-label">6 AM</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-filled" style="height: 75%;"></div>
                                    <span class="chart-bar-label">8 AM</span>
                                </div>
                                <div class="chart-bar bar-highlight">
                                    <div class="chart-bar-value bar-filled" style="height: 90%;"></div>
                                    <span class="chart-bar-label">10 AM</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-filled" style="height: 100%;"></div>
                                    <span class="chart-bar-label">12 PM</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 40%;"></div>
                                    <span class="chart-bar-label">2 PM</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 55%;"></div>
                                    <span class="chart-bar-label">4 PM</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 45%;"></div>
                                    <span class="chart-bar-label">6 PM</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions & Incident Reports -->
                        <div class="two-column-grid">
                            <!-- Quick Actions -->
                            <div class="card">
                                <h2 class="card-title">Quick Actions</h2>
                                <div class="quick-actions">
                                    <div class="action-button" onclick="window.location.href='report_incident.php'">
                                        <div class="icon-box icon-box-incident" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-error' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Report Incident</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='adjust_signals.php'">
                                        <div class="icon-box icon-box-permit" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-traffic' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Adjust Signals</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='plan_diversion.php'">
                                        <div class="icon-box icon-box-tanod" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-navigation' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Plan Diversion</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='issue_ticket.php'">
                                        <div class="icon-box icon-box-permit" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-receipt' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Issue Ticket</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Incident Reports -->
                            <div class="card">
                                <div class="incident-header">
                                    <h2 class="card-title">Recent Incidents</h2>
                                    <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="window.location.href='all_incidents.php'">View All</button>
                                </div>
                                <div class="incident-list">
                                    <?php if (!empty($recent_incidents)): ?>
                                        <?php 
                                        $status_colors = [
                                            'pending' => 'red',
                                            'under_review' => 'yellow',
                                            'verified' => 'blue',
                                            'action_taken' => 'cyan',
                                            'resolved' => 'green',
                                            'rejected' => 'gray'
                                        ];
                                        
                                        $status_texts = [
                                            'pending' => 'Pending',
                                            'under_review' => 'In Progress',
                                            'verified' => 'Verified',
                                            'action_taken' => 'Action Taken',
                                            'resolved' => 'Resolved',
                                            'rejected' => 'Rejected'
                                        ];
                                        
                                        $status_badge_classes = [
                                            'pending' => 'status-pending',
                                            'under_review' => 'status-progress',
                                            'verified' => 'status-progress',
                                            'action_taken' => 'status-progress',
                                            'resolved' => 'status-completed',
                                            'rejected' => 'status-pending'
                                        ];
                                        
                                        $icon_map = [
                                            'road_hazard' => 'bxs-map',
                                            'accident' => 'bxs-error',
                                            'emergency' => 'bxs-first-aid',
                                            'traffic_violation' => 'bxs-traffic',
                                            'other' => 'bxs-report'
                                        ];
                                        
                                        $counter = 0;
                                        foreach ($recent_incidents as $incident): 
                                            $counter++;
                                            $color_class = 'icon-' . $status_colors[$incident['status']] ?? 'icon-blue';
                                            $icon_class = $icon_map[$incident['incident_type']] ?? 'bxs-report';
                                            $time_ago = time_elapsed_string($incident['created_at']);
                                        ?>
                                            <div class="incident-item">
                                                <div class="incident-icon <?php echo $color_class; ?>">
                                                    <i class='bx <?php echo $icon_class; ?>' style="color: var(--icon-incident);"></i>
                                                </div>
                                                <div class="incident-info">
                                                    <p class="incident-name"><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $incident['incident_type']))); ?> - <?php echo htmlspecialchars($incident['location']); ?></p>
                                                    <p class="incident-location">Reported: <?php echo $time_ago; ?></p>
                                                </div>
                                                <span class="status-badge <?php echo $status_badge_classes[$incident['status']] ?? 'status-pending'; ?>">
                                                    <?php echo $status_texts[$incident['status']] ?? 'Pending'; ?>
                                                </span>
                                            </div>
                                        <?php 
                                            if ($counter >= 3) break;
                                        endforeach; 
                                        ?>
                                    <?php else: ?>
                                        <!-- Fallback demo data -->
                                        <div class="incident-item">
                                            <div class="incident-icon icon-red">
                                                <i class='bx bxs-error' style="color: var(--icon-incident);"></i>
                                            </div>
                                            <div class="incident-info">
                                                <p class="incident-name">Vehicle Collision - Main St & 5th Ave</p>
                                                <p class="incident-location">Reported: 15 minutes ago</p>
                                            </div>
                                            <span class="status-badge status-pending">Pending</span>
                                        </div>
                                        <div class="incident-item">
                                            <div class="incident-icon icon-yellow">
                                                <i class='bx bxs-time' style="color: var(--icon-tanod);"></i>
                                            </div>
                                            <div class="incident-info">
                                                <p class="incident-name">Traffic Signal Malfunction</p>
                                                <p class="incident-location">Reported: 45 minutes ago</p>
                                            </div>
                                            <span class="status-badge status-progress">In Progress</span>
                                        </div>
                                        <div class="incident-item">
                                            <div class="incident-icon icon-blue">
                                                <i class='bx bxs-map' style="color: var(--icon-road-condition);"></i>
                                            </div>
                                            <div class="incident-info">
                                                <p class="incident-name">Road Debris - Highway 101</p>
                                                <p class="incident-location">Reported: 1 hour ago</p>
                                            </div>
                                            <span class="status-badge status-completed">Resolved</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column (1/3) -->
                    <div class="right-column">
                        <!-- Traffic Alerts -->
                        <div class="card">
                            <h2 class="card-title">Traffic Alerts</h2>
                            <div class="alert-card">
                                <h3 class="alert-title">Heavy Congestion - Downtown Area</h3>
                                <p class="alert-time">Expected duration: 2-3 hours</p>
                                <button class="alert-button" onclick="window.location.href='alternative_routes.php'">
                                    <i class='bx bxs-navigation button-icon'></i>
                                    Plan Alternative Route
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Road Closure - Bridge Maintenance</h3>
                                <p class="alert-time">Effective: Tomorrow 6 AM - 6 PM</p>
                                <button class="alert-button" onclick="window.location.href='detour_map.php'">
                                    <i class='bx bxs-map button-icon'></i>
                                    View Detour Map
                                </button>
                            </div>
                        </div>
                        
                        <!-- Traffic Routes -->
                        <div class="card">
                            <div class="route-header">
                                <h2 class="card-title">Recommended Routes</h2>
                                <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="window.location.href='refresh_routes.php'">Refresh</button>
                            </div>
                            <div class="route-list">
                                <div class="route-item">
                                    <div class="route-icon icon-cyan">
                                        <i class='bx bxs-navigation' style="color: var(--icon-tanod);"></i>
                                    </div>
                                    <div class="route-info">
                                        <p class="route-name">Downtown to Airport</p>
                                        <p class="route-details">Estimated time: 25 min (low traffic)</p>
                                    </div>
                                </div>
                                <div class="route-item">
                                    <div class="route-icon icon-purple">
                                        <i class='bx bxs-navigation' style="color: var(--icon-tanod);"></i>
                                    </div>
                                    <div class="route-info">
                                        <p class="route-name">North District to Business Park</p>
                                        <p class="route-details">Estimated time: 18 min (moderate traffic)</p>
                                    </div>
                                </div>
                                <div class="route-item">
                                    <div class="route-icon icon-indigo">
                                        <i class='bx bxs-navigation' style="color: var(--icon-tanod);"></i>
                                    </div>
                                    <div class="route-info">
                                        <p class="route-name">East Side to Shopping Mall</p>
                                        <p class="route-details">Estimated time: 22 min (light traffic)</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Traffic Signal Status -->
                        <div class="card">
                            <h2 class="card-title">Signal System Status</h2>
                            <div class="signal-container">
                                <div class="signal-circle">
                                    <svg class="signal-svg">
                                        <circle cx="96" cy="96" r="80" class="signal-background"></circle>
                                        <circle cx="96" cy="96" r="80" class="signal-fill"></circle>
                                    </svg>
                                    <div class="signal-text">
                                        <span class="signal-value">98%</span>
                                        <span class="signal-label">Operational</span>
                                    </div>
                                </div>
                            </div>
                            <div class="signal-legend">
                                <div class="legend-item">
                                    <div class="legend-dot dot-operational"></div>
                                    <span class="text-gray-600">Operational</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-maintenance"></div>
                                    <span class="text-gray-600">Maintenance</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot dot-offline"></div>
                                    <span class="text-gray-600">Offline</span>
                                </div>
                            </div>
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
        
        // Animate chart bars on page load
        window.addEventListener('load', function() {
            const bars = document.querySelectorAll('.chart-bar-value');
            bars.forEach(bar => {
                const height = bar.style.height;
                bar.style.height = '0%';
                setTimeout(() => {
                    bar.style.height = height;
                }, 300);
            });
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


