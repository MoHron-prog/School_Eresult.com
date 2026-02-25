<?php
// file: ajax_comment_handler.php
// Description: Handles AJAX requests for academic comment management

require_once 'config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_head_teacher = ($user_role === 'head_teacher');
$is_class_teacher = ($user_role === 'teacher');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_terms_by_academic_year':
            $academic_year_id = $_GET['academic_year_id'] ?? 0;
            
            if (!$academic_year_id) {
                echo json_encode(['success' => false, 'message' => 'Academic Year ID required']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT id, term_name 
                FROM academic_terms 
                WHERE academic_year_id = ? 
                ORDER BY id
            ");
            $stmt->execute([$academic_year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'terms' => $terms]);
            break;
            
        case 'get_classes_by_level':
            $level_id = $_GET['level_id'] ?? 0;
            $academic_year_id = $_GET['academic_year_id'] ?? 0;
            
            if (!$level_id) {
                echo json_encode(['success' => false, 'message' => 'Level ID required']);
                exit;
            }
            
            if ($is_admin || $is_head_teacher) {
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM classes 
                    WHERE level_id = ? AND status = 'active'
                    ORDER BY name
                ");
                $stmt->execute([$level_id]);
            } else {
                // Class teacher - only their assigned classes
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.name
                    FROM teacher_classes tc
                    JOIN classes c ON tc.class_id = c.id
                    WHERE tc.teacher_id = ? 
                      AND c.level_id = ? 
                      AND tc.academic_year_id = ?
                      AND c.status = 'active'
                      AND tc.is_class_teacher = 1
                    ORDER BY c.name
                ");
                $stmt->execute([$user_id, $level_id, $academic_year_id]);
            }
            
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
            break;
            
        case 'get_streams_by_class':
            $class_id = $_GET['class_id'] ?? 0;
            $academic_year_id = $_GET['academic_year_id'] ?? 0;
            
            if (!$class_id) {
                echo json_encode(['success' => false, 'message' => 'Class ID required']);
                exit;
            }
            
            if ($is_admin || $is_head_teacher) {
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM streams 
                    WHERE class_id = ? AND status = 'active'
                    ORDER BY name
                ");
                $stmt->execute([$class_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT s.id, s.name
                    FROM teacher_classes tc
                    JOIN streams s ON tc.stream_id = s.id
                    WHERE tc.teacher_id = ? 
                      AND tc.class_id = ? 
                      AND tc.academic_year_id = ?
                      AND s.status = 'active'
                      AND tc.is_class_teacher = 1
                    ORDER BY s.name
                ");
                $stmt->execute([$user_id, $class_id, $academic_year_id]);
            }
            
            $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'streams' => $streams]);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("AJAX Comment Handler Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>