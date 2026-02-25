<?php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';
$is_admin = ($role === 'admin');

header('Content-Type: application/json');

switch ($action) {
    case 'get_academic_years':
        try {
            $stmt = $pdo->prepare("
                SELECT id, year_name 
                FROM academic_years 
                WHERE status = 'active' 
                ORDER BY start_year DESC
            ");
            $stmt->execute();
            $years = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'years' => $years]);
            
        } catch (Exception $e) {
            error_log("Error in get_academic_years: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading academic years: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_terms':
        $academic_year_id = $_GET['academic_year'] ?? 0;
        
        if (!$academic_year_id) {
            echo json_encode(['success' => false, 'message' => 'Academic Year ID required']);
            exit;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT id, term_name 
                FROM academic_terms 
                WHERE academic_year_id = ? 
                AND status = 'active'
                ORDER BY id
            ");
            $stmt->execute([$academic_year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'terms' => $terms]);
            
        } catch (Exception $e) {
            error_log("Error in get_terms: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading terms: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_classes':
        $level_id = $_GET['level'] ?? 0;
        
        if (!$level_id) {
            echo json_encode(['success' => false, 'message' => 'Level ID required']);
            exit;
        }
        
        try {
            if ($is_admin) {
                // ADMIN LOGIC: Load all classes for the selected level
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM classes 
                    WHERE level_id = ? 
                    AND status = 'active'
                    ORDER BY name
                ");
                $stmt->execute([$level_id]);
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // TEACHER LOGIC: Load only classes assigned to the teacher
                // Combine multiple sources to get teacher's classes
                $queries = [];
                $params = [];
                
                // 1. Direct teacher-class assignments
                $queries[] = "
                    SELECT DISTINCT c.id, c.name 
                    FROM teacher_classes tc
                    JOIN classes c ON tc.class_id = c.id
                    WHERE c.level_id = ? AND tc.teacher_id = ? AND c.status = 'active'
                ";
                $params[] = [$level_id, $user_id];
                
                // 2. Teacher assignments (with class_id)
                $queries[] = "
                    SELECT DISTINCT c.id, c.name 
                    FROM teacher_assignments ta
                    JOIN classes c ON ta.class_id = c.id
                    WHERE c.level_id = ? AND ta.teacher_id = ? AND c.status = 'active'
                    AND ta.status = 'active' AND ta.class_id IS NOT NULL
                ";
                $params[] = [$level_id, $user_id];
                
                // 3. Classes through teacher subjects
                $queries[] = "
                    SELECT DISTINCT c.id, c.name 
                    FROM teacher_subjects ts
                    JOIN subjects s ON ts.subject_id = s.id
                    JOIN classes c ON s.level_id = c.level_id
                    WHERE ts.teacher_id = ? AND c.level_id = ? AND c.status = 'active'
                ";
                $params[] = [$user_id, $level_id];
                
                // Execute all queries and combine results
                $allClasses = [];
                foreach ($queries as $index => $query) {
                    $stmt = $pdo->prepare($query);
                    $stmt->execute($params[$index]);
                    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $allClasses = array_merge($allClasses, $classes);
                }
                
                // Remove duplicates
                $uniqueClasses = [];
                foreach ($allClasses as $class) {
                    $uniqueClasses[$class['id']] = $class;
                }
                $result = array_values($uniqueClasses);
            }
            
            echo json_encode(['success' => true, 'classes' => $result]);
            
        } catch (Exception $e) {
            error_log("Error in get_classes: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading classes: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_streams':
        $class_id = $_GET['class'] ?? 0;
        
        if (!$class_id) {
            echo json_encode(['success' => false, 'message' => 'Class ID required']);
            exit;
        }
        
        try {
            if ($is_admin) {
                // ADMIN LOGIC: Load all streams for the selected class
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM streams 
                    WHERE class_id = ? 
                    AND status = 'active'
                    ORDER BY name
                ");
                $stmt->execute([$class_id]);
            } else {
                // TEACHER LOGIC: Load only streams assigned to the teacher for this class
                $stmt = $pdo->prepare("
                    SELECT DISTINCT s.id, s.name 
                    FROM streams s
                    WHERE s.class_id = ? 
                    AND s.status = 'active'
                    AND (
                        -- Teacher has direct assignment to this stream
                        s.id IN (
                            SELECT ta.stream_id 
                            FROM teacher_assignments ta
                            WHERE ta.teacher_id = ? 
                            AND ta.class_id = s.class_id
                            AND ta.stream_id = s.id
                            AND ta.status = 'active'
                        )
                        -- Teacher is class teacher for this class (all streams)
                        OR ? IN (
                            SELECT tc.teacher_id 
                            FROM teacher_classes tc 
                            WHERE tc.class_id = s.class_id 
                            AND tc.academic_year_id = (
                                SELECT id FROM academic_years WHERE status = 'active' AND is_current = 1 LIMIT 1
                            )
                        )
                        -- Teacher teaches subjects to students in this stream
                        OR EXISTS (
                            SELECT 1 
                            FROM students st
                            JOIN student_subjects ss ON st.id = ss.student_id
                            JOIN teacher_subjects ts ON ss.subject_id = ts.subject_id
                            WHERE st.class_id = s.class_id 
                            AND st.stream_id = s.id
                            AND ts.teacher_id = ?
                            AND st.status = 'active'
                        )
                    )
                    ORDER BY s.name
                ");
                $stmt->execute([$class_id, $user_id, $user_id, $user_id]);
            }
            
            $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'streams' => $streams]);
            
        } catch (Exception $e) {
            error_log("Error in get_streams: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading streams: ' . $e->getMessage()]);
        }
        break;
        
    case 'get_subjects':
        $level_id = $_GET['level'] ?? 0;
        $class_id = $_GET['class'] ?? 0;
        $stream_id = $_GET['stream'] ?? 0;
        
        if (!$level_id) {
            echo json_encode(['success' => false, 'message' => 'Level ID required']);
            exit;
        }
        
        try {
            if ($is_admin) {
                // ADMIN LOGIC: Load all subjects for the selected level
                $stmt = $pdo->prepare("
                    SELECT s.id, s.code, s.name 
                    FROM subjects s
                    WHERE s.level_id = ? 
                    AND s.status = 'active'
                    ORDER BY s.code
                ");
                $stmt->execute([$level_id]);
                $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // TEACHER LOGIC: Load only subjects assigned to the teacher
                // First, try direct teacher_subjects assignments
                $stmt = $pdo->prepare("
                    SELECT DISTINCT s.id, s.code, s.name 
                    FROM subjects s
                    JOIN teacher_subjects ts ON s.id = ts.subject_id
                    WHERE s.level_id = ? 
                    AND ts.teacher_id = ?
                    AND s.status = 'active'
                    ORDER BY s.code
                ");
                $stmt->execute([$level_id, $user_id]);
                $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no subjects found, check teacher_assignments
                if (empty($subjects)) {
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT s.id, s.code, s.name 
                        FROM subjects s
                        JOIN teacher_assignments ta ON s.id = ta.subject_id
                        WHERE s.level_id = ? 
                        AND ta.teacher_id = ?
                        AND ta.status = 'active'
                        AND s.status = 'active'
                        ORDER BY s.code
                    ");
                    $stmt->execute([$level_id, $user_id]);
                    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
                
                // Still no subjects? Check if teacher is class teacher
                if (empty($subjects)) {
                    // If teacher is class teacher for a class in this level, 
                    // they should see all subjects for that level
                    $stmt = $pdo->prepare("
                        SELECT DISTINCT s.id, s.code, s.name 
                        FROM subjects s
                        WHERE s.level_id = ? 
                        AND s.status = 'active'
                        AND EXISTS (
                            SELECT 1 
                            FROM teacher_classes tc
                            JOIN classes c ON tc.class_id = c.id
                            WHERE tc.teacher_id = ?
                            AND c.level_id = s.level_id
                            AND tc.academic_year_id = (
                                SELECT id FROM academic_years WHERE status = 'active' AND is_current = 1 LIMIT 1
                            )
                        )
                        ORDER BY s.code
                    ");
                    $stmt->execute([$level_id, $user_id]);
                    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                }
            }
            
            echo json_encode(['success' => true, 'subjects' => $subjects ?? []]);
            
        } catch (Exception $e) {
            error_log("Error in get_subjects: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error loading subjects: ' . $e->getMessage()]);
        }
        break;
        
    case 'clear_session':
        // Clear form data from session
        unset($_SESSION['form_data']);
        echo json_encode(['success' => true, 'message' => 'Session cleared']);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>