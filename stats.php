<?php
// [file name]: stats.php
require_once 'config.php';
session_start();

// Only allow admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

try {
    // Helper function for counting
    function safeCount($pdo, $table, $condition = "1=1") {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $condition");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log("Count error for $table: " . $e->getMessage());
            return 0;
        }
    }

    // Student statistics
    $total_students = safeCount($pdo, 'students', "status = 'active'");
    $male_students = safeCount($pdo, 'students', "status = 'active' AND sex = 'male'");
    $female_students = safeCount($pdo, 'students', "status = 'active' AND sex = 'female'");
    $day_students = safeCount($pdo, 'students', "status = 'active' AND status_type = 'Day'");
    $boarding_students = safeCount($pdo, 'students', "status = 'active' AND status_type = 'Boarding'");
    
    // Level-based counts
    $o_level_students = 0;
    $a_level_students = 0;
    try {
        $stmt = $pdo->prepare("
            SELECT l.name, COUNT(s.id) as count 
            FROM students s 
            JOIN levels l ON s.level_id = l.id 
            WHERE s.status = 'active' 
            GROUP BY l.name
        ");
        $stmt->execute();
        $levelCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($levelCounts as $level) {
            if (stripos($level['name'], 'O Level') !== false || $level['name'] === 'O Level') {
                $o_level_students = (int)$level['count'];
            } elseif (stripos($level['name'], 'A Level') !== false || $level['name'] === 'A Level') {
                $a_level_students = (int)$level['count'];
            }
        }
    } catch (PDOException $e) {
        error_log("Error counting levels: " . $e->getMessage());
    }

    // Calculate percentages
    $male_percentage = $total_students > 0 ? round(($male_students / $total_students) * 100, 1) : 0;
    $female_percentage = $total_students > 0 ? round(($female_students / $total_students) * 100, 1) : 0;

    // Prepare response
    $stats = [
        'success' => true,
        'total_students' => $total_students,
        'male_students' => $male_students,
        'female_students' => $female_students,
        'male_percentage' => $male_percentage,
        'female_percentage' => $female_percentage,
        'day_students' => $day_students,
        'boarding_students' => $boarding_students,
        'o_level_students' => $o_level_students,
        'a_level_students' => $a_level_students,
        'archived_students' => safeCount($pdo, 'archived_students'),
        'recent_students' => safeCount($pdo, 'students', "status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"),
        'total_teachers' => safeCount($pdo, 'users', "role = 'teacher' AND status = 'active'"),
        'total_classes' => safeCount($pdo, 'classes'),
        'total_streams' => safeCount($pdo, 'streams'),
        'total_levels' => safeCount($pdo, 'levels'),
        'total_subjects' => safeCount($pdo, 'subjects'),
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($stats);
    
} catch (Exception $e) {
    error_log("Stats API error: " . $e->getMessage());
    echo json_encode([
        'error' => 'Failed to fetch statistics',
        'message' => $e->getMessage(),
        'success' => false
    ]);
}
?>