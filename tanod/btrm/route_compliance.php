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
    // Redirect to login if not logged in
    header('Location: ../login.php');
    exit();
}

// Check if user is TANOD
if ($user['role'] !== 'TANOD') {
    die('Access denied. This page is for TANOD only.');
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'route-compliance';

// Handle form submission for new compliance check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_compliance_check'])) {
        try {
            $check_code = 'RCC-' . date('Y') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
            $check_date = $_POST['check_date'];
            $barangay_id = $_POST['barangay_id'];
            $location = $_POST['location'];
            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];
            $check_type = $_POST['check_type'];
            $status = $_POST['status'];
            $notes = $_POST['notes'];
            
            // Insert compliance check
            $sql = "INSERT INTO route_compliance_checks 
                    (check_code, tanod_id, check_date, barangay_id, location, latitude, longitude, check_type, status, notes) 
                    VALUES 
                    (:check_code, :tanod_id, :check_date, :barangay_id, :location, :latitude, :longitude, :check_type, :status, :notes)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'check_code' => $check_code,
                'tanod_id' => $user_id,
                'check_date' => $check_date,
                'barangay_id' => $barangay_id,
                'location' => $location,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'check_type' => $check_type,
                'status' => $status,
                'notes' => $notes
            ]);
            
            $check_id = $pdo->lastInsertId();
            
            // If there's a violation, handle it
            if (isset($_POST['has_violation']) && $_POST['has_violation'] == '1') {
                $violation_id = $_POST['violation_id'];
                $driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
                $operator_id = !empty($_POST['operator_id']) ? $_POST['operator_id'] : null;
                $vehicle_number = $_POST['vehicle_number'];
                $violation_details = $_POST['violation_details'];
                $evidence_type = $_POST['evidence_type'];
                $fine_amount = $_POST['fine_amount'];
                
                // Insert violation
                $violation_sql = "INSERT INTO compliance_violations 
                                 (check_id, violation_id, driver_id, operator_id, vehicle_number, 
                                  violation_details, evidence_type, fine_amount, status, 
                                  reported_by, reported_date) 
                                 VALUES 
                                 (:check_id, :violation_id, :driver_id, :operator_id, :vehicle_number, 
                                  :violation_details, :evidence_type, :fine_amount, 'Reported',
                                  :reported_by, :reported_date)";
                
                $violation_stmt = $pdo->prepare($violation_sql);
                $violation_stmt->execute([
                    'check_id' => $check_id,
                    'violation_id' => $violation_id,
                    'driver_id' => $driver_id,
                    'operator_id' => $operator_id,
                    'vehicle_number' => $vehicle_number,
                    'violation_details' => $violation_details,
                    'evidence_type' => $evidence_type,
                    'fine_amount' => $fine_amount,
                    'reported_by' => $user_id,
                    'reported_date' => $check_date
                ]);
                
                $violation_id_inserted = $pdo->lastInsertId();
                
                // Handle file upload for evidence
                if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] == 0) {
                    handleEvidenceUpload($pdo, $violation_id_inserted, $evidence_type, $latitude, $longitude, $location, $user_id, $check_date);
                }
                
                // Also save GPS coordinates as location evidence
                if (!empty($latitude) && !empty($longitude)) {
                    saveGPSEvidence($pdo, $violation_id_inserted, $latitude, $longitude, $location, $user_id, $check_date);
                }
            }
            
            $success_message = "Compliance check recorded successfully!";
            
        } catch (Exception $e) {
            $error_message = "Error recording compliance check: " . $e->getMessage();
        }
    }
    // Handle edit submission
    elseif (isset($_POST['edit_compliance_check'])) {
        try {
            $check_id = $_POST['check_id'];
            $check_date = $_POST['check_date'];
            $barangay_id = $_POST['barangay_id'];
            $location = $_POST['location'];
            $latitude = $_POST['latitude'];
            $longitude = $_POST['longitude'];
            $check_type = $_POST['check_type'];
            $status = $_POST['status'];
            $notes = $_POST['notes'];
            
            // Update compliance check
            $sql = "UPDATE route_compliance_checks 
                    SET check_date = :check_date, 
                        barangay_id = :barangay_id,
                        location = :location,
                        latitude = :latitude,
                        longitude = :longitude,
                        check_type = :check_type,
                        status = :status,
                        notes = :notes,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :check_id AND tanod_id = :tanod_id";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'check_date' => $check_date,
                'barangay_id' => $barangay_id,
                'location' => $location,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'check_type' => $check_type,
                'status' => $status,
                'notes' => $notes,
                'check_id' => $check_id,
                'tanod_id' => $user_id
            ]);
            
            // Handle violation update
            if (isset($_POST['has_violation']) && $_POST['has_violation'] == '1') {
                $violation_id = $_POST['violation_id'] ?? 0;
                $driver_id = !empty($_POST['driver_id']) ? $_POST['driver_id'] : null;
                $operator_id = !empty($_POST['operator_id']) ? $_POST['operator_id'] : null;
                $vehicle_number = $_POST['vehicle_number'];
                $violation_details = $_POST['violation_details'];
                $evidence_type = $_POST['evidence_type'];
                $fine_amount = $_POST['fine_amount'];
                
                // Check if violation already exists
                $check_violation_sql = "SELECT id FROM compliance_violations WHERE check_id = :check_id";
                $check_violation_stmt = $pdo->prepare($check_violation_sql);
                $check_violation_stmt->execute(['check_id' => $check_id]);
                $existing_violation = $check_violation_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_violation) {
                    // Update existing violation
                    $violation_sql = "UPDATE compliance_violations 
                                     SET violation_id = :violation_id,
                                         driver_id = :driver_id,
                                         operator_id = :operator_id,
                                         vehicle_number = :vehicle_number,
                                         violation_details = :violation_details,
                                         evidence_type = :evidence_type,
                                         fine_amount = :fine_amount,
                                         updated_at = CURRENT_TIMESTAMP
                                     WHERE check_id = :check_id";
                    
                    $violation_stmt = $pdo->prepare($violation_sql);
                    $violation_stmt->execute([
                        'violation_id' => $violation_id,
                        'driver_id' => $driver_id,
                        'operator_id' => $operator_id,
                        'vehicle_number' => $vehicle_number,
                        'violation_details' => $violation_details,
                        'evidence_type' => $evidence_type,
                        'fine_amount' => $fine_amount,
                        'check_id' => $check_id
                    ]);
                    
                    $violation_id_inserted = $existing_violation['id'];
                } else {
                    // Insert new violation
                    $violation_sql = "INSERT INTO compliance_violations 
                                     (check_id, violation_id, driver_id, operator_id, vehicle_number, 
                                      violation_details, evidence_type, fine_amount, status, 
                                      reported_by, reported_date) 
                                     VALUES 
                                     (:check_id, :violation_id, :driver_id, :operator_id, :vehicle_number, 
                                      :violation_details, :evidence_type, :fine_amount, 'Reported',
                                      :reported_by, :reported_date)";
                    
                    $violation_stmt = $pdo->prepare($violation_sql);
                    $violation_stmt->execute([
                        'check_id' => $check_id,
                        'violation_id' => $violation_id,
                        'driver_id' => $driver_id,
                        'operator_id' => $operator_id,
                        'vehicle_number' => $vehicle_number,
                        'violation_details' => $violation_details,
                        'evidence_type' => $evidence_type,
                        'fine_amount' => $fine_amount,
                        'reported_by' => $user_id,
                        'reported_date' => $check_date
                    ]);
                    
                    $violation_id_inserted = $pdo->lastInsertId();
                }
                
                // Handle file upload for evidence
                if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] == 0) {
                    handleEvidenceUpload($pdo, $violation_id_inserted, $evidence_type, $latitude, $longitude, $location, $user_id, $check_date);
                }
                
                // Also save GPS coordinates as location evidence
                if (!empty($latitude) && !empty($longitude)) {
                    saveGPSEvidence($pdo, $violation_id_inserted, $latitude, $longitude, $location, $user_id, $check_date);
                }
            } else {
                // Remove violation if unchecked
                $delete_violation_sql = "DELETE FROM compliance_violations WHERE check_id = :check_id";
                $delete_violation_stmt = $pdo->prepare($delete_violation_sql);
                $delete_violation_stmt->execute(['check_id' => $check_id]);
            }
            
            $success_message = "Compliance check updated successfully!";
            
        } catch (Exception $e) {
            $error_message = "Error updating compliance check: " . $e->getMessage();
        }
    }
}

