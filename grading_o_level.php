<?php
require_once 'config.php';

// Authentication & Authorization
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Fetch user info
$stmt = $pdo->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header("Location: logout.php");
    exit;
}

// Only allow admin or O-Level class teacher
if ($role !== 'admin') {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM teacher_assignments ta
        JOIN levels l ON ta.level_id = l.id
        WHERE ta.teacher_id = ? AND l.name = 'O Level' AND ta.assignment_type = 'Class Teacher' AND ta.status = 'active'
    ");
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() == 0) {
        $_SESSION['error'] = "Access denied. Only admins or O-Level class teachers may manage grading.";
        header("Location: admin_dashboard.php");
        exit;
    }
}

// Get O-Level ID
$stmt = $pdo->prepare("SELECT id FROM levels WHERE name = 'O Level' LIMIT 1");
$stmt->execute();
$olevel = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$olevel) {
    $_SESSION['error'] = "O-Level not found.";
    header("Location: admin_dashboard.php");
    exit;
}
$olevel_id = $olevel['id'];

// Handle form submissions
$action = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $grade_letter = trim(strtoupper($_POST['grade_letter']));
        $achievement_level = $_POST['achievement_level'];
        $min_score = (float)$_POST['min_score'];
        $max_score = (float)$_POST['max_score'];

        if (empty($grade_letter) || empty($achievement_level) || $min_score < 0 || $max_score > 100 || $min_score > $max_score) {
            $_SESSION['error'] = "Invalid input.";
        } else {
            try {
                // Insert into both compulsory and elective
                $categories = ['compulsory', 'elective'];
                foreach ($categories as $cat) {
                    $stmt = $pdo->prepare("INSERT INTO olevel_grading_scale (level_id, category, grade_letter, achievement_level, min_score, max_score) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$olevel_id, $cat, $grade_letter, $achievement_level, $min_score, $max_score]);
                }
                $_SESSION['success'] = "Grade added successfully for both categories.";
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['error'] = "Grade letter '$grade_letter' already exists for O-Level.";
                } else {
                    $_SESSION['error'] = "Database error: " . $e->getMessage();
                }
            }
        }
        header("Location: grading_o_level.php");
        exit;
    } elseif ($action === 'update') {
        $id = (int)$_POST['id'];
        $grade_letter = trim(strtoupper($_POST['grade_letter']));
        $achievement_level = $_POST['achievement_level'];
        $min_score = (float)$_POST['min_score'];
        $max_score = (float)$_POST['max_score'];

        if (empty($grade_letter) || empty($achievement_level) || $min_score < 0 || $max_score > 100 || $min_score > $max_score) {
            $_SESSION['error'] = "Invalid input.";
        } else {
            try {
                // Fetch original grade_letter to update all matching entries
                $stmt = $pdo->prepare("SELECT grade_letter FROM olevel_grading_scale WHERE id = ? AND level_id = ?");
                $stmt->execute([$id, $olevel_id]);
                $old_grade = $stmt->fetchColumn();

                if ($old_grade) {
                    // Update all categories with this grade_letter
                    $stmt = $pdo->prepare("UPDATE olevel_grading_scale SET grade_letter = ?, achievement_level = ?, min_score = ?, max_score = ? WHERE level_id = ? AND grade_letter = ?");
                    $stmt->execute([$grade_letter, $achievement_level, $min_score, $max_score, $olevel_id, $old_grade]);
                    $_SESSION['success'] = "Grade updated successfully.";
                } else {
                    $_SESSION['error'] = "Record not found.";
                }
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $_SESSION['error'] = "Grade letter '$grade_letter' already exists.";
                } else {
                    $_SESSION['error'] = "Update failed: " . $e->getMessage();
                }
            }
        }
        header("Location: grading_o_level.php");
        exit;
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        try {
            $stmt = $pdo->prepare("SELECT grade_letter FROM olevel_grading_scale WHERE id = ? AND level_id = ?");
            $stmt->execute([$id, $olevel_id]);
            $grade_letter = $stmt->fetchColumn();

            if ($grade_letter) {
                $stmt = $pdo->prepare("DELETE FROM olevel_grading_scale WHERE level_id = ? AND grade_letter = ?");
                $stmt->execute([$olevel_id, $grade_letter]);
                $_SESSION['success'] = "Grade deleted from all categories.";
            } else {
                $_SESSION['error'] = "Grade not found.";
            }
        } catch (Exception $e) {
            $_SESSION['error'] = "Delete failed.";
        }
        header("Location: grading_o_level.php");
        exit;
    }
}

// Fetch current grades (grouped by grade_letter)
$stmt = $pdo->prepare("
    SELECT grade_letter, achievement_level, MIN(min_score) as min_score, MAX(max_score) as max_score, MIN(id) as id
    FROM olevel_grading_scale 
    WHERE level_id = ?
    GROUP BY grade_letter
    ORDER BY min_score DESC
");
$stmt->execute([$olevel_id]);
$grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

$success_msg = $_SESSION['success'] ?? '';
$error_msg = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>O-Level Grading Scale</title>
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

        .dropdown-toggle::after,
        .nested .dropdown-toggle::after {
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
            overflow-y: auto;
            background: var(--body-bg);
        }

        .page-title {
            color: var(--primary);
            margin-bottom: 1.5rem;
            font-size: 1.6rem;
        }

        .grading-form-card {
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        .form-control {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }

        .form-select {
            width: 100%;
            padding: 0.6rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
            background-color: white;
        }

        .btn {
            padding: 0.55rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .btn-danger {
            background: var(--secondary);
        }

        .btn-danger:hover {
            background: #8f1717;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            font-weight: 500;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .grades-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .grades-table th {
            background: var(--primary);
            color: white;
            padding: 1rem;
            text-align: left;
        }

        .grades-table td {
            padding: 1rem;
            border-bottom: 1px solid #eee;
        }

        .grades-table tr:last-child td {
            border-bottom: none;
        }

        .grades-table tr:hover {
            background: #f9f9f9;
        }

        .action-icons {
            display: flex;
            gap: 0.6rem;
        }

        .action-icons button {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 1.1rem;
            padding: 4px;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .action-icons .edit {
            color: #1a2a6c;
        }

        .action-icons .delete {
            color: #b21f1f;
        }

        .action-icons button:hover.edit {
            background: #e6e9f0;
        }

        .action-icons button:hover.delete {
            background: #fdecea;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 0;
            border: 1px solid #888;
            width: 500px;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.2);
            animation: modalSlide 0.3s;
        }

        @keyframes modalSlide {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            padding: 1.2rem 1.5rem;
            background: var(--primary);
            color: white;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-header .close {
            color: white;
            font-size: 1.8rem;
            font-weight: bold;
            cursor: pointer;
            opacity: 0.8;
        }

        .modal-header .close:hover {
            opacity: 1;
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1rem 1.5rem 1.5rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.8rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
            }

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
            }

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after {
                opacity: 0;
            }

            .sidebar:hover {
                width: 280px;
            }

            .sidebar:hover .sidebar-header span,
            .sidebar:hover .nav-link span,
            .sidebar:hover .dropdown-toggle::after {
                opacity: 1;
            }

            .sidebar .nav-link {
                justify-content: center;
                padding: 0.8rem;
            }

            .sidebar:hover .nav-link {
                padding: 0.8rem 1.2rem;
                justify-content: flex-start;
            }

            .sidebar .nav-link i {
                margin-right: 0;
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

            .modal-content {
                width: 90%;
                margin: 20% auto;
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
        <header class="header">
            <div class="admin-info">
                <h1>O-Level Grading Scale</h1>
                <p>Configure grade letters, achievement levels, and score ranges</p>
            </div>
            <div class="role-tag"><?= ucfirst($user['role']) ?></div>
        </header>
        <main class="main-content">

            <?php if ($success_msg): ?>
                <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
            <?php endif; ?>
            <?php if ($error_msg): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
            <?php endif; ?>

            <!-- Add New Grade Form -->
            <div class="grading-form-card">
                <h3 style="margin-bottom: 1.2rem; color: var(--primary);">Add New Grade</h3>
                <form method="POST">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>Education Level</label>
                        <input type="text" class="form-control" value="O Level" readonly>
                    </div>
                    <div class="form-group">
                        <label>Subject Category</label>
                        <input type="text" class="form-control" value="Compulsory & Elective (Same Grading)" readonly>
                    </div>
                    <div class="form-group">
                        <label>Grade Letter</label>
                        <input type="text" name="grade_letter" class="form-control" maxlength="5" required placeholder="e.g., A, B+, C">
                    </div>
                    <div class="form-group">
                        <label>Achievement Level</label>
                        <select name="achievement_level" class="form-select" required>
                            <option value="">Select Achievement Level</option>
                            <option value="Exceptional">Exceptional</option>
                            <option value="Outstanding">Outstanding</option>
                            <option value="Satisfactory">Satisfactory</option>
                            <option value="Basic">Basic</option>
                            <option value="Elementary">Elementary</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Minimum Score (%)</label>
                        <input type="number" step="0.01" name="min_score" class="form-control" min="0" max="100" required placeholder="e.g., 80.00">
                    </div>
                    <div class="form-group">
                        <label>Maximum Score (%)</label>
                        <input type="number" step="0.01" name="max_score" class="form-control" min="0" max="100" required placeholder="e.g., 100.00">
                    </div>
                    <button type="submit" class="btn"><i class="fas fa-plus"></i> Add Grade</button>
                </form>
            </div>

            <!-- Grades Table -->
            <div style="margin-top: 2rem;">
                <h3 class="page-title">Current Grading Scale</h3>
                <?php if (empty($grades)): ?>
                    <p style="color: #6c757d; font-style: italic;">No grades defined yet.</p>
                <?php else: ?>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th>Grade</th>
                                <th>Achievement Level</th>
                                <th>Min Score (%)</th>
                                <th>Max Score (%)</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grades as $g): ?>
                                <tr>
                                    <td><?= htmlspecialchars($g['grade_letter']) ?></td>
                                    <td><?= htmlspecialchars($g['achievement_level']) ?></td>
                                    <td><?= number_format($g['min_score'], 2) ?></td>
                                    <td><?= number_format($g['max_score'], 2) ?></td>
                                    <td>
                                        <div class="action-icons">
                                            <button type="button" class="edit" title="Edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($g)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this grade from all categories?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                                <button type="submit" class="delete" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </main>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="margin-right: 8px;"></i> Edit Grade</h3>
                <span class="close" onclick="closeEditModal()">&times;</span>
            </div>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="id" id="edit_id">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Education Level</label>
                        <input type="text" class="form-control" value="O Level" readonly>
                    </div>
                    <div class="form-group">
                        <label>Subject Category</label>
                        <input type="text" class="form-control" value="Compulsory & Elective (Same Grading)" readonly>
                    </div>
                    <div class="form-group">
                        <label>Grade Letter</label>
                        <input type="text" name="grade_letter" id="edit_grade_letter" class="form-control" maxlength="5" required>
                    </div>
                    <div class="form-group">
                        <label>Achievement Level</label>
                        <select name="achievement_level" id="edit_achievement_level" class="form-select" required>
                            <option value="">Select Achievement Level</option>
                            <option value="Exceptional">Exceptional</option>
                            <option value="Outstanding">Outstanding</option>
                            <option value="Satisfactory">Satisfactory</option>
                            <option value="Basic">Basic</option>
                            <option value="Elementary">Elementary</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Minimum Score (%)</label>
                        <input type="number" step="0.01" name="min_score" id="edit_min_score" class="form-control" min="0" max="100" required>
                    </div>
                    <div class="form-group">
                        <label>Maximum Score (%)</label>
                        <input type="number" step="0.01" name="max_score" id="edit_max_score" class="form-control" min="0" max="100" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dropdown logic
        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.dropdown');
                document.querySelectorAll('.dropdown').forEach(d => d.classList.remove('active'));
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

        // Modal functions
        function openEditModal(gradeData) {
            document.getElementById('edit_id').value = gradeData.id;
            document.getElementById('edit_grade_letter').value = gradeData.grade_letter;
            document.getElementById('edit_achievement_level').value = gradeData.achievement_level;
            document.getElementById('edit_min_score').value = gradeData.min_score;
            document.getElementById('edit_max_score').value = gradeData.max_score;
            document.getElementById('editModal').style.display = 'block';
        }

        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('editModal');
            if (event.target == modal) {
                closeEditModal();
            }
        }

        // Keep dropdowns active based on current page
        document.addEventListener('DOMContentLoaded', function() {
            // Mark current page in navigation
            const currentPage = window.location.pathname.split('/').pop();
            if (currentPage === 'grading_o_level.php') {
                const assessmentDropdown = document.querySelector('.nav-item.dropdown.active');
                const oLevelNested = document.querySelector('.nested');
                if (oLevelNested) {
                    oLevelNested.classList.add('active');
                }
            }
        });
    </script>
</body>

</html>