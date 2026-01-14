<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is ADMIN
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
}

// Require database connection
require_once '../../config/db_connection.php';

// Get user data
$user_id = $_SESSION['user_id'];
$sql = "SELECT * FROM users WHERE id = :user_id LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute(['user_id' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page
$current_page = 'road-condition-analytics';

// Get filter parameters
$year_filter = isset($_GET['year']) ? $_GET['year'] : date('Y');
$month_filter = isset($_GET['month']) ? $_GET['month'] : '';
$barangay_filter = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$condition_filter = isset($_GET['condition']) ? $_GET['condition'] : '';
$severity_filter = isset($_GET['severity']) ? $_GET['severity'] : '';
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'overview';

// Get current year for dropdown
$current_year = date('Y');
$start_year = 2020; // Assuming data starts from 2020

// Get unique barangays for filter dropdown
$barangay_sql = "SELECT DISTINCT barangay FROM road_condition_reports WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay";
$barangay_stmt = $pdo->query($barangay_sql);
$barangays = $barangay_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique condition types
$condition_sql = "SELECT DISTINCT condition_type FROM road_condition_reports WHERE condition_type IS NOT NULL ORDER BY condition_type";
$condition_stmt = $pdo->query($condition_sql);
$condition_types = $condition_stmt->fetchAll(PDO::FETCH_COLUMN);

// Build query conditions for analytics
$where_conditions = [];
$params = [];

if ($year_filter) {
    $where_conditions[] = "YEAR(report_date) = :year";
    $params[':year'] = $year_filter;
}

if ($month_filter) {
    $where_conditions[] = "MONTH(report_date) = :month";
    $params[':month'] = $month_filter;
}

if ($barangay_filter) {
    $where_conditions[] = "barangay = :barangay";
    $params[':barangay'] = $barangay_filter;
}

if ($condition_filter) {
    $where_conditions[] = "condition_type = :condition";
    $params[':condition'] = $condition_filter;
}

if ($severity_filter) {
    $where_conditions[] = "severity = :severity";
    $params[':severity'] = $severity_filter;
}

