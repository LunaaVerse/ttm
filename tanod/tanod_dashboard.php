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
        'first_name' => 'Tanod',
        'middle_name' => '',
        'last_name' => 'User',
        'email' => 'tanod@barangay.com',
        'role' => 'TANOD'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'dashboard';

// Fetch dashboard statistics from database for Tanod
$stats = [];

try {
    // Fetch patrol logs count for current tanod
    $sql_patrol_logs = "SELECT COUNT(*) as count FROM patrol_logs WHERE tanod_id = :user_id AND DATE(log_date) = CURDATE()";
    $stmt = $pdo->prepare($sql_patrol_logs);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['today_patrols'] = $row['count'] ?? 0;

    // Fetch total incidents reported by this tanod
    $sql_my_incidents = "SELECT COUNT(*) as count FROM incident_reports WHERE reported_by = :user_id";
    $stmt = $pdo->prepare($sql_my_incidents);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['my_incidents'] = $row['count'] ?? 0;

    // Fetch pending assignments for this tanod
    $sql_pending_assignments = "SELECT COUNT(*) as count FROM patrol_assignments WHERE tanod_id = :user_id AND status = 'pending'";
    $stmt = $pdo->prepare($sql_pending_assignments);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['pending_assignments'] = $row['count'] ?? 0;

    // Fetch recent patrol logs for the dashboard
    $sql_recent_patrols = "SELECT pl.id, pl.location, pl.activity, pl.status, pl.log_date, 
                                  pr.route_name
                           FROM patrol_logs pl
                           LEFT JOIN patrol_routes pr ON pl.route_id = pr.id
                           WHERE pl.tanod_id = :user_id
                           ORDER BY pl.log_date DESC 
                           LIMIT 5";
    $recent_patrols = [];
    $stmt = $pdo->prepare($sql_recent_patrols);
    $stmt->execute(['user_id' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_patrols[] = $row;
    }

    // Fetch today's assignments
    $sql_todays_assignments = "SELECT pa.*, pr.route_name, pr.area_coverage
                               FROM patrol_assignments pa
                               LEFT JOIN patrol_routes pr ON pa.route_id = pr.id
                               WHERE pa.tanod_id = :user_id 
                               AND DATE(pa.assigned_date) = CURDATE()
                               AND pa.status IN ('pending', 'in_progress')
                               ORDER BY pa.priority DESC
                               LIMIT 5";
    $todays_assignments = [];
    $stmt = $pdo->prepare($sql_todays_assignments);
    $stmt->execute(['user_id' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $todays_assignments[] = $row;
    }

} catch (Exception $e) {
    // Use fallback values if database query fails
    $stats['today_patrols'] = 3;
    $stats['my_incidents'] = 12;
    $stats['pending_assignments'] = 2;
    $recent_patrols = [];
    $todays_assignments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tanod Patrol Management</title>
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
        
        /* Activity Chart */
        .activity-chart {
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
        
        /* Patrol Logs */
        .patrol-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .patrol-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .patrol-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .patrol-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .patrol-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .patrol-icon {
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
        
        .patrol-info {
            flex: 1;
            min-width: 0;
        }
        
        .patrol-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .patrol-location {
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
        
        /* Patrol Status */
        .patrol-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        
        .patrol-circle {
            position: relative;
            width: 192px;
            height: 192px;
        }
        
        .patrol-svg {
            transform: rotate(-90deg);
            width: 192px;
            height: 192px;
        }
        
        .patrol-background {
            fill: none;
            stroke: var(--border-color);
            stroke-width: 16;
        }
        
        .patrol-fill {
            fill: none;
            stroke: var(--secondary-color);
            stroke-width: 16;
            stroke-dasharray: 502;
            stroke-dashoffset: 295;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .patrol-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .patrol-value {
            font-size: 48px;
            font-weight: 700;
        }
        
        .patrol-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .patrol-legend {
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
        
        /* Assignment List */
        .assignment-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .assignment-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .assignment-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .assignment-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .assignment-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .assignment-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .assignment-info {
            flex: 1;
            min-width: 0;
        }
        
        .assignment-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .assignment-details {
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
                    <img src="../img/ttm.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <span class="logo-text"> Tanod Patrol Management</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button (Added as first item) -->
                    <a href="tanod_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('road-condition')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-condition" class="submenu">
                        <a href="lrcr/tanod_field_report.php" class="submenu-item">Report Condition</a>
                        <a href="lrcr/follow_up_logs.php" class="submenu-item">Follow-Up Logs</a>
                    </div>
                    
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item" onclick="toggleSubmenu('route-management')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-management" class="submenu">
                        <a href="btrm/route_compliance.php" class="submenu-item">Route Compliance</a>
                        <a href="btrm/incident_logging.php" class="submenu-item">Incident Logging</a>
                        
                    </div>
                   
                    
                    <!-- 1.3 Minor Accident & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-reporting')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Minor Accident & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-reporting" class="submenu">
                        <a href="incidents/report_incident.php" class="submenu-item">Report Incident</a>
                        <a href="incidents/my_reports.php" class="submenu-item">My Reports</a>
                        <a href="incidents/emergency_contacts.php" class="submenu-item">Emergency Contacts</a>
                    </div>
                    
                     <!-- 1.4 Tanod Patrol Logs for Traffic -->
                    <div class="menu-item" onclick="toggleSubmenu('patrol-logs')">
                        <div class="icon-box icon-box-tanod">
                            <i class='bx bxs-notepad'></i>
                        </div>
                        <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="patrol-logs" class="submenu">
                        <a href="patrol_logs/create_log.php" class="submenu-item">Create Patrol Log</a>
                        <a href="patrol_logs/my_logs.php" class="submenu-item">My Patrol Logs</a>
                        <a href="patrol_logs/shift_schedule.php" class="submenu-item">Shift Schedule</a>
                    </div>
                    
                    <!-- 1.5 Permit and Local Regulation Tracking -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-tracking')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit and Local Regulation Tracking</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-tracking" class="submenu">
                        <a href="permits/verify_permit.php" class="submenu-item">Verify Permit</a>
                        <a href="permits/regulation_guide.php" class="submenu-item">Regulation Guide</a>
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
                        <a href="feedback/submit_feedback.php" class="submenu-item">Submit Feedback</a>
                        <a href="feedback/view_feedback.php" class="submenu-item">View Feedback</a>
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
                            <input type="text" placeholder="Search patrol logs or incidents" class="search-input">
                            <kbd class="search-shortcut">🛡️</kbd>
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
                        <h1 class="dashboard-title">Tanod Patrol Dashboard</h1>
                        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Monitor your patrol activities and respond to incidents.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='patrol_logs/create_log.php'">
                            <span style="font-size: 20px;">+</span>
                            Log Patrol Activity
                        </button>
                        <button class="secondary-button" onclick="window.location.href='incidents/report_incident.php'">
                            Report Incident
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Today's Patrols</span>
                            <button class="stat-button stat-button-primary" onclick="window.location.href='patrol_logs/my_logs.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['today_patrols']) ? $stats['today_patrols'] : '3'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Patrol activities today</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">My Incidents Reported</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='incidents/my_reports.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['my_incidents']) ? $stats['my_incidents'] : '12'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Total incidents reported</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Pending Assignments</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='assignments.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['pending_assignments']) ? $stats['pending_assignments'] : '2'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Awaiting action</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Monthly Performance</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='performance.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">92%</div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Completion rate</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Left Column (2/3) -->
                    <div class="left-column">
                        <!-- Daily Activity Chart -->
                        <div class="card">
                            <h2 class="card-title">Daily Patrol Activity</h2>
                            <div class="activity-chart">
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
                        
                        <!-- Quick Actions & Recent Patrols -->
                        <div class="two-column-grid">
                            <!-- Quick Actions -->
                            <div class="card">
                                <h2 class="card-title">Quick Actions</h2>
                                <div class="quick-actions">
                                    <div class="action-button" onclick="window.location.href='patrol_logs/create_log.php'">
                                        <div class="icon-box icon-box-tanod" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-notepad' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Log Patrol</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='incidents/report_incident.php'">
                                        <div class="icon-box icon-box-incident" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-error' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Report Incident</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='road_condition/report_condition.php'">
                                        <div class="icon-box icon-box-road-condition" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-map' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Road Condition</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='permits/verify_permit.php'">
                                        <div class="icon-box icon-box-permit" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-id-card' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Verify Permit</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent Patrols -->
                            <div class="card">
                                <div class="patrol-header">
                                    <h2 class="card-title">Recent Patrols</h2>
                                    <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="window.location.href='patrol_logs/my_logs.php'">View All</button>
                                </div>
                                <div class="patrol-list">
                                    <?php if (!empty($recent_patrols)): ?>
                                        <?php 
                                        $counter = 0;
                                        foreach ($recent_patrols as $patrol): 
                                            $counter++;
                                            $time_ago = time_elapsed_string($patrol['log_date']);
                                            $activity_icon = getPatrolIcon($patrol['activity']);
                                            $status_class = getStatusClass($patrol['status']);
                                            $status_text = ucfirst($patrol['status']);
                                        ?>
                                            <div class="patrol-item">
                                                <div class="patrol-icon <?php echo $activity_icon['color']; ?>">
                                                    <i class='bx <?php echo $activity_icon['icon']; ?>' style="color: var(--icon-tanod);"></i>
                                                </div>
                                                <div class="patrol-info">
                                                    <p class="patrol-name"><?php echo htmlspecialchars($patrol['activity']); ?></p>
                                                    <p class="patrol-location"><?php echo htmlspecialchars($patrol['location']); ?> • <?php echo $time_ago; ?></p>
                                                </div>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </div>
                                        <?php 
                                            if ($counter >= 3) break;
                                        endforeach; 
                                        ?>
                                    <?php else: ?>
                                        <!-- Fallback demo data -->
                                        <div class="patrol-item">
                                            <div class="patrol-icon icon-blue">
                                                <i class='bx bxs-shield' style="color: var(--icon-tanod);"></i>
                                            </div>
                                            <div class="patrol-info">
                                                <p class="patrol-name">Routine Patrol</p>
                                                <p class="patrol-location">Main Street • 15 minutes ago</p>
                                            </div>
                                            <span class="status-badge status-completed">Completed</span>
                                        </div>
                                        <div class="patrol-item">
                                            <div class="patrol-icon icon-yellow">
                                                <i class='bx bxs-time' style="color: var(--icon-tanod);"></i>
                                            </div>
                                            <div class="patrol-info">
                                                <p class="patrol-name">Traffic Control</p>
                                                <p class="patrol-location">Market Area • 45 minutes ago</p>
                                            </div>
                                            <span class="status-badge status-progress">In Progress</span>
                                        </div>
                                        <div class="patrol-item">
                                            <div class="patrol-icon icon-red">
                                                <i class='bx bxs-error' style="color: var(--icon-tanod);"></i>
                                            </div>
                                            <div class="patrol-info">
                                                <p class="patrol-name">Incident Response</p>
                                                <p class="patrol-location">School Zone • 1 hour ago</p>
                                            </div>
                                            <span class="status-badge status-pending">Pending</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column (1/3) -->
                    <div class="right-column">
                        <!-- Today's Assignments -->
                        <div class="card">
                            <h2 class="card-title">Today's Assignments</h2>
                            <?php if (!empty($todays_assignments)): ?>
                                <div class="assignment-list">
                                    <?php 
                                    $counter = 0;
                                    foreach ($todays_assignments as $assignment): 
                                        $counter++;
                                        $priority_icon = getPriorityIcon($assignment['priority']);
                                        $priority_color = getPriorityColor($assignment['priority']);
                                    ?>
                                        <div class="assignment-item">
                                            <div class="assignment-icon" style="background-color: <?php echo $priority_color; ?>20;">
                                                <i class='bx <?php echo $priority_icon; ?>' style="color: <?php echo $priority_color; ?>;"></i>
                                            </div>
                                            <div class="assignment-info">
                                                <p class="assignment-name"><?php echo htmlspecialchars($assignment['route_name']); ?></p>
                                                <p class="assignment-details"><?php echo htmlspecialchars($assignment['area_coverage']); ?></p>
                                            </div>
                                            <button class="alert-button" style="font-size: 12px; padding: 4px 12px;" onclick="window.location.href='assignment_details.php?id=<?php echo $assignment['id']; ?>'">
                                                Start
                                            </button>
                                        </div>
                                    <?php 
                                        if ($counter >= 3) break;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="alert-card">
                                    <h3 class="alert-title">No Assignments Today</h3>
                                    <p class="alert-time">Check back later or contact supervisor</p>
                                    <button class="alert-button" onclick="window.location.href='patrol_logs/create_log.php'">
                                        <i class='bx bxs-notepad button-icon'></i>
                                        Log Patrol Activity
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Emergency Alerts -->
                        <div class="card">
                            <h2 class="card-title">Emergency Alerts</h2>
                            <div class="alert-card">
                                <h3 class="alert-title">Accident Reported - Market Street</h3>
                                <p class="alert-time">5 minutes ago • Requires immediate response</p>
                                <button class="alert-button" onclick="window.location.href='incidents/report_incident.php'">
                                    <i class='bx bxs-first-aid button-icon'></i>
                                    Respond Now
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Road Hazard - Fallen Tree</h3>
                                <p class="alert-time">30 minutes ago • Blocking traffic</p>
                                <button class="alert-button" onclick="window.location.href='road_condition/report_condition.php'">
                                    <i class='bx bxs-map button-icon'></i>
                                    Report Condition
                                </button>
                            </div>
                        </div>
                        
                        <!-- Patrol Performance -->
                        <div class="card">
                            <h2 class="card-title">Patrol Performance</h2>
                            <div class="patrol-container">
                                <div class="patrol-circle">
                                    <svg class="patrol-svg">
                                        <circle cx="96" cy="96" r="80" class="patrol-background"></circle>
                                        <circle cx="96" cy="96" r="80" class="patrol-fill"></circle>
                                    </svg>
                                    <div class="patrol-text">
                                        <span class="patrol-value">92%</span>
                                        <span class="patrol-label">Completion Rate</span>
                                    </div>
                                </div>
                            </div>
                            <div class="patrol-legend">
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: var(--secondary-color);"></div>
                                    <span class="text-gray-600">Completed</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: #f59e0b;"></div>
                                    <span class="text-gray-600">In Progress</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: #ef4444;"></div>
                                    <span class="text-gray-600">Pending</span>
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

// Helper function to get patrol icon based on activity
function getPatrolIcon($activity) {
    $icons = [
        'routine_patrol' => ['icon' => 'bxs-shield', 'color' => 'icon-blue'],
        'traffic_control' => ['icon' => 'bxs-traffic', 'color' => 'icon-yellow'],
        'incident_response' => ['icon' => 'bxs-error', 'color' => 'icon-red'],
        'permit_check' => ['icon' => 'bxs-id-card', 'color' => 'icon-purple'],
        'road_inspection' => ['icon' => 'bxs-map', 'color' => 'icon-cyan'],
        'community_engagement' => ['icon' => 'bxs-group', 'color' => 'icon-indigo']
    ];
    
    return $icons[$activity] ?? ['icon' => 'bxs-shield', 'color' => 'icon-blue'];
}

// Helper function to get status class
function getStatusClass($status) {
    $classes = [
        'completed' => 'status-completed',
        'in_progress' => 'status-progress',
        'pending' => 'status-pending',
        'cancelled' => 'status-pending'
    ];
    
    return $classes[$status] ?? 'status-pending';
}

// Helper function to get priority icon
function getPriorityIcon($priority) {
    $icons = [
        'high' => 'bxs-flag-alt',
        'medium' => 'bxs-flag',
        'low' => 'bxs-flag'
    ];
    
    return $icons[$priority] ?? 'bxs-flag';
}

// Helper function to get priority color
function getPriorityColor($priority) {
    $colors = [
        'high' => '#ef4444',
        'medium' => '#f59e0b',
        'low' => '#10b981'
    ];
    
    return $colors[$priority] ?? '#6b7280';
}
?>