<?php
// file: ajax_report_handler.php
// Description: Handles all AJAX requests for report card module
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

// Get action from POST request
$action = $_POST['action'] ?? '';

// Debug logging
error_log("AJAX Report Handler - Action: " . $action);
error_log("POST Data: " . print_r($_POST, true));

// Handle different actions
switch ($action) {
    case 'get_terms':
        getTerms($pdo);
        break;
        
    case 'get_classes':
        getClasses($pdo, $user_id, $user_role);
        break;
        
    case 'get_streams':
        getStreams($pdo, $user_id, $user_role);
        break;
        
    case 'get_students':
        getStudents($pdo);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
        break;
}

function getTerms($pdo) {
    $academic_year_id = $_POST['academic_year'] ?? 0;
    $level_id = $_POST['level'] ?? 0;
    
    if (!$academic_year_id || !$level_id) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters']);
        return;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, term_name 
            FROM academic_terms 
            WHERE academic_year_id = ? AND status = 'active'
            ORDER BY id
        ");
        $stmt->execute([$academic_year_id]);
        $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'terms' => $terms]);
    } catch (Exception $e) {
        error_log("Error in getTerms: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getClasses($pdo, $user_id, $user_role) {
    $level_id = $_POST['level'] ?? 0;
    
    if (!$level_id) {
        echo json_encode(['success' => false, 'message' => 'Missing level ID']);
        return;
    }
    
    try {
        if ($user_role === 'admin') {
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
                WHERE tc.teacher_id = ? AND c.level_id = ? AND c.status = 'active'
                ORDER BY c.name
            ");
            $stmt->execute([$user_id, $level_id]);
        }
        
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'classes' => $classes]);
    } catch (Exception $e) {
        error_log("Error in getClasses: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getStreams($pdo, $user_id, $user_role) {
    $class_id = $_POST['class'] ?? 0;
    
    if (!$class_id) {
        echo json_encode(['success' => false, 'message' => 'Missing class ID']);
        return;
    }
    
    try {
        if ($user_role === 'admin') {
            $stmt = $pdo->prepare("
                SELECT id, name 
                FROM streams 
                WHERE class_id = ? AND status = 'active'
                ORDER BY name
            ");
            $stmt->execute([$class_id]);
        } else {
            // Class teacher - only streams they teach
            $stmt = $pdo->prepare("
                SELECT DISTINCT s.id, s.name
                FROM teacher_classes tc
                JOIN streams s ON tc.stream_id = s.id
                WHERE tc.teacher_id = ? AND tc.class_id = ? AND s.status = 'active'
                ORDER BY s.name
            ");
            $stmt->execute([$user_id, $class_id]);
        }
        
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'streams' => $streams]);
    } catch (Exception $e) {
        error_log("Error in getStreams: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getStudents($pdo) {
    $class_id = $_POST['class'] ?? 0;
    $stream_id = $_POST['stream'] ?? 0;
    $academic_year_id = $_POST['academic_year'] ?? 0;
    
    error_log("getStudents called with: class=$class_id, stream=$stream_id, academic_year=$academic_year_id");
    
    if (!$class_id || !$stream_id || !$academic_year_id) {
        echo json_encode([
            'success' => false, 
            'message' => 'Missing parameters',
            'received' => [
                'class' => $class_id,
                'stream' => $stream_id,
                'academic_year' => $academic_year_id
            ]
        ]);
        return;
    }
    
    try {
        // First check if there are any students
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM students
            WHERE class_id = ? AND stream_id = ? AND academic_year_id = ? AND status = 'active'
        ");
        $stmt->execute([$class_id, $stream_id, $academic_year_id]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);
        
        error_log("Found " . $count['total'] . " students");
        
        // Now fetch the actual students
        $stmt = $pdo->prepare("
            SELECT student_id, surname, other_names
            FROM students
            WHERE class_id = ? AND stream_id = ? AND academic_year_id = ? AND status = 'active'
            ORDER BY surname, other_names
        ");
        $stmt->execute([$class_id, $stream_id, $academic_year_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Debug: Log the first student if any
        if (!empty($students)) {
            error_log("First student: " . print_r($students[0], true));
        }
        
        echo json_encode([
            'success' => true, 
            'students' => $students,
            'count' => count($students)
        ]);
        
    } catch (Exception $e) {
        error_log("Error in getStudents: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
?>