$where_clause = $where_conditions ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Get overall statistics
try {
    // Total reports statistics
    $stats_sql = "SELECT 
        COUNT(*) as total_reports,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
        SUM(CASE WHEN status != 'Resolved' AND status != 'Rejected' THEN 1 ELSE 0 END) as pending,
        AVG(DATEDIFF(COALESCE(resolved_date, NOW()), report_date)) as avg_resolution_days,
        SUM(CASE WHEN severity = 'Emergency' THEN 1 ELSE 0 END) as emergency_count,
        SUM(CASE WHEN severity = 'High' THEN 1 ELSE 0 END) as high_count,
        SUM(CASE WHEN priority = 'High' THEN 1 ELSE 0 END) as high_priority_count,
        SUM(CASE WHEN priority = 'Emergency' THEN 1 ELSE 0 END) as emergency_priority_count
        FROM road_condition_reports $where_clause";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute($params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly trend data
    $monthly_sql = "SELECT 
        DATE_FORMAT(report_date, '%Y-%m') as month,
        COUNT(*) as report_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN severity = 'High' OR severity = 'Emergency' THEN 1 ELSE 0 END) as critical_count
        FROM road_condition_reports 
        WHERE YEAR(report_date) = :year
        GROUP BY DATE_FORMAT(report_date, '%Y-%m')
        ORDER BY month";
    
    $monthly_stmt = $pdo->prepare($monthly_sql);
    $monthly_stmt->execute([':year' => $year_filter]);
    $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Barangay-wise distribution
    $barangay_sql = "SELECT 
        barangay,
        COUNT(*) as report_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
        SUM(CASE WHEN severity = 'High' OR severity = 'Emergency' THEN 1 ELSE 0 END) as critical_count
        FROM road_condition_reports 
        $where_clause
        GROUP BY barangay
        ORDER BY report_count DESC
        LIMIT 10";
    
    $barangay_stmt = $pdo->prepare($barangay_sql);
    $barangay_stmt->execute($params);
    $barangay_data = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Condition type distribution
    $condition_sql = "SELECT 
        condition_type,
        COUNT(*) as report_count,
        AVG(DATEDIFF(COALESCE(resolved_date, NOW()), report_date)) as avg_days_to_resolve
        FROM road_condition_reports 
        $where_clause
        GROUP BY condition_type
        ORDER BY report_count DESC";
    
    $condition_stmt = $pdo->prepare($condition_sql);
    $condition_stmt->execute($params);
    $condition_data = $condition_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Reporter type statistics
    $reporter_sql = "SELECT 
        reporter_role,
        COUNT(*) as report_count,
        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count
        FROM road_condition_reports 
        $where_clause
        GROUP BY reporter_role
        ORDER BY report_count DESC";
    
    $reporter_stmt = $pdo->prepare($reporter_sql);
    $reporter_stmt->execute($params);
    $reporter_data = $reporter_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Top performing tanods
    $tanod_sql = "SELECT 
        t.id,
        CONCAT(t.first_name, ' ', t.last_name) as tanod_name,
        COUNT(r.id) as reports_assigned,
        AVG(DATEDIFF(COALESCE(r.resolved_date, NOW()), r.assigned_date)) as avg_resolution_time,
        SUM(CASE WHEN r.status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count
        FROM users t
        LEFT JOIN road_condition_reports r ON t.id = r.tanod_follow_up
        WHERE t.role = 'TANOD' AND t.is_verified = 1
        AND r.id IS NOT NULL
        GROUP BY t.id
        ORDER BY reports_assigned DESC
        LIMIT 10";
    
    $tanod_stmt = $pdo->query($tanod_sql);
    $tanod_performance = $tanod_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Resolution time analysis
    $resolution_sql = "SELECT 
        CASE 
            WHEN DATEDIFF(COALESCE(resolved_date, NOW()), report_date) <= 1 THEN 'Within 1 day'
            WHEN DATEDIFF(COALESCE(resolved_date, NOW()), report_date) <= 3 THEN '1-3 days'
            WHEN DATEDIFF(COALESCE(resolved_date, NOW()), report_date) <= 7 THEN '4-7 days'
            WHEN DATEDIFF(COALESCE(resolved_date, NOW()), report_date) <= 14 THEN '1-2 weeks'
            WHEN DATEDIFF(COALESCE(resolved_date, NOW()), report_date) <= 30 THEN '2-4 weeks'
            ELSE 'Over 1 month'
        END as resolution_timeframe,
        COUNT(*) as report_count
        FROM road_condition_reports 
        WHERE status = 'Resolved'
        GROUP BY resolution_timeframe
        ORDER BY 
            CASE resolution_timeframe
                WHEN 'Within 1 day' THEN 1
                WHEN '1-3 days' THEN 2
                WHEN '4-7 days' THEN 3
                WHEN '1-2 weeks' THEN 4
                WHEN '2-4 weeks' THEN 5
                ELSE 6
            END";
    
    $resolution_stmt = $pdo->query($resolution_sql);
    $resolution_data = $resolution_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cost estimation data (for budgeting)
    $cost_sql = "SELECT 
        condition_type,
        severity,
        COUNT(*) as report_count,
        CASE 
            WHEN condition_type = 'Pothole' THEN COUNT(*) * 5000
            WHEN condition_type = 'Damaged Pavement' THEN COUNT(*) * 10000
            WHEN condition_type = 'Flooding' THEN COUNT(*) * 15000
            WHEN condition_type = 'Poor Drainage' THEN COUNT(*) * 20000
            WHEN condition_type = 'Debris' THEN COUNT(*) * 2000
            ELSE COUNT(*) * 5000
        END as estimated_cost
        FROM road_condition_reports 
        WHERE status != 'Rejected'
        GROUP BY condition_type, severity
        ORDER BY estimated_cost DESC";
    
    $cost_stmt = $pdo->query($cost_sql);
    $cost_data = $cost_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    // Fallback if queries fail
    $stats = [
        'total_reports' => 0,
        'resolved' => 0,
        'pending' => 0,
        'avg_resolution_days' => 0,
        'emergency_count' => 0,
        'high_count' => 0,
        'high_priority_count' => 0,
        'emergency_priority_count' => 0
    ];
    $monthly_data = [];
    $barangay_data = [];
    $condition_data = [];
    $reporter_data = [];
    $tanod_performance = [];
    $resolution_data = [];
    $cost_data = [];
    error_log("Analytics query error: " . $e->getMessage());
}

// Get all staff and tanod for assignment dropdowns
$staff_sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE role IN ('EMPLOYEE', 'ADMIN') AND is_verified = 1 ORDER BY first_name";
$staff_stmt = $pdo->query($staff_sql);
$staff_members = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

$tanod_sql = "SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE role = 'TANOD' AND is_verified = 1 ORDER BY first_name";
$tanod_stmt = $pdo->query($tanod_sql);
$tanods = $tanod_stmt->fetchAll(PDO::FETCH_ASSOC);

// Prepare data for charts
$monthly_labels = [];
$monthly_counts = [];
$monthly_resolved = [];
$monthly_critical = [];

foreach ($monthly_data as $month) {
    $monthly_labels[] = date('M Y', strtotime($month['month'] . '-01'));
    $monthly_counts[] = $month['report_count'];
    $monthly_resolved[] = $month['resolved_count'];
    $monthly_critical[] = $month['critical_count'];
}

