<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in and is ADMIN
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ADMIN') {
    header('Location: ../login.php');
    exit();
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

// Get user data
$user_id = $_SESSION['user_id'];
$user = getUserData($pdo, $user_id);

if (!$user) {
    header('Location: ../login.php');
    exit();
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'enforcement_rules';

// Handle form submissions
$success_message = '';
$error_message = '';

// Add new violation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_violation'])) {
    try {
        $violation_code = 'VIO-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        $violation_name = $_POST['violation_name'];
        $description = $_POST['description'];
        $penalty_amount = $_POST['penalty_amount'];
        $penalty_type = $_POST['penalty_type'];
        $suspension_days = ($penalty_type == 'Suspension') ? $_POST['suspension_days'] : 0;
        $demerit_points = $_POST['demerit_points'];
        $barangay_id = $_POST['barangay_id'];
        $enforcement_priority = $_POST['enforcement_priority'];
        $applicable_to = $_POST['applicable_to'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $misuse_types = isset($_POST['misuse_types']) ? $_POST['misuse_types'] : [];
        
        $sql = "INSERT INTO route_violations (violation_code, violation_name, description, penalty_amount, penalty_type, suspension_days, demerit_points, barangay_id, created_by, enforcement_priority, applicable_to, is_active) 
                VALUES (:violation_code, :violation_name, :description, :penalty_amount, :penalty_type, :suspension_days, :demerit_points, :barangay_id, :created_by, :enforcement_priority, :applicable_to, :is_active)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'violation_code' => $violation_code,
            'violation_name' => $violation_name,
            'description' => $description,
            'penalty_amount' => $penalty_amount,
            'penalty_type' => $penalty_type,
            'suspension_days' => $suspension_days,
            'demerit_points' => $demerit_points,
            'barangay_id' => $barangay_id,
            'created_by' => $user_id,
            'enforcement_priority' => $enforcement_priority,
            'applicable_to' => $applicable_to,
            'is_active' => $is_active
        ]);
        
        $violation_id = $pdo->lastInsertId();
        
        // Save misuse types if any
        if (!empty($misuse_types)) {
            foreach ($misuse_types as $misuse_type) {
                $sql = "INSERT INTO violation_misuse_types (violation_id, misuse_type) VALUES (:violation_id, :misuse_type)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['violation_id' => $violation_id, 'misuse_type' => $misuse_type]);
            }
        }
        
        $_SESSION['success_message'] = "Violation rule added successfully!";
        
        // Log the action
        $log_sql = "INSERT INTO enforcement_logs (log_type, reference_id, action, details, acted_by) 
                    VALUES ('Violation', :ref_id, 'Violation Rule Added', :details, :acted_by)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([
            'ref_id' => $violation_id,
            'details' => "Added new violation: $violation_name",
            'acted_by' => $user_id
        ]);
        
        header('Location: enforcement_rules.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = "Error adding violation: " . $e->getMessage();
    }
}

// Apply penalty for route misuse
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['apply_penalty'])) {
    try {
        $driver_id = $_POST['driver_id'];
        $operator_id = $_POST['operator_id'];
        $vehicle_number = $_POST['vehicle_number'];
        $route_id = $_POST['route_id'];
        $violation_id = $_POST['violation_id'];
        $location = $_POST['location'];
        $barangay_id = $_POST['barangay_id'];
        $misuse_type = $_POST['misuse_type'];
        $route_misuse_details = $_POST['route_misuse_details'];
        $witness_details = $_POST['witness_details'];
        $evidence_path = $_POST['evidence_path'];
        
        // Get violation details for penalty
        $sql = "SELECT penalty_amount, penalty_type, suspension_days, demerit_points 
                FROM route_violations WHERE id = :violation_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['violation_id' => $violation_id]);
        $violation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$violation) {
            throw new Exception("Violation not found");
        }
        
        // Calculate suspension dates if applicable
        $suspension_start = null;
        $suspension_end = null;
        if ($violation['penalty_type'] == 'Suspension' && $violation['suspension_days'] > 0) {
            $suspension_start = date('Y-m-d');
            $suspension_end = date('Y-m-d', strtotime("+{$violation['suspension_days']} days"));
        }
        
        // Generate violation record code
        $violation_record_code = 'VR-' . date('Ymd') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        
        // Check if violation_records table has violation_code column
        $check_column_sql = "SHOW COLUMNS FROM violation_records LIKE 'violation_code'";
        $check_stmt = $pdo->query($check_column_sql);
        $has_violation_code_column = $check_stmt->rowCount() > 0;
        
        if ($has_violation_code_column) {
            $sql = "INSERT INTO violation_records (violation_code, violation_id, driver_id, operator_id, vehicle_number, route_id, location, barangay_id, violation_date, reported_by, witness_details, evidence_path, status, penalty_applied, suspension_start, suspension_end, demerit_points_applied, notes, misuse_type, route_misuse_details) 
                    VALUES (:violation_code, :violation_id, :driver_id, :operator_id, :vehicle_number, :route_id, :location, :barangay_id, NOW(), :reported_by, :witness_details, :evidence_path, 'Pending', :penalty_applied, :suspension_start, :suspension_end, :demerit_points_applied, :notes, :misuse_type, :route_misuse_details)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'violation_code' => $violation_record_code,
                'violation_id' => $violation_id,
                'driver_id' => $driver_id ?: null,
                'operator_id' => $operator_id ?: null,
                'vehicle_number' => $vehicle_number,
                'route_id' => $route_id,
                'location' => $location,
                'barangay_id' => $barangay_id,
                'reported_by' => $user_id,
                'witness_details' => $witness_details,
                'evidence_path' => $evidence_path,
                'penalty_applied' => $violation['penalty_amount'],
                'suspension_start' => $suspension_start,
                'suspension_end' => $suspension_end,
                'demerit_points_applied' => $violation['demerit_points'],
                'notes' => "Route misuse penalty applied: $misuse_type",
                'misuse_type' => $misuse_type,
                'route_misuse_details' => $route_misuse_details
            ]);
        } else {
            // If column doesn't exist, insert without violation_code
            $sql = "INSERT INTO violation_records (violation_id, driver_id, operator_id, vehicle_number, route_id, location, barangay_id, violation_date, reported_by, witness_details, evidence_path, status, penalty_applied, suspension_start, suspension_end, demerit_points_applied, notes, misuse_type, route_misuse_details) 
                    VALUES (:violation_id, :driver_id, :operator_id, :vehicle_number, :route_id, :location, :barangay_id, NOW(), :reported_by, :witness_details, :evidence_path, 'Pending', :penalty_applied, :suspension_start, :suspension_end, :demerit_points_applied, :notes, :misuse_type, :route_misuse_details)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                'violation_id' => $violation_id,
                'driver_id' => $driver_id ?: null,
                'operator_id' => $operator_id ?: null,
                'vehicle_number' => $vehicle_number,
                'route_id' => $route_id,
                'location' => $location,
                'barangay_id' => $barangay_id,
                'reported_by' => $user_id,
                'witness_details' => $witness_details,
                'evidence_path' => $evidence_path,
                'penalty_applied' => $violation['penalty_amount'],
                'suspension_start' => $suspension_start,
                'suspension_end' => $suspension_end,
                'demerit_points_applied' => $violation['demerit_points'],
                'notes' => "Route misuse penalty applied: $misuse_type",
                'misuse_type' => $misuse_type,
                'route_misuse_details' => $route_misuse_details
            ]);
        }
        
        $violation_record_id = $pdo->lastInsertId();
        
        // Update driver/operator status if suspended
        if ($violation['penalty_type'] == 'Suspension') {
            if ($driver_id) {
                $sql = "UPDATE tricycle_drivers SET status = 'Suspended' WHERE id = :driver_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['driver_id' => $driver_id]);
            }
            if ($operator_id) {
                $sql = "UPDATE tricycle_operators SET status = 'Suspended' WHERE id = :operator_id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['operator_id' => $operator_id]);
            }
        }
        
        if ($has_violation_code_column) {
            $_SESSION['success_message'] = "Penalty applied successfully! Violation Record #: $violation_record_code";
        } else {
            $_SESSION['success_message'] = "Penalty applied successfully! Violation Record ID: $violation_record_id";
        }
        
        // Log the action
        $log_sql = "INSERT INTO enforcement_logs (log_type, reference_id, action, details, acted_by) 
                    VALUES ('Violation', :ref_id, 'Route Misuse Penalty Applied', :details, :acted_by)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([
            'ref_id' => $violation_record_id,
            'details' => "Applied penalty for route misuse: $misuse_type",
            'acted_by' => $user_id
        ]);
        
        header('Location: enforcement_rules.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = "Error applying penalty: " . $e->getMessage();
    }
}

