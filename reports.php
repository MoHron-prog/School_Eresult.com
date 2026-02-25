<?php
// file: report.php
// Description: Report Center Dashboard with role-based access to report card printing modules
require_once 'config.php';

// ==================== SESSION & ACCESS CONTROL ====================
// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

// Get user role and ID
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$fullname = $_SESSION['fullname'] ?? 'User';

// Define roles that have access to reports
$allowed_roles = ['admin', 'class_teacher']; // Subject teachers are explicitly denied

// Check if user has access to reports
if (!in_array($user_role, $allowed_roles)) {
    // Subject teachers or other roles - redirect with error message
    $_SESSION['error_message'] = "You do not have permission to access the Report Center.";
    header("Location: " . ($user_role === 'teacher' ? 'teacher_dashboard.php' : 'index.php'));
    exit;
}

// Double-check for subject teachers specifically (additional security)
if ($user_role === 'teacher') {
    // Verify if this teacher is actually a class teacher
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as class_count 
            FROM classes 
            WHERE class_teacher_id = ? AND status = 'active'
        ");
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        // If not a class teacher, deny access
        if ($result['class_count'] == 0) {
            $_SESSION['error_message'] = "Only Class Teachers and Administrators can access the Report Center.";
            header("Location: teacher_dashboard.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("Error verifying class teacher status: " . $e->getMessage());
        // Fail secure - deny access on error
        header("Location: teacher_dashboard.php");
        exit;
    }
}

// ==================== FETCH USER & SCHOOL INFORMATION ====================
try {
    // Fetch user details
    $stmt = $pdo->prepare("SELECT fullname, email, teacher_id FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $fullname = htmlspecialchars($user['fullname']);
        $email = htmlspecialchars($user['email'] ?? '');
        $teacher_id_display = htmlspecialchars($user['teacher_id'] ?? '—');
    }
} catch (Exception $e) {
    error_log("Error fetching user info: " . $e->getMessage());
    // Continue with default values
}

// Fetch school information for branding
$school_info = [];
try {
    $stmt = $pdo->prepare("SELECT school_name, school_logo, address, motto FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// ==================== FETCH STATISTICS FOR DASHBOARD ====================
$stats = [
    'total_classes' => 0,
    'total_students' => 0,
    'total_subjects' => 0,
    'academic_years' => []
];

try {
    // Get active academic years
    $stmt = $pdo->prepare("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY start_year DESC");
    $stmt->execute();
    $stats['academic_years'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($user_role === 'admin') {
        // Admin stats - overall counts
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM classes WHERE status = 'active'");
        $stmt->execute();
        $stats['total_classes'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM students WHERE status = 'active'");
        $stmt->execute();
        $stats['total_students'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM subjects WHERE status = 'active'");
        $stmt->execute();
        $stats['total_subjects'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    } else {
        // Class teacher stats - only their assigned class
        $stmt = $pdo->prepare("
            SELECT c.id, c.name, 
                   (SELECT COUNT(*) FROM students WHERE class_id = c.id AND status = 'active') as student_count
            FROM classes c 
            WHERE c.class_teacher_id = ? AND c.status = 'active'
        ");
        $stmt->execute([$user_id]);
        $class_info = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($class_info) {
            $stats['total_classes'] = 1;
            $stats['total_students'] = $class_info['student_count'] ?? 0;
            $stats['assigned_class'] = $class_info['name'] ?? '';
        }

        // Get subjects taught by this class teacher
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT subject_id) as subject_count
            FROM teacher_subjects 
            WHERE teacher_id = ?
        ");
        $stmt->execute([$user_id]);
        $stats['total_subjects'] = $stmt->fetch(PDO::FETCH_ASSOC)['subject_count'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Error fetching statistics: " . $e->getMessage());
}

// Handle any session messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';

// Clear session messages
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Report Center - <?= htmlspecialchars($school_info['school_name'] ?? 'School Management System') ?></title>

    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />

    <!-- Google Fonts - Inter for clean typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <style>
        /* ============ COLORS MATCHING marksheets.php ============ */
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
            --success-light: #d4edda;
            --info: #17a2b8;
            --info-light: #d1ecf1;
            --warning: #ffc107;
            --danger: #dc3545;
            --danger-light: #f8d7da;
            --gray-100: #f8f9fa;
            --gray-200: #e9ecef;
            --gray-300: #dee2e6;
            --gray-400: #ced4da;
            --gray-500: #adb5bd;
            --gray-600: #6c757d;
            --gray-700: #495057;
            --gray-800: #343a40;
            --gray-900: #212529;
            --sidebar-width: 280px;
            --header-height: 80px;
            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.08);
            --shadow-lg: 0 8px 24px rgba(0, 0, 0, 0.12);
            --shadow-hover: 0 12px 28px rgba(26, 42, 108, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: var(--body-bg);
            color: var(--text-dark);
            line-height: 1.5;
            font-size: 14px;
            overflow: hidden;
            height: 100vh;
        }

        /* Layout */
        .app {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ============ SIDEBAR - EXACT MATCH FROM marksheets.php ============ */
        .sidebar {
            width: var(--sidebar-width);
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
            color: var(--text-light);
        }

        .sidebar-header i {
            color: var(--text-light);
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

        /* ============ MAIN WRAPPER ============ */
        .main-wrapper {
            margin-left: var(--sidebar-width);
            width: calc(100% - var(--sidebar-width));
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
        }

        /* Header - matching marksheets.php */
        .header {
            height: var(--header-height);
            min-height: var(--header-height);
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
            font-weight: 600;
        }

        .teacher-info p {
            font-size: 1rem;
            color: var(--gray-600);
            margin-top: 4px;
        }

        .role-tag {
            background: var(--primary);
            color: white;
            padding: 3px 10px;
            border-radius: 14px;
            font-size: 0.95rem;
            font-weight: 500;
        }

        /* Main Content Area */
        .main-content {
            padding: 1.5rem 2rem;
            flex: 1;
            overflow-y: auto;
        }

        /* Container */
        .container-fluid {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Breadcrumbs */
        .breadcrumb-custom {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            color: var(--gray-600);
            font-size: 0.95rem;
        }

        .breadcrumb-custom a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb-custom a:hover {
            text-decoration: underline;
        }

        .breadcrumb-custom i {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .breadcrumb-custom .current {
            color: var(--gray-700);
            font-weight: 500;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1.5rem;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 500;
            border: 1px solid transparent;
            box-shadow: var(--shadow-sm);
        }

        .alert-success {
            background: var(--success-light);
            color: #155724;
            border-color: #c3e6cb;
        }

        .alert-danger {
            background: var(--danger-light);
            color: #721c24;
            border-color: #f5c6cb;
        }

        /* Welcome Card */
        .welcome-card {
            background: white;
            border-radius: var(--radius-md);
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
        }

        .welcome-card:hover {
            box-shadow: var(--shadow-lg);
        }

        .welcome-card h2 {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .welcome-card p {
            color: var(--gray-700);
            font-size: 0.95rem;
        }

        .welcome-card strong {
            color: var(--primary);
        }

        /* Stats Grid - CENTERED with SHADOW BOX and ICONS */
        .stats-section {
            margin-bottom: 2.5rem;
        }

        .stats-grid {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }

        .stat-item {
            background: white;
            border-radius: var(--radius-md);
            padding: 1.25rem 1.75rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--gray-200);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 1rem;
            min-width: 200px;
            flex: 0 1 auto;
        }

        .stat-item:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary) 0%, #2a3f8e 100%);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.3rem;
            box-shadow: 0 4px 10px rgba(26, 42, 108, 0.2);
        }

        .stat-icon.classes {
            background: linear-gradient(135deg, #1a2a6c, #2a3f8e);
        }

        .stat-icon.students {
            background: linear-gradient(135deg, #28a745, #34ce57);
        }

        .stat-icon.subjects {
            background: linear-gradient(135deg, #17a2b8, #1fc8e3);
        }

        .stat-icon.years {
            background: linear-gradient(135deg, #ffc107, #ffdb6d);
            color: #212529;
        }

        .stat-content {
            display: flex;
            flex-direction: column;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            color: var(--gray-900);
            line-height: 1.2;
        }

        .stat-label {
            color: var(--gray-600);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        .stat-desc {
            font-size: 0.7rem;
            color: var(--gray-400);
            margin-top: 2px;
        }

        /* Report Cards - CENTERED with REDUCED SIZE */
        .report-cards-section {
            margin: 2.5rem 0;
        }

        .report-cards-container {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 2rem;
            flex-wrap: wrap;
        }

        .report-card {
            background: white;
            border-radius: var(--radius-md);
            border: 1px solid var(--gray-200);
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-md);
            width: 300px;
            min-width: 280px;
            max-width: 320px;
        }

        .report-card:hover {
            box-shadow: var(--shadow-hover);
            border-color: var(--primary);
            transform: translateY(-5px);
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 1rem;
            background: linear-gradient(to right, #f8f9fa, #ffffff);
        }

        .card-icon {
            width: 45px;
            height: 45px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .card-icon.single {
            background: linear-gradient(135deg, #28a745, #34ce57);
            color: white;
        }

        .card-icon.bulk {
            background: linear-gradient(135deg, #17a2b8, #1fc8e3);
            color: white;
        }

        .card-header h3 {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 2px;
            line-height: 1.3;
        }

        .card-header p {
            color: var(--gray-600);
            font-size: 0.8rem;
        }

        .card-body {
            padding: 1.25rem 1.5rem;
        }

        .feature-list {
            list-style: none;
            margin-bottom: 1.5rem;
        }

        .feature-list li {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.4rem 0;
            color: var(--gray-700);
            font-size: 0.85rem;
            border-bottom: 1px dashed var(--gray-200);
        }

        .feature-list li:last-child {
            border-bottom: none;
        }

        .feature-list li i {
            color: var(--success);
            font-size: 0.85rem;
            width: 16px;
        }

        .card-action {
            margin-top: 0.5rem;
            text-align: center;
        }

        /* Buttons - matching marksheets.php with reduced size */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 0.5rem 1.25rem;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            font-size: 0.9rem;
            text-decoration: none;
            transition: all 0.2s;
            cursor: pointer;
            border: 1px solid transparent;
            width: 100%;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .btn-success {
            background: linear-gradient(135deg, #28a745, #34ce57);
            color: white;
        }

        .btn-success:hover {
            background: linear-gradient(135deg, #218838, #2ebc4d);
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(40, 167, 69, 0.3);
        }

        .btn-info {
            background: linear-gradient(135deg, #17a2b8, #1fc8e3);
            color: white;
        }

        .btn-info:hover {
            background: linear-gradient(135deg, #138496, #1bb5d0);
            transform: scale(1.02);
            box-shadow: 0 4px 8px rgba(23, 162, 184, 0.3);
        }

        .btn i {
            font-size: 0.9rem;
        }

        /* Filter Bar */
        .filter-bar {
            background: white;
            border-radius: var(--radius-md);
            padding: 1rem 1.5rem;
            margin: 1rem 0 2rem;
            border: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: var(--shadow-md);
        }

        .filter-group {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .filter-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--gray-700);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .filter-label i {
            color: var(--primary);
        }

        .filter-select {
            padding: 0.5rem 2rem 0.5rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            color: var(--gray-700);
            background: white;
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23495057' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            min-width: 180px;
            box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
        }

        .filter-select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .filter-note {
            color: var(--gray-500);
            font-size: 0.85rem;
        }

        .filter-note i {
            color: var(--info);
        }

        /* Info Banner */
        .info-banner {
            background: linear-gradient(135deg, #e8f4fd, #d4e6f9);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-sm);
            padding: 1rem 1.5rem;
            margin-top: 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--gray-700);
            font-size: 0.95rem;
            box-shadow: var(--shadow-sm);
        }

        .info-banner i {
            color: var(--primary);
            font-size: 1.1rem;
        }

        .info-banner strong {
            color: var(--primary);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                transition: width 0.3s;
                overflow-x: hidden;
            }

            .sidebar:hover {
                width: var(--sidebar-width);
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
                margin-left: var(--sidebar-width);
                width: calc(100% - var(--sidebar-width));
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 1rem;
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

            .filter-bar {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-group {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-select {
                width: 100%;
            }

            .report-cards-container {
                flex-direction: column;
                align-items: center;
            }

            .report-card {
                width: 100%;
                max-width: 320px;
            }

            .stats-grid {
                flex-direction: column;
                align-items: center;
            }

            .stat-item {
                width: 100%;
                max-width: 280px;
            }

            .card-header {
                flex-direction: row;
                text-align: left;
            }
        }
    </style>
</head>

<body>
    <div class="app">
        <!-- Sidebar - EXACT MATCH FROM marksheets.php -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-chalkboard-teacher"></i>
                <span><?= $user_role === 'admin' ? 'Admin Portal' : 'Teacher Portal' ?></span>
            </div>
            <ul class="nav-menu">
                <li class="nav-item">
                    <a href="<?= $user_role === 'admin' ? 'admin_dashboard.php' : 'teacher_dashboard.php' ?>" class="nav-link">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>

                <?php if ($user_role === 'admin' || $user_role === 'class_teacher'): ?>
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
                <?php endif; ?>

                <li class="nav-item">
                    <a href="reports.php" class="nav-link active">
                        <i class="fas fa-print"></i>
                        <span>Report Center</span>
                    </a>
                </li>

                <?php if ($user_role === 'admin'): ?>
                    <li class="nav-item">
                        <a href="master_marksheet.php" class="nav-link">
                            <i class="fas fa-file-alt"></i>
                            <span>Master Marksheets</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="logout-section">
                <button class="logout-btn" onclick="window.location='logout.php'">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Logout</span>
                </button>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="main-wrapper">
            <!-- Header - matching marksheets.php -->
            <header class="header">
                <div class="teacher-info">
                    <h1>Report Center</h1>
                    <p><?= htmlspecialchars($fullname) ?> | <?= htmlspecialchars($email ?? '') ?></p>
                </div>
                <div class="role-tag"><?= $user_role === 'admin' ? 'Admin' : 'Class Teacher' ?></div>
            </header>

            <!-- Main Content Area -->
            <main class="main-content">
                <div class="container-fluid">
                    <!-- Breadcrumbs -->
                    <div class="breadcrumb-custom">
                        <a href="<?= $user_role === 'admin' ? 'admin_dashboard.php' : 'teacher_dashboard.php' ?>">
                            <i class="fas fa-home"></i> Dashboard
                        </a>
                        <i class="fas fa-chevron-right"></i>
                        <span class="current">Report Center</span>
                    </div>

                    <!-- Alert Messages -->
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i>
                            <?= htmlspecialchars($success_message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i>
                            <?= htmlspecialchars($error_message) ?>
                        </div>
                    <?php endif; ?>

                    <!-- Welcome Card -->
                    <div class="welcome-card">
                        <h2>Welcome to Report Center</h2>
                        <p>
                            <?php if ($user_role === 'admin'): ?>
                                Generate and print report cards for any class, stream, or individual student. You have full access to all report card features.
                            <?php else: ?>
                                Generate and print report cards for your assigned class:
                                <strong><?= htmlspecialchars($stats['assigned_class'] ?? 'N/A') ?></strong>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Stats Cards - CENTERED with SHADOW BOX and ICONS -->
                    <div class="stats-section">
                        <div class="stats-grid">
                            <div class="stat-item">
                                <div class="stat-icon classes">
                                    <i class="fas fa-chalkboard"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-number"><?= $stats['total_classes'] ?></span>
                                    <span class="stat-label">Classes</span>
                                    <span class="stat-desc">Assigned to you</span>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon students">
                                    <i class="fas fa-users"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-number"><?= $stats['total_students'] ?></span>
                                    <span class="stat-label">Students</span>
                                    <span class="stat-desc">Active enrollment</span>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon subjects">
                                    <i class="fas fa-book"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-number"><?= $stats['total_subjects'] ?></span>
                                    <span class="stat-label">Subjects</span>
                                    <span class="stat-desc">In curriculum</span>
                                </div>
                            </div>

                            <div class="stat-item">
                                <div class="stat-icon years">
                                    <i class="fas fa-calendar-alt"></i>
                                </div>
                                <div class="stat-content">
                                    <span class="stat-number"><?= count($stats['academic_years']) ?></span>
                                    <span class="stat-label">Academic Years</span>
                                    <span class="stat-desc">Currently active</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Report Cards - CENTERED with REDUCED SIZE -->
                    <div class="report-cards-section">
                        <div class="report-cards-container">
                            <!-- Single Report Card -->
                            <div class="report-card">
                                <div class="card-header">
                                    <div class="card-icon single">
                                        <i class="fas fa-user-graduate"></i>
                                    </div>
                                    <div>
                                        <h3>Print Single</h3>
                                        <p>Individual student report</p>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <ul class="feature-list">
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Search by name or ID
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            All subjects & grades
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Teacher comments
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Print or save as PDF
                                        </li>
                                    </ul>

                                    <div class="card-action">
                                        <a href="print_single_report_card.php" class="btn btn-success">
                                            <i class="fas fa-print"></i>
                                            Print Single
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- Bulk Report Card -->
                            <div class="report-card">
                                <div class="card-header">
                                    <div class="card-icon bulk">
                                        <i class="fas fa-layer-group"></i>
                                    </div>
                                    <div>
                                        <h3>Bulk Print</h3>
                                        <p>Entire class reports</p>
                                    </div>
                                </div>

                                <div class="card-body">
                                    <ul class="feature-list">
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Select class & stream
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Generate all at once
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Print as PDF set
                                        </li>
                                        <li>
                                            <i class="fas fa-check-circle"></i>
                                            Export options
                                        </li>
                                    </ul>

                                    <div class="card-action">
                                        <a href="bulk_report_card_print.php" class="btn btn-info">
                                            <i class="fas fa-print"></i>
                                            Bulk Print
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Filter -->
                    <?php if (!empty($stats['academic_years'])): ?>
                        <div class="filter-bar">
                            <div class="filter-group">
                                <span class="filter-label">
                                    <i class="fas fa-calendar-alt"></i>
                                    Academic Year:
                                </span>
                                <select class="filter-select" id="academic_year" onchange="filterAcademicYear(this.value)">
                                    <option value="">All Academic Years</option>
                                    <?php foreach ($stats['academic_years'] as $year): ?>
                                        <option value="<?= $year['id'] ?>"><?= htmlspecialchars($year['year_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-note">
                                <i class="fas fa-info-circle"></i>
                                Filter applies to report card modules
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Info Banner -->
                    <div class="info-banner">
                        <i class="fas fa-shield-alt"></i>
                        <span>
                            <strong>Access Information:</strong>
                            <?php if ($user_role === 'admin'): ?>
                                You have administrator access to all report card features for all classes and streams.
                            <?php else: ?>
                                You have access to <strong><?= htmlspecialchars($stats['assigned_class'] ?? 'your assigned class') ?></strong> only.
                                Subject teachers cannot access this module.
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script>
        // Quick filter function
        function filterAcademicYear(yearId) {
            if (yearId) {
                localStorage.setItem('selected_academic_year', yearId);
                // Show subtle feedback
                const filterBar = document.querySelector('.filter-bar');
                if (filterBar) {
                    const originalBg = filterBar.style.backgroundColor;
                    filterBar.style.backgroundColor = '#e8f4fd';
                    setTimeout(() => {
                        filterBar.style.backgroundColor = originalBg;
                    }, 300);
                }
            } else {
                localStorage.removeItem('selected_academic_year');
            }
        }

        // Load saved filter
        $(document).ready(function() {
            const savedYear = localStorage.getItem('selected_academic_year');
            if (savedYear) {
                $('#academic_year').val(savedYear);
            }

            // Pass filter to report card links
            $('.btn-success, .btn-info').on('click', function(e) {
                const academicYear = $('#academic_year').val();
                if (academicYear) {
                    const href = $(this).attr('href');
                    const separator = href.includes('?') ? '&' : '?';
                    $(this).attr('href', href + separator + 'academic_year=' + academicYear);
                }
            });
        });

        // Prevent unauthorized access via back button
        window.history.pushState(null, null, window.location.href);
        window.onpopstate = function() {
            window.history.go(1);
        };
    </script>
</body>

</html>