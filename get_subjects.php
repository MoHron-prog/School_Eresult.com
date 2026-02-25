<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Validate required parameters
$required_params = ['level_id', 'class_id', 'stream_id'];
foreach ($required_params as $param) {
    if (!isset($_GET[$param]) || empty($_GET[$param])) {
        echo json_encode(['success' => false, 'message' => "{$param} is required"]);
        exit;
    }
}

$level_id = intval($_GET['level_id']);
$class_id = intval($_GET['class_id']);
$stream_id = intval($_GET['stream_id']);
$teacher_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

try {
    if (!$is_admin) {
        // For teachers, only show subjects they teach for this level
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.code, s.name
            FROM teacher_subjects ts
            JOIN subjects s ON ts.subject_id = s.id
            WHERE ts.teacher_id = ? 
            AND s.level_id = ?
            AND s.status = 'active'
            ORDER BY s.name
        ");
        $stmt->execute([$teacher_id, $level_id]);
    } else {
        // Admin can see all subjects for the level
        $stmt = $pdo->prepare("
            SELECT id, code, name
            FROM subjects
            WHERE level_id = ? 
            AND status = 'active'
            ORDER BY name
        ");
        $stmt->execute([$level_id]);
    }
    
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $subjects
    ]);
} catch (Exception $e) {
    error_log("Error fetching subjects: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading subjects'
    ]);
}
?>