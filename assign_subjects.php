<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch admin info
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$admin) {
        throw new Exception("Admin user not found.");
    }
    $fullname = htmlspecialchars($admin['fullname']);
    $email = htmlspecialchars($admin['email']);
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}

// Initialize variables
$message = '';
$message_type = '';

// Get all teachers without assigned subjects or classes
try {
    $stmt = $pdo->prepare("
        SELECT u.id, u.fullname, u.email, u.username
        FROM users u
        WHERE u.role = 'teacher' 
        AND u.status = 'active'
        AND NOT EXISTS (
            SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id = u.id
        )
        AND NOT EXISTS (
            SELECT 1 FROM teacher_classes tc WHERE tc.teacher_id = u.id
        )
        ORDER BY u.fullname
    ");
    $stmt->execute();
    $available_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $available_teachers = [];
    $message = "Error fetching teachers: " . $e->getMessage();
    $message_type = 'error';
}

// Get all subjects
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.name, s.code, l.name as level_name
        FROM subjects s
        JOIN levels l ON s.level_id = l.id
        WHERE s.status = 'active'
        ORDER BY l.name, s.name
    ");
    $stmt->execute();
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $subjects = [];
    $message = "Error fetching subjects: " . $e->getMessage();
    $message_type = 'error';
}

