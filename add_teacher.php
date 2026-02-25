<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$message = '';
$error = '';

// Fetch data for dropdowns
try {
    // Fetch levels
    $levels = $pdo->query("SELECT * FROM levels WHERE status = 'active' ORDER BY name")->fetchAll();

    // Fetch academic years
    $academic_years = $pdo->query("SELECT * FROM academic_years WHERE status = 'active' ORDER BY start_year DESC")->fetchAll();

    // Fetch current academic year
    $current_academic_year = $pdo->query("SELECT * FROM academic_years WHERE is_current = 1 LIMIT 1")->fetch();
} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
}

// Function to generate teacher ID
function generateTeacherID($pdo) {
    try {
        // Check if teacher_id column exists in users table
        $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
        $column_exists = $stmt->fetch();
        
        if (!$column_exists) {
            return "TCH/" . date('y') . "/001"; // Fallback
        }
        
        // Get current academic year prefix (e.g., 26 for 2026)
        $stmt = $pdo->query("SELECT start_year FROM academic_years WHERE is_current = 1 LIMIT 1");
        $current_year = $stmt->fetch();
        $year_prefix = substr($current_year['start_year'] ?? date('Y'), -2); // Last 2 digits
        
        // Get the highest teacher ID for the current year
        $stmt = $pdo->prepare("SELECT teacher_id FROM users WHERE teacher_id LIKE ? AND role = 'teacher' ORDER BY teacher_id DESC LIMIT 1");
        $stmt->execute(["TCH/{$year_prefix}/%"]);
        $last_teacher = $stmt->fetch();
        
        if ($last_teacher && preg_match('/TCH\/(\d{2})\/(\d{3})/', $last_teacher['teacher_id'], $matches)) {
            $next_number = str_pad((int)$matches[2] + 1, 3, '0', STR_PAD_LEFT);
            return "TCH/{$year_prefix}/{$next_number}";
        } else {
            // First teacher of the year
            return "TCH/{$year_prefix}/001";
        }
    } catch (Exception $e) {
        // Fallback ID
        $year = date('y');
        return "TCH/{$year}/001";
    }
}

// Check if teacher_id column exists
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
    $column_exists = $stmt->fetch();
    $teacher_id = $column_exists ? generateTeacherID($pdo) : '';
} catch (Exception $e) {
    $column_exists = false;
    $teacher_id = '';
}