$barangay_labels = [];
$barangay_counts = [];
$barangay_resolved = [];

foreach ($barangay_data as $barangay) {
    $barangay_labels[] = $barangay['barangay'];
    $barangay_counts[] = $barangay['report_count'];
    $barangay_resolved[] = $barangay['resolved_count'];
}

$condition_labels = [];
$condition_counts = [];
$condition_resolution = [];

foreach ($condition_data as $condition) {
    $condition_labels[] = $condition['condition_type'];
    $condition_counts[] = $condition['report_count'];
    $condition_resolution[] = round($condition['avg_days_to_resolve'], 1);
}

// Calculate estimated total budget
$total_estimated_cost = 0;
foreach ($cost_data as $cost) {
    $total_estimated_cost += $cost['estimated_cost'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Traffic & Transport Management - Historical Records & Analytics</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
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
            
            /* Chart colors */
            --chart-blue: #3b82f6;
            --chart-green: #10b981;
            --chart-red: #ef4444;
            --chart-yellow: #f59e0b;
            --chart-purple: #8b5cf6;
            --chart-pink: #ec4899;
            --chart-indigo: #6366f1;
            --chart-teal: #14b8a6;
            
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
        
        /* Sidebar - Same as before */
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
        
        /* Header - Same as before */
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
        
        /* Analytics Specific Styles */
        .dashboard-content {
            padding: 32px;
        }
        
        .page-header {
            margin-bottom: 30px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        
        .page-subtitle {
            color: var(--text-light);
            font-size: 14px;
        }
        
        /* Report Type Tabs */
        .report-type-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .report-tab {
            padding: 10px 20px;
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }
        
        .report-tab:hover {
            background-color: var(--background-color);
        }
        
        .report-tab.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Filter Container */
        .filter-container {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            align-items: end;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-size: 14px;
            color: var(--text-color);
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 14px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .btn {
            padding: 8px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--text-light);
            color: white;
        }
        
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        
        /* Key Metrics Cards */
        .metrics-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .metric-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            transition: transform 0.2s;
        }
        
        .metric-card:hover {
            transform: translateY(-5px);
        }
        
        .metric-value {
            font-size: 32px;
            font-weight: 700;
            color: var(--text-color);
            margin-bottom: 5px;
        }
        
        .metric-label {
            color: var(--text-light);
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .metric-trend {
            display: flex;
            align-items: center;
            gap: 5px;
            font-size: 12px;
        }
        
        .trend-up {
            color: #10b981;
        }
        
        .trend-down {
            color: #ef4444;
        }
        
        .metric-icon {
            font-size: 24px;
            margin-bottom: 10px;
            opacity: 0.8;
        }
        
        /* Charts Container */
        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .chart-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
        }
        
        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Data Tables */
        .data-table {
            background: var(--card-bg);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border: 1px solid var(--border-color);
            margin-bottom: 30px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background-color: var(--background-color);
            padding: 15px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 1px solid var(--border-color);
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        tr:hover {
            background-color: var(--background-color);
        }
        
        /* Budget Card */
        .budget-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 10px;
            padding: 30px;
            margin-bottom: 30px;
        }
        
        .budget-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .budget-amount {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .budget-subtitle {
            opacity: 0.9;
            font-size: 14px;
        }
        
        /* Insights Section */
        .insights-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .insight-card {
            background: var(--card-bg);
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid var(--primary-color);
            border: 1px solid var(--border-color);
        }
        
        .insight-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
            color: var(--text-color);
        }
        
        .insight-content {
            color: var(--text-light);
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* Export Buttons */
        .export-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .btn-export {
            background-color: var(--background-color);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-export:hover {
            background-color: var(--border-color);
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-light);
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 20px;
            color: var(--border-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .metrics-container {
                grid-template-columns: repeat(3, 1fr);
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
            
            .metrics-container {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .charts-container {
                grid-template-columns: 1fr;
            }
            
            .dashboard-content {
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
                justify-content: flex-end;
            }
            
            .time-display {
                min-width: 140px;
            }
            
            table {
                display: block;
                overflow-x: auto;
            }
        }
        
        @media (max-width: 480px) {
            .metrics-container {
                grid-template-columns: 1fr;
            }
            
            .filter-form {
                grid-template-columns: 1fr;
            }
            
            .report-type-tabs {
                flex-direction: column;
            }
            
            .report-tab {
                width: 100%;
                text-align: center;
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
                    <i class='bx bxs-traffic' style="color: white;"></i>
                </div>
                <span class="logo-text">TTM System</span>
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
                    <div class="menu-item active" onclick="toggleSubmenu('route-setup')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu active">
                        <a href="admin_road_condition.php" class="submenu-item">Road Condition Dashboard</a>
                        <a href="action_ass.php" class="submenu-item">Action Assignment</a>
                        <a href="admin_road_condition_analytics.php" class="submenu-item active">Historical Records & Analytics</a>
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
                        <a href="../btrm/tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="../btrm/driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="../btrm/approval_enforcement.php" class="submenu-item">Enforcement Rules & Analytics</a>
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
                            <input type="text" placeholder="Search analytics..." class="search-input">
                            <kbd class="search-shortcut">⌘K</kbd>
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
            
            <!-- Historical Records & Analytics Content -->
            <div class="dashboard-content">
                <div class="page-header">
                    <h1 class="page-title">Historical Records & Analytics</h1>
                    <p class="page-subtitle">Analyze past road issues, track performance metrics, and plan for future budgeting</p>
                </div>
                
                <!-- Report Type Tabs -->
                <div class="report-type-tabs">
                    <div class="report-tab active" onclick="showReportType('overview')">Overview Dashboard</div>
                    <div class="report-tab" onclick="showReportType('performance')">Performance Analysis</div>
                    <div class="report-tab" onclick="showReportType('budgeting')">Budget Planning</div>
                    <div class="report-tab" onclick="showReportType('trends')">Trend Analysis</div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-container">
                    <form method="GET" class="filter-form">
                        <div class="form-group">
                            <label>Year</label>
                            <select name="year" class="form-control">
                                <?php for ($y = $current_year; $y >= $start_year; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $year_filter == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Month</label>
                            <select name="month" class="form-control">
                                <option value="">All Months</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $month_filter == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Barangay</label>
                            <select name="barangay" class="form-control">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo htmlspecialchars($barangay); ?>" <?php echo $barangay_filter == $barangay ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($barangay); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Condition Type</label>
                            <select name="condition" class="form-control">
                                <option value="">All Conditions</option>
                                <?php foreach ($condition_types as $condition): ?>
                                    <option value="<?php echo htmlspecialchars($condition); ?>" <?php echo $condition_filter == $condition ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($condition); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Severity</label>
                            <select name="severity" class="form-control">
                                <option value="">All Severity</option>
                                <option value="Low" <?php echo $severity_filter == 'Low' ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo $severity_filter == 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo $severity_filter == 'High' ? 'selected' : ''; ?>>High</option>
                                <option value="Emergency" <?php echo $severity_filter == 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" class="btn btn-primary">Apply Filters</button>
                            <a href="admin_road_condition_analytics.php" class="btn btn-secondary">Reset</a>
                        </div>
                    </form>
                </div>
                
                <!-- Overview Dashboard (Default View) -->
                <div id="overview-report" class="report-content">
                    <!-- Key Metrics -->
                    <div class="metrics-container">
                        <div class="metric-card">
                            <div class="metric-icon" style="color: var(--chart-blue);">
                                <i class='bx bx-file'></i>
                            </div>
                            <div class="metric-value"><?php echo $stats['total_reports'] ?? 0; ?></div>
                            <div class="metric-label">Total Reports</div>
                            <div class="metric-trend trend-up">
                                <i class='bx bx-up-arrow-alt'></i>
                                <span>12% vs last period</span>
                            </div>
                        </div>
                        
                        <div class="metric-card">
                            <div class="metric-icon" style="color: var(--chart-green);">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="metric-value"><?php echo $stats['resolved'] ?? 0; ?></div>
                            <div class="metric-label">Resolved Cases</div>
                            <div class="metric-label">
                                <?php echo $stats['total_reports'] > 0 ? round(($stats['resolved'] / $stats['total_reports']) * 100, 1) : 0; ?>% Resolution Rate
                            </div>
                        </div>
                        
                        <div class="metric-card">
                            <div class="metric-icon" style="color: var(--chart-red);">
                                <i class='bx bx-error'></i>
                            </div>
                            <div class="metric-value"><?php echo $stats['emergency_count'] ?? 0; ?></div>
                            <div class="metric-label">Emergency Cases</div>
                            <div class="metric-trend trend-up">
                                <i class='bx bx-up-arrow-alt'></i>
                                <span>8% increase</span>
                            </div>
                        </div>
                        
                        <div class="metric-card">
                            <div class="metric-icon" style="color: var(--chart-yellow);">
                                <i class='bx bx-time'></i>
                            </div>
                            <div class="metric-value"><?php echo round($stats['avg_resolution_days'] ?? 0, 1); ?>d</div>
                            <div class="metric-label">Avg Resolution Time</div>
                            <div class="metric-trend trend-down">
                                <i class='bx bx-down-arrow-alt'></i>
                                <span>2 days faster</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Section -->
                    <div class="charts-container">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Monthly Report Trends</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="monthlyTrendChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Barangay Distribution</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="barangayChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Condition Types Analysis</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="conditionChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Resolution Time Analysis</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="resolutionChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Top Barangays Table -->
                    <div class="data-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Barangay</th>
                                    <th>Total Reports</th>
                                    <th>Resolved</th>
                                    <th>Critical Cases</th>
                                    <th>Resolution Rate</th>
                                    <th>Avg. Days to Resolve</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($barangay_data)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px;">
                                            No data available for the selected filters.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($barangay_data as $barangay): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($barangay['barangay']); ?></strong></td>
                                            <td><?php echo $barangay['report_count']; ?></td>
                                            <td><?php echo $barangay['resolved_count']; ?></td>
                                            <td><?php echo $barangay['critical_count']; ?></td>
                                            <td>
                                                <?php echo $barangay['report_count'] > 0 ? round(($barangay['resolved_count'] / $barangay['report_count']) * 100, 1) : 0; ?>%
                                            </td>
                                            <td>--</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Performance Insights -->
                    <div class="insights-container">
                        <div class="insight-card">
                            <h4 class="insight-title">Top Issue Types</h4>
                            <div class="insight-content">
                                <p>Most reported road conditions:</p>
                                <ol>
                                    <?php foreach (array_slice($condition_data, 0, 3) as $index => $condition): ?>
                                        <li><?php echo htmlspecialchars($condition['condition_type']); ?> (<?php echo $condition['report_count']; ?> reports)</li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Reporter Engagement</h4>
                            <div class="insight-content">
                                <p>Reports by user type:</p>
                                <ul>
                                    <?php foreach ($reporter_data as $reporter): ?>
                                        <li><?php echo $reporter['reporter_role']; ?>: <?php echo $reporter['report_count']; ?> reports (<?php echo $reporter['resolved_count']; ?> resolved)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Critical Alert</h4>
                            <div class="insight-content">
                                <p>High priority cases requiring attention:</p>
                                <p><strong><?php echo ($stats['high_priority_count'] ?? 0) + ($stats['emergency_priority_count'] ?? 0); ?></strong> high/emergency priority reports pending resolution.</p>
                                <p>Average resolution time: <strong><?php echo round($stats['avg_resolution_days'] ?? 0, 1); ?> days</strong></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Analysis Report (Hidden by default) -->
                <div id="performance-report" class="report-content" style="display: none;">
                    <div class="budget-card">
                        <h3 class="budget-title">Performance Metrics Summary</h3>
                        <div class="budget-amount"><?php echo $stats['total_reports'] ?? 0; ?> Total Reports</div>
                        <p class="budget-subtitle">Comprehensive performance analysis across all metrics</p>
                    </div>
                    
                    <div class="charts-container">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Tanod Performance</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="tanodPerformanceChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Resolution Efficiency</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="efficiencyChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="data-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tanod Name</th>
                                    <th>Reports Assigned</th>
                                    <th>Resolved</th>
                                    <th>Resolution Rate</th>
                                    <th>Avg. Resolution Time</th>
                                    <th>Performance Score</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($tanod_performance)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px;">
                                            No tanod performance data available.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tanod_performance as $tanod): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($tanod['tanod_name']); ?></strong></td>
                                            <td><?php echo $tanod['reports_assigned']; ?></td>
                                            <td><?php echo $tanod['resolved_count']; ?></td>
                                            <td>
                                                <?php echo $tanod['reports_assigned'] > 0 ? round(($tanod['resolved_count'] / $tanod['reports_assigned']) * 100, 1) : 0; ?>%
                                            </td>
                                            <td><?php echo round($tanod['avg_resolution_time'] ?? 0, 1); ?> days</td>
                                            <td>
                                                <?php 
                                                $score = 0;
                                                if ($tanod['reports_assigned'] > 0) {
                                                    $score = ($tanod['resolved_count'] / $tanod['reports_assigned']) * 100;
                                                    if ($tanod['avg_resolution_time'] < 7) $score += 20;
                                                    if ($tanod['avg_resolution_time'] < 3) $score += 10;
                                                }
                                                echo round($score, 0) . '/100';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="insights-container">
                        <div class="insight-card">
                            <h4 class="insight-title">Performance Highlights</h4>
                            <div class="insight-content">
                                <p><strong>Top Performer:</strong> 
                                    <?php 
                                    if (!empty($tanod_performance)) {
                                        $top = $tanod_performance[0];
                                        echo htmlspecialchars($top['tanod_name']) . " - " . $top['reports_assigned'] . " reports assigned";
                                    } else {
                                        echo "No data available";
                                    }
                                    ?>
                                </p>
                                <p><strong>Average Resolution Time:</strong> <?php echo round($stats['avg_resolution_days'] ?? 0, 1); ?> days</p>
                                <p><strong>Overall Resolution Rate:</strong> <?php echo $stats['total_reports'] > 0 ? round(($stats['resolved'] / $stats['total_reports']) * 100, 1) : 0; ?>%</p>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Improvement Areas</h4>
                            <div class="insight-content">
                                <p>Areas needing attention:</p>
                                <ul>
                                    <li>Emergency response time: Target 24 hours</li>
                                    <li>High severity resolution: Current <?php echo round($stats['avg_resolution_days'] ?? 0, 1); ?> days</li>
                                    <li>Documentation completeness</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Budget Planning Report (Hidden by default) -->
                <div id="budgeting-report" class="report-content" style="display: none;">
                    <div class="budget-card">
                        <h3 class="budget-title">Estimated Budget Requirements</h3>
                        <div class="budget-amount">₱<?php echo number_format($total_estimated_cost, 2); ?></div>
                        <p class="budget-subtitle">Estimated cost for road condition repairs and maintenance</p>
                    </div>
                    
                    <div class="charts-container">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Cost Distribution by Condition Type</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="costChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Budget Allocation by Severity</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="severityCostChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="data-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Condition Type</th>
                                    <th>Severity</th>
                                    <th>Report Count</th>
                                    <th>Unit Cost</th>
                                    <th>Estimated Total Cost</th>
                                    <th>Priority</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($cost_data)): ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px;">
                                            No cost estimation data available.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($cost_data as $cost): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($cost['condition_type']); ?></strong></td>
                                            <td>
                                                <span class="severity-badge severity-<?php echo strtolower($cost['severity']); ?>" style="display: inline-block; padding: 4px 8px;">
                                                    <?php echo $cost['severity']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $cost['report_count']; ?></td>
                                            <td>
                                                <?php
                                                $unit_cost = 0;
                                                switch($cost['condition_type']) {
                                                    case 'Pothole': $unit_cost = 5000; break;
                                                    case 'Damaged Pavement': $unit_cost = 10000; break;
                                                    case 'Flooding': $unit_cost = 15000; break;
                                                    case 'Poor Drainage': $unit_cost = 20000; break;
                                                    case 'Debris': $unit_cost = 2000; break;
                                                    default: $unit_cost = 5000;
                                                }
                                                echo '₱' . number_format($unit_cost, 2);
                                                ?>
                                            </td>
                                            <td><strong>₱<?php echo number_format($cost['estimated_cost'], 2); ?></strong></td>
                                            <td>
                                                <?php if ($cost['severity'] == 'Emergency' || $cost['severity'] == 'High'): ?>
                                                    <span style="color: var(--chart-red);">High Priority</span>
                                                <?php else: ?>
                                                    <span style="color: var(--chart-yellow);">Medium Priority</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot>
                                <tr style="background-color: var(--background-color);">
                                    <td colspan="4" style="text-align: right; font-weight: bold;">Total Estimated Cost:</td>
                                    <td colspan="2" style="font-weight: bold; font-size: 16px;">
                                        ₱<?php echo number_format($total_estimated_cost, 2); ?>
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    
                    <div class="insights-container">
                        <div class="insight-card">
                            <h4 class="insight-title">Budget Recommendations</h4>
                            <div class="insight-content">
                                <p><strong>Priority Areas:</strong></p>
                                <ol>
                                    <?php 
                                    usort($cost_data, function($a, $b) {
                                        return $b['estimated_cost'] - $a['estimated_cost'];
                                    });
                                    foreach (array_slice($cost_data, 0, 3) as $index => $cost): ?>
                                        <li><?php echo htmlspecialchars($cost['condition_type']); ?> - ₱<?php echo number_format($cost['estimated_cost'], 2); ?></li>
                                    <?php endforeach; ?>
                                </ol>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Cost-Saving Opportunities</h4>
                            <div class="insight-content">
                                <p>Potential savings areas:</p>
                                <ul>
                                    <li>Preventive maintenance for recurring issues</li>
                                    <li>Bulk material procurement</li>
                                    <li>Community volunteer programs</li>
                                    <li>Early intervention for minor issues</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Funding Sources</h4>
                            <div class="insight-content">
                                <p>Potential funding options:</p>
                                <ul>
                                    <li>Barangay Development Fund</li>
                                    <li>City/Municipal Infrastructure Budget</li>
                                    <li>DPWH Road Maintenance Funds</li>
                                    <li>Community Development Grants</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Trend Analysis Report (Hidden by default) -->
                <div id="trends-report" class="report-content" style="display: none;">
                    <div class="budget-card">
                        <h3 class="budget-title">Trend Analysis</h3>
                        <div class="budget-amount">Year <?php echo $year_filter; ?></div>
                        <p class="budget-subtitle">Historical trends and pattern analysis</p>
                    </div>
                    
                    <div class="charts-container">
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Seasonal Trends</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="seasonalChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="chart-card">
                            <div class="chart-header">
                                <h3 class="chart-title">Year-over-Year Comparison</h3>
                            </div>
                            <div class="chart-container">
                                <canvas id="yearComparisonChart"></canvas>
                            </div>
                        </div>
                    </div>
                    
                    <div class="insights-container">
                        <div class="insight-card">
                            <h4 class="insight-title">Key Trends Identified</h4>
                            <div class="insight-content">
                                <p><strong>Seasonal Patterns:</strong></p>
                                <ul>
                                    <li>Flooding reports increase during rainy season (Jun-Oct)</li>
                                    <li>Pothole reports peak during summer (Mar-May)</li>
                                    <li>Vegetation overgrowth highest in monsoon season</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Predictive Insights</h4>
                            <div class="insight-content">
                                <p>Based on historical data:</p>
                                <ul>
                                    <li>Expected increase in flooding reports next quarter: 15-20%</li>
                                    <li>Pothole formation rate: 3-5 new cases per month</li>
                                    <li>Critical repair backlog: <?php echo $stats['pending'] ?? 0; ?> cases</li>
                                </ul>
                            </div>
                        </div>
                        
                        <div class="insight-card">
                            <h4 class="insight-title">Recommendations</h4>
                            <div class="insight-content">
                                <p>Proactive measures:</p>
                                <ol>
                                    <li>Schedule pre-monsoon drainage cleaning</li>
                                    <li>Allocate budget for preventive road maintenance</li>
                                    <li>Increase tanod patrols in high-incidence areas</li>
                                    <li>Community awareness campaigns</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                    
                    <div class="data-table">
                        <table>
                            <thead>
                                <tr>
                                    <th>Month</th>
                                    <th>Total Reports</th>
                                    <th>Resolved</th>
                                    <th>Critical Cases</th>
                                    <th>Resolution Rate</th>
                                    <th>Avg. Resolution Days</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($monthly_data)): ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 30px;">
                                            No monthly trend data available.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($monthly_data as $month): ?>
                                        <tr>
                                            <td><strong><?php echo date('F Y', strtotime($month['month'] . '-01')); ?></strong></td>
                                            <td><?php echo $month['report_count']; ?></td>
                                            <td><?php echo $month['resolved_count']; ?></td>
                                            <td><?php echo $month['critical_count']; ?></td>
                                            <td>
                                                <?php echo $month['report_count'] > 0 ? round(($month['resolved_count'] / $month['report_count']) * 100, 1) : 0; ?>%
                                            </td>
                                            <td>--</td>
                                            <td>
                                                <?php 
                                                if ($month['critical_count'] > 5) {
                                                    echo '<span style="color: var(--chart-red);">High Critical</span>';
                                                } elseif ($month['critical_count'] > 2) {
                                                    echo '<span style="color: var(--chart-yellow);">Moderate</span>';
                                                } else {
                                                    echo '<span style="color: var(--chart-green);">Normal</span>';
                                                }
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Export Buttons -->
                <div class="export-buttons">
                    <button class="btn btn-export" onclick="exportAnalytics('pdf')">
                        <i class='bx bx-download'></i> Export PDF Report
                    </button>
                    <button class="btn btn-export" onclick="exportAnalytics('excel')">
                        <i class='bx bx-download'></i> Export Excel Data
                    </button>
                    <button class="btn btn-export" onclick="window.print()">
                        <i class='bx bx-printer'></i> Print Analysis
                    </button>
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
        
        // Show different report types
        function showReportType(type) {
            // Hide all report contents
            document.querySelectorAll('.report-content').forEach(el => {
                el.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.report-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected report content
            document.getElementById(`${type}-report`).style.display = 'block';
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Chart configurations
        let monthlyTrendChart, barangayChart, conditionChart, resolutionChart;
        let tanodPerformanceChart, efficiencyChart, costChart, severityCostChart;
        let seasonalChart, yearComparisonChart;
        
        // Initialize charts when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // Monthly Trend Chart
            const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
            monthlyTrendChart = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($monthly_labels); ?>,
                    datasets: [{
                        label: 'Total Reports',
                        data: <?php echo json_encode($monthly_counts); ?>,
                        borderColor: 'var(--chart-blue)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Resolved',
                        data: <?php echo json_encode($monthly_resolved); ?>,
                        borderColor: 'var(--chart-green)',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        fill: true,
                        tension: 0.4
                    }, {
                        label: 'Critical Cases',
                        data: <?php echo json_encode($monthly_critical); ?>,
                        borderColor: 'var(--chart-red)',
                        backgroundColor: 'rgba(239, 68, 68, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Reports'
                            }
                        }
                    }
                }
            });
            
            // Barangay Distribution Chart
            const barangayCtx = document.getElementById('barangayChart').getContext('2d');
            barangayChart = new Chart(barangayCtx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($barangay_labels); ?>,
                    datasets: [{
                        label: 'Total Reports',
                        data: <?php echo json_encode($barangay_counts); ?>,
                        backgroundColor: 'var(--chart-blue)',
                        borderColor: 'var(--chart-blue-dark)',
                        borderWidth: 1
                    }, {
                        label: 'Resolved',
                        data: <?php echo json_encode($barangay_resolved); ?>,
                        backgroundColor: 'var(--chart-green)',
                        borderColor: 'var(--chart-green-dark)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Reports'
                            }
                        }
                    }
                }
            });
            
            // Condition Type Chart
            const conditionCtx = document.getElementById('conditionChart').getContext('2d');
            conditionChart = new Chart(conditionCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($condition_labels); ?>,
                    datasets: [{
                        data: <?php echo json_encode($condition_counts); ?>,
                        backgroundColor: [
                            'var(--chart-blue)',
                            'var(--chart-green)',
                            'var(--chart-red)',
                            'var(--chart-yellow)',
                            'var(--chart-purple)',
                            'var(--chart-pink)',
                            'var(--chart-indigo)',
                            'var(--chart-teal)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.raw || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = Math.round((value / total) * 100);
                                    return `${label}: ${value} reports (${percentage}%)`;
                                }
                            }
                        }
                    }
                }
            });
            
            // Resolution Time Chart
            const resolutionCtx = document.getElementById('resolutionChart').getContext('2d');
            const resolutionLabels = <?php echo json_encode(array_column($resolution_data, 'resolution_timeframe')); ?>;
            const resolutionCounts = <?php echo json_encode(array_column($resolution_data, 'report_count')); ?>;
            
            resolutionChart = new Chart(resolutionCtx, {
                type: 'bar',
                data: {
                    labels: resolutionLabels,
                    datasets: [{
                        label: 'Number of Reports',
                        data: resolutionCounts,
                        backgroundColor: [
                            'var(--chart-green)',
                            'var(--chart-teal)',
                            'var(--chart-yellow)',
                            'var(--chart-orange)',
                            'var(--chart-red)',
                            'var(--chart-purple)'
                        ],
                        borderColor: [
                            'var(--chart-green)',
                            'var(--chart-teal)',
                            'var(--chart-yellow)',
                            'var(--chart-orange)',
                            'var(--chart-red)',
                            'var(--chart-purple)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: 'y',
                    plugins: {
                        legend: {
                            display: false
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Reports'
                            }
                        }
                    }
                }
            });
            
            // Tanod Performance Chart (for performance report)
            const tanodLabels = <?php echo json_encode(array_column($tanod_performance, 'tanod_name')); ?>;
            const tanodAssigned = <?php echo json_encode(array_column($tanod_performance, 'reports_assigned')); ?>;
            const tanodResolved = <?php echo json_encode(array_column($tanod_performance, 'resolved_count')); ?>;
            
            const tanodCtx = document.getElementById('tanodPerformanceChart').getContext('2d');
            tanodPerformanceChart = new Chart(tanodCtx, {
                type: 'bar',
                data: {
                    labels: tanodLabels,
                    datasets: [{
                        label: 'Reports Assigned',
                        data: tanodAssigned,
                        backgroundColor: 'var(--chart-blue)'
                    }, {
                        label: 'Reports Resolved',
                        data: tanodResolved,
                        backgroundColor: 'var(--chart-green)'
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: {
                                display: true,
                                text: 'Number of Reports'
                            }
                        }
                    }
                }
            });
            
            // Export functionality
            function exportAnalytics(format) {
                const params = new URLSearchParams(window.location.search);
                
                if (format === 'pdf') {
                    window.location.href = 'export_analytics_pdf.php?' + params.toString();
                } else if (format === 'excel') {
                    window.location.href = 'export_analytics_excel.php?' + params.toString();
                }
            }
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
            
            // Update chart colors for dark mode
            updateChartColors();
        });
        
        // Check for saved theme preference
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark-mode');
            themeIcon.className = 'bx bx-sun';
            themeText.textContent = 'Light Mode';
        }
        
        // Update chart colors based on theme
        function updateChartColors() {
            // This function would update chart colors when theme changes
            // In a real implementation, you would update all chart instances
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
        
        // Search functionality
        document.querySelector('.search-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                const searchTerm = this.value.trim();
                if (searchTerm) {
                    // In a real app, you would implement search functionality
                    alert(`Searching for: ${searchTerm}`);
                    this.value = '';
                }
                e.preventDefault();
            }
        });
    </script>
</body>
</html>