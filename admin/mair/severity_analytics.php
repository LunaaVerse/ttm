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
        'first_name' => 'Admin',
        'middle_name' => '',
        'last_name' => 'User',
        'email' => 'admin@commonwealth.com',
        'role' => 'ADMIN'
    ];
}

// Prepare user data
$full_name = $user['first_name'] . " " . ($user['middle_name'] ? $user['middle_name'] . " " : "") . $user['last_name'];
$email = $user['email'];
$role = $user['role'];

// Set current page for active menu highlighting
$current_page = 'severity_safety_review';

// Check if user is admin
if ($role !== 'ADMIN') {
    header('Location: ../index.php');
    exit();
}

// FIRST: Check if any barangays exist, create sample if none
$sql_check_barangays = "SELECT COUNT(*) as count FROM barangays";
$stmt_check = $pdo->query($sql_check_barangays);
$row_check = $stmt_check->fetch(PDO::FETCH_ASSOC);

if ($row_check['count'] == 0) {
    // Create sample barangay
    $sql_create_barangay = "INSERT INTO barangays (name, code, population, area_sqkm, contact_person, contact_number) 
                           VALUES ('Commonwealth', 'BRG-001', 5000, 2.5, 'Barangay Captain', '09123456789')";
    $pdo->exec($sql_create_barangay);
}