// Get all classes
try {
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, l.name as level_name
        FROM classes c
        JOIN levels l ON c.level_id = l.id
        WHERE c.status = 'active'
        ORDER BY l.name, c.name
    ");
    $stmt->execute();
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $classes = [];
    $message = "Error fetching classes: " . $e->getMessage();
    $message_type = 'error';
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_subjects'])) {
    try {
        $pdo->beginTransaction();

        $teacher_id = $_POST['teacher_id'] ?? null;
        $subject_ids = $_POST['subject_ids'] ?? [];
        $class_ids = $_POST['class_ids'] ?? [];

        if (empty($teacher_id)) {
            throw new Exception("Please select a teacher.");
        }

        if (empty($subject_ids) && empty($class_ids)) {
            throw new Exception("Please select at least one subject or class to assign.");
        }

        // Check if teacher still doesn't have assignments
        $check_stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM teacher_subjects 
            WHERE teacher_id = ?
            UNION ALL
            SELECT COUNT(*) 
            FROM teacher_classes 
            WHERE teacher_id = ?
        ");
        $check_stmt->execute([$teacher_id, $teacher_id]);
        $counts = $check_stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($counts[0] > 0 || $counts[1] > 0) {
            throw new Exception("This teacher already has assignments. Please select another teacher.");
        }

        // Assign subjects
        if (!empty($subject_ids)) {
            $subject_stmt = $pdo->prepare("
                INSERT INTO teacher_subjects (teacher_id, subject_id, assigned_by, assigned_at) 
                VALUES (?, ?, ?, NOW())
            ");

            foreach ($subject_ids as $subject_id) {
                $subject_stmt->execute([$teacher_id, $subject_id, $_SESSION['user_id']]);
            }
        }

        // Assign classes
        if (!empty($class_ids)) {
            $class_stmt = $pdo->prepare("
                INSERT INTO teacher_classes (teacher_id, class_id, assigned_by, assigned_at) 
                VALUES (?, ?, ?, NOW())
            ");

            foreach ($class_ids as $class_id) {
                $class_stmt->execute([$teacher_id, $class_id, $_SESSION['user_id']]);
            }
        }

        // Log activity
        $activity_stmt = $pdo->prepare("
            INSERT INTO activity_logs (user_id, action, description, created_at) 
            VALUES (?, 'assign_subjects', ?, NOW())
        ");
        $teacher_name = '';
        foreach ($available_teachers as $teacher) {
            if ($teacher['id'] == $teacher_id) {
                $teacher_name = $teacher['fullname'];
                break;
            }
        }

        $subject_count = count($subject_ids);
        $class_count = count($class_ids);
        $description = "Assigned ";
        if ($subject_count > 0) {
            $description .= "$subject_count subject(s) ";
        }
        if ($class_count > 0) {
            if ($subject_count > 0) $description .= "and ";
            $description .= "$class_count class(es) ";
        }
        $description .= "to teacher: $teacher_name";

        $activity_stmt->execute([$_SESSION['user_id'], $description]);

        $pdo->commit();

        $message = "Subjects and classes assigned successfully!";
        $message_type = 'success';

        // Refresh available teachers list
        $stmt = $pdo->prepare("
            SELECT u.id, u.fullname, u.email, u.username
            FROM users u
            WHERE u.role = 'teacher' 
            AND u.status = 'active'
            AND NOT EXISTS (
                SELECT 1 FROM teacher_subjects ts WHERE ts.teacher_id = u.id
            )
            AND NOT EXISTS (
                SELECT 1 FROM teacher_classes tc WHERE tc.teacher_id = u.id
            )
            ORDER BY u.fullname
        ");
        $stmt->execute();
        $available_teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $pdo->rollBack();
        $message = $e->getMessage();
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Assign Subjects to Teachers</title>
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
            overflow: hidden;
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

        .header:hover {
            background: #f0f4ff;
        }

        .admin-info h1 {
            font-size: 1.5rem;
            color: var(--primary);
            margin: 0;
        }

        .admin-info p {
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
            padding: 1.4rem;
            flex: 1;
            overflow-y: auto;
            background: var(--body-bg);
        }

        /* Message Alert */
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideIn 0.3s ease;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-10px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #6c757d;
            font-size: 1rem;
            margin: 0;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .form-container h2 {
            color: var(--primary);
            font-size: 1.3rem;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid #eee;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }

        .required::after {
            content: " *";
            color: var(--secondary);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(26, 42, 108, 0.1);
        }

        .form-control[multiple] {
            min-height: 120px;
        }

        select.form-control[multiple] option {
            padding: 0.4rem 0.6rem;
            border-bottom: 1px solid #f8f9fa;
        }

        select.form-control[multiple] option:hover {
            background-color: #f8f9fa;
        }

        .select-all-container {
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .select-all-checkbox {
            margin: 0;
        }

        .select-all-label {
            font-size: 0.9rem;
            color: #6c757d;
            cursor: pointer;
        }

        .form-hint {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.4rem;
            display: block;
        }

        .form-hint i {
            color: var(--primary);
            margin-right: 0.3rem;
        }

        /* Selection Grid */
        .selection-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.8rem;
            margin-top: 0.5rem;
            max-height: 200px;
            overflow-y: auto;
            padding: 0.5rem;
            border: 1px solid #e9ecef;
            border-radius: 4px;
            background: #f8f9fa;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.4rem 0.6rem;
            background: white;
            border-radius: 4px;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }

        .checkbox-item:hover {
            background: #f8f9fa;
            border-color: #ced4da;
        }

        .checkbox-item input[type="checkbox"] {
            margin: 0;
        }

        .checkbox-label {
            font-size: 0.9rem;
            color: #495057;
            cursor: pointer;
        }

        /* Teacher Info */
        .teacher-info {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            border-left: 4px solid var(--primary);
            margin-bottom: 1.2rem;
        }

        .teacher-info h3 {
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
        }

        .teacher-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 0.8rem;
        }

        .teacher-detail-item {
            font-size: 0.9rem;
        }

        .teacher-detail-label {
            font-weight: 600;
            color: #6c757d;
            margin-right: 0.5rem;
        }

        .teacher-detail-value {
            color: #495057;
        }

        /* Button Group */
        .button-group {
            display: flex;
            gap: 0.8rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #eee;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 4px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn-primary {
            background-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background-color: #0f1d4d;
        }

        .btn-secondary {
            background-color: var(--secondary);
            color: white;
        }

        .btn-secondary:hover {
            background-color: #8f1717;
        }

        .btn-outline {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            color: #adb5bd;
            margin-bottom: 1rem;
        }

        .empty-state h3 {
            font-size: 1.2rem;
            margin-bottom: 0.5rem;
        }

        .empty-state p {
            font-size: 0.95rem;
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
            .main-content {
                padding: 1rem;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .form-container {
                padding: 1rem;
            }

            .selection-grid {
                grid-template-columns: 1fr;
            }

            .teacher-details {
                grid-template-columns: 1fr;
            }

            .button-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }
        }

        @media (max-width: 576px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
                padding: 1rem;
                height: auto;
                gap: 0.8rem;
            }

            .admin-info h1 {
                font-size: 1.3rem;
            }

            .role-tag {
                align-self: flex-start;
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
                <h1>Assign Subjects & Classes</h1>
                <p><?= $fullname ?> (<?= $email ?>)</p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <!-- Message Alert -->
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'error' ?>">
                    <i class="fas <?= $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Page Header -->
            <div class="page-header">
                <h1>Assign Subjects and Classes to Teachers</h1>
                <p>Assign subjects and/or classes to teachers who have no current assignments</p>
            </div>

            <?php if (empty($available_teachers)): ?>
                <!-- Empty State -->
                <div class="form-container">
                    <div class="empty-state">
                        <i class="fas fa-chalkboard-teacher"></i>
                        <h3>No Teachers Available</h3>
                        <p>All teachers already have subjects or classes assigned to them.</p>
                        <div class="button-group" style="justify-content: center; margin-top: 1.5rem;">
                            <a href="teachers.php" class="btn btn-primary">
                                <i class="fas fa-eye"></i> View All Teachers
                            </a>
                            <a href="add_teacher.php" class="btn btn-outline">
                                <i class="fas fa-plus"></i> Add New Teacher
                            </a>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Assignment Form -->
                <div class="form-container">
                    <form method="POST" id="assignmentForm">
                        <!-- Teacher Selection -->
                        <div class="form-group">
                            <label for="teacher_id" class="required">Select Teacher</label>
                            <select name="teacher_id" id="teacher_id" class="form-control" required>
                                <option value="">-- Choose a teacher --</option>
                                <?php foreach ($available_teachers as $teacher): ?>
                                    <option value="<?= $teacher['id'] ?>">
                                        <?= htmlspecialchars($teacher['fullname']) ?>
                                        (<?= htmlspecialchars($teacher['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-hint">
                                <i class="fas fa-info-circle"></i> Only teachers without any assigned subjects or classes are shown
                            </span>
                        </div>

                        <!-- Selected Teacher Info (will be populated via JavaScript) -->
                        <div id="teacherInfo" class="teacher-info" style="display: none;">
                            <h3><i class="fas fa-user-tie"></i> Selected Teacher</h3>
                            <div class="teacher-details" id="teacherDetails"></div>
                        </div>

                        <!-- Subjects Selection -->
                        <div class="form-group">
                            <label for="subject_ids">Assign Subjects (Optional)</label>
                            <div class="select-all-container">
                                <input type="checkbox" id="selectAllSubjects" class="select-all-checkbox">
                                <label for="selectAllSubjects" class="select-all-label">Select/Deselect All Subjects</label>
                            </div>
                            <div class="selection-grid">
                                <?php if (!empty($subjects)): ?>
                                    <?php foreach ($subjects as $subject): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="subject_ids[]" id="subject_<?= $subject['id'] ?>"
                                                value="<?= $subject['id'] ?>" class="subject-checkbox">
                                            <label for="subject_<?= $subject['id'] ?>" class="checkbox-label">
                                                <?= htmlspecialchars($subject['name']) ?>
                                                <small>(<?= htmlspecialchars($subject['level_name']) ?>)</small>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="grid-column: 1 / -1; text-align: center; padding: 1rem; color: #6c757d;">
                                        <i class="fas fa-book"></i> No subjects available
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="form-hint">
                                <i class="fas fa-info-circle"></i> Select subjects to assign to the teacher
                            </span>
                        </div>

                        <!-- Classes Selection -->
                        <div class="form-group">
                            <label for="class_ids">Assign Classes (Optional)</label>
                            <div class="select-all-container">
                                <input type="checkbox" id="selectAllClasses" class="select-all-checkbox">
                                <label for="selectAllClasses" class="select-all-label">Select/Deselect All Classes</label>
                            </div>
                            <div class="selection-grid">
                                <?php if (!empty($classes)): ?>
                                    <?php foreach ($classes as $class): ?>
                                        <div class="checkbox-item">
                                            <input type="checkbox" name="class_ids[]" id="class_<?= $class['id'] ?>"
                                                value="<?= $class['id'] ?>" class="class-checkbox">
                                            <label for="class_<?= $class['id'] ?>" class="checkbox-label">
                                                <?= htmlspecialchars($class['name']) ?>
                                                <small>(<?= htmlspecialchars($class['level_name']) ?>)</small>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div style="grid-column: 1 / -1; text-align: center; padding: 1rem; color: #6c757d;">
                                        <i class="fas fa-school"></i> No classes available
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="form-hint">
                                <i class="fas fa-info-circle"></i> Select classes to assign to the teacher
                            </span>
                        </div>

                        <!-- Validation Message -->
                        <div id="validationMessage" style="display: none;" class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span id="validationText"></span>
                        </div>

                        <!-- Submit Buttons -->
                        <div class="button-group">
                            <button type="submit" name="assign_subjects" class="btn btn-primary">
                                <i class="fas fa-check"></i> Assign Selected
                            </button>
                            <button type="reset" class="btn btn-outline" id="resetBtn">
                                <i class="fas fa-redo"></i> Reset Form
                            </button>
                            <a href="teachers.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Back to Teachers
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Instructions -->
                <div class="form-container">
                    <h2><i class="fas fa-info-circle"></i> Instructions</h2>
                    <div style="margin-top: 1rem;">
                        <div style="display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; padding: 0.8rem; background: #f8f9fa; border-radius: 6px;">
                            <i class="fas fa-check-circle" style="color: var(--primary); margin-top: 0.2rem;"></i>
                            <div>
                                <h4 style="margin: 0 0 0.3rem 0; color: #495057;">Requirements</h4>
                                <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">
                                    You must select at least one teacher, and at least one subject OR one class.
                                </p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 1rem; margin-bottom: 1rem; padding: 0.8rem; background: #f8f9fa; border-radius: 6px;">
                            <i class="fas fa-user-check" style="color: var(--primary); margin-top: 0.2rem;"></i>
                            <div>
                                <h4 style="margin: 0 0 0.3rem 0; color: #495057;">Eligible Teachers</h4>
                                <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">
                                    Only teachers with no assigned subjects or classes are shown in the dropdown.
                                </p>
                            </div>
                        </div>
                        <div style="display: flex; align-items: flex-start; gap: 1rem; padding: 0.8rem; background: #f8f9fa; border-radius: 6px;">
                            <i class="fas fa-sync-alt" style="color: var(--primary); margin-top: 0.2rem;"></i>
                            <div>
                                <h4 style="margin: 0 0 0.3rem 0; color: #495057;">After Assignment</h4>
                                <p style="margin: 0; color: #6c757d; font-size: 0.9rem;">
                                    Once assigned, teachers will appear in the "View Teachers" list with their assignments.
                                </p>
                            </div>
                        </div>
                    </div>
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

        // Teacher data for JavaScript
        const teachers = <?= json_encode($available_teachers) ?>;

        // Show teacher info when selected
        document.getElementById('teacher_id').addEventListener('change', function() {
            const teacherInfo = document.getElementById('teacherInfo');
            const teacherDetails = document.getElementById('teacherDetails');
            const teacherId = this.value;

            if (teacherId) {
                const teacher = teachers.find(t => t.id == teacherId);
                if (teacher) {
                    teacherDetails.innerHTML = `
                        <div class="teacher-detail-item">
                            <span class="teacher-detail-label">Full Name:</span>
                            <span class="teacher-detail-value">${teacher.fullname}</span>
                        </div>
                        <div class="teacher-detail-item">
                            <span class="teacher-detail-label">Email:</span>
                            <span class="teacher-detail-value">${teacher.email}</span>
                        </div>
                        <div class="teacher-detail-item">
                            <span class="teacher-detail-label">Username:</span>
                            <span class="teacher-detail-value">${teacher.username}</span>
                        </div>
                    `;
                    teacherInfo.style.display = 'block';
                }
            } else {
                teacherInfo.style.display = 'none';
            }
        });

        // Select/Deselect All Subjects
        document.getElementById('selectAllSubjects').addEventListener('change', function() {
            const subjectCheckboxes = document.querySelectorAll('.subject-checkbox');
            subjectCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Select/Deselect All Classes
        document.getElementById('selectAllClasses').addEventListener('change', function() {
            const classCheckboxes = document.querySelectorAll('.class-checkbox');
            classCheckboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });

        // Reset form
        document.getElementById('resetBtn').addEventListener('click', function(e) {
            e.preventDefault();

            // Reset form elements
            document.getElementById('assignmentForm').reset();

            // Hide teacher info
            document.getElementById('teacherInfo').style.display = 'none';

            // Uncheck select all checkboxes
            document.getElementById('selectAllSubjects').checked = false;
            document.getElementById('selectAllClasses').checked = false;

            // Hide validation message
            document.getElementById('validationMessage').style.display = 'none';

            // Show success message
            showNotification('Form has been reset.', 'info');
        });

        // Form validation
        document.getElementById('assignmentForm').addEventListener('submit', function(e) {
            const teacherId = document.getElementById('teacher_id').value;
            const subjectCheckboxes = document.querySelectorAll('.subject-checkbox:checked');
            const classCheckboxes = document.querySelectorAll('.class-checkbox:checked');
            const validationMessage = document.getElementById('validationMessage');
            const validationText = document.getElementById('validationText');

            // Clear previous validation
            validationMessage.style.display = 'none';

            if (!teacherId) {
                e.preventDefault();
                validationText.textContent = 'Please select a teacher.';
                validationMessage.style.display = 'block';
                return false;
            }

            if (subjectCheckboxes.length === 0 && classCheckboxes.length === 0) {
                e.preventDefault();
                validationText.textContent = 'Please select at least one subject or one class to assign.';
                validationMessage.style.display = 'block';
                return false;
            }

            return true;
        });

        // Show notification function
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 18px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                color: white;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 10000;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideIn 0.3s ease;
            `;

            // Add icon based on type
            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';

            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);

            // Add animation styles if not present
            if (!document.getElementById('notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%) translateY(-20px); opacity: 0; }
                        to { transform: translateX(0) translateY(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

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

        // Auto-check select-all if all checkboxes are checked
        function updateSelectAllCheckboxes() {
            const subjectCheckboxes = document.querySelectorAll('.subject-checkbox');
            const selectAllSubjects = document.getElementById('selectAllSubjects');
            const allSubjectsChecked = subjectCheckboxes.length > 0 &&
                Array.from(subjectCheckboxes).every(cb => cb.checked);
            selectAllSubjects.checked = allSubjectsChecked;

            const classCheckboxes = document.querySelectorAll('.class-checkbox');
            const selectAllClasses = document.getElementById('selectAllClasses');
            const allClassesChecked = classCheckboxes.length > 0 &&
                Array.from(classCheckboxes).every(cb => cb.checked);
            selectAllClasses.checked = allClassesChecked;
        }

        // Add event listeners to individual checkboxes
        document.querySelectorAll('.subject-checkbox, .class-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectAllCheckboxes);
        });

        // Initialize select-all state
        updateSelectAllCheckboxes();
    </script>
</body>

</html>