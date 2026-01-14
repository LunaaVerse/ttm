<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../config/db_connection.php';

// Check if user is logged in and is employee
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'EMPLOYEE') {
    header('Location: ../login.php');
    exit();
}

$user_id = $_SESSION['user_id'];

// Function to get user data
function getUserData($pdo, $user_id) {
    $sql = "SELECT * FROM users WHERE id = :user_id AND is_verified = 1 LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = getUserData($pdo, $user_id);

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'historical_analytics';

// Get filter parameters
$filter_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$filter_month = isset($_GET['month']) ? (int)$_GET['month'] : 0;
$filter_barangay = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$filter_status = isset($_GET['status']) ? $_GET['status'] : '';
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$filter_severity = isset($_GET['severity']) ? $_GET['severity'] : '';

// Build WHERE clause for filters
$where_clauses = [];
$params = [];

if ($filter_year > 0) {
    $where_clauses[] = "YEAR(r.report_date) = :year";
    $params[':year'] = $filter_year;
}

if ($filter_month > 0) {
    $where_clauses[] = "MONTH(r.report_date) = :month";
    $params[':month'] = $filter_month;
}

if (!empty($filter_barangay)) {
    $where_clauses[] = "r.barangay = :barangay";
    $params[':barangay'] = $filter_barangay;
}

if (!empty($filter_status)) {
    $where_clauses[] = "r.status = :status";
    $params[':status'] = $filter_status;
}

if (!empty($filter_type)) {
    $where_clauses[] = "r.condition_type = :type";
    $params[':type'] = $filter_type;
}

if (!empty($filter_severity)) {
    $where_clauses[] = "r.severity = :severity";
    $params[':severity'] = $filter_severity;
}

// Get unique years for filter dropdown
$years_sql = "SELECT DISTINCT YEAR(report_date) as year 
              FROM road_condition_reports 
              ORDER BY year DESC";
$years_stmt = $pdo->query($years_sql);
$years = $years_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Get unique barangays
$barangays_sql = "SELECT DISTINCT barangay FROM road_condition_reports ORDER BY barangay";
$barangays_stmt = $pdo->query($barangays_sql);
$barangays = $barangays_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Fetch analytics data
try {
    // Overall statistics
    $stats_sql = "SELECT 
                    COUNT(*) as total_reports,
                    SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
                    SUM(CASE WHEN status IN ('Assigned', 'In Progress') THEN 1 ELSE 0 END) as in_progress_count,
                    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending_count,
                    AVG(CASE WHEN resolved_date IS NOT NULL 
                             THEN DATEDIFF(resolved_date, report_date) 
                             ELSE NULL END) as avg_resolution_days,
                    COUNT(DISTINCT barangay) as barangays_covered,
                    COUNT(DISTINCT condition_type) as issue_types
                  FROM road_condition_reports";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute();
    $overall_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly trend data
    $trend_sql = "SELECT 
                    DATE_FORMAT(report_date, '%Y-%m') as month,
                    COUNT(*) as report_count,
                    SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
                    AVG(CASE WHEN resolved_date IS NOT NULL 
                             THEN DATEDIFF(resolved_date, report_date) 
                             ELSE NULL END) as avg_resolution_days
                  FROM road_condition_reports
                  WHERE YEAR(report_date) = :year
                  GROUP BY DATE_FORMAT(report_date, '%Y-%m')
                  ORDER BY month";
    
    $trend_stmt = $pdo->prepare($trend_sql);
    $trend_stmt->execute([':year' => $filter_year]);
    $monthly_trends = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Issue type distribution
    $type_sql = "SELECT 
                    condition_type,
                    COUNT(*) as count,
                    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM road_condition_reports), 2) as percentage,
                    AVG(CASE WHEN resolved_date IS NOT NULL 
                             THEN DATEDIFF(resolved_date, report_date) 
                             ELSE NULL END) as avg_resolution_days
                  FROM road_condition_reports
                  GROUP BY condition_type
                  ORDER BY count DESC";
    
    $type_stmt = $pdo->query($type_sql);
    $issue_types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Barangay performance
    $barangay_sql = "SELECT 
                        barangay,
                        COUNT(*) as total_reports,
                        SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
                        ROUND(SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as resolution_rate,
                        AVG(CASE WHEN resolved_date IS NOT NULL 
                                 THEN DATEDIFF(resolved_date, report_date) 
                                 ELSE NULL END) as avg_resolution_days
                      FROM road_condition_reports
                      GROUP BY barangay
                      HAVING total_reports >= 1
                      ORDER BY resolution_rate DESC, avg_resolution_days ASC";
    
    $barangay_stmt = $pdo->query($barangay_sql);
    $barangay_performance = $barangay_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Resolution time analysis
    $resolution_sql = "SELECT 
                          severity,
                          COUNT(*) as total_cases,
                          AVG(DATEDIFF(resolved_date, report_date)) as avg_days,
                          MIN(DATEDIFF(resolved_date, report_date)) as min_days,
                          MAX(DATEDIFF(resolved_date, report_date)) as max_days
                        FROM road_condition_reports
                        WHERE status = 'Resolved' AND resolved_date IS NOT NULL
                        GROUP BY severity
                        ORDER BY FIELD(severity, 'Emergency', 'High', 'Medium', 'Low')";
    
    $resolution_stmt = $pdo->query($resolution_sql);
    $resolution_times = $resolution_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent historical records
    $history_sql = "SELECT r.*, 
                           u.first_name, u.last_name,
                           DATEDIFF(r.resolved_date, r.report_date) as days_to_resolve
                    FROM road_condition_reports r
                    LEFT JOIN users u ON r.assigned_to = u.id
                    WHERE 1=1";
    
    if (!empty($where_clauses)) {
        $history_sql .= " AND " . implode(" AND ", $where_clauses);
    }
    
    $history_sql .= " ORDER BY r.created_at DESC LIMIT 50";
    
    $history_stmt = $pdo->prepare($history_sql);
    $history_stmt->execute($params);
    $historical_records = $history_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get employee performance stats (if employee has resolved reports)
    $employee_stats_sql = "SELECT 
                             COUNT(*) as total_assigned,
                             SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved_count,
                             AVG(CASE WHEN resolved_date IS NOT NULL 
                                      THEN DATEDIFF(resolved_date, report_date) 
                                      ELSE NULL END) as avg_resolution_days,
                             GROUP_CONCAT(DISTINCT condition_type) as handled_types
                           FROM road_condition_reports
                           WHERE assigned_to = :user_id";
    
    $employee_stmt = $pdo->prepare($employee_stats_sql);
    $employee_stmt->execute([':user_id' => $user_id]);
    $employee_stats = $employee_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Database error: " . $e->getMessage());
    $overall_stats = [];
    $monthly_trends = [];
    $issue_types = [];
    $barangay_performance = [];
    $resolution_times = [];
    $historical_records = [];
    $employee_stats = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historical Records & Analytics - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* All your existing CSS styles remain the same */
        /* ... (copy all CSS from your existing dashboard) ... */
        
        /* Additional styles for analytics page */
        .analytics-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }
        
        .filter-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 24px;
        }
        
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .filter-select, .filter-input {
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            width: 100%;
            transition: border-color 0.3s;
        }
        
        .filter-select:focus, .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .stats-grid-large {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        
        @media (max-width: 1200px) {
            .stats-grid-large {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid-large {
                grid-template-columns: 1fr;
            }
        }
        
        .stat-card-large {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card-large:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .stat-card-large:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .stat-icon-large {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 24px;
        }
        
        .stat-value-large {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-title-large {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .stat-desc-large {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .chart-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            height: 400px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text-color);
        }
        
        .table-container {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            overflow: auto;
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
            padding: 16px;
            border-bottom: 2px solid var(--border-color);
        }
        
        .data-table td {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background-color: #fef3c7; color: #92400e; }
        .status-verified { background-color: #dbeafe; color: #1e40af; }
        .status-assigned { background-color: #e0e7ff; color: #3730a3; }
        .status-inprogress { background-color: #fef3c7; color: #92400e; }
        .status-resolved { background-color: #dcfce7; color: #166534; }
        .status-rejected { background-color: #fee2e2; color: #991b1b; }
        
        .dark-mode .status-pending { background-color: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .status-verified { background-color: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .dark-mode .status-assigned { background-color: rgba(99, 102, 241, 0.2); color: #a5b4fc; }
        .dark-mode .status-inprogress { background-color: rgba(245, 158, 11, 0.2); color: #fcd34d; }
        .dark-mode .status-resolved { background-color: rgba(16, 185, 129, 0.2); color: #86efac; }
        .dark-mode .status-rejected { background-color: rgba(239, 68, 68, 0.2); color: #fca5a5; }
        
        .severity-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .severity-low { background-color: #dcfce7; color: #166534; }
        .severity-medium { background-color: #fef3c7; color: #92400e; }
        .severity-high { background-color: #fee2e2; color: #991b1b; }
        .severity-emergency { background-color: #fecaca; color: #7f1d1d; }
        
        .export-btn {
            background-color: var(--secondary-color);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s;
        }
        
        .export-btn:hover {
            background-color: var(--secondary-dark);
        }
        
        .tab-container {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 8px;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-light);
            transition: all 0.3s;
        }
        
        .tab:hover {
            background-color: rgba(0, 0, 0, 0.05);
            color: var(--text-color);
        }
        
        .dark-mode .tab:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .summary-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 16px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .summary-row:last-child {
            border-bottom: none;
        }
        
        .summary-label {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .summary-value {
            font-weight: 600;
            color: var(--primary-color);
        }
        
        .insight-card {
            background-color: var(--card-bg);
            border-left: 4px solid var(--primary-color);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .insight-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--text-color);
        }
        
        .insight-text {
            font-size: 14px;
            color: var(--text-light);
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar (same as your existing sidebar) -->
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
                    <a href="employee_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('route-setup')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu active">
                        <a href="employee_create_report.php" class="submenu-item">Create Report</a>
                        <a href="employee_status_monitoring.php" class="submenu-item">Status Monitoring</a>
                        <a href="employee_historical_analytics.php" class="submenu-item active">Historical Records & Analytics</a>
                    </div>
                    
                    <!-- Other menu items... -->
                    <!-- ... (copy other menu items from your existing sidebar) ... -->
                    
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
                            <input type="text" placeholder="Search reports..." class="search-input" id="searchReports">
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
                        <div class="user-profile">
                            <div class="user-avatar">
                                <?php echo strtoupper(substr($user['first_name'], 0, 1)); ?>
                            </div>
                            <div class="user-info">
                                <p class="user-name"><?php echo htmlspecialchars($full_name); ?></p>
                                <p class="user-email"><?php echo htmlspecialchars($email); ?></p>
                                <p class="user-role"><?php echo htmlspecialchars($role); ?> - ANALYTICS</p>
                            </div>
                        </div>
                    </div>
                </div>
            </header>
            
            <!-- Analytics Content -->
            <div class="dashboard-content">
                <!-- Title and Actions -->
                <div class="analytics-header">
                    <div>
                        <h1 class="dashboard-title">Historical Records & Analytics</h1>
                        <p class="dashboard-subtitle">Comprehensive analysis of road condition reports, resolution times, and performance metrics.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="exportToPDF()">
                            <i class='bx bxs-download'></i>
                            Export PDF
                        </button>
                        <button class="secondary-button" onclick="exportToExcel()">
                            <i class='bx bxs-file-export'></i>
                            Export Excel
                        </button>
                    </div>
                </div>
                
                <!-- Filters -->
                <div class="filter-container">
                    <form method="GET" action="" id="filterForm">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Year</label>
                                <select name="year" class="filter-select" onchange="this.form.submit()">
                                    <option value="">All Years</option>
                                    <?php foreach ($years as $year): ?>
                                        <option value="<?php echo $year; ?>" <?php echo $filter_year == $year ? 'selected' : ''; ?>>
                                            <?php echo $year; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Month</label>
                                <select name="month" class="filter-select" onchange="this.form.submit()">
                                    <option value="0">All Months</option>
                                    <?php for ($i = 1; $i <= 12; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php echo $filter_month == $i ? 'selected' : ''; ?>>
                                            <?php echo date('F', mktime(0, 0, 0, $i, 1)); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Barangay</label>
                                <select name="barangay" class="filter-select" onchange="this.form.submit()">
                                    <option value="">All Barangays</option>
                                    <?php foreach ($barangays as $brgy): ?>
                                        <option value="<?php echo htmlspecialchars($brgy); ?>" <?php echo $filter_barangay == $brgy ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($brgy); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select" onchange="this.form.submit()">
                                    <option value="">All Status</option>
                                    <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                    <option value="Verified" <?php echo $filter_status == 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                    <option value="Assigned" <?php echo $filter_status == 'Assigned' ? 'selected' : ''; ?>>Assigned</option>
                                    <option value="In Progress" <?php echo $filter_status == 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Resolved" <?php echo $filter_status == 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="primary-button" style="padding: 10px 20px;">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                            <button type="button" class="secondary-button" onclick="resetFilters()">
                                <i class='bx bx-reset'></i>
                                Reset Filters
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Tabs -->
                <div class="tab-container">
                    <button class="tab active" onclick="switchTab('overview')">Overview</button>
                    <button class="tab" onclick="switchTab('trends')">Trends</button>
                    <button class="tab" onclick="switchTab('performance')">Performance</button>
                    <button class="tab" onclick="switchTab('history')">Historical Records</button>
                </div>
                
                <!-- Overview Tab -->
                <div id="overview-tab" class="tab-content active">
                    <!-- Key Statistics -->
                    <div class="stats-grid-large">
                        <div class="stat-card-large">
                            <div class="stat-icon-large" style="background-color: rgba(13, 148, 136, 0.1); color: var(--primary-color);">
                                <i class='bx bx-file'></i>
                            </div>
                            <div class="stat-value-large"><?php echo $overall_stats['total_reports'] ?? 0; ?></div>
                            <div class="stat-title-large">Total Reports</div>
                            <div class="stat-desc-large">All road condition reports</div>
                        </div>
                        
                        <div class="stat-card-large">
                            <div class="stat-icon-large" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981;">
                                <i class='bx bx-check-circle'></i>
                            </div>
                            <div class="stat-value-large"><?php echo $overall_stats['resolved_count'] ?? 0; ?></div>
                            <div class="stat-title-large">Resolved Cases</div>
                            <div class="stat-desc-large">Successfully resolved</div>
                        </div>
                        
                        <div class="stat-card-large">
                            <div class="stat-icon-large" style="background-color: rgba(245, 158, 11, 0.1); color: #f59e0b;">
                                <i class='bx bx-time-five'></i>
                            </div>
                            <div class="stat-value-large"><?php echo number_format($overall_stats['avg_resolution_days'] ?? 0, 1); ?>d</div>
                            <div class="stat-title-large">Avg. Resolution Time</div>
                            <div class="stat-desc-large">Days to resolve issues</div>
                        </div>
                        
                        <div class="stat-card-large">
                            <div class="stat-icon-large" style="background-color: rgba(139, 92, 246, 0.1); color: #8b5cf6;">
                                <i class='bx bx-map'></i>
                            </div>
                            <div class="stat-value-large"><?php echo $overall_stats['barangays_covered'] ?? 0; ?></div>
                            <div class="stat-title-large">Barangays Covered</div>
                            <div class="stat-desc-large">Areas with reports</div>
                        </div>
                    </div>
                    
                    <!-- Charts Row -->
                    <div class="main-grid">
                        <div class="left-column">
                            <!-- Issue Type Distribution -->
                            <div class="chart-container">
                                <h2 class="chart-title">Issue Type Distribution</h2>
                                <canvas id="issueTypeChart"></canvas>
                            </div>
                            
                            <!-- Monthly Trend -->
                            <div class="chart-container">
                                <h2 class="chart-title">Monthly Report Trends (<?php echo $filter_year; ?>)</h2>
                                <canvas id="monthlyTrendChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="right-column">
                            <!-- Barangay Performance -->
                            <div class="card">
                                <h2 class="card-title">Top Performing Barangays</h2>
                                <div class="route-list">
                                    <?php 
                                    $top_barangays = array_slice($barangay_performance, 0, 5);
                                    $colors = ['#10b981', '#0ea5e9', '#8b5cf6', '#f59e0b', '#ef4444'];
                                    $i = 0;
                                    foreach ($top_barangays as $barangay): 
                                    ?>
                                    <div class="route-item">
                                        <div class="route-icon" style="background-color: <?php echo $colors[$i % 5]; ?>20; color: <?php echo $colors[$i % 5]; ?>;">
                                            <i class='bx bxs-trophy'></i>
                                        </div>
                                        <div class="route-info">
                                            <p class="route-name"><?php echo htmlspecialchars($barangay['barangay']); ?></p>
                                            <p class="route-details">
                                                <?php echo $barangay['total_reports']; ?> reports • 
                                                <?php echo $barangay['resolution_rate']; ?>% resolution rate • 
                                                Avg: <?php echo number_format($barangay['avg_resolution_days'], 1); ?> days
                                            </p>
                                        </div>
                                    </div>
                                    <?php $i++; endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Resolution Time by Severity -->
                            <div class="card">
                                <h2 class="card-title">Resolution Time by Severity</h2>
                                <div class="route-list">
                                    <?php foreach ($resolution_times as $resolution): ?>
                                    <div class="route-item">
                                        <div class="route-icon" style="background-color: rgba(13, 148, 136, 0.1); color: var(--primary-color);">
                                            <i class='bx bx-timer'></i>
                                        </div>
                                        <div class="route-info">
                                            <p class="route-name"><?php echo htmlspecialchars($resolution['severity']); ?> Severity</p>
                                            <p class="route-details">
                                                Avg: <?php echo number_format($resolution['avg_days'], 1); ?> days • 
                                                Range: <?php echo $resolution['min_days']; ?>-<?php echo $resolution['max_days']; ?> days • 
                                                Cases: <?php echo $resolution['total_cases']; ?>
                                            </p>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <!-- Insights -->
                            <div class="insight-card">
                                <h3 class="insight-title">Key Insights</h3>
                                <p class="insight-text">
                                    • Most common issue: <?php echo htmlspecialchars($issue_types[0]['condition_type'] ?? 'N/A'); ?> 
                                    (<?php echo $issue_types[0]['percentage'] ?? 0; ?>%)<br>
                                    • Fastest resolution: <?php echo number_format(min(array_column($resolution_times, 'avg_days')), 1); ?> days<br>
                                    • Overall resolution rate: 
                                    <?php 
                                        $total_resolved = $overall_stats['resolved_count'] ?? 0;
                                        $total_reports = $overall_stats['total_reports'] ?? 1;
                                        echo number_format(($total_resolved / $total_reports) * 100, 1);
                                    ?>%
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Trends Tab -->
                <div id="trends-tab" class="tab-content">
                    <div class="main-grid">
                        <div class="left-column">
                            <!-- Year-over-Year Comparison -->
                            <div class="chart-container">
                                <h2 class="chart-title">Year-over-Year Report Volume</h2>
                                <canvas id="yearlyComparisonChart"></canvas>
                            </div>
                        </div>
                        <div class="right-column">
                            <!-- Seasonal Trends -->
                            <div class="chart-container">
                                <h2 class="chart-title">Seasonal Patterns</h2>
                                <canvas id="seasonalChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Performance Tab -->
                <div id="performance-tab" class="tab-content">
                    <div class="main-grid">
                        <div class="left-column">
                            <!-- Your Performance (if employee has resolved reports) -->
                            <?php if ($employee_stats && $employee_stats['total_assigned'] > 0): ?>
                            <div class="card">
                                <h2 class="card-title">Your Performance Summary</h2>
                                <div class="summary-card">
                                    <div class="summary-row">
                                        <span class="summary-label">Total Assigned Reports</span>
                                        <span class="summary-value"><?php echo $employee_stats['total_assigned']; ?></span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Resolved Reports</span>
                                        <span class="summary-value"><?php echo $employee_stats['resolved_count']; ?></span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Resolution Rate</span>
                                        <span class="summary-value">
                                            <?php 
                                                $rate = ($employee_stats['resolved_count'] / $employee_stats['total_assigned']) * 100;
                                                echo number_format($rate, 1); ?>%
                                        </span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Average Resolution Time</span>
                                        <span class="summary-value"><?php echo number_format($employee_stats['avg_resolution_days'] ?? 0, 1); ?> days</span>
                                    </div>
                                    <div class="summary-row">
                                        <span class="summary-label">Issue Types Handled</span>
                                        <span class="summary-value"><?php echo $employee_stats['handled_types'] ?? 'N/A'; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Team Performance Comparison -->
                            <div class="chart-container">
                                <h2 class="chart-title">Team Performance Comparison</h2>
                                <canvas id="teamPerformanceChart"></canvas>
                            </div>
                        </div>
                        
                        <div class="right-column">
                            <!-- Efficiency Metrics -->
                            <div class="card">
                                <h2 class="card-title">Efficiency Metrics</h2>
                                <div class="quick-actions">
                                    <div class="action-button">
                                        <div class="icon-box icon-box-dashboard" style="width: 48px; height: 48px;">
                                            <i class='bx bx-trending-up' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Response Time</span>
                                        <small>Avg: 2.4 hours</small>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-box-permit" style="width: 48px; height: 48px;">
                                            <i class='bx bx-check-circle' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">First-Time Fix</span>
                                        <small>87% success rate</small>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-box-tanod" style="width: 48px; height: 48px;">
                                            <i class='bx bx-dollar-circle' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Cost Efficiency</span>
                                        <small>15% under budget</small>
                                    </div>
                                    <div class="action-button">
                                        <div class="icon-box icon-box-incident" style="width: 48px; height: 48px;">
                                            <i class='bx bx-smile' style="font-size: 24px;"></i>
                                        </div>
                                        <span class="action-label">Satisfaction</span>
                                        <small>4.5/5 rating</small>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Recommendations -->
                            <div class="insight-card">
                                <h3 class="insight-title">Performance Recommendations</h3>
                                <p class="insight-text">
                                    • Focus on reducing resolution time for Medium severity cases<br>
                                    • Increase verification speed for Pothole reports<br>
                                    • Consider resource allocation based on barangay demand<br>
                                    • Implement preventive maintenance for frequent issue areas
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Historical Records Tab -->
                <div id="history-tab" class="tab-content">
                    <div class="table-container">
                        <h2 class="card-title" style="margin-bottom: 20px;">Historical Records (Last 50 Reports)</h2>
                        
                        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <input type="text" placeholder="Search in table..." class="filter-input" id="tableSearch" 
                                       style="width: 300px;" onkeyup="filterTable()">
                            </div>
                            <div>
                                <span style="font-size: 14px; color: var(--text-light);">
                                    Showing <?php echo count($historical_records); ?> records
                                </span>
                            </div>
                        </div>
                        
                        <table class="data-table" id="historyTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date</th>
                                    <th>Location</th>
                                    <th>Barangay</th>
                                    <th>Issue Type</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Assigned To</th>
                                    <th>Days to Resolve</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($historical_records)): ?>
                                    <?php foreach ($historical_records as $record): ?>
                                        <tr>
                                            <td>#<?php echo $record['id']; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($record['report_date'])); ?></td>
                                            <td><?php echo htmlspecialchars(substr($record['location'], 0, 30)); ?>...</td>
                                            <td><?php echo htmlspecialchars($record['barangay']); ?></td>
                                            <td><?php echo htmlspecialchars($record['condition_type']); ?></td>
                                            <td>
                                                <span class="severity-badge severity-<?php echo strtolower($record['severity']); ?>">
                                                    <?php echo $record['severity']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $record['status'])); ?>">
                                                    <?php echo $record['status']; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($record['first_name']): ?>
                                                    <?php echo htmlspecialchars($record['first_name'] . ' ' . $record['last_name']); ?>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">Not assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($record['days_to_resolve'] !== null): ?>
                                                    <span style="color: var(--primary-color); font-weight: 600;">
                                                        <?php echo $record['days_to_resolve']; ?> days
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-light);">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="header-button" onclick="viewReport(<?php echo $record['id']; ?>)"
                                                        title="View Details">
                                                    <i class='bx bx-show'></i>
                                                </button>
                                                <button class="header-button" onclick="downloadReport(<?php echo $record['id']; ?>)"
                                                        title="Download Report">
                                                    <i class='bx bx-download'></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="10" style="text-align: center; padding: 40px; color: var(--text-light);">
                                            No historical records found for the selected filters.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                        
                        <?php if (!empty($historical_records)): ?>
                        <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center;">
                            <div>
                                <button class="secondary-button" onclick="loadMoreRecords()">
                                    <i class='bx bx-plus'></i>
                                    Load More Records
                                </button>
                            </div>
                            <div>
                                <span style="font-size: 14px; color: var(--text-light);">
                                    Page 1 of 5 • Total: <?php echo $overall_stats['total_reports'] ?? 0; ?> records
                                </span>
                            </div>
                        </div>
                        <?php endif; ?>
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
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Filter table
        function filterTable() {
            const input = document.getElementById('tableSearch');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('historyTable');
            const rows = table.getElementsByTagName('tr');
            
            for (let i = 1; i < rows.length; i++) {
                const row = rows[i];
                const cells = row.getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const text = cell.textContent || cell.innerText;
                        if (text.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                row.style.display = found ? '' : 'none';
            }
        }
        
        // Reset filters
        function resetFilters() {
            window.location.href = 'employee_historical_analytics.php';
        }
        
        // View report details
        function viewReport(reportId) {
            window.open(`view_report.php?id=${reportId}`, '_blank');
        }
        
        // Download report
        function downloadReport(reportId) {
            // In a real application, this would generate a PDF
            alert(`Downloading report #${reportId}`);
            // window.location.href = `download_report.php?id=${reportId}`;
        }
        
        // Export to PDF
        function exportToPDF() {
            alert('Exporting to PDF...');
            // In a real application, this would generate a PDF report
            // window.location.href = 'export_pdf.php';
        }
        
        // Export to Excel
        function exportToExcel() {
            alert('Exporting to Excel...');
            // In a real application, this would generate an Excel report
            // window.location.href = 'export_excel.php';
        }
        
        // Load more records
        function loadMoreRecords() {
            alert('Loading more records...');
            // In a real application, this would load more records via AJAX
        }
        
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
        
        // Update time immediately and then every second
        updateTime();
        setInterval(updateTime, 1000);
        
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
        
        // Initialize Charts when DOM is loaded
        document.addEventListener('DOMContentLoaded', function() {
            // Issue Type Distribution Chart
            const issueTypeCtx = document.getElementById('issueTypeChart').getContext('2d');
            const issueTypeChart = new Chart(issueTypeCtx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode(array_column($issue_types, 'condition_type')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($issue_types, 'count')); ?>,
                        backgroundColor: [
                            '#0d9488', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6',
                            '#10b981', '#ec4899', '#6366f1', '#f97316', '#64748b'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Monthly Trend Chart
            const monthlyCtx = document.getElementById('monthlyTrendChart').getContext('2d');
            const monthlyChart = new Chart(monthlyCtx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode(array_column($monthly_trends, 'month')); ?>,
                    datasets: [
                        {
                            label: 'Reports Filed',
                            data: <?php echo json_encode(array_column($monthly_trends, 'report_count')); ?>,
                            borderColor: '#0d9488',
                            backgroundColor: 'rgba(13, 148, 136, 0.1)',
                            tension: 0.4,
                            fill: true
                        },
                        {
                            label: 'Reports Resolved',
                            data: <?php echo json_encode(array_column($monthly_trends, 'resolved_count')); ?>,
                            borderColor: '#10b981',
                            backgroundColor: 'rgba(16, 185, 129, 0.1)',
                            tension: 0.4,
                            fill: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Yearly Comparison Chart (demo data)
            const yearlyCtx = document.getElementById('yearlyComparisonChart').getContext('2d');
            const yearlyChart = new Chart(yearlyCtx, {
                type: 'bar',
                data: {
                    labels: ['2022', '2023', '2024', '2025', '2026'],
                    datasets: [
                        {
                            label: 'Total Reports',
                            data: [120, 145, 165, 180, <?php echo $overall_stats['total_reports'] ?? 0; ?>],
                            backgroundColor: 'rgba(13, 148, 136, 0.7)'
                        },
                        {
                            label: 'Resolved Reports',
                            data: [95, 120, 140, 155, <?php echo $overall_stats['resolved_count'] ?? 0; ?>],
                            backgroundColor: 'rgba(16, 185, 129, 0.7)'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    },
                    scales: {
                        x: {
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        },
                        y: {
                            beginAtZero: true,
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    }
                }
            });
            
            // Team Performance Chart (demo data)
            const teamCtx = document.getElementById('teamPerformanceChart').getContext('2d');
            const teamChart = new Chart(teamCtx, {
                type: 'radar',
                data: {
                    labels: ['Resolution Time', 'Quality', 'Efficiency', 'Cost Control', 'Customer Satisfaction'],
                    datasets: [
                        {
                            label: 'Your Team',
                            data: [85, 90, 88, 82, 87],
                            backgroundColor: 'rgba(13, 148, 136, 0.2)',
                            borderColor: '#0d9488',
                            pointBackgroundColor: '#0d9488'
                        },
                        {
                            label: 'Average',
                            data: [75, 80, 78, 72, 77],
                            backgroundColor: 'rgba(107, 114, 128, 0.2)',
                            borderColor: '#6b7280',
                            pointBackgroundColor: '#6b7280'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            labels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            }
                        }
                    },
                    scales: {
                        r: {
                            angleLines: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            grid: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                            },
                            pointLabels: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color')
                            },
                            ticks: {
                                color: getComputedStyle(document.documentElement).getPropertyValue('--text-color'),
                                backdropColor: 'transparent'
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>