// Helper function to handle evidence upload
function handleEvidenceUpload($pdo, $violation_id, $evidence_type, $latitude, $longitude, $location, $user_id, $check_date) {
    $upload_dir = '../uploads/violations/evidence/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $file_name = time() . '_' . basename($_FILES['evidence_file']['name']);
    $file_path = $upload_dir . $file_name;
    
    if (move_uploaded_file($_FILES['evidence_file']['tmp_name'], $file_path)) {
        $evidence_sql = "INSERT INTO violation_evidence 
                        (violation_id, evidence_type, file_name, file_path, file_size, 
                         latitude, longitude, location_name, taken_by, taken_date) 
                        VALUES 
                        (:violation_id, :evidence_type, :file_name, :file_path, :file_size,
                         :latitude, :longitude, :location_name, :taken_by, :taken_date)";
        
        $evidence_stmt = $pdo->prepare($evidence_sql);
        $evidence_stmt->execute([
            'violation_id' => $violation_id,
            'evidence_type' => $evidence_type,
            'file_name' => $file_name,
            'file_path' => '/uploads/violations/evidence/' . $file_name,
            'file_size' => $_FILES['evidence_file']['size'],
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_name' => $location,
            'taken_by' => $user_id,
            'taken_date' => $check_date
        ]);
        
        // Update violation with evidence path
        $update_sql = "UPDATE compliance_violations SET evidence_path = :evidence_path WHERE id = :id";
        $update_stmt = $pdo->prepare($update_sql);
        $update_stmt->execute([
            'evidence_path' => '/uploads/violations/evidence/' . $file_name,
            'id' => $violation_id
        ]);
    }
}

// Helper function to save GPS evidence
function saveGPSEvidence($pdo, $violation_id, $latitude, $longitude, $location, $user_id, $check_date) {
    $gps_sql = "INSERT INTO violation_evidence 
               (violation_id, evidence_type, file_name, file_path, latitude, longitude, 
                location_name, taken_by, taken_date, description) 
               VALUES 
               (:violation_id, 'Location', 'gps_coordinates.json', 'GPS Data', 
                :latitude, :longitude, :location_name, :taken_by, :taken_date, 
                'GPS coordinates recorded during violation')";
    
    $gps_stmt = $pdo->prepare($gps_sql);
    $gps_stmt->execute([
        'violation_id' => $violation_id,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'location_name' => $location,
        'taken_by' => $user_id,
        'taken_date' => $check_date
    ]);
}