// SECOND: Check if severity_analytics table exists, create if not
try {
    $sql_check = "SHOW TABLES LIKE 'severity_analytics'";
    $stmt = $pdo->query($sql_check);
    if ($stmt->rowCount() == 0) {
        // Create severity_analytics table
        $sql_create = "CREATE TABLE IF NOT EXISTS `severity_analytics` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `analytics_code` varchar(20) NOT NULL,
            `barangay_id` int(11) NOT NULL,
            `route_id` int(11) DEFAULT NULL,
            `analytics_date` date NOT NULL,
            `period_type` enum('Daily','Weekly','Monthly','Quarterly','Yearly') DEFAULT 'Monthly',
            `minor_accidents` int(11) DEFAULT 0,
            `near_misses` int(11) DEFAULT 0,
            `hazards` int(11) DEFAULT 0,
            `obstructions` int(11) DEFAULT 0,
            `total_incidents` int(11) DEFAULT 0,
            `accident_prone_area_id` int(11) DEFAULT NULL,
            `risk_score` decimal(5,2) DEFAULT 0.00,
            `safety_score` decimal(5,2) DEFAULT 0.00,
            `top_incident_type` varchar(50) DEFAULT NULL,
            `recommendations` text DEFAULT NULL,
            `generated_by` int(11) NOT NULL,
            `status` enum('Draft','Published','Archived') DEFAULT 'Draft',
            `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
            `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (`id`),
            UNIQUE KEY `analytics_code` (`analytics_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        
        $pdo->exec($sql_create);
        
        // Get first barangay ID for sample data
        $sql_get_barangay = "SELECT id FROM barangays LIMIT 1";
        $stmt_get_barangay = $pdo->query($sql_get_barangay);
        $first_barangay = $stmt_get_barangay->fetch(PDO::FETCH_ASSOC);
        
        if ($first_barangay) {
            $barangay_id = $first_barangay['id'];
            
            // Insert sample data
            $sql_sample = "INSERT INTO `severity_analytics` 
                          (`analytics_code`, `barangay_id`, `analytics_date`, `period_type`, 
                           `minor_accidents`, `near_misses`, `hazards`, `obstructions`, `total_incidents`,
                           `risk_score`, `safety_score`, `top_incident_type`, `recommendations`, `generated_by`, `status`) 
                          VALUES 
                          ('SA-2025-001', :barangay_id, '2025-01-15', 'Monthly', 5, 12, 8, 3, 28, 65.50, 78.25, 'Near Miss', '1. Install speed bumps near school zone\n2. Add warning signs at sharp curve\n3. Increase patrol frequency during peak hours', 1, 'Published'),
                          ('SA-2025-002', :barangay_id, '2025-01-15', 'Monthly', 3, 8, 5, 2, 18, 45.75, 85.00, 'Hazard', '1. Clear vegetation overgrowth\n2. Repair damaged pavement\n3. Improve drainage system', 1, 'Published')";
            
            $stmt_sample = $pdo->prepare($sql_sample);
            $stmt_sample->execute(['barangay_id' => $barangay_id]);
        }
    }
} catch (Exception $e) {
    // Silently continue if table already exists
    error_log("Table creation/sample data error: " . $e->getMessage());
}

// Initialize variables
$search = isset($_GET['search']) ? $_GET['search'] : '';
$barangay_filter = isset($_GET['barangay']) ? $_GET['barangay'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';
$period = isset($_GET['period']) ? $_GET['period'] : 'Monthly';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['generate_report'])) {
        // Generate severity analytics report
        $barangay_id = $_POST['barangay_id'];
        $route_id = $_POST['route_id'] ?: NULL;
        $analytics_date = $_POST['analytics_date'];
        $period_type = $_POST['period_type'];
        
        // Calculate statistics from road_condition_reports
        $sql = "SELECT 
                    incident_type,
                    COUNT(*) as count,
                    SUM(CASE WHEN severity = 'Emergency' THEN 1 ELSE 0 END) as emergency_count,
                    SUM(CASE WHEN severity = 'High' THEN 1 ELSE 0 END) as high_count,
                    SUM(CASE WHEN severity IN ('Medium', 'Low') THEN 1 ELSE 0 END) as medium_low_count
                FROM road_condition_reports 
                WHERE barangay = (SELECT name FROM barangays WHERE id = :barangay_id)
                AND report_date >= DATE_SUB(:analytics_date, INTERVAL 1 MONTH)
                AND report_date <= :analytics_date";
        
        if ($route_id) {
            $sql .= " AND route_id = :route_id";
        }
        
        $sql .= " GROUP BY incident_type ORDER BY count DESC";
        
        $stmt = $pdo->prepare($sql);
        $params = ['barangay_id' => $barangay_id, 'analytics_date' => $analytics_date];
        if ($route_id) {
            $params['route_id'] = $route_id;
        }
        $stmt->execute($params);
        $incident_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate totals
        $minor_accidents = 0;
        $near_misses = 0;
        $hazards = 0;
        $obstructions = 0;
        $total_incidents = 0;
        $top_incident_type = '';
        $max_count = 0;
        
        foreach ($incident_stats as $stat) {
            $total_incidents += $stat['count'];
            switch ($stat['incident_type']) {
                case 'Minor Accident':
                    $minor_accidents = $stat['count'];
                    break;
                case 'Near Miss':
                    $near_misses = $stat['count'];
                    break;
                case 'Hazard':
                    $hazards = $stat['count'];
                    break;
                case 'Obstruction':
                    $obstructions = $stat['count'];
                    break;
            }
            
            if ($stat['count'] > $max_count) {
                $max_count = $stat['count'];
                $top_incident_type = $stat['incident_type'];
            }
        }
        
        // Calculate risk score (higher incidents = higher risk)
        $risk_score = min(100, ($total_incidents * 2) + ($minor_accidents * 5));
        $safety_score = 100 - $risk_score;
        
        // Generate analytics code
        $analytics_code = 'SA-' . date('Y') . '-' . str_pad(rand(100, 999), 3, '0', STR_PAD_LEFT);
        
        // Insert into severity_analytics table
        $sql = "INSERT INTO severity_analytics 
                (analytics_code, barangay_id, route_id, analytics_date, period_type, 
                 minor_accidents, near_misses, hazards, obstructions, total_incidents,
                 risk_score, safety_score, top_incident_type, generated_by, status)
                VALUES 
                (:analytics_code, :barangay_id, :route_id, :analytics_date, :period_type,
                 :minor_accidents, :near_misses, :hazards, :obstructions, :total_incidents,
                 :risk_score, :safety_score, :top_incident_type, :generated_by, 'Draft')";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'analytics_code' => $analytics_code,
            'barangay_id' => $barangay_id,
            'route_id' => $route_id,
            'analytics_date' => $analytics_date,
            'period_type' => $period_type,
            'minor_accidents' => $minor_accidents,
            'near_misses' => $near_misses,
            'hazards' => $hazards,
            'obstructions' => $obstructions,
            'total_incidents' => $total_incidents,
            'risk_score' => $risk_score,
            'safety_score' => $safety_score,
            'top_incident_type' => $top_incident_type,
            'generated_by' => $user_id
        ]);
        
        $_SESSION['success_message'] = "Severity analytics report generated successfully!";
        header("Location: severity_safety_review.php?tab=analytics");
        exit();
    }
    
    if (isset($_POST['update_status'])) {
        $analytics_id = $_POST['analytics_id'];
        $status = $_POST['status'];
        
        $sql = "UPDATE severity_analytics SET status = :status WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['status' => $status, 'id' => $analytics_id]);
        
        $_SESSION['success_message'] = "Status updated successfully!";
        header("Location: severity_safety_review.php?tab=analytics");
        exit();
    }
    
    if (isset($_POST['add_prone_area'])) {
        $area_code = 'APA-' . date('Ym') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        $location_name = $_POST['location_name'];
        $location = $_POST['location'];
        $latitude = $_POST['latitude'] ?: NULL;
        $longitude = $_POST['longitude'] ?: NULL;
        $barangay_id = $_POST['barangay_id'];
        $route_id = $_POST['route_id'] ?: NULL;
        $area_type = $_POST['area_type'];
        $risk_level = $_POST['risk_level'];
        $common_causes = $_POST['common_causes'];
        $safety_measures = $_POST['safety_measures'];
        $improvement_status = $_POST['improvement_status'];
        $notes = $_POST['notes'];
        
        $sql = "INSERT INTO accident_prone_areas 
                (area_code, location_name, location, latitude, longitude, barangay_id, route_id,
                 area_type, risk_level, common_causes, safety_measures, improvement_status, notes)
                VALUES 
                (:area_code, :location_name, :location, :latitude, :longitude, :barangay_id, :route_id,
                 :area_type, :risk_level, :common_causes, :safety_measures, :improvement_status, :notes)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'area_code' => $area_code,
            'location_name' => $location_name,
            'location' => $location,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'barangay_id' => $barangay_id,
            'route_id' => $route_id,
            'area_type' => $area_type,
            'risk_level' => $risk_level,
            'common_causes' => $common_causes,
            'safety_measures' => $safety_measures,
            'improvement_status' => $improvement_status,
            'notes' => $notes
        ]);
        
        $_SESSION['success_message'] = "Accident-prone area added successfully!";
        header("Location: severity_safety_review.php?tab=prone_areas");
        exit();
    }
}

// Fetch data for display
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'dashboard';

// Fetch barangays
$sql_barangays = "SELECT * FROM barangays ORDER BY name";
$barangays = $pdo->query($sql_barangays)->fetchAll(PDO::FETCH_ASSOC);

// Fetch routes
$sql_routes = "SELECT * FROM tricycle_routes WHERE status = 'Active' ORDER BY route_name";
$routes = $pdo->query($sql_routes)->fetchAll(PDO::FETCH_ASSOC);

// Fetch severity analytics
$sql_analytics = "SELECT sa.*, b.name as barangay_name, tr.route_name 
                  FROM severity_analytics sa
                  LEFT JOIN barangays b ON sa.barangay_id = b.id
                  LEFT JOIN tricycle_routes tr ON sa.route_id = tr.id
                  WHERE 1=1";
                  
if ($barangay_filter) {
    $sql_analytics .= " AND sa.barangay_id = :barangay_id";
}
if ($date_from) {
    $sql_analytics .= " AND sa.analytics_date >= :date_from";
}
if ($date_to) {
    $sql_analytics .= " AND sa.analytics_date <= :date_to";
}
if ($period) {
    $sql_analytics .= " AND sa.period_type = :period";
}

$sql_analytics .= " ORDER BY sa.analytics_date DESC, sa.created_at DESC";
$stmt_analytics = $pdo->prepare($sql_analytics);

$params = [];
if ($barangay_filter) $params['barangay_id'] = $barangay_filter;
if ($date_from) $params['date_from'] = $date_from;
if ($date_to) $params['date_to'] = $date_to;
if ($period) $params['period'] = $period;

$stmt_analytics->execute($params);
$analytics_data = $stmt_analytics->fetchAll(PDO::FETCH_ASSOC);

// Fetch accident-prone areas
$sql_prone_areas = "SELECT apa.*, b.name as barangay_name, tr.route_name 
                    FROM accident_prone_areas apa
                    LEFT JOIN barangays b ON apa.barangay_id = b.id
                    LEFT JOIN tricycle_routes tr ON apa.route_id = tr.id
                    WHERE 1=1";
                    
if ($barangay_filter) {
    $sql_prone_areas .= " AND apa.barangay_id = :barangay_id";
}
if ($search) {
    $sql_prone_areas .= " AND (apa.location_name LIKE :search OR apa.location LIKE :search)";
}

$sql_prone_areas .= " ORDER BY apa.risk_level DESC, apa.total_incidents DESC";
$stmt_prone_areas = $pdo->prepare($sql_prone_areas);

$params2 = [];
if ($barangay_filter) $params2['barangay_id'] = $barangay_filter;
if ($search) $params2['search'] = "%$search%";

$stmt_prone_areas->execute($params2);
$prone_areas = $stmt_prone_areas->fetchAll(PDO::FETCH_ASSOC);

// Fetch safety reviews
$sql_safety_reviews = "SELECT sra.*, b.name as barangay_name, tr.route_name 
                       FROM safety_reviews_audits sra
                       LEFT JOIN barangays b ON sra.barangay_id = b.id
                       LEFT JOIN tricycle_routes tr ON sra.route_id = tr.id
                       WHERE 1=1";
                       
if ($barangay_filter) {
    $sql_safety_reviews .= " AND sra.barangay_id = :barangay_id";
}
if ($date_from) {
    $sql_safety_reviews .= " AND sra.review_date >= :date_from";
}
if ($date_to) {
    $sql_safety_reviews .= " AND sra.review_date <= :date_to";
}

$sql_safety_reviews .= " ORDER BY sra.review_date DESC, sra.created_at DESC";
$stmt_safety_reviews = $pdo->prepare($sql_safety_reviews);

$params3 = [];
if ($barangay_filter) $params3['barangay_id'] = $barangay_filter;
if ($date_from) $params3['date_from'] = $date_from;
if ($date_to) $params3['date_to'] = $date_to;

$stmt_safety_reviews->execute($params3);
$safety_reviews = $stmt_safety_reviews->fetchAll(PDO::FETCH_ASSOC);

// Fetch incident statistics for dashboard
$sql_incident_stats = "SELECT 
                        incident_type,
                        COUNT(*) as count,
                        AVG(CASE 
                            WHEN severity = 'Emergency' THEN 100
                            WHEN severity = 'High' THEN 75
                            WHEN severity = 'Medium' THEN 50
                            WHEN severity = 'Low' THEN 25
                            ELSE 0
                        END) as avg_severity_score
                       FROM road_condition_reports 
                       WHERE report_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                       GROUP BY incident_type
                       ORDER BY count DESC";
$incident_stats = $pdo->query($sql_incident_stats)->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals for dashboard
$total_incidents = array_sum(array_column($incident_stats, 'count'));
$avg_risk_score = $total_incidents > 0 ? 
    array_sum(array_column($incident_stats, 'avg_severity_score')) / count($incident_stats) : 0;

// Fetch sample accident-prone areas if empty - FIXED VERSION
if (empty($prone_areas) && !empty($barangays)) {
    $sql_sample_areas = "SELECT COUNT(*) as count FROM accident_prone_areas";
    $stmt = $pdo->query($sql_sample_areas);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($row['count'] == 0) {
        // Use first barangay ID
        $first_barangay_id = $barangays[0]['id'];
        
        // Check if any routes exist
        $sql_check_routes = "SELECT id FROM tricycle_routes LIMIT 1";
        $stmt_routes = $pdo->query($sql_check_routes);
        $first_route = $stmt_routes->fetch(PDO::FETCH_ASSOC);
        $route_id = $first_route ? $first_route['id'] : NULL;
        
        $sql_insert_sample = "INSERT INTO accident_prone_areas 
                              (area_code, location_name, location, latitude, longitude, barangay_id, route_id, area_type, risk_level, total_incidents, minor_accidents, near_misses, hazards, last_incident_date, common_causes, safety_measures, improvement_status, estimated_risk_score, last_assessment_date, assessed_by, notes) 
                              VALUES 
                              ('APA-001', 'Main Street Intersection', 'Main St & 1st Ave', 14.59951200, 120.98422200, :barangay_id, :route_id, 'Intersection', 'High', 15, 5, 8, 2, '2025-01-10', 'Speeding, No proper signage', 'Speed bumps installed, Warning signs added', 'Completed', 75.50, '2025-01-14', 1, 'High traffic area near market'),
                              ('APA-002', 'School Zone Curve', 'Near Elementary School', 14.59876500, 120.98345600, :barangay_id, :route_id, 'Sharp Curve', 'Critical', 22, 8, 10, 4, '2025-01-12', 'Sharp curve, Poor visibility', 'Mirrors installed, Road markings improved', 'In Progress', 85.25, '2025-01-14', 1, 'Need additional warning lights')";
        
        $stmt_insert = $pdo->prepare($sql_insert_sample);
        $stmt_insert->execute([
            'barangay_id' => $first_barangay_id,
            'route_id' => $route_id
        ]);
        
        // Re-fetch prone areas
        $prone_areas = $pdo->query("SELECT apa.*, b.name as barangay_name, tr.route_name 
                                   FROM accident_prone_areas apa
                                   LEFT JOIN barangays b ON apa.barangay_id = b.id
                                   LEFT JOIN tricycle_routes tr ON apa.route_id = tr.id
                                   ORDER BY apa.risk_level DESC")->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Severity & Safety Review - Traffic & Transport Management</title>
    <!-- Boxicons CSS -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
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
            
            /* Risk level colors */
            --risk-critical: #dc2626;
            --risk-high: #ea580c;
            --risk-medium: #d97706;
            --risk-low: #16a34a;
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
        
        /* Module Content */
        .module-content {
            padding: 32px;
        }
        
        .module-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .module-title {
            font-size: 30px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .module-subtitle {
            color: var(--text-light);
        }
        
        /* Module-specific styles */
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
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 24px;
            padding-bottom: 8px;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            color: var(--text-color);
            font-weight: 500;
            transition: all 0.3s;
            position: relative;
        }
        
        .tab:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .tab:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .tab.active {
            background-color: var(--primary-color);
            color: white;
        }
        
        .tab-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: var(--secondary-color);
            color: white;
            font-size: 12px;
            padding: 2px 8px;
            border-radius: 12px;
            min-width: 20px;
            text-align: center;
        }
        
        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            padding: 16px;
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            flex-wrap: wrap;
            align-items: flex-end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 200px;
        }
        
        .filter-label {
            font-size: 14px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .filter-input {
            padding: 10px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .filter-button {
            padding: 10px 24px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: background-color 0.3s;
        }
        
        .filter-button:hover {
            background-color: var(--primary-dark);
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
            background-color: var(--card-bg);
            border: 1px solid var(--border-color);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        
        .dark-mode .stat-card:hover {
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
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
            color: var(--text-light);
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .stat-trend {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 14px;
        }
        
        .trend-up {
            color: var(--risk-low);
        }
        
        .trend-down {
            color: var(--risk-critical);
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            overflow: hidden;
        }
        
        .table-header {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            padding: 16px;
            background-color: rgba(0, 0, 0, 0.02);
            border-bottom: 1px solid var(--border-color);
            font-weight: 600;
            font-size: 14px;
        }
        
        .dark-mode .table-header {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .table-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            padding: 16px;
            border-bottom: 1px solid var(--border-color);
            transition: background-color 0.2s;
        }
        
        .table-row:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        
        .dark-mode .table-row:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        .table-cell {
            display: flex;
            align-items: center;
            padding: 4px;
        }
        
        /* Badges */
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .badge-critical {
            background-color: #fee2e2;
            color: #991b1b;
        }
        
        .dark-mode .badge-critical {
            background-color: rgba(220, 38, 38, 0.2);
            color: #fca5a5;
        }
        
        .badge-high {
            background-color: #ffedd5;
            color: #92400e;
        }
        
        .dark-mode .badge-high {
            background-color: rgba(234, 88, 12, 0.2);
            color: #fdba74;
        }
        
        .badge-medium {
            background-color: #fef3c7;
            color: #92400e;
        }
        
        .dark-mode .badge-medium {
            background-color: rgba(217, 119, 6, 0.2);
            color: #fcd34d;
        }
        
        .badge-low {
            background-color: #dcfce7;
            color: #166534;
        }
        
        .dark-mode .badge-low {
            background-color: rgba(22, 163, 74, 0.2);
            color: #86efac;
        }
        
        .badge-draft {
            background-color: #e5e7eb;
            color: #374151;
        }
        
        .badge-published {
            background-color: #dbeafe;
            color: #1e40af;
        }
        
        .badge-archived {
            background-color: #f3f4f6;
            color: #6b7280;
        }
        
        /* Charts */
        .chart-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 24px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
        }
        
        .chart-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        
        .chart-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-bottom: 24px;
        }
        
        .btn-primary {
            padding: 12px 24px;
            background-color: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-secondary {
            padding: 12px 24px;
            background-color: var(--card-bg);
            color: var(--text-color);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background-color 0.3s;
        }
        
        .btn-secondary:hover {
            background-color: rgba(0, 0, 0, 0.05);
        }
        
        .dark-mode .btn-secondary:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        
        /* Forms */
        .form-container {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 32px;
            border: 1px solid var(--border-color);
            max-width: 800px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-color);
        }
        
        .form-input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            min-height: 120px;
            resize: vertical;
            transition: border-color 0.3s;
        }
        
        .form-textarea:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background-color: var(--card-bg);
            color: var(--text-color);
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 32px;
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
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background-color: var(--card-bg);
            border-radius: 12px;
            padding: 32px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        
        .modal-title {
            font-size: 24px;
            font-weight: 600;
        }
        
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-color);
            cursor: pointer;
            padding: 4px;
        }
        
        /* Progress Bars */
        .progress-bar {
            height: 8px;
            background-color: var(--border-color);
            border-radius: 4px;
            overflow: hidden;
            margin: 8px 0;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-critical {
            background-color: var(--risk-critical);
        }
        
        .progress-high {
            background-color: var(--risk-high);
        }
        
        .progress-medium {
            background-color: var(--risk-medium);
        }
        
        .progress-low {
            background-color: var(--risk-low);
        }
        
        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .module-content {
                padding: 16px;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-group {
                min-width: 100%;
            }
            
            .tabs {
                flex-wrap: wrap;
            }
            
            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
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
                    <div id="road-monitoring" class="submenu active">
                        <a href="tricycle_route_management.php" class="submenu-item">Route Management</a>
                        <a href="driver_franchise_records.php" class="submenu-item">Driver & Franchise Records</a>
                        <a href="approval_enforcement.php" class="submenu-item">Enforcement Rules & Analytics</a>
                        <a href="severity_safety_review.php" class="submenu-item active">Severity & Safety Review</a>
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
                        <a href="../mair/admin_report_review.php" class="submenu-item">Patrol Maps</a>
                        <a href="#" class="submenu-item">Tanod Shift</a>
                        <a href="#" class="submenu-item">Patrol Efficiency</a>
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
                        <a href="#" class="submenu-item">Transport Permits</a>
                        <a href="#" class="submenu-item">Regulatory Updates</a>
                        <a href="#" class="submenu-item">Compliance Verification</a>
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
                        <a href="#" class="submenu-item">Review Feedback</a>
                        <a href="#" class="submenu-item">Escalate Issues</a>
                        <a href="#" class="submenu-item">Generate Reports</a>
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
                        <a href="#" class="submenu-item">Review Feedback</a>
                        <a href="#" class="submenu-item">Escalate Issues</a>
                        <a href="#" class="submenu-item">Generate Reports</a>
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
                            <input type="text" placeholder="Search safety reviews..." class="search-input" id="global-search">
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
            
            <!-- Module Content -->
            <div class="module-content">
                <div class="module-header">
                    <div>
                        <h1 class="module-title">Severity & Safety Review</h1>
                        <p class="module-subtitle">Welcome back, <?php echo htmlspecialchars($full_name); ?>! Analyze incident severity, identify accident-prone areas, and conduct safety reviews.</p>
                    </div>
                    <div class="dashboard-actions">
                        <button class="primary-button" onclick="openGenerateReportModal()">
                            <span style="font-size: 20px;">+</span>
                            Generate Report
                        </button>
                        <button class="secondary-button" onclick="openAddProneAreaModal()">
                            <i class='bx bx-map-pin'></i>
                            Add Prone Area
                        </button>
                        <button class="secondary-button" onclick="exportData()">
                            <i class='bx bx-download'></i>
                            Export Data
                        </button>
                    </div>
                </div>
                
                <!-- Tabs -->
                <div class="tabs">
                    <button class="tab <?php echo $active_tab == 'dashboard' ? 'active' : ''; ?>" onclick="switchTab('dashboard')">
                        Dashboard
                        <span class="tab-badge"><?php echo $total_incidents; ?></span>
                    </button>
                    <button class="tab <?php echo $active_tab == 'analytics' ? 'active' : ''; ?>" onclick="switchTab('analytics')">
                        Severity Analytics
                        <span class="tab-badge"><?php echo count($analytics_data); ?></span>
                    </button>
                    <button class="tab <?php echo $active_tab == 'prone_areas' ? 'active' : ''; ?>" onclick="switchTab('prone_areas')">
                        Accident-Prone Areas
                        <span class="tab-badge"><?php echo count($prone_areas); ?></span>
                    </button>
                    <button class="tab <?php echo $active_tab == 'safety_reviews' ? 'active' : ''; ?>" onclick="switchTab('safety_reviews')">
                        Safety Reviews
                        <span class="tab-badge"><?php echo count($safety_reviews); ?></span>
                    </button>
                </div>
                
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-group">
                        <label class="filter-label">Barangay</label>
                        <select class="filter-input" id="barangay-filter">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays as $barangay): ?>
                                <option value="<?php echo $barangay['id']; ?>" <?php echo $barangay_filter == $barangay['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($barangay['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date From</label>
                        <input type="date" class="filter-input" id="date-from" value="<?php echo $date_from; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Date To</label>
                        <input type="date" class="filter-input" id="date-to" value="<?php echo $date_to; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label class="filter-label">Period</label>
                        <select class="filter-input" id="period-filter">
                            <option value="Daily" <?php echo $period == 'Daily' ? 'selected' : ''; ?>>Daily</option>
                            <option value="Weekly" <?php echo $period == 'Weekly' ? 'selected' : ''; ?>>Weekly</option>
                            <option value="Monthly" <?php echo $period == 'Monthly' ? 'selected' : ''; ?>>Monthly</option>
                            <option value="Quarterly" <?php echo $period == 'Quarterly' ? 'selected' : ''; ?>>Quarterly</option>
                            <option value="Yearly" <?php echo $period == 'Yearly' ? 'selected' : ''; ?>>Yearly</option>
                        </select>
                    </div>
                    
                    <button class="filter-button" onclick="applyFilters()">
                        <i class='bx bx-filter'></i>
                        Apply Filters
                    </button>
                    
                    <button class="secondary-button" onclick="clearFilters()">
                        <i class='bx bx-reset'></i>
                        Clear
                    </button>
                </div>
                
                <!-- Dashboard Tab -->
                <?php if ($active_tab == 'dashboard'): ?>
                    <!-- Stats Cards -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-header">
                                <span class="stat-title">Total Incidents (30 Days)</span>
                                <i class='bx bx-error-circle' style="font-size: 24px; color: var(--primary-color);"></i>
                            </div>
                            <div class="stat-value"><?php echo $total_incidents; ?></div>
                            <div class="stat-trend <?php echo $total_incidents > 20 ? 'trend-up' : 'trend-down'; ?>">
                                <i class='bx bx-<?php echo $total_incidents > 20 ? 'trending-up' : 'trending-down'; ?>'></i>
                                <span><?php echo $total_incidents > 20 ? 'High' : 'Normal'; ?> activity</span>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <span class="stat-title">Average Risk Score</span>
                                <i class='bx bx-line-chart' style="font-size: 24px; color: var(--risk-medium);"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format($avg_risk_score, 1); ?></div>
                            <div class="progress-bar">
                                <div class="progress-fill progress-<?php 
                                    echo $avg_risk_score > 75 ? 'critical' : 
                                         ($avg_risk_score > 50 ? 'high' : 
                                         ($avg_risk_score > 25 ? 'medium' : 'low')); 
                                ?>" style="width: <?php echo $avg_risk_score; ?>%;"></div>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <span class="stat-title">Top Incident Type</span>
                                <i class='bx bx-target-lock' style="font-size: 24px; color: var(--secondary-color);"></i>
                            </div>
                            <div class="stat-value">
                                <?php echo !empty($incident_stats) ? $incident_stats[0]['incident_type'] : 'None'; ?>
                            </div>
                            <div class="stat-trend">
                                <i class='bx bx-stats'></i>
                                <span><?php echo !empty($incident_stats) ? $incident_stats[0]['count'] : '0'; ?> cases</span>
                            </div>
                        </div>
                        
                        <div class="stat-card">
                            <div class="stat-header">
                                <span class="stat-title">Safety Score</span>
                                <i class='bx bx-shield-check' style="font-size: 24px; color: var(--risk-low);"></i>
                            </div>
                            <div class="stat-value"><?php echo number_format(100 - $avg_risk_score, 1); ?>%</div>
                            <div class="stat-trend">
                                <i class='bx bx-check-circle'></i>
                                <span><?php echo $avg_risk_score < 50 ? 'Good' : 'Needs Improvement'; ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Charts Grid -->
                    <div class="chart-grid">
                        <!-- Incident Type Distribution -->
                        <div class="chart-container">
                            <h3 class="chart-title">Incident Type Distribution (Last 30 Days)</h3>
                            <div id="incident-type-chart" style="height: 300px;">
                                <div style="display: flex; flex-direction: column; gap: 12px; margin-top: 20px;">
                                    <?php if (!empty($incident_stats)): ?>
                                        <?php foreach ($incident_stats as $stat): ?>
                                            <div>
                                                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                                    <span><?php echo $stat['incident_type']; ?></span>
                                                    <span><?php echo $stat['count']; ?> incidents</span>
                                                </div>
                                                <div class="progress-bar">
                                                    <div class="progress-fill progress-<?php 
                                                        echo $stat['avg_severity_score'] > 75 ? 'critical' : 
                                                             ($stat['avg_severity_score'] > 50 ? 'high' : 
                                                             ($stat['avg_severity_score'] > 25 ? 'medium' : 'low')); 
                                                    ?>" style="width: <?php echo ($stat['count'] / $total_incidents) * 100; ?>%;"></div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <p style="text-align: center; color: var(--text-light);">No incident data available</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Risk Level Distribution -->
                        <div class="chart-container">
                            <h3 class="chart-title">Risk Level Analysis</h3>
                            <div id="risk-level-chart" style="height: 300px;">
                                <div style="display: flex; justify-content: center; align-items: center; height: 200px; gap: 20px; margin-top: 20px;">
                                    <div style="text-align: center;">
                                        <div style="width: 80px; height: 80px; border-radius: 50%; background-color: var(--risk-critical); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;">
                                            <span style="color: white; font-weight: bold;"><?php echo $avg_risk_score > 75 ? 'Critical' : ''; ?></span>
                                        </div>
                                        <span>Critical</span>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="width: 60px; height: 60px; border-radius: 50%; background-color: var(--risk-high); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;">
                                            <span style="color: white; font-weight: bold;"><?php echo $avg_risk_score > 50 && $avg_risk_score <= 75 ? 'High' : ''; ?></span>
                                        </div>
                                        <span>High</span>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="width: 50px; height: 50px; border-radius: 50%; background-color: var(--risk-medium); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;">
                                            <span style="color: white; font-weight: bold;"><?php echo $avg_risk_score > 25 && $avg_risk_score <= 50 ? 'Medium' : ''; ?></span>
                                        </div>
                                        <span>Medium</span>
                                    </div>
                                    <div style="text-align: center;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background-color: var(--risk-low); display: flex; align-items: center; justify-content: center; margin: 0 auto 10px;">
                                            <span style="color: white; font-weight: bold;"><?php echo $avg_risk_score <= 25 ? 'Low' : ''; ?></span>
                                        </div>
                                        <span>Low</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Analytics Reports -->
                    <div class="chart-container">
                        <div class="module-header">
                            <h3 class="chart-title">Recent Severity Analytics Reports</h3>
                            <button class="secondary-button" style="font-size: 14px; padding: 8px 16px;" onclick="switchTab('analytics')">View All</button>
                        </div>
                        <div class="data-table">
                            <div class="table-header">
                                <div class="table-cell">Report Code</div>
                                <div class="table-cell">Date</div>
                                <div class="table-cell">Barangay</div>
                                <div class="table-cell">Total Incidents</div>
                                <div class="table-cell">Risk Score</div>
                                <div class="table-cell">Status</div>
                                <div class="table-cell">Actions</div>
                            </div>
                            <?php foreach (array_slice($analytics_data, 0, 5) as $analytic): ?>
                                <div class="table-row">
                                    <div class="table-cell">
                                        <strong><?php echo $analytic['analytics_code']; ?></strong>
                                    </div>
                                    <div class="table-cell">
                                        <?php echo date('M d, Y', strtotime($analytic['analytics_date'])); ?>
                                    </div>
                                    <div class="table-cell">
                                        <?php echo $analytic['barangay_name']; ?>
                                    </div>
                                    <div class="table-cell">
                                        <?php echo $analytic['total_incidents']; ?>
                                    </div>
                                    <div class="table-cell">
                                        <div class="progress-bar">
                                            <div class="progress-fill progress-<?php 
                                                echo $analytic['risk_score'] > 75 ? 'critical' : 
                                                     ($analytic['risk_score'] > 50 ? 'high' : 
                                                     ($analytic['risk_score'] > 25 ? 'medium' : 'low')); 
                                            ?>" style="width: <?php echo $analytic['risk_score']; ?>%;"></div>
                                        </div>
                                        <small><?php echo number_format($analytic['risk_score'], 1); ?></small>
                                    </div>
                                    <div class="table-cell">
                                        <span class="badge badge-<?php echo strtolower($analytic['status']); ?>">
                                            <?php echo $analytic['status']; ?>
                                        </span>
                                    </div>
                                    <div class="table-cell">
                                        <button class="secondary-button" onclick="viewAnalytics(<?php echo $analytic['id']; ?>)">
                                            <i class='bx bx-show'></i>
                                        </button>
                                        <button class="secondary-button" onclick="editAnalytics(<?php echo $analytic['id']; ?>)">
                                            <i class='bx bx-edit'></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Severity Analytics Tab -->
                <?php if ($active_tab == 'analytics'): ?>
                    <div class="data-table">
                        <div class="table-header">
                            <div class="table-cell">Report Code</div>
                            <div class="table-cell">Period</div>
                            <div class="table-cell">Barangay</div>
                            <div class="table-cell">Route</div>
                            <div class="table-cell">Minor Accidents</div>
                            <div class="table-cell">Near Misses</div>
                            <div class="table-cell">Hazards</div>
                            <div class="table-cell">Obstructions</div>
                            <div class="table-cell">Total</div>
                            <div class="table-cell">Risk Score</div>
                            <div class="table-cell">Status</div>
                            <div class="table-cell">Actions</div>
                        </div>
                        <?php foreach ($analytics_data as $analytic): ?>
                            <div class="table-row">
                                <div class="table-cell">
                                    <strong><?php echo $analytic['analytics_code']; ?></strong>
                                </div>
                                <div class="table-cell">
                                    <?php echo $analytic['period_type']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $analytic['barangay_name']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $analytic['route_name'] ?: 'N/A'; ?>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-high"><?php echo $analytic['minor_accidents']; ?></span>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-medium"><?php echo $analytic['near_misses']; ?></span>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-medium"><?php echo $analytic['hazards']; ?></span>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-low"><?php echo $analytic['obstructions']; ?></span>
                                </div>
                                <div class="table-cell">
                                    <strong><?php echo $analytic['total_incidents']; ?></strong>
                                </div>
                                <div class="table-cell">
                                    <div class="progress-bar">
                                        <div class="progress-fill progress-<?php 
                                            echo $analytic['risk_score'] > 75 ? 'critical' : 
                                                 ($analytic['risk_score'] > 50 ? 'high' : 
                                                 ($analytic['risk_score'] > 25 ? 'medium' : 'low')); 
                                        ?>" style="width: <?php echo $analytic['risk_score']; ?>%;"></div>
                                    </div>
                                    <small><?php echo number_format($analytic['risk_score'], 1); ?></small>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php echo strtolower($analytic['status']); ?>">
                                        <?php echo $analytic['status']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <button class="secondary-button" onclick="viewAnalytics(<?php echo $analytic['id']; ?>)">
                                        <i class='bx bx-show'></i>
                                    </button>
                                    <button class="secondary-button" onclick="editAnalytics(<?php echo $analytic['id']; ?>)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                    <button class="secondary-button" onclick="updateStatus(<?php echo $analytic['id']; ?>)">
                                        <i class='bx bx-cog'></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Accident-Prone Areas Tab -->
                <?php if ($active_tab == 'prone_areas'): ?>
                    <div class="data-table">
                        <div class="table-header">
                            <div class="table-cell">Area Code</div>
                            <div class="table-cell">Location</div>
                            <div class="table-cell">Barangay</div>
                            <div class="table-cell">Area Type</div>
                            <div class="table-cell">Risk Level</div>
                            <div class="table-cell">Total Incidents</div>
                            <div class="table-cell">Last Incident</div>
                            <div class="table-cell">Improvement Status</div>
                            <div class="table-cell">Risk Score</div>
                            <div class="table-cell">Actions</div>
                        </div>
                        <?php foreach ($prone_areas as $area): ?>
                            <div class="table-row">
                                <div class="table-cell">
                                    <strong><?php echo $area['area_code']; ?></strong>
                                </div>
                                <div class="table-cell">
                                    <div><?php echo $area['location_name']; ?></div>
                                    <small><?php echo $area['location']; ?></small>
                                </div>
                                <div class="table-cell">
                                    <?php echo $area['barangay_name']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $area['area_type']; ?>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php echo strtolower($area['risk_level']); ?>">
                                        <?php echo $area['risk_level']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <?php echo $area['total_incidents']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $area['last_incident_date'] ? date('M d, Y', strtotime($area['last_incident_date'])) : 'N/A'; ?>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php 
                                        echo $area['improvement_status'] == 'Completed' ? 'low' : 
                                             ($area['improvement_status'] == 'In Progress' ? 'medium' : 'high'); 
                                    ?>">
                                        <?php echo $area['improvement_status']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <div class="progress-bar">
                                        <div class="progress-fill progress-<?php 
                                            echo $area['estimated_risk_score'] > 75 ? 'critical' : 
                                                 ($area['estimated_risk_score'] > 50 ? 'high' : 
                                                 ($area['estimated_risk_score'] > 25 ? 'medium' : 'low')); 
                                        ?>" style="width: <?php echo $area['estimated_risk_score']; ?>%;"></div>
                                    </div>
                                    <small><?php echo number_format($area['estimated_risk_score'], 1); ?></small>
                                </div>
                                <div class="table-cell">
                                    <button class="secondary-button" onclick="viewProneArea(<?php echo $area['id']; ?>)">
                                        <i class='bx bx-show'></i>
                                    </button>
                                    <button class="secondary-button" onclick="editProneArea(<?php echo $area['id']; ?>)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Safety Reviews Tab -->
                <?php if ($active_tab == 'safety_reviews'): ?>
                    <div class="data-table">
                        <div class="table-header">
                            <div class="table-cell">Review Code</div>
                            <div class="table-cell">Review Type</div>
                            <div class="table-cell">Date</div>
                            <div class="table-cell">Barangay</div>
                            <div class="table-cell">Focus Area</div>
                            <div class="table-cell">Risk Before</div>
                            <div class="table-cell">Risk After</div>
                            <div class="table-cell">Status</div>
                            <div class="table-cell">Deadline</div>
                            <div class="table-cell">Actions</div>
                        </div>
                        <?php foreach ($safety_reviews as $review): ?>
                            <div class="table-row">
                                <div class="table-cell">
                                    <strong><?php echo $review['review_code']; ?></strong>
                                </div>
                                <div class="table-cell">
                                    <?php echo $review['review_type']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo date('M d, Y', strtotime($review['review_date'])); ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $review['barangay_name']; ?>
                                </div>
                                <div class="table-cell">
                                    <?php echo $review['review_focus']; ?>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php echo strtolower($review['risk_level_before']); ?>">
                                        <?php echo $review['risk_level_before']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php echo strtolower($review['risk_level_after']); ?>">
                                        <?php echo $review['risk_level_after']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <span class="badge badge-<?php 
                                        echo $review['status'] == 'Completed' ? 'low' : 
                                             ($review['status'] == 'In Progress' ? 'medium' : 'high'); 
                                    ?>">
                                        <?php echo $review['status']; ?>
                                    </span>
                                </div>
                                <div class="table-cell">
                                    <?php echo $review['deadline'] ? date('M d, Y', strtotime($review['deadline'])) : 'N/A'; ?>
                                </div>
                                <div class="table-cell">
                                    <button class="secondary-button" onclick="viewSafetyReview(<?php echo $review['id']; ?>)">
                                        <i class='bx bx-show'></i>
                                    </button>
                                    <button class="secondary-button" onclick="editSafetyReview(<?php echo $review['id']; ?>)">
                                        <i class='bx bx-edit'></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Modals -->
    <!-- Generate Report Modal -->
    <div class="modal" id="generate-report-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Generate Severity Analytics Report</h2>
                <button class="modal-close" onclick="closeModal('generate-report-modal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Barangay *</label>
                    <select class="form-select" name="barangay_id" required>
                        <option value="">Select Barangay</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Route (Optional)</label>
                    <select class="form-select" name="route_id">
                        <option value="">All Routes</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?php echo $route['id']; ?>">
                                <?php echo htmlspecialchars($route['route_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Analytics Date *</label>
                    <input type="date" class="form-input" name="analytics_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Period Type *</label>
                    <select class="form-select" name="period_type" required>
                        <option value="Monthly" selected>Monthly</option>
                        <option value="Weekly">Weekly</option>
                        <option value="Quarterly">Quarterly</option>
                        <option value="Yearly">Yearly</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeModal('generate-report-modal')">Cancel</button>
                    <button type="submit" name="generate_report" class="primary-button">Generate Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Add Prone Area Modal -->
    <div class="modal" id="add-prone-area-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Add Accident-Prone Area</h2>
                <button class="modal-close" onclick="closeModal('add-prone-area-modal')">&times;</button>
            </div>
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Location Name *</label>
                    <input type="text" class="form-input" name="location_name" required placeholder="e.g., Main Street Intersection">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Location Details *</label>
                    <input type="text" class="form-input" name="location" required placeholder="e.g., Main St & 1st Ave">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Coordinates</label>
                    <div style="display: flex; gap: 12px;">
                        <input type="text" class="form-input" name="latitude" placeholder="Latitude" pattern="[-]?\d+\.?\d*">
                        <input type="text" class="form-input" name="longitude" placeholder="Longitude" pattern="[-]?\d+\.?\d*">
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Barangay *</label>
                    <select class="form-select" name="barangay_id" required>
                        <option value="">Select Barangay</option>
                        <?php foreach ($barangays as $barangay): ?>
                            <option value="<?php echo $barangay['id']; ?>">
                                <?php echo htmlspecialchars($barangay['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Route (Optional)</label>
                    <select class="form-select" name="route_id">
                        <option value="">Select Route</option>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?php echo $route['id']; ?>">
                                <?php echo htmlspecialchars($route['route_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Area Type *</label>
                    <select class="form-select" name="area_type" required>
                        <option value="Intersection">Intersection</option>
                        <option value="School Zone">School Zone</option>
                        <option value="Market Area">Market Area</option>
                        <option value="Sharp Curve">Sharp Curve</option>
                        <option value="Narrow Road">Narrow Road</option>
                        <option value="Bridge">Bridge</option>
                        <option value="Terminal">Terminal</option>
                        <option value="Loading Zone">Loading Zone</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Risk Level *</label>
                    <select class="form-select" name="risk_level" required>
                        <option value="Low">Low</option>
                        <option value="Medium">Medium</option>
                        <option value="High" selected>High</option>
                        <option value="Critical">Critical</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Common Causes</label>
                    <textarea class="form-textarea" name="common_causes" placeholder="Describe common causes of incidents..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Safety Measures</label>
                    <textarea class="form-textarea" name="safety_measures" placeholder="Describe existing safety measures..."></textarea>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Improvement Status *</label>
                    <select class="form-select" name="improvement_status" required>
                        <option value="Pending">Pending</option>
                        <option value="Planned">Planned</option>
                        <option value="In Progress">In Progress</option>
                        <option value="Completed">Completed</option>
                        <option value="Monitoring">Monitoring</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea class="form-textarea" name="notes" placeholder="Additional notes..."></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeModal('add-prone-area-modal')">Cancel</button>
                    <button type="submit" name="add_prone_area" class="primary-button">Add Area</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Update Status Modal -->
    <div class="modal" id="update-status-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title">Update Report Status</h2>
                <button class="modal-close" onclick="closeModal('update-status-modal')">&times;</button>
            </div>
            <form method="POST" action="" id="status-form">
                <input type="hidden" name="analytics_id" id="analytics-id">
                
                <div class="form-group">
                    <label class="form-label">Status *</label>
                    <select class="form-select" name="status" id="status-select" required>
                        <option value="Draft">Draft</option>
                        <option value="Published">Published</option>
                        <option value="Archived">Archived</option>
                    </select>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="secondary-button" onclick="closeModal('update-status-modal')">Cancel</button>
                    <button type="submit" name="update_status" class="primary-button">Update Status</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Initialize variables from PHP
        const incidentStats = <?php echo json_encode($incident_stats); ?>;
        const analyticsData = <?php echo json_encode($analytics_data); ?>;
        const proneAreas = <?php echo json_encode($prone_areas); ?>;
        
        // Show success message if exists
        <?php if (isset($_SESSION['success_message'])): ?>
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: '<?php echo $_SESSION['success_message']; ?>',
                timer: 3000,
                showConfirmButton: false
            });
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        // Toggle submenu visibility
        function toggleSubmenu(id) {
            const submenu = document.getElementById(id);
            const arrow = document.querySelector(`#${id}`).previousElementSibling.querySelector('.dropdown-arrow');
            
            submenu.classList.toggle('active');
            arrow.classList.toggle('rotated');
        }
        
        // Tab switching
        function switchTab(tabName) {
            const url = new URL(window.location.href);
            url.searchParams.set('tab', tabName);
            window.location.href = url.toString();
        }
        
        // Filter functions
        function applyFilters() {
            const barangay = document.getElementById('barangay-filter').value;
            const dateFrom = document.getElementById('date-from').value;
            const dateTo = document.getElementById('date-to').value;
            const period = document.getElementById('period-filter').value;
            
            const url = new URL(window.location.href);
            if (barangay) url.searchParams.set('barangay', barangay);
            if (dateFrom) url.searchParams.set('date_from', dateFrom);
            if (dateTo) url.searchParams.set('date_to', dateTo);
            if (period) url.searchParams.set('period', period);
            
            window.location.href = url.toString();
        }
        
        function clearFilters() {
            window.location.href = 'severity_safety_review.php?tab=<?php echo $active_tab; ?>';
        }
        
        // Modal functions
        function openGenerateReportModal() {
            document.getElementById('generate-report-modal').style.display = 'flex';
        }
        
        function openAddProneAreaModal() {
            document.getElementById('add-prone-area-modal').style.display = 'flex';
        }
        
        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Action functions
        function viewAnalytics(id) {
            Swal.fire({
                title: 'Analytics Report Details',
                html: 'Loading report details...',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            
            // In a real implementation, you would fetch the data via AJAX
            // For now, show a simple view
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    title: 'Report Details',
                    html: 'Report ID: ' + id + '<br>Detailed view would be implemented here.',
                    icon: 'info'
                });
            }, 1000);
        }
        
        function editAnalytics(id) {
            Swal.fire({
                title: 'Edit Analytics Report',
                text: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        function updateStatus(id) {
            document.getElementById('analytics-id').value = id;
            document.getElementById('update-status-modal').style.display = 'flex';
        }
        
        function viewProneArea(id) {
            Swal.fire({
                title: 'Accident-Prone Area Details',
                html: 'Loading area details...',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            
            // In a real implementation, you would fetch the data via AJAX
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    title: 'Area Details',
                    html: 'Area ID: ' + id + '<br>Detailed view would be implemented here.',
                    icon: 'info'
                });
            }, 1000);
        }
        
        function editProneArea(id) {
            Swal.fire({
                title: 'Edit Accident-Prone Area',
                text: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        function viewSafetyReview(id) {
            Swal.fire({
                title: 'Safety Review Details',
                html: 'Loading review details...',
                showConfirmButton: false,
                allowOutsideClick: false
            });
            
            // In a real implementation, you would fetch the data via AJAX
            setTimeout(() => {
                Swal.close();
                Swal.fire({
                    title: 'Review Details',
                    html: 'Review ID: ' + id + '<br>Detailed view would be implemented here.',
                    icon: 'info'
                });
            }, 1000);
        }
        
        function editSafetyReview(id) {
            Swal.fire({
                title: 'Edit Safety Review',
                text: 'This feature is under development.',
                icon: 'info'
            });
        }
        
        function exportData() {
            const tab = '<?php echo $active_tab; ?>';
            let exportUrl = '';
            
            switch(tab) {
                case 'analytics':
                    exportUrl = 'export_analytics.php';
                    break;
                case 'prone_areas':
                    exportUrl = 'export_prone_areas.php';
                    break;
                case 'safety_reviews':
                    exportUrl = 'export_safety_reviews.php';
                    break;
                default:
                    exportUrl = 'export_all.php';
            }
            
            // Add current filters to export URL
            const params = new URLSearchParams(window.location.search);
            Swal.fire({
                title: 'Export Data',
                text: 'Data export feature would be implemented here.',
                icon: 'info'
            });
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
        window.addEventListener('click', function(event) {
            const modals = document.querySelectorAll('.modal');
            modals.forEach(modal => {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });
        });
        
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
    </script>
</body>
</html>