<?php
// edit_teachers.php
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Initialize variables
$message = '';
$message_type = '';
$teacher_id = '';
$teacher_details = [];
$assigned_classes = [];
$assigned_subjects = [];
$all_subjects = [];
$all_classes = [];
$all_levels = [];
$all_streams = [];
$teacher_assignments = [];

// Fetch all subjects, classes, levels, and streams for dropdowns
try {
    // Get all levels first
    $stmt = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY name");
    $all_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get all subjects grouped by level
    $stmt = $pdo->query("
        SELECT s.id, s.code, s.name, s.level_id, l.name as level_name 
        FROM subjects s 
        JOIN levels l ON s.level_id = l.id 
        WHERE s.status = 'active' 
        ORDER BY l.name, s.code
    ");
    $all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group subjects by level
    $subjects_by_level = [];
    foreach ($all_subjects as $subject) {
        $level_id = $subject['level_id'];
        if (!isset($subjects_by_level[$level_id])) {
            $subjects_by_level[$level_id] = [
                'level_name' => $subject['level_name'],
                'subjects' => []
            ];
        }
        $subjects_by_level[$level_id]['subjects'][] = $subject;
    }

    // Get all classes grouped by level
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.level_id, l.name as level_name 
        FROM classes c 
        JOIN levels l ON c.level_id = l.id 
        WHERE c.status = 'active' 
        ORDER BY l.name, c.name
    ");
    $all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group classes by level
    $classes_by_level = [];
    foreach ($all_classes as $class) {
        $level_id = $class['level_id'];
        if (!isset($classes_by_level[$level_id])) {
            $classes_by_level[$level_id] = [
                'level_name' => $class['level_name'],
                'classes' => []
            ];
        }
        $classes_by_level[$level_id]['classes'][] = $class;
    }

    // Get all streams
    $stmt = $pdo->query("
        SELECT s.id, s.name, c.name as class_name, s.class_id, c.level_id, l.name as level_name
        FROM streams s 
        JOIN classes c ON s.class_id = c.id
        JOIN levels l ON c.level_id = l.id
        WHERE s.status = 'active' 
        ORDER BY l.name, c.name, s.name
    ");
    $all_streams = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group streams by class
    $streams_by_class = [];
    foreach ($all_streams as $stream) {
        $class_id = $stream['class_id'];
        if (!isset($streams_by_class[$class_id])) {
            $streams_by_class[$class_id] = [];
        }
        $streams_by_class[$class_id][] = $stream;
    }
} catch (PDOException $e) {
    $message = "Error loading reference data: " . $e->getMessage();
    $message_type = "error";
}

// Handle edit request
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $teacher_id = intval($_GET['edit']);

    try {
        // Get teacher basic info from users table
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, username, email, fullname, gender, role, status, position 
            FROM users 
            WHERE id = ? AND role = 'teacher'
        ");
        $stmt->execute([$teacher_id]);
        $teacher_details = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$teacher_details) {
            $message = "Teacher not found.";
            $message_type = "error";
        } else {
            // Get assigned classes
            $stmt = $pdo->prepare("
                SELECT tc.class_id, c.name as class_name, tc.stream_id, s.name as stream_name,
                       tc.academic_year_id, tc.is_class_teacher, c.level_id, l.name as level_name
                FROM teacher_classes tc
                JOIN classes c ON tc.class_id = c.id
                JOIN levels l ON c.level_id = l.id
                LEFT JOIN streams s ON tc.stream_id = s.id
                WHERE tc.teacher_id = ?
                ORDER BY l.name, c.name
            ");
            $stmt->execute([$teacher_id]);
            $assigned_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get assigned subjects
            $stmt = $pdo->prepare("
                SELECT ts.subject_id, s.code, s.name as subject_name, ts.level_id, l.name as level_name, ts.is_primary
                FROM teacher_subjects ts
                JOIN subjects s ON ts.subject_id = s.id
                JOIN levels l ON ts.level_id = l.id
                WHERE ts.teacher_id = ?
                ORDER BY ts.level_id, s.code
            ");
            $stmt->execute([$teacher_id]);
            $assigned_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get teacher assignments
            $stmt = $pdo->prepare("
                SELECT ta.id, ta.assignment_type, ta.level_id, ta.class_id, ta.stream_id, 
                       ta.subject_id, ta.is_primary, ta.start_date, ta.end_date, ta.status, ta.notes,
                       l.name as level_name, c.name as class_name, s.name as stream_name, sub.name as subject_name
                FROM teacher_assignments ta
                LEFT JOIN levels l ON ta.level_id = l.id
                LEFT JOIN classes c ON ta.class_id = c.id
                LEFT JOIN streams s ON ta.stream_id = s.id
                LEFT JOIN subjects sub ON ta.subject_id = sub.id
                WHERE ta.teacher_id = ? AND ta.status = 'active'
                ORDER BY ta.start_date DESC
            ");
            $stmt->execute([$teacher_id]);
            $teacher_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $message = "Error fetching teacher details: " . $e->getMessage();
        $message_type = "error";
    }
}

// Handle form submission for updating teacher
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_teacher'])) {
    $teacher_id = intval($_POST['teacher_id']);

    try {
        $pdo->beginTransaction();

        // Update basic teacher info in users table
        $stmt = $pdo->prepare("
            UPDATE users 
            SET fullname = ?, email = ?, gender = ?, position = ?, status = ?
            WHERE id = ? AND role = 'teacher'
        ");
        $stmt->execute([
            trim($_POST['fullname']),
            trim($_POST['email']),
            $_POST['gender'],
            $_POST['position'],
            $_POST['status'],
            $teacher_id
        ]);

        // Remove existing class assignments
        $stmt = $pdo->prepare("DELETE FROM teacher_classes WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);

        // Add new class assignments if provided
        if (isset($_POST['classes']) && is_array($_POST['classes'])) {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_classes (teacher_id, class_id, stream_id, academic_year_id, is_class_teacher) 
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($_POST['classes'] as $class_assignment) {
                $parts = explode('_', $class_assignment);
                $class_id = intval($parts[0]);
                $stream_id = isset($parts[1]) ? intval($parts[1]) : null;
                $is_class_teacher = isset($_POST['class_teacher'][$class_assignment]) ? 1 : 0;

                // Use current academic year
                $current_year = 1; // Default, you might want to fetch current academic year

                $stmt->execute([$teacher_id, $class_id, $stream_id, $current_year, $is_class_teacher]);
            }
        }

        // Remove existing subject assignments
        $stmt = $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?");
        $stmt->execute([$teacher_id]);

        // Add new subject assignments if provided
        if (isset($_POST['subjects']) && is_array($_POST['subjects'])) {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_subjects (teacher_id, subject_id, level_id, is_primary) 
                VALUES (?, ?, ?, ?)
            ");

            foreach ($_POST['subjects'] as $subject_id) {
                // Get subject level
                $subject_stmt = $pdo->prepare("SELECT level_id FROM subjects WHERE id = ?");
                $subject_stmt->execute([$subject_id]);
                $subject = $subject_stmt->fetch(PDO::FETCH_ASSOC);

                if ($subject) {
                    $is_primary = isset($_POST['primary_subject'][$subject_id]) ? 1 : 0;
                    $stmt->execute([$teacher_id, $subject_id, $subject['level_id'], $is_primary]);
                }
            }
        }

        // Update teacher assignments
        $stmt = $pdo->prepare("
            UPDATE teacher_assignments 
            SET status = 'inactive' 
            WHERE teacher_id = ? AND status = 'active'
        ");
        $stmt->execute([$teacher_id]);

        // Add new assignments
        if (isset($_POST['assignments']) && is_array($_POST['assignments'])) {
            $stmt = $pdo->prepare("
                INSERT INTO teacher_assignments 
                (teacher_id, academic_year_id, level_id, class_id, stream_id, subject_id, 
                 assignment_type, is_primary, start_date, end_date, status, notes) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($_POST['assignments'] as $assignment) {
                $stmt->execute([
                    $teacher_id,
                    $assignment['academic_year_id'] ?? 1,
                    $assignment['level_id'] ?? null,
                    $assignment['class_id'] ?? null,
                    $assignment['stream_id'] ?? null,
                    $assignment['subject_id'] ?? null,
                    $assignment['assignment_type'],
                    $assignment['is_primary'] ?? 0,
                    $assignment['start_date'] ?? date('Y-m-d'),
                    $assignment['end_date'] ?? null,
                    'active',
                    $assignment['notes'] ?? null
                ]);
            }
        }

        $pdo->commit();

        // Log the activity
        $log_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, description, ip_address) 
            VALUES (?, 'EDIT_TEACHER', ?, ?)
        ");
        $log_stmt->execute([
            $_SESSION['user_id'],
            "Updated teacher profile: " . trim($_POST['fullname']),
            $_SERVER['REMOTE_ADDR']
        ]);

        $message = "Teacher updated successfully!";
        $message_type = "success";

        // Refresh teacher data
        $stmt = $pdo->prepare("
            SELECT id, teacher_id, username, email, fullname, gender, role, status, position 
            FROM users 
            WHERE id = ? AND role = 'teacher'
        ");
        $stmt->execute([$teacher_id]);
        $teacher_details = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $message = "Error updating teacher: " . $e->getMessage();
        $message_type = "error";
    }
}

// Fetch all teachers for the table
try {
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $search_sql = '';
    $search_params = [];

    if ($search) {
        $search_sql = "WHERE (u.fullname LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.teacher_id LIKE ?)";
        $search_param = "%$search%";
        $search_params = [$search_param, $search_param, $search_param, $search_param];
    }

    $stmt = $pdo->prepare("
        SELECT u.id, u.teacher_id, u.username, u.email, u.fullname, u.gender, 
               u.position, u.status, u.created_at,
               COUNT(DISTINCT tc.class_id) as class_count,
               COUNT(DISTINCT ts.subject_id) as subject_count
        FROM users u
        LEFT JOIN teacher_classes tc ON u.id = tc.teacher_id
        LEFT JOIN teacher_subjects ts ON u.id = ts.teacher_id
        WHERE u.role = 'teacher'
        GROUP BY u.id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $teachers = [];
    $message = "Error loading teachers: " . $e->getMessage();
    $message_type = "error";
}

// Get admin info for header
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($admin['fullname'] ?? 'Admin');
    $email = htmlspecialchars($admin['email'] ?? '—');
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Edit Teachers - School Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
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
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        html,
        body {
            height: 100%;
        }

        body {
            display: flex;
            background-color: var(--body-bg);
            color: var(--text-dark);
            height: 100vh;
        }

        /* Sidebar - Same as admin_dashboard.php */
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

        .nav-link:hover {
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

        .nav-link:hover i {
            color: #ecf0f1;
        }

        .dropdown-toggle::after {
            content: "▶";
            margin-left: auto;
            font-size: 0.7rem;
            color: #7f8c8d;
            transition: transform 0.3s;
            transform: rotate(0deg);
        }

        .dropdown.active>.nav-link.dropdown-toggle::after,
        .nested.active>.nav-link.dropdown-toggle::after {
            transform: rotate(90deg);
            color: #ecf0f1;
        }

        .dropdown-menu,
        .nested-menu {
            list-style: none;
            padding-left: 1.2rem;
            max-height: 0;
            overflow: hidden;
            background: rgba(0, 0, 0, 0.1);
            transition: max-height 0.3s ease;
        }

        .dropdown.active>.dropdown-menu {
            max-height: 1000px;
            padding: 0.45rem 0 0.45rem 1.2rem;
        }

        .nested.active>.nested-menu {
            max-height: 500px;
            padding: 0.3rem 0 0.3rem 1.2rem;
            background: rgba(0, 0, 0, 0.15);
        }

        .nested-menu .nav-link {
            padding: 0.5rem 0.7rem;
            font-size: 0.9rem;
            color: #d5dbdb;
        }

        .nested-menu .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
            padding-left: 0.9rem;
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
            cursor: pointer;
        }

        .admin-info h1 {
            font-size: 1.5rem;
            color: var(--primary);
            margin: 0;
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
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: background 0.2s;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .btn.secondary {
            background: var(--secondary);
        }

        .btn.secondary:hover {
            background: #8f1717;
        }

        /* Message Alert */
        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Search Bar */
        .search-bar {
            margin-bottom: 1rem;
            display: flex;
            gap: 0.5rem;
        }

        .search-input {
            flex: 1;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        /* Teachers Table */
        .table-container {
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 1rem;
        }

        .teachers-table {
            width: 100%;
            border-collapse: collapse;
        }

        .teachers-table th {
            background: var(--primary);
            color: white;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .teachers-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            font-size: 0.9rem;
        }

        .teachers-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 3px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Edit Form */
        .edit-form-container {
            background: white;
            border-radius: 6px;
            padding: 1.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1rem;
            display: <?= $teacher_details ? 'block' : 'none' ?>;
        }

        .edit-form-container h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #495057;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: border-color 0.15s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 1rem;
            margin-bottom: 1rem;
        }

        .form-section h4 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }

        .level-group {
            margin-bottom: 1.5rem;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 1rem;
            background: white;
        }

        .level-group h5 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1rem;
            background: rgba(26, 42, 108, 0.1);
            padding: 0.75rem;
            border-radius: 4px;
            border-left: 4px solid var(--primary);
        }

        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 0.75rem;
        }

        .class-assignment {
            margin-bottom: 1rem;
            padding: 0.75rem;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            background: #f8f9fa;
        }

        .class-assignment:last-child {
            margin-bottom: 0;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.25rem 0;
        }

        .checkbox-item label {
            margin: 0;
            font-size: 0.9rem;
            cursor: pointer;
        }

        .streams-section {
            margin-left: 1.5rem;
            margin-top: 0.5rem;
            padding-left: 1rem;
            border-left: 2px dashed #adb5bd;
        }

        .streams-section small {
            color: #6c757d;
            font-size: 0.85rem;
            margin-bottom: 0.5rem;
            display: block;
        }

        .class-teacher-checkbox {
            margin-left: 1.5rem;
            margin-top: 0.5rem;
            padding: 0.5rem;
            background: rgba(26, 42, 108, 0.05);
            border-radius: 4px;
        }

        .subject-level-group {
            margin-bottom: 1.5rem;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 1rem;
            background: white;
        }

        .subject-level-group h5 {
            color: var(--primary);
            margin-bottom: 0.75rem;
            font-size: 1rem;
            background: rgba(26, 42, 108, 0.1);
            padding: 0.5rem;
            border-radius: 4px;
            border-left: 4px solid var(--secondary);
        }

        .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            padding-top: 1rem;
            border-top: 1px solid #eee;
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

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after,
            .sidebar .nested>.nav-link::after {
                opacity: 0;
                transition: opacity 0.3s;
                white-space: nowrap;
            }

            .sidebar:hover .sidebar-header span,
            .sidebar:hover .nav-link span,
            .sidebar:hover .dropdown-toggle::after,
            .sidebar:hover .nested>.nav-link::after {
                opacity: 1;
            }

            .sidebar .nav-link {
                justify-content: center;
                padding: 0.8rem;
            }

            .sidebar:hover .nav-link {
                justify-content: flex-start;
                padding: 0.8rem 1.2rem;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 1.1rem;
            }

            .sidebar:hover .nav-link i {
                margin-right: 12px;
            }

            .dropdown-menu,
            .nested-menu {
                display: none !important;
            }

            .sidebar:hover .dropdown.active>.dropdown-menu,
            .sidebar:hover .nested.active>.nested-menu {
                display: block !important;
            }

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .sidebar:hover+.main-wrapper {
                margin-left: 280px;
                width: calc(100% - 280px);
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .button-group {
                width: 100%;
                justify-content: flex-start;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .checkbox-group {
                grid-template-columns: 1fr;
            }

            .teachers-table {
                font-size: 0.8rem;
            }

            .teachers-table th,
            .teachers-table td {
                padding: 0.5rem;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-school"></i>
            <span>School Admin</span>
        </div>
        <ul class="nav-menu">
            <!-- Teacher Management -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <span>Teacher Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_teacher.php" class="nav-link">Add Teacher</a></li>
                    <li><a href="assign_subjects.php" class="nav-link">Assign Subjects</a></li>
                    <li><a href="teachers.php" class="nav-link">View Teachers</a></li>
                    <li><a href="edit_teachers.php" class="nav-link">Edit Teachers</a></li>
                    <li><a href="delete_teacher.php" class="nav-link">Delete Teacher</a></li>
                </ul>
            </li>

            <!-- Student Management -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-user-graduate"></i>
                    <span>Student Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_student.php" class="nav-link">Add Learner</a></li>
                    <li><a href="students.php" class="nav-link">View Learners</a></li>
                    <li><a href="promote_students.php" class="nav-link">Promote Learners</a></li>
                    <li><a href="archive_students.php" class="nav-link">Archive Learners</a></li>
                    <li><a href="archived_students.php" class="nav-link">View Archived Learners</a></li>
                </ul>
            </li>

            <!-- Classes & Stream -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-school"></i>
                    <span>Classes & Stream</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_level.php" class="nav-link">Add Level</a></li>
                    <li><a href="manage_levels.php" class="nav-link">Manage Levels</a></li>
                    <li><a href="add_class.php" class="nav-link">Add Class</a></li>
                    <li><a href="manage_classes.php" class="nav-link">Manage Classes</a></li>
                    <li><a href="add_stream.php" class="nav-link">Add Stream</a></li>
                    <li><a href="manage_streams.php" class="nav-link">Manage Streams</a></li>
                </ul>
            </li>

            <!-- Subjects -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-book"></i>
                    <span>Subjects Management</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="add_subject.php" class="nav-link">Add Subject</a></li>
                    <li><a href="subjects.php" class="nav-link">View Subjects</a></li>
                    <li><a href="manage_subjects.php" class="nav-link">Manage Subjects</a></li>
                </ul>
            </li>

            <!-- Assessment -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-chart-bar"></i>
                    <span>Assessment</span>
                </a>
                <ul class="dropdown-menu">
                    <!-- O-Level -->
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>O-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="enter_marks.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marksheets.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_o_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <!-- A-Level -->
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>A-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="enter_marks.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marksheets.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_a_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <!-- Removed Examinations section -->
                    <li><a href="reports.php" class="nav-link">Reports</a></li>
                    <li><a href="academic_calendar.php" class="nav-link">Academic Calendar</a></li>
                </ul>
            </li>

            <!-- More -->
            <li class="nav-item dropdown">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-cog"></i>
                    <span>More</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="export_logs.php" class="nav-link">Export Logs</a></li>
                    <li><a href="settings.php" class="nav-link">Settings</a></li>
                    <li><a href="profile.php" class="nav-link">Profile</a></li>
                </ul>
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
        <header class="header" onclick="window.location='admin_dashboard.php'">
            <div class="admin-info">
                <h1>Edit Teachers</h1>
                <p>Manage teacher profiles and assignments</p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert <?= $message_type ?>">
                    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <span><?= htmlspecialchars($message) ?></span>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <h2>Teacher Management</h2>
                <div class="button-group">
                    <a href="teachers.php" class="btn">
                        <i class="fas fa-list"></i> View All
                    </a>
                    <a href="add_teacher.php" class="btn secondary">
                        <i class="fas fa-plus"></i> Add New
                    </a>
                </div>
            </div>

            <!-- Search Bar -->
            <form method="GET" class="search-bar">
                <input type="text" name="search" class="search-input"
                    placeholder="Search by name, email, or ID..."
                    value="<?= htmlspecialchars($search ?? '') ?>">
                <button type="submit" class="btn">
                    <i class="fas fa-search"></i> Search
                </button>
                <?php if ($search): ?>
                    <a href="edit_teachers.php" class="btn" style="background: #6c757d;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
            </form>

            <!-- Teachers Table -->
            <div class="table-container">
                <table class="teachers-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Gender</th>
                            <th>Position</th>
                            <th>Status</th>
                            <th>Classes/Subjects</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($teachers)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; padding: 2rem;">
                                    No teachers found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($teachers as $teacher): ?>
                                <tr>
                                    <td><?= htmlspecialchars($teacher['teacher_id']) ?></td>
                                    <td><strong><?= htmlspecialchars($teacher['fullname']) ?></strong></td>
                                    <td><?= htmlspecialchars($teacher['username']) ?></td>
                                    <td><?= htmlspecialchars($teacher['email']) ?></td>
                                    <td><?= ucfirst($teacher['gender']) ?></td>
                                    <td><?= htmlspecialchars($teacher['position']) ?></td>
                                    <td>
                                        <span class="status-badge status-<?= $teacher['status'] ?>">
                                            <?= ucfirst($teacher['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?= $teacher['class_count'] ?> classes,
                                            <?= $teacher['subject_count'] ?> subjects
                                        </small>
                                    </td>
                                    <td>
                                        <a href="?edit=<?= $teacher['id'] ?>" class="btn" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;">
                                            <i class="fas fa-edit"></i> Edit
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Edit Form -->
            <?php if ($teacher_details): ?>
                <div class="edit-form-container" id="editForm">
                    <h3>Edit Teacher: <?= htmlspecialchars($teacher_details['fullname']) ?></h3>

                    <form method="POST" action="">
                        <input type="hidden" name="teacher_id" value="<?= $teacher_id ?>">
                        <input type="hidden" name="update_teacher" value="1">

                        <!-- Basic Information -->
                        <div class="form-section">
                            <h4>Basic Information</h4>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label for="fullname">Full Name *</label>
                                    <input type="text" id="fullname" name="fullname" class="form-control"
                                        value="<?= htmlspecialchars($teacher_details['fullname']) ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="email">Email *</label>
                                    <input type="email" id="email" name="email" class="form-control"
                                        value="<?= htmlspecialchars($teacher_details['email']) ?>" required>
                                </div>

                                <div class="form-group">
                                    <label for="gender">Gender</label>
                                    <select id="gender" name="gender" class="form-control" required>
                                        <option value="male" <?= $teacher_details['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                                        <option value="female" <?= $teacher_details['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="position">Position</label>
                                    <select id="position" name="position" class="form-control" required>
                                        <option value="Teacher" <?= $teacher_details['position'] === 'Teacher' ? 'selected' : '' ?>>Teacher</option>
                                        <option value="Class Teacher" <?= $teacher_details['position'] === 'Class Teacher' ? 'selected' : '' ?>>Class Teacher</option>
                                        <option value="Head Teacher" <?= $teacher_details['position'] === 'Head Teacher' ? 'selected' : '' ?>>Head Teacher</option>
                                        <option value="Head of Department" <?= $teacher_details['position'] === 'Head of Department' ? 'selected' : '' ?>>Head of Department</option>
                                        <option value="Director of Studies" <?= $teacher_details['position'] === 'Director of Studies' ? 'selected' : '' ?>>Director of Studies</option>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="status">Status</label>
                                    <select id="status" name="status" class="form-control" required>
                                        <option value="active" <?= $teacher_details['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="inactive" <?= $teacher_details['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Class Assignments - Grouped by Level -->
                        <div class="form-section">
                            <h4>Class Assignments</h4>
                            <?php
                            $assigned_class_ids = array_column($assigned_classes, 'class_id');

                            if (!empty($classes_by_level)):
                                foreach ($classes_by_level as $level_id => $level_data):
                            ?>
                                    <div class="level-group">
                                        <h5><?= htmlspecialchars($level_data['level_name']) ?> Classes</h5>
                                        <div class="checkbox-group">
                                            <?php foreach ($level_data['classes'] as $class):
                                                $class_streams = isset($streams_by_class[$class['id']]) ? $streams_by_class[$class['id']] : [];
                                                $is_class_teacher = array_filter($assigned_classes, function ($ac) use ($class) {
                                                    return $ac['class_id'] == $class['id'] && $ac['is_class_teacher'] == 1;
                                                });
                                            ?>
                                                <div class="class-assignment">
                                                    <div class="checkbox-item">
                                                        <input type="checkbox"
                                                            id="class_<?= $class['id'] ?>"
                                                            name="classes[]"
                                                            value="<?= $class['id'] ?>"
                                                            <?= in_array($class['id'], $assigned_class_ids) ? 'checked' : '' ?>>
                                                        <label for="class_<?= $class['id'] ?>" style="margin: 0;">
                                                            <strong><?= htmlspecialchars($class['name']) ?></strong>
                                                        </label>
                                                    </div>

                                                    <?php if (!empty($class_streams)): ?>
                                                        <div class="streams-section">
                                                            <small>Streams:</small>
                                                            <?php foreach ($class_streams as $stream):
                                                                $assigned = array_filter($assigned_classes, function ($ac) use ($class, $stream) {
                                                                    return $ac['class_id'] == $class['id'] && $ac['stream_id'] == $stream['id'];
                                                                });
                                                            ?>
                                                                <div class="checkbox-item" style="margin-top: 0.25rem;">
                                                                    <input type="checkbox"
                                                                        id="class_<?= $class['id'] ?>_<?= $stream['id'] ?>"
                                                                        name="classes[]"
                                                                        value="<?= $class['id'] ?>_<?= $stream['id'] ?>"
                                                                        <?= !empty($assigned) ? 'checked' : '' ?>>
                                                                    <label for="class_<?= $class['id'] ?>_<?= $stream['id'] ?>" style="margin: 0; font-size: 0.85rem;">
                                                                        <?= htmlspecialchars($stream['name']) ?>
                                                                    </label>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    <?php endif; ?>

                                                    <div class="class-teacher-checkbox">
                                                        <div class="checkbox-item">
                                                            <input type="checkbox"
                                                                id="ct_<?= $class['id'] ?>"
                                                                name="class_teacher[<?= $class['id'] ?>]"
                                                                value="1"
                                                                <?= !empty($is_class_teacher) ? 'checked' : '' ?>>
                                                            <label for="ct_<?= $class['id'] ?>" style="margin: 0; font-size: 0.85rem; color: var(--primary);">
                                                                <i class="fas fa-user-tie"></i> Class Teacher for <?= htmlspecialchars($class['name']) ?>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php
                                endforeach;
                            else:
                                ?>
                                <p style="color: #6c757d; text-align: center; padding: 1rem;">
                                    No classes available
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Subject Assignments - Grouped by Level -->
                        <div class="form-section">
                            <h4>Subject Assignments</h4>
                            <?php
                            $assigned_subject_ids = array_column($assigned_subjects, 'subject_id');

                            if (!empty($subjects_by_level)):
                                foreach ($subjects_by_level as $level_id => $level_data):
                            ?>
                                    <div class="subject-level-group">
                                        <h5><?= htmlspecialchars($level_data['level_name']) ?> Subjects</h5>
                                        <div class="checkbox-group">
                                            <?php foreach ($level_data['subjects'] as $subject):
                                                $is_primary = array_filter($assigned_subjects, function ($as) use ($subject) {
                                                    return $as['subject_id'] == $subject['id'] && $as['is_primary'] == 1;
                                                });
                                            ?>
                                                <div class="checkbox-item">
                                                    <input type="checkbox"
                                                        id="subject_<?= $subject['id'] ?>"
                                                        name="subjects[]"
                                                        value="<?= $subject['id'] ?>"
                                                        <?= in_array($subject['id'], $assigned_subject_ids) ? 'checked' : '' ?>>
                                                    <label for="subject_<?= $subject['id'] ?>">
                                                        <?= htmlspecialchars($subject['code']) ?> -
                                                        <?= htmlspecialchars($subject['name']) ?>
                                                    </label>

                                                    <?php if (in_array($subject['id'], $assigned_subject_ids)): ?>
                                                        <div class="checkbox-item" style="margin-left: 1rem; display: inline-block;">
                                                            <input type="checkbox"
                                                                id="primary_<?= $subject['id'] ?>"
                                                                name="primary_subject[<?= $subject['id'] ?>]"
                                                                value="1"
                                                                <?= !empty($is_primary) ? 'checked' : '' ?>>
                                                            <label for="primary_<?= $subject['id'] ?>" style="font-size: 0.8rem; color: var(--primary);">
                                                                Primary
                                                            </label>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php
                                endforeach;
                            else:
                                ?>
                                <p style="color: #6c757d; text-align: center; padding: 1rem;">
                                    No subjects available
                                </p>
                            <?php endif; ?>
                        </div>

                        <!-- Form Actions -->
                        <div class="form-actions">
                            <button type="submit" class="btn secondary">
                                <i class="fas fa-save"></i> Update Teacher
                            </button>
                            <a href="edit_teachers.php" class="btn" style="background: #6c757d;">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Toggle top-level dropdowns
        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.dropdown');
                parent.classList.toggle('active');
            });
        });

        // Toggle nested dropdowns
        document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.nested');
                parent.classList.toggle('active');
            });
        });

        // Scroll to edit form when editing a teacher
        <?php if ($teacher_details): ?>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('editForm').scrollIntoView({
                    behavior: 'smooth'
                });
            });
        <?php endif; ?>

        // Handle class teacher checkbox - only one per class
        document.addEventListener('change', function(e) {
            if (e.target.name === 'class_teacher[]') {
                const classId = e.target.id.split('_')[1];
                const checkboxes = document.querySelectorAll(`input[name="class_teacher[]"][id^="ct_${classId}"]`);

                if (e.target.checked) {
                    checkboxes.forEach(cb => {
                        if (cb !== e.target) {
                            cb.checked = false;
                        }
                    });
                }
            }
        });

        // Confirm before submitting form
        document.querySelector('form')?.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to update this teacher?')) {
                e.preventDefault();
            }
        });

        // Auto-check stream when class is checked with stream
        document.addEventListener('change', function(e) {
            if (e.target.name === 'classes[]' && e.target.value.includes('_')) {
                if (e.target.checked) {
                    const classId = e.target.value.split('_')[0];
                    const classCheckbox = document.getElementById(`class_${classId}`);
                    if (classCheckbox) {
                        classCheckbox.checked = true;
                    }
                }
            }
        });

        // Auto-check primary checkbox when subject is checked
        document.addEventListener('change', function(e) {
            if (e.target.name === 'subjects[]') {
                const subjectId = e.target.value;
                const primaryCheckbox = document.getElementById(`primary_${subjectId}`);
                if (primaryCheckbox) {
                    if (e.target.checked) {
                        primaryCheckbox.disabled = false;
                    } else {
                        primaryCheckbox.disabled = true;
                        primaryCheckbox.checked = false;
                    }
                }
            }
        });

        // Fix sidebar hover behavior for mobile
        const sidebar = document.querySelector('.sidebar');
        if (window.innerWidth <= 992) {
            let hoverTimeout;

            sidebar.addEventListener('mouseenter', function() {
                clearTimeout(hoverTimeout);
                this.style.width = '280px';
            });

            sidebar.addEventListener('mouseleave', function() {
                hoverTimeout = setTimeout(() => {
                    this.style.width = '70px';
                }, 300);
            });
        }

        // Initialize checkboxes state on page load
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input[name="subjects[]"]').forEach(checkbox => {
                if (!checkbox.checked) {
                    const subjectId = checkbox.value;
                    const primaryCheckbox = document.getElementById(`primary_${subjectId}`);
                    if (primaryCheckbox) {
                        primaryCheckbox.disabled = true;
                    }
                }
            });
        });
    </script>
</body>

</html>