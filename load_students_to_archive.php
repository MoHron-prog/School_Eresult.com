<?php
/**
 * load_students_to_archive.php
 * AJAX endpoint to load students based on filter criteria for archiving
 */

require_once 'config.php';

// Verify admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

// Validate required parameters
$academic_year_id = isset($_GET['academic_year_id']) ? (int)$_GET['academic_year_id'] : 0;
$level_id = isset($_GET['level_id']) ? (int)$_GET['level_id'] : 0;
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$stream_id = isset($_GET['stream_id']) ? (int)$_GET['stream_id'] : 0;

if (!$academic_year_id || !$level_id || !$class_id || !$stream_id) {
    echo json_encode(['students' => [], 'count' => 0]);
    exit;
}

try {
    // Fetch students matching the filter criteria
    $query = "
        SELECT 
            s.id,
            s.student_id,
            s.surname,
            s.other_names,
            s.sex,
            s.date_of_birth,
            s.nationality,
            s.home_district,
            s.photo,
            s.status_type,
            s.created_at,
            s.updated_at,
            l.name as level_name,
            c.name as class_name,
            st.name as stream_name,
            ay.year_name as academic_year_name,
            GROUP_CONCAT(DISTINCT sub.code ORDER BY sub.code SEPARATOR ', ') as subjects
        FROM students s
        JOIN levels l ON s.level_id = l.id
        JOIN classes c ON s.class_id = c.id
        JOIN streams st ON s.stream_id = st.id
        JOIN academic_years ay ON s.academic_year_id = ay.id
        LEFT JOIN student_subjects ss ON s.id = ss.student_id
        LEFT JOIN subjects sub ON ss.subject_id = sub.id
        WHERE s.academic_year_id = :academic_year_id
            AND s.level_id = :level_id
            AND s.class_id = :class_id
            AND s.stream_id = :stream_id
            AND s.status = 'active'
        GROUP BY s.id
        ORDER BY s.student_id ASC
    ";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':academic_year_id' => $academic_year_id,
        ':level_id' => $level_id,
        ':class_id' => $class_id,
        ':stream_id' => $stream_id
    ]);
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get count statistics
    $count_query = "
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN sex = 'male' THEN 1 ELSE 0 END) as male_count,
            SUM(CASE WHEN sex = 'female' THEN 1 ELSE 0 END) as female_count,
            SUM(CASE WHEN status_type = 'Boarding' THEN 1 ELSE 0 END) as boarding_count,
            SUM(CASE WHEN status_type = 'Day' THEN 1 ELSE 0 END) as day_count
        FROM students
        WHERE academic_year_id = :academic_year_id
            AND level_id = :level_id
            AND class_id = :class_id
            AND stream_id = :stream_id
            AND status = 'active'
    ";
    
    $stmt_count = $pdo->prepare($count_query);
    $stmt_count->execute([
        ':academic_year_id' => $academic_year_id,
        ':level_id' => $level_id,
        ':class_id' => $class_id,
        ':stream_id' => $stream_id
    ]);
    $stats = $stmt_count->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'students' => $students,
        'count' => count($students),
        'stats' => $stats
    ]);
    
} catch (PDOException $e) {
    error_log("Error loading students for archive: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error occurred while loading students'
    ]);
}