<?php
// file: academic_comment_management.php
// Description: Manage head teacher and class teacher comments for student report cards

require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_head_teacher = ($user_role === 'head_teacher');
$is_class_teacher = ($user_role === 'teacher');

// Strict access control - only admin, head teacher, and class teachers
if (!$is_admin && !$is_head_teacher && !$is_class_teacher) {
    die("Unauthorized Access: This module is only accessible to Administrators, Head Teachers, and Class Teachers.");
}

// Get teacher info if user is a teacher
$teacher_info = null;
$teacher_classes = [];

if ($is_class_teacher) {
    try {
        $stmt = $pdo->prepare("SELECT id, fullname FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->execute([$user_id]);
        $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$teacher_info) {
            header("Location: index.php");
            exit;
        }
        
        // Get teacher's assigned classes for current academic year
        $stmt = $pdo->prepare("
            SELECT tc.class_id, tc.stream_id, c.name as class_name, s.name as stream_name
            FROM teacher_classes tc
            JOIN classes c ON tc.class_id = c.id
            LEFT JOIN streams s ON tc.stream_id = s.id
            WHERE tc.teacher_id = ? AND tc.is_class_teacher = 1
        ");
        $stmt->execute([$teacher_info['id']]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching teacher info: " . $e->getMessage());
    }
}

// Fetch user details for display
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($user['fullname'] ?? 'User');
    $email = htmlspecialchars($user['email'] ?? '');
} catch (Exception $e) {
    $fullname = "User";
    $email = "—";
}

// Fetch academic years for dropdown
$academic_years = [];
try {
    $stmt = $pdo->prepare("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY start_year DESC");
    $stmt->execute();
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
}

// Fetch levels for dropdown
$levels = [];
try {
    $stmt = $pdo->prepare("SELECT id, name FROM levels WHERE status = 'active'");
    $stmt->execute();
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching levels: " . $e->getMessage());
}

// Handle CRUD operations
$message = '';
$message_type = '';

// Add/Edit Comment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_comment'])) {
        $comment_id = $_POST['comment_id'] ?? '';
        $academic_year_id = $_POST['academic_year'] ?? '';
        $level_id = $_POST['level'] ?? '';
        $class_id = $_POST['class'] ?? '';
        $stream_id = $_POST['stream'] ?? '';
        $term_id = $_POST['term'] ?? '';
        $comment_type = $_POST['comment_type'] ?? '';
        $min_points = $_POST['min_points'] !== '' ? $_POST['min_points'] : null;
        $max_points = $_POST['max_points'] !== '' ? $_POST['max_points'] : null;
        $min_a_count = $_POST['min_a_count'] !== '' ? $_POST['min_a_count'] : null;
        $min_b_count = $_POST['min_b_count'] !== '' ? $_POST['min_b_count'] : null;
        $min_c_count = $_POST['min_c_count'] !== '' ? $_POST['min_c_count'] : null;
        $min_d_count = $_POST['min_d_count'] !== '' ? $_POST['min_d_count'] : null;
        $min_e_count = $_POST['min_e_count'] !== '' ? $_POST['min_e_count'] : null;
        $comment_text = $_POST['comment'] ?? '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validate required fields
        $errors = [];
        if (!$academic_year_id) $errors[] = "Academic Year is required";
        if (!$level_id) $errors[] = "Level is required";
        if (!$term_id) $errors[] = "Term is required";
        if (!$comment_type) $errors[] = "Comment Type is required";
        if (!$comment_text) $errors[] = "Comment is required";
        
        // Validate criteria based on level and comment type
        if ($level_id == 2) { // A-Level
            if ($min_points === null || $max_points === null) {
                $errors[] = "Points range is required for A-Level comments";
            } elseif ($min_points >= $max_points) {
                $errors[] = "Min Points must be less than Max Points";
            }
        } else { // O-Level
            if ($min_a_count === null && $min_b_count === null && $min_c_count === null && 
                $min_d_count === null && $min_e_count === null) {
                $errors[] = "At least one grade count criterion is required for O-Level comments";
            }
        }
        
        if (empty($errors)) {
            try {
                // Begin transaction
                $pdo->beginTransaction();
                
                // Check for overlapping criteria
                $check_sql = "
                    SELECT id FROM academic_comments 
                    WHERE academic_year_id = ? 
                      AND level_id = ? 
                      AND term_id = ? 
                      AND comment_type = ?
                      AND is_active = 1
                ";
                $params = [$academic_year_id, $level_id, $term_id, $comment_type];
                
                // Add class filter if specified
                if ($class_id) {
                    $check_sql .= " AND (class_id = ? OR class_id IS NULL)";
                    $params[] = $class_id;
                } else {
                    $check_sql .= " AND class_id IS NULL";
                }
                
                // Add stream filter if specified
                if ($stream_id) {
                    $check_sql .= " AND (stream_id = ? OR stream_id IS NULL)";
                    $params[] = $stream_id;
                } else {
                    $check_sql .= " AND stream_id IS NULL";
                }
                
                // Check for overlapping points range (A-Level)
                if ($level_id == 2 && $min_points !== null && $max_points !== null) {
                    $check_sql .= " AND (
                        (? BETWEEN min_points AND max_points) 
                        OR (? BETWEEN min_points AND max_points)
                        OR (min_points BETWEEN ? AND ?)
                    )";
                    $params = array_merge($params, [$min_points, $max_points, $min_points, $max_points]);
                }
                
                // Exclude current comment if editing
                if ($comment_id) {
                    $check_sql .= " AND id != ?";
                    $params[] = $comment_id;
                }
                
                $stmt = $pdo->prepare($check_sql);
                $stmt->execute($params);
                
                if ($stmt->rowCount() > 0) {
                    $message = "Overlapping criteria detected. Please adjust the range.";
                    $message_type = "error";
                    $pdo->rollBack();
                } else {
                    if ($comment_id) {
                        // Update existing comment
                        $stmt = $pdo->prepare("
                            UPDATE academic_comments SET
                                academic_year_id = ?,
                                level_id = ?,
                                class_id = ?,
                                stream_id = ?,
                                term_id = ?,
                                comment_type = ?,
                                min_points = ?,
                                max_points = ?,
                                min_a_count = ?,
                                min_b_count = ?,
                                min_c_count = ?,
                                min_d_count = ?,
                                min_e_count = ?,
                                comment = ?,
                                is_active = ?,
                                updated_by = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $academic_year_id, 
                            $level_id, 
                            $class_id ?: null, 
                            $stream_id ?: null,
                            $term_id, 
                            $comment_type,
                            $min_points, 
                            $max_points,
                            $min_a_count, 
                            $min_b_count,
                            $min_c_count, 
                            $min_d_count,
                            $min_e_count,
                            $comment_text, 
                            $is_active, 
                            $user_id, 
                            $comment_id
                        ]);
                        
                        // Log activity
                        $log_stmt = $pdo->prepare("
                            INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                            VALUES (?, 'UPDATE_COMMENT', ?, ?, NOW())
                        ");
                        $log_stmt->execute([$user_id, "Updated $comment_type comment ID: $comment_id", $_SERVER['REMOTE_ADDR'] ?? null]);
                        
                        $message = "Comment updated successfully!";
                        $message_type = "success";
                    } else {
                        // Insert new comment
                        $stmt = $pdo->prepare("
                            INSERT INTO academic_comments (
                                academic_year_id, level_id, class_id, stream_id, term_id,
                                comment_type, min_points, max_points,
                                min_a_count, min_b_count, min_c_count, min_d_count, min_e_count,
                                comment, is_active, created_by
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $academic_year_id, 
                            $level_id, 
                            $class_id ?: null, 
                            $stream_id ?: null,
                            $term_id, 
                            $comment_type,
                            $min_points, 
                            $max_points,
                            $min_a_count, 
                            $min_b_count,
                            $min_c_count, 
                            $min_d_count,
                            $min_e_count,
                            $comment_text, 
                            $is_active, 
                            $user_id
                        ]);
                        
                        $new_id = $pdo->lastInsertId();
                        
                        // Log activity
                        $log_stmt = $pdo->prepare("
                            INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                            VALUES (?, 'ADD_COMMENT', ?, ?, NOW())
                        ");
                        $log_stmt->execute([$user_id, "Added new $comment_type comment ID: $new_id", $_SERVER['REMOTE_ADDR'] ?? null]);
                        
                        $message = "Comment added successfully!";
                        $message_type = "success";
                    }
                    
                    $pdo->commit();
                }
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error saving comment: " . $e->getMessage();
                $message_type = "error";
                error_log("Comment save error: " . $e->getMessage());
            }
        } else {
            $message = "Please fix the following errors: " . implode(", ", $errors);
            $message_type = "error";
        }
    }
    
    // Delete comment
    if (isset($_POST['delete_comment'])) {
        $comment_id = $_POST['comment_id'] ?? '';
        
        if ($comment_id) {
            try {
                $pdo->beginTransaction();
                
                // Check if user has permission to delete this comment
                if ($is_class_teacher) {
                    $stmt = $pdo->prepare("
                        SELECT c.* FROM academic_comments c
                        LEFT JOIN teacher_classes tc ON c.class_id = tc.class_id 
                        WHERE c.id = ? AND (tc.teacher_id = ? OR c.class_id IS NULL)
                    ");
                    $stmt->execute([$comment_id, $user_id]);
                    if ($stmt->rowCount() == 0) {
                        throw new Exception("You don't have permission to delete this comment");
                    }
                }
                
                // Log before deletion
                $log_stmt = $pdo->prepare("
                    INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                    VALUES (?, 'DELETE_COMMENT', ?, ?, NOW())
                ");
                $log_stmt->execute([$user_id, "Deleted comment ID: $comment_id", $_SERVER['REMOTE_ADDR'] ?? null]);
                
                // Delete comment
                $stmt = $pdo->prepare("DELETE FROM academic_comments WHERE id = ?");
                $stmt->execute([$comment_id]);
                
                $pdo->commit();
                
                $message = "Comment deleted successfully!";
                $message_type = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $message = "Error deleting comment: " . $e->getMessage();
                $message_type = "error";
            }
        }
    }
    
    // Toggle active status
    if (isset($_POST['toggle_status'])) {
        $comment_id = $_POST['comment_id'] ?? '';
        $current_status = $_POST['current_status'] ?? 0;
        $new_status = $current_status ? 0 : 1;
        
        try {
            $stmt = $pdo->prepare("UPDATE academic_comments SET is_active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $user_id, $comment_id]);
            
            $message = "Comment status updated successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $message = "Error updating status: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Fetch comments with filters
$comments = [];
$filter_academic_year = $_GET['filter_academic_year'] ?? '';
$filter_level = $_GET['filter_level'] ?? '';
$filter_class = $_GET['filter_class'] ?? '';
$filter_stream = $_GET['filter_stream'] ?? '';
$filter_term = $_GET['filter_term'] ?? '';
$filter_comment_type = $_GET['filter_comment_type'] ?? '';

try {
    $sql = "
        SELECT c.*, 
               ay.year_name,
               l.name as level_name,
               cls.name as class_name,
               s.name as stream_name,
               t.term_name,
               u.fullname as created_by_name,
               u2.fullname as updated_by_name
        FROM academic_comments c
        JOIN academic_years ay ON c.academic_year_id = ay.id
        JOIN levels l ON c.level_id = l.id
        JOIN academic_terms t ON c.term_id = t.id
        LEFT JOIN classes cls ON c.class_id = cls.id
        LEFT JOIN streams s ON c.stream_id = s.id
        LEFT JOIN users u ON c.created_by = u.id
        LEFT JOIN users u2 ON c.updated_by = u2.id
        WHERE 1=1
    ";
    
    $params = [];
    
    if ($filter_academic_year) {
        $sql .= " AND c.academic_year_id = ?";
        $params[] = $filter_academic_year;
    }
    
    if ($filter_level) {
        $sql .= " AND c.level_id = ?";
        $params[] = $filter_level;
    }
    
    if ($filter_class) {
        $sql .= " AND (c.class_id = ? OR c.class_id IS NULL)";
        $params[] = $filter_class;
    }
    
    if ($filter_stream) {
        $sql .= " AND (c.stream_id = ? OR c.stream_id IS NULL)";
        $params[] = $filter_stream;
    }
    
    if ($filter_term) {
        $sql .= " AND c.term_id = ?";
        $params[] = $filter_term;
    }
    
    if ($filter_comment_type) {
        $sql .= " AND c.comment_type = ?";
        $params[] = $filter_comment_type;
    }
    
    // For class teachers, restrict to their classes
    if ($is_class_teacher && !empty($teacher_classes)) {
        $class_ids = array_column($teacher_classes, 'class_id');
        if (!empty($class_ids)) {
            $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
            $sql .= " AND (c.class_id IN ($placeholders) OR c.class_id IS NULL)";
            $params = array_merge($params, $class_ids);
        }
    }
    
    $sql .= " ORDER BY ay.start_year DESC, l.id, c.comment_type, t.id, c.min_points";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $comments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Error fetching comments: " . $e->getMessage());
    $message = "Error loading comments: " . $e->getMessage();
    $message_type = "error";
}

// Get selected filter values for dynamic loading
$selected_academic_year = $filter_academic_year;
$selected_level = $filter_level;
$selected_class = $filter_class;
$selected_stream = $filter_stream;
$selected_term = $filter_term;
$selected_comment_type = $filter_comment_type;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Academic Comment Management - School Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        :root {
            --primary: #1a2a6c;
            --secondary: #b21f1f;
            --sidebar-bg: #2c3e50;
            --sidebar-hover: #34495e;
            --text-light: #ecf0f1;
            --body-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #212529;
            --border-color: #dee2e6;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html, body {
            height: 100%;
        }

        body {
            display: flex;
            background-color: var(--body-bg);
            color: var(--text-dark);
            height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--text-light);
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.12);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 1rem;
            min-height: 80px;
            background: var(--primary);
            font-weight: 700;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #3a506b;
            flex-shrink: 0;
        }

        .nav-menu {
            list-style: none;
            padding: 0;
            flex: 1;
            overflow-y: auto;
        }

        .nav-item {
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.8rem 1.2rem;
            color: var(--text-light);
            text-decoration: none;
            font-size: 0.98rem;
            transition: all 0.2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: var(--sidebar-hover);
            color: white;
        }

        .nav-link i {
            width: 22px;
            font-size: 0.95rem;
            margin-right: 12px;
            text-align: center;
            color: #95a5a6;
        }

        .nav-link:hover i,
        .nav-link.active i {
            color: #ecf0f1;
        }

        .logout-section {
            padding: 0.9rem 1.2rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
            flex-shrink: 0;
        }

        .logout-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            color: #e74c3c;
            font-size: 0.98rem;
            padding: 0.65rem 0;
            cursor: pointer;
            text-align: left;
            font-weight: 600;
        }

        .logout-btn:hover {
            color: #c0392b;
        }

        /* Main Content */
        .main-wrapper {
            margin-left: 280px;
            width: calc(100% - 280px);
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        .header {
            height: 80px;
            min-height: 80px;
            background: white;
            box-shadow: 0 1px 5px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 1.4rem;
            flex-shrink: 0;
        }

        .teacher-info h1 {
            font-size: 1.5rem;
            color: var(--primary);
            margin: 0;
        }

        .teacher-info p {
            font-size: 1rem;
            color: #6c757d;
            margin-top: 4px;
        }

        .role-tag {
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 0.95rem;
        }

        .main-content {
            padding: 1rem 1.4rem;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        /* Form Section */
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .form-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary);
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #152255;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: var(--warning);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .comment-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            font-size: 0.9rem;
        }

        .comment-table th {
            background: var(--primary);
            color: white;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .comment-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .comment-table tr:hover {
            background: #f8f9fa;
        }

        .comment-table .active-badge {
            background: #28a745;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .comment-table .inactive-badge {
            background: #dc3545;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .comment-table .head-teacher-badge {
            background: #1a2a6c;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
        }

        .comment-table .class-teacher-badge {
            background: #17a2b8;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            display: inline-block;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 1050;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 1.5rem;
            border-radius: 8px;
            width: 80%;
            max-width: 1000px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            position: relative;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        .modal-header h3 {
            color: var(--primary);
            font-size: 1.3rem;
            margin: 0;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #6c757d;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            font-weight: 600;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        /* Info Box */
        .info-box {
            background: #e8f4fd;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .info-box p {
            margin: 0;
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .info-box i {
            color: var(--primary);
            margin-right: 0.5rem;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 4px;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-edit {
            background: #ffc107;
            color: #212529;
        }

        .btn-edit:hover {
            background: #e0a800;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .btn-toggle {
            background: #17a2b8;
            color: white;
        }

        .btn-toggle:hover {
            background: #138496;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1100;
            background: rgba(255,255,255,0.9);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            text-align: center;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Filter Section */
        .filter-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            align-items: flex-end;
        }

        .filter-group {
            flex: 1;
            min-width: 150px;
        }

        .filter-group label {
            display: block;
            margin-bottom: 0.25rem;
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
        }

        .filter-actions {
            display: flex;
            gap: 0.5rem;
            margin-left: auto;
        }

        /* Criteria Section */
        .criteria-section {
            background: #f0f4ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            border-left: 4px solid var(--primary);
        }

        .criteria-title {
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .grade-criteria-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                transition: width 0.3s;
                overflow-x: hidden;
            }

            .sidebar:hover {
                width: 280px;
            }

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .modal-content {
                width: 95%;
                margin: 10% auto;
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
                height: auto;
                gap: 0.5rem;
            }

            .filter-section {
                flex-direction: column;
            }

            .filter-actions {
                margin-left: 0;
                width: 100%;
            }

            .filter-actions .btn {
                flex: 1;
            }

            .grade-criteria-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
        <p>Processing...</p>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-chalkboard-teacher"></i>
            <span><?= $is_admin ? 'Admin Portal' : ($is_head_teacher ? 'Head Teacher Portal' : 'Teacher Portal') ?></span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="<?= $is_admin ? 'admin_dashboard.php' : ($is_head_teacher ? 'head_teacher_dashboard.php' : 'teacher_dashboard.php') ?>" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="enter_marks.php" class="nav-link">
                    <i class="fas fa-edit"></i>
                    <span>Enter Marks</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="marksheets.php" class="nav-link">
                    <i class="fas fa-file-alt"></i>
                    <span>View Marksheets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="master_marksheet.php" class="nav-link">
                    <i class="fas fa-table"></i>
                    <span>Master Marksheets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="print_single_report_card.php" class="nav-link">
                    <i class="fas fa-print"></i>
                    <span>Print Single Report</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="bulk_report_card_print.php" class="nav-link">
                    <i class="fas fa-print"></i>
                    <span>Bulk Report Print</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="academic_comment_management.php" class="nav-link active">
                    <i class="fas fa-comment"></i>
                    <span>Comment Management</span>
                </a>
            </li>
        </ul>
        <div class="logout-section">
            <button class="logout-btn" onclick="window.location='logout.php'">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </button>
        </div>
    </aside>

    <div class="main-wrapper">
        <header class="header">
            <div class="teacher-info">
                <h1>Academic Comment Management</h1>
                <p><?= $fullname ?> | <?= $email ?></p>
            </div>
            <div class="role-tag">
                <?= $is_admin ? 'Admin' : ($is_head_teacher ? 'Head Teacher' : 'Class Teacher') ?>
            </div>
        </header>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type ?>">
                    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Add Comment Button -->
            <div class="form-section no-print">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>Head Teacher & Class Teacher Comments</h3>
                    <button class="btn btn-success" onclick="openAddModal()">
                        <i class="fas fa-plus"></i> Add New Comment
                    </button>
                </div>
                
                <div class="info-box" style="margin-top: 1rem;">
                    <p><i class="fas fa-info-circle"></i> Comments are automatically assigned to students based on:</p>
                    <ul style="margin-top: 0.5rem; margin-left: 1.5rem;">
                        <li><strong>A-Level:</strong> Total Points range</li>
                        <li><strong>O-Level:</strong> Grade counts (e.g., students with 7 or more A's, 5 B's and 2 C's, etc.)</li>
                    </ul>
                </div>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-group">
                    <label for="filter_academic_year">Academic Year</label>
                    <select class="form-control" id="filter_academic_year" name="filter_academic_year">
                        <option value="">All Years</option>
                        <?php foreach ($academic_years as $year): ?>
                            <option value="<?= $year['id'] ?>" <?= $filter_academic_year == $year['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($year['year_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_level">Level</label>
                    <select class="form-control" id="filter_level" name="filter_level">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= $level['id'] ?>" <?= $filter_level == $level['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_class">Class</label>
                    <select class="form-control" id="filter_class" name="filter_class">
                        <option value="">All Classes</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_stream">Stream</label>
                    <select class="form-control" id="filter_stream" name="filter_stream">
                        <option value="">All Streams</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_term">Term</label>
                    <select class="form-control" id="filter_term" name="filter_term">
                        <option value="">All Terms</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filter_comment_type">Comment Type</label>
                    <select class="form-control" id="filter_comment_type" name="filter_comment_type">
                        <option value="">All Types</option>
                        <option value="head_teacher" <?= $filter_comment_type == 'head_teacher' ? 'selected' : '' ?>>Head Teacher</option>
                        <option value="class_teacher" <?= $filter_comment_type == 'class_teacher' ? 'selected' : '' ?>>Class Teacher</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="button" class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
            </div>

            <!-- Comments Table -->
            <div class="form-section">
                <div class="table-container">
                    <table class="comment-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Type</th>
                                <th>Year</th>
                                <th>Level</th>
                                <th>Class/Stream</th>
                                <th>Term</th>
                                <th>Criteria</th>
                                <th>Comment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($comments)): ?>
                                <tr>
                                    <td colspan="10" style="text-align: center; padding: 2rem; color: #6c757d;">
                                        <i class="fas fa-comment-slash" style="font-size: 2rem; margin-bottom: 0.5rem;"></i>
                                        <p>No comments found. Click "Add New Comment" to create one.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($comments as $index => $comment): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td>
                                            <?php if ($comment['comment_type'] == 'head_teacher'): ?>
                                                <span class="head-teacher-badge">Head Teacher</span>
                                            <?php else: ?>
                                                <span class="class-teacher-badge">Class Teacher</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($comment['year_name']) ?></td>
                                        <td><?= htmlspecialchars($comment['level_name']) ?></td>
                                        <td>
                                            <?php if ($comment['class_name']): ?>
                                                <?= htmlspecialchars($comment['class_name']) ?>
                                                <?php if ($comment['stream_name']): ?>
                                                    - <?= htmlspecialchars($comment['stream_name']) ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <em>All Classes</em>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($comment['term_name']) ?></td>
                                        <td>
                                            <?php if ($comment['level_id'] == 2): // A-Level ?>
                                                Points: <?= $comment['min_points'] ?> - <?= $comment['max_points'] ?>
                                            <?php else: // O-Level ?>
                                                <?php 
                                                $criteria = [];
                                                if ($comment['min_a_count']) $criteria[] = "A ≥ {$comment['min_a_count']}";
                                                if ($comment['min_b_count']) $criteria[] = "B ≥ {$comment['min_b_count']}";
                                                if ($comment['min_c_count']) $criteria[] = "C ≥ {$comment['min_c_count']}";
                                                if ($comment['min_d_count']) $criteria[] = "D ≥ {$comment['min_d_count']}";
                                                if ($comment['min_e_count']) $criteria[] = "E ≥ {$comment['min_e_count']}";
                                                echo implode(', ', $criteria) ?: '—';
                                                ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                                 title="<?= htmlspecialchars($comment['comment']) ?>">
                                                <?= htmlspecialchars(substr($comment['comment'], 0, 60)) ?>...
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($comment['is_active']): ?>
                                                <span class="active-badge">Active</span>
                                            <?php else: ?>
                                                <span class="inactive-badge">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="action-btn btn-edit" onclick='editComment(<?= json_encode($comment) ?>)'>
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this comment?');">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <button type="submit" name="delete_comment" class="action-btn btn-delete">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                                
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                                    <input type="hidden" name="current_status" value="<?= $comment['is_active'] ?>">
                                                    <button type="submit" name="toggle_status" class="action-btn btn-toggle">
                                                        <i class="fas fa-<?= $comment['is_active'] ? 'ban' : 'check' ?>"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>

    <!-- Add/Edit Comment Modal -->
    <div id="commentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Add New Comment</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            
            <form method="POST" id="commentForm" onsubmit="return validateCommentForm()">
                <input type="hidden" name="comment_id" id="comment_id">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="academic_year">Academic Year *</label>
                        <select class="form-control" id="academic_year" name="academic_year" required>
                            <option value="">Select Academic Year</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?= $year['id'] ?>"><?= htmlspecialchars($year['year_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="term">Term *</label>
                        <select class="form-control" id="term" name="term" required>
                            <option value="">Select Academic Year First</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="level">Level *</label>
                        <select class="form-control" id="level" name="level" required onchange="toggleCriteriaFields()">
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="comment_type">Comment Type *</label>
                        <select class="form-control" id="comment_type" name="comment_type" required>
                            <option value="">Select Type</option>
                            <option value="head_teacher">Head Teacher Comment</option>
                            <option value="class_teacher">Class Teacher Comment</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select class="form-control" id="class" name="class">
                            <option value="">All Classes</option>
                        </select>
                        <small class="text-muted">Leave empty to apply to all classes</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="stream">Stream</label>
                        <select class="form-control" id="stream" name="stream">
                            <option value="">All Streams</option>
                        </select>
                        <small class="text-muted">Leave empty to apply to all streams</small>
                    </div>
                </div>

                <!-- A-Level Criteria (Points Range) -->
                <div id="alevel_criteria" class="criteria-section" style="display: none;">
                    <div class="criteria-title">A-Level Criteria (Total Points)</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="min_points">Min Points *</label>
                            <input type="number" class="form-control" id="min_points" name="min_points" step="0.1" min="0" max="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="max_points">Max Points *</label>
                            <input type="number" class="form-control" id="max_points" name="max_points" step="0.1" min="0" max="100">
                        </div>
                    </div>
                </div>

                <!-- O-Level Criteria (Grade Counts) -->
                <div id="olevel_criteria" class="criteria-section" style="display: none;">
                    <div class="criteria-title">O-Level Criteria (Minimum Grade Counts)</div>
                    <div class="grade-criteria-grid">
                        <div class="form-group">
                            <label for="min_a_count">Minimum A's Required</label>
                            <input type="number" class="form-control" id="min_a_count" name="min_a_count" min="0" max="20">
                        </div>
                        
                        <div class="form-group">
                            <label for="min_b_count">Minimum B's Required</label>
                            <input type="number" class="form-control" id="min_b_count" name="min_b_count" min="0" max="20">
                        </div>
                        
                        <div class="form-group">
                            <label for="min_c_count">Minimum C's Required</label>
                            <input type="number" class="form-control" id="min_c_count" name="min_c_count" min="0" max="20">
                        </div>
                        
                        <div class="form-group">
                            <label for="min_d_count">Minimum D's Required</label>
                            <input type="number" class="form-control" id="min_d_count" name="min_d_count" min="0" max="20">
                        </div>
                        
                        <div class="form-group">
                            <label for="min_e_count">Minimum E's Required</label>
                            <input type="number" class="form-control" id="min_e_count" name="min_e_count" min="0" max="20">
                        </div>
                    </div>
                    <small class="text-muted">At least one grade count criterion is required. The system will match students who meet ALL specified minimum counts.</small>
                </div>
                
                <div class="form-group">
                    <label for="comment">Comment Text *</label>
                    <textarea class="form-control" id="comment" name="comment" rows="4" required placeholder="Enter the comment that will appear on report cards..."></textarea>
                </div>
                
                <div class="form-group checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <label for="is_active">Active (comment will be used in report cards)</label>
                </div>
                
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" name="save_comment" class="btn btn-success">
                        <i class="fas fa-save"></i> Save Comment
                    </button>
                    <button type="button" class="btn btn-danger" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        $(document).ready(function() {
            // Store selected values for filters
            const selectedAcademicYear = '<?= $selected_academic_year ?>';
            const selectedLevel = '<?= $selected_level ?>';
            const selectedClass = '<?= $selected_class ?>';
            const selectedStream = '<?= $selected_stream ?>';
            const selectedTerm = '<?= $selected_term ?>';

            // Load filter dropdowns based on selections
            if (selectedAcademicYear) {
                loadFilterTerms(selectedAcademicYear);
            }
            
            if (selectedLevel && selectedAcademicYear) {
                loadFilterClasses(selectedLevel, selectedAcademicYear);
            }
            
            if (selectedClass && selectedAcademicYear) {
                setTimeout(function() {
                    loadFilterStreams(selectedClass, selectedAcademicYear);
                }, 300);
            }

            // Load terms when academic year changes in modal
            $('#academic_year').on('change', function() {
                const academicYearId = $(this).val();
                loadModalTerms(academicYearId);
            });

            // Load classes when level changes in modal
            $('#level').on('change', function() {
                const levelId = $(this).val();
                const academicYearId = $('#academic_year').val();
                loadModalClasses(levelId, academicYearId);
                
                // Toggle criteria fields based on level
                toggleCriteriaFields();
            });

            // Load streams when class changes in modal
            $('#class').on('change', function() {
                const classId = $(this).val();
                const academicYearId = $('#academic_year').val();
                loadModalStreams(classId, academicYearId);
            });

            // Modal validation
            window.validateCommentForm = function() {
                const level = $('#level').val();
                const commentType = $('#comment_type').val();
                
                if (!level) {
                    alert('Please select Level');
                    return false;
                }
                
                if (!commentType) {
                    alert('Please select Comment Type');
                    return false;
                }
                
                if (level == 2) { // A-Level
                    const minPoints = parseFloat($('#min_points').val());
                    const maxPoints = parseFloat($('#max_points').val());
                    
                    if (isNaN(minPoints) || isNaN(maxPoints)) {
                        alert('Points range is required for A-Level comments');
                        return false;
                    }
                    
                    if (minPoints >= maxPoints) {
                        alert('Min Points must be less than Max Points');
                        return false;
                    }
                } else { // O-Level
                    const aCount = $('#min_a_count').val();
                    const bCount = $('#min_b_count').val();
                    const cCount = $('#min_c_count').val();
                    const dCount = $('#min_d_count').val();
                    const eCount = $('#min_e_count').val();
                    
                    if (!aCount && !bCount && !cCount && !dCount && !eCount) {
                        alert('At least one grade count criterion is required for O-Level comments');
                        return false;
                    }
                }
                
                return true;
            };

            // Toggle criteria fields based on level
            window.toggleCriteriaFields = function() {
                const level = $('#level').val();
                
                if (level == 2) { // A-Level
                    $('#alevel_criteria').show();
                    $('#olevel_criteria').hide();
                    
                    // Clear O-Level fields
                    $('#min_a_count, #min_b_count, #min_c_count, #min_d_count, #min_e_count').val('');
                } else if (level == 1) { // O-Level
                    $('#alevel_criteria').hide();
                    $('#olevel_criteria').show();
                    
                    // Clear A-Level fields
                    $('#min_points, #max_points').val('');
                } else {
                    $('#alevel_criteria, #olevel_criteria').hide();
                }
            };

            // Filter functions
            window.applyFilters = function() {
                const academicYear = $('#filter_academic_year').val();
                const level = $('#filter_level').val();
                const className = $('#filter_class').val();
                const stream = $('#filter_stream').val();
                const term = $('#filter_term').val();
                const commentType = $('#filter_comment_type').val();
                
                let url = 'academic_comment_management.php?';
                if (academicYear) url += 'filter_academic_year=' + academicYear + '&';
                if (level) url += 'filter_level=' + level + '&';
                if (className) url += 'filter_class=' + className + '&';
                if (stream) url += 'filter_stream=' + stream + '&';
                if (term) url += 'filter_term=' + term + '&';
                if (commentType) url += 'filter_comment_type=' + commentType + '&';
                
                window.location.href = url;
            };

            window.clearFilters = function() {
                window.location.href = 'academic_comment_management.php';
            };

            // Modal functions
            window.openAddModal = function() {
                document.getElementById('modalTitle').textContent = 'Add New Comment';
                document.getElementById('commentForm').reset();
                document.getElementById('comment_id').value = '';
                document.getElementById('is_active').checked = true;
                
                // Reset all dropdowns
                $('#term').html('<option value="">Select Academic Year First</option>');
                $('#class').html('<option value="">All Classes</option>');
                $('#stream').html('<option value="">All Streams</option>');
                $('#alevel_criteria, #olevel_criteria').hide();
                
                document.getElementById('commentModal').style.display = 'block';
            };

            window.editComment = function(comment) {
                document.getElementById('modalTitle').textContent = 'Edit Comment';
                
                // Populate form fields
                document.getElementById('comment_id').value = comment.id;
                document.getElementById('academic_year').value = comment.academic_year_id;
                document.getElementById('comment_type').value = comment.comment_type;
                
                // Trigger change to load terms
                $('#academic_year').trigger('change');
                
                // Set values after a delay to allow AJAX to complete
                setTimeout(function() {
                    document.getElementById('level').value = comment.level_id;
                    $('#level').trigger('change');
                    
                    setTimeout(function() {
                        if (comment.class_id) {
                            document.getElementById('class').value = comment.class_id;
                            $('#class').trigger('change');
                            
                            setTimeout(function() {
                                if (comment.stream_id) {
                                    document.getElementById('stream').value = comment.stream_id;
                                }
                            }, 500);
                        }
                        
                        document.getElementById('term').value = comment.term_id;
                        
                        // Set criteria fields based on level
                        if (comment.level_id == 2) { // A-Level
                            document.getElementById('min_points').value = comment.min_points;
                            document.getElementById('max_points').value = comment.max_points;
                        } else { // O-Level
                            document.getElementById('min_a_count').value = comment.min_a_count || '';
                            document.getElementById('min_b_count').value = comment.min_b_count || '';
                            document.getElementById('min_c_count').value = comment.min_c_count || '';
                            document.getElementById('min_d_count').value = comment.min_d_count || '';
                            document.getElementById('min_e_count').value = comment.min_e_count || '';
                        }
                        
                        document.getElementById('comment').value = comment.comment;
                        document.getElementById('is_active').checked = comment.is_active == 1;
                        
                        // Show appropriate criteria section
                        toggleCriteriaFields();
                    }, 300);
                }, 100);
                
                document.getElementById('commentModal').style.display = 'block';
            };

            window.closeModal = function() {
                document.getElementById('commentModal').style.display = 'none';
            };

            // Close modal when clicking outside
            window.onclick = function(event) {
                const modal = document.getElementById('commentModal');
                if (event.target == modal) {
                    closeModal();
                }
            };

            // Helper functions for AJAX loading
            function loadFilterTerms(academicYearId) {
                if (!academicYearId) return;
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_terms_by_academic_year',
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.terms.length > 0) {
                            let options = '<option value="">All Terms</option>';
                            $.each(data.terms, function(index, term) {
                                const selected = (term.id == selectedTerm) ? 'selected' : '';
                                options += `<option value="${term.id}" ${selected}>${term.term_name}</option>`;
                            });
                            $('#filter_term').html(options);
                        } else {
                            $('#filter_term').html('<option value="">No terms found</option>');
                        }
                    }
                });
            }

            function loadFilterClasses(levelId, academicYearId) {
                if (!levelId || !academicYearId) return;
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_classes_by_level',
                        level_id: levelId,
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.classes.length > 0) {
                            let options = '<option value="">All Classes</option>';
                            $.each(data.classes, function(index, cls) {
                                const selected = (cls.id == selectedClass) ? 'selected' : '';
                                options += `<option value="${cls.id}" ${selected}>${cls.name}</option>`;
                            });
                            $('#filter_class').html(options);
                        } else {
                            $('#filter_class').html('<option value="">No classes found</option>');
                        }
                    }
                });
            }

            function loadFilterStreams(classId, academicYearId) {
                if (!classId || !academicYearId) return;
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_streams_by_class',
                        class_id: classId,
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.streams.length > 0) {
                            let options = '<option value="">All Streams</option>';
                            $.each(data.streams, function(index, stream) {
                                const selected = (stream.id == selectedStream) ? 'selected' : '';
                                options += `<option value="${stream.id}" ${selected}>${stream.name}</option>`;
                            });
                            $('#filter_stream').html(options);
                        } else {
                            $('#filter_stream').html('<option value="">No streams found</option>');
                        }
                    }
                });
            }

            function loadModalTerms(academicYearId) {
                if (!academicYearId) {
                    $('#term').html('<option value="">Select Academic Year First</option>');
                    return;
                }
                
                $('#term').html('<option value="">Loading...</option>');
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_terms_by_academic_year',
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.terms.length > 0) {
                            let options = '<option value="">Select Term</option>';
                            $.each(data.terms, function(index, term) {
                                options += `<option value="${term.id}">${term.term_name}</option>`;
                            });
                            $('#term').html(options);
                        } else {
                            $('#term').html('<option value="">No terms found</option>');
                        }
                    },
                    error: function() {
                        $('#term').html('<option value="">Error loading terms</option>');
                    }
                });
            }

            function loadModalClasses(levelId, academicYearId) {
                if (!levelId || !academicYearId) {
                    $('#class').html('<option value="">All Classes</option>');
                    return;
                }
                
                $('#class').html('<option value="">Loading...</option>');
                $('#stream').html('<option value="">All Streams</option>');
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_classes_by_level',
                        level_id: levelId,
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.classes.length > 0) {
                            let options = '<option value="">All Classes</option>';
                            $.each(data.classes, function(index, cls) {
                                options += `<option value="${cls.id}">${cls.name}</option>`;
                            });
                            $('#class').html(options);
                        } else {
                            $('#class').html('<option value="">No classes found</option>');
                        }
                    },
                    error: function() {
                        $('#class').html('<option value="">Error loading classes</option>');
                    }
                });
            }

            function loadModalStreams(classId, academicYearId) {
                if (!classId || !academicYearId) {
                    $('#stream').html('<option value="">All Streams</option>');
                    return;
                }
                
                $('#stream').html('<option value="">Loading...</option>');
                
                $.ajax({
                    url: 'ajax_comment_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_streams_by_class',
                        class_id: classId,
                        academic_year_id: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.streams.length > 0) {
                            let options = '<option value="">All Streams</option>';
                            $.each(data.streams, function(index, stream) {
                                options += `<option value="${stream.id}">${stream.name}</option>`;
                            });
                            $('#stream').html(options);
                        } else {
                            $('#stream').html('<option value="">No streams found</option>');
                        }
                    },
                    error: function() {
                        $('#stream').html('<option value="">Error loading streams</option>');
                    }
                });
            }
        });
    </script>
</body>

</html>