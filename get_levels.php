<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$teacher_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');

try {
    if (!$is_admin) {
        // For teachers, only show levels they teach
        $stmt = $pdo->prepare("
            SELECT DISTINCT l.id, l.name
            FROM teacher_subjects ts
            JOIN subjects s ON ts.subject_id = s.id
            JOIN levels l ON s.level_id = l.id
            WHERE ts.teacher_id = ?
            UNION
            SELECT DISTINCT l.id, l.name
            FROM teacher_classes tc
            JOIN classes c ON tc.class_id = c.id
            JOIN levels l ON c.level_id = l.id
            WHERE tc.teacher_id = ?
            ORDER BY name
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
    } else {
        // Admin can see all active levels
        $stmt = $pdo->query("
            SELECT id, name 
            FROM levels 
            WHERE status = 'active' 
            ORDER BY name
        ");
    }
    
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $levels
    ]);
} catch (Exception $e) {
    error_log("Error fetching levels: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading levels'
    ]);
}
?>