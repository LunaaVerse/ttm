<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require database connection
require_once '../../config/db_connection.php';

// Check if user is logged in and has employee role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'EMPLOYEE') {
    header("Location: ../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$barangay_id = $_SESSION['barangay_id'] ?? 1; // Default to Barangay 1 if not set

// Get user data
function getUserData($pdo, $user_id) {
    $sql = "SELECT * FROM users WHERE id = :user_id LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['user_id' => $user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$user = getUserData($pdo, $user_id);
if (!$user) {
    header("Location: ../login.php");
    exit();
}

$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Initialize filters
$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to = $_GET['date_to'] ?? date('Y-m-t');
$status_filter = $_GET['status'] ?? 'all';
$severity_filter = $_GET['severity'] ?? 'all';
$report_type_filter = $_GET['report_type'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build WHERE clause for filtering
$where_clauses = ["i.barangay_id = :barangay_id"];
$params = [':barangay_id' => $barangay_id];

if ($date_from && $date_to) {
    $where_clauses[] = "DATE(i.report_date) BETWEEN :date_from AND :date_to";
    $params[':date_from'] = $date_from;
    $params[':date_to'] = $date_to;
}

if ($status_filter !== 'all') {
    $where_clauses[] = "i.status = :status";
    $params[':status'] = $status_filter;
}

if ($severity_filter !== 'all') {
    $where_clauses[] = "i.severity = :severity";
    $params[':severity'] = $severity_filter;
}

if ($report_type_filter !== 'all') {
    $where_clauses[] = "i.report_type = :report_type";
    $params[':report_type'] = $report_type_filter;
}

if ($search_query) {
    $where_clauses[] = "(i.title LIKE :search OR i.location LIKE :search OR i.description LIKE :search OR i.tags LIKE :search)";
    $params[':search'] = "%$search_query%";
}

$where_sql = implode(' AND ', $where_clauses);

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM incident_history i WHERE $where_sql";
$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Get incident history with pagination
$sql = "SELECT i.*, b.name as barangay_name, 
        u.first_name as reporter_first, u.last_name as reporter_last,
        a.first_name as assignee_first, a.last_name as assignee_last
        FROM incident_history i
        LEFT JOIN barangays b ON i.barangay_id = b.id
        LEFT JOIN users u ON i.reported_by_id = u.id
        LEFT JOIN users a ON i.assigned_to = a.id
        WHERE $where_sql
        ORDER BY i.report_date DESC
        LIMIT :limit OFFSET :offset";

$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_sql = "SELECT 
    COUNT(*) as total_reports,
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) as resolved,
    SUM(CASE WHEN severity = 'Emergency' THEN 1 ELSE 0 END) as emergencies
    FROM incident_history 
    WHERE barangay_id = :barangay_id 
    AND DATE(report_date) BETWEEN :date_from AND :date_to";

$stats_stmt = $pdo->prepare($stats_sql);
$stats_stmt->execute([
    ':barangay_id' => $barangay_id,
    ':date_from' => $date_from,
    ':date_to' => $date_to
]);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'review_report') {
        $report_id = $_POST['report_id'];
        $review_status = $_POST['review_status'];
        $review_notes = $_POST['review_notes'];
        $severity_verified = $_POST['severity_verified'];
        $priority_verified = $_POST['priority_verified'];
        $cost_estimate = $_POST['cost_estimate'] ?? null;
        $resolution_days = $_POST['resolution_days'] ?? null;
        $return_deadline = $_POST['return_deadline'] ?? null;
        
        try {
            $pdo->beginTransaction();
            
            // Insert review record
            $review_sql = "INSERT INTO report_review_validation 
                          (report_type, report_id, reviewer_id, review_date, status, 
                           review_notes, correction_notes, return_deadline, barangay_id,
                           resolution_time_days, severity_verified, priority_verified, cost_estimate)
                          VALUES ('Incident', :report_id, :reviewer_id, NOW(), :status,
                                  :review_notes, :correction_notes, :return_deadline, :barangay_id,
                                  :resolution_days, :severity_verified, :priority_verified, :cost_estimate)";
            
            $review_stmt = $pdo->prepare($review_sql);
            $review_stmt->execute([
                ':report_id' => $report_id,
                ':reviewer_id' => $user_id,
                ':status' => $review_status,
                ':review_notes' => $review_notes,
                ':correction_notes' => ($review_status === 'Returned for Correction') ? $_POST['correction_notes'] : null,
                ':return_deadline' => $return_deadline,
                ':barangay_id' => $barangay_id,
                ':resolution_days' => $resolution_days,
                ':severity_verified' => $severity_verified,
                ':priority_verified' => $priority_verified,
                ':cost_estimate' => $cost_estimate
            ]);
            
            $review_id = $pdo->lastInsertId();
            
            // Log workflow
            $workflow_sql = "INSERT INTO review_workflow (review_id, action, performed_by, performed_at, notes)
                           VALUES (:review_id, :action, :performed_by, NOW(), :notes)";
            $workflow_stmt = $pdo->prepare($workflow_sql);
            $workflow_stmt->execute([
                ':review_id' => $review_id,
                ':action' => 'Reviewed',
                ':performed_by' => $user_id,
                ':notes' => $review_notes
            ]);
            
            // Update incident status if approved
            if ($review_status === 'Approved') {
                $update_sql = "UPDATE incident_history SET status = 'Verified' WHERE id = :report_id";
                $update_stmt = $pdo->prepare($update_sql);
                $update_stmt->execute([':report_id' => $report_id]);
                
                // Log approval
                $workflow_stmt->execute([
                    ':review_id' => $review_id,
                    ':action' => 'Approved',
                    ':performed_by' => $user_id,
                    ':notes' => 'Report approved for action'
                ]);
            }
            
            $pdo->commit();
            
            // SweetAlert success message
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Review Submitted',
                'text' => 'Report review has been successfully submitted.'
            ];
            
            header("Location: staff_report_review.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'Error',
                'text' => 'Failed to submit review: ' . $e->getMessage()
            ];
        }
    }
    
    // Archive action
    if (isset($_POST['action']) && $_POST['action'] === 'archive_report') {
        $incident_id = $_POST['incident_id'];
        $archive_reason = $_POST['archive_reason'];
        
        try {
            $pdo->beginTransaction();
            
            // Archive incident
            $archive_sql = "UPDATE incident_history 
                           SET is_archived = 1, archive_date = NOW(), archive_reason = :reason 
                           WHERE id = :incident_id";
            $archive_stmt = $pdo->prepare($archive_sql);
            $archive_stmt->execute([
                ':reason' => $archive_reason,
                ':incident_id' => $incident_id
            ]);
            
            // Log archive action
            $log_sql = "INSERT INTO archive_logs 
                       (record_type, record_id, archive_date, archived_by, archive_reason, retention_period_years, scheduled_purge_date)
                       VALUES ('Incident', :record_id, NOW(), :archived_by, :reason, 5, DATE_ADD(NOW(), INTERVAL 5 YEAR))";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                ':record_id' => $incident_id,
                ':archived_by' => $user_id,
                ':reason' => $archive_reason
            ]);
            
            $pdo->commit();
            
            $_SESSION['alert'] = [
                'type' => 'success',
                'title' => 'Report Archived',
                'text' => 'Incident report has been successfully archived.'
            ];
            
            header("Location: staff_report_review.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['alert'] = [
                'type' => 'error',
                'title' => 'Error',
                'text' => 'Failed to archive report: ' . $e->getMessage()
            ];
        }
    }
}

