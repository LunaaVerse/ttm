<?php
session_start();
require_once '../../config/db_connection.php';

if (!isset($_GET['id'])) {
    die('Route ID required');
}

$route_id = $_GET['id'];

try {
    $sql = "SELECT r.*, b.name as barangay_name, u.first_name as created_by_name,
            (SELECT COUNT(*) FROM route_stops WHERE route_id = r.id) as stop_count,
            (SELECT COUNT(*) FROM route_assignments WHERE route_id = r.id AND status = 'Active') as active_vehicles,
            (SELECT COUNT(*) FROM route_complaints WHERE route_id = r.id AND status != 'Resolved') as pending_complaints,
            (SELECT COUNT(*) FROM violation_records WHERE route_id = r.id AND status = 'Verified') as recent_violations
            FROM tricycle_routes r 
            LEFT JOIN barangays b ON r.barangay_id = b.id 
            LEFT JOIN users u ON r.created_by = u.id 
            WHERE r.id = :id";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $route_id]);
    $route = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$route) {
        die('Route not found');
    }
    
    // Get stops
    $sql = "SELECT * FROM route_stops WHERE route_id = :route_id ORDER BY stop_number";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['route_id' => $route_id]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get assignments
    $sql = "SELECT ra.*, o.operator_code, o.vehicle_number, d.driver_code, 
            CONCAT(d.first_name, ' ', d.last_name) as driver_name
            FROM route_assignments ra
            JOIN tricycle_operators o ON ra.operator_id = o.id
            LEFT JOIN tricycle_drivers d ON ra.driver_id = d.id
            WHERE ra.route_id = :route_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['route_id' => $route_id]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get restrictions
    $sql = "SELECT * FROM route_restrictions WHERE route_id = :route_id OR barangay_id = :barangay_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['route_id' => $route_id, 'barangay_id' => $route['barangay_id']]);
    $restrictions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ?>
    <div style="max-width: 100%;">
        <div style="display: flex; align-items: center; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 2px solid #e5e7eb;">
            <div style="flex: 1;">
                <h2 style="margin: 0 0 5px 0; color: #1f2937;"><?php echo htmlspecialchars($route['route_name']); ?></h2>
                <div style="display: flex; gap: 15px; color: #6b7280; font-size: 14px;">
                    <span><strong>Code:</strong> <?php echo htmlspecialchars($route['route_code']); ?></span>
                    <span><strong>Barangay:</strong> <?php echo htmlspecialchars($route['barangay_name']); ?></span>
                    <span><strong>Status:</strong> 
                        <span style="padding: 2px 8px; border-radius: 4px; background-color: 
                            <?php echo $route['status'] == 'Active' ? '#d1fae5' : 
                                     ($route['status'] == 'Suspended' ? '#fee2e2' : '#fef3c7'); ?>;">
                            <?php echo $route['status']; ?>
                        </span>
                    </span>
                </div>
            </div>
            <div style="background-color: <?php echo $route['color_code']; ?>; width: 20px; height: 20px; border-radius: 4px;"></div>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <div>
                <h3 style="margin-bottom: 10px; color: #374151;">Route Information</h3>
                <table style="width: 100%; font-size: 14px;">
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280; width: 40%;">Start Point:</td>
                        <td style="padding: 8px 0; font-weight: 500;"><?php echo htmlspecialchars($route['start_point']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">End Point:</td>
                        <td style="padding: 8px 0; font-weight: 500;"><?php echo htmlspecialchars($route['end_point']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Distance:</td>
                        <td style="padding: 8px 0; font-weight: 500;"><?php echo $route['distance_km']; ?> km</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Travel Time:</td>
                        <td style="padding: 8px 0; font-weight: 500;"><?php echo $route['estimated_time_min']; ?> minutes</td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Regular Fare:</td>
                        <td style="padding: 8px 0; font-weight: 500;">₱<?php echo number_format($route['fare_regular'], 2); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Special Fare:</td>
                        <td style="padding: 8px 0; font-weight: 500;">₱<?php echo number_format($route['fare_special'], 2); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Operating Hours:</td>
                        <td style="padding: 8px 0; font-weight: 500;">
                            <?php echo date('g:i A', strtotime($route['operating_hours_start'])); ?> - 
                            <?php echo date('g:i A', strtotime($route['operating_hours_end'])); ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 0; color: #6b7280;">Max Vehicles:</td>
                        <td style="padding: 8px 0; font-weight: 500;"><?php echo $route['max_vehicles']; ?></td>
                    </tr>
                </table>
            </div>
            
            <div>
                <h3 style="margin-bottom: 10px; color: #374151;">Route Statistics</h3>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                    <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #3b82f6;"><?php echo $route['stop_count']; ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Total Stops</div>
                    </div>
                    <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #10b981;"><?php echo $route['active_vehicles']; ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Active Vehicles</div>
                    </div>
                    <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #f59e0b;"><?php echo $route['pending_complaints']; ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Pending Complaints</div>
                    </div>
                    <div style="background: #f3f4f6; padding: 15px; border-radius: 8px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #ef4444;"><?php echo $route['recent_violations']; ?></div>
                        <div style="font-size: 12px; color: #6b7280;">Recent Violations</div>
                    </div>
                </div>
                
                <?php if ($route['description']): ?>
                    <div style="margin-top: 15px;">
                        <h4 style="margin-bottom: 5px; color: #374151;">Description</h4>
                        <p style="font-size: 14px; color: #6b7280;"><?php echo htmlspecialchars($route['description']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($stops): ?>
            <div style="margin-bottom: 20px;">
                <h3 style="margin-bottom: 10px; color: #374151;">Route Stops (<?php echo count($stops); ?>)</h3>
                <div style="background: #f9fafb; border-radius: 8px; padding: 15px; max-height: 200px; overflow-y: auto;">
                    <div style="display: flex; flex-direction: column; gap: 8px;">
                        <?php foreach ($stops as $stop): ?>
                            <div style="display: flex; align-items: center; padding: 10px; background: white; border-radius: 6px; border-left: 4px solid #3b82f6;">
                                <div style="background: #3b82f6; color: white; width: 24px; height: 24px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; margin-right: 10px;">
                                    <?php echo $stop['stop_number']; ?>
                                </div>
                                <div style="flex: 1;">
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($stop['stop_name']); ?></div>
                                    <div style="font-size: 12px; color: #6b7280;"><?php echo htmlspecialchars($stop['location']); ?></div>
                                </div>
                                <div style="font-size: 12px; color: #6b7280;">
                                    <?php echo $stop['stop_type']; ?>
                                    <?php if ($stop['estimated_time_from_start']): ?> | <?php echo $stop['estimated_time_from_start']; ?> mins<?php endif; ?>
                                    <?php if ($stop['fare_from_start']): ?> | ₱<?php echo $stop['fare_from_start']; ?><?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if ($assignments): ?>
            <div style="margin-bottom: 20px;">
                <h3 style="margin-bottom: 10px; color: #374151;">Vehicle Assignments (<?php echo count($assignments); ?>)</h3>
                <div style="background: #f9fafb; border-radius: 8px; padding: 15px; max-height: 200px; overflow-y: auto;">
                    <table style="width: 100%; font-size: 14px;">
                        <thead>
                            <tr style="border-bottom: 1px solid #e5e7eb;">
                                <th style="padding: 8px; text-align: left; color: #6b7280;">Vehicle</th>
                                <th style="padding: 8px; text-align: left; color: #6b7280;">Operator</th>
                                <th style="padding: 8px; text-align: left; color: #6b7280;">Driver</th>
                                <th style="padding: 8px; text-align: left; color: #6b7280;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assignments as $assignment): ?>
                                <tr style="border-bottom: 1px solid #f3f4f6;">
                                    <td style="padding: 8px;"><?php echo htmlspecialchars($assignment['vehicle_number']); ?></td>
                                    <td style="padding: 8px;"><?php echo htmlspecialchars($assignment['operator_code']); ?></td>
                                    <td style="padding: 8px;">
                                        <?php if ($assignment['driver_name']): ?>
                                            <?php echo htmlspecialchars($assignment['driver_code'] . ' - ' . $assignment['driver_name']); ?>
                                        <?php else: ?>
                                            <span style="color: #9ca3af;">Not assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="padding: 8px;">
                                        <span style="padding: 2px 8px; border-radius: 4px; background-color: 
                                            <?php echo $assignment['status'] == 'Active' ? '#d1fae5' : '#fef3c7'; ?>; 
                                            color: <?php echo $assignment['status'] == 'Active' ? '#065f46' : '#92400e'; ?>;">
                                            <?php echo $assignment['status']; ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
        
        <div style="color: #6b7280; font-size: 12px; text-align: right; margin-top: 20px; padding-top: 15px; border-top: 1px solid #e5e7eb;">
            Created by: <?php echo htmlspecialchars($route['created_by_name']); ?> | 
            Created on: <?php echo date('M d, Y', strtotime($route['created_at'])); ?>
        </div>
    </div>
    <?php
    
} catch (Exception $e) {
    echo '<div style="color: #ef4444; padding: 20px; text-align: center;">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>