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
$current_page = 'dashboard';

// Fetch dashboard statistics from database for User
$stats = [];

try {
    // Fetch reported incidents count for current user
    $sql_my_reports = "SELECT COUNT(*) as count FROM incident_reports WHERE reported_by = :user_id";
    $stmt = $pdo->prepare($sql_my_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['my_reports'] = $row['count'] ?? 0;

    // Fetch road condition reports by this user
    $sql_road_reports = "SELECT COUNT(*) as count FROM road_condition_reports WHERE reported_by = :user_id";
    $stmt = $pdo->prepare($sql_road_reports);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['road_reports'] = $row['count'] ?? 0;

    // Fetch feedback submitted by this user
    $sql_feedback = "SELECT COUNT(*) as count FROM feedback WHERE submitted_by = :user_id";
    $stmt = $pdo->prepare($sql_feedback);
    $stmt->execute(['user_id' => $user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $stats['my_feedback'] = $row['count'] ?? 0;

    // Fetch recent incident reports for the dashboard
    $sql_recent_reports = "SELECT ir.id, ir.incident_type, ir.location, ir.status, ir.reported_date, 
                                  ir.description
                           FROM incident_reports ir
                           WHERE ir.reported_by = :user_id
                           ORDER BY ir.reported_date DESC 
                           LIMIT 5";
    $recent_reports = [];
    $stmt = $pdo->prepare($sql_recent_reports);
    $stmt->execute(['user_id' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_reports[] = $row;
    }

    // Fetch recent feedback
    $sql_recent_feedback = "SELECT f.*, 
                            CASE 
                                WHEN f.feedback_type = 'complaint' THEN 'Complaint'
                                WHEN f.feedback_type = 'suggestion' THEN 'Suggestion'
                                WHEN f.feedback_type = 'compliment' THEN 'Compliment'
                                ELSE 'General'
                            END as type_label
                           FROM feedback f
                           WHERE f.submitted_by = :user_id 
                           ORDER BY f.submitted_date DESC
                           LIMIT 5";
    $recent_feedback = [];
    $stmt = $pdo->prepare($sql_recent_feedback);
    $stmt->execute(['user_id' => $user_id]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_feedback[] = $row;
    }

} catch (Exception $e) {
    // Use fallback values if database query fails
    $stats['my_reports'] = 2;
    $stats['road_reports'] = 5;
    $stats['my_feedback'] = 3;
    $recent_reports = [];
    $recent_feedback = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Resident Portal</title>
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
        
        /* Report List */
        .report-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .report-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .report-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .report-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .report-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .report-icon {
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
        
        .report-info {
            flex: 1;
            min-width: 0;
        }
        
        .report-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .report-location {
            font-size: 12px;
            color: var(--text-light);
        }
        
        .status-badge {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .status-resolved {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .status-resolved {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .status-inprogress {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .status-inprogress {
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
        
        .status-review {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .status-review {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        /* Community Engagement */
        .engagement-container {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }
        
        .engagement-circle {
            position: relative;
            width: 192px;
            height: 192px;
        }
        
        .engagement-svg {
            transform: rotate(-90deg);
            width: 192px;
            height: 192px;
        }
        
        .engagement-background {
            fill: none;
            stroke: var(--border-color);
            stroke-width: 16;
        }
        
        .engagement-fill {
            fill: none;
            stroke: var(--secondary-color);
            stroke-width: 16;
            stroke-dasharray: 502;
            stroke-dashoffset: 295;
            stroke-linecap: round;
            transition: stroke-dashoffset 1s ease;
        }
        
        .engagement-text {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        
        .engagement-value {
            font-size: 48px;
            font-weight: 700;
        }
        
        .engagement-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .engagement-legend {
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
        
        .dot-reported {
            background-color: var(--secondary-color);
        }
        
        .dot-resolved {
            background-color: var(--text-color);
        }
        
        .dot-pending {
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
        
        /* Feedback List */
        .feedback-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .feedback-list {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .feedback-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }
        
        .feedback-item:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .feedback-item:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .feedback-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .feedback-info {
            flex: 1;
            min-width: 0;
        }
        
        .feedback-name {
            font-size: 14px;
            font-weight: 500;
        }
        
        .feedback-details {
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
                        <a href="btrm/tricycle_route_info.php" class="submenu-item">View Routes</a>
                        <a href="user_routes/schedule.php" class="submenu-item">Schedule Information</a>
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
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <!-- Title and Actions -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="dashboard-title">Welcome to Barangay Portal</h1>
                        <p class="dashboard-subtitle">Hello, <?php echo htmlspecialchars($full_name); ?>! Access barangay services and report community concerns.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='user_incidents/report_incident.php'">
                            <span style="font-size: 20px;">+</span>
                            Report Incident
                        </button>
                        <button class="secondary-button" onclick="window.location.href='user_feedback/submit_feedback.php'">
                            Submit Feedback
                        </button>
                    </div>
                </div>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">My Incident Reports</span>
                            <button class="stat-button stat-button-primary" onclick="window.location.href='user_incidents/my_reports.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['my_reports']) ? $stats['my_reports'] : '2'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Incidents reported</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Road Condition Reports</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='user_road_condition/my_reports.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['road_reports']) ? $stats['road_reports'] : '5'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Road issues reported</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Feedback Submitted</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='user_feedback/my_feedback.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value"><?php echo isset($stats['my_feedback']) ? $stats['my_feedback'] : '3'; ?></div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Community feedback</span>
                        </div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Report Resolution Rate</span>
                            <button class="stat-button stat-button-white" onclick="window.location.href='user_reports.php'">
                                <svg class="menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                </svg>
                            </button>
                        </div>
                        <div class="stat-value">78%</div>
                        <div class="stat-info">
                            <svg class="stat-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
                            </svg>
                            <span>Issues resolved</span>
                        </div>
                    </div>
                </div>
                
                <!-- Main Grid -->
                <div class="main-grid">
                    <!-- Left Column (2/3) -->
                    <div class="left-column">
                        <!-- Community Activity Chart -->
                        <div class="card">
                            <h2 class="card-title">Community Activity Overview</h2>
                            <div class="activity-chart">
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 35%;"></div>
                                    <span class="chart-bar-label">Jan</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-filled" style="height: 65%;"></div>
                                    <span class="chart-bar-label">Feb</span>
                                </div>
                                <div class="chart-bar bar-highlight">
                                    <div class="chart-bar-value bar-filled" style="height: 90%;"></div>
                                    <span class="chart-bar-label">Mar</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-filled" style="height: 70%;"></div>
                                    <span class="chart-bar-label">Apr</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 40%;"></div>
                                    <span class="chart-bar-label">May</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 85%;"></div>
                                    <span class="chart-bar-label">Jun</span>
                                </div>
                                <div class="chart-bar">
                                    <div class="chart-bar-value bar-striped" style="height: 60%;"></div>
                                    <span class="chart-bar-label">Jul</span>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Quick Actions & Recent Reports -->
                        <div class="two-column-grid">
                            <!-- Quick Actions -->
                            <div class="card">
                                <h2 class="card-title">Quick Actions</h2>
                                <div class="quick-actions">
                                    <div class="action-button" onclick="window.location.href='user_incidents/report_incident.php'">
                                        <div class="icon-box icon-box-incident" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-error' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Report Incident</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='user_road_condition/report_condition.php'">
                                        <div class="icon-box icon-box-road-condition" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-map' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Road Issue</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='user_feedback/submit_feedback.php'">
                                        <div class="icon-box icon-box-feedback" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-chat' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Submit Feedback</span>
                                    </div>
                                    <div class="action-button" onclick="window.location.href='user_permits/check_permit.php'">
                                        <div class="icon-box icon-box-permit" style="width: 48px; height: 48px;">
                                            <i class='bx bxs-id-card' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Check Permit</span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recent Reports -->
                            <div class="card">
                                <div class="report-header">
                                    <h2 class="card-title">My Recent Reports</h2>
                                    <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="window.location.href='user_incidents/my_reports.php'">View All</button>
                                </div>
                                <div class="report-list">
                                    <?php if (!empty($recent_reports)): ?>
                                        <?php 
                                        $counter = 0;
                                        foreach ($recent_reports as $report): 
                                            $counter++;
                                            $time_ago = time_elapsed_string($report['reported_date']);
                                            $incident_icon = getIncidentIcon($report['incident_type']);
                                            $status_class = getReportStatusClass($report['status']);
                                            $status_text = ucfirst($report['status']);
                                        ?>
                                            <div class="report-item">
                                                <div class="report-icon <?php echo $incident_icon['color']; ?>">
                                                    <i class='bx <?php echo $incident_icon['icon']; ?>' style="color: var(--icon-incident);"></i>
                                                </div>
                                                <div class="report-info">
                                                    <p class="report-name"><?php echo htmlspecialchars($report['incident_type']); ?></p>
                                                    <p class="report-location"><?php echo htmlspecialchars($report['location']); ?> • <?php echo $time_ago; ?></p>
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
                                        <div class="report-item">
                                            <div class="report-icon icon-blue">
                                                <i class='bx bxs-error-alt' style="color: var(--icon-incident);"></i>
                                            </div>
                                            <div class="report-info">
                                                <p class="report-name">Minor Accident</p>
                                                <p class="report-location">Main Street • 2 days ago</p>
                                            </div>
                                            <span class="status-badge status-resolved">Resolved</span>
                                        </div>
                                        <div class="report-item">
                                            <div class="report-icon icon-yellow">
                                                <i class='bx bxs-error' style="color: var(--icon-incident);"></i>
                                            </div>
                                            <div class="report-info">
                                                <p class="report-name">Traffic Issue</p>
                                                <p class="report-location">Market Area • 3 days ago</p>
                                            </div>
                                            <span class="status-badge status-inprogress">In Progress</span>
                                        </div>
                                        <div class="report-item">
                                            <div class="report-icon icon-red">
                                                <i class='bx bxs-warning' style="color: var(--icon-incident);"></i>
                                            </div>
                                            <div class="report-info">
                                                <p class="report-name">Road Hazard</p>
                                                <p class="report-location">School Zone • 1 week ago</p>
                                            </div>
                                            <span class="status-badge status-review">Under Review</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Right Column (1/3) -->
                    <div class="right-column">
                        <!-- My Feedback -->
                        <div class="card">
                            <h2 class="card-title">Recent Feedback</h2>
                            <?php if (!empty($recent_feedback)): ?>
                                <div class="feedback-list">
                                    <?php 
                                    $counter = 0;
                                    foreach ($recent_feedback as $feedback): 
                                        $counter++;
                                        $time_ago = time_elapsed_string($feedback['submitted_date']);
                                        $feedback_icon = getFeedbackIcon($feedback['feedback_type']);
                                    ?>
                                        <div class="feedback-item">
                                            <div class="feedback-icon" style="background-color: <?php echo $feedback_icon['color']; ?>20;">
                                                <i class='bx <?php echo $feedback_icon['icon']; ?>' style="color: <?php echo $feedback_icon['color']; ?>;"></i>
                                            </div>
                                            <div class="feedback-info">
                                                <p class="feedback-name"><?php echo htmlspecialchars($feedback['type_label']); ?></p>
                                                <p class="feedback-details"><?php echo substr(htmlspecialchars($feedback['message']), 0, 30); ?>... • <?php echo $time_ago; ?></p>
                                            </div>
                                        </div>
                                    <?php 
                                        if ($counter >= 2) break;
                                    endforeach; 
                                    ?>
                                </div>
                            <?php else: ?>
                                <div class="alert-card">
                                    <h3 class="alert-title">No Feedback Yet</h3>
                                    <p class="alert-time">Share your thoughts with the barangay</p>
                                    <button class="alert-button" onclick="window.location.href='user_feedback/submit_feedback.php'">
                                        <i class='bx bxs-chat button-icon'></i>
                                        Submit Feedback
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Community Alerts -->
                        <div class="card">
                            <h2 class="card-title">Community Alerts</h2>
                            <div class="alert-card">
                                <h3 class="alert-title">Road Repair - Market Street</h3>
                                <p class="alert-time">Starting tomorrow • Expect traffic delays</p>
                                <button class="alert-button" onclick="window.location.href='user_routes/view_routes.php'">
                                    <i class='bx bxs-map-alt button-icon'></i>
                                    View Alternate Routes
                                </button>
                            </div>
                            <div class="alert-card">
                                <h3 class="alert-title">Community Meeting</h3>
                                <p class="alert-time">Saturday 3PM • Barangay Hall</p>
                                <button class="alert-button" onclick="window.location.href='user_feedback/submit_feedback.php'">
                                    <i class='bx bxs-chat button-icon'></i>
                                    Submit Agenda Item
                                </button>
                            </div>
                        </div>
                        
                        <!-- Community Engagement -->
                        <div class="card">
                            <h2 class="card-title">Community Engagement</h2>
                            <div class="engagement-container">
                                <div class="engagement-circle">
                                    <svg class="engagement-svg">
                                        <circle cx="96" cy="96" r="80" class="engagement-background"></circle>
                                        <circle cx="96" cy="96" r="80" class="engagement-fill"></circle>
                                    </svg>
                                    <div class="engagement-text">
                                        <span class="engagement-value">78%</span>
                                        <span class="engagement-label">Active Participation</span>
                                    </div>
                                </div>
                            </div>
                            <div class="engagement-legend">
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: var(--secondary-color);"></div>
                                    <span class="text-gray-600">Reports Made</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: #10b981;"></div>
                                    <span class="text-gray-600">Issues Resolved</span>
                                </div>
                                <div class="legend-item">
                                    <div class="legend-dot" style="background-color: #6b7280;"></div>
                                    <span class="text-gray-600">Awaiting Action</span>
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

// Helper function to get incident icon based on type
function getIncidentIcon($incident_type) {
    $icons = [
        'accident' => ['icon' => 'bxs-car-crash', 'color' => 'icon-red'],
        'traffic' => ['icon' => 'bxs-traffic', 'color' => 'icon-yellow'],
        'road_hazard' => ['icon' => 'bxs-warning', 'color' => 'icon-red'],
        'noise_complaint' => ['icon' => 'bxs-volume-full', 'color' => 'icon-purple'],
        'safety_concern' => ['icon' => 'bxs-shield', 'color' => 'icon-blue'],
        'other' => ['icon' => 'bxs-error-alt', 'color' => 'icon-indigo']
    ];
    
    return $icons[$incident_type] ?? ['icon' => 'bxs-error-alt', 'color' => 'icon-blue'];
}

// Helper function to get report status class
function getReportStatusClass($status) {
    $classes = [
        'resolved' => 'status-resolved',
        'in_progress' => 'status-inprogress',
        'pending' => 'status-pending',
        'review' => 'status-review',
        'cancelled' => 'status-pending'
    ];
    
    return $classes[$status] ?? 'status-pending';
}

// Helper function to get feedback icon
function getFeedbackIcon($feedback_type) {
    $icons = [
        'complaint' => ['icon' => 'bxs-message-alt-error', 'color' => '#ef4444'],
        'suggestion' => ['icon' => 'bxs-bulb', 'color' => '#f59e0b'],
        'compliment' => ['icon' => 'bxs-heart', 'color' => '#10b981'],
        'general' => ['icon' => 'bxs-message', 'color' => '#3b82f6']
    ];
    
    return $icons[$feedback_type] ?? ['icon' => 'bxs-message', 'color' => '#3b82f6'];
}
?>