// Handle AJAX request for generating new teacher ID
if (isset($_GET['generate_teacher_id'])) {
    header('Content-Type: text/plain');
    echo generateTeacherID($pdo);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic information
    $fullname = trim($_POST['fullname'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $teacher_id = trim($_POST['teacher_id'] ?? generateTeacherID($pdo)); // Use generated or form input
    $gender = $_POST['gender'] ?? '';
    $position = $_POST['position'] ?? 'Teacher';

    // Assignment information
    $academic_year_id = $_POST['academic_year_id'] ?? ($current_academic_year['id'] ?? null);
    $level_ids = $_POST['level_ids'] ?? [];
    $class_ids = $_POST['class_ids'] ?? [];
    $subject_ids = $_POST['subject_ids'] ?? [];
    $is_class_teacher = isset($_POST['is_class_teacher']) ? 1 : 0;
    $additional_responsibilities = $_POST['responsibilities'] ?? [];

    // Validation
    $errors = [];

    if (empty($fullname)) $errors[] = "Full name is required.";
    if (empty($username)) $errors[] = "Username is required.";
    if (empty($email)) $errors[] = "Email is required.";
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please provide a valid email address.";
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Check if username or email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetch()) {
                $error = "A user with this username or email already exists.";
            } else {
                // Generate default password
                $default_password = 'teacher@123';
                $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
                $role = 'teacher';
                $status = 'active';

                // Check if teacher_id column exists
                $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'teacher_id'");
                $column_exists = $stmt->fetch();
                
                if ($column_exists) {
                    // Insert with teacher_id
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (teacher_id, username, email, fullname, gender, 
                         position, role, password, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                    $inserted = $stmt->execute([
                        $teacher_id,
                        $username,
                        $email,
                        $fullname,
                        $gender,
                        $position,
                        $role,
                        $hashed_password,
                        $status
                    ]);
                } else {
                    // Insert without teacher_id (fallback)
                    $stmt = $pdo->prepare("INSERT INTO users 
                        (username, email, fullname, gender, 
                         position, role, password, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");

                    $inserted = $stmt->execute([
                        $username,
                        $email,
                        $fullname,
                        $gender,
                        $position,
                        $role,
                        $hashed_password,
                        $status
                    ]);
                }

                if ($inserted) {
                    $teacher_db_id = $pdo->lastInsertId();

                    // Handle teacher assignments
                    if (!empty($level_ids) && $academic_year_id) {
                        // Insert teacher classes
                        foreach ($class_ids as $class_id) {
                            $stmt = $pdo->prepare("INSERT INTO teacher_classes 
                                (teacher_id, class_id, academic_year_id, is_class_teacher, created_at) 
                                VALUES (?, ?, ?, ?, NOW())");
                            $stmt->execute([$teacher_db_id, $class_id, $academic_year_id, $is_class_teacher]);

                            // Also insert into teacher_assignments for class teacher role
                            if ($is_class_teacher) {
                                $stmt = $pdo->prepare("INSERT INTO teacher_assignments 
                                    (teacher_id, academic_year_id, class_id, 
                                     assignment_type, is_primary, start_date, status, created_at) 
                                    VALUES (?, ?, ?, 'Class Teacher', 1, CURDATE(), 'active', NOW())");
                                $stmt->execute([$teacher_db_id, $academic_year_id, $class_id]);
                            }
                        }

                        // Insert teacher subjects
                        foreach ($subject_ids as $subject_id) {
                            // Get level_id for this subject
                            $stmt = $pdo->prepare("SELECT level_id FROM subjects WHERE id = ?");
                            $stmt->execute([$subject_id]);
                            $subject = $stmt->fetch();

                            if ($subject) {
                                $stmt = $pdo->prepare("INSERT INTO teacher_subjects 
                                    (teacher_id, subject_id, level_id, is_primary, created_at) 
                                    VALUES (?, ?, ?, 1, NOW())");
                                $stmt->execute([$teacher_db_id, $subject_id, $subject['level_id']]);

                                // Also insert into teacher_assignments for subject teacher role
                                $stmt = $pdo->prepare("INSERT INTO teacher_assignments 
                                    (teacher_id, academic_year_id, subject_id, level_id,
                                     assignment_type, is_primary, start_date, status, created_at) 
                                    VALUES (?, ?, ?, ?, 'Subject Teacher', 1, CURDATE(), 'active', NOW())");
                                $stmt->execute([$teacher_db_id, $academic_year_id, $subject_id, $subject['level_id']]);
                            }
                        }
                    }

                    // Handle additional responsibilities
                    if (!empty($additional_responsibilities)) {
                        foreach ($additional_responsibilities as $responsibility) {
                            $stmt = $pdo->prepare("INSERT INTO teacher_assignments 
                                (teacher_id, academic_year_id, assignment_type, is_primary, start_date, status, created_at) 
                                VALUES (?, ?, ?, 0, CURDATE(), 'active', NOW())");
                            $stmt->execute([$teacher_db_id, $academic_year_id, $responsibility]);
                        }
                    }

                    $pdo->commit();

                    // Log activity
                    $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, created_at) 
                                          VALUES (?, 'ADD_TEACHER', ?, NOW())");
                    $stmt->execute([$_SESSION['user_id'], "Added teacher: $teacher_id - $fullname"]);

                    $message = "Teacher added successfully!<br>";
                    if ($column_exists) {
                        $message .= "Teacher ID: <strong>$teacher_id</strong><br>";
                    }
                    $message .= "Default password: <strong>teacher@123</strong>";
                    
                    // IMPORTANT: Regenerate new Teacher ID for the next teacher
                    $new_teacher_id = generateTeacherID($pdo);
                    
                    // Clear form but preserve the new Teacher ID
                    $_POST = [];
                    $teacher_id = $new_teacher_id; // Set the new Teacher ID for display
                } else {
                    $error = "Failed to add teacher. Please try again.";
                }
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "An error occurred: " . $e->getMessage() . 
                    "<br><strong>SQL Error:</strong> " . $e->getMessage() . 
                    "<br><em>Note: Make sure the teacher_id column exists in the users table.</em>";
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Fetch classes based on selected levels (for AJAX)
if (isset($_GET['get_classes']) && isset($_GET['level_ids'])) {
    $level_ids = explode(',', $_GET['level_ids']);
    $placeholders = str_repeat('?,', count($level_ids) - 1) . '?';

    $stmt = $pdo->prepare("SELECT c.* FROM classes c 
                          WHERE c.level_id IN ($placeholders) AND c.status = 'active' 
                          ORDER BY c.name");
    $stmt->execute($level_ids);
    $classes = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode($classes);
    exit;
}

// Fetch subjects based on selected levels (for AJAX)
if (isset($_GET['get_subjects']) && isset($_GET['level_ids'])) {
    $level_ids = explode(',', $_GET['level_ids']);
    $placeholders = str_repeat('?,', count($level_ids) - 1) . '?';

    $stmt = $pdo->prepare("SELECT s.* FROM subjects s 
                          WHERE s.level_id IN ($placeholders) AND s.status = 'active' 
                          ORDER BY s.name");
    $stmt->execute($level_ids);
    $subjects = $stmt->fetchAll();

    header('Content-Type: application/json');
    echo json_encode($subjects);
    exit;
}

// Get admin info for header
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($admin['fullname']);
    $email = htmlspecialchars($admin['email']);
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
    <title>Add Teacher - Admin Dashboard</title>
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
            min-height: 100vh;
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
            min-height: 100vh;
        }

        .header {
            height: 100px;
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
            padding: 1.5rem;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.6rem;
            margin: 0;
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: background 0.2s;
            white-space: nowrap;
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

        /* Message alerts */
        .alert {
            padding: 0.8rem 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
            border-left: 4px solid;
        }

        .alert-success {
            background-color: #d4edda;
            border-color: #28a745;
            color: #155724;
        }

        .alert-error {
            background-color: #f8d7da;
            border-color: #dc3545;
            color: #721c24;
        }

        .alert-warning {
            background-color: #fff3cd;
            border-color: #ffc107;
            color: #856404;
        }

        /* Form Container */
        .form-container {
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-container h3 {
            color: var(--primary);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .form-control {
            width: 100%;
            padding: 0.375rem 0.75rem;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #495057;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid #ced4da;
            border-radius: 0.25rem;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            color: #495057;
            background-color: #fff;
            border-color: var(--primary);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .form-group .hint {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 0.25rem;
            font-style: italic;
        }

        .required::after {
            content: " *";
            color: var(--secondary);
        }

        .form-row {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .form-row .form-group {
            flex: 1;
            margin-bottom: 0;
        }

        .form-section {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--primary);
        }

        .form-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-section h3 i {
            color: var(--primary);
        }

        .checkbox-group {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .checkbox-item input[type="checkbox"] {
            width: auto;
        }

        .multi-select {
            min-height: 100px;
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ced4da;
            border-radius: 4px;
            padding: 0.5rem;
            background: white;
        }

        .select-all {
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
            padding: 0.25rem;
            border-bottom: 1px solid #eee;
        }

        .teacher-id-field {
            background-color: #f8f9fa;
            border: 1px solid #ced4da;
            font-weight: bold;
            color: var(--primary);
            cursor: not-allowed;
        }

        .note {
            margin-top: 1.5rem;
            padding: 1rem;
            background: #e9f7fe;
            border-radius: 4px;
            border-left: 4px solid #17a2b8;
            color: #0c5460;
            font-size: 0.9rem;
        }

        .note strong {
            color: #0c5460;
        }

        .note code {
            background: #d1ecf1;
            padding: 0.2rem 0.4rem;
            border-radius: 3px;
            font-family: monospace;
        }

        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .main-wrapper {
                margin-left: 0;
                width: 100%;
            }

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .mobile-menu-btn {
                display: block;
                position: fixed;
                top: 1rem;
                left: 1rem;
                z-index: 1001;
                background: var(--primary);
                color: white;
                border: none;
                padding: 0.5rem 0.8rem;
                border-radius: 4px;
                cursor: pointer;
                font-size: 1.2rem;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .form-actions {
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
                gap: 0.75rem;
            }

            .admin-info h1 {
                font-size: 1.3rem;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Button -->
    <button class="mobile-menu-btn" id="mobileMenuBtn">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
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
                            <li><a href="marks_o_level_add.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marks_o_level_view.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_o_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <!-- A-Level -->
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>A-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="marks_a_level_add.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marks_a_level_view.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_a_level.php" class="nav-link">Set Grading</a></li>
                        </ul>
                    </li>
                    <!-- Common -->
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
                <h1>Add New Teacher</h1>
                <p><?= $fullname ?> (<?= $email ?>)</p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <div class="page-header">
                <h2>
                    <i class="fas fa-user-plus"></i> Add New Teacher
                </h2>
            </div>

            <div class="form-container">
                <?php if ($message): ?>
                    <div id="success-message" class="alert alert-success"><?= $message ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-error"><?= $error ?></div>
                <?php endif; ?>

                <form id="teacherForm" method="POST">
                    <!-- Personal Information Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-user"></i> Personal Information</h3>
                        
                        <?php if ($column_exists): ?>
                        <div class="form-row">
                            <div class="form-group">
                                <label for="teacher_id">Teacher ID</label>
                                <input type="text" id="teacher_id" name="teacher_id" 
                                       class="form-control teacher-id-field" 
                                       value="<?= htmlspecialchars($teacher_id) ?>" 
                                       readonly>
                                <div class="hint">Teacher ID is automatically generated</div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="fullname" class="required">Full Name</label>
                                <input type="text" id="fullname" name="fullname" class="form-control"
                                    value="<?= htmlspecialchars($_POST['fullname'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="username" class="required">Username</label>
                                <input type="text" id="username" name="username" class="form-control"
                                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="email" class="required">Email</label>
                                <input type="email" id="email" name="email" class="form-control"
                                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="gender">Gender</label>
                                <select id="gender" name="gender" class="form-control">
                                    <option value="">Select Gender</option>
                                    <option value="male" <?= ($_POST['gender'] ?? '') == 'male' ? 'selected' : '' ?>>Male</option>
                                    <option value="female" <?= ($_POST['gender'] ?? '') == 'female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="position">Position</label>
                                <select id="position" name="position" class="form-control">
                                    <option value="Teacher" <?= ($_POST['position'] ?? '') == 'Teacher' ? 'selected' : '' ?>>Teacher</option>
                                    <option value="Head of Department" <?= ($_POST['position'] ?? '') == 'Head of Department' ? 'selected' : '' ?>>Head of Department</option>
                                    <option value="Director of Studies" <?= ($_POST['position'] ?? '') == 'Director of Studies' ? 'selected' : '' ?>>Director of Studies</option>
                                    <option value="Ass Director of Studies" <?= ($_POST['position'] ?? '') == 'Ass Director of Studies' ? 'selected' : '' ?>>Ass Director of Studies</option>
                                    <option value="Head Teacher" <?= ($_POST['position'] ?? '') == 'Head Teacher' ? 'selected' : '' ?>>Head Teacher</option>
                                    <option value="Deputy Head Teacher" <?= ($_POST['position'] ?? '') == 'Deputy Head Teacher' ? 'selected' : '' ?>>Deputy Head Teacher</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Professional Information Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-graduation-cap"></i> Professional Information</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="academic_year_id">Academic Year</label>
                                <select id="academic_year_id" name="academic_year_id" class="form-control" required>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?= $year['id'] ?>"
                                            <?= ($_POST['academic_year_id'] ?? ($current_academic_year['id'] ?? '')) == $year['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($year['year_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Level(s) to Teach</label>
                            <div class="checkbox-group">
                                <?php foreach ($levels as $level): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="level_ids[]" value="<?= $level['id'] ?>"
                                            class="level-checkbox"
                                            <?= (isset($_POST['level_ids']) && in_array($level['id'], $_POST['level_ids'])) ? 'checked' : '' ?>>
                                        <label><?= htmlspecialchars($level['name']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Teaching Assignments Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-chalkboard-teacher"></i> Teaching Assignments</h3>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="class_ids">Class(es)</label>
                                <div class="multi-select" id="class-select-container">
                                    <div class="select-all">
                                        <input type="checkbox" id="select-all-classes">
                                        <label for="select-all-classes">Select All</label>
                                    </div>
                                    <div id="class-checkboxes">
                                        <!-- Classes will be loaded dynamically via AJAX -->
                                        <p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>
                                    </div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="subject_ids">Subject(s)</label>
                                <div class="multi-select" id="subject-select-container">
                                    <div class="select-all">
                                        <input type="checkbox" id="select-all-subjects">
                                        <label for="select-all-subjects">Select All</label>
                                    </div>
                                    <div id="subject-checkboxes">
                                        <!-- Subjects will be loaded dynamically via AJAX -->
                                        <p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="is_class_teacher" name="is_class_teacher" value="1"
                                    <?= isset($_POST['is_class_teacher']) ? 'checked' : '' ?>>
                                <label for="is_class_teacher"><strong>Assign as Class Teacher</strong></label>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Responsibilities Section -->
                    <div class="form-section">
                        <h3><i class="fas fa-tasks"></i> Additional Responsibilities</h3>
                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" name="responsibilities[]" value="Director of Studies"
                                    <?= (isset($_POST['responsibilities']) && in_array('Director of Studies', $_POST['responsibilities'])) ? 'checked' : '' ?>>
                                <label>Director of Studies</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="responsibilities[]" value="Ass Director of Studies"
                                    <?= (isset($_POST['responsibilities']) && in_array('Ass Director of Studies', $_POST['responsibilities'])) ? 'checked' : '' ?>>
                                <label>Ass Director of Studies</label>
                            </div>
                            <div class="checkbox-item">
                                <input type="checkbox" name="responsibilities[]" value="Head of Department"
                                    <?= (isset($_POST['responsibilities']) && in_array('Head of Department', $_POST['responsibilities'])) ? 'checked' : '' ?>>
                                <label>Head of Department</label>
                            </div>
                        </div>
                    </div>

                    <div class="form-group" style="text-align: right;">
                        <button type="submit" class="btn">
                            <i class="fas fa-user-plus"></i> Add Teacher
                        </button>
                    </div>
                </form>

                <div class="note">
                    <strong>Note:</strong> The default password for this teacher will be <code>teacher@123</code>.
                    The teacher will be able to change it after first login.
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const sidebar = document.getElementById('sidebar');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', () => {
                sidebar.classList.toggle('active');
            });
        }

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth <= 1200 &&
                !sidebar.contains(e.target) &&
                e.target !== mobileMenuBtn &&
                !mobileMenuBtn.contains(e.target)) {
                sidebar.classList.remove('active');
            }
        });

        // Toggle dropdowns in sidebar
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

        // Auto-hide success message and update Teacher ID
        document.addEventListener('DOMContentLoaded', function() {
            const successMsg = document.getElementById('success-message');
            const form = document.getElementById('teacherForm');

            if (successMsg) {
                // Auto-hide success message
                setTimeout(() => {
                    successMsg.style.opacity = '0';
                    setTimeout(() => successMsg.remove(), 300);
                }, 5000);
                
                // Clear form fields except Teacher ID
                if (form) {
                    // Clear all text inputs except teacher_id
                    form.querySelectorAll('input[type="text"], input[type="email"], textarea').forEach(input => {
                        if (input.id !== 'teacher_id') {
                            input.value = '';
                        }
                    });
                    
                    // Clear select fields
                    form.querySelectorAll('select').forEach(select => {
                        select.selectedIndex = 0;
                    });
                    
                    // Clear checkboxes
                    form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                        checkbox.checked = false;
                    });
                    
                    // Clear class and subject checkboxes containers
                    const classCheckboxes = document.getElementById('class-checkboxes');
                    const subjectCheckboxes = document.getElementById('subject-checkboxes');
                    if (classCheckboxes) {
                        classCheckboxes.innerHTML = '<p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>';
                    }
                    if (subjectCheckboxes) {
                        subjectCheckboxes.innerHTML = '<p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>';
                    }
                    
                    // Get new Teacher ID from server
                    setTimeout(() => {
                        fetch('?generate_teacher_id=1')
                            .then(response => response.text())
                            .then(newId => {
                                if (newId.trim()) {
                                    const teacherIdField = document.getElementById('teacher_id');
                                    if (teacherIdField) {
                                        teacherIdField.value = newId.trim();
                                    }
                                }
                            })
                            .catch(error => {
                                console.error('Error updating Teacher ID:', error);
                            });
                    }, 100);
                }
            }

            // Load initial data
            loadClasses();
            loadSubjects();
        });

        // Function to load classes based on selected levels
        function loadClasses() {
            const selectedLevels = Array.from(document.querySelectorAll('.level-checkbox:checked'))
                .map(cb => cb.value);

            if (selectedLevels.length === 0) {
                document.getElementById('class-checkboxes').innerHTML =
                    '<p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>';
                return;
            }

            fetch(`?get_classes=1&level_ids=${selectedLevels.join(',')}`)
                .then(response => response.json())
                .then(classes => {
                    const container = document.getElementById('class-checkboxes');
                    container.innerHTML = '';

                    classes.forEach(cls => {
                        const div = document.createElement('div');
                        div.className = 'checkbox-item';
                        div.innerHTML = `
                            <input type="checkbox" name="class_ids[]" value="${cls.id}" 
                                   id="class_${cls.id}">
                            <label for="class_${cls.id}">${cls.name}</label>
                        `;
                        container.appendChild(div);
                    });

                    // Add select all functionality
                    const selectAllCheckbox = document.getElementById('select-all-classes');
                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
                            checkboxes.forEach(cb => cb.checked = this.checked);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading classes:', error);
                    document.getElementById('class-checkboxes').innerHTML =
                        '<p style="color: #dc3545; padding: 0.5rem;">Error loading classes</p>';
                });
        }

        // Function to load subjects based on selected levels
        function loadSubjects() {
            const selectedLevels = Array.from(document.querySelectorAll('.level-checkbox:checked'))
                .map(cb => cb.value);

            if (selectedLevels.length === 0) {
                document.getElementById('subject-checkboxes').innerHTML =
                    '<p style="color: #6c757d; padding: 0.5rem;">Select levels first</p>';
                return;
            }

            fetch(`?get_subjects=1&level_ids=${selectedLevels.join(',')}`)
                .then(response => response.json())
                .then(subjects => {
                    const container = document.getElementById('subject-checkboxes');
                    container.innerHTML = '';

                    subjects.forEach(subject => {
                        const div = document.createElement('div');
                        div.className = 'checkbox-item';
                        div.innerHTML = `
                            <input type="checkbox" name="subject_ids[]" value="${subject.id}" 
                                   id="subject_${subject.id}">
                            <label for="subject_${subject.id}">${subject.code} - ${subject.name}</label>
                        `;
                        container.appendChild(div);
                    });

                    // Add select all functionality
                    const selectAllCheckbox = document.getElementById('select-all-subjects');
                    if (selectAllCheckbox) {
                        selectAllCheckbox.addEventListener('change', function() {
                            const checkboxes = container.querySelectorAll('input[type="checkbox"]');
                            checkboxes.forEach(cb => cb.checked = this.checked);
                        });
                    }
                })
                .catch(error => {
                    console.error('Error loading subjects:', error);
                    document.getElementById('subject-checkboxes').innerHTML =
                        '<p style="color: #dc3545; padding: 0.5rem;">Error loading subjects</p>';
                });
        }

        // Event listeners for level selection changes
        document.querySelectorAll('.level-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                loadClasses();
                loadSubjects();
            });
        });

        // Responsive adjustments
        function handleResponsive() {
            if (window.innerWidth <= 1200) {
                if (!mobileMenuBtn) {
                    const btn = document.createElement('button');
                    btn.className = 'mobile-menu-btn';
                    btn.id = 'mobileMenuBtn';
                    btn.innerHTML = '<i class="fas fa-bars"></i>';
                    document.body.prepend(btn);

                    btn.addEventListener('click', () => {
                        sidebar.classList.toggle('active');
                    });
                }
            } else {
                if (mobileMenuBtn) {
                    mobileMenuBtn.remove();
                }
                sidebar.classList.remove('active');
            }
        }

        window.addEventListener('resize', handleResponsive);
        handleResponsive();
    </script>
</body>

</html>