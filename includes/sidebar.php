<?php
// sidebar.php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../config/db_connection.php';

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

// Check if user is logged in
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $user = getUserData($pdo, $user_id);
} else {
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

// Prepare user data for header
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = isset($current_page) ? $current_page : 'dashboard';
?>

<!-- Sidebar -->
<div class="sidebar">
    <!-- Logo -->
    <div class="logo">
        <div class="logo-icon">
            <img src="/img/ttm.png" alt="TTM Logo" style="width: 40px; height: 30px;">
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
            
            <!-- 1.1 Route Setup -->
            <div class="menu-item" onclick="toggleSubmenu('route-setup')">
                <div class="icon-box icon-box-route-config">
                    <i class='bx bxs-map'></i>
                </div>
                <span class="font-medium">Local Road Condition Reporting</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="route-setup" class="submenu">
                <a href="system_route/patrol_routes.php" class="submenu-item">Patrol Route</a>
                <a href="system_route/parameters.php" class="submenu-item">Parameters</a>
                <a href="system_route/vehicle_registry.php" class="submenu-item">Vehicle Registry</a>
            </div>
            
            <!-- 1.2 Road Monitoring -->
            <div class="menu-item" onclick="toggleSubmenu('road-monitoring')">
                <div class="icon-box icon-box-road-condition">
                    <i class='bx bxs-report'></i>
                </div>
                <span class="font-medium">Barangay Tricycle Route Management</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="road-monitoring" class="submenu">
                <a href="analysis/performance_report.php" class="submenu-item">Performance Reports</a>
                <a href="analysis/traffic_incident.php" class="submenu-item">Traffic & Incident</a>
                <a href="analysis/system.php" class="submenu-item">System-wide KPIs</a>
            </div>
            
            <!-- 1.3 Patrol Oversight -->
            <div class="menu-item" onclick="toggleSubmenu('patrol-oversight')">
                <div class="icon-box icon-box-tanod">
                    <i class='bx bxs-user-badge'></i>
                </div>
                <span class="font-medium">Minor Accident & Incident Reporting</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="patrol-oversight" class="submenu">
                <a href="system_operations/patrol_maps.php" class="submenu-item">Patrol Maps</a>
                <a href="system_operations/tanod_shift.php" class="submenu-item">Tanod Shift</a>
                <a href="system_operations/patrol_efficiency.php" class="submenu-item">Patrol Efficiency</a>
            </div>
            
            <!-- 1.4 Permit Control -->
            <div class="menu-item" onclick="toggleSubmenu('permit-control')">
                <div class="icon-box icon-box-permit">
                    <i class='bx bxs-id-card'></i>
                </div>
                <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="permit-control" class="submenu">
                <a href="regulation_authority/transport_permits.php" class="submenu-item">Transport Permits</a>
                <a href="regulation_authority/publish_update.php" class="submenu-item">Regulatory Updates</a>
                <a href="regulation_authority/verification.php" class="submenu-item">Compliance Verification</a>
            </div>
            
            <!-- 1.5 Feedback Center -->
            <div class="menu-item" onclick="toggleSubmenu('feedback-center')">
                <div class="icon-box icon-box-feedback">
                    <i class='bx bxs-message-rounded-dots'></i>
                </div>
                <span class="font-medium">Permit and Local Regulation Tracking</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="feedback-center" class="submenu">
                <a href="feedback_review.php" class="submenu-item">Review Feedback</a>
                <a href="issue_escalation.php" class="submenu-item">Escalate Issues</a>
                <a href="feedback_reports.php" class="submenu-item">Generate Reports</a>
            </div>
            
            <!-- 1.6 Community Feedback Portal -->
            <div class="menu-item" onclick="toggleSubmenu('feedback-center2')">
                <div class="icon-box icon-box-feedback">
                    <i class='bx bxs-message-rounded-dots'></i>
                </div>
                <span class="font-medium">Community Feedback Portal</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="feedback-center2" class="submenu">
                <a href="feedback_review.php" class="submenu-item">Review Feedback</a>
                <a href="issue_escalation.php" class="submenu-item">Escalate Issues</a>
                <a href="feedback_reports.php" class="submenu-item">Generate Reports</a>
            </div>
        
            <!-- 1.7 Integration with City & Neighboring Barangays -->
            <div class="menu-item" onclick="toggleSubmenu('feedback-center3')">
                <div class="icon-box icon-box-feedback">
                    <i class='bx bxs-message-rounded-dots'></i>
                </div>
                <span class="font-medium">Integration with City & Neighboring Barangays</span>
                <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                </svg>
            </div>
            <div id="feedback-center3" class="submenu">
                <a href="feedback_review.php" class="submenu-item">Review Feedback</a>
                <a href="issue_escalation.php" class="submenu-item">Escalate Issues</a>
                <a href="feedback_reports.php" class="submenu-item">Generate Reports</a>
            </div>
        </div>
    </div>
    
    <!-- Bottom Menu Section -->
    <div class="menu-section">
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