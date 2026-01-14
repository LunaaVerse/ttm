<?php
session_start();
require_once '../config/db_connection.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['id'])) {
    echo '<p>Invalid request</p>';
    exit();
}

$report_id = $_GET['id'];

try {
    // Fetch report details with barangay information
    $sql = "SELECT r.*, b.name as barangay_name, b.contact_person, b.contact_number,
            CONCAT(u1.first_name, ' ', u1.last_name) as reporter_name,
            CONCAT(u2.first_name, ' ', u2.last_name) as assigned_to_name
            FROM road_condition_reports r
            LEFT JOIN barangays b ON r.barangay = b.name
            LEFT JOIN users u1 ON r.reporter_id = u1.id
            LEFT JOIN users u2 ON r.assigned_to = u2.id
            WHERE r.id = :report_id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['report_id' => $report_id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        echo '<p>Report not found</p>';
        exit();
    }
    
    // Status badge class
    $status_class = 'status-' . strtolower(str_replace(' ', '-', $report['status'])) . '-badge';
    $priority_class = 'priority-' . strtolower($report['priority']) . '-badge';
    $severity_class = 'severity-' . strtolower($report['severity']) . '-badge';
    
    ?>
    <div class="report-details">
        <div class="detail-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <h3 style="margin-bottom: 10px; color: var(--primary-color);">Basic Information</h3>
                <p><strong>Report ID:</strong> #<?php echo $report['id']; ?></p>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($report['location']); ?></p>
                <p><strong>Barangay:</strong> <?php echo htmlspecialchars($report['barangay']); ?></p>
                <p><strong>Road Type:</strong> <?php echo htmlspecialchars($report['road_type']); ?></p>
                <p><strong>Condition:</strong> <?php echo htmlspecialchars($report['condition_type']); ?></p>
            </div>
            
            <div>
                <h3 style="margin-bottom: 10px; color: var(--primary-color);">Status Information</h3>
                <p><strong>Status:</strong> <span class="status-badge <?php echo $status_class; ?>"><?php echo $report['status']; ?></span></p>
                <p><strong>Priority:</strong> <span class="priority-badge <?php echo $priority_class; ?>"><?php echo $report['priority']; ?></span></p>
                <p><strong>Severity:</strong> <span class="severity-badge <?php echo $severity_class; ?>"><?php echo $report['severity']; ?></span></p>
                <p><strong>Report Date:</strong> <?php echo date('F d, Y', strtotime($report['report_date'])); ?></p>
                <p><strong>Reported By:</strong> <?php echo htmlspecialchars($report['reporter_name'] ?: $report['reporter_name']); ?></p>
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <h3 style="margin-bottom: 10px; color: var(--primary-color);">Description</h3>
            <div style="background-color: var(--background-color); padding: 15px; border-radius: 8px;">
                <?php echo nl2br(htmlspecialchars($report['description'])); ?>
            </div>
        </div>
        
        <?php if ($report['image_path']): ?>
        <div style="margin-bottom: 20px;">
            <h3 style="margin-bottom: 10px; color: var(--primary-color);">Attached Image</h3>
            <img src="<?php echo htmlspecialchars($report['image_path']); ?>" 
                 alt="Report Image" 
                 style="max-width: 100%; border-radius: 8px; border: 1px solid var(--border-color);">
        </div>
        <?php endif; ?>
        
        <div class="detail-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <?php if ($report['assigned_to']): ?>
            <div>
                <h3 style="margin-bottom: 10px; color: var(--primary-color);">Assignment</h3>
                <p><strong>Assigned To:</strong> <?php echo htmlspecialchars($report['assigned_to_name']); ?></p>
                <?php if ($report['assigned_date']): ?>
                    <p><strong>Assigned Date:</strong> <?php echo date('F d, Y H:i', strtotime($report['assigned_date'])); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($report['barangay_name']): ?>
            <div>
                <h3 style="margin-bottom: 10px; color: var(--primary-color);">Barangay Contact</h3>
                <p><strong>Contact Person:</strong> <?php echo htmlspecialchars($report['contact_person']); ?></p>
                <p><strong>Contact Number:</strong> <?php echo htmlspecialchars($report['contact_number']); ?></p>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($report['resolution_notes']): ?>
        <div style="margin-top: 20px;">
            <h3 style="margin-bottom: 10px; color: var(--primary-color);">Resolution Notes</h3>
            <div style="background-color: rgba(16, 185, 129, 0.1); padding: 15px; border-radius: 8px; border-left: 4px solid var(--status-resolved);">
                <?php echo nl2br(htmlspecialchars($report['resolution_notes'])); ?>
            </div>
            <?php if ($report['resolved_date']): ?>
                <p style="margin-top: 5px; font-size: 14px; color: var(--text-light);">
                    Resolved on: <?php echo date('F d, Y H:i', strtotime($report['resolved_date'])); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($report['follow_up_notes']): ?>
        <div style="margin-top: 20px;">
            <h3 style="margin-bottom: 10px; color: var(--primary-color);">Follow-up Notes</h3>
            <div style="background-color: rgba(14, 165, 233, 0.1); padding: 15px; border-radius: 8px; border-left: 4px solid var(--status-in-progress);">
                <?php echo nl2br(htmlspecialchars($report['follow_up_notes'])); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php
    
} catch (Exception $e) {
    echo '<p>Error loading report details: ' . htmlspecialchars($e->getMessage()) . '</p>';
}
?>