// Handle AJAX request for edit data
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_edit_data') {
    $check_id = $_GET['check_id'] ?? 0;
    
    try {
        // Get compliance check details
        $check_sql = "SELECT rcc.*, b.name as barangay_name
                      FROM route_compliance_checks rcc
                      JOIN barangays b ON rcc.barangay_id = b.id
                      WHERE rcc.id = :check_id AND rcc.tanod_id = :tanod_id";
        
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute(['check_id' => $check_id, 'tanod_id' => $user_id]);
        $check = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$check) {
            echo json_encode(['success' => false, 'message' => 'Compliance check not found or access denied']);
            exit();
        }
        
        // Get violation for this check
        $violation_sql = "SELECT cv.*, rv.violation_name
                          FROM compliance_violations cv
                          LEFT JOIN route_violations rv ON cv.violation_id = rv.id
                          WHERE cv.check_id = :check_id";
        
        $violation_stmt = $pdo->prepare($violation_sql);
        $violation_stmt->execute(['check_id' => $check_id]);
        $violation = $violation_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get evidence for violation
        $evidence = [];
        if ($violation) {
            $evidence_sql = "SELECT * FROM violation_evidence WHERE violation_id = :violation_id";
            $evidence_stmt = $pdo->prepare($evidence_sql);
            $evidence_stmt->execute(['violation_id' => $violation['id']]);
            $evidence = $evidence_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Format date for datetime-local input
        $check['check_date'] = date('Y-m-d\TH:i', strtotime($check['check_date']));
        
        echo json_encode([
            'success' => true,
            'check' => $check,
            'violation' => $violation,
            'evidence' => $evidence
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit();
    }
}

// Handle AJAX request for view details
if (isset($_GET['ajax']) && $_GET['ajax'] == 'get_details') {
    $check_id = $_GET['check_id'] ?? 0;
    
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
            echo '<div class="alert alert-error">Compliance check not found</div>';
            exit();
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
        
        // Output HTML for view modal
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
        <?php
        exit();
    } catch (Exception $e) {
        echo '<div class="alert alert-error">Error loading details: ' . $e->getMessage() . '</div>';
        exit();
    }
}

// Handle deletion
if (isset($_GET['delete_id'])) {
    try {
        $delete_id = $_GET['delete_id'];
        
        // Delete the compliance check (cascade will handle related violations)
        $delete_sql = "DELETE FROM route_compliance_checks WHERE id = :id AND tanod_id = :tanod_id";
        $delete_stmt = $pdo->prepare($delete_sql);
        $delete_stmt->execute([
            'id' => $delete_id,
            'tanod_id' => $user_id
        ]);
        
        $success_message = "Compliance check deleted successfully!";
        
    } catch (Exception $e) {
        $error_message = "Error deleting compliance check: " . $e->getMessage();
    }
}

