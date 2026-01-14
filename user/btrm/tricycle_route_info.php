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
$current_page = 'route-info';

// Get barangay of user (if available)
$user_barangay_id = isset($user['barangay_id']) ? $user['barangay_id'] : 1; // Default to barangay 1

// Fetch active routes for the user's barangay
$sql_routes = "SELECT tr.*, b.name as barangay_name 
               FROM tricycle_routes tr 
               JOIN barangays b ON tr.barangay_id = b.id 
               WHERE tr.status = 'Active' 
               AND tr.submission_status = 'Approved'
               AND tr.barangay_id = :barangay_id 
               ORDER BY tr.route_name";
$stmt_routes = $pdo->prepare($sql_routes);
$stmt_routes->execute(['barangay_id' => $user_barangay_id]);
$routes = $stmt_routes->fetchAll(PDO::FETCH_ASSOC);

// Fetch terminals for the barangay
$sql_terminals = "SELECT * FROM route_terminals 
                  WHERE barangay_id = :barangay_id 
                  AND status = 'Operational'
                  ORDER BY terminal_name";
$stmt_terminals = $pdo->prepare($sql_terminals);
$stmt_terminals->execute(['barangay_id' => $user_barangay_id]);
$terminals = $stmt_terminals->fetchAll(PDO::FETCH_ASSOC);

// Fetch route restrictions
$sql_restrictions = "SELECT rr.*, b.name as barangay_name, tr.route_name 
                     FROM route_restrictions rr
                     JOIN barangays b ON rr.barangay_id = b.id
                     LEFT JOIN tricycle_routes tr ON rr.route_id = tr.id
                     WHERE rr.barangay_id = :barangay_id 
                     AND rr.status = 'Active'
                     AND (rr.expiry_date IS NULL OR rr.expiry_date >= CURDATE())
                     ORDER BY rr.effective_date DESC";
$stmt_restrictions = $pdo->prepare($sql_restrictions);
$stmt_restrictions->execute(['barangay_id' => $user_barangay_id]);
$restrictions = $stmt_restrictions->fetchAll(PDO::FETCH_ASSOC);

// Fetch service updates
$sql_updates = "SELECT rsu.*, b.name as barangay_name, 
                       tr.route_name, rt.terminal_name
                FROM route_service_updates rsu
                JOIN barangays b ON rsu.affected_barangay_id = b.id
                LEFT JOIN tricycle_routes tr ON rsu.affected_route_id = tr.id
                LEFT JOIN route_terminals rt ON rsu.affected_terminal_id = rt.id
                WHERE rsu.affected_barangay_id = :barangay_id 
                AND rsu.status = 'Active'
                AND (rsu.expiry_date IS NULL OR rsu.expiry_date >= CURDATE())
                ORDER BY rsu.priority DESC, rsu.effective_date DESC
                LIMIT 5";
$stmt_updates = $pdo->prepare($sql_updates);
$stmt_updates->execute(['barangay_id' => $user_barangay_id]);
$updates = $stmt_updates->fetchAll(PDO::FETCH_ASSOC);

