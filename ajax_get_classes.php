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
$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;

if (!$level_id || !$academic_year_id) {
    echo json_encode([]);
    exit;
}

try {
    if ($is_admin) {
        // Admin sees all active classes for the level
        $stmt = $pdo->prepare("
            SELECT c.id, c.name 
            FROM classes c
            WHERE c.level_id = ? 
            AND c.status = 'active'
            ORDER BY c.name
        ");
        $stmt->execute([$level_id]);
    } else {
        // Teacher sees only assigned classes
        $stmt = $pdo->prepare("
            SELECT DISTINCT c.id, c.name
            FROM teacher_classes tc
            JOIN classes c ON tc.class_id = c.id
            WHERE tc.teacher_id = ? 
            AND c.level_id = ?
            AND c.status = 'active'
            ORDER BY c.name
        ");
        $stmt->execute([$user_id, $level_id]);
    }
    
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($classes);
} catch (Exception $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    echo json_encode([]);
}
?>