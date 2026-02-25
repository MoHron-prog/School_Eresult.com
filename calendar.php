<?php
// academic_calendar.php - Teacher/Class Teacher View Only
require_once 'config.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is teacher or class teacher
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher'])) {
    header("Location: index.php");
    exit;
}

$teacher_id = $_SESSION['user_id'];
$teacher_name = $_SESSION['fullname'] ?? 'Teacher';
$teacher_role = $_SESSION['role'];

// Fetch teacher info
try {
    $stmt = $pdo->prepare("SELECT email, position FROM users WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$teacher) {
        header("Location: index.php");
        exit;
    }
    $teacher_email = htmlspecialchars($teacher['email']);
    $teacher_position = htmlspecialchars($teacher['position']);
} catch (Exception $e) {
    $teacher_email = "—";
    $teacher_position = "Teacher";
}

// Get default tab from URL or default to years
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['years', 'terms']) ? $_GET['tab'] : 'years';

// Fetch academic years for display
try {
    $academic_years = $pdo->query("SELECT id, year_name, start_year, display_format, status, is_current FROM academic_years ORDER BY start_year DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $academic_years = [];
}

// Fetch academic terms with year info
try {
    $terms = $pdo->query("
        SELECT at.*, ay.year_name 
        FROM academic_terms at 
        JOIN academic_years ay ON at.academic_year_id = ay.id 
        ORDER BY ay.start_year DESC, 
        CASE at.term_name 
            WHEN 'Term I' THEN 1 
            WHEN 'Term II' THEN 2 
            WHEN 'Term III' THEN 3 
            ELSE 4 
        END
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $terms = [];
}

// Prepare terms for display with formatted dates and duration
foreach ($terms as &$term) {
    $opening = new DateTime($term['opening_date']);
    $closing = new DateTime($term['closing_date']);
    $term['formatted_opening'] = $opening->format('M j, Y');
    $term['formatted_closing'] = $closing->format('M j, Y');
    $term['duration'] = $opening->diff($closing)->days + 1;
}
unset($term);

// Get current academic year
$current_year = null;
foreach ($academic_years as $year) {
    if ($year['is_current']) {
        $current_year = $year;
        break;
    }
}

// Get current/active term
$current_term = null;
foreach ($terms as $term) {
    if ($term['status'] === 'active') {
        $current_term = $term;
        break;
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Academic Calendar - Teacher Portal</title>
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
            --info: #17a2b8;
            --light-gray: #f8f9fa;
            --border-gray: #dee2e6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: var(--body-bg);
            color: var(--text-dark);
            min-height: 100vh;
            overflow-x: hidden;
            line-height: 1.6;
        }

        /* Mobile First Design */
        .container {
            width: 100%;
            padding: 0;
        }

        /* Sidebar for Mobile */
        .sidebar {
            position: fixed;
            top: 0;
            left: -280px;
            width: 280px;
            height: 100vh;
            background: var(--sidebar-bg);
            color: var(--text-light);
            transition: left 0.3s ease;
            z-index: 1000;
            box-shadow: 2px 0 8px rgba(0, 0, 0, 0.2);
            display: flex;
            flex-direction: column;
        }

        .sidebar.active {
            left: 0;
        }

        .sidebar-header {
            padding: 1.5rem 1rem;
            background: var(--primary);
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid #3a506b;
        }

        .sidebar-header i {
            font-size: 1.5rem;
        }

        .sidebar-header span {
            font-weight: 700;
            font-size: 1.2rem;
        }

        .nav-menu {
            list-style: none;
            padding: 1rem 0;
            flex: 1;
            overflow-y: auto;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 1rem 1.5rem;
            color: var(--text-light);
            text-decoration: none;
            font-size: 1rem;
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }

        .nav-link:hover,
        .nav-link.active {
            background: var(--sidebar-hover);
            border-left-color: var(--secondary);
        }

        .nav-link i {
            width: 24px;
            font-size: 1.1rem;
            margin-right: 12px;
        }

        .logout-section {
            padding: 1rem;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }

        .logout-btn {
            width: 100%;
            display: flex;
            align-items: center;
            gap: 10px;
            background: none;
            border: none;
            color: #e74c3c;
            font-size: 1rem;
            padding: 0.75rem;
            cursor: pointer;
            border-radius: 4px;
            transition: background 0.2s;
        }

        .logout-btn:hover {
            background: rgba(231, 76, 60, 0.1);
        }

        /* Main Content */
        .main-content {
            padding: 1rem;
            width: 100%;
            max-width: 100%;
            overflow-x: hidden;
        }

        /* Mobile Header */
        .mobile-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: white;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            margin-bottom: 1rem;
            position: sticky;
            top: 0;
            z-index: 900;
        }

        .menu-toggle {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
        }

        .teacher-info {
            flex: 1;
            padding: 0 1rem;
        }

        .teacher-info h1 {
            font-size: 1.2rem;
            color: var(--primary);
            margin-bottom: 0.25rem;
            word-break: break-word;
        }

        .teacher-info p {
            font-size: 0.85rem;
            color: #6c757d;
            margin: 0;
            word-break: break-all;
        }

        .role-tag {
            background: var(--secondary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            white-space: nowrap;
        }

        /* Welcome Card */
        .welcome-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .welcome-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .welcome-card h2 {
            color: var(--primary);
            margin-bottom: 0.75rem;
            font-size: 1.4rem;
            font-weight: 700;
        }

        .welcome-card p {
            color: #5a6268;
            line-height: 1.7;
            margin-bottom: 0.5rem;
        }

        /* Current Info Cards */
        .current-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .current-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            border-top: 4px solid var(--primary);
            position: relative;
            overflow: hidden;
        }

        .current-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--secondary));
        }

        .current-card h3 {
            color: var(--primary);
            font-size: 1.1rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .current-card h3 i {
            color: var(--secondary);
        }

        .current-card .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.75rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-gray);
        }

        .current-card .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.95rem;
        }

        .info-value {
            color: var(--primary);
            font-weight: 600;
            font-size: 0.95rem;
            text-align: right;
        }

        .info-value.current {
            background: #d4edda;
            color: #155724;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .info-value.active {
            background: #cce5ff;
            color: #004085;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        .info-value.inactive {
            background: #f8d7da;
            color: #721c24;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
        }

        /* Tabs */
        .tabs {
            display: flex;
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }

        .tab {
            padding: 0.8rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
            white-space: nowrap;
        }

        .tab.active {
            color: var(--primary);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Tables */
        .table-container {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
            margin-bottom: 2rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
            font-size: 0.95rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .current-badge {
            background: #cce5ff;
            color: #004085;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        /* Overlay for mobile sidebar */
        .sidebar-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
            display: none;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .sidebar-overlay.active {
            display: block;
            opacity: 1;
        }

        /* View Only Notice */
        .view-notice {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .view-notice i {
            color: #856404;
            font-size: 1.5rem;
        }

        .view-notice p {
            color: #856404;
            margin: 0;
            font-size: 0.95rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.08);
            margin: 2rem 0;
            border: 2px dashed var(--border-gray);
        }

        .empty-state i {
            font-size: 4rem;
            color: #dee2e6;
            margin-bottom: 1.5rem;
            opacity: 0.6;
        }

        .empty-state h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.5rem;
            font-weight: 700;
        }

        .empty-state p {
            color: #6c757d;
            font-size: 1rem;
            max-width: 500px;
            margin: 0 auto 2rem;
            line-height: 1.6;
        }

        /* Desktop View */
        @media (min-width: 768px) {
            .container {
                display: flex;
                min-height: 100vh;
            }

            .sidebar {
                position: relative;
                left: 0;
                width: 260px;
                flex-shrink: 0;
            }

            .mobile-header {
                display: none;
            }

            .main-content {
                flex: 1;
                padding: 2rem;
                overflow-y: auto;
                max-height: 100vh;
            }

            .menu-toggle {
                display: none;
            }

            .teacher-info h1 {
                font-size: 1.5rem;
            }

            .welcome-card {
                padding: 2rem;
            }

            .table th,
            .table td {
                padding: 1rem 1.25rem;
            }
        }

        @media (min-width: 992px) {
            .sidebar {
                width: 280px;
            }

            .main-content {
                padding: 2.5rem;
            }

            .current-info {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        /* Tablet View */
        @media (max-width: 767px) {
            .table {
                display: block;
                overflow-x: auto;
            }

            .mobile-header {
                flex-wrap: wrap;
                padding: 0.75rem;
            }

            .teacher-info {
                order: 3;
                width: 100%;
                margin-top: 0.5rem;
                padding: 0;
            }

            .tabs {
                flex-wrap: nowrap;
                overflow-x: auto;
            }

            .tab {
                white-space: nowrap;
                flex-shrink: 0;
            }
        }

        /* Small Mobile View */
        @media (max-width: 576px) {
            .current-info {
                grid-template-columns: 1fr;
            }

            .mobile-header {
                padding: 0.75rem;
            }

            .welcome-card,
            .current-card {
                padding: 1.25rem;
            }

            .main-content {
                padding: 0.75rem;
            }

            .table th,
            .table td {
                padding: 0.75rem;
            }
        }

        /* Info Card */
        .info-card {
            background: linear-gradient(135deg, var(--primary), #2c3e50);
            color: white;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 2rem;
        }

        .info-card h4 {
            margin-bottom: 0.75rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .info-card p {
            opacity: 0.9;
            font-size: 0.95rem;
            margin: 0;
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Mobile Header -->
        <div class="mobile-header">
            <button class="menu-toggle" id="menuToggle" aria-label="Toggle menu">
                <i class="fas fa-bars"></i>
            </button>
            <div class="teacher-info">
                <h1><?= htmlspecialchars($teacher_name) ?></h1>
                <?php if ($teacher_email): ?>
                    <p><?= htmlspecialchars($teacher_email) ?></p>
                <?php endif; ?>
            </div>
            <div class="role-tag">
                <?= htmlspecialchars($teacher_position) ?>
            </div>
        </div>

        <!-- Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Teacher Portal</span>
            </div>
            <ul class="nav-menu">
                <li>
                    <a href="teacher_dashboard.php" class="nav-link active">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link">
                        <i class="fas fa-plus-circle"></i>
                        <span>Add Marks</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link">
                        <i class="fas fa-eye"></i>
                        <span>View Marks</span>
                    </a>
                </li>
                <li>
                    <a href="#" class="nav-link">
                        <i class="fas fa-file-alt"></i>
                        <span>Generate Reports</span>
                    </a>
                <li>
                    <a href="calendar.php" class="nav-link">
                        <i class="fas fa-calendar-alt"></i>
                        <span>Academic Calendar</span>
                    </a>
                </li>
                <a href="profile.php" class="nav-link">
                    <i class="fas fa-user"></i>
                    <span>Profile</span>
                </a>
                </li>
                <li>
                    <a href="settings.php" class="nav-link">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
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
        <!-- Main Content -->
        <main class="main-content">
            <!-- Welcome Card -->
            <div class="welcome-card">
                <h2>Academic Calendar - View Only</h2>
                <p>Welcome to the Academic Calendar. Here you can view all academic years and terms set by the school administration. This is a <strong>view-only</strong> interface for teachers and class teachers.</p>
            </div>

            <!-- View Only Notice -->
            <div class="view-notice">
                <i class="fas fa-info-circle"></i>
                <p><strong>Note:</strong> This is a view-only interface. Only school administrators can add, edit, or delete academic years and terms.</p>
            </div>

            <!-- Current Information -->
            <div class="current-info">
                <div class="current-card">
                    <h3><i class="fas fa-calendar"></i> Current Academic Year</h3>
                    <?php if ($current_year): ?>
                        <div class="info-item">
                            <span class="info-label">Year:</span>
                            <span class="info-value current"><?= htmlspecialchars($current_year['year_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Start Year:</span>
                            <span class="info-value"><?= htmlspecialchars($current_year['start_year']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Format:</span>
                            <span class="info-value"><?= ucfirst(htmlspecialchars($current_year['display_format'])) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-value <?= $current_year['status'] ?>"><?= ucfirst($current_year['status']) ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-value inactive">No current year set</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="current-card">
                    <h3><i class="fas fa-clock"></i> Current Academic Term</h3>
                    <?php if ($current_term): ?>
                        <div class="info-item">
                            <span class="info-label">Term:</span>
                            <span class="info-value active"><?= htmlspecialchars($current_term['term_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Academic Year:</span>
                            <span class="info-value"><?= htmlspecialchars($current_term['year_name']) ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Duration:</span>
                            <span class="info-value"><?= $current_term['duration'] ?> days</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Dates:</span>
                            <span class="info-value"><?= $current_term['formatted_opening'] ?> - <?= $current_term['formatted_closing'] ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">Status:</span>
                            <span class="info-value inactive">No active term</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab <?= $active_tab === 'years' ? 'active' : '' ?>" onclick="switchTab('years')">
                    <i class="fas fa-calendar-alt"></i> Academic Years
                </button>
                <button class="tab <?= $active_tab === 'terms' ? 'active' : '' ?>" onclick="switchTab('terms')">
                    <i class="fas fa-clock"></i> Academic Terms
                </button>
            </div>

            <!-- Academic Years Tab Content -->
            <div id="years" class="tab-content <?= $active_tab === 'years' ? 'active' : '' ?>">
                <?php if (!empty($academic_years)): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Year</th>
                                    <th>Start Year</th>
                                    <th>Display Format</th>
                                    <th>Status</th>
                                    <th>Current</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($academic_years as $year): ?>
                                    <tr>
                                        <td>
                                            <?= htmlspecialchars($year['year_name']) ?>
                                        </td>
                                        <td><?= htmlspecialchars($year['start_year']) ?></td>
                                        <td><?= ucfirst(htmlspecialchars($year['display_format'])) ?></td>
                                        <td>
                                            <span class="status-badge status-<?= $year['status'] ?>">
                                                <?= ucfirst($year['status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($year['is_current']): ?>
                                                <span class="current-badge">Current</span>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-calendar-times"></i>
                        <h3>No Academic Years Found</h3>
                        <p>No academic years have been set up by the school administration yet. Please check back later.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Academic Terms Tab Content -->
            <div id="terms" class="tab-content <?= $active_tab === 'terms' ? 'active' : '' ?>">
                <?php if (!empty($terms)): ?>
                    <div class="table-container">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Term</th>
                                    <th>Academic Year</th>
                                    <th>Opening Date</th>
                                    <th>Closing Date</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($terms as $term): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($term['term_name']) ?></td>
                                        <td><?= htmlspecialchars($term['year_name']) ?></td>
                                        <td><?= $term['formatted_opening'] ?></td>
                                        <td><?= $term['formatted_closing'] ?></td>
                                        <td><?= $term['duration'] ?> days</td>
                                        <td>
                                            <span class="status-badge status-<?= $term['status'] ?>">
                                                <?= ucfirst($term['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($term['remarks'] ?: '-') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clock"></i>
                        <h3>No Academic Terms Found</h3>
                        <p>No academic terms have been set up by the school administration yet. Please check back later.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Information Card -->
            <div class="info-card">
                <h4><i class="fas fa-info-circle"></i> About Academic Calendar</h4>
                <p>The Academic Calendar is maintained by school administrators. Teachers can view all academic years and terms to plan their teaching schedules accordingly. Contact the administration office if you notice any discrepancies in the calendar information.</p>
            </div>
        </main>
    </div>

    <script>
        // Mobile sidebar toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleSidebar() {
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            toggleSidebar();
        });

        sidebarOverlay.addEventListener('click', () => {
            toggleSidebar();
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', (e) => {
            if (window.innerWidth < 768 &&
                !sidebar.contains(e.target) &&
                !menuToggle.contains(e.target) &&
                sidebar.classList.contains('active')) {
                toggleSidebar();
            }
        });

        // Handle window resize
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });

        // Tab switching functionality
        function switchTab(tabName) {
            // Update URL to preserve tab state
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked tab
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                if (tab.textContent.includes(tabName === 'years' ? 'Years' : 'Terms')) {
                    tab.classList.add('active');
                }
            });
        }

        // Add click animation to cards
        document.querySelectorAll('.current-card').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'translateY(-5px) scale(1.02)';
                setTimeout(() => {
                    this.style.transform = 'translateY(-3px)';
                }, 150);
            });

            card.addEventListener('mousedown', function() {
                this.style.transform = 'translateY(-1px) scale(0.98)';
            });

            card.addEventListener('mouseup', function() {
                this.style.transform = 'translateY(-3px)';
            });

            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });

        // Handle tab clicks
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const tabName = this.textContent.includes('Years') ? 'years' : 'terms';
                switchTab(tabName);
            });
        });

        // Show welcome notification
        document.addEventListener('DOMContentLoaded', function() {
            if (!localStorage.getItem('teacher_calendar_shown')) {
                setTimeout(() => {
                    showNotification('Welcome to Academic Calendar. This is a view-only interface.', 'info');
                    localStorage.setItem('teacher_calendar_shown', 'true');
                }, 1000);
            }
        });

        // Notification function
        function showNotification(message, type = 'info') {
            // Remove any existing notifications
            document.querySelectorAll('.custom-notification').forEach(n => n.remove());

            const notification = document.createElement('div');
            notification.className = 'custom-notification';
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : type === 'info' ? '#17a2b8' : '#6c757d'};
                color: white;
                border-radius: 8px;
                box-shadow: 0 5px 20px rgba(0,0,0,0.15);
                z-index: 1100;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 10px;
                animation: slideInRight 0.3s ease;
                max-width: 400px;
                border-left: 4px solid ${type === 'success' ? '#218838' : type === 'error' ? '#c82333' : type === 'info' ? '#138496' : '#545b62'};
            `;

            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';
            if (type === 'info') icon = 'fas fa-info-circle';

            notification.innerHTML = `
                <i class="${icon}" style="font-size: 1.2rem;"></i>
                <span style="flex: 1;">${message}</span>
                <button onclick="this.parentElement.remove()" 
                        style="background: none; border: none; color: white; cursor: pointer; padding: 0 5px;">
                    <i class="fas fa-times"></i>
                </button>
            `;

            document.body.appendChild(notification);

            // Add animation styles if not already present
            if (!document.getElementById('notification-animations')) {
                const style = document.createElement('style');
                style.id = 'notification-animations';
                style.textContent = `
                    @keyframes slideInRight {
                        from { transform: translateX(100%); opacity: 0; }
                        to { transform: translateX(0); opacity: 1; }
                    }
                    @keyframes slideOutRight {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }

            // Auto-remove after 5 seconds
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.style.animation = 'slideOutRight 0.3s ease';
                    setTimeout(() => {
                        if (notification.parentNode) {
                            notification.parentNode.removeChild(notification);
                        }
                    }, 300);
                }
            }, 5000);
        }
    </script>
</body>

</html>