// Fetch barangay info
$sql_barangay = "SELECT * FROM barangays WHERE id = :barangay_id";
$stmt_barangay = $pdo->prepare($sql_barangay);
$stmt_barangay->execute(['barangay_id' => $user_barangay_id]);
$barangay = $stmt_barangay->fetch(PDO::FETCH_ASSOC);

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    try {
        // Generate unique feedback code
        $feedback_code = 'FB-' . date('Ymd') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Insert feedback into database
        $sql = "INSERT INTO route_feedback 
                (feedback_code, user_id, feedback_type, route_id, terminal_id, barangay_id, 
                 subject, message, suggestion_details, status, priority, created_at) 
                VALUES 
                (:feedback_code, :user_id, :feedback_type, :route_id, :terminal_id, :barangay_id,
                 :subject, :message, :suggestion, 'Pending', 'Medium', NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'feedback_code' => $feedback_code,
            'user_id' => $user_id,
            'feedback_type' => $_POST['feedback_type'],
            'route_id' => !empty($_POST['route_id']) ? $_POST['route_id'] : null,
            'terminal_id' => !empty($_POST['terminal_id']) ? $_POST['terminal_id'] : null,
            'barangay_id' => $user_barangay_id,
            'subject' => $_POST['subject'],
            'message' => $_POST['message'],
            'suggestion' => $_POST['suggestion'] ?? null
        ]);
        
        $feedback_success = true;
        $feedback_message = "Feedback submitted successfully! Reference: " . $feedback_code;
        
    } catch (Exception $e) {
        $feedback_error = true;
        $feedback_message = "Error submitting feedback: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tricycle Route Information - Barangay Resident Portal</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
        
        /* New Styles for Route Information Module */
        
        .quick-nav {
            display: flex;
            gap: 12px;
            margin-top: 24px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        
        .nav-button {
            padding: 12px 24px;
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-button:hover {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .nav-button.active {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .section-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 24px;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .section-title i {
            color: var(--primary-color);
        }
        
        .cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }
        
        .info-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .info-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .card-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-active {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .badge-active {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .badge-inactive {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .card-content {
            margin-bottom: 16px;
        }
        
        .card-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .item-label {
            color: var(--text-light);
        }
        
        .item-value {
            font-weight: 500;
            color: var(--text-color);
        }
        
        .route-stops {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border-color);
        }
        
        .stop-item {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .stop-number {
            width: 24px;
            height: 24px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 600;
        }
        
        .terminal-card {
            position: relative;
        }
        
        .terminal-marker {
            position: absolute;
            top: 24px;
            right: 24px;
            width: 40px;
            height: 40px;
            background-color: rgba(13, 148, 136, 0.1);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
        }
        
        .restriction-card {
            border-left: 4px solid #f59e0b;
        }
        
        .restriction-time {
            display: inline-block;
            padding: 4px 8px;
            background-color: #fef3c7;
            border-radius: 4px;
            font-size: 12px;
            margin-top: 8px;
        }
        
        .dark-mode .restriction-time {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .update-card {
            border-left: 4px solid #3b82f6;
        }
        
        .update-priority {
            font-size: 12px;
            padding: 4px 8px;
            border-radius: 4px;
            margin-top: 8px;
        }
        
        .priority-high {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .priority-high {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .priority-medium {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .priority-medium {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .priority-low {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .priority-low {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        .map-container {
            height: 200px;
            background-color: #e5e7eb;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-light);
        }
        
        .dark-mode .map-container {
            background-color: #334155;
        }
        
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-light);
        }
        
        .empty-icon {
            font-size: 48px;
            color: var(--border-color);
            margin-bottom: 16px;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .feedback-section {
            background-color: var(--card-bg);
            border-radius: 16px;
            padding: 32px;
            margin-top: 48px;
            border: 1px solid var(--border-color);
        }
        
        .feedback-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 16px;
            color: var(--text-color);
        }
        
        .feedback-form {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .form-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-select, .form-input, .form-textarea {
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-textarea {
            min-height: 120px;
            resize: vertical;
        }
        
        .form-select:focus, .form-input:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-secondary:hover {
            background-color: var(--border-color);
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .cards-grid {
                grid-template-columns: repeat(2, 1fr);
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
            
            .cards-grid {
                grid-template-columns: 1fr;
            }
            
            .quick-nav {
                flex-direction: column;
            }
            
            .nav-button {
                width: 100%;
                justify-content: center;
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
                <span class="logo-text"> Barangay Resident Portal</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY SERVICES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button -->
                    <a href="user_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- Tricycle Route Information -->
                    <div class="menu-item active" onclick="toggleSubmenu('route-info')">
                        <div class="icon-box icon-box-route-info">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Tricycle Route Information</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-info" class="submenu active">
                        <a href="btrm/tricycle_route_info.php" class="submenu-item active">View Routes & Terminals</a>
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
                            <input type="text" placeholder="Search routes or terminals" class="search-input" id="searchInput">
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
                        <h1 class="dashboard-title">Tricycle Route Information</h1>
                        <p class="dashboard-subtitle">View approved routes, terminals, restrictions, and service updates in <?php echo htmlspecialchars($barangay['name'] ?? 'your barangay'); ?></p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="window.location.href='user_feedback/submit_feedback.php'">
                            <span style="font-size: 20px;">+</span>
                            Submit Feedback
                        </button>
                        <button class="secondary-button" onclick="printRouteInfo()">
                            <i class='bx bx-printer'></i>
                            Print Information
                        </button>
                    </div>
                </div>
                
                <!-- Quick Navigation -->
                <div class="quick-nav">
                    <button class="nav-button active" onclick="showTab('routes')" id="tab-routes">
                        <i class='bx bx-map'></i>
                        Approved Routes
                    </button>
                    <button class="nav-button" onclick="showTab('terminals')" id="tab-terminals">
                        <i class='bx bx-current-location'></i>
                        Terminal Locator
                    </button>
                    <button class="nav-button" onclick="showTab('restrictions')" id="tab-restrictions">
                        <i class='bx bx-no-entry'></i>
                        Route Restrictions
                    </button>
                    <button class="nav-button" onclick="showTab('updates')" id="tab-updates">
                        <i class='bx bx-news'></i>
                        Service Updates
                    </button>
                </div>
                
                <!-- Tab Content -->
                <div id="tab-content">
                    <!-- Approved Routes Tab -->
                    <div class="tab-content active" id="routes-tab">
                        <h2 class="section-title">
                            <i class='bx bx-map'></i>
                            Approved Tricycle Routes
                        </h2>
                        
                        <?php if (empty($routes)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class='bx bx-map-alt'></i>
                                </div>
                                <h3>No Routes Available</h3>
                                <p>There are no approved tricycle routes in your barangay at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="cards-grid">
                                <?php foreach ($routes as $route): ?>
                                    <div class="info-card">
                                        <div class="card-header">
                                            <h3 class="card-title"><?php echo htmlspecialchars($route['route_name']); ?></h3>
                                            <span class="card-badge badge-active">Active</span>
                                        </div>
                                        <div class="card-content">
                                            <div class="card-item">
                                                <span class="item-label">Route Code:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($route['route_code']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Start Point:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($route['start_point']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">End Point:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($route['end_point']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Distance:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($route['distance_km']); ?> km</span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Fare:</span>
                                                <span class="item-value">₱<?php echo htmlspecialchars($route['fare_regular']); ?> (Regular)</span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Operating Hours:</span>
                                                <span class="item-value">
                                                    <?php echo date('g:i A', strtotime($route['operating_hours_start'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($route['operating_hours_end'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="route-stops">
                                            <p style="font-size: 14px; color: var(--text-light); margin-bottom: 12px;">Route Stops:</p>
                                            <?php 
                                            // Fetch stops for this route
                                            $sql_stops = "SELECT * FROM route_stops WHERE route_id = :route_id ORDER BY stop_number";
                                            $stmt_stops = $pdo->prepare($sql_stops);
                                            $stmt_stops->execute(['route_id' => $route['id']]);
                                            $stops = $stmt_stops->fetchAll(PDO::FETCH_ASSOC);
                                            
                                            if (!empty($stops)) {
                                                foreach ($stops as $stop):
                                            ?>
                                                <div class="stop-item">
                                                    <div class="stop-number"><?php echo $stop['stop_number']; ?></div>
                                                    <div>
                                                        <div><?php echo htmlspecialchars($stop['stop_name']); ?></div>
                                                        <div style="font-size: 12px; color: var(--text-light);"><?php echo htmlspecialchars($stop['location']); ?></div>
                                                    </div>
                                                </div>
                                            <?php 
                                                endforeach;
                                            } else {
                                                echo '<p style="color: var(--text-light); font-size: 14px;">No stops defined for this route.</p>';
                                            }
                                            ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Terminal Locator Tab -->
                    <div class="tab-content" id="terminals-tab">
                        <h2 class="section-title">
                            <i class='bx bx-current-location'></i>
                            Terminal & Stop Locator
                        </h2>
                        
                        <?php if (empty($terminals)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class='bx bx-current-location'></i>
                                </div>
                                <h3>No Terminals Available</h3>
                                <p>There are no active terminals in your barangay at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="cards-grid">
                                <?php foreach ($terminals as $terminal): ?>
                                    <div class="info-card terminal-card">
                                        <div class="terminal-marker">
                                            <i class='bx bx-map-pin'></i>
                                        </div>
                                        <div class="card-header">
                                            <h3 class="card-title"><?php echo htmlspecialchars($terminal['terminal_name']); ?></h3>
                                            <span class="card-badge badge-active">Operational</span>
                                        </div>
                                        <div class="card-content">
                                            <div class="card-item">
                                                <span class="item-label">Terminal Code:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($terminal['terminal_code']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Type:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($terminal['terminal_type']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Location:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($terminal['location']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Capacity:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($terminal['capacity'] ?? 'N/A'); ?> vehicles</span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Operating Hours:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($terminal['operating_hours'] ?? '24/7'); ?></span>
                                            </div>
                                            <?php if ($terminal['contact_person']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Contact Person:</span>
                                                    <span class="item-value"><?php echo htmlspecialchars($terminal['contact_person']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($terminal['contact_number']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Contact Number:</span>
                                                    <span class="item-value"><?php echo htmlspecialchars($terminal['contact_number']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="map-container">
                                            <div style="text-align: center;">
                                                <i class='bx bx-map-alt' style="font-size: 48px; margin-bottom: 16px;"></i>
                                                <p>Map view for: <?php echo htmlspecialchars($terminal['terminal_name']); ?></p>
                                                <p style="font-size: 12px; margin-top: 8px;">
                                                    <?php if ($terminal['latitude'] && $terminal['longitude']): ?>
                                                        Coordinates: <?php echo htmlspecialchars($terminal['latitude']); ?>, 
                                                        <?php echo htmlspecialchars($terminal['longitude']); ?>
                                                    <?php else: ?>
                                                        Coordinates not available
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Route Restrictions Tab -->
                    <div class="tab-content" id="restrictions-tab">
                        <h2 class="section-title">
                            <i class='bx bx-no-entry'></i>
                            Route Restrictions Notice
                        </h2>
                        
                        <?php if (empty($restrictions)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class='bx bx-no-entry'></i>
                                </div>
                                <h3>No Active Restrictions</h3>
                                <p>There are no active route restrictions in your barangay at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="cards-grid">
                                <?php foreach ($restrictions as $restriction): ?>
                                    <div class="info-card restriction-card">
                                        <div class="card-header">
                                            <h3 class="card-title"><?php echo htmlspecialchars($restriction['restriction_type']); ?></h3>
                                            <span class="card-badge badge-active">Active</span>
                                        </div>
                                        <div class="card-content">
                                            <div class="card-item">
                                                <span class="item-label">Road Name:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($restriction['road_name']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Location:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($restriction['location']); ?></span>
                                            </div>
                                            <?php if ($restriction['route_name']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Affected Route:</span>
                                                    <span class="item-value"><?php echo htmlspecialchars($restriction['route_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="card-item">
                                                <span class="item-label">Description:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($restriction['description']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Effective Date:</span>
                                                <span class="item-value"><?php echo date('F j, Y', strtotime($restriction['effective_date'])); ?></span>
                                            </div>
                                            <?php if ($restriction['expiry_date']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Expiry Date:</span>
                                                    <span class="item-value"><?php echo date('F j, Y', strtotime($restriction['expiry_date'])); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($restriction['restriction_time_start'] && $restriction['restriction_time_end']): ?>
                                                <div class="restriction-time">
                                                    Time: <?php echo date('g:i A', strtotime($restriction['restriction_time_start'])); ?> - 
                                                    <?php echo date('g:i A', strtotime($restriction['restriction_time_end'])); ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($restriction['penalty_amount']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Penalty:</span>
                                                    <span class="item-value">₱<?php echo number_format($restriction['penalty_amount'], 2); ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Service Updates Tab -->
                    <div class="tab-content" id="updates-tab">
                        <h2 class="section-title">
                            <i class='bx bx-news'></i>
                            Service Updates & Notices
                        </h2>
                        
                        <?php if (empty($updates)): ?>
                            <div class="empty-state">
                                <div class="empty-icon">
                                    <i class='bx bx-news'></i>
                                </div>
                                <h3>No Service Updates</h3>
                                <p>There are no active service updates at the moment.</p>
                            </div>
                        <?php else: ?>
                            <div class="cards-grid">
                                <?php foreach ($updates as $update): ?>
                                    <div class="info-card update-card">
                                        <div class="card-header">
                                            <h3 class="card-title"><?php echo htmlspecialchars($update['title']); ?></h3>
                                            <span class="card-badge badge-active">Active</span>
                                        </div>
                                        <div class="card-content">
                                            <div class="card-item">
                                                <span class="item-label">Update Type:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($update['update_type']); ?></span>
                                            </div>
                                            <?php if ($update['route_name']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Affected Route:</span>
                                                    <span class="item-value"><?php echo htmlspecialchars($update['route_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($update['terminal_name']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Affected Terminal:</span>
                                                    <span class="item-value"><?php echo htmlspecialchars($update['terminal_name']); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="card-item">
                                                <span class="item-label">Description:</span>
                                                <span class="item-value"><?php echo htmlspecialchars($update['description']); ?></span>
                                            </div>
                                            <div class="card-item">
                                                <span class="item-label">Effective Date:</span>
                                                <span class="item-value"><?php echo date('F j, Y', strtotime($update['effective_date'])); ?></span>
                                            </div>
                                            <?php if ($update['expiry_date']): ?>
                                                <div class="card-item">
                                                    <span class="item-label">Expiry Date:</span>
                                                    <span class="item-value"><?php echo date('F j, Y', strtotime($update['expiry_date'])); ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <div class="update-priority priority-<?php echo strtolower($update['priority']); ?>">
                                                Priority: <?php echo htmlspecialchars($update['priority']); ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Feedback Section -->
                <div class="feedback-section">
                    <h2 class="feedback-title">Have Feedback About Our Tricycle Services?</h2>
                    <p style="color: var(--text-light); margin-bottom: 24px;">Share your suggestions, report issues, or ask questions about tricycle routes and services.</p>
                    
                    <?php if (isset($feedback_success) && $feedback_success): ?>
                        <div style="background-color: #dcfce7; color: #166534; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                            <i class='bx bx-check-circle' style="margin-right: 8px;"></i>
                            <?php echo htmlspecialchars($feedback_message); ?>
                        </div>
                    <?php elseif (isset($feedback_error) && $feedback_error): ?>
                        <div style="background-color: #fee2e2; color: #991b1b; padding: 16px; border-radius: 8px; margin-bottom: 24px;">
                            <i class='bx bx-error-circle' style="margin-right: 8px;"></i>
                            <?php echo htmlspecialchars($feedback_message); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form class="feedback-form" method="POST" action="">
                        <input type="hidden" name="submit_feedback" value="1">
                        
                        <div class="form-group">
                            <label class="form-label">Feedback Type</label>
                            <select class="form-select" name="feedback_type" required>
                                <option value="">Select type...</option>
                                <option value="Route Suggestion">Route Suggestion</option>
                                <option value="Terminal Issue">Terminal Issue</option>
                                <option value="Restriction Concern">Restriction Concern</option>
                                <option value="Service Complaint">Service Complaint</option>
                                <option value="General Feedback">General Feedback</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Subject</label>
                            <input type="text" class="form-input" name="subject" placeholder="Brief description of your feedback" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Message</label>
                            <textarea class="form-textarea" name="message" placeholder="Please provide details about your feedback..." required></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Suggestion (Optional)</label>
                            <textarea class="form-textarea" name="suggestion" placeholder="Any suggestions for improvement?"></textarea>
                        </div>
                        
                        <button type="submit" class="btn-primary" style="align-self: flex-start;">
                            <i class='bx bx-send'></i>
                            Submit Feedback
                        </button>
                    </form>
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
        
        // Tab navigation
        function showTab(tabName) {
            // Update active tab button
            document.querySelectorAll('.nav-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // Show active tab content
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Save active tab to localStorage
            localStorage.setItem('activeRouteTab', tabName);
        }
        
        // Load saved tab preference
        const savedTab = localStorage.getItem('activeRouteTab');
        if (savedTab) {
            showTab(savedTab);
        }
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            
            // Search in all cards
            document.querySelectorAll('.info-card').forEach(card => {
                const cardText = card.textContent.toLowerCase();
                if (cardText.includes(searchTerm)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        });
        
        // Print functionality
        function printRouteInfo() {
            const activeTab = document.querySelector('.tab-content.active').id.replace('-tab', '');
            const tabNames = {
                'routes': 'Approved Tricycle Routes',
                'terminals': 'Terminal & Stop Locator',
                'restrictions': 'Route Restrictions Notice',
                'updates': 'Service Updates & Notices'
            };
            
            Swal.fire({
                title: 'Print Information',
                text: `Do you want to print the "${tabNames[activeTab]}" information?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, print',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    const printContent = document.querySelector('.tab-content.active').innerHTML;
                    const printWindow = window.open('', '_blank');
                    printWindow.document.write(`
                        <html>
                        <head>
                            <title>Tricycle Route Information - ${tabNames[activeTab]}</title>
                            <style>
                                body { font-family: Arial, sans-serif; padding: 20px; }
                                .card { border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; }
                                .card-title { font-size: 18px; font-weight: bold; margin-bottom: 10px; }
                                .card-item { display: flex; justify-content: space-between; margin-bottom: 8px; }
                                .item-label { color: #666; }
                                .print-header { text-align: center; margin-bottom: 30px; }
                                .print-header h1 { color: #0d9488; }
                                .print-footer { text-align: center; margin-top: 30px; color: #666; font-size: 12px; }
                            </style>
                        </head>
                        <body>
                            <div class="print-header">
                                <h1>${tabNames[activeTab]}</h1>
                                <p>Barangay: ${document.querySelector('.dashboard-subtitle').textContent.split('in ')[1]}</p>
                                <p>Printed on: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}</p>
                            </div>
                            ${printContent}
                            <div class="print-footer">
                                <p>Generated by Barangay Resident Portal</p>
                            </div>
                        </body>
                        </html>
                    `);
                    printWindow.document.close();
                    printWindow.print();
                }
            });
        }
        
        // Add hover effects to cards
        document.querySelectorAll('.info-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-5px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
        
        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Check if there's a feedback success message
            <?php if (isset($feedback_success) && $feedback_success): ?>
                Swal.fire({
                    icon: 'success',
                    title: 'Feedback Submitted!',
                    text: 'Thank you for your feedback.',
                    timer: 3000,
                    showConfirmButton: false
                });
            <?php endif; ?>
        });
    </script>
</body>
</html>