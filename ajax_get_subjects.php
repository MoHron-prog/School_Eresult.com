<?php
require_once 'config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$level_id = isset($_GET['level_id']) ? intval($_GET['level_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;

if (!$level_id) {
    echo json_encode([]);
    exit;
}

try {
    if ($is_admin) {
        // Admin sees all active subjects for the level
        $stmt = $pdo->prepare("
            SELECT s.id, s.code, s.name
            FROM subjects s
            WHERE s.level_id = ?
            AND s.status = 'active'
            ORDER BY s.name
        ");
        $stmt->execute([$level_id]);
    } else {
        // Teacher sees only assigned subjects for the level
        $stmt = $pdo->prepare("
            SELECT DISTINCT s.id, s.code, s.name
            FROM teacher_subjects ts
            JOIN subjects s ON ts.subject_id = s.id
            WHERE ts.teacher_id = ?
            AND s.level_id = ?
            AND s.status = 'active'
            ORDER BY s.name
        ");
        $stmt->execute([$user_id, $level_id]);
    }
    
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($subjects);
} catch (Exception $e) {
    error_log("Error fetching subjects: " . $e->getMessage());
    echo json_encode([]);
}
?>