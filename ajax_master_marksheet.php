<?php
// ajax_master_marksheet.php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_terms':
            $academic_year_id = $_GET['academic_year'] ?? 0;
            
            $stmt = $pdo->prepare("
                SELECT id, term_name 
                FROM academic_terms 
                WHERE academic_year_id = ? AND status = 'active' 
                ORDER BY id
            ");
            $stmt->execute([$academic_year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'terms' => $terms]);
            break;

        case 'get_classes_by_level':
            $level_id = $_GET['level'] ?? 0;
            $teacher_id = $_GET['teacher_id'] ?? $user_id;
            
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM classes 
                    WHERE level_id = ? AND status = 'active' 
                    ORDER BY name
                ");
                $stmt->execute([$level_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.name
                    FROM classes c
                    LEFT JOIN teacher_classes tc ON tc.class_id = c.id
                    LEFT JOIN teacher_subjects ts ON ts.level_id = c.level_id
                    WHERE c.level_id = ? 
                        AND c.status = 'active' 
                        AND (tc.teacher_id = ? OR ts.teacher_id = ?)
                    ORDER BY c.name
                ");
                $stmt->execute([$level_id, $teacher_id, $teacher_id]);
            }
            
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
            break;

        case 'get_streams_by_class':
            $class_id = $_GET['class'] ?? 0;
            $teacher_id = $_GET['teacher_id'] ?? $user_id;
            
            if ($is_admin) {
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
                    FROM streams s
                    JOIN teacher_classes tc ON tc.class_id = s.class_id
                    WHERE s.class_id = ? 
                        AND s.status = 'active' 
                        AND tc.teacher_id = ?
                    ORDER BY s.name
                ");
                $stmt->execute([$class_id, $teacher_id]);
            }
            
            $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'streams' => $streams]);
            break;

        case 'get_subjects_by_level':
            $level_id = $_GET['level'] ?? 0;
            $teacher_id = $_GET['teacher_id'] ?? $user_id;
            
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT id, code, name, category 
                    FROM subjects 
                    WHERE level_id = ? AND status = 'active' 
                    ORDER BY category = 'compulsory' DESC, 
                             category = 'principal' DESC, 
                             code
                ");
                $stmt->execute([$level_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.code, s.name, s.category
                    FROM subjects s
                    JOIN teacher_subjects ts ON ts.subject_id = s.id
                    WHERE s.level_id = ? 
                        AND s.status = 'active' 
                        AND ts.teacher_id = ?
                    ORDER BY s.category = 'compulsory' DESC, 
                             s.category = 'principal' DESC, 
                             s.code
                ");
                $stmt->execute([$level_id, $teacher_id]);
            }
            
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'subjects' => $subjects]);
            break;

        case 'get_class_name':
            $class_id = $_GET['class_id'] ?? 0;
            
            $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
            $stmt->execute([$class_id]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'name' => $class['name'] ?? '']);
            break;

        case 'get_stream_name':
            $stream_id = $_GET['stream_id'] ?? 0;
            
            $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
            $stmt->execute([$stream_id]);
            $stream = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'name' => $stream['name'] ?? '']);
            break;

        case 'get_term_name':
            $term_id = $_GET['term_id'] ?? 0;
            
            $stmt = $pdo->prepare("SELECT term_name FROM academic_terms WHERE id = ?");
            $stmt->execute([$term_id]);
            $term = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'name' => $term['term_name'] ?? '']);
            break;

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log("AJAX Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>