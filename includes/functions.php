<?php
require_once '../vendor/autoload.php'; // For PHPMailer

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

function generate_verification_code() {
    return sprintf("%06d", mt_rand(1, 999999));
}

function send_verification_email($email, $name, $verification_code) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Set your SMTP server
        $mail->SMTPAuth = true;
        $mail->Username = 'ttmd.4104@gmail.com'; // Your email
        $mail->Password = 'whle pcfu zeoq znew'; // Your app password
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('ttmd.4104@gmail.com', 'Traffic & Transport Management Department');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Email Verification - Traffic & Transport Management Department';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f9fff9; border: 1px solid #e0f0e0; border-radius: 10px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #22c55e, #16a34a); padding: 30px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚦 Traffic & Transport Management</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Sustainable Mobility Solutions</p>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #15803d; margin-top: 0;'>Email Verification</h2>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>Thank you for registering with <strong>Traffic & Transport Management Department</strong>. Please use the verification code below to complete your registration:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <div style='display: inline-block; padding: 15px 30px; background: #dcfce7; border: 2px dashed #22c55e; border-radius: 8px; font-family: monospace;'>
                            <h3 style='color: #15803d; font-size: 28px; margin: 0; letter-spacing: 3px;'>$verification_code</h3>
                        </div>
                    </div>
                    
                    <div style='background: #f0fdf4; padding: 15px; border-radius: 5px; border-left: 4px solid #22c55e;'>
                        <p style='margin: 0; color: #166534; font-size: 14px;'>
                            <strong>⚠️ Important:</strong> This code will expire in 15 minutes.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 25px;'>
                        If you did not request this registration, please ignore this email.
                    </p>
                </div>
                
                <div style='background: #f0fdf4; padding: 20px; text-align: center; border-top: 1px solid #dcfce7;'>
                    <p style='margin: 0; color: #166534;'>
                        <strong>🌱 Green Initiative:</strong> Help us reduce traffic congestion and promote sustainable transportation.
                    </p>
                    <p style='margin: 10px 0 0 0; color: #666; font-size: 12px;'>
                        Best regards,<br>
                        <strong>Traffic & Transport Management Department Team</strong><br>
                        Creating smarter, greener mobility solutions
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Hello $name,\n\nThank you for registering with Traffic & Transport Management Department. Please use the verification code below to complete your registration:\n\nVerification Code: $verification_code\n\nThis code will expire in 15 minutes.\n\nIf you did not request this registration, please ignore this email.\n\n🌱 Green Initiative: Help us reduce traffic congestion and promote sustainable transportation.\n\nBest regards,\nTraffic & Transport Management Department Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to send verification email with link (for login scenario)
function send_verification_email_with_link($email, $name, $verification_code) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ttmd.4104@gmail.com';
        $mail->Password = 'whle pcfu zeoq znew';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('ttmd.4104@gmail.com', 'Traffic & Transport Management Department');
        $mail->addAddress($email, $name);
        
        // Create verification link
        $verification_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/verify_email_login.php?email=" . urlencode($email) . "&code=" . $verification_code;
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Complete Your Login - Email Verification Required';
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f9fff9; border: 1px solid #e0f0e0; border-radius: 10px; overflow: hidden;'>
                <div style='background: linear-gradient(135deg, #22c55e, #16a34a); padding: 30px; text-align: center; color: white;'>
                    <h1 style='margin: 0; font-size: 28px;'>🚦 Traffic & Transport Management</h1>
                    <p style='margin: 10px 0 0 0; opacity: 0.9;'>Sustainable Mobility Solutions</p>
                </div>
                
                <div style='padding: 30px;'>
                    <h2 style='color: #15803d; margin-top: 0;'>Complete Your Login</h2>
                    <p>Hello <strong>$name</strong>,</p>
                    <p>We noticed you tried to login but your email address is not yet verified. To complete your login and access our sustainable transport services, please verify your email address:</p>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='$verification_link' style='display: inline-block; padding: 15px 40px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; border: none; cursor: pointer;'>
                            🚀 Verify Email Address
                        </a>
                    </div>
                    
                    <div style='background: #f0fdf4; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <p style='margin: 0 0 15px 0; color: #166534; font-weight: bold;'>Alternative Verification Method:</p>
                        <p style='margin: 0;'>Or copy and paste this link in your browser:</p>
                        <p style='margin: 10px 0; background: #dcfce7; padding: 12px; border-radius: 5px; word-break: break-all; font-size: 12px; color: #166534;'>
                            <a href='$verification_link' style='color: #166534;'>$verification_link</a>
                        </p>
                        
                        <p style='margin: 15px 0 0 0;'>Your verification code is:</p>
                        <div style='text-align: center; margin: 15px 0;'>
                            <div style='display: inline-block; padding: 12px 25px; background: #dcfce7; border: 2px dashed #22c55e; border-radius: 6px;'>
                                <strong style='font-size: 24px; color: #15803d; letter-spacing: 3px;'>$verification_code</strong>
                            </div>
                        </div>
                    </div>
                    
                    <div style='background: #fffbeb; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b;'>
                        <p style='margin: 0; color: #92400e; font-size: 14px;'>
                            <strong>⏰ Time Sensitive:</strong> This verification link will expire in 30 minutes.
                        </p>
                    </div>
                    
                    <p style='color: #666; font-size: 14px; margin-top: 25px;'>
                        If you didn't try to login to your account, please ignore this email to help us maintain security and reduce unnecessary digital traffic.
                    </p>
                </div>
                
                <div style='background: #f0fdf4; padding: 20px; text-align: center; border-top: 1px solid #dcfce7;'>
                    <p style='margin: 0; color: #166534; font-size: 14px;'>
                        <strong>🌿 Eco-Friendly Tip:</strong> Consider using public transport, cycling, or walking for shorter journeys to reduce carbon emissions.
                    </p>
                    <p style='margin: 10px 0 0 0; color: #666; font-size: 12px;'>
                        Best regards,<br>
                        <strong>Traffic & Transport Management Department Team</strong><br>
                        Driving sustainable mobility forward
                    </p>
                </div>
            </div>
        ";
        
        $mail->AltBody = "Hello $name,\n\nWe noticed you tried to login but your email address is not yet verified. To complete your login and access our sustainable transport services, please verify your email address.\n\nVerification Code: $verification_code\n\nOr visit this link: $verification_link\n\nEnter this code on the verification page to complete your login.\n\nThis verification code will expire in 30 minutes.\n\nIf you didn't try to login to your account, please ignore this email to help us maintain security and reduce unnecessary digital traffic.\n\n🌿 Eco-Friendly Tip: Consider using public transport, cycling, or walking for shorter journeys to reduce carbon emissions.\n\nBest regards,\nTraffic & Transport Management Department Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send password reset email
 */
