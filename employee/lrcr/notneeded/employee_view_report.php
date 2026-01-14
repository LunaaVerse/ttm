<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is an employee
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'EMPLOYEE') {
    header('Location: ../index.php');
    exit();
}

// Require database connection
require_once '../config/db_connection.php';

// Get report ID from URL
$report_id = $_GET['id'] ?? 0;

// Get report details
$sql = "SELECT r.*, 
               b.name as barangay_name,
               u.first_name as reporter_first_name,
               u.last_name as reporter_last_name,
               u.email as reporter_email,
               u.contact as reporter_contact,
               a.first_name as assigned_first_name,
               a.last_name as assigned_last_name,
               v.first_name as verified_first_name,
               v.last_name as verified_last_name
        FROM road_condition_reports r
        LEFT JOIN barangays b ON r.barangay = b.name
        LEFT JOIN users u ON r.reporter_id = u.id
        LEFT JOIN users a ON r.assigned_to = a.id
        LEFT JOIN users v ON r.verified_by = v.id
        WHERE r.id = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header('Location: employee_view_reports.php');
    exit();
}

// Get assignment logs for this report
$sql_logs = "SELECT al.*, 
                    u1.first_name as assigned_by_first_name,
                    u1.last_name as assigned_by_last_name,
                    u2.first_name as assigned_to_first_name,
                    u2.last_name as assigned_to_last_name
             FROM assignment_logs al
             LEFT JOIN users u1 ON al.assigned_by = u1.id
             LEFT JOIN users u2 ON al.assigned_to = u2.id
             WHERE al.report_id = :report_id
             ORDER BY al.assignment_date DESC";