// Update violation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_violation'])) {
    try {
        $violation_id = $_POST['violation_id'];
        $violation_name = $_POST['violation_name'];
        $description = $_POST['description'];
        $penalty_amount = $_POST['penalty_amount'];
        $penalty_type = $_POST['penalty_type'];
        $suspension_days = ($penalty_type == 'Suspension') ? $_POST['suspension_days'] : 0;
        $demerit_points = $_POST['demerit_points'];
        $enforcement_priority = $_POST['enforcement_priority'];
        $applicable_to = $_POST['applicable_to'];
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $misuse_types = isset($_POST['misuse_types']) ? $_POST['misuse_types'] : [];
        
        $sql = "UPDATE route_violations 
                SET violation_name = :violation_name, 
                    description = :description, 
                    penalty_amount = :penalty_amount, 
                    penalty_type = :penalty_type, 
                    suspension_days = :suspension_days, 
                    demerit_points = :demerit_points,
                    enforcement_priority = :enforcement_priority,
                    applicable_to = :applicable_to,
                    is_active = :is_active,
                    updated_at = CURRENT_TIMESTAMP 
                WHERE id = :id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'violation_name' => $violation_name,
            'description' => $description,
            'penalty_amount' => $penalty_amount,
            'penalty_type' => $penalty_type,
            'suspension_days' => $suspension_days,
            'demerit_points' => $demerit_points,
            'enforcement_priority' => $enforcement_priority,
            'applicable_to' => $applicable_to,
            'is_active' => $is_active,
            'id' => $violation_id
        ]);
        
        // Update misuse types
        // First, delete existing misuse types
        $sql = "DELETE FROM violation_misuse_types WHERE violation_id = :violation_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['violation_id' => $violation_id]);
        
        // Add new misuse types
        if (!empty($misuse_types)) {
            foreach ($misuse_types as $misuse_type) {
                $sql = "INSERT INTO violation_misuse_types (violation_id, misuse_type) VALUES (:violation_id, :misuse_type)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['violation_id' => $violation_id, 'misuse_type' => $misuse_type]);
            }
        }
        
        $_SESSION['success_message'] = "Violation rule updated successfully!";
        
        // Log the action
        $log_sql = "INSERT INTO enforcement_logs (log_type, reference_id, action, details, acted_by) 
                    VALUES ('Violation', :ref_id, 'Violation Rule Updated', :details, :acted_by)";
        $log_stmt = $pdo->prepare($log_sql);
        $log_stmt->execute([
            'ref_id' => $violation_id,
            'details' => "Updated violation: $violation_name",
            'acted_by' => $user_id
        ]);
        
        header('Location: enforcement_rules.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = "Error updating violation: " . $e->getMessage();
    }
}

// Delete violation
if (isset($_GET['delete_violation'])) {
    try {
        $violation_id = $_GET['delete_violation'];
        
        // Check if violation is being used
        $check_sql = "SELECT COUNT(*) as count FROM violation_records WHERE violation_id = :violation_id";
        $check_stmt = $pdo->prepare($check_sql);
        $check_stmt->execute(['violation_id' => $violation_id]);
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $_SESSION['error_message'] = "Cannot delete violation. It is being used in violation records.";
        } else {
            // Delete misuse types first
            $sql = "DELETE FROM violation_misuse_types WHERE violation_id = :violation_id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['violation_id' => $violation_id]);
            
            // Delete violation
            $sql = "DELETE FROM route_violations WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['id' => $violation_id]);
            
            $_SESSION['success_message'] = "Violation rule deleted successfully!";
            
            // Log the action
            $log_sql = "INSERT INTO enforcement_logs (log_type, reference_id, action, details, acted_by) 
                        VALUES ('Violation', :ref_id, 'Violation Rule Deleted', :details, :acted_by)";
            $log_stmt = $pdo->prepare($log_sql);
            $log_stmt->execute([
                'ref_id' => $violation_id,
                'details' => "Deleted violation ID: $violation_id",
                'acted_by' => $user_id
            ]);
        }
        
        header('Location: enforcement_rules.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = "Error deleting violation: " . $e->getMessage();
    }
}

// Generate analytics report
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generate_report'])) {
    try {
        $report_type = $_POST['report_type'];
        $barangay_id = $_POST['barangay_id'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        
        // Calculate analytics based on date range
        $analytics = calculateEnforcementAnalytics($pdo, $barangay_id, $start_date, $end_date);
        
        // Save analytics report
        $sql = "INSERT INTO enforcement_analytics (report_type, report_date, barangay_id, total_violations, total_complaints, total_fines, total_suspensions, avg_response_time, compliance_rate, top_violation_type, top_violation_count, generated_by) 
                VALUES (:report_type, :report_date, :barangay_id, :total_violations, :total_complaints, :total_fines, :total_suspensions, :avg_response_time, :compliance_rate, :top_violation_type, :top_violation_count, :generated_by)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'report_type' => $report_type,
            'report_date' => date('Y-m-d'),
            'barangay_id' => $barangay_id,
            'total_violations' => $analytics['total_violations'],
            'total_complaints' => $analytics['total_complaints'],
            'total_fines' => $analytics['total_fines'],
            'total_suspensions' => $analytics['total_suspensions'],
            'avg_response_time' => $analytics['avg_response_time'],
            'compliance_rate' => $analytics['compliance_rate'],
            'top_violation_type' => $analytics['top_violation_type'],
            'top_violation_count' => $analytics['top_violation_count'],
            'generated_by' => $user_id
        ]);
        
        $report_id = $pdo->lastInsertId();
        $_SESSION['success_message'] = "Analytics report generated successfully! Report ID: $report_id";
        
        header('Location: enforcement_rules.php');
        exit();
        
    } catch (Exception $e) {
        $error_message = "Error generating report: " . $e->getMessage();
    }
}

