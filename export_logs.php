<?php
require_once 'config.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch admin info for header
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

// Handle CSV Export
if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    // Get filter parameters
    $search_term = trim($_GET['search'] ?? '');
    $start_date = trim($_GET['start_date'] ?? '');
    $end_date = trim($_GET['end_date'] ?? '');
    
    // Build WHERE clause
    $where_clause = "1=1";
    $params = [];
    
    if (!empty($search_term)) {
        $where_clause .= " AND (al.action LIKE ? OR al.description LIKE ? OR u.fullname LIKE ?)";
        $params[] = "%$search_term%";
        $params[] = "%$search_term%";
        $params[] = "%$search_term%";
    }
    
    if (!empty($start_date)) {
        $where_clause .= " AND DATE(al.created_at) >= ?";
        $params[] = $start_date;
    }
    
    if (!empty($end_date)) {
        $where_clause .= " AND DATE(al.created_at) <= ?";
        $params[] = $end_date;
    }
    
    // Fetch logs for export
    try {
        $stmt = $pdo->prepare("
            SELECT 
                al.id,
                u.fullname AS actor,
                al.action,
                al.description,
                al.created_at,
                al.ip_address,
                al.user_agent
            FROM activity_logs al
            JOIN users u ON al.user_id = u.id
            WHERE $where_clause
            ORDER BY al.created_at DESC
        ");
        $stmt->execute($params);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=activity_logs_' . date('Y-m-d_H-i-s') . '.csv');
        
        // Create output stream
        $output = fopen('php://output', 'w');
        
        // Add BOM for UTF-8
        fwrite($output, "\xEF\xBB\xBF");
        
        // CSV headers
        fputcsv($output, [
            'ID',
            'Actor',
            'Action',
            'Description', 
            'Date & Time',
            'IP Address',
            'User Agent'
        ]);
        
        // Add data rows
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['id'],
                $log['actor'],
                $log['action'],
                $log['description'],
                date('Y-m-d H:i:s', strtotime($log['created_at'])),
                $log['ip_address'] ?? 'N/A',
                substr($log['user_agent'] ?? 'N/A', 0, 100) // Truncate long user agents
            ]);
        }
        
        fclose($output);
        exit;
        
    } catch (PDOException $e) {
        $error = "Failed to export logs: " . $e->getMessage();
    }
}

