<?php
require_once '../config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Validate POST parameters
$required_params = [
    'academic_year', 'term', 'level', 'class', 'stream', 'subject'
];

$errors = [];
$params = [];

foreach ($required_params as $param) {
    if (!isset($_POST[$param]) || !is_numeric($_POST[$param])) {
        $errors[] = "{$param} is required and must be numeric";
    } else {
        $params[$param] = intval($_POST[$param]);
    }
}

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(', ', $errors)]);
    exit;
}

try {
    // Get level name to determine which table to use
    $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
    $stmt->execute([$params['level']]);
    $level_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $level_name = $level_data['name'] ?? '';
    
    // Use whitelist approach for table names
    $table_name = ($level_name === 'A Level') ? 'a_level_marks' : 'o_level_marks';
    
    // Get student data
    $query = "
        SELECT
            s.id,
            s.student_id,
            s.surname,
            s.other_names,
            s.sex,
            s.photo,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Aol' THEN m.a1 END), 0) AS a1,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Aol' THEN m.a2 END), 0) AS a2,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Aol' THEN m.a3 END), 0) AS a3,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Aol' THEN m.a4 END), 0) AS a4,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Aol' THEN m.a5 END), 0) AS a5,
            COALESCE(MAX(CASE WHEN m.exam_type = 'Proj' THEN m.project_score END), 0) AS project_score,
            COALESCE(MAX(CASE WHEN m.exam_type = 'EoC' THEN m.eoc_score END), 0) AS eoc_score
        FROM students s
        LEFT JOIN {$table_name} m ON s.student_id = m.student_id
            AND m.academic_year_id = ?
            AND m.term_id = ?
            AND m.subject_id = ?
        WHERE s.class_id = ?
            AND s.stream_id = ?
            AND s.status = 'active'
        GROUP BY s.id, s.student_id, s.surname, s.other_names, s.sex, s.photo
        ORDER BY s.surname, s.other_names
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $params['academic_year'],
        $params['term'],
        $params['subject'],
        $params['class'],
        $params['stream']
    ]);
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $students,
        'count' => count($students)
    ]);
    
} catch (Exception $e) {
    error_log("Error fetching marksheet data: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading marksheet data'
    ]);
}
?>