// Function to calculate enforcement analytics
function calculateEnforcementAnalytics($pdo, $barangay_id, $start_date, $end_date) {
    $analytics = [
        'total_violations' => 0,
        'total_complaints' => 0,
        'total_fines' => 0,
        'total_suspensions' => 0,
        'avg_response_time' => 0,
        'compliance_rate' => 0,
        'top_violation_type' => 'None',
        'top_violation_count' => 0
    ];
    
    try {
        // Total violations in date range
        $sql = "SELECT COUNT(*) as count FROM violation_records vr 
                LEFT JOIN route_violations rv ON vr.violation_id = rv.id 
                WHERE vr.barangay_id = :barangay_id 
                AND DATE(vr.violation_date) BETWEEN :start_date AND :end_date
                AND vr.status IN ('Verified', 'Resolved')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $analytics['total_violations'] = $result['count'] ?? 0;
        
        // Total complaints in date range
        $sql = "SELECT COUNT(*) as count FROM route_complaints 
                WHERE barangay_id = :barangay_id 
                AND DATE(complaint_date) BETWEEN :start_date AND :end_date
                AND status IN ('Resolved', 'Under Investigation')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $analytics['total_complaints'] = $result['count'] ?? 0;
        
        // Total fines collected
        $sql = "SELECT SUM(vr.penalty_applied) as total FROM violation_records vr 
                LEFT JOIN route_violations rv ON vr.violation_id = rv.id 
                WHERE vr.barangay_id = :barangay_id 
                AND DATE(vr.violation_date) BETWEEN :start_date AND :end_date
                AND vr.status = 'Resolved'
                AND rv.penalty_type = 'Fine'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $analytics['total_fines'] = $result['total'] ?: 0;
        
        // Total suspensions issued
        $sql = "SELECT COUNT(*) as count FROM violation_records vr 
                LEFT JOIN route_violations rv ON vr.violation_id = rv.id 
                WHERE vr.barangay_id = :barangay_id 
                AND DATE(vr.violation_date) BETWEEN :start_date AND :end_date
                AND vr.status = 'Resolved'
                AND rv.penalty_type = 'Suspension'";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $analytics['total_suspensions'] = $result['count'] ?? 0;
        
        // Average response time (complaints resolved within 7 days)
        $sql = "SELECT AVG(DATEDIFF(resolved_date, complaint_date)) as avg_days 
                FROM route_complaints 
                WHERE barangay_id = :barangay_id 
                AND DATE(complaint_date) BETWEEN :start_date AND :end_date
                AND status = 'Resolved'
                AND resolved_date IS NOT NULL";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $analytics['avg_response_time'] = round($result['avg_days'] ?: 0, 2);
        
        // Compliance rate (violations without repeat offenders)
        $sql = "SELECT COUNT(DISTINCT driver_id) as unique_offenders,
                       COUNT(*) as total_violations
                FROM violation_records 
                WHERE barangay_id = :barangay_id 
                AND DATE(violation_date) BETWEEN :start_date AND :end_date
                AND status IN ('Verified', 'Resolved')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $unique_offenders = $result['unique_offenders'] ?? 0;
        $total_violations = $result['total_violations'] ?? 0;
        
        if ($total_violations > 0) {
            $repeat_offenders = $total_violations - $unique_offenders;
            $compliance_rate = (($total_violations - $repeat_offenders) / $total_violations) * 100;
            $analytics['compliance_rate'] = round($compliance_rate, 2);
        }
        
        // Top violation type
        $sql = "SELECT rv.violation_name, COUNT(*) as count 
                FROM violation_records vr 
                LEFT JOIN route_violations rv ON vr.violation_id = rv.id 
                WHERE vr.barangay_id = :barangay_id 
                AND DATE(vr.violation_date) BETWEEN :start_date AND :end_date
                GROUP BY vr.violation_id 
                ORDER BY count DESC 
                LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['barangay_id' => $barangay_id, 'start_date' => $start_date, 'end_date' => $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result) {
            $analytics['top_violation_type'] = $result['violation_name'];
            $analytics['top_violation_count'] = $result['count'];
        }
        
    } catch (Exception $e) {
        // Log error but continue with default values
        error_log("Error calculating analytics: " . $e->getMessage());
    }
    
    return $analytics;
}

// Fetch all violations
$violations = [];
try {
    $sql = "SELECT v.*, b.name as barangay_name, u.first_name as created_by_name 
            FROM route_violations v 
            LEFT JOIN barangays b ON v.barangay_id = b.id 
            LEFT JOIN users u ON v.created_by = u.id 
            ORDER BY v.enforcement_priority DESC, v.created_at DESC";
    $stmt = $pdo->query($sql);
    $violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error fetching violations: " . $e->getMessage();
}

// Fetch barangays for dropdown
$barangays = [];
try {
    $sql = "SELECT * FROM barangays ORDER BY name";
    $stmt = $pdo->query($sql);
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error_message = "Error fetching barangays: " . $e->getMessage();
}

// Fetch routes for dropdown
$routes = [];
try {
    $sql = "SELECT * FROM tricycle_routes WHERE status = 'Active' ORDER BY route_name";
    $stmt = $pdo->query($sql);
    $routes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without routes
}

// Fetch drivers for dropdown
$drivers = [];
try {
    $sql = "SELECT id, driver_code, first_name, last_name, vehicle_number 
            FROM tricycle_drivers 
            WHERE status = 'Active' 
            ORDER BY last_name, first_name";
    $stmt = $pdo->query($sql);
    $drivers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without drivers
}

// Fetch operators for dropdown
$operators = [];
try {
    $sql = "SELECT id, operator_code, first_name, last_name, vehicle_number 
            FROM tricycle_operators 
            WHERE status = 'Active' 
            ORDER BY last_name, first_name";
    $stmt = $pdo->query($sql);
    $operators = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without operators
}

// Fetch recent complaints
$recent_complaints = [];
try {
    $sql = "SELECT rc.*, r.route_name, b.name as barangay_name, 
                   CONCAT(td.first_name, ' ', td.last_name) as driver_name,
                   u.first_name as reported_by_name
            FROM route_complaints rc
            LEFT JOIN tricycle_routes r ON rc.route_id = r.id
            LEFT JOIN barangays b ON rc.barangay_id = b.id
            LEFT JOIN tricycle_drivers td ON rc.driver_id = td.id
            LEFT JOIN users u ON rc.created_by = u.id
            ORDER BY rc.complaint_date DESC 
            LIMIT 10";
    $stmt = $pdo->query($sql);
    $recent_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without complaints
}

// Fetch recent violations
$recent_violations = [];
try {
    $sql = "SELECT vr.*, rv.violation_name, r.route_name, 
                   CONCAT(td.first_name, ' ', td.last_name) as driver_name,
                   u.first_name as reported_by_name,
                   vr.vehicle_number
            FROM violation_records vr
            LEFT JOIN route_violations rv ON vr.violation_id = rv.id
            LEFT JOIN tricycle_routes r ON vr.route_id = r.id
            LEFT JOIN tricycle_drivers td ON vr.driver_id = td.id
            LEFT JOIN users u ON vr.reported_by = u.id
            ORDER BY vr.violation_date DESC 
            LIMIT 10";
    $stmt = $pdo->query($sql);
    $recent_violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without violations
}

// Fetch route usage statistics
$route_usage = [];
try {
    $sql = "SELECT r.route_name, 
                   COUNT(DISTINCT rul.id) as total_trips,
                   SUM(rul.passenger_count) as total_passengers,
                   SUM(rul.revenue) as total_revenue,
                   COUNT(DISTINCT rul.driver_id) as active_drivers
            FROM route_usage_logs rul
            LEFT JOIN tricycle_routes r ON rul.route_id = r.id
            WHERE rul.start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY rul.route_id
            ORDER BY total_trips DESC 
            LIMIT 5";
    $stmt = $pdo->query($sql);
    $route_usage = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without route usage data
}