// For the UI page, fetch some statistics
try {
    // Get total logs count
    $stmt = $pdo->query("SELECT COUNT(*) FROM activity_logs");
    $total_logs = $stmt->fetchColumn();
    
    // Get today's logs
    $stmt = $pdo->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(created_at) = CURDATE()");
    $today_logs = $stmt->fetchColumn();
    
    // Get unique users with activity
    $stmt = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs");
    $unique_users = $stmt->fetchColumn();
    
    // Get most active user
    $stmt = $pdo->query("
        SELECT u.fullname, COUNT(al.id) as activity_count 
        FROM activity_logs al 
        JOIN users u ON al.user_id = u.id 
        GROUP BY al.user_id 
        ORDER BY activity_count DESC 
        LIMIT 1
    ");
    $most_active_user = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $total_logs = 0;
    $today_logs = 0;
    $unique_users = 0;
    $most_active_user = ['fullname' => 'N/A', 'activity_count' => 0];
}

// Get recent export history (from a hypothetical exports table or from file system)
$export_history = [];
try {
    // Check if exports table exists, if not we'll use a simulated history
    $stmt = $pdo->query("SHOW TABLES LIKE 'exports'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->query("
            SELECT * FROM exports 
            WHERE user_id = " . $_SESSION['user_id'] . " 
            ORDER BY created_at DESC 
            LIMIT 10
        ");
        $export_history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Simulate some export history
    $export_history = [
        ['filename' => 'activity_logs_' . date('Y-m-d', strtotime('-1 day')) . '.csv', 'created_at' => date('Y-m-d H:i:s', strtotime('-1 day'))],
        ['filename' => 'activity_logs_' . date('Y-m-d', strtotime('-2 days')) . '.csv', 'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ['filename' => 'activity_logs_' . date('Y-m-d', strtotime('-3 days')) . '.csv', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))],
    ];
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Export Activity Logs - Admin Dashboard</title>
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
            display: flex;
            flex-direction: column;
        }

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-header p {
            color: #6c757d;
            font-size: 1rem;
            max-width: 800px;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 1.2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-card i {
            font-size: 1.8rem;
            margin-bottom: 0.8rem;
            color: var(--primary);
        }

        .stat-card h3 {
            font-size: 1.8rem;
            margin: 0.2rem 0;
            color: var(--secondary);
        }

        .stat-card p {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }

        .stat-card .trend {
            font-size: 0.8rem;
            margin-top: 0.5rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .trend.up {
            color: var(--success);
        }

        .trend.down {
            color: var(--secondary);
        }

        /* Export Section */
        .export-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
        }

        .export-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-dark);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 0.6rem 0.8rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26, 42, 108, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        .btn {
            padding: 0.6rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: background 0.2s, transform 0.1s;
            font-weight: 500;
        }

        .btn:hover {
            background: #0f1d4d;
            transform: translateY(-1px);
        }

        .btn:active {
            transform: translateY(0);
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

        .btn-info {
            background: var(--info);
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-group {
            display: flex;
            gap: 0.8rem;
            flex-wrap: wrap;
        }

        /* Export History */
        .history-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .history-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .history-list {
            flex: 1;
            overflow-y: auto;
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .history-item {
            padding: 0.8rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }

        .history-item:hover {
            background: #f8f9fa;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .file-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .file-info i {
            color: var(--primary);
            font-size: 1.2rem;
        }

        .file-name {
            font-weight: 500;
            color: var(--text-dark);
        }

        .file-date {
            color: #6c757d;
            font-size: 0.85rem;
        }

        .file-actions {
            display: flex;
            gap: 0.5rem;
        }

        .btn-sm {
            padding: 0.3rem 0.6rem;
            font-size: 0.85rem;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 10px;
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

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
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

            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .btn-group {
                flex-direction: column;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .main-content {
                padding: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }

            .header {
                height: auto;
                padding: 0.8rem 1rem;
                flex-direction: column;
                align-items: flex-start;
                gap: 0.8rem;
            }

            .admin-info h1 {
                font-size: 1.3rem;
            }

            .role-tag {
                align-self: flex-start;
            }

            .file-info {
                flex-direction: column;
                align-items: flex-start;
                gap: 5px;
            }

            .history-item {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .file-actions {
                width: 100%;
                justify-content: flex-end;
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
            <!-- Dashboard -->
            <li class="nav-item">
                <a href="admin_dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt"></i>
                    <span>Dashboard</span>
                </a>
            </li>

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
                    <li class="nested">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>Examinations</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="examination.php" class="nav-link">Set Exams</a></li>
                            <li><a href="view_exam.php" class="nav-link">View Exams</a></li>
                            <li><a href="archive_exams.php" class="nav-link">Archive Exams</a></li>
                            <li><a href="view_archived_exams.php" class="nav-link">View Archived Exams</a></li>
                        </ul>
                    </li>
                    <li><a href="reports.php" class="nav-link">Reports</a></li>
                    <li><a href="academic_calendar.php" class="nav-link">Academic Calendar</a></li>
                </ul>
            </li>

            <!-- More -->
            <li class="nav-item dropdown active">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-cog"></i>
                    <span>More</span>
                </a>
                <ul class="dropdown-menu">
                    <li><a href="export_logs.php" class="nav-link"><i class="fas fa-file-export"></i> Export Logs</a></li>
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
                <h1>Export Activity Logs</h1>
                <p><?= $email ?></p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h2><i class="fas fa-file-export"></i> Export Activity Logs</h2>
                <p>Export system activity logs in CSV format. You can filter the logs before exporting to get specific data.</p>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <i class="fas fa-history"></i>
                    <h3><?= number_format($total_logs) ?></h3>
                    <p>Total Logs</p>
                    <div class="trend up">
                        <i class="fas fa-arrow-up"></i>
                        <span><?= number_format($today_logs) ?> today</span>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-users"></i>
                    <h3><?= number_format($unique_users) ?></h3>
                    <p>Active Users</p>
                    <div class="trend info">
                        <i class="fas fa-user"></i>
                        <span>Tracked activities</span>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-check"></i>
                    <h3><?= htmlspecialchars($most_active_user['fullname']) ?></h3>
                    <p>Most Active User</p>
                    <div class="trend warning">
                        <i class="fas fa-chart-line"></i>
                        <span><?= number_format($most_active_user['activity_count']) ?> activities</span>
                    </div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-file-csv"></i>
                    <h3><?= count($export_history) ?></h3>
                    <p>Recent Exports</p>
                    <div class="trend info">
                        <i class="fas fa-download"></i>
                        <span>CSV downloads</span>
                    </div>
                </div>
            </div>

            <!-- Success/Error Messages -->
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <strong>Error!</strong> <?= htmlspecialchars($error) ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['success']) && $_GET['success'] == '1'): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <strong>Success!</strong> Logs have been exported successfully. Your download should start automatically.
                    </div>
                </div>
            <?php endif; ?>

            <!-- Export Form Section -->
            <div class="export-section">
                <h3><i class="fas fa-filter"></i> Filter & Export Logs</h3>
                
                <form method="GET" action="" id="exportForm">
                    <input type="hidden" name="format" value="csv">
                    
                    <div class="form-group">
                        <label for="search"><i class="fas fa-search"></i> Search Term</label>
                        <input type="text" 
                               id="search" 
                               name="search" 
                               class="form-control" 
                               placeholder="Search by action, description, or actor..."
                               value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="start_date"><i class="fas fa-calendar-alt"></i> Start Date</label>
                            <input type="date" 
                                   id="start_date" 
                                   name="start_date" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($_GET['start_date'] ?? '') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="end_date"><i class="fas fa-calendar-alt"></i> End Date</label>
                            <input type="date" 
                                   id="end_date" 
                                   name="end_date" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($_GET['end_date'] ?? '') ?>">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-download"></i> Export Options</label>
                        <div class="btn-group">
                            <button type="submit" class="btn btn-success">
                                <i class="fas fa-file-csv"></i> Export to CSV
                            </button>
                            <button type="button" class="btn btn-info" onclick="previewExport()">
                                <i class="fas fa-eye"></i> Preview Export
                            </button>
                            <button type="button" class="btn btn-warning" onclick="resetForm()">
                                <i class="fas fa-redo"></i> Reset Filters
                            </button>
                            <a href="admin_dashboard.php" class="btn">
                                <i class="fas fa-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </form>
                
                <div class="form-group">
                    <label><i class="fas fa-info-circle"></i> Export Information</label>
                    <div style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border-left: 4px solid var(--info);">
                        <p style="margin-bottom: 0.5rem; color: #495057;">
                            <strong>CSV Export includes:</strong>
                        </p>
                        <ul style="color: #6c757d; margin-bottom: 0; padding-left: 1.5rem;">
                            <li>Log ID, Actor Name, Action Type</li>
                            <li>Description, Date & Time (YYYY-MM-DD HH:MM:SS)</li>
                            <li>IP Address and User Agent (truncated)</li>
                            <li>All filtered data based on your criteria</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Export History -->
            <div class="history-section">
                <h3><i class="fas fa-history"></i> Recent Export History</h3>
                
                <?php if (!empty($export_history)): ?>
                    <ul class="history-list">
                        <?php foreach ($export_history as $export): ?>
                            <li class="history-item">
                                <div class="file-info">
                                    <i class="fas fa-file-csv"></i>
                                    <div>
                                        <div class="file-name"><?= htmlspecialchars($export['filename'] ?? 'export.csv') ?></div>
                                        <div class="file-date">
                                            <i class="far fa-clock"></i>
                                            <?= date('M j, Y g:i A', strtotime($export['created_at'] ?? 'now')) ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="file-actions">
                                    <button class="btn btn-sm" onclick="downloadFile('<?= htmlspecialchars($export['filename'] ?? 'export.csv') ?>')">
                                        <i class="fas fa-download"></i> Download
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="shareExport('<?= htmlspecialchars($export['filename'] ?? 'export.csv') ?>')">
                                        <i class="fas fa-share-alt"></i> Share
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-file-export"></i>
                        <h4>No Export History</h4>
                        <p>Your exported files will appear here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Toggle dropdowns
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

        // Form validation
        function validateExportForm() {
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            if (startDate && endDate && new Date(startDate) > new Date(endDate)) {
                alert('Start date cannot be after end date.');
                return false;
            }
            
            return true;
        }

        // Preview export
        function previewExport() {
            const form = document.getElementById('exportForm');
            const search = document.getElementById('search').value;
            const startDate = document.getElementById('start_date').value;
            const endDate = document.getElementById('end_date').value;
            
            let message = "Export Preview:\n\n";
            message += "Filters applied:\n";
            if (search) message += `• Search: "${search}"\n`;
            if (startDate) message += `• From: ${startDate}\n`;
            if (endDate) message += `• To: ${endDate}\n`;
            
            if (!search && !startDate && !endDate) {
                message += "• No filters (all logs will be exported)\n";
            }
            
            message += "\nEstimated data: Approximately <?= number_format($total_logs) ?> logs\n";
            message += "File format: CSV with UTF-8 encoding\n";
            message += "Columns: ID, Actor, Action, Description, Date, IP, User Agent\n\n";
            message += "Click OK to proceed with export.";
            
            if (confirm(message)) {
                // Add a parameter to show success message after download
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'success';
                input.value = '1';
                form.appendChild(input);
                
                form.submit();
            }
        }

        // Reset form
        function resetForm() {
            if (confirm('Are you sure you want to reset all filters?')) {
                document.getElementById('search').value = '';
                document.getElementById('start_date').value = '';
                document.getElementById('end_date').value = '';
            }
        }

        // Download file (simulated)
        function downloadFile(filename) {
            alert('Downloading: ' + filename + '\n\nNote: This is a simulation. In a real application, this would download the file.');
        }

        // Share export
        function shareExport(filename) {
            const shareText = `Check out this activity log export: ${filename}\nGenerated on: <?= date('Y-m-d') ?>`;
            
            if (navigator.share) {
                navigator.share({
                    title: 'Activity Logs Export',
                    text: shareText,
                    url: window.location.href
                });
            } else {
                alert('Share: ' + shareText + '\n\nCopy this text to share.');
            }
        }

        // Auto-set end date to today if not set
        window.addEventListener('load', function() {
            const endDateInput = document.getElementById('end_date');
            if (!endDateInput.value) {
                const today = new Date().toISOString().split('T')[0];
                endDateInput.value = today;
            }
            
            // Set max date to today for both date inputs
            const today = new Date().toISOString().split('T')[0];
            document.getElementById('start_date').max = today;
            document.getElementById('end_date').max = today;
        });

        // Prevent form submission if dates are invalid
        document.getElementById('exportForm').addEventListener('submit', function(e) {
            if (!validateExportForm()) {
                e.preventDefault();
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

        // Auto-focus search input
        document.getElementById('search').focus();
    </script>
</body>

</html>