function send_password_reset_email($email, $name, $reset_link) {
    $mail = new PHPMailer(true);
    
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'ttmd.4104@gmail.com';
        $mail->Password = 'whle pcfu zeoq znew';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('ttmd.4104@gmail.com', 'Traffic & Transport Management Department');
        $mail->addAddress($email, $name);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request - Traffic & Transport Management Department';
        $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
                    .container { max-width: 600px; margin: 0 auto; background: #ffffff; }
                    .header { background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 30px; text-align: center; }
                    .content { background: #f9fff9; padding: 30px; border: 1px solid #e0f0e0; border-top: none; }
                    .button { display: inline-block; padding: 15px 30px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 16px; margin: 20px 0; }
                    .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; padding: 20px; background: #f0fdf4; }
                    .warning { background: #fffbeb; padding: 15px; border-radius: 5px; border-left: 4px solid #f59e0b; margin: 20px 0; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>🚦 Traffic & Transport Management</h1>
                        <h2>Password Reset Request</h2>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>$name</strong>,</p>
                        <p>We received a request to reset your password for your Traffic & Transport Management Department account.</p>
                        <p>Click the button below to create a new password:</p>
                        <p style='text-align: center;'>
                            <a href='$reset_link' class='button'>Reset Your Password</a>
                        </p>
                        
                        <div class='warning'>
                            <p style='margin: 0; color: #92400e;'><strong>⏰ Time Sensitive:</strong> This link will expire in 1 hour for security reasons.</p>
                        </div>
                        
                        <p>If you didn't request this reset, please ignore this email. Your password will remain unchanged.</p>
                        
                        <div style='background: #f0fdf4; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                            <p style='margin: 0; color: #166534; font-size: 14px;'>
                                <strong>🌿 Eco-Friendly Reminder:</strong> Consider sustainable transport options for your daily commute!
                            </p>
                        </div>
                        
                        <p>Stay safe,<br><strong>The Traffic & Transport Management Department Team</strong></p>
                    </div>
                    <div class='footer'>
                        <p>© 2025 Traffic & Transport Management Department. All rights reserved.</p>
                        <p>Driving sustainable mobility forward</p>
                    </div>
                </div>
            </body>
            </html>
        ";
        
        $mail->AltBody = "Hello $name,\n\nWe received a request to reset your password for your Traffic & Transport Management Department account.\n\nPlease click the following link to reset your password:\n$reset_link\n\nThis link will expire in 1 hour for security reasons.\n\nIf you didn't request this reset, please ignore this email. Your password will remain unchanged.\n\n🌿 Eco-Friendly Reminder: Consider sustainable transport options for your daily commute!\n\nStay safe,\nThe Traffic & Transport Management Department Team";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Password reset email sending failed: " . $mail->ErrorInfo);
        return false;
    }
}

// Function to get user role for dashboard redirection
function getUserRoleDashboard($role) {
    switch($role) {
        case 'ADMIN':
            return '../admin/admin_dashboard.php';
        case 'TRAFFIC_MANAGER':
            return '../manager/manager_dashboard.php';
        case 'TRANSPORT_OFFICER':
            return '../officer/officer_dashboard.php';
        case 'USER':
        default:
            return '../user/user_dashboard.php';
    }
}

// Additional green-themed functions for traffic management
function calculate_carbon_savings($distance_km, $transport_mode) {
    $carbon_factors = [
        'car' => 0.21,        // kg CO2 per km
        'bus' => 0.09,        // kg CO2 per km
        'train' => 0.04,      // kg CO2 per km
        'bicycle' => 0,       // kg CO2 per km
        'walking' => 0,       // kg CO2 per km
        'electric_vehicle' => 0.05  // kg CO2 per km
    ];
    
    $default_car_emissions = $distance_km * $carbon_factors['car'];
    $chosen_emissions = $distance_km * ($carbon_factors[$transport_mode] ?? $carbon_factors['car']);
    
    return [
        'savings' => max(0, $default_car_emissions - $chosen_emissions),
        'chosen_emissions' => $chosen_emissions,
        'car_emissions' => $default_car_emissions
    ];
}

function get_green_transport_tip() {
    $tips = [
        "🚍 Use public transport - one bus can replace 30 cars on the road!",
        "🚲 Cycle for short trips - it's emission-free and great for your health!",
        "🚶 Walk when possible - zero emissions and completely free!",
        "🚗 Carpool with colleagues - reduce traffic and split costs!",
        "🌳 Plan efficient routes - combine multiple errands into one trip!",
        "⚡ Consider electric vehicles - cleaner and cheaper to run!",
        "🚆 Take the train for longer journeys - more efficient than driving!",
        "🕒 Travel off-peak - avoid congestion and reduce idle emissions!"
    ];
    
    return $tips[array_rand($tips)];
}

function calculate_traffic_efficiency_score($route_data) {
    $score = 100;
    
    // Deduct points based on traffic conditions
    if ($route_data['congestion_level'] > 0.7) $score -= 30;
    elseif ($route_data['congestion_level'] > 0.4) $score -= 15;
    
    // Add points for green routes
    if ($route_data['has_bike_lanes']) $score += 10;
    if ($route_data['near_public_transport']) $score += 15;
    if ($route_data['low_emission_zone']) $score += 20;
    
    // Consider route efficiency
    $efficiency_ratio = $route_data['direct_distance'] / max(1, $route_data['actual_distance']);
    $score *= $efficiency_ratio;
    
    return max(0, min(100, round($score)));
}
?>