// Fetch recent analytics reports
$recent_reports = [];
try {
    $sql = "SELECT ea.*, b.name as barangay_name, u.first_name as generated_by_name 
            FROM enforcement_analytics ea 
            LEFT JOIN barangays b ON ea.barangay_id = b.id 
            LEFT JOIN users u ON ea.generated_by = u.id 
            ORDER BY ea.created_at DESC 
            LIMIT 5";
    $stmt = $pdo->query($sql);
    $recent_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without reports if table doesn't exist yet
}

// Fetch violation statistics
$violation_stats = [
    'total' => 0,
    'active' => 0,
    'fines' => 0,
    'suspensions' => 0,
    'compliance_rate' => 0,
    'pending_complaints' => 0,
    'route_misuse_cases' => 0
];

try {
    // Total violations defined
    $sql = "SELECT COUNT(*) as count FROM route_violations";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['total'] = $result['count'] ?? 0;
    
    // Active violations (records)
    $sql = "SELECT COUNT(DISTINCT violation_id) as count FROM violation_records WHERE status = 'Verified'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['active'] = $result['count'] ?? 0;
    
    // Total fines amount
    $sql = "SELECT SUM(penalty_amount) as total FROM route_violations WHERE penalty_type = 'Fine'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['fines'] = $result['total'] ?: 0;
    
    // Suspensions count
    $sql = "SELECT COUNT(*) as count FROM route_violations WHERE penalty_type = 'Suspension' AND suspension_days > 0";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['suspensions'] = $result['count'] ?? 0;
    
    // Pending complaints
    $sql = "SELECT COUNT(*) as count FROM route_complaints WHERE status = 'Pending'";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['pending_complaints'] = $result['count'] ?? 0;
    
    // Route misuse cases
    $sql = "SELECT COUNT(*) as count FROM violation_records WHERE misuse_type IS NOT NULL";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $violation_stats['route_misuse_cases'] = $result['count'] ?? 0;
    
    // Calculate compliance rate
    $sql = "SELECT COUNT(DISTINCT driver_id) as unique_offenders,
                   COUNT(*) as total_violations
            FROM violation_records 
            WHERE status IN ('Verified', 'Resolved')";
    $stmt = $pdo->query($sql);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result && $result['total_violations'] > 0) {
        $unique_offenders = $result['unique_offenders'];
        $total_violations = $result['total_violations'];
        $repeat_offenders = $total_violations - $unique_offenders;
        $compliance_rate = (($total_violations - $repeat_offenders) / $total_violations) * 100;
        $violation_stats['compliance_rate'] = round($compliance_rate, 2);
    }
    
} catch (Exception $e) {
    // Use default values if query fails
}

// Get top violation types
$top_violations = [];
try {
    $sql = "SELECT rv.violation_name, COUNT(vr.id) as count, 
                   SUM(vr.penalty_applied) as total_fines,
                   AVG(rv.demerit_points) as avg_demerit
            FROM violation_records vr 
            LEFT JOIN route_violations rv ON vr.violation_id = rv.id 
            WHERE vr.status IN ('Verified', 'Resolved')
            GROUP BY vr.violation_id 
            ORDER BY count DESC 
            LIMIT 5";
    $stmt = $pdo->query($sql);
    $top_violations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Continue without top violations
}

// Misuse types for checkboxes
$misuse_types = [
    'Route Deviation',
    'Unauthorized Route', 
    'Overloading',
    'Speed Violation',
    'Time Violation',
    'Other'
];

