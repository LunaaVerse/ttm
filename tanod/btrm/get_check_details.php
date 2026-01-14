<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_SESSION['user_id'])) {
    die('Access denied');
}

$user_id = $_SESSION['user_id'];
$check_id = $_GET['id'] ?? 0;

try {
    // Get compliance check details
    $check_sql = "SELECT rcc.*, b.name as barangay_name, 
                         CONCAT(u.first_name, ' ', u.last_name) as tanod_name
                  FROM route_compliance_checks rcc
                  JOIN barangays b ON rcc.barangay_id = b.id
                  JOIN users u ON rcc.tanod_id = u.id
                  WHERE rcc.id = :check_id AND rcc.tanod_id = :tanod_id";
    
    $check_stmt = $pdo->prepare($check_sql);
    $check_stmt->execute(['check_id' => $check_id, 'tanod_id' => $user_id]);
    $check = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$check) {
        die('Compliance check not found');
    }
    
    // Get violations for this check
    $violations_sql = "SELECT cv.*, rv.violation_name, rv.penalty_amount as standard_penalty,
                              td.driver_code, CONCAT(td.first_name, ' ', td.last_name) as driver_name,
                              topr.operator_code, CONCAT(topr.first_name, ' ', topr.last_name) as operator_name
                       FROM compliance_violations cv
                       LEFT JOIN route_violations rv ON cv.violation_id = rv.id
                       LEFT JOIN tricycle_drivers td ON cv.driver_id = td.id
                       LEFT JOIN tricycle_operators topr ON cv.operator_id = topr.id
                       WHERE cv.check_id = :check_id";
    
    $violations_stmt = $pdo->prepare($violations_sql);
    $violations_stmt->execute(['check_id' => $check_id]);
    $violations = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get evidence for violations
    $evidence = [];
    foreach ($violations as $violation) {
        $evidence_sql = "SELECT * FROM violation_evidence WHERE violation_id = :violation_id";
        $evidence_stmt = $pdo->prepare($evidence_sql);
        $evidence_stmt->execute(['violation_id' => $violation['id']]);
        $evidence[$violation['id']] = $evidence_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    die('Error loading details: ' . $e->getMessage());
}
?>

