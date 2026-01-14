<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$action_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$action_id) {
    die('Invalid action ID');
}

// Fetch action data
$sql = "SELECT arm.*, b.name as barangay_name, 
       u1.first_name as assigned_to_name, u1.last_name as assigned_to_last,
       u2.first_name as created_by_name, u2.last_name as created_by_last
       FROM action_referral_management arm
       LEFT JOIN barangays b ON arm.barangay_id = b.id
       LEFT JOIN users u1 ON arm.assigned_to = u1.id
       LEFT JOIN users u2 ON arm.created_by = u2.id
       WHERE arm.id = :action_id";
$stmt = $pdo->prepare($sql);
$stmt->execute(['action_id' => $action_id]);
$action = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$action) {
    die('Action not found');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Action Report - <?php echo htmlspecialchars($action['action_code']); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
        .logo { font-size: 24px; font-weight: bold; color: #0d9488; }
        .title { font-size: 20px; margin: 10px 0; }
        .subtitle { color: #666; }
        .section { margin-bottom: 20px; }
        .section-title { background: #f3f4f6; padding: 8px; font-weight: bold; border-left: 4px solid #0d9488; }
        .info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px; margin-top: 10px; }
        .info-item { margin-bottom: 10px; }
        .label { font-weight: bold; color: #555; }
        .value { margin-top: 5px; }
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; }
        .footer { margin-top: 50px; text-align: center; color: #666; font-size: 12px; border-top: 1px solid #ddd; padding-top: 20px; }
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">Traffic & Transport Management</div>
        <div class="title">Action & Referral Management Report</div>
        <div class="subtitle">Action Code: <?php echo htmlspecialchars($action['action_code']); ?></div>
        <div class="subtitle">Printed on: <?php echo date('F j, Y H:i:s'); ?></div>
    </div>
    
    <div class="section">
        <div class="section-title">Basic Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Action Code:</div>
                <div class="value"><?php echo htmlspecialchars($action['action_code']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Status:</div>
                <div class="value">
                    <span class="badge" style="background: #f0fdf4; color: #166534;">
                        <?php echo htmlspecialchars($action['current_status']); ?>
                    </span>
                </div>
            </div>
            <div class="info-item">
                <div class="label">Priority:</div>
                <div class="value">
                    <span class="badge" style="background: #fee2e2; color: #991b1b;">
                        <?php echo htmlspecialchars($action['priority_level']); ?>
                    </span>
                </div>
            </div>
            <div class="info-item">
                <div class="label">Action Type:</div>
                <div class="value"><?php echo htmlspecialchars($action['action_type']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Location:</div>
                <div class="value"><?php echo htmlspecialchars($action['location']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Barangay:</div>
                <div class="value"><?php echo htmlspecialchars($action['barangay_name']); ?></div>
            </div>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">Description</div>
        <div style="padding: 10px; background: #f9fafb; border-radius: 4px;">
            <?php echo nl2br(htmlspecialchars($action['description'])); ?>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">Resolution Details</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Assigned To:</div>
                <div class="value">
                    <?php if ($action['assigned_to_name']): ?>
                        <?php echo htmlspecialchars($action['assigned_to_name'] . ' ' . $action['assigned_to_last']); ?>
                    <?php else: ?>
                        Not assigned
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="label">Resolution Deadline:</div>
                <div class="value">
                    <?php echo $action['resolution_deadline'] ? date('F j, Y', strtotime($action['resolution_deadline'])) : 'Not set'; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="label">Cost Estimate:</div>
                <div class="value">₱ <?php echo number_format($action['cost_estimate'], 2); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Actual Cost:</div>
                <div class="value">₱ <?php echo number_format($action['actual_cost'], 2); ?></div>
            </div>
        </div>
        <div style="margin-top: 15px;">
            <div class="label">Resolution Plan:</div>
            <div style="padding: 10px; background: #f9fafb; border-radius: 4px; margin-top: 5px;">
                <?php echo nl2br(htmlspecialchars($action['resolution_plan'])); ?>
            </div>
        </div>
    </div>
    
    <?php if ($action['referral_agency']): ?>
    <div class="section">
        <div class="section-title">Referral Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Referred To:</div>
                <div class="value"><?php echo htmlspecialchars($action['referral_agency']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Contact:</div>
                <div class="value"><?php echo htmlspecialchars($action['referral_contact']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Referral Date:</div>
                <div class="value">
                    <?php echo $action['referral_date'] ? date('F j, Y H:i', strtotime($action['referral_date'])) : 'Not set'; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="label">Referral Status:</div>
                <div class="value"><?php echo htmlspecialchars($action['referral_status']); ?></div>
            </div>
        </div>
        <div style="margin-top: 15px;">
            <div class="label">Referral Reason:</div>
            <div style="padding: 10px; background: #f9fafb; border-radius: 4px; margin-top: 5px;">
                <?php echo nl2br(htmlspecialchars($action['referral_reason'])); ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <div class="section-title">System Information</div>
        <div class="info-grid">
            <div class="info-item">
                <div class="label">Created By:</div>
                <div class="value"><?php echo htmlspecialchars($action['created_by_name'] . ' ' . $action['created_by_last']); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Created Date:</div>
                <div class="value"><?php echo date('F j, Y H:i', strtotime($action['created_at'])); ?></div>
            </div>
            <div class="info-item">
                <div class="label">Last Updated:</div>
                <div class="value"><?php echo date('F j, Y H:i', strtotime($action['updated_at'])); ?></div>
            </div>
        </div>
    </div>
    
    <div class="footer">
        <div>This is an official document from Traffic & Transport Management System</div>
        <div>Generated automatically by the system</div>
    </div>
    
    <script>
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>