// Check for session messages
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enforcement Rules & Analytics - Traffic & Transport Management</title>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- Chart.js for analytics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        
        /* Tabs */
        .tab-container {
            display: flex;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            color: var(--text-light);
            font-weight: 500;
            cursor: pointer;
            font-size: 14px;
            white-space: nowrap;
        }
        
        .tab.active {
            color: var(--primary-color);
            border-bottom-color: var(--primary-color);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Cards and Tables */
        .card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .data-table th {
            background-color: var(--background-color);
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid var(--border-color);
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table tr:hover {
            background-color: rgba(0, 0, 0, 0.03);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
        }
        
        .stat-card-primary {
            background-color: var(--primary-color);
            color: white;
            border: none;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--text-light);
        }
        
        .stat-card-primary .stat-label {
            color: rgba(255, 255, 255, 0.9);
        }
        
        /* Buttons */
        .primary-button {
            background-color: var(--primary-color);
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.2s;
        }
        
        .primary-button:hover {
            background-color: var(--primary-dark);
        }
        
        .secondary-button {
            background-color: var(--card-bg);
            color: var(--text-color);
            padding: 10px 20px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.2s;
        }
        
        .secondary-button:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .secondary-button:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
            border-radius: 6px;
        }
        
        .btn-view {
            background-color: #10b981;
            color: white;
            border: none;
        }
        
        .btn-edit {
            background-color: #3b82f6;
            color: white;
            border: none;
        }
        
        .btn-delete {
            background-color: #ef4444;
            color: white;
            border: none;
        }
        
        .btn-view:hover, .btn-edit:hover, .btn-delete:hover {
            opacity: 0.9;
        }
        
        /* Badges */
        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
        }
        
        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-resolved {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        .badge-investigation {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-high {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .badge-medium {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .badge-low {
            background-color: #d1fae5;
            color: #065f46;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            margin: 50px auto;
            padding: 30px;
            border-radius: 12px;
            max-width: 800px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-light);
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--card-bg);
            color: var(--text-color);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }
        
        /* Checkbox group */
        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 10px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .action-card {
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        .action-card:hover {
            transform: translateY(-2px);
        }
        
        .action-icon {
            font-size: 32px;
            margin-bottom: 10px;
            color: var(--primary-color);
        }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #d1fae5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        
        .alert-error {
            background-color: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .main-grid {
                grid-template-columns: 1fr;
            }
            
            .two-column-grid {
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
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .tab {
                padding: 10px 15px;
                font-size: 13px;
            }
            
            .modal-content {
                margin: 20px;
                padding: 20px;
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
                    <!-- Dashboard Button (Added as first item) -->
                    <a href="../admin_dashboard.php" class="menu-item <?php echo $current_page == 'dashboard' ? 'active' : ''; ?>">
                        <div class="icon-box icon-box-dashboard">
                            <i class='bx bxs-dashboard'></i>
                        </div>
                        <span class="font-medium">Dashboard</span>
                    </a>
                    
                    <!-- 1.1 Local Road Condition Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('route-setup')">
                        <div class="icon-box icon-box-road-condition">
                            <i class='bx bxs-error'></i>
                        </div>
                        <span class="font-medium">Local Road Condition Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="route-setup" class="submenu">
                        <a href="../lrcr/admin_road_condition.php" class="submenu-item">Road Condition Reports</a>
                        <a href="../lrcr/action_ass.php" class="submenu-item">Action Assignment</a>
                        <a href="../lrcr/admin_road_condition_analytics.php" class="submenu-item">Historical Records & Analytics</a>
                    </div>
                    
                    <!-- 1.2 Barangay Tricycle Route Management -->
                    <div class="menu-item active" onclick="toggleSubmenu('road-monitoring')">
                        <div class="icon-box icon-box-route-config">
                            <i class='bx bxs-map-alt'></i>
                        </div>
                        <span class="font-medium">Barangay Tricycle Route Management</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="road-monitoring" class="submenu">
                        <a href="tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="approval_enforcement.php" class="submenu-item active">Enforcement Rules & Analytics</a>
                    </div>
                    
                    <!-- 1.3 Minor Accident & Incident Reporting -->
                    <div class="menu-item" onclick="toggleSubmenu('patrol-oversight')">
                        <div class="icon-box icon-box-incident">
                            <i class='bx bxs-error-alt'></i>
                        </div>
                        <span class="font-medium">Minor Accident & Incident Reporting</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="patrol-oversight" class="submenu">
                        <a href="../incident_reporting/patrol_maps.php" class="submenu-item">Patrol Maps</a>
                        <a href="../incident_reporting/tanod_shift.php" class="submenu-item">Tanod Shift</a>
                        <a href="../incident_reporting/patrol_efficiency.php" class="submenu-item">Patrol Efficiency</a>
                    </div>
                    
                    <!-- 1.4 Tanod Patrol Logs for Traffic -->
                    <div class="menu-item" onclick="toggleSubmenu('permit-control')">
                        <div class="icon-box icon-box-tanod">
                            <i class='bx bxs-notepad'></i>
                        </div>
                        <span class="font-medium">Tanod Patrol Logs for Traffic</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="permit-control" class="submenu">
                        <a href="../patrol_logs/transport_permits.php" class="submenu-item">Transport Permits</a>
                        <a href="../patrol_logs/regulatory_updates.php" class="submenu-item">Regulatory Updates</a>
                        <a href="../patrol_logs/compliance_verification.php" class="submenu-item">Compliance Verification</a>
                    </div>
                    
                    <!-- 1.5 Permit and Local Regulation Tracking -->
                    <div class="menu-item" onclick="toggleSubmenu('feedback-center')">
                        <div class="icon-box icon-box-permit">
                            <i class='bx bxs-id-card'></i>
                        </div>
                        <span class="font-medium">Permit and Local Regulation Tracking</span>
                        <svg class="dropdown-arrow menu-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>
                    <div id="feedback-center" class="submenu">
                        <a href="../permit_tracking/review_feedback.php" class="submenu-item">Review Feedback</a>
                        <a href="../permit_tracking/escalate_issues.php" class="submenu-item">Escalate Issues</a>
                        <a href="../permit_tracking/generate_reports.php" class="submenu-item">Generate Reports</a>
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
                        <a href="../community_feedback/review_feedback.php" class="submenu-item">Review Feedback</a>
                        <a href="../community_feedback/escalate_issues.php" class="submenu-item">Escalate Issues</a>
                        <a href="../community_feedback/generate_reports.php" class="submenu-item">Generate Reports</a>
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
                            <input type="text" placeholder="Search violations or penalties" class="search-input">
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
                        <h1 class="dashboard-title">Enforcement Rules & Analytics</h1>
                        <p class="dashboard-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Manage violation rules, apply penalties, and generate enforcement reports.</p>
                    </div>
                </div>
                
                <!-- Alert Messages -->
                <?php if ($success_message): ?>
                    <div class="alert alert-success">
                        <i class='bx bx-check-circle'></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-error">
                        <i class='bx bx-error-circle'></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <!-- Tabs -->
                <div class="tab-container">
                    <button class="tab active" onclick="switchTab('dashboard')">Dashboard</button>
                    <button class="tab" onclick="switchTab('violations')">Violation Rules</button>
                    <button class="tab" onclick="switchTab('penalties')">Apply Penalties</button>
                    <button class="tab" onclick="switchTab('complaints')">View Complaints</button>
                    <button class="tab" onclick="switchTab('violation-records')">Violation Records</button>
                    <button class="tab" onclick="switchTab('route-usage')">Route Usage</button>
                    <button class="tab" onclick="switchTab('analytics')">Analytics</button>
                </div>
                
                <!-- Dashboard Tab -->
                <div id="dashboard-tab" class="tab-content active">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <div class="action-card" onclick="openAddModal()">
                            <div class="action-icon">
                                <i class='bx bx-plus-circle'></i>
                            </div>
                            <h3>Add Violation Rule</h3>
                            <p>Create new violation rules and penalties</p>
                        </div>
                        
                        <div class="action-card" onclick="switchTab('penalties')">
                            <div class="action-icon">
                                <i class='bx bx-gavel'></i>
                            </div>
                            <h3>Apply Penalty</h3>
                            <p>Issue penalties for route misuse</p>
                        </div>
                        
                        <div class="action-card" onclick="switchTab('complaints')">
                            <div class="action-icon">
                                <i class='bx bx-message-error'></i>
                            </div>
                            <h3>View Complaints</h3>
                            <p>Review passenger complaints</p>
                        </div>
                        
                        <div class="action-card" onclick="switchTab('route-usage')">
                            <div class="action-icon">
                                <i class='bx bx-trending-up'></i>
                            </div>
                            <h3>Route Usage</h3>
                            <p>Monitor route performance</p>
                        </div>
                    </div>
                    
                    <!-- Stats Grid -->
                    <div class="stats-grid">
                        <div class="stat-card stat-card-primary">
                            <div class="stat-label">Total Violation Rules</div>
                            <div class="stat-value"><?php echo $violation_stats['total']; ?></div>
                            <div class="stat-info">Rules defined in system</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Active Violation Cases</div>
                            <div class="stat-value"><?php echo $violation_stats['active']; ?></div>
                            <div class="stat-info">Currently being enforced</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Pending Complaints</div>
                            <div class="stat-value"><?php echo $violation_stats['pending_complaints']; ?></div>
                            <div class="stat-info">Awaiting investigation</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Route Misuse Cases</div>
                            <div class="stat-value"><?php echo $violation_stats['route_misuse_cases']; ?></div>
                            <div class="stat-info">Route violation incidents</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Total Fines</div>
                            <div class="stat-value">₱<?php echo number_format($violation_stats['fines'], 2); ?></div>
                            <div class="stat-info">Penalty amounts defined</div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-label">Compliance Rate</div>
                            <div class="stat-value"><?php echo $violation_stats['compliance_rate']; ?>%</div>
                            <div class="stat-info">Driver compliance level</div>
                        </div>
                    </div>
                    
                    <!-- Recent Violations -->
                    <div class="card">
                        <div class="card-title">
                            <span>Recent Violation Records</span>
                            <button class="secondary-button btn-sm" onclick="switchTab('violation-records')">View All</button>
                        </div>
                        <?php if (!empty($recent_violations)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Violation Code</th>
                                        <th>Violation</th>
                                        <th>Driver/Vehicle</th>
                                        <th>Route</th>
                                        <th>Date</th>
                                        <th>Penalty</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_violations as $violation): ?>
                                    <tr>
                                        <td>VR-<?php echo str_pad($violation['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo htmlspecialchars($violation['violation_name']); ?></td>
                                        <td>
                                            <?php 
                                            if ($violation['driver_name']) {
                                                echo htmlspecialchars($violation['driver_name']);
                                            } else {
                                                echo htmlspecialchars($violation['vehicle_number']);
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($violation['route_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($violation['violation_date'])); ?></td>
                                        <td>₱<?php echo number_format($violation['penalty_applied'], 2); ?></td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-pending';
                                            if ($violation['status'] == 'Resolved') $badge_class = 'badge-resolved';
                                            if ($violation['status'] == 'Verified') $badge_class = 'badge-investigation';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($violation['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No recent violation records found.</p>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Recent Complaints -->
                    <div class="card">
                        <div class="card-title">
                            <span>Recent Complaints</span>
                            <button class="secondary-button btn-sm" onclick="switchTab('complaints')">View All</button>
                        </div>
                        <?php if (!empty($recent_complaints)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Complaint Code</th>
                                        <th>Type</th>
                                        <th>Route</th>
                                        <th>Complainant</th>
                                        <th>Date</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_complaints as $complaint): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($complaint['complaint_code']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['complaint_type']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['route_name']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['complainant_name']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?></td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-low';
                                            if ($complaint['priority'] == 'High') $badge_class = 'badge-high';
                                            if ($complaint['priority'] == 'Medium') $badge_class = 'badge-medium';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($complaint['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php 
                                            $badge_class = 'badge-pending';
                                            if ($complaint['status'] == 'Resolved') $badge_class = 'badge-resolved';
                                            if ($complaint['status'] == 'Under Investigation') $badge_class = 'badge-investigation';
                                            ?>
                                            <span class="badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($complaint['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No recent complaints found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Violation Rules Tab -->
                <div id="violations-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Violation Rules Management</span>
                            <button class="primary-button" onclick="openAddModal()">
                                <i class='bx bx-plus'></i>
                                Add New Rule
                            </button>
                        </div>
                        
                        <?php if (!empty($violations)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Code</th>
                                        <th>Violation Name</th>
                                        <th>Description</th>
                                        <th>Penalty Type</th>
                                        <th>Amount</th>
                                        <th>Demerit Points</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($violations as $violation): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($violation['violation_code']); ?></td>
                                        <td><?php echo htmlspecialchars($violation['violation_name']); ?></td>
                                        <td><?php echo htmlspecialchars(substr($violation['description'], 0, 50)) . (strlen($violation['description']) > 50 ? '...' : ''); ?></td>
                                        <td>
                                            <span class="badge <?php echo $violation['penalty_type'] == 'Fine' ? 'badge-medium' : 'badge-high'; ?>">
                                                <?php echo htmlspecialchars($violation['penalty_type']); ?>
                                            </span>
                                        </td>
                                        <td>₱<?php echo number_format($violation['penalty_amount'], 2); ?></td>
                                        <td><?php echo $violation['demerit_points']; ?> pts</td>
                                        <td>
                                            <span class="badge <?php echo $violation['enforcement_priority'] == 'High' ? 'badge-high' : ($violation['enforcement_priority'] == 'Medium' ? 'badge-medium' : 'badge-low'); ?>">
                                                <?php echo htmlspecialchars($violation['enforcement_priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $violation['is_active'] ? 'badge-resolved' : 'badge-pending'; ?>">
                                                <?php echo $violation['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn-edit btn-sm" onclick="editViolation(<?php echo $violation['id']; ?>)">
                                                <i class='bx bx-edit'></i>
                                            </button>
                                            <button class="btn-delete btn-sm" onclick="deleteViolation(<?php echo $violation['id']; ?>, '<?php echo htmlspecialchars($violation['violation_name']); ?>')">
                                                <i class='bx bx-trash'></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No violation rules found. Click "Add New Rule" to create one.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Apply Penalties Tab -->
                <div id="penalties-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Apply Penalty for Route Misuse</span>
                        </div>
                        
                        <form method="POST" id="penaltyForm" onsubmit="return validatePenaltyForm()">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Violation Type *</label>
                                    <select class="form-control" name="violation_id" required id="violationSelect">
                                        <option value="">Select Violation</option>
                                        <?php foreach ($violations as $violation): ?>
                                            <?php if ($violation['is_active']): ?>
                                            <option value="<?php echo $violation['id']; ?>" data-penalty-type="<?php echo $violation['penalty_type']; ?>" data-penalty-amount="<?php echo $violation['penalty_amount']; ?>">
                                                <?php echo htmlspecialchars($violation['violation_name']); ?> 
                                                (₱<?php echo number_format($violation['penalty_amount'], 2); ?>)
                                            </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Misuse Type *</label>
                                    <select class="form-control" name="misuse_type" required>
                                        <option value="">Select Misuse Type</option>
                                        <option value="Route Deviation">Route Deviation</option>
                                        <option value="Unauthorized Route">Unauthorized Route</option>
                                        <option value="Overloading">Overloading</option>
                                        <option value="Speed Violation">Speed Violation</option>
                                        <option value="Time Violation">Time Violation</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Route *</label>
                                    <select class="form-control" name="route_id" required>
                                        <option value="">Select Route</option>
                                        <?php foreach ($routes as $route): ?>
                                            <option value="<?php echo $route['id']; ?>">
                                                <?php echo htmlspecialchars($route['route_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Driver</label>
                                    <select class="form-control" name="driver_id">
                                        <option value="">Select Driver</option>
                                        <?php foreach ($drivers as $driver): ?>
                                            <option value="<?php echo $driver['id']; ?>">
                                                <?php echo htmlspecialchars($driver['first_name'] . ' ' . $driver['last_name']); ?>
                                                (<?php echo htmlspecialchars($driver['vehicle_number']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Operator</label>
                                    <select class="form-control" name="operator_id">
                                        <option value="">Select Operator</option>
                                        <?php foreach ($operators as $operator): ?>
                                            <option value="<?php echo $operator['id']; ?>">
                                                <?php echo htmlspecialchars($operator['first_name'] . ' ' . $operator['last_name']); ?>
                                                (<?php echo htmlspecialchars($operator['vehicle_number']); ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Vehicle Number</label>
                                    <input type="text" class="form-control" name="vehicle_number" placeholder="Enter vehicle number">
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Location *</label>
                                    <input type="text" class="form-control" name="location" required placeholder="Enter violation location">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Barangay *</label>
                                    <select class="form-control" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Route Misuse Details *</label>
                                <textarea class="form-control" name="route_misuse_details" rows="3" required placeholder="Describe the route misuse incident"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Witness Details</label>
                                <textarea class="form-control" name="witness_details" rows="2" placeholder="Witness information (if any)"></textarea>
                            </div>
                            
                            <div class="form-group">
                                <label class="form-label">Evidence Path</label>
                                <input type="text" class="form-control" name="evidence_path" placeholder="Path to evidence files/images">
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" name="apply_penalty" class="primary-button">
                                    <i class='bx bx-gavel'></i>
                                    Apply Penalty
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Complaints Tab -->
                <div id="complaints-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Route Complaints</span>
                        </div>
                        
                        <?php if (!empty($recent_complaints)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Complaint Code</th>
                                        <th>Date</th>
                                        <th>Complainant</th>
                                        <th>Route</th>
                                        <th>Type</th>
                                        <th>Location</th>
                                        <th>Priority</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_complaints as $complaint): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($complaint['complaint_code']); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($complaint['complaint_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['complainant_name']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['route_name']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['complaint_type']); ?></td>
                                        <td><?php echo htmlspecialchars($complaint['location']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $complaint['priority'] == 'High' ? 'badge-high' : ($complaint['priority'] == 'Medium' ? 'badge-medium' : 'badge-low'); ?>">
                                                <?php echo htmlspecialchars($complaint['priority']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $complaint['status'] == 'Resolved' ? 'badge-resolved' : ($complaint['status'] == 'Under Investigation' ? 'badge-investigation' : 'badge-pending'); ?>">
                                                <?php echo htmlspecialchars($complaint['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn-view btn-sm" onclick="viewComplaint(<?php echo $complaint['id']; ?>)">
                                                <i class='bx bx-show'></i>
                                            </button>
                                            <?php if ($complaint['status'] == 'Pending'): ?>
                                            <button class="btn-edit btn-sm" onclick="assignComplaint(<?php echo $complaint['id']; ?>)">
                                                <i class='bx bx-user-check'></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No complaints found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Violation Records Tab -->
                <div id="violation-records-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Violation Records</span>
                        </div>
                        
                        <?php if (!empty($recent_violations)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Violation Code</th>
                                        <th>Date</th>
                                        <th>Violation</th>
                                        <th>Driver/Vehicle</th>
                                        <th>Route</th>
                                        <th>Misuse Type</th>
                                        <th>Penalty</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_violations as $violation): ?>
                                    <tr>
                                        <td>VR-<?php echo str_pad($violation['id'], 6, '0', STR_PAD_LEFT); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($violation['violation_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($violation['violation_name']); ?></td>
                                        <td>
                                            <?php 
                                            if ($violation['driver_name']) {
                                                echo htmlspecialchars($violation['driver_name']);
                                            } else {
                                                echo htmlspecialchars($violation['vehicle_number']);
                                            }
                                            ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($violation['route_name']); ?></td>
                                    <td>
                                        <?php 
                                        // Check if misuse_type exists in the violation_records table
                                        if (isset($violation['misuse_type']) && $violation['misuse_type']): 
                                        ?>
                                            <span class="badge badge-high">
                                                <?php echo htmlspecialchars($violation['misuse_type']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-medium">General</span>
                                        <?php endif; ?>
                                    </td>
                                        <td>₱<?php echo number_format($violation['penalty_applied'], 2); ?></td>
                                        <td>
                                            <span class="badge <?php echo $violation['status'] == 'Resolved' ? 'badge-resolved' : ($violation['status'] == 'Verified' ? 'badge-investigation' : 'badge-pending'); ?>">
                                                <?php echo htmlspecialchars($violation['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn-view btn-sm" onclick="viewViolationRecord(<?php echo $violation['id']; ?>)">
                                                <i class='bx bx-show'></i>
                                            </button>
                                            <?php if ($violation['status'] == 'Pending'): ?>
                                            <button class="btn-edit btn-sm" onclick="verifyViolation(<?php echo $violation['id']; ?>)">
                                                <i class='bx bx-check'></i>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No violation records found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Route Usage Tab -->
                <div id="route-usage-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Route Usage Statistics (Last 7 Days)</span>
                        </div>
                        
                        <?php if (!empty($route_usage)): ?>
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Route</th>
                                        <th>Total Trips</th>
                                        <th>Total Passengers</th>
                                        <th>Total Revenue</th>
                                        <th>Active Drivers</th>
                                        <th>Avg. Passengers/Trip</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($route_usage as $usage): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($usage['route_name']); ?></td>
                                        <td><?php echo $usage['total_trips']; ?></td>
                                        <td><?php echo $usage['total_passengers']; ?></td>
                                        <td>₱<?php echo number_format($usage['total_revenue'], 2); ?></td>
                                        <td><?php echo $usage['active_drivers']; ?></td>
                                        <td>
                                            <?php 
                                            $avg_passengers = $usage['total_trips'] > 0 ? $usage['total_passengers'] / $usage['total_trips'] : 0;
                                            echo number_format($avg_passengers, 1);
                                            ?>
                                        </td>
                                        <td>
                                            <button class="btn-view btn-sm" onclick="viewRouteDetails('<?php echo htmlspecialchars($usage['route_name']); ?>')">
                                                <i class='bx bx-detail'></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <p>No route usage data available for the last 7 days.</p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Analytics Tab -->
                <div id="analytics-tab" class="tab-content">
                    <div class="card">
                        <div class="card-title">
                            <span>Generate Analytics Report</span>
                        </div>
                        
                        <form method="POST" id="reportForm" onsubmit="return validateReportForm()">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Report Type *</label>
                                    <select class="form-control" name="report_type" required>
                                        <option value="Daily">Daily Report</option>
                                        <option value="Weekly">Weekly Report</option>
                                        <option value="Monthly">Monthly Report</option>
                                        <option value="Quarterly">Quarterly Report</option>
                                        <option value="Yearly">Yearly Report</option>
                                    </select>
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">Barangay *</label>
                                    <select class="form-control" name="barangay_id" required>
                                        <option value="">Select Barangay</option>
                                        <?php foreach ($barangays as $barangay): ?>
                                            <option value="<?php echo $barangay['id']; ?>">
                                                <?php echo htmlspecialchars($barangay['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Start Date *</label>
                                    <input type="date" class="form-control" name="start_date" required value="<?php echo date('Y-m-01'); ?>">
                                </div>
                                
                                <div class="form-group">
                                    <label class="form-label">End Date *</label>
                                    <input type="date" class="form-control" name="end_date" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            
                            <div class="form-group">
                                <button type="submit" name="generate_report" class="primary-button">
                                    <i class='bx bx-download'></i>
                                    Generate Report
                                </button>
                            </div>
                        </form>
                    </div>
                    
                    <!-- Recent Reports -->
                    <?php if (!empty($recent_reports)): ?>
                    <div class="card">
                        <div class="card-title">
                            <span>Recent Analytics Reports</span>
                        </div>
                        
                        <div class="table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Report ID</th>
                                        <th>Type</th>
                                        <th>Date</th>
                                        <th>Barangay</th>
                                        <th>Violations</th>
                                        <th>Complaints</th>
                                        <th>Total Fines</th>
                                        <th>Compliance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_reports as $report): ?>
                                    <tr>
                                        <td>#<?php echo $report['id']; ?></td>
                                        <td><?php echo $report['report_type']; ?></td>
                                        <td><?php echo date('M d, Y', strtotime($report['report_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($report['barangay_name']); ?></td>
                                        <td><?php echo $report['total_violations']; ?></td>
                                        <td><?php echo $report['total_complaints']; ?></td>
                                        <td>₱<?php echo number_format($report['total_fines'], 2); ?></td>
                                        <td><?php echo $report['compliance_rate']; ?>%</td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Violation Modal -->
    <div id="violationModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title" id="modalTitle">Add New Violation Rule</h2>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" id="violationForm" onsubmit="return validateViolationForm()">
                <input type="hidden" id="violation_id" name="violation_id">
                
                <div class="form-group">
                    <label class="form-label">Violation Name *</label>
                    <input type="text" class="form-control" name="violation_name" required id="violationName">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Description</label>
                    <textarea class="form-control" name="description" rows="3" id="description"></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Penalty Type *</label>
                        <select class="form-control" name="penalty_type" required id="penaltyType" onchange="toggleSuspensionDays()">
                            <option value="Fine">Fine</option>
                            <option value="Suspension">Suspension</option>
                            <option value="Warning">Warning</option>
                            <option value="Demerit">Demerit Points</option>
                            <option value="Franchise Revocation">Franchise Revocation</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Penalty Amount (₱) *</label>
                        <input type="number" class="form-control" name="penalty_amount" min="0" step="0.01" required id="penaltyAmount">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group" id="suspensionDaysGroup" style="display: none;">
                        <label class="form-label">Suspension Days</label>
                        <input type="number" class="form-control" name="suspension_days" min="0" value="0" id="suspensionDays">
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Demerit Points</label>
                        <input type="number" class="form-control" name="demerit_points" min="0" value="0" id="demeritPoints">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Enforcement Priority</label>
                        <select class="form-control" name="enforcement_priority" id="enforcementPriority">
                            <option value="Low">Low</option>
                            <option value="Medium" selected>Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Applicable To</label>
                        <select class="form-control" name="applicable_to" id="applicableTo">
                            <option value="Driver">Driver Only</option>
                            <option value="Operator">Operator Only</option>
                            <option value="Both" selected>Both Driver & Operator</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Barangay *</label>
                    <select class="form-control" name="barangay_id" required id="barangayId">
                        <option value="">Select Barangay</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Route Misuse Types (Optional)</label>
                    <div class="checkbox-group" id="misuseTypesContainer">
                        <?php foreach ($misuse_types as $type): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="misuse_types[]" value="<?php echo htmlspecialchars($type); ?>" id="misuse_<?php echo str_replace(' ', '_', $type); ?>">
                            <label for="misuse_<?php echo str_replace(' ', '_', $type); ?>"><?php echo htmlspecialchars($type); ?></label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <input type="checkbox" name="is_active" value="1" checked id="isActive"> Active Rule
                    </label>
                </div>
                
                <div class="form-group">
                    <button type="submit" name="add_violation" class="primary-button" id="submitAdd">Save Violation Rule</button>
                    <button type="submit" name="update_violation" class="primary-button" id="submitUpdate" style="display: none;">Update Violation Rule</button>
                    <button type="button" class="secondary-button" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Tab switching
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        // Toggle submenu
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = event.currentTarget.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Modal functions
        function openAddModal() {
            document.getElementById('violationModal').style.display = 'block';
            document.getElementById('modalTitle').textContent = 'Add New Violation Rule';
            document.getElementById('violationForm').reset();
            document.getElementById('violation_id').value = '';
            document.getElementById('submitAdd').style.display = 'inline-block';
            document.getElementById('submitUpdate').style.display = 'none';
            toggleSuspensionDays();
        }
        
        function closeModal() {
            document.getElementById('violationModal').style.display = 'none';
        }
        
        function toggleSuspensionDays() {
            const penaltyType = document.getElementById('penaltyType').value;
            const suspensionGroup = document.getElementById('suspensionDaysGroup');
            
            if (penaltyType === 'Suspension') {
                suspensionGroup.style.display = 'block';
            } else {
                suspensionGroup.style.display = 'none';
            }
        }
        
        // Edit violation
        function editViolation(id) {
            // Show loading
            Swal.fire({
                title: 'Loading...',
                text: 'Fetching violation details',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            fetch('get_violation.php?id=' + id)
                .then(response => response.json())
                .then(data => {
                    Swal.close();
                    
                    if (data.success) {
                        const violation = data.violation;
                        document.getElementById('violationModal').style.display = 'block';
                        document.getElementById('modalTitle').textContent = 'Edit Violation Rule';
                        
                        // Fill form with violation data
                        document.getElementById('violation_id').value = violation.id;
                        document.getElementById('violationName').value = violation.violation_name;
                        document.getElementById('description').value = violation.description;
                        document.getElementById('penaltyType').value = violation.penalty_type;
                        document.getElementById('penaltyAmount').value = violation.penalty_amount;
                        document.getElementById('suspensionDays').value = violation.suspension_days;
                        document.getElementById('demeritPoints').value = violation.demerit_points;
                        document.getElementById('enforcementPriority').value = violation.enforcement_priority;
                        document.getElementById('applicableTo').value = violation.applicable_to;
                        document.getElementById('barangayId').value = violation.barangay_id;
                        document.getElementById('isActive').checked = violation.is_active == 1;
                        
                        // Show update button, hide add button
                        document.getElementById('submitAdd').style.display = 'none';
                        document.getElementById('submitUpdate').style.display = 'inline-block';
                        
                        // Load misuse types
                        if (data.misuse_types) {
                            // Uncheck all first
                            document.querySelectorAll('[name="misuse_types[]"]').forEach(checkbox => {
                                checkbox.checked = false;
                            });
                            
                            // Check the ones that apply
                            data.misuse_types.forEach(type => {
                                const checkbox = document.querySelector(`[value="${type}"]`);
                                if (checkbox) checkbox.checked = true;
                            });
                        }
                        
                        toggleSuspensionDays();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: data.message || 'Failed to load violation data'
                        });
                    }
                })
                .catch(error => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'Failed to load violation data'
                    });
                });
        }
        
        // Delete violation with confirmation
        function deleteViolation(id, name) {
            Swal.fire({
                title: 'Delete Violation Rule',
                text: `Are you sure you want to delete "${name}"? This action cannot be undone.`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?delete_violation=' + id;
                }
            });
        }
        
        // View complaint details
        function viewComplaint(id) {
            Swal.fire({
                title: 'Complaint Details',
                html: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        // Assign complaint
        function assignComplaint(id) {
            Swal.fire({
                title: 'Assign Complaint',
                html: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        // View violation record
        function viewViolationRecord(id) {
            Swal.fire({
                title: 'Violation Record Details',
                html: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        // Verify violation
        function verifyViolation(id) {
            Swal.fire({
                title: 'Verify Violation',
                text: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        // View route details
        function viewRouteDetails(routeName) {
            Swal.fire({
                title: 'Route Details: ' + routeName,
                html: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        // Form validation for penalty application
        function validatePenaltyForm() {
            const violationId = document.querySelector('[name="violation_id"]').value;
            const misuseType = document.querySelector('[name="misuse_type"]').value;
            const routeId = document.querySelector('[name="route_id"]').value;
            const location = document.querySelector('[name="location"]').value;
            const barangayId = document.querySelector('[name="barangay_id"]').value;
            const misuseDetails = document.querySelector('[name="route_misuse_details"]').value;
            
            if (!violationId || !misuseType || !routeId || !location || !barangayId || !misuseDetails) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.'
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Applying Penalty...',
                text: 'Please wait while we process the penalty.',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            return true;
        }
        
        // Form validation for report generation
        function validateReportForm() {
            const startDate = document.querySelector('[name="start_date"]').value;
            const endDate = document.querySelector('[name="end_date"]').value;
            
            if (new Date(startDate) > new Date(endDate)) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Date Range',
                    text: 'Start date cannot be later than end date.'
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Generating Report...',
                text: 'This may take a few moments.',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            return true;
        }
        
        // Form validation for violation form
        function validateViolationForm() {
            const violationName = document.getElementById('violationName').value;
            const penaltyAmount = document.getElementById('penaltyAmount').value;
            const barangayId = document.getElementById('barangayId').value;
            
            if (!violationName || !penaltyAmount || !barangayId) {
                Swal.fire({
                    icon: 'error',
                    title: 'Missing Information',
                    text: 'Please fill in all required fields.'
                });
                return false;
            }
            
            if (parseFloat(penaltyAmount) < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Invalid Amount',
                    text: 'Penalty amount cannot be negative.'
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Saving Violation Rule...',
                text: 'Please wait while we save the rule.',
                icon: 'info',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            return true;
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
                localStorage.setItem('theme', 'dark');
            } else {
                themeIcon.className = 'bx bx-moon';
                themeText.textContent = 'Dark Mode';
                localStorage.setItem('theme', 'light');
            }
        });
        
        // Check saved theme preference
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
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('violationModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => {
                    alert.style.display = 'none';
                }, 500);
            });
        }, 5000);
        
        // Show success message with SweetAlert if exists
        <?php if ($success_message): ?>
        Swal.fire({
            icon: 'success',
            title: 'Success!',
            text: '<?php echo addslashes($success_message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>
        
        // Show error message with SweetAlert if exists
        <?php if ($error_message): ?>
        Swal.fire({
            icon: 'error',
            title: 'Error!',
            text: '<?php echo addslashes($error_message); ?>',
            timer: 3000,
            showConfirmButton: false
        });
        <?php endif; ?>
    </script>
</body>
</html>