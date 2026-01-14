<?php
// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    die('Unauthorized access');
}

require_once '../../config/db_connection.php';

if (!isset($_GET['id'])) {
    die('Report ID is required');
}

$report_id = $_GET['id'];

$sql = "SELECT rcr.*, 
               u_assigned.first_name as assignee_fname, 
               u_assigned.last_name as assignee_lname,
               u_reporter.first_name as reporter_fname, 
               u_reporter.last_name as reporter_lname,
               u_tanod.first_name as tanod_fname,
               u_tanod.last_name as tanod_lname,
               u_verified.first_name as verifier_fname,
               u_verified.last_name as verifier_lname
        FROM road_condition_reports rcr
        LEFT JOIN users u_assigned ON rcr.assigned_to = u_assigned.id
        LEFT JOIN users u_reporter ON rcr.reporter_id = u_reporter.id
        LEFT JOIN users u_tanod ON rcr.tanod_follow_up = u_tanod.id
        LEFT JOIN users u_verified ON rcr.verified_by = u_verified.id
        WHERE rcr.id = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute(['id' => $report_id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    die('Report not found');
}

// Function to format date
function formatDate($date) {
    if (!$date) return 'N/A';
    return date('F j, Y g:i A', strtotime($date));
}
?>

<div style="max-width: 800px;">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Report Information</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Report ID:</td>
                    <td style="padding: 5px 0;">#<?php echo str_pad($report['id'], 5, '0', STR_PAD_LEFT); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Report Date:</td>
                    <td style="padding: 5px 0;"><?php echo date('F j, Y', strtotime($report['report_date'])); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Status:</td>
                    <td style="padding: 5px 0;">
                        <span style="display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; 
                            background-color: <?php 
                                $colors = [
                                    'Pending' => '#fef3c7',
                                    'Verified' => '#d1fae5',
                                    'Assigned' => '#dbeafe',
                                    'In Progress' => '#f3e8ff',
                                    'Resolved' => '#dcfce7',
                                    'Rejected' => '#fee2e2',
                                    'Needs Clarification' => '#fef9c3'
                                ];
                                echo $colors[$report['status']] ?? '#e5e7eb';
                            ?>; 
                            color: <?php 
                                $textColors = [
                                    'Pending' => '#92400e',
                                    'Verified' => '#065f46',
                                    'Assigned' => '#1e40af',
                                    'In Progress' => '#6b21a8',
                                    'Resolved' => '#166534',
                                    'Rejected' => '#991b1b',
                                    'Needs Clarification' => '#854d0e'
                                ];
                                echo $textColors[$report['status']] ?? '#4b5563';
                            ?>;">
                            <?php echo $report['status']; ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Priority:</td>
                    <td style="padding: 5px 0;">
                        <span style="display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; 
                            background-color: <?php 
                                $priorityColors = [
                                    'Low' => '#d1fae5',
                                    'Medium' => '#fef3c7',
                                    'High' => '#fed7aa',
                                    'Emergency' => '#fecaca'
                                ];
                                echo $priorityColors[$report['priority']] ?? '#e5e7eb';
                            ?>; 
                            color: <?php 
                                $priorityTextColors = [
                                    'Low' => '#065f46',
                                    'Medium' => '#92400e',
                                    'High' => '#c2410c',
                                    'Emergency' => '#991b1b'
                                ];
                                echo $priorityTextColors[$report['priority']] ?? '#4b5563';
                            ?>;">
                            <?php echo $report['priority']; ?>
                        </span>
                    </td>
                </tr>
            </table>
        </div>
        
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Location Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Location:</td>
                    <td style="padding: 5px 0;"><?php echo htmlspecialchars($report['location']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Barangay:</td>
                    <td style="padding: 5px 0;"><?php echo htmlspecialchars($report['barangay']); ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Road Type:</td>
                    <td style="padding: 5px 0;"><?php echo $report['road_type']; ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Reporter Information</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Name:</td>
                    <td style="padding: 5px 0;">
                        <?php 
                        $reporter_name = $report['reporter_fname'] && $report['reporter_lname'] 
                            ? $report['reporter_fname'] . ' ' . $report['reporter_lname']
                            : $report['reporter_name'];
                        echo htmlspecialchars($reporter_name);
                        ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Role:</td>
                    <td style="padding: 5px 0;"><?php echo $report['reporter_role']; ?></td>
                </tr>
            </table>
        </div>
        
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Condition Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Condition Type:</td>
                    <td style="padding: 5px 0;"><?php echo $report['condition_type']; ?></td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Severity:</td>
                    <td style="padding: 5px 0;"><?php echo $report['severity']; ?></td>
                </tr>
            </table>
        </div>
    </div>
    
    <div style="margin-bottom: 20px;">
        <h4 style="color: #0d9488; margin-bottom: 10px;">Description</h4>
        <div style="background-color: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #0d9488;">
            <?php echo nl2br(htmlspecialchars($report['description'])); ?>
        </div>
    </div>
    
    <?php if ($report['image_path']): ?>
    <div style="margin-bottom: 20px;">
        <h4 style="color: #0d9488; margin-bottom: 10px;">Attached Image</h4>
        <div style="text-align: center;">
            <img src="<?php echo htmlspecialchars($report['image_path']); ?>" alt="Report Image" 
                 style="max-width: 100%; max-height: 300px; border-radius: 8px; border: 1px solid #e5e7eb;">
        </div>
    </div>
    <?php endif; ?>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
        <?php if ($report['verified_by']): ?>
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Verification Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Verified By:</td>
                    <td style="padding: 5px 0;">
                        <?php echo htmlspecialchars($report['verifier_fname'] . ' ' . $report['verifier_lname']); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Verification Date:</td>
                    <td style="padding: 5px 0;"><?php echo formatDate($report['verified_date']); ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($report['assigned_to']): ?>
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Assignment Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Assigned To:</td>
                    <td style="padding: 5px 0;">
                        <?php echo htmlspecialchars($report['assignee_fname'] . ' ' . $report['assignee_lname']); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Assignment Date:</td>
                    <td style="padding: 5px 0;"><?php echo formatDate($report['assigned_date']); ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($report['tanod_follow_up']): ?>
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Tanod Follow-up</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Follow-up By:</td>
                    <td style="padding: 5px 0;">
                        <?php echo htmlspecialchars($report['tanod_fname'] . ' ' . $report['tanod_lname']); ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ($report['resolved_date']): ?>
        <div>
            <h4 style="color: #0d9488; margin-bottom: 10px;">Resolution Details</h4>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 5px 0; font-weight: 500;">Resolved Date:</td>
                    <td style="padding: 5px 0;"><?php echo formatDate($report['resolved_date']); ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if ($report['follow_up_notes']): ?>
    <div style="margin-bottom: 20px;">
        <h4 style="color: #0d9488; margin-bottom: 10px;">Follow-up Notes</h4>
        <div style="background-color: #f9fafb; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b;">
            <?php echo nl2br(htmlspecialchars($report['follow_up_notes'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if ($report['resolution_notes']): ?>
    <div style="margin-bottom: 20px;">
        <h4 style="color: #0d9488; margin-bottom: 10px;">Resolution Notes</h4>
        <div style="background-color: #f0fdfa; padding: 15px; border-radius: 8px; border-left: 4px solid #10b981;">
            <?php echo nl2br(htmlspecialchars($report['resolution_notes'])); ?>
        </div>
    </div>
    <?php endif; ?>
    
    <div style="display: flex; justify-content: space-between; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e7eb;">
        <div>
            <small style="color: #6b7280;">Reported: <?php echo formatDate($report['created_at']); ?></small>
        </div>
        <div>
            <small style="color: #6b7280;">Last Updated: <?php echo formatDate($report['updated_at']); ?></small>
        </div>
    </div>
</div>