// Fetch all compliance checks for this tanod
try {
    $checks_sql = "SELECT rcc.*, b.name as barangay_name, 
                          COUNT(cv.id) as violation_count,
                          SUM(CASE WHEN cv.status = 'Verified' THEN cv.fine_amount ELSE 0 END) as total_fines
                   FROM route_compliance_checks rcc
                   JOIN barangays b ON rcc.barangay_id = b.id
                   LEFT JOIN compliance_violations cv ON rcc.id = cv.check_id
                   WHERE rcc.tanod_id = :tanod_id
                   GROUP BY rcc.id
                   ORDER BY rcc.check_date DESC";
    
    $checks_stmt = $pdo->prepare($checks_sql);
    $checks_stmt->execute(['tanod_id' => $user_id]);
    $compliance_checks = $checks_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch barangays
    $barangays_sql = "SELECT id, name FROM barangays ORDER BY name";
    $barangays_stmt = $pdo->prepare($barangays_sql);
    $barangays_stmt->execute();
    $barangays = $barangays_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch violation types
    $violations_sql = "SELECT id, violation_code, violation_name, penalty_amount 
                      FROM route_violations 
                      WHERE is_active = 1 
                      ORDER BY violation_name";
    $violations_stmt = $pdo->prepare($violations_sql);
    $violations_stmt->execute();
    $violation_types = $violations_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch drivers for dropdown
    $drivers_sql = "SELECT id, driver_code, CONCAT(first_name, ' ', last_name) as driver_name, 
                           vehicle_number, license_number 
                    FROM tricycle_drivers 
                    WHERE status = 'Active' 
                    ORDER BY last_name, first_name";
    $drivers_stmt = $pdo->prepare($drivers_sql);
    $drivers_stmt->execute();
    $drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch operators for dropdown
    $operators_sql = "SELECT id, operator_code, CONCAT(first_name, ' ', last_name) as operator_name, 
                             vehicle_number, franchise_number 
                      FROM tricycle_operators 
                      WHERE status = 'Active' 
                      ORDER BY last_name, first_name";
    $operators_stmt = $pdo->prepare($operators_sql);
    $operators_stmt->execute();
    $operators = $operators_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_sql = "SELECT 
                  COUNT(*) as total_checks,
                  SUM(CASE WHEN status = 'Non-Compliant' THEN 1 ELSE 0 END) as non_compliant,
                  SUM(CASE WHEN status = 'Warning Issued' THEN 1 ELSE 0 END) as warnings_issued,
                  SUM(CASE WHEN status = 'Fine Issued' THEN 1 ELSE 0 END) as fines_issued,
                  COUNT(DISTINCT DATE(check_date)) as days_active
                  FROM route_compliance_checks 
                  WHERE tanod_id = :tanod_id";
    
    $stats_stmt = $pdo->prepare($stats_sql);
    $stats_stmt->execute(['tanod_id' => $user_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    $error_message = "Error fetching data: " . $e->getMessage();
    $compliance_checks = [];
    $barangays = [];
    $violation_types = [];
    $drivers = [];
    $operators = [];
    $stats = [
        'total_checks' => 0,
        'non_compliant' => 0,
        'warnings_issued' => 0,
        'fines_issued' => 0,
        'days_active' => 0
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Route Compliance Monitoring - Barangay Tricycle Route Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Leaflet CSS for maps -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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
        
        /* Sidebar - Keep your existing sidebar styles */
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
        
        /* Page Content */
        .page-content {
            padding: 32px;
        }
        
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .page-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .page-subtitle {
            color: var(--text-light);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 24px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            border-radius: 16px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .stat-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        }
        
        .stat-card-primary {
            background-color: var(--secondary-color);
            color: white;
        }
        
        .stat-card-white {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
        }
        
        .stat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        
        .stat-title {
            font-size: 14px;
            font-weight: 500;
        }
        
        .stat-button {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .stat-button-primary {
            background-color: rgba(255, 255, 255, 0.2);
        }
        
        .stat-button-primary:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .stat-button-white {
            background-color: var(--background-color);
        }
        
        .stat-button-white:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .stat-button-white {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .dark-mode .stat-button-white:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        
        .stat-value {
            font-size: 48px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-info {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
        }
        
        .stat-icon {
            width: 16px;
            height: 16px;
        }
        
        /* Main Grid */
        .main-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 24px;
        }
        
        .left-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        
        /* Card Styles */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 24px;
            transition: background-color 0.3s, border-color 0.3s;
            margin-bottom: 24px;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 24px;
        }
        
        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        /* Button Styles */
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
            font-size: 14px;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .btn-danger {
            background-color: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
        }
        
        .btn-success {
            background-color: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background-color: #047857;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background-color: var(--background-color);
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: var(--text-color);
            border-bottom: 2px solid var(--border-color);
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: top;
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        .dark-mode .data-table tr:hover {
            background-color: rgba(255, 255, 255, 0.03);
        }
        
        /* Badge Styles */
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
        
        .dark-mode .badge-compliant {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
        }
        
        .badge-non-compliant {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .badge-non-compliant {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
        }
        
        .badge-warning {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .badge-warning {
            background-color: rgba(245, 158, 11, 0.2);
            color: #fcd34d;
        }
        
        .badge-fine {
            background-color: #e9d5ff;
            color: #6d28d9;
        }
        
        .dark-mode .badge-fine {
            background-color: rgba(139, 92, 246, 0.2);
            color: #c4b5fd;
        }
        
        .badge-reported {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .dark-mode .badge-reported {
            background-color: rgba(59, 130, 246, 0.2);
            color: #93c5fd;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .action-btn-view {
            background-color: var(--primary-color);
            color: white;
        }
        
        .action-btn-view:hover {
            background-color: var(--primary-dark);
        }
        
        .action-btn-edit {
            background-color: #f59e0b;
            color: white;
        }
        
        .action-btn-edit:hover {
            background-color: #d97706;
        }
        
        .action-btn-delete {
            background-color: #ef4444;
            color: white;
        }
        
        .action-btn-delete:hover {
            background-color: #dc2626;
        }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }
        
        .modal-content {
            background-color: var(--card-bg);
            margin: 5% auto;
            padding: 0;
            border-radius: 16px;
            width: 90%;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-color);
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 24px;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        /* Alert Messages */
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .dark-mode .alert-success {
            background-color: rgba(16, 185, 129, 0.2);
            color: #86efac;
            border-color: rgba(16, 185, 129, 0.3);
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        .dark-mode .alert-error {
            background-color: rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-color: rgba(239, 68, 68, 0.3);
        }
        
        .alert-icon {
            font-size: 20px;
        }
        
        /* Map Container */
        .map-container {
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }
        
        #locationMap {
            width: 100%;
            height: 100%;
        }
        
        /* Evidence Preview */
        .evidence-preview {
            margin-top: 10px;
        }
        
        .evidence-image {
            max-width: 100%;
            max-height: 200px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        /* GPS Capture Button */
        .gps-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-top: 8px;
        }
        
        .gps-button:hover {
            background-color: var(--primary-dark);
        }
        
        /* Violation Section */
        .violation-section {
            background-color: rgba(239, 68, 68, 0.05);
            padding: 20px;
            border-radius: 8px;
            margin-top: 20px;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }
        
        .violation-section-title {
            color: #ef4444;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Loading Spinner */
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 20px auto;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .form-row {
                grid-template-columns: 1fr;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .page-content {
                padding: 16px;
            }
            
            .page-header {
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
            
            .action-buttons {
                flex-direction: column;
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
                    <img src="../img/ttm.png" alt="TTM Logo" style="width: 80px; height: 80px;">
                </div>
                <span class="logo-text"> Tanod Patrol Management</span>
            </div>
            
            <!-- Menu Section -->
            <div class="menu-section">
                <p class="menu-title">BARANGAY MANAGEMENT MODULES</p>
                
                <div class="menu-items">
                    <!-- Dashboard Button (Added as first item) -->
                    <a href="../tanod_dashboard.php" class="menu-item">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>

                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('road-condition')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-condition" class="submenu">
                        <a href="../lrcr/tanod_field_report.php" class="submenu-item">Report Condition</a>
                        <a href="../lrcr/follow_up_logs.php" class="submenu-item">Follow-Up Logs</a>
                    </div>
                    
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('route-management')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon rotated" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-management" class="submenu active">
                        <a href="route_compliance.php" class="submenu-item active">Route Compliance</a>
                        <a href="incident_logging.php" class="submenu-item ">Incident Logging</a>
                 
                    </div>
                    
                    <!-- 1.3 Minor Accident & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('incident-reporting')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Minor Accident & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="incident-reporting" class="submenu">
                        <a href="../incidents/report_incident.php" class="submenu-item">Report Incident</a>
                        <a href="../incidents/my_reports.php" class="submenu-item">My Reports</a>
                        <a href="../incidents/emergency_contacts.php" class="submenu-item">Emergency Contacts</a>
                    </div>
                    
                    <!-- 1.4 Tanod Patrol Logs for Traffic -->
                    <div class="menu-item" onclick="toggleSubmenu('patrol-logs')">
                        <div class="icon-box icon-box-tanod">
                            <i class='bx bxs-notepad'></i>
                        </div>
                        <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="patrol-logs" class="submenu">
                        <a href="../patrol_logs/create_log.php" class="submenu-item">Create Patrol Log</a>
                        <a href="../patrol_logs/my_logs.php" class="submenu-item">My Patrol Logs</a>
                        <a href="../patrol_logs/shift_schedule.php" class="submenu-item">Shift Schedule</a>
                    </div>
                  
                    <!-- 1.5 Permit and Local Regulation Tracking -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-tracking')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit and Local Regulation Tracking</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-tracking" class="submenu">
                        <a href="../permits/verify_permit.php" class="submenu-item">Verify Permit</a>
                        <a href="../permits/regulation_guide.php" class="submenu-item">Regulation Guide</a>
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
                        <a href="../feedback/submit_feedback.php" class="submenu-item">Submit Feedback</a>
                        <a href="../feedback/view_feedback.php" class="submenu-item">View Feedback</a>
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
                            <input type="text" placeholder="Search compliance checks..." class="search-input" id="searchInput">
                            <kbd class="search-shortcut">📋</kbd>
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
            
            <!-- Page Content -->
            <div class="page-content">
                <!-- Page Header -->
                <div class="page-header">
                    <div>
                        <h1 class="page-title">Route Compliance Monitoring</h1>
                        <p class="page-subtitle">Monitor tricycle routes, report violations, and upload evidence</p>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="openAddModal()">
                            <i class='bx bx-plus'></i>
                            New Compliance Check
                        </button>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if (isset($success_message)): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle alert-icon'></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle alert-icon'></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Stats Cards -->
                <div class="stats-grid">
                    <div class="stat-card stat-card-primary">
                        <div class="stat-header">
                            <span class="stat-title">Total Checks</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['total_checks']; ?></div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Non-Compliant</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['non_compliant']; ?></div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Warnings Issued</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['warnings_issued']; ?></div>
                    </div>
                    
                    <div class="stat-card stat-card-white">
                        <div class="stat-header">
                            <span class="stat-title">Fines Issued</span>
                        </div>
                        <div class="stat-value"><?php echo $stats['fines_issued']; ?></div>
                    </div>
                </div>
                
                <!-- Compliance Checks Table -->
                <div class="card">
                    <h2 class="card-title">Recent Compliance Checks</h2>
                    <div class="table-container">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Check Code</th>
                                    <th>Location</th>
                                    <th>Type</th>
                                    <th>Status</th>
                                    <th>Date & Time</th>
                                    <th>Violations</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="checksTableBody">
                                <?php if (!empty($compliance_checks)): ?>
                                    <?php foreach ($compliance_checks as $check): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($check['check_code']); ?></strong><br>
                                                <small><?php echo htmlspecialchars($check['barangay_name']); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($check['location']); ?></strong><br>
                                                <?php if ($check['latitude'] && $check['longitude']): ?>
                                                    <small>📍 GPS: <?php echo $check['latitude']; ?>, <?php echo $check['longitude']; ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($check['check_type']); ?>
                                            </td>
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
                                            <td>
                                                <?php echo date('M d, Y', strtotime($check['check_date'])); ?><br>
                                                <small><?php echo date('h:i A', strtotime($check['check_date'])); ?></small>
                                            </td>
                                            <td>
                                                <strong><?php echo $check['violation_count']; ?> violations</strong><br>
                                                <?php if ($check['total_fines'] > 0): ?>
                                                    <small>₱<?php echo number_format($check['total_fines'], 2); ?> in fines</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <button class="action-btn action-btn-view" onclick="viewCheckDetails(<?php echo $check['id']; ?>)">
                                                        <i class='bx bx-show'></i> View
                                                    </button>
                                                    <button class="action-btn action-btn-edit" onclick="editCheck(<?php echo $check['id']; ?>)">
                                                        <i class='bx bx-edit'></i> Edit
                                                    </button>
                                                    <button class="action-btn action-btn-delete" onclick="confirmDelete(<?php echo $check['id']; ?>)">
                                                        <i class='bx bx-trash'></i> Delete
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="7" style="text-align: center; padding: 40px;">
                                            <i class='bx bx-clipboard' style="font-size: 48px; color: var(--text-light); margin-bottom: 16px;"></i>
                                            <p>No compliance checks found. Record your first compliance check!</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Modal -->
    <div id="complianceModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modalTitle">New Compliance Check</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" action="" id="complianceForm" enctype="multipart/form-data">
                <div class="modal-body" id="modalBody">
                    <input type="hidden" name="add_compliance_check" value="1">
                    <input type="hidden" id="check_id" name="check_id" value="">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="check_date">Check Date & Time *</label>
                            <input type="datetime-local" class="form-control" id="check_date" name="check_date" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="barangay_id">Barangay *</label>
                            <select class="form-control" id="barangay_id" name="barangay_id" required>
                                <option value="">Select barangay</option>
                                <?php foreach ($barangays as $barangay): ?>
                                    <option value="<?php echo $barangay['id']; ?>">
                                        <?php echo htmlspecialchars($barangay['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="location">Location *</label>
                        <input type="text" class="form-control" id="location" name="location" 
                               placeholder="e.g., Poblacion Market Entrance, San Isidro Crossing" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="latitude">Latitude</label>
                            <input type="number" step="any" class="form-control" id="latitude" name="latitude" 
                                   placeholder="14.686832">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="longitude">Longitude</label>
                            <input type="number" step="any" class="form-control" id="longitude" name="longitude" 
                                   placeholder="121.096623">
                        </div>
                    </div>
                    
                    <button type="button" class="gps-button" onclick="getCurrentLocation()">
                        <i class='bx bx-map'></i>
                        Get Current Location
                    </button>
                    
                    <div id="mapContainer" class="map-container" style="display: none;">
                        <div id="locationMap"></div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="check_type">Check Type *</label>
                            <select class="form-control" id="check_type" name="check_type" required>
                                <option value="Route Compliance">Route Compliance</option>
                                <option value="Terminal Compliance">Terminal Compliance</option>
                                <option value="Vehicle Inspection">Vehicle Inspection</option>
                                <option value="Driver Check">Driver Check</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label" for="status">Status *</label>
                            <select class="form-control" id="status" name="status" required>
                                <option value="Compliant">Compliant</option>
                                <option value="Non-Compliant">Non-Compliant</option>
                                <option value="Warning Issued">Warning Issued</option>
                                <option value="Fine Issued">Fine Issued</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3" 
                                  placeholder="Describe what you checked and any observations..."></textarea>
                    </div>
                    
                    <!-- Violation Section -->
                    <div class="violation-section">
                        <h4 class="violation-section-title">
                            <i class='bx bx-error-circle'></i>
                            Violation Reporting (Optional)
                        </h4>
                        
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 8px;">
                                <input type="checkbox" id="has_violation" name="has_violation" value="1" onchange="toggleViolationSection()">
                                <span style="margin-left: 8px;">Report a violation</span>
                            </label>
                        </div>
                        
                        <div id="violationFields" style="display: none;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="violation_id">Violation Type *</label>
                                    <select class="form-control" id="violation_id" name="violation_id">
                                        <option value="">Select violation type</option>
                                        <?php foreach ($violation_types as $violation): ?>
                                            <option value="<?php echo $violation['id']; ?>" data-penalty="<?php echo $violation['penalty_amount']; ?>">
                                                <?php echo htmlspecialchars($violation['violation_name']); ?> (₱<?php echo number_format($violation['penalty_amount'], 2); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="vehicle_number">Vehicle Number *</label>
                                    <input type="text" class="form-control" id="vehicle_number" name="vehicle_number" 
                                           placeholder="e.g., TRC-123">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="driver_id">Driver (Optional)</label>
                                    <select class="form-control" id="driver_id" name="driver_id">
                                        <option value="">Select driver</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>" data-vehicle="<?php echo $driver['vehicle_number']; ?>">
                                                <?php echo htmlspecialchars($driver['driver_name']); ?> (<?php echo $driver['vehicle_number']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="operator_id">Operator (Optional)</label>
                                    <select class="form-control" id="operator_id" name="operator_id">
                                        <option value="">Select operator</option>
                                        <?php foreach ($operators as $operator): ?>
                                            <option value="<?php echo $operator['id']; ?>" data-vehicle="<?php echo $operator['vehicle_number']; ?>">
                                                <?php echo htmlspecialchars($operator['operator_name']); ?> (<?php echo $operator['vehicle_number']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="violation_details">Violation Details *</label>
                                <textarea class="form-control" id="violation_details" name="violation_details" rows="3" 
                                          placeholder="Describe the violation in detail..."></textarea>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label" for="evidence_type">Evidence Type</label>
                                    <select class="form-control" id="evidence_type" name="evidence_type">
                                        <option value="Photo">Photo</option>
                                        <option value="Video">Video</option>
                                        <option value="Location">Location Data</option>
                                        <option value="Witness">Witness Statement</option>
                                        <option value="Document">Document</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label" for="fine_amount">Fine Amount (₱)</label>
                                    <input type="number" step="0.01" class="form-control" id="fine_amount" name="fine_amount" 
                                           value="0.00" min="0">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label" for="evidence_file">Upload Evidence (Photo/Video)</label>
                                <input type="file" class="form-control" id="evidence_file" name="evidence_file" 
                                       accept="image/*,video/*,.pdf,.doc,.docx">
                                <small>Max file size: 10MB. Accepted formats: Images, Videos, PDF, DOC</small>
                            </div>
                            
                            <div id="evidencePreview" class="evidence-preview"></div>
                            
                            <div class="form-group">
                                <label class="form-label">Take Photo (Mobile Only)</label>
                                <button type="button" class="gps-button" onclick="takePhoto()">
                                    <i class='bx bx-camera'></i>
                                    Take Photo
                                </button>
                                <input type="file" id="cameraInput" accept="image/*" capture="environment" style="display: none;" onchange="handleCameraPhoto(event)">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="modalSubmitBtn">Save Compliance Check</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- View Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Compliance Check Details</h3>
                <button class="modal-close" onclick="closeViewModal()">&times;</button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <!-- Details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
            </div>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Leaflet JS for maps -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
        // Global variables
        let locationMap = null;
        let locationMarker = null;
        
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
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
            }
        });
        
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
        
        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'New Compliance Check';
            document.getElementById('modalSubmitBtn').textContent = 'Save Compliance Check';
            document.getElementById('complianceForm').reset();
            document.getElementById('check_id').value = '';
            document.getElementById('check_date').value = new Date().toISOString().slice(0, 16);
            document.getElementById('complianceModal').style.display = 'block';
            
            // Set form action for adding
            document.querySelector('input[name="add_compliance_check"]').value = '1';
            document.querySelector('input[name="edit_compliance_check"]')?.remove();
            
            // Hide violation section by default
            document.getElementById('violationFields').style.display = 'none';
            document.getElementById('has_violation').checked = false;
            
            // Clear evidence preview
            document.getElementById('evidencePreview').innerHTML = '';
            
            // Hide map
            document.getElementById('mapContainer').style.display = 'none';
            
            // Clear map if exists
            if (locationMap) {
                locationMap.remove();
                locationMap = null;
                locationMarker = null;
            }
        }
        
        function closeModal() {
            document.getElementById('complianceModal').style.display = 'none';
        }
        
        function closeViewModal() {
            document.getElementById('viewModal').style.display = 'none';
        }
        
        // Edit check functionality
        async function editCheck(checkId) {
            try {
                // Show loading
                Swal.fire({
                    title: 'Loading...',
                    text: 'Fetching compliance check data',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                // Fetch check details
                const response = await fetch(`?ajax=get_edit_data&check_id=${checkId}`);
                const data = await response.json();
                
                if (data.success) {
                    Swal.close();
                    
                    // Populate form with data
                    document.getElementById('modalTitle').textContent = 'Edit Compliance Check';
                    document.getElementById('modalSubmitBtn').textContent = 'Update Compliance Check';
                    document.getElementById('check_id').value = data.check.id;
                    
                    // Set check date
                    document.getElementById('check_date').value = data.check.check_date;
                    
                    // Set other form values
                    document.getElementById('barangay_id').value = data.check.barangay_id;
                    document.getElementById('location').value = data.check.location;
                    document.getElementById('latitude').value = data.check.latitude || '';
                    document.getElementById('longitude').value = data.check.longitude || '';
                    document.getElementById('check_type').value = data.check.check_type;
                    document.getElementById('status').value = data.check.status;
                    document.getElementById('notes').value = data.check.notes || '';
                    
                    // Show map if coordinates exist
                    if (data.check.latitude && data.check.longitude) {
                        showMap(data.check.latitude, data.check.longitude);
                    }
                    
                    // Handle violation data
                    if (data.violation) {
                        document.getElementById('has_violation').checked = true;
                        toggleViolationSection();
                        
                        document.getElementById('violation_id').value = data.violation.violation_id || '';
                        document.getElementById('vehicle_number').value = data.violation.vehicle_number || '';
                        document.getElementById('driver_id').value = data.violation.driver_id || '';
                        document.getElementById('operator_id').value = data.violation.operator_id || '';
                        document.getElementById('violation_details').value = data.violation.violation_details || '';
                        document.getElementById('evidence_type').value = data.violation.evidence_type || 'Photo';
                        document.getElementById('fine_amount').value = data.violation.fine_amount || '0.00';
                        
                        // Show existing evidence if any
                        if (data.evidence && data.evidence.length > 0) {
                            let evidenceHtml = '<h5>Existing Evidence:</h5>';
                            data.evidence.forEach(ev => {
                                if (ev.evidence_type === 'Photo') {
                                    evidenceHtml += `
                                        <div style="margin-bottom: 10px;">
                                            <img src="../${ev.file_path}" alt="Evidence" class="evidence-image" style="max-width: 200px;">
                                            <p><small>${ev.file_name}</small></p>
                                        </div>
                                    `;
                                } else if (ev.evidence_type === 'Location') {
                                    evidenceHtml += `
                                        <div style="margin-bottom: 10px;">
                                            <p>📍 GPS Location</p>
                                            <small>Lat: ${ev.latitude}, Lng: ${ev.longitude}</small>
                                        </div>
                                    `;
                                }
                            });
                            document.getElementById('evidencePreview').innerHTML = evidenceHtml;
                        }
                    } else {
                        document.getElementById('has_violation').checked = false;
                        toggleViolationSection();
                        document.getElementById('evidencePreview').innerHTML = '';
                    }
                    
                    // Remove old hidden inputs and add edit input
                    const oldAddInput = document.querySelector('input[name="add_compliance_check"]');
                    if (oldAddInput) {
                        oldAddInput.remove();
                    }
                    
                    // Add edit input
                    const editInput = document.createElement('input');
                    editInput.type = 'hidden';
                    editInput.name = 'edit_compliance_check';
                    editInput.value = '1';
                    document.getElementById('modalBody').appendChild(editInput);
                    
                    // Show modal
                    document.getElementById('complianceModal').style.display = 'block';
                    
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: data.message || 'Failed to load compliance check data'
                    });
                }
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Failed to load compliance check data: ' + error.message
                });
            }
        }
        
        // Toggle violation section
        function toggleViolationSection() {
            const violationFields = document.getElementById('violationFields');
            const hasViolation = document.getElementById('has_violation').checked;
            
            violationFields.style.display = hasViolation ? 'block' : 'none';
            
            // Set required attributes
            const requiredFields = ['violation_id', 'vehicle_number', 'violation_details'];
            requiredFields.forEach(field => {
                const element = document.getElementById(field);
                if (element) {
                    element.required = hasViolation;
                }
            });
        }
        
        // Get current location using Geolocation API
        function getCurrentLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    function(position) {
                        const lat = position.coords.latitude;
                        const lng = position.coords.longitude;
                        
                        document.getElementById('latitude').value = lat.toFixed(8);
                        document.getElementById('longitude').value = lng.toFixed(8);
                        
                        // Show map
                        showMap(lat, lng);
                        
                        // Try to get location name using reverse geocoding
                        getLocationName(lat, lng);
                    },
                    function(error) {
                        Swal.fire({
                            icon: 'error',
                            title: 'Location Error',
                            text: 'Unable to get your location. Please enter coordinates manually.'
                        });
                    },
                    {
                        enableHighAccuracy: true,
                        timeout: 5000,
                        maximumAge: 0
                    }
                );
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Not Supported',
                    text: 'Geolocation is not supported by your browser.'
                });
            }
        }
        
        // Show map with location
        function showMap(lat, lng) {
            const mapContainer = document.getElementById('mapContainer');
            const mapDiv = document.getElementById('locationMap');
            
            mapContainer.style.display = 'block';
            
            // Initialize map if not already initialized
            if (!locationMap) {
                locationMap = L.map('locationMap').setView([lat, lng], 15);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '© OpenStreetMap contributors'
                }).addTo(locationMap);
            } else {
                locationMap.setView([lat, lng], 15);
            }
            
            // Clear existing markers
            if (locationMarker) {
                locationMap.removeLayer(locationMarker);
            }
            
            // Add marker
            locationMarker = L.marker([lat, lng]).addTo(locationMap)
                .bindPopup('Violation Location')
                .openPopup();
        }
        
        // Get location name from coordinates (using Nominatim)
        function getLocationName(lat, lng) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&zoom=18&addressdetails=1`)
                .then(response => response.json())
                .then(data => {
                    if (data.display_name) {
                        const locationInput = document.getElementById('location');
                        if (!locationInput.value) {
                            locationInput.value = data.display_name.split(',')[0];
                        }
                    }
                })
                .catch(error => console.error('Error getting location name:', error));
        }
        
        // Take photo using camera
        function takePhoto() {
            document.getElementById('cameraInput').click();
        }
        
        function handleCameraPhoto(event) {
            const file = event.target.files[0];
            if (file) {
                // Create preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('evidencePreview');
                    preview.innerHTML = `
                        <img src="${e.target.result}" alt="Camera photo" class="evidence-image">
                        <p>Photo captured: ${new Date().toLocaleTimeString()}</p>
                    `;
                    
                    // Create a new file input with the captured photo
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    
                    const evidenceFileInput = document.getElementById('evidence_file');
                    evidenceFileInput.files = dataTransfer.files;
                    
                    // Set evidence type to Photo
                    document.getElementById('evidence_type').value = 'Photo';
                };
                reader.readAsDataURL(file);
            }
        }
        
        // Update vehicle number when driver/operator is selected
        document.getElementById('driver_id').addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const vehicleNumber = selectedOption.getAttribute('data-vehicle');
                if (vehicleNumber) {
                    document.getElementById('vehicle_number').value = vehicleNumber;
                }
            }
        });
        
        document.getElementById('operator_id').addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const vehicleNumber = selectedOption.getAttribute('data-vehicle');
                if (vehicleNumber) {
                    document.getElementById('vehicle_number').value = vehicleNumber;
                }
            }
        });
        
        // Update fine amount when violation type is selected
        document.getElementById('violation_id').addEventListener('change', function() {
            if (this.value) {
                const selectedOption = this.options[this.selectedIndex];
                const penaltyAmount = selectedOption.getAttribute('data-penalty');
                if (penaltyAmount) {
                    document.getElementById('fine_amount').value = penaltyAmount;
                }
            }
        });
        
        // Preview evidence file
        document.getElementById('evidence_file').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('evidencePreview');
                    if (file.type.startsWith('image/')) {
                        preview.innerHTML = `<img src="${e.target.result}" alt="Evidence" class="evidence-image">`;
                    } else if (file.type.startsWith('video/')) {
                        preview.innerHTML = `
                            <video controls class="evidence-image">
                                <source src="${e.target.result}" type="${file.type}">
                                Your browser does not support the video tag.
                            </video>
                        `;
                    } else {
                        preview.innerHTML = `<p>File: ${file.name} (${(file.size / 1024).toFixed(2)} KB)</p>`;
                    }
                };
                reader.readAsDataURL(file);
            }
        });
        
        // View check details
        function viewCheckDetails(checkId) {
            fetch(`?ajax=get_details&check_id=${checkId}`)
                .then(response => response.text())
                .then(data => {
                    document.getElementById('viewModalBody').innerHTML = data;
                    document.getElementById('viewModal').style.display = 'block';
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load check details'
                    });
                });
        }
        
        // Confirm deletion with SweetAlert2
        function confirmDelete(checkId) {
            Swal.fire({
                title: 'Are you sure?',
                text: "This will delete the compliance check and any associated violations!",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = `?delete_id=${checkId}`;
                }
            });
        }
        
        // Search functionality
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('#checksTableBody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
        
        // Close modals when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('complianceModal');
            const viewModal = document.getElementById('viewModal');
            
            if (event.target == modal) {
                modal.style.display = 'none';
            }
            if (event.target == viewModal) {
                viewModal.style.display = 'none';
            }
        };
        
        // Show success message with SweetAlert if present
        <?php if (isset($success_message)): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo addslashes($success_message); ?>',
                timer: 3000,
                showConfirmButton: false
            });
        <?php endif; ?>
        
        // Show error message with SweetAlert if present
        <?php if (isset($error_message)): ?>
            Swal.fire({
                icon: 'error',
                title: 'Error!',
                text: '<?php echo addslashes($error_message); ?>'
            });
        <?php endif; ?>
        
        // Initialize form with current datetime
        document.addEventListener('DOMContentLoaded', function() {
            // Set default check date to current time
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            document.getElementById('check_date').value = now.toISOString().slice(0, 16);
        });
    </script>
</body>
</html>