$stmt_logs = $pdo->prepare($sql_logs);
$stmt_logs->execute(['report_id' => $report_id]);
$logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Report - Local Road Condition Reporting</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <style>
        /* Include all CSS from employee_dashboard.php */
        /* Add specific styles for view report */
        
        .report-container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .report-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .report-subtitle {
            color: var(--text-light);
            font-size: 16px;
        }
        
        .report-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        
        .back-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: 1px solid var(--border-color);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        
        .back-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .two-column-layout {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 32px;
            margin-bottom: 32px;
        }
        
        @media (max-width: 768px) {
            .two-column-layout {
                grid-template-columns: 1fr;
            }
        }
        
        .info-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .info-card-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .info-item {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .info-label {
            font-size: 14px;
            color: var(--text-light);
            font-weight: 500;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 500;
        }
        
        .description-box {
            background-color: var(--background-color);
            border-radius: 8px;
            padding: 20px;
            margin-top: 16px;
        }
        
        .image-container {
            margin-top: 20px;
        }
        
        .report-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .no-image {
            text-align: center;
            padding: 40px;
            color: var(--text-light);
            background-color: var(--background-color);
            border-radius: 8px;
        }
        
        .status-timeline {
            margin-top: 32px;
        }
        
        .timeline-item {
            display: flex;
            gap: 16px;
            padding: 16px 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .timeline-item:last-child {
            border-bottom: none;
        }
        
        .timeline-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: var(--primary-color);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            flex-shrink: 0;
        }
        
        .timeline-content {
            flex: 1;
        }
        
        .timeline-title {
            font-weight: 600;
            margin-bottom: 4px;
        }
        
        .timeline-time {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .timeline-notes {
            margin-top: 8px;
            padding: 12px;
            background-color: var(--background-color);
            border-radius: 6px;
            font-size: 14px;
        }
        
        .update-form {
            margin-top: 32px;
            padding: 24px;
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-select, .form-textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
        }
        
        .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar (same as employee_dashboard.php) -->
        <div class="sidebar">
            <!-- ... Sidebar content from employee_dashboard.php ... -->
        </div>
        
        <!-- Main Content -->
        <div class="main-content">
            <!-- Header -->
            <div class="header">
                <div class="header-content">
                    <div>
                        <h1 style="font-size: 24px; font-weight: 600;">Report Details</h1>
                        <p style="color: var(--text-light);">View and manage road condition report</p>
                    </div>
                </div>
            </div>
            
            <!-- Dashboard Content -->
            <div class="dashboard-content">
                <div class="report-container">
                    <!-- Report Header -->
                    <div class="report-header">
                        <div>
                            <h1 class="report-title">Report #<?php echo $report['id']; ?>: <?php echo htmlspecialchars($report['condition_type']); ?></h1>
                            <p class="report-subtitle">Reported on <?php echo date('F j, Y', strtotime($report['report_date'])); ?></p>
                        </div>
                        <div class="report-actions">
                            <button class="back-button" onclick="window.location.href='employee_status_monitoring.php'">
                                <i class='bx bx-arrow-back'></i> Back to List
                            </button>
                            <?php if ($report['assigned_to'] == $_SESSION['user_id'] && in_array($report['status'], ['Assigned', 'In Progress'])): ?>
                                <button class="primary-button" onclick="window.location.href='employee_update_report.php?id=<?php echo $report['id']; ?>'">
                                    <i class='bx bx-edit'></i> Update Status
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Main Content -->
                    <div class="two-column-layout">
                        <!-- Left Column: Report Details -->
                        <div>
                            <!-- Basic Information -->
                            <div class="info-card">
                                <h2 class="info-card-title">Report Information</h2>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Status</span>
                                        <span class="info-value">
                                            <?php
                                            $status_class = 'status-' . str_replace(' ', '-', strtolower($report['status']));
                                            ?>
                                            <span class="status-badge <?php echo $status_class; ?>">
                                                <?php echo htmlspecialchars($report['status']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Priority</span>
                                        <span class="info-value">
                                            <?php
                                            $priority_class = 'priority-' . strtolower($report['priority']);
                                            ?>
                                            <span class="<?php echo $priority_class; ?>">
                                                <?php echo htmlspecialchars($report['priority']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Severity</span>
                                        <span class="info-value"><?php echo htmlspecialchars($report['severity']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Road Type</span>
                                        <span class="info-value"><?php echo htmlspecialchars($report['road_type']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Location Information -->
                            <div class="info-card">
                                <h2 class="info-card-title">Location Details</h2>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Barangay</span>
                                        <span class="info-value"><?php echo htmlspecialchars($report['barangay_name'] ?? $report['barangay']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Location</span>
                                        <span class="info-value"><?php echo htmlspecialchars($report['location']); ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">Condition Type</span>
                                        <span class="info-value"><?php echo htmlspecialchars($report['condition_type']); ?></span>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Description -->
                            <div class="info-card">
                                <h2 class="info-card-title">Description</h2>
                                <div class="description-box">
                                    <?php echo nl2br(htmlspecialchars($report['description'])); ?>
                                </div>
                                
                                <!-- Image -->
                                <?php if (!empty($report['image_path'])): ?>
                                    <div class="image-container">
                                        <h3 style="margin-bottom: 12px; font-weight: 500;">Attached Image</h3>
                                        <img src="../<?php echo htmlspecialchars($report['image_path']); ?>" 
                                             alt="Report Image" 
                                             class="report-image"
                                             onclick="window.open('../<?php echo htmlspecialchars($report['image_path']); ?>', '_blank')"
                                             style="cursor: pointer;">
                                    </div>
                                <?php else: ?>
                                    <div class="no-image">
                                        <i class='bx bxs-image-alt' style="font-size: 48px; margin-bottom: 16px;"></i>
                                        <p>No image attached to this report</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Resolution Notes -->
                            <?php if (!empty($report['resolution_notes'])): ?>
                                <div class="info-card">
                                    <h2 class="info-card-title">Resolution Notes</h2>
                                    <div class="description-box">
                                        <?php echo nl2br(htmlspecialchars($report['resolution_notes'])); ?>
                                    </div>
                                    <?php if ($report['resolved_date']): ?>
                                        <div style="margin-top: 16px; font-size: 14px; color: var(--text-light);">
                                            Resolved on: <?php echo date('F j, Y H:i', strtotime($report['resolved_date'])); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Right Column: Timeline and Assignment Info -->
                        <div>
                            <!-- Assignment Information -->
                            <div class="info-card">
                                <h2 class="info-card-title">Assignment Details</h2>
                                <div class="info-grid">
                                    <div class="info-item">
                                        <span class="info-label">Reporter</span>
                                        <span class="info-value">
                                            <?php echo htmlspecialchars($report['reporter_first_name'] . ' ' . $report['reporter_last_name']); ?>
                                            <br>
                                            <small style="color: var(--text-light);"><?php echo htmlspecialchars($report['reporter_role']); ?></small>
                                        </span>
                                    </div>
                                    <?php if ($report['assigned_to']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Assigned To</span>
                                            <span class="info-value">
                                                <?php echo htmlspecialchars($report['assigned_first_name'] . ' ' . $report['assigned_last_name']); ?>
                                                <?php if ($report['assigned_date']): ?>
                                                    <br>
                                                    <small style="color: var(--text-light);">
                                                        <?php echo date('M j, Y', strtotime($report['assigned_date'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($report['verified_by']): ?>
                                        <div class="info-item">
                                            <span class="info-label">Verified By</span>
                                            <span class="info-value">
                                                <?php echo htmlspecialchars($report['verified_first_name'] . ' ' . $report['verified_last_name']); ?>
                                                <?php if ($report['verified_date']): ?>
                                                    <br>
                                                    <small style="color: var(--text-light);">
                                                        <?php echo date('M j, Y', strtotime($report['verified_date'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Status Timeline -->
                            <div class="info-card">
                                <h2 class="info-card-title">Status Timeline</h2>
                                <div class="status-timeline">
                                    <!-- Report Created -->
                                    <div class="timeline-item">
                                        <div class="timeline-icon">
                                            <i class='bx bx-plus'></i>
                                        </div>
                                        <div class="timeline-content">
                                            <div class="timeline-title">Report Created</div>
                                            <div class="timeline-time">
                                                <?php echo date('F j, Y H:i', strtotime($report['created_at'])); ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Verification -->
                                    <?php if ($report['verified_date']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-icon">
                                                <i class='bx bx-check'></i>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">Report Verified</div>
                                                <div class="timeline-time">
                                                    <?php echo date('F j, Y H:i', strtotime($report['verified_date'])); ?>
                                                </div>
                                                <div class="timeline-notes">
                                                    Verified by: <?php echo htmlspecialchars($report['verified_first_name'] . ' ' . $report['verified_last_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- Assignment Logs -->
                                    <?php foreach ($logs as $log): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-icon">
                                                <i class='bx bx-transfer'></i>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">Assignment Updated</div>
                                                <div class="timeline-time">
                                                    <?php echo date('F j, Y H:i', strtotime($log['assignment_date'])); ?>
                                                </div>
                                                <div class="timeline-notes">
                                                    Assigned by: <?php echo htmlspecialchars($log['assigned_by_first_name'] . ' ' . $log['assigned_by_last_name']); ?>
                                                    <br>
                                                    Assigned to: <?php echo htmlspecialchars($log['assigned_to_first_name'] . ' ' . $log['assigned_to_last_name']); ?>
                                                    <?php if ($log['notes']): ?>
                                                        <br>
                                                        Notes: <?php echo htmlspecialchars($log['notes']); ?>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <!-- Resolution -->
                                    <?php if ($report['resolved_date']): ?>
                                        <div class="timeline-item">
                                            <div class="timeline-icon">
                                                <i class='bx bx-check-circle'></i>
                                            </div>
                                            <div class="timeline-content">
                                                <div class="timeline-title">Report Resolved</div>
                                                <div class="timeline-time">
                                                    <?php echo date('F j, Y H:i', strtotime($report['resolved_date'])); ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Add any JavaScript needed for this page
        document.addEventListener('DOMContentLoaded', function() {
            // Add click handlers for interactive elements
            const reportImage = document.querySelector('.report-image');
            if (reportImage) {
                reportImage.addEventListener('click', function() {
                    window.open(this.src, '_blank');
                });
            }
        });
    </script>
</body>
</html>