<div class="check-details">
    <div class="detail-section">
        <div style="display: flex; justify-content: space-between; align-items: center;">
            <h4>Compliance Check Information</h4>
            <button class="action-btn action-btn-edit" onclick="editCheck(<?php echo $check_id; ?>)">
                <i class='bx bx-edit'></i> Edit
            </button>
        </div>
        <table class="detail-table">
            <tr>
                <th>Check Code:</th>
                <td><?php echo htmlspecialchars($check['check_code']); ?></td>
            </tr>
            <tr>
                <th>Date & Time:</th>
                <td><?php echo date('F j, Y h:i A', strtotime($check['check_date'])); ?></td>
            </tr>
            <tr>
                <th>Barangay:</th>
                <td><?php echo htmlspecialchars($check['barangay_name']); ?></td>
            </tr>
            <tr>
                <th>Location:</th>
                <td><?php echo htmlspecialchars($check['location']); ?></td>
            </tr>
            <?php if ($check['latitude'] && $check['longitude']): ?>
            <tr>
                <th>GPS Coordinates:</th>
                <td><?php echo $check['latitude']; ?>, <?php echo $check['longitude']; ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Check Type:</th>
                <td><?php echo htmlspecialchars($check['check_type']); ?></td>
            </tr>
            <tr>
                <th>Status:</th>
                <td>
                    <?php 
                    $badge_class = '';
                    switch($check['status']) {
                        case 'Compliant': $badge_class = 'badge-compliant'; break;
                        case 'Non-Compliant': $badge_class = 'badge-non-compliant'; break;
                        case 'Warning Issued': $badge_class = 'badge-warning'; break;
                        case 'Fine Issued': $badge_class = 'badge-fine'; break;
                    }
                    ?>
                    <span class="badge <?php echo $badge_class; ?>">
                        <?php echo htmlspecialchars($check['status']); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th>Reported By:</th>
                <td><?php echo htmlspecialchars($check['tanod_name']); ?></td>
            </tr>
            <?php if ($check['notes']): ?>
            <tr>
                <th>Notes:</th>
                <td><?php echo nl2br(htmlspecialchars($check['notes'])); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <th>Created:</th>
                <td><?php echo date('F j, Y h:i A', strtotime($check['created_at'])); ?></td>
            </tr>
            <tr>
                <th>Last Updated:</th>
                <td><?php echo date('F j, Y h:i A', strtotime($check['updated_at'])); ?></td>
            </tr>
        </table>
    </div>
    
    <?php if (!empty($violations)): ?>
    <div class="detail-section">
        <h4>Reported Violations (<?php echo count($violations); ?>)</h4>
        
        <?php foreach ($violations as $violation): ?>
        <div class="violation-detail" style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 15px;">
            <h5>Violation: <?php echo htmlspecialchars($violation['violation_name']); ?></h5>
            <table class="detail-table">
                <tr>
                    <th>Vehicle:</th>
                    <td><?php echo htmlspecialchars($violation['vehicle_number']); ?></td>
                </tr>
                <?php if ($violation['driver_name']): ?>
                <tr>
                    <th>Driver:</th>
                    <td><?php echo htmlspecialchars($violation['driver_name']); ?> (<?php echo $violation['driver_code']; ?>)</td>
                </tr>
                <?php endif; ?>
                <?php if ($violation['operator_name']): ?>
                <tr>
                    <th>Operator:</th>
                    <td><?php echo htmlspecialchars($violation['operator_name']); ?> (<?php echo $violation['operator_code']; ?>)</td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th>Violation Details:</th>
                    <td><?php echo nl2br(htmlspecialchars($violation['violation_details'])); ?></td>
                </tr>
                <tr>
                    <th>Evidence Type:</th>
                    <td><?php echo htmlspecialchars($violation['evidence_type']); ?></td>
                </tr>
                <tr>
                    <th>Fine Amount:</th>
                    <td>₱<?php echo number_format($violation['fine_amount'], 2); ?></td>
                </tr>
                <tr>
                    <th>Status:</th>
                    <td>
                        <?php 
                        $violation_badge_class = '';
                        switch($violation['status']) {
                            case 'Reported': $violation_badge_class = 'badge-reported'; break;
                            case 'Verified': $violation_badge_class = 'badge-compliant'; break;
                            case 'Appealed': $violation_badge_class = 'badge-warning'; break;
                            case 'Resolved': $violation_badge_class = 'badge-compliant'; break;
                            case 'Dismissed': $violation_badge_class = 'badge-non-compliant'; break;
                        }
                        ?>
                        <span class="badge <?php echo $violation_badge_class; ?>">
                            <?php echo htmlspecialchars($violation['status']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>Reported Date:</th>
                    <td><?php echo date('F j, Y h:i A', strtotime($violation['reported_date'])); ?></td>
                </tr>
                <?php if ($violation['verified_by']): ?>
                <tr>
                    <th>Verified Date:</th>
                    <td><?php echo date('F j, Y h:i A', strtotime($violation['verified_date'])); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($violation['resolution_notes']): ?>
                <tr>
                    <th>Resolution Notes:</th>
                    <td><?php echo nl2br(htmlspecialchars($violation['resolution_notes'])); ?></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if (!empty($evidence[$violation['id']])): ?>
            <div class="evidence-section" style="margin-top: 10px;">
                <h6>Evidence:</h6>
                <div class="evidence-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px;">
                    <?php foreach ($evidence[$violation['id']] as $evidence_item): ?>
                    <div class="evidence-item" style="border: 1px solid #ddd; padding: 10px; border-radius: 5px;">
                        <?php if ($evidence_item['evidence_type'] === 'Photo'): ?>
                            <?php if (file_exists('..' . $evidence_item['file_path'])): ?>
                                <img src="<?php echo '..' . $evidence_item['file_path']; ?>" alt="Evidence" style="max-width: 100%; height: auto;">
                            <?php else: ?>
                                <p>Photo: <?php echo htmlspecialchars($evidence_item['file_name']); ?></p>
                            <?php endif; ?>
                        <?php elseif ($evidence_item['evidence_type'] === 'Location'): ?>
                            <p>📍 GPS Location</p>
                            <small>Lat: <?php echo $evidence_item['latitude']; ?></small><br>
                            <small>Lng: <?php echo $evidence_item['longitude']; ?></small>
                        <?php else: ?>
                            <p><?php echo htmlspecialchars($evidence_item['evidence_type']); ?>:</p>
                            <small><?php echo htmlspecialchars($evidence_item['file_name']); ?></small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
.detail-section {
    margin-bottom: 25px;
    padding-bottom: 25px;
    border-bottom: 1px solid #e5e7eb;
}

.detail-section:last-child {
    border-bottom: none;
}

.detail-section h4 {
    color: #374151;
    margin-bottom: 15px;
    font-size: 18px;
}

.detail-section h5 {
    color: #4b5563;
    margin-bottom: 10px;
    font-size: 16px;
}

.detail-table {
    width: 100%;
    border-collapse: collapse;
}

.detail-table th {
    text-align: left;
    padding: 8px 15px 8px 0;
    font-weight: 600;
    color: #6b7280;
    width: 140px;
    vertical-align: top;
}

.detail-table td {
    padding: 8px 0;
    color: #374151;
}

.badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
}

.badge-compliant {
    background-color: #dcfce7;
    color: #166534;
}

.badge-non-compliant {
    background-color: #fee2e2;
    color: #991b1b;
}

.badge-warning {
    background-color: #fef3c7;
    color: #92400e;
}

.badge-reported {
    background-color: #dbeafe;
    color: #1e40af;
}

.action-btn {
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 12px;
    border: none;
    cursor: pointer;
    transition: background-color 0.2s;
}

.action-btn-edit {
    background-color: #f59e0b;
    color: white;
}

.action-btn-edit:hover {
    background-color: #d97706;
}
</style>