// Check for alert message
$alert = $_SESSION['alert'] ?? null;
unset($_SESSION['alert']);

// Function to get report details
function getReportDetails($pdo, $incident_id) {
    $sql = "SELECT i.*, b.name as barangay_name,
            u.first_name as reporter_first, u.last_name as reporter_last, u.email as reporter_email,
            a.first_name as assignee_first, a.last_name as assignee_last, a.email as assignee_email,
            rv.review_notes, rv.severity_verified, rv.priority_verified, rv.cost_estimate
            FROM incident_history i
            LEFT JOIN barangays b ON i.barangay_id = b.id
            LEFT JOIN users u ON i.reported_by_id = u.id
            LEFT JOIN users a ON i.assigned_to = a.id
            LEFT JOIN report_review_validation rv ON i.id = rv.report_id AND rv.report_type = 'Incident'
            WHERE i.id = :id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $incident_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// AJAX request for report details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_report_details' && isset($_GET['id'])) {
    $incident = getReportDetails($pdo, $_GET['id']);
    if ($incident) {
        echo '<div class="report-details">';
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Incident Code:</div>';
        echo '<div class="detail-value"><strong>' . htmlspecialchars($incident['incident_code']) . '</strong></div>';
        echo '</div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Title:</div>';
        echo '<div class="detail-value">' . htmlspecialchars($incident['title']) . '</div>';
        echo '</div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Description:</div>';
        echo '<div class="detail-value">' . nl2br(htmlspecialchars($incident['description'])) . '</div>';
        echo '</div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Location:</div>';
        echo '<div class="detail-value">' . htmlspecialchars($incident['location']);
        if ($incident['latitude'] && $incident['longitude']) {
            echo '<br><small class="text-muted">Coordinates: ' . $incident['latitude'] . ', ' . $incident['longitude'] . '</small>';
        }
        echo '</div></div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Barangay:</div>';
        echo '<div class="detail-value">' . htmlspecialchars($incident['barangay_name']) . '</div>';
        echo '</div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Reported By:</div>';
        echo '<div class="detail-value">';
        if ($incident['reporter_first']) {
            echo htmlspecialchars($incident['reporter_first'] . ' ' . $incident['reporter_last']);
            echo '<br><small class="text-muted">' . htmlspecialchars($incident['reporter_email']) . '</small>';
        } else {
            echo htmlspecialchars($incident['reported_by_name']) . ' (' . htmlspecialchars($incident['reported_by_role']) . ')';
        }
        echo '</div></div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Report Date:</div>';
        echo '<div class="detail-value">';
        echo date('F j, Y', strtotime($incident['report_date'])) . ' at ' . date('h:i A', strtotime($incident['report_date']));
        echo '</div></div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Severity:</div>';
        echo '<div class="detail-value">';
        echo '<span class="severity-badge severity-' . strtolower($incident['severity']) . '">';
        echo htmlspecialchars($incident['severity']) . '</span>';
        if ($incident['severity_verified']) {
            echo '<span class="text-muted"> (Verified: ' . $incident['severity_verified'] . ')</span>';
        }
        echo '</div></div>';
        
        echo '<div class="detail-row">';
        echo '<div class="detail-label">Status:</div>';
        echo '<div class="detail-value">';
        echo '<span class="status-badge status-' . strtolower(str_replace(' ', '-', $incident['status'])) . '">';
        echo htmlspecialchars($incident['status']) . '</span>';
        echo '</div></div>';
        
        if ($incident['assigned_to']) {
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Assigned To:</div>';
            echo '<div class="detail-value">';
            echo htmlspecialchars($incident['assignee_first'] . ' ' . $incident['assignee_last']);
            echo '<br><small class="text-muted">' . htmlspecialchars($incident['assignee_email']) . '</small>';
            echo '</div></div>';
            
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Assigned Date:</div>';
            echo '<div class="detail-value">' . date('F j, Y', strtotime($incident['assigned_date'])) . '</div>';
            echo '</div>';
        }
        
        if ($incident['resolution_date']) {
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Resolution Date:</div>';
            echo '<div class="detail-value">' . date('F j, Y', strtotime($incident['resolution_date'])) . '</div>';
            echo '</div>';
            
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Resolution Notes:</div>';
            echo '<div class="detail-value">' . nl2br(htmlspecialchars($incident['resolution_notes'])) . '</div>';
            echo '</div>';
            
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Cost Incurred:</div>';
            echo '<div class="detail-value">₱' . number_format($incident['cost_incurred'], 2) . '</div>';
            echo '</div>';
        }
        
        if ($incident['review_notes']) {
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Review Notes:</div>';
            echo '<div class="detail-value">' . nl2br(htmlspecialchars($incident['review_notes'])) . '</div>';
            echo '</div>';
            
            if ($incident['cost_estimate']) {
                echo '<div class="detail-row">';
                echo '<div class="detail-label">Estimated Cost:</div>';
                echo '<div class="detail-value">₱' . number_format($incident['cost_estimate'], 2) . '</div>';
                echo '</div>';
            }
        }
        
        if ($incident['tags']) {
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Tags:</div>';
            echo '<div class="detail-value">';
            $tags = explode(',', $incident['tags']);
            foreach ($tags as $tag) {
                echo '<span class="badge badge-outline">' . htmlspecialchars(trim($tag)) . '</span> ';
            }
            echo '</div></div>';
        }
        
        if ($incident['is_archived']) {
            echo '<div class="detail-row">';
            echo '<div class="detail-label">Archive Status:</div>';
            echo '<div class="detail-value">';
            echo '<span class="status-badge status-archived">Archived</span>';
            echo '<br><small class="text-muted">';
            echo 'Archived on: ' . date('F j, Y', strtotime($incident['archive_date']));
            echo '<br>Reason: ' . htmlspecialchars($incident['archive_reason']);
            echo '</small></div></div>';
        }
        
        echo '</div>';
        
        echo '<style>
        .report-details { max-height: 70vh; overflow-y: auto; padding-right: 10px; }
        .detail-row { display: flex; margin-bottom: 16px; border-bottom: 1px solid #e5e7eb; padding-bottom: 12px; }
        .detail-label { width: 150px; font-weight: 600; color: #374151; flex-shrink: 0; }
        .detail-value { flex: 1; color: #6b7280; }
        .badge-outline { display: inline-block; padding: 4px 8px; background: #f3f4f6; border: 1px solid #d1d5db; border-radius: 4px; font-size: 12px; margin-right: 4px; margin-bottom: 4px; }
        </style>';
    } else {
        echo '<div class="alert alert-danger">Report not found.</div>';
    }
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Report Review & Validation | Traffic & Transport Management</title>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Import existing dashboard styles */
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
            
            /* Status colors */
            --status-pending: #fbbf24;
            --status-verified: #10b981;
            --status-in-progress: #3b82f6;
            --status-resolved: #8b5cf6;
            --status-closed: #6b7280;
            --status-archived: #374151;
            
            /* Severity colors */
            --severity-low: #10b981;
            --severity-medium: #f59e0b;
            --severity-high: #ef4444;
            --severity-emergency: #dc2626;
        }
        
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
            color: var(--primary-color);
        }
        
        .icon-box-route-config {
            background-color: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
        }
        
        .icon-box-road-condition {
            background-color: rgba(245, 158, 11, 0.1);
            color: #f59e0b;
        }
        
        .icon-box-incident {
            background-color: rgba(239, 68, 68, 0.1);
            color: #ef4444;
        }
        
        .icon-box-tanod {
            background-color: rgba(139, 92, 246, 0.1);
            color: #8b5cf6;
        }
        
        .icon-box-permit {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
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
        
        /* Module-specific styles */
        .filter-section {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
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
            color: var(--text-light);
        }
        
        .filter-select, .filter-input {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        
        .stat-content {
            flex: 1;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .table-container {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-title {
            font-size: 18px;
            font-weight: 600;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th {
            padding: 16px 24px;
            text-align: left;
            font-weight: 600;
            color: var(--text-light);
            border-bottom: 1px solid var(--border-color);
            background: var(--background-color);
        }
        
        .table td {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .table tr:hover {
            background: rgba(0, 0, 0, 0.02);
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-verified { background: #d1fae5; color: #065f46; }
        .status-in-progress { background: #dbeafe; color: #1e40af; }
        .status-resolved { background: #ede9fe; color: #5b21b6; }
        .status-closed { background: #f3f4f6; color: #374151; }
        .status-archived { background: #e5e7eb; color: #111827; }
        
        .severity-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .severity-low { background: #d1fae5; color: #065f46; }
        .severity-medium { background: #fef3c7; color: #92400e; }
        .severity-high { background: #fee2e2; color: #991b1b; }
        .severity-emergency { background: #fecaca; color: #7f1d1d; }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: var(--secondary-color);
            color: white;
        }
        
        .btn-secondary:hover {
            background: var(--secondary-dark);
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-color);
        }
        
        .btn-outline:hover {
            background: rgba(0, 0, 0, 0.05);
        }
        
        .btn-sm {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            padding: 20px;
            gap: 8px;
        }
        
        .page-link {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: var(--card-bg);
            color: var(--text-color);
            text-decoration: none;
            transition: all 0.2s;
        }
        
        .page-link:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .page-link.active {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 24px;
        }
        
        .empty-state {
            padding: 48px 24px;
            text-align: center;
            color: var(--text-light);
        }
        
        .empty-state-icon {
            font-size: 48px;
            color: var(--border-color);
            margin-bottom: 16px;
        }
        
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }
        
        .alert-info {
            background: #dbeafe;
            color: #1e40af;
            border: 1px solid #93c5fd;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 4px;
        }
        
        .badge-outline {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            color: #374151;
        }
        
        .text-muted {
            color: var(--text-light);
        }
        
        .text-sm {
            font-size: 12px;
        }
        
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }
            
            .table-container {
                overflow-x: auto;
            }
            
            .table {
                min-width: 800px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
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
                    <a href="../admin_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
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
                        <a href="staff_route_management.php" class="submenu-item">Route & Terminal Encoding</a>
                        <a href="staff_driver_association.php" class="submenu-item">Driver Association</a>
                        <a href="staff_document_upload.php" class="submenu-item">Document Upload</a>
                        <a href="staff_status_tracking.php" class="submenu-item">Status Tracking</a>
                        <a href="staff_report_review.php" class="submenu-item active">Report Review & Validation</a>
                    </div>
                </div>
                
                <!-- Separator -->
                <div class="menu-separator"></div>
                
                <!-- Bottom Menu Section -->
                <div class="menu-items">
                    <a href="../settings.php" class="menu-item">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-cog'></i>
                        </div>
                        <span class="font-medium">Settings</span>
                    </a>
                    
                    <a href="../help_support.php" class="menu-item">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-help-circle'></i>
                        </div>
                        <span class="font-medium">Help & Support</span>
                    </a>
                    
                    <a href="../../includes/logout.php" class="menu-item">
                        <div class="icon-box icon-box-permit">
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
                        <h1 class="dashboard-title">Report Review & Validation</h1>
                        <p class="dashboard-subtitle">Review, validate, and manage incident reports. Archive resolved cases.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="exportReports()">
                            <i class='bx bx-export'></i>
                            Export Reports
                        </button>
                        <button class="secondary-button" onclick="showReviewModal()">
                            <i class='bx bx-check-circle'></i>
                            Quick Review
                        </button>
                    </div>
                </div>
                
                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #dbeafe; color: #3b82f6;">
                            <i class='bx bx-file'></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['total_reports'] ?? 0; ?></div>
                            <div class="stat-label">Total Reports</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fef3c7; color: #f59e0b;">
                            <i class='bx bx-time'></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['pending'] ?? 0; ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #d1fae5; color: #10b981;">
                            <i class='bx bx-check'></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['resolved'] ?? 0; ?></div>
                            <div class="stat-label">Resolved Cases</div>
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #fee2e2; color: #ef4444;">
                            <i class='bx bx-error'></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['emergencies'] ?? 0; ?></div>
                            <div class="stat-label">Emergency Cases</div>
                        </div>
                    </div>
                </div>
                
                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="GET" action="">
                        <div class="filter-grid">
                            <div class="filter-group">
                                <label class="filter-label">Date From</label>
                                <input type="date" name="date_from" class="filter-input" value="<?php echo htmlspecialchars($date_from); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Date To</label>
                                <input type="date" name="date_to" class="filter-input" value="<?php echo htmlspecialchars($date_to); ?>">
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Status</label>
                                <select name="status" class="filter-select">
                                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                    <option value="Reported" <?php echo $status_filter === 'Reported' ? 'selected' : ''; ?>>Reported</option>
                                    <option value="Verified" <?php echo $status_filter === 'Verified' ? 'selected' : ''; ?>>Verified</option>
                                    <option value="In Progress" <?php echo $status_filter === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Resolved" <?php echo $status_filter === 'Resolved' ? 'selected' : ''; ?>>Resolved</option>
                                    <option value="Closed" <?php echo $status_filter === 'Closed' ? 'selected' : ''; ?>>Closed</option>
                                    <option value="Archived" <?php echo $status_filter === 'Archived' ? 'selected' : ''; ?>>Archived</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Severity</label>
                                <select name="severity" class="filter-select">
                                    <option value="all" <?php echo $severity_filter === 'all' ? 'selected' : ''; ?>>All Severity</option>
                                    <option value="Low" <?php echo $severity_filter === 'Low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="Medium" <?php echo $severity_filter === 'Medium' ? 'selected' : ''; ?>>Medium</option>
                                    <option value="High" <?php echo $severity_filter === 'High' ? 'selected' : ''; ?>>High</option>
                                    <option value="Emergency" <?php echo $severity_filter === 'Emergency' ? 'selected' : ''; ?>>Emergency</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Report Type</label>
                                <select name="report_type" class="filter-select">
                                    <option value="all" <?php echo $report_type_filter === 'all' ? 'selected' : ''; ?>>All Types</option>
                                    <option value="Road Condition" <?php echo $report_type_filter === 'Road Condition' ? 'selected' : ''; ?>>Road Condition</option>
                                    <option value="Accident" <?php echo $report_type_filter === 'Accident' ? 'selected' : ''; ?>>Accident</option>
                                    <option value="Complaint" <?php echo $report_type_filter === 'Complaint' ? 'selected' : ''; ?>>Complaint</option>
                                    <option value="Violation" <?php echo $report_type_filter === 'Violation' ? 'selected' : ''; ?>>Violation</option>
                                    <option value="Other" <?php echo $report_type_filter === 'Other' ? 'selected' : ''; ?>>Other</option>
                                </select>
                            </div>
                            
                            <div class="filter-group">
                                <label class="filter-label">Search</label>
                                <input type="text" name="search" class="filter-input" placeholder="Search reports..." value="<?php echo htmlspecialchars($search_query); ?>">
                            </div>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class='bx bx-filter-alt'></i>
                                Apply Filters
                            </button>
                            <button type="button" class="btn btn-outline" onclick="resetFilters()">
                                <i class='bx bx-reset'></i>
                                Reset
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Incident Reports Table -->
                <div class="table-container">
                    <div class="table-header">
                        <h2 class="table-title">Incident History</h2>
                        <span class="text-muted"><?php echo $total_records; ?> records found</span>
                    </div>
                    
                    <?php if (empty($incidents)): ?>
                        <div class="empty-state">
                            <div class="empty-state-icon">
                                <i class='bx bx-file'></i>
                            </div>
                            <h3>No reports found</h3>
                            <p>Try adjusting your filters or check back later.</p>
                        </div>
                    <?php else: ?>
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Incident Code</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Severity</th>
                                    <th>Status</th>
                                    <th>Report Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($incidents as $incident): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($incident['incident_code']); ?></strong>
                                    </td>
                                    <td>
                                        <div class="font-medium"><?php echo htmlspecialchars($incident['title']); ?></div>
                                        <div class="text-sm text-muted"><?php echo htmlspecialchars($incident['location']); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge badge-outline"><?php echo htmlspecialchars($incident['report_type']); ?></span>
                                    </td>
                                    <td>
                                        <span class="severity-badge severity-<?php echo strtolower($incident['severity']); ?>">
                                            <?php echo htmlspecialchars($incident['severity']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $incident['status'])); ?>">
                                            <?php echo htmlspecialchars($incident['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo date('M d, Y', strtotime($incident['report_date'])); ?>
                                        <div class="text-sm text-muted">
                                            <?php echo date('h:i A', strtotime($incident['report_date'])); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="btn btn-sm btn-outline" onclick="viewReport(<?php echo $incident['id']; ?>)">
                                                <i class='bx bx-show'></i> View
                                            </button>
                                            
                                            <?php if ($incident['status'] === 'Reported' || $incident['status'] === 'Pending'): ?>
                                            <button class="btn btn-sm btn-primary" onclick="reviewReport(<?php echo $incident['id']; ?>)">
                                                <i class='bx bx-check'></i> Review
                                            </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($incident['status'] === 'Resolved' && !$incident['is_archived']): ?>
                                            <button class="btn btn-sm btn-secondary" onclick="archiveReport(<?php echo $incident['id']; ?>)">
                                                <i class='bx bx-archive'></i> Archive
                                            </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($incident['is_archived']): ?>
                                            <span class="text-muted text-sm">Archived</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        
                        <!-- Pagination -->
                        <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($_GET); ?>" class="page-link">
                                    <i class='bx bx-chevron-left'></i> Prev
                                </a>
                            <?php endif; ?>
                            
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <?php if ($i == $page): ?>
                                    <span class="page-link active"><?php echo $i; ?></span>
                                <?php elseif (abs($i - $page) <= 2 || $i == 1 || $i == $total_pages): ?>
                                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($_GET); ?>" class="page-link"><?php echo $i; ?></a>
                                <?php elseif (abs($i - $page) == 3): ?>
                                    <span class="page-link">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                            
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($_GET); ?>" class="page-link">
                                    Next <i class='bx bx-chevron-right'></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Review Modal -->
    <div id="reviewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Review Incident Report</h3>
                <button type="button" onclick="closeReviewModal()" class="btn btn-outline btn-sm">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="reviewForm" method="POST">
                    <input type="hidden" name="action" value="review_report">
                    <input type="hidden" name="report_id" id="report_id">
                    
                    <div class="form-group">
                        <label class="form-label">Review Status</label>
                        <select name="review_status" id="review_status" class="form-control" required onchange="toggleReturnFields()">
                            <option value="">Select Status</option>
                            <option value="Approved">Approve</option>
                            <option value="Rejected">Reject</option>
                            <option value="Returned for Correction">Return for Correction</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Severity (Verified)</label>
                        <select name="severity_verified" class="form-control" required>
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Priority (Verified)</label>
                        <select name="priority_verified" class="form-control" required>
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Emergency">Emergency</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Estimated Resolution Time (Days)</label>
                        <input type="number" name="resolution_days" class="form-control" min="1" max="30" value="3">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Cost Estimate (₱)</label>
                        <input type="number" name="cost_estimate" class="form-control" min="0" step="0.01" placeholder="0.00">
                    </div>
                    
                    <div id="returnFields" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Correction Notes</label>
                            <textarea name="correction_notes" class="form-control" rows="3" placeholder="Specify what needs to be corrected..."></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Return Deadline</label>
                            <input type="date" name="return_deadline" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Review Notes</label>
                        <textarea name="review_notes" class="form-control" rows="4" placeholder="Add your review comments..." required></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeReviewModal()">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Review</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Archive Modal -->
    <div id="archiveModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Archive Incident Report</h3>
                <button type="button" onclick="closeArchiveModal()" class="btn btn-outline btn-sm">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="archiveForm" method="POST">
                    <input type="hidden" name="action" value="archive_report">
                    <input type="hidden" name="incident_id" id="archive_incident_id">
                    
                    <div class="form-group">
                        <label class="form-label">Archive Reason</label>
                        <select name="archive_reason" class="form-control" required>
                            <option value="">Select Reason</option>
                            <option value="Resolved and documented">Resolved and documented</option>
                            <option value="Historical record">Historical record</option>
                            <option value="Seasonal analysis">Seasonal analysis</option>
                            <option value="Data cleanup">Data cleanup</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Additional Notes (Optional)</label>
                        <textarea name="archive_notes" class="form-control" rows="3" placeholder="Add any additional notes..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class='bx bx-info-circle'></i>
                        Archived reports will be retained for 5 years before automatic purging.
                    </div>
                    
                    <div class="form-actions">
                        <button type="button" class="btn btn-outline" onclick="closeArchiveModal()">Cancel</button>
                        <button type="submit" class="btn btn-secondary">Archive Report</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Incident Report Details</h3>
                <button type="button" onclick="closeViewModal()" class="btn btn-outline btn-sm">
                    <i class='bx bx-x'></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="reportDetails">
                    <!-- Details will be loaded here via AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Show alert if exists
        <?php if ($alert): ?>
        Swal.fire({
            icon: '<?php echo $alert['type']; ?>',
            title: '<?php echo $alert['title']; ?>',
            text: '<?php echo $alert['text']; ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>
        
        // Toggle submenu
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
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
        
        // Real-time clock
        function updateTime() {
            const now = new Date();
            const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
            const gmt8 = new Date(utc + (8 * 3600000));
            const hours = gmt8.getHours().toString().padStart(2, '0');
            const minutes = gmt8.getMinutes().toString().padStart(2, '0');
            const seconds = gmt8.getSeconds().toString().padStart(2, '0');
            document.getElementById('current-time').textContent = `${hours}:${minutes}:${seconds} UTC+8`;
        }
        updateTime();
        setInterval(updateTime, 1000);
        
        // Modal functions
        function showReviewModal() {
            document.getElementById('reviewModal').style.display = 'flex';
        }
        
        function closeReviewModal() {
            document.getElementById('reviewModal').style.display = 'none';
        }
        
        function reviewReport(reportId) {
            document.getElementById('report_id').value = reportId;
            showReviewModal();
        }
        
        function toggleReturnFields() {
            const status = document.getElementById('review_status').value;
            const returnFields = document.getElementById('returnFields');
            returnFields.style.display = (status === 'Returned for Correction') ? 'block' : 'none';
        }
        
        function archiveReport(incidentId) {
            document.getElementById('archive_incident_id').value = incidentId;
            document.getElementById('archiveModal').style.display = 'flex';
        }
        
        function closeArchiveModal() {
            document.getElementById('archiveModal').style.display = 'none';
        }
        
        function viewReport(incidentId) {
            // Load report details via fetch
            fetch(`staff_report_review.php?ajax=get_report_details&id=${incidentId}`)
                .then(response => response.text())
                .then(html => {
                    document.getElementById('reportDetails').innerHTML = html;
                    document.getElementById('viewModal').style.display = 'flex';
                })
                .catch(error => {
                    Swal.fire('Error', 'Failed to load report details', 'error');
                });
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        function resetFilters() {
            window.location.href = 'staff_report_review.php';
        }
        
        function exportReports() {
            Swal.fire({
                title: 'Export Reports',
                text: 'Export all filtered reports?',
                showCancelButton: true,
                confirmButtonText: 'Export as CSV',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Create CSV content
                    let csvContent = "data:text/csv;charset=utf-8,";
                    csvContent += "Incident Code,Title,Type,Severity,Status,Location,Barangay,Report Date,Resolution Date,Cost Incurred,Reported By,Tags\n";
                    
                    <?php foreach ($incidents as $incident): ?>
                    csvContent += `"<?php echo addslashes($incident['incident_code']); ?>","<?php echo addslashes($incident['title']); ?>","<?php echo addslashes($incident['report_type']); ?>","<?php echo addslashes($incident['severity']); ?>","<?php echo addslashes($incident['status']); ?>","<?php echo addslashes($incident['location']); ?>","<?php echo addslashes($incident['barangay_name']); ?>","<?php echo addslashes($incident['report_date']); ?>","<?php echo addslashes($incident['resolution_date']); ?>","<?php echo addslashes($incident['cost_incurred']); ?>","<?php echo addslashes($incident['reported_by_name']); ?>","<?php echo addslashes($incident['tags']); ?>"\n`;
                    <?php endforeach; ?>
                    
                    // Create download link
                    const encodedUri = encodeURI(csvContent);
                    const link = document.createElement("a");
                    link.setAttribute("href", encodedUri);
                    link.setAttribute("download", `incident_reports_<?php echo date('Y-m-d'); ?>.csv`);
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                }
            });
        }
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }
        
        // Set active menu
        document.addEventListener('DOMContentLoaded', function() {
            const currentUrl = window.location.pathname;
            const menuItems = document.querySelectorAll('.menu-item, .submenu-item');
            menuItems.forEach(item => {
                if (item.getAttribute('href') === currentUrl.split('/').pop()) {
                    item.classList.add('active');
                    // Expand parent submenu if this is a submenu item
                    const submenuItem = item.closest('.submenu-item');
                    if (submenuItem) {
                        const parentMenu = submenuItem.closest('.submenu').previousElementSibling;
                        if (parentMenu) {
                            parentMenu.click();
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>