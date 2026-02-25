<?php
// print_single_report_card.php
require_once 'config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_teacher = ($user_role === 'teacher');

// Get teacher ID if user is a teacher
$teacher_id = null;
$teacher_info = null;

if ($is_teacher) {
    try {
        $stmt = $pdo->prepare("SELECT id, teacher_id, fullname FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->execute([$user_id]);
        $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$teacher_info) {
            header("Location: index.php");
            exit;
        }
        $teacher_id = $teacher_info['id'];
    } catch (Exception $e) {
        error_log("Error fetching teacher info: " . $e->getMessage());
        header("Location: index.php");
        exit;
    }
} elseif (!$is_admin) {
    // Only admin and teachers can access this page
    header("Location: index.php");
    exit;
}

// Fetch teacher info for display
try {
    $stmt = $pdo->prepare("SELECT fullname, email, teacher_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($user['fullname'] ?? 'User');
    $email = htmlspecialchars($user['email'] ?? '');
    $teacher_id_display = htmlspecialchars($user['teacher_id'] ?? '—');
} catch (Exception $e) {
    $fullname = "User";
    $email = "—";
    $teacher_id_display = "—";
}

// Fetch academic years
$academic_years = [];
try {
    $stmt = $pdo->prepare("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY start_year DESC");
    $stmt->execute();
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
}

// Fetch school information
$school_info = [];
try {
    $stmt = $pdo->prepare("SELECT school_name, school_logo, address, motto, phone, email FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// Handle AJAX requests
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');
    
    $action = $_GET['action'] ?? '';
    
    if ($action == 'get_terms') {
        $academic_year_id = $_GET['academic_year'] ?? 0;
        try {
            $stmt = $pdo->prepare("SELECT id, term_name FROM academic_terms WHERE academic_year_id = ? AND status = 'active' ORDER BY id");
            $stmt->execute([$academic_year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'terms' => $terms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action == 'get_classes') {
        $level_id = $_GET['level'] ?? 0;
        $teacher_id = $_GET['teacher_id'] ?? 0;
        $is_admin = $_GET['is_admin'] ?? 0;
        $academic_year_id = $_GET['academic_year'] ?? 0;
        
        try {
            if ($is_admin == 1) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.name 
                    FROM classes c
                    WHERE c.level_id = ? AND c.status = 'active'
                    ORDER BY c.name
                ");
                $stmt->execute([$level_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.name
                    FROM classes c
                    JOIN teacher_classes tc ON c.id = tc.class_id
                    WHERE c.level_id = ? AND c.status = 'active' 
                      AND tc.teacher_id = ? AND tc.academic_year_id = ? AND tc.is_class_teacher = 1
                    ORDER BY c.name
                ");
                $stmt->execute([$level_id, $teacher_id, $academic_year_id]);
            }
            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action == 'get_streams') {
        $class_id = $_GET['class'] ?? 0;
        $teacher_id = $_GET['teacher_id'] ?? 0;
        $is_admin = $_GET['is_admin'] ?? 0;
        $academic_year_id = $_GET['academic_year'] ?? 0;
        
        try {
            if ($is_admin == 1) {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.name 
                    FROM streams s
                    WHERE s.class_id = ? AND s.status = 'active'
                    ORDER BY s.name
                ");
                $stmt->execute([$class_id]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT DISTINCT s.id, s.name
                    FROM streams s
                    JOIN teacher_classes tc ON s.class_id = tc.class_id
                    WHERE s.class_id = ? AND s.status = 'active' 
                      AND tc.teacher_id = ? AND tc.academic_year_id = ? AND tc.is_class_teacher = 1
                    ORDER BY s.name
                ");
                $stmt->execute([$class_id, $teacher_id, $academic_year_id]);
            }
            $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'streams' => $streams]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action == 'get_students') {
        $class_id = $_GET['class'] ?? 0;
        $stream_id = $_GET['stream'] ?? 0;
        
        try {
            $stmt = $pdo->prepare("
                SELECT id, student_id, surname, other_names 
                FROM students 
                WHERE class_id = ? AND stream_id = ? AND status = 'active'
                ORDER BY surname, other_names
            ");
            $stmt->execute([$class_id, $stream_id]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'students' => $students]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Print Report Card - School Management System</title>
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

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Preview Container */
        .preview-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .preview-container h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        .preview-iframe {
            width: 100%;
            height: 800px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            background: white;
        }

        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
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
            .sidebar .nav-link span {
                opacity: 0;
                transition: opacity 0.3s;
                white-space: nowrap;
            }

            .sidebar:hover .sidebar-header span,
            .sidebar:hover .nav-link span {
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

            .teacher-info h1 {
                font-size: 1.3rem;
            }

            .role-tag {
                align-self: flex-start;
            }

            .preview-iframe {
                height: 600px;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-chalkboard-teacher"></i>
            <span><?= $is_admin ? 'Admin Portal' : 'Teacher Portal' ?></span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="<?= $is_admin ? 'admin_dashboard.php' : 'teacher_dashboard.php' ?>" class="nav-link">
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
                <a href="print_single_report_card.php" class="nav-link active">
                    <i class="fas fa-print"></i>
                    <span>Print Report Card</span>
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
                <h1>Print Report Card</h1>
                <p><?= $fullname ?> | <?= $email ?></p>
            </div>
            <div class="role-tag"><?= $is_admin ? 'Admin' : 'Class Teacher' ?></div>
        </header>

        <main class="main-content">
            <?php if (!$is_admin && !$is_teacher): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i>
                    You do not have permission to access this page.
                </div>
            <?php else: ?>

            <!-- Selection Form -->
            <div class="form-section no-print">
                <h3>Generate Student Report Card</h3>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> Select the criteria and student to generate a report card. The format will automatically adapt based on the student's academic level (O-Level or A-Level).</p>
                </div>

                <form method="POST" id="reportForm" action="report_card_template.php" target="reportFrame">
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
                            <select class="form-control" id="level" name="level" required>
                                <option value="">Select Level</option>
                                <option value="1">O Level</option>
                                <option value="2">A Level</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select class="form-control" id="class" name="class" required>
                                <option value="">Select Level First</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="stream">Stream *</label>
                            <select class="form-control" id="stream" name="stream" required>
                                <option value="">Select Class First</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="student">Student *</label>
                            <select class="form-control" id="student" name="student" required>
                                <option value="">Select Stream First</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="generate_report" class="btn btn-primary" id="generateBtn">
                            <i class="fas fa-file-alt"></i> Generate Report Card
                        </button>
                        <button type="button" id="clearForm" class="btn btn-danger">
                            <i class="fas fa-times"></i> Clear Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Preview Section (initially hidden) -->
            <div class="preview-container no-print" id="previewSection" style="display: none;">
                <h3>Report Card Preview</h3>
                <iframe name="reportFrame" class="preview-iframe" id="reportFrame"></iframe>
                
                <div class="action-buttons">
                    <button type="button" onclick="printReport()" class="btn btn-success">
                        <i class="fas fa-print"></i> Print Report Card
                    </button>
                    <button type="button" onclick="closePreview()" class="btn btn-secondary">
                        <i class="fas fa-times"></i> Close Preview
                    </button>
                </div>
            </div>

            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            // Show loading spinner during form submission
            $('#reportForm').on('submit', function() {
                $('#loadingSpinner').show();
                $('#previewSection').hide();
            });

            // Store teacher info for AJAX requests
            const teacherId = '<?= $teacher_id ?>';
            const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;

            // Load terms when academic year is selected
            $('#academic_year').on('change', function() {
                const academicYearId = $(this).val();
                const termSelect = $('#term');
                
                if (!academicYearId) {
                    termSelect.html('<option value="">Select Academic Year First</option>');
                    return;
                }
                
                termSelect.html('<option value="">Loading...</option>');
                
                $.ajax({
                    url: 'print_single_report_card.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_terms',
                        academic_year: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.terms.length > 0) {
                            let options = '<option value="">Select Term</option>';
                            $.each(data.terms, function(index, term) {
                                options += `<option value="${term.id}">${term.term_name}</option>`;
                            });
                            termSelect.html(options);
                        } else {
                            termSelect.html('<option value="">No active terms found</option>');
                        }
                    },
                    error: function() {
                        termSelect.html('<option value="">Error loading terms</option>');
                    }
                });
            });

            // Load classes based on selected level
            $('#level').on('change', function() {
                const levelId = $(this).val();
                const academicYearId = $('#academic_year').val();
                const classSelect = $('#class');
                const streamSelect = $('#stream');
                const studentSelect = $('#student');

                if (!levelId || !academicYearId) {
                    classSelect.html('<option value="">Select Level and Academic Year First</option>');
                    return;
                }

                classSelect.html('<option value="">Loading...</option>');
                streamSelect.html('<option value="">Select Class First</option>');
                studentSelect.html('<option value="">Select Stream First</option>');

                $.ajax({
                    url: 'print_single_report_card.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_classes',
                        level: levelId,
                        academic_year: academicYearId,
                        teacher_id: teacherId,
                        is_admin: isAdmin ? 1 : 0
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.classes.length > 0) {
                            let options = '<option value="">Select Class</option>';
                            $.each(data.classes, function(index, cls) {
                                options += `<option value="${cls.id}">${cls.name}</option>`;
                            });
                            classSelect.html(options);
                        } else {
                            classSelect.html('<option value="">No classes found</option>');
                        }
                    },
                    error: function() {
                        classSelect.html('<option value="">Error loading classes</option>');
                    }
                });
            });

            // Load streams based on selected class
            $('#class').on('change', function() {
                const classId = $(this).val();
                const academicYearId = $('#academic_year').val();
                const streamSelect = $('#stream');
                const studentSelect = $('#student');

                if (!classId) {
                    streamSelect.html('<option value="">Select Class First</option>');
                    return;
                }

                streamSelect.html('<option value="">Loading...</option>');
                studentSelect.html('<option value="">Select Stream First</option>');

                $.ajax({
                    url: 'print_single_report_card.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_streams',
                        class: classId,
                        academic_year: academicYearId,
                        teacher_id: teacherId,
                        is_admin: isAdmin ? 1 : 0
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.streams.length > 0) {
                            let options = '<option value="">Select Stream</option>';
                            $.each(data.streams, function(index, stream) {
                                options += `<option value="${stream.id}">${stream.name}</option>`;
                            });
                            streamSelect.html(options);
                        } else {
                            streamSelect.html('<option value="">No streams found</option>');
                        }
                    },
                    error: function() {
                        streamSelect.html('<option value="">Error loading streams</option>');
                    }
                });
            });

            // Load students based on selected stream
            $('#stream').on('change', function() {
                const classId = $('#class').val();
                const streamId = $(this).val();
                const studentSelect = $('#student');

                if (!classId || !streamId) {
                    studentSelect.html('<option value="">Select Class and Stream First</option>');
                    return;
                }

                studentSelect.html('<option value="">Loading...</option>');

                $.ajax({
                    url: 'print_single_report_card.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_students',
                        class: classId,
                        stream: streamId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.students.length > 0) {
                            let options = '<option value="">Select Student</option>';
                            $.each(data.students, function(index, student) {
                                options += `<option value="${student.id}">${student.student_id} - ${student.surname} ${student.other_names}</option>`;
                            });
                            studentSelect.html(options);
                        } else {
                            studentSelect.html('<option value="">No students found</option>');
                        }
                    },
                    error: function() {
                        studentSelect.html('<option value="">Error loading students</option>');
                    }
                });
            });

            // Clear form button
            $('#clearForm').on('click', function() {
                window.location.href = 'print_single_report_card.php';
            });

            // Form validation
            $('#reportForm').on('submit', function(e) {
                const requiredFields = ['academic_year', 'term', 'level', 'class', 'stream', 'student'];
                let isValid = true;
                let missingFields = [];

                requiredFields.forEach(function(field) {
                    const value = $('#' + field).val();
                    if (!value) {
                        isValid = false;
                        missingFields.push(field.replace('_', ' '));
                        $('#' + field).css('border-color', 'red');
                    } else {
                        $('#' + field).css('border-color', '');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields: ' + missingFields.join(', '));
                    return false;
                }
            });

            // Listen for iframe load event to hide spinner and show preview
            $('#reportFrame').on('load', function() {
                $('#loadingSpinner').hide();
                $('#previewSection').show();
            });
        });

        function printReport() {
            const iframe = document.getElementById('reportFrame');
            iframe.contentWindow.print();
        }

        function closePreview() {
            $('#previewSection').hide();
            $('#reportFrame').attr('src', 'about:blank');
        }
    </script>
</body>

</html>