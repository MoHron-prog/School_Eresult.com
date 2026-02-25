<?php
// promote_students.php
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
    $fullname = htmlspecialchars($admin['fullname']);
    $email = htmlspecialchars($admin['email']);
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}

$message = '';
$students = [];
$selected_class_id = $_POST['class_id'] ?? '';
$selected_stream_id = $_POST['stream_id'] ?? '';
$selected_academic_year = $_POST['academic_year'] ?? '';
$selected_level_id = $_POST['level_id'] ?? '';

// Get all academic years
$academic_years = [];
try {
    $stmt = $pdo->query("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY year_name DESC");
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching academic years: " . $e->getMessage();
}

// Get all levels
$levels = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY id");
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching levels: " . $e->getMessage();
}

// Get classes based on selected level (if any) - for current class selection
$classes = [];
if (isset($_POST['level_id']) && !empty($_POST['level_id'])) {
    $level_id = (int)$_POST['level_id'];
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM classes WHERE level_id = ? AND status = 'active' ORDER BY name");
        $stmt->execute([$level_id]);
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = "Error fetching classes: " . $e->getMessage();
    }
}

// Get streams based on selected class
$streams = [];
if ($selected_class_id) {
    try {
        $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE class_id = ? AND status = 'active' ORDER BY name");
        $stmt->execute([$selected_class_id]);
        $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = "Error fetching streams: " . $e->getMessage();
    }
}

// Fetch students if class and stream are selected
// EXCLUDE S.4 and S.6 students since they could have finished their education level
if ($selected_class_id && $selected_stream_id && $selected_academic_year) {
    try {
        // Convert academic year name to ID
        $stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_name = ? AND status = 'active'");
        $stmt->execute([$selected_academic_year]);
        $academic_year_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($academic_year_data) {
            $academic_year_id = $academic_year_data['id'];
            
            $stmt = $pdo->prepare("
                SELECT s.*, l.name as level_name, c.name as class_name, st.name as stream_name, ay.year_name
                FROM students s
                JOIN levels l ON s.level_id = l.id
                JOIN classes c ON s.class_id = c.id
                JOIN streams st ON s.stream_id = st.id
                LEFT JOIN academic_years ay ON s.academic_year_id = ay.id
                WHERE s.class_id = ? 
                AND s.stream_id = ? 
                AND s.academic_year_id = ?
                AND s.status = 'active'
                -- Exclude S.4 and S.6 classes as they are at the end of their education level
                AND c.name NOT IN ('S.4', 'S.6')
                ORDER BY s.surname, s.other_names
            ");
            $stmt->execute([$selected_class_id, $selected_stream_id, $academic_year_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($students)) {
                $message = "No active students found in the selected class, stream, and academic year. Note: S.4 and S.6 students are not available for promotion.";
            }
        } else {
            $message = "Invalid academic year selected.";
        }
    } catch (PDOException $e) {
        $message = "Error fetching students: " . $e->getMessage() . " (Query: " . $stmt->queryString . ")";
    }
}

// Handle student promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_students'])) {
    if (!empty($_POST['student_ids']) && isset($_POST['new_class_id']) && isset($_POST['new_stream_id']) && isset($_POST['new_academic_year'])) {
        $student_ids = $_POST['student_ids'];
        $new_class_id = (int)$_POST['new_class_id'];
        $new_stream_id = (int)$_POST['new_stream_id'];
        $new_academic_year = $_POST['new_academic_year'];
        
        // Prevent promoting to the same class
        if ($new_class_id == $selected_class_id && $new_stream_id == $selected_stream_id) {
            $message = "Error: Cannot promote students to the same class and stream.";
        } else {
            try {
                // Get new academic year ID
                $stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_name = ? AND status = 'active'");
                $stmt->execute([$new_academic_year]);
                $new_academic_year_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$new_academic_year_data) {
                    $message = "Invalid new academic year selected.";
                } else {
                    $new_academic_year_id = $new_academic_year_data['id'];
                    
                    // Get new class info
                    $stmt = $pdo->prepare("SELECT level_id, name FROM classes WHERE id = ?");
                    $stmt->execute([$new_class_id]);
                    $new_class = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($new_class) {
                        $pdo->beginTransaction();
                        $success_count = 0;

                        foreach ($student_ids as $student_id) {
                            $stmt = $pdo->prepare("
                                UPDATE students 
                                SET level_id = ?, class_id = ?, stream_id = ?, academic_year_id = ?, updated_at = NOW()
                                WHERE id = ? AND status = 'active'
                            ");
                            $stmt->execute([$new_class['level_id'], $new_class_id, $new_stream_id, $new_academic_year_id, $student_id]);

                            if ($stmt->rowCount() > 0) {
                                $success_count++;

                                // Log the promotion activity
                                $stmt = $pdo->prepare("
                                    SELECT student_id, surname, other_names 
                                    FROM students 
                                    WHERE id = ?
                                ");
                                $stmt->execute([$student_id]);
                                $student = $stmt->fetch(PDO::FETCH_ASSOC);

                                if ($student) {
                                    $log_stmt = $pdo->prepare("
                                        INSERT INTO activity_logs (user_id, action, description, created_at)
                                        VALUES (?, 'PROMOTE_STUDENT', ?, NOW())
                                    ");
                                    $description = "Promoted student: {$student['student_id']} - {$student['surname']} {$student['other_names']} to {$new_class['name']}";
                                    $log_stmt->execute([$_SESSION['user_id'], $description]);
                                }
                            }
                        }

                        $pdo->commit();

                        if ($success_count > 0) {
                            $message = "Successfully promoted $success_count student(s)!";
                            // Clear the students list
                            $students = [];
                            $selected_class_id = '';
                            $selected_stream_id = '';
                            $selected_level_id = '';
                        } else {
                            $message = "No students were promoted. Please check if students are still active.";
                        }
                    } else {
                        $message = "Invalid new class selected.";
                    }
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                $message = "Error promoting students: " . $e->getMessage() . " (Query: " . $stmt->queryString . ")";
            }
        }
    } else {
        $message = "Please select at least one student and provide new class/stream information.";
    }
}

// Get all classes for promotion target - will be filtered by JavaScript based on selected level
$all_classes = [];
try {
    $stmt = $pdo->query("SELECT id, name, level_id FROM classes WHERE status = 'active' ORDER BY level_id, name");
    $all_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $message = "Error fetching classes: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Promote Students - School Admin</title>
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
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            height: 100vh;
            background-color: var(--body-bg);
            color: var(--text-dark);
            overflow: hidden;
        }

        /* Sidebar - Same as admin_dashboard.php */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            color: var(--text-light);
            height: 100%;
            position: fixed;
            top: 0;
            left: 0;
            overflow-y: auto;
            z-index: 1000;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.12);
        }

        .sidebar-header {
            padding: 0.9rem 1rem;
            min-height: 100px;
            background: var(--primary);
            font-weight: 700;
            font-size: 1.15rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #3a506b;
        }

        .nav-menu {
            list-style: none;
            padding: 0;
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
            height: 100%;
            overflow-y: auto;
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
            padding: 1rem 1.4rem;
            flex: 1;
            overflow-y: auto;
        }

        /* Form Styles */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
        }

        .page-header p {
            color: #6c757d;
            font-size: 0.95rem;
            margin-top: 0.3rem;
        }

        .message {
            padding: 0.75rem 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            font-size: 0.95rem;
        }

        .message.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message.info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }

        .form-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .form-container h3 {
            color: var(--primary);
            font-size: 1.2rem;
            margin-bottom: 1.2rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.4rem;
            color: #495057;
            font-size: 0.9rem;
        }

        .form-control {
            padding: 0.6rem 0.8rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
            transition: border-color 0.15s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26, 42, 108, 0.1);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.15s;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .btn-success {
            background: var(--success);
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-warning {
            background: var(--warning);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        /* Students Table */
        .students-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .students-container h3 {
            color: var(--primary);
            font-size: 1.2rem;
            margin-bottom: 1.2rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #eee;
        }

        .students-table {
            width: 100%;
            border-collapse: collapse;
        }

        .students-table th {
            background: #f8f9fa;
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 0.9rem;
        }

        .students-table td {
            padding: 0.8rem;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .students-table tbody tr:hover {
            background: #f8f9fa;
        }

        .student-checkbox {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .student-photo {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: fill;
            border: 2px solid #e9ecef;
        }

        .student-info {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .student-details h4 {
            margin: 0;
            font-size: 0.95rem;
            color: #212529;
        }

        .student-details p {
            margin: 0.2rem 0 0;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
            font-style: italic;
        }

        /* Promotion Target Section */
        .promotion-target {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
            border: 1px dashed #adb5bd;
        }

        .promotion-target h3 {
            color: var(--secondary);
            font-size: 1.2rem;
            margin-bottom: 1.2rem;
        }

        .select-all-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding: 0.8rem;
            background: #e9ecef;
            border-radius: 4px;
        }

        .select-all-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 600;
            color: #495057;
        }

        .selected-count {
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Warning message for S.4/S.6 */
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 4px;
            padding: 0.8rem;
            margin-bottom: 1rem;
            color: #856404;
            font-size: 0.9rem;
        }

        .warning-box i {
            color: #ffc107;
            margin-right: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 60px;
            }

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after,
            .sidebar .nested>.nav-link::after {
                display: none;
            }

            .sidebar .nav-link {
                justify-content: center;
                padding: 0.8rem;
            }

            .sidebar .nav-link i {
                margin-right: 0;
                font-size: 1.1rem;
            }

            .dropdown-menu,
            .nested-menu {
                display: none !important;
            }

            .main-wrapper {
                margin-left: 60px;
                width: calc(100% - 60px);
            }
        }

        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
            }

            .students-table {
                display: block;
                overflow-x: auto;
            }

            .student-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.3rem;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar - Same as admin_dashboard.php -->
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
                <h1>Promote Students</h1>
                <p><?= $fullname ?> (<?= $email ?>)</p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <div class="page-header">
                <h2>Student Promotion</h2>
                <p>Select current class and stream to view students, then choose their new class/stream for promotion</p>
                <p><small><strong>Note:</strong> S.4 and S.6 students are not available for promotion as they have completed their education level.</small></p>
            </div>

            <?php if ($message): ?>
                <div class="message <?= strpos($message, 'Successfully') !== false ? 'success' : (strpos($message, 'Error') !== false ? 'error' : 'info') ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Current Class Selection Form -->
            <div class="form-container">
                <h3>1. Select Current Class & Stream</h3>
                <form method="POST" action="" id="currentClassForm">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <select name="academic_year" id="academic_year" class="form-control" required>
                                <option value="">-- Select Academic Year --</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= htmlspecialchars($year['year_name']) ?>" <?= $selected_academic_year == $year['year_name'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="level_id">Level *</label>
                            <select name="level_id" id="level_id" class="form-control" required onchange="this.form.submit()">
                                <option value="">-- Select Level --</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" <?= $selected_level_id == $level['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if (!empty($classes)): ?>
                            <div class="form-group">
                                <label for="class_id">Class *</label>
                                <select name="class_id" id="class_id" class="form-control" required onchange="this.form.submit()">
                                    <option value="">-- Select Class --</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?= $class['id'] ?>" <?= $selected_class_id == $class['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($class['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($streams)): ?>
                            <div class="form-group">
                                <label for="stream_id">Stream *</label>
                                <select name="stream_id" id="stream_id" class="form-control" required onchange="this.form.submit()">
                                    <option value="">-- Select Stream --</option>
                                    <?php foreach ($streams as $stream): ?>
                                        <option value="<?= $stream['id'] ?>" <?= $selected_stream_id == $stream['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($stream['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($selected_class_id && $selected_stream_id && $selected_academic_year): ?>
                        <button type="submit" class="btn">
                            <i class="fas fa-search"></i> View Students
                        </button>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Students List -->
            <?php if (!empty($students)): ?>
                <div class="warning-box">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Note:</strong> S.4 and S.6 students are not shown here as they have completed their education level and cannot be promoted.
                </div>

                <div class="students-container">
                    <h3>2. Select Students to Promote</h3>

                    <div class="select-all-row">
                        <label class="select-all-label">
                            <input type="checkbox" id="selectAll" class="student-checkbox">
                            Select All Students
                        </label>
                        <span class="selected-count" id="selectedCount">0 selected</span>
                    </div>

                    <form method="POST" action="" id="promotionForm">
                        <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">
                        <input type="hidden" name="stream_id" value="<?= $selected_stream_id ?>">
                        <input type="hidden" name="academic_year" value="<?= $selected_academic_year ?>">
                        <input type="hidden" name="level_id" value="<?= $selected_level_id ?>">

                        <table class="students-table">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"></th>
                                    <th style="width: 70px;">Photo</th>
                                    <th>Student ID</th>
                                    <th>Student Name</th>
                                    <th>Class</th>
                                    <th>Stream</th>
                                    <th>Academic Year</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" name="student_ids[]" value="<?= $student['id'] ?>" class="student-checkbox student-select">
                                        </td>

                                        <td>
                                            <?php if ($student['photo'] && file_exists($student['photo'])): ?>
                                                <img src="<?= htmlspecialchars($student['photo']) ?>" alt="Photo" class="student-photo">
                                            <?php else: ?>
                                                <div class="student-photo" style="background: #e9ecef; display: flex; align-items: center; justify-content: center;">
                                                    <i class="fas fa-user" style="color: #6c757d;"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($student['student_id']) ?></td>
                                        <td>
                                            <div class="student-info">
                                                <div class="student-details">
                                                    <h4><?= htmlspecialchars($student['surname']) . ' ' . htmlspecialchars($student['other_names']) ?></h4>
                                                </div>
                                            </div>
                                        </td>

                                        <td><?= htmlspecialchars($student['class_name']) ?></td>
                                        <td><?= htmlspecialchars($student['stream_name']) ?></td>
                                        <td><?= htmlspecialchars($student['year_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <span style="color: var(--success); font-weight: 600;">
                                                <?= ucfirst($student['status']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <!-- Promotion Target Section -->
                        <div class="promotion-target" style="margin-top: 2rem;">
                            <h3>3. Set Promotion Target</h3>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="new_level_id">New Level *</label>
                                    <select name="new_level_id" id="new_level_id" class="form-control" required>
                                        <option value="">-- Select Level --</option>
                                        <?php foreach ($levels as $level): ?>
                                            <option value="<?= $level['id'] ?>" <?= $selected_level_id == $level['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($level['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="new_academic_year">New Academic Year *</label>
                                    <select name="new_academic_year" id="new_academic_year" class="form-control" required>
                                        <option value="">-- Select Academic Year --</option>
                                        <?php foreach ($academic_years as $year): ?>
                                            <option value="<?= htmlspecialchars($year['year_name']) ?>" <?= $selected_academic_year == $year['year_name'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($year['year_name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="new_class_id">New Class *</label>
                                    <select name="new_class_id" id="new_class_id" class="form-control" required>
                                        <option value="">-- Select New Class --</option>
                                        <!-- Classes will be populated via JavaScript based on selected level -->
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="new_stream_id">New Stream *</label>
                                    <select name="new_stream_id" id="new_stream_id" class="form-control" required>
                                        <option value="">-- Select Stream --</option>
                                        <!-- Streams will be populated via JavaScript -->
                                    </select>
                                </div>
                            </div>
                            <div style="margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center;">
                                <button type="submit" name="promote_students" class="btn btn-success" id="promoteBtn" disabled>
                                    <i class="fas fa-graduation-cap"></i> Promote Selected Students
                                </button>
                                <span id="promotionSummary" style="color: #6c757d; font-size: 0.9rem;">
                                    Please select students and set promotion target
                                </span>
                            </div>
                        </div>
                    </form>
                </div>
            <?php elseif ($selected_class_id && $selected_stream_id && $selected_academic_year): ?>
                <div class="no-data">
                    <i class="fas fa-user-graduate" style="font-size: 3rem; color: #dee2e6; margin-bottom: 1rem;"></i>
                    <h3>No Students Found</h3>
                    <p>There are no active students in the selected class, stream, and academic year that are eligible for promotion.</p>
                    <p><small>Note: S.4 and S.6 students are not available for promotion as they have completed their education level.</small></p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        // Store classes data from PHP for JavaScript filtering
        const allClasses = <?= json_encode($all_classes) ?>;

        // Toggle dropdowns (same as admin_dashboard.php)
        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.dropdown');
                parent.classList.toggle('active');
            });
        });

        document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.nested');
                parent.classList.toggle('active');
            });
        });

        // Student selection functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        const studentCheckboxes = document.querySelectorAll('.student-select');
        const selectedCountSpan = document.getElementById('selectedCount');
        const promoteBtn = document.getElementById('promoteBtn');
        const promotionSummary = document.getElementById('promotionSummary');
        const newLevelSelect = document.getElementById('new_level_id');
        const newClassSelect = document.getElementById('new_class_id');
        const newStreamSelect = document.getElementById('new_stream_id');
        const newAcademicYearSelect = document.getElementById('new_academic_year');
        const currentClassId = <?= $selected_class_id ?: 'null' ?>;
        const currentStreamId = <?= $selected_stream_id ?: 'null' ?>;

        // Filter classes by selected level
        function filterClassesByLevel(levelId) {
            if (!levelId) {
                newClassSelect.innerHTML = '<option value="">-- Select New Class --</option>';
                return;
            }

            const filteredClasses = allClasses.filter(cls => cls.level_id == levelId);
            
            newClassSelect.innerHTML = '<option value="">-- Select New Class --</option>';
            filteredClasses.forEach(cls => {
                const option = document.createElement('option');
                option.value = cls.id;
                option.textContent = cls.name;
                // Disable option if it's the current class (to prevent promoting to same class)
                if (cls.id == currentClassId) {
                    option.disabled = true;
                    option.textContent += ' (Current Class)';
                }
                newClassSelect.appendChild(option);
            });

            // Clear streams when class changes
            newStreamSelect.innerHTML = '<option value="">-- Select Stream --</option>';
            updatePromotionButton();
        }

        // Fetch streams for selected class
        async function fetchStreams(classId) {
            if (!classId) {
                newStreamSelect.innerHTML = '<option value="">-- Select Stream --</option>';
                return;
            }

            try {
                const response = await fetch(`get_streams.php?class_id=${classId}`);
                const streams = await response.json();

                newStreamSelect.innerHTML = '<option value="">-- Select Stream --</option>';
                streams.forEach(stream => {
                    const option = document.createElement('option');
                    option.value = stream.id;
                    option.textContent = stream.name;
                    // Disable option if it's the current stream (to prevent promoting to same class)
                    if (stream.id == currentStreamId && classId == currentClassId) {
                        option.disabled = true;
                        option.textContent += ' (Current Stream)';
                    }
                    newStreamSelect.appendChild(option);
                });

                updatePromotionButton();
            } catch (error) {
                console.error('Error fetching streams:', error);
            }
        }

        // Update selected count
        function updateSelectedCount() {
            const selected = document.querySelectorAll('.student-select:checked').length;
            selectedCountSpan.textContent = `${selected} selected`;

            if (selected > 0) {
                selectAllCheckbox.indeterminate = selected < studentCheckboxes.length;
                selectAllCheckbox.checked = selected === studentCheckboxes.length;
            } else {
                selectAllCheckbox.indeterminate = false;
                selectAllCheckbox.checked = false;
            }

            updatePromotionButton();
        }

        // Update promotion button state
        function updatePromotionButton() {
            const selectedCount = document.querySelectorAll('.student-select:checked').length;
            const hasNewLevel = newLevelSelect.value !== '';
            const hasNewClass = newClassSelect.value !== '';
            const hasNewStream = newStreamSelect.value !== '';
            const hasNewAcademicYear = newAcademicYearSelect.value !== '';

            const canPromote = selectedCount > 0 && hasNewLevel && hasNewClass && hasNewStream && hasNewAcademicYear;

            promoteBtn.disabled = !canPromote;

            if (selectedCount > 0) {
                promotionSummary.textContent = `${selectedCount} student(s) selected for promotion`;
                promotionSummary.style.color = 'var(--primary)';
                
                // Add warning if trying to promote to same class
                if (newClassSelect.value == currentClassId && newStreamSelect.value == currentStreamId) {
                    promotionSummary.textContent = 'Cannot promote to the same class and stream!';
                    promotionSummary.style.color = 'var(--danger)';
                    promoteBtn.disabled = true;
                }
            } else {
                promotionSummary.textContent = 'Please select students and set promotion target';
                promotionSummary.style.color = '#6c757d';
            }
        }

        // Select all students
        selectAllCheckbox.addEventListener('change', function() {
            const isChecked = this.checked;
            studentCheckboxes.forEach(checkbox => {
                checkbox.checked = isChecked;
            });
            updateSelectedCount();
        });

        // Individual student selection
        studentCheckboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateSelectedCount);
        });

        // New level change event
        newLevelSelect.addEventListener('change', function() {
            filterClassesByLevel(this.value);
            updatePromotionButton();
        });

        // New class change event
        newClassSelect.addEventListener('change', function() {
            fetchStreams(this.value);
            updatePromotionButton();
        });

        // New stream and academic year change events
        newStreamSelect.addEventListener('change', updatePromotionButton);
        newAcademicYearSelect.addEventListener('change', updatePromotionButton);

        // Form validation
        document.getElementById('promotionForm').addEventListener('submit', function(e) {
            const selectedCount = document.querySelectorAll('.student-select:checked').length;

            if (selectedCount === 0) {
                e.preventDefault();
                alert('Please select at least one student to promote.');
                return;
            }

            if (!newLevelSelect.value || !newClassSelect.value || !newStreamSelect.value || !newAcademicYearSelect.value) {
                e.preventDefault();
                alert('Please select new level, class, stream, and academic year for promotion.');
                return;
            }

            // Prevent promoting to the same class
            if (newClassSelect.value == currentClassId && newStreamSelect.value == currentStreamId) {
                e.preventDefault();
                alert('Cannot promote students to the same class and stream. Please select a different class or stream.');
                return;
            }

            // Confirm promotion
            if (!confirm(`Are you sure you want to promote ${selectedCount} student(s)? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
            
            // Set initial level if we have one
            <?php if ($selected_level_id): ?>
                newLevelSelect.value = <?= $selected_level_id ?>;
                filterClassesByLevel(<?= $selected_level_id ?>);
            <?php endif; ?>
        });
    </script>
</body>

</html>