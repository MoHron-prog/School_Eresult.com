<?php
require_once 'config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}
// Handle POST actions via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    // BULK ACTIONS
    if (isset($_POST['bulk_action'])) {
        $action = $_POST['bulk_action'];
        $ids = $_POST['ids'] ?? [];
        if (empty($ids) || !is_array($ids)) {
            echo json_encode(['success' => false, 'message' => 'Invalid request']);
            exit;
        }
        try {
            $placeholders = str_repeat('?,', count($ids) - 1) . '?';
            $int_ids = array_map('intval', $ids);
            if ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id IN ($placeholders)");
            } elseif ($action === 'activate') {
                $stmt = $pdo->prepare("UPDATE subjects SET status = 'active' WHERE id IN ($placeholders)");
            } elseif ($action === 'deactivate') {
                $stmt = $pdo->prepare("UPDATE subjects SET status = 'inactive' WHERE id IN ($placeholders)");
            } else {
                throw new Exception('Invalid action.');
            }
            $stmt->execute($int_ids);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    // SINGLE ACTIONS
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid ID']);
            exit;
        }
        try {
            if ($action === 'update') {
                $name = trim($_POST['name'] ?? '');
                $code = trim($_POST['code'] ?? '');
                $status = trim($_POST['status'] ?? '');
                $level_id = (int)($_POST['level_id'] ?? 0);
                if (empty($name) || empty($code)) throw new Exception('Subject name and code are required.');
                if (!in_array($status, ['active', 'inactive'])) throw new Exception('Invalid status.');
                if (!$level_id) throw new Exception('Level is required.');
                // Check for duplicate code in same level
                $stmt = $pdo->prepare("SELECT id FROM subjects WHERE code = ? AND level_id = ? AND id != ?");
                $stmt->execute([$code, $level_id, $id]);
                if ($stmt->fetch()) throw new Exception('Subject code already exists in this level.');
                $stmt = $pdo->prepare("UPDATE subjects SET name = ?, code = ?, status = ?, level_id = ? WHERE id = ?");
                $stmt->execute([$name, $code, $status, $level_id, $id]);
                echo json_encode([
                    'success' => true,
                    'message' => 'Subject updated successfully!',
                    'new_name' => htmlspecialchars($name),
                    'new_code' => htmlspecialchars($code),
                    'new_status' => $status
                ]);
            } elseif ($action === 'toggle_status') {
                $stmt = $pdo->prepare("UPDATE subjects SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
                $stmt->execute([$id]);
                $new = $pdo->query("SELECT status FROM subjects WHERE id = $id")->fetchColumn();
                echo json_encode(['success' => true, 'new_status' => $new, 'message' => 'Status updated successfully!']);
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM subjects WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Subject deleted successfully!']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}
// Fetch levels
$levels = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
// Check if subjects table exists
$table_exists = (bool)$pdo->query("SHOW TABLES LIKE 'subjects'")->rowCount();
// Get filters
$level_filter = $_GET['level'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search_filter = trim($_GET['search'] ?? '');
$subjects = [];
if ($table_exists) {
    $where = "1=1";
    $params = [];
    if ($level_filter !== '') {
        $where .= " AND s.level_id = ?";
        $params[] = (int)$level_filter;
    }
    if ($category_filter !== '' && in_array($category_filter, ['compulsory', 'elective', 'principal', 'subsidiary'])) {
        $where .= " AND s.category = ?";
        $params[] = $category_filter;
    }
    if ($search_filter !== '') {
        $where .= " AND (s.code LIKE ? OR s.name LIKE ?)";
        $params[] = "%$search_filter%";
        $params[] = "%$search_filter%";
    }
    $stmt = $pdo->prepare("
        SELECT s.id, s.code, s.name, s.category, s.status, s.level_id, l.name AS level_name
        FROM subjects s
        JOIN levels l ON s.level_id = l.id
        WHERE $where
        ORDER BY s.level_id, s.category, s.name
    ");
    $stmt->execute($params);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Subjects</title>
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
            --border-color: #dee2e6;
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

        /* SIDEBAR */
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
        }

        /* IMPROVED: Search & Bulk Actions Row */
        .controls-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
            padding: 1rem 1.2rem;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.2rem;
            flex-wrap: wrap;
            gap: 1rem;
            border: 1px solid var(--border-color);
        }

        .search-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            flex: 1;
            min-width: 300px;
        }

        .search-section label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .search-container {
            display: flex;
            flex: 1;
            max-width: 400px;
        }

        .search-input {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-right: none;
            border-radius: 6px 0 0 6px;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s;
        }

        .search-input:focus {
            border-color: var(--primary);
        }

        .search-btn {
            padding: 0.75rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 0 6px 6px 0;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            white-space: nowrap;
            transition: background-color 0.2s;
        }

        .search-btn:hover {
            background: #15235a;
        }

        .search-btn i {
            font-size: 0.9rem;
        }

        .bulk-section {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .bulk-section label {
            font-weight: 600;
            color: var(--primary);
            font-size: 0.9rem;
            white-space: nowrap;
        }

        .bulk-controls {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .bulk-dropdown {
            position: relative;
        }

        .bulk-dropdown select {
            padding: 0.75rem 2rem 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            background: white;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            outline: none;
            min-width: 150px;
            transition: border-color 0.2s;
        }

        .bulk-dropdown select:focus {
            border-color: var(--primary);
        }

        .bulk-dropdown::after {
            content: "▼";
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            pointer-events: none;
            color: #6c757d;
            font-size: 0.7rem;
        }

        .apply-btn {
            padding: 0.75rem 1.5rem;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 600;
            white-space: nowrap;
            transition: background-color 0.2s;
        }

        .apply-btn:hover {
            background: #218838;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.2rem;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            border: 1px solid var(--border-color);
        }

        .filter-row {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            min-width: 140px;
        }

        .form-group label {
            margin-bottom: 0.35rem;
            font-size: 0.9rem;
            color: var(--primary);
            font-weight: 600;
        }

        .form-control {
            padding: 0.5rem 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
        }

        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            overflow: hidden;
        }

        th,
        td {
            padding: 0.75rem 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: var(--primary);
            color: white;
            font-weight: 600;
        }

        .status-active {
            color: #28a745;
            font-weight: bold;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: bold;
        }

        /* Icon-based action buttons */
        .action-buttons {
            display: flex;
            gap: 0.4rem;
            align-items: center;
        }

        .action-btn {
            width: 36px;
            height: 36px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            font-size: 0.95rem;
            color: white;
            position: relative;
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .btn-edit {
            background: #17a2b8;
        }

        .btn-edit:hover {
            background: #138496;
        }

        .btn-delete {
            background: #dc3545;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .btn-toggle {
            background: #28a745;
        }

        .btn-toggle:hover {
            background: #218838;
        }

        .btn-toggle.inactive {
            background: #ffc107;
            color: #212529;
        }

        .btn-toggle.inactive:hover {
            background: #e0a800;
        }

        /* Tooltip */
        .action-btn .tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.4rem 0.75rem;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s;
            z-index: 10;
            margin-bottom: 5px;
            pointer-events: none;
        }

        .action-btn .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 5px;
            border-style: solid;
            border-color: rgba(0, 0, 0, 0.8) transparent transparent transparent;
        }

        .action-btn:hover .tooltip {
            opacity: 1;
            visibility: visible;
        }

        /* Notification Styles */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 6px;
            color: white;
            font-weight: 600;
            z-index: 9999;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            opacity: 0;
            transform: translateX(100%);
            transition: opacity 0.3s, transform 0.3s;
        }

        .notification.show {
            opacity: 1;
            transform: translateX(0);
        }

        .notification.success {
            background: #28a745;
            border-left: 5px solid #1e7e34;
        }

        .notification.error {
            background: #dc3545;
            border-left: 5px solid #c82333;
        }

        .notification.warning {
            background: #ffc107;
            color: #212529;
            border-left: 5px solid #e0a800;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background: white;
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            transform: translateY(-20px);
            transition: transform 0.3s;
        }

        .modal-overlay.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            background: var(--primary);
            color: white;
            padding: 1.2rem 1.5rem;
            border-radius: 10px 10px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            line-height: 1;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-body .form-group {
            margin-bottom: 1.2rem;
        }

        .modal-body .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--primary);
        }

        .modal-body .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .modal-body .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26, 42, 108, 0.1);
        }

        .radio-group {
            display: flex;
            gap: 1.5rem;
            margin-top: 0.5rem;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .radio-option input[type="radio"] {
            margin: 0;
        }

        .modal-footer {
            padding: 1.2rem 1.5rem;
            border-top: 1px solid var(--border-color);
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            min-width: 100px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #15235a;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* No data message */
        .no-data {
            text-align: center;
            padding: 3rem;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            color: #6c757d;
            font-style: italic;
            font-size: 1rem;
        }

        @media (max-width: 1024px) {
            .controls-row {
                flex-direction: column;
                align-items: stretch;
            }

            .search-section,
            .bulk-section {
                width: 100%;
            }

            .search-container {
                max-width: none;
            }

            .filter-row {
                flex-direction: column;
                align-items: stretch;
            }

            .form-group {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <div class="sidebar-header" onclick="window.location='admin_dashboard.php'">
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
            <li class="nav-item dropdown active">
                <a href="#" class="nav-link dropdown-toggle">
                    <i class="fas fa-book"></i>
                    <span>Subjects Management</span>
                </a>
                <ul class="dropdown-menu" style="max-height: 1000px; padding: 0.45rem 0 0.45rem 1.2rem;">
                    <li><a href="add_subject.php" class="nav-link">Add Subject</a></li>
                    <li><a href="subjects.php" class="nav-link">View Subjects</a></li>
                    <li><a href="manage_subjects.php" class="nav-link" style="background: rgba(255, 255, 255, 0.1);">Manage Subjects</a></li>
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
                <h1>Manage Subjects</h1>
            </div>
            <div class="role-tag">Admin</div>
        </header>
        <main class="main-content">
            <!-- IMPROVED Controls Row -->
            <div class="controls-row">
                <div class="search-section">
                    <label for="search_input">Search:</label>
                    <div class="search-container">
                        <input type="text" id="search_input" class="search-input"
                            placeholder="Enter subject code or name..."
                            value="<?= htmlspecialchars($search_filter) ?>"
                            onkeypress="if(event.keyCode==13) applySearch()">
                        <button class="search-btn" onclick="applySearch()">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </div>
                </div>
                <div class="bulk-section">
                    <label for="bulk_action">Bulk Actions:</label>
                    <div class="bulk-controls">
                        <div class="bulk-dropdown">
                            <select id="bulk_action" class="search-input">
                                <option value="">Select Action</option>
                                <option value="activate">Activate</option>
                                <option value="deactivate">Deactivate</option>
                                <option value="delete">Delete</option>
                            </select>
                        </div>
                        <button class="apply-btn" onclick="applyBulkAction()">
                            <i class="fas fa-check"></i> Apply
                        </button>
                    </div>
                </div>
            </div>
            <!-- Filter Section -->
            <div class="filter-section">
                <form id="filterForm" method="GET">
                    <div class="filter-row">
                        <div class="form-group">
                            <label for="level">Level</label>
                            <select name="level" id="level" class="form-control" onchange="updateCategoryOptions()">
                                <option value="">All Levels</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" <?= $level_filter == $level['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="category">Category</label>
                            <select name="category" id="category" class="form-control">
                                <option value="">All Categories</option>
                                <?php
                                $categories = [];
                                if ($level_filter == 1) {
                                    $categories = ['compulsory' => 'Compulsory', 'elective' => 'Elective'];
                                } elseif ($level_filter == 2) {
                                    $categories = ['principal' => 'Principal', 'subsidiary' => 'Subsidiary'];
                                }
                                foreach ($categories as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= $category_filter == $value ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php if (!$table_exists): ?>
                <div class="no-data">
                    The <code>subjects</code> table does not exist.<br>
                    Please create it using the SQL provided.
                </div>
            <?php elseif (empty($subjects)): ?>
                <div class="no-data">No subjects found. Try adjusting your filters.</div>
            <?php else: ?>
                <form id="subjects_form">
                    <table>
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="select_all" onchange="toggleAll(this)"></th>
                                <th>S/N</th>
                                <th>Code</th>
                                <th>Subject Name</th>
                                <th>Level</th>
                                <th>Category</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $i => $subj): ?>
                                <tr id="subject-<?= $subj['id'] ?>">
                                    <td><input type="checkbox" name="ids[]" value="<?= $subj['id'] ?>" class="row-checkbox"></td>
                                    <td><?= $i + 1 ?></td>
                                    <td class="subject-code"><?= htmlspecialchars($subj['code']) ?></td>
                                    <td class="subject-name"><?= htmlspecialchars($subj['name']) ?></td>
                                    <td class="subject-level"><?= htmlspecialchars($subj['level_name']) ?></td>
                                    <td class="subject-category"><?= ucfirst(htmlspecialchars($subj['category'])) ?></td>
                                    <td>
                                        <span class="<?= $subj['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                            <?= ucfirst($subj['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="action-btn btn-edit" onclick="openEditModal(<?= $subj['id'] ?>, '<?= htmlspecialchars($subj['code']) ?>', '<?= htmlspecialchars($subj['name']) ?>', '<?= $subj['status'] ?>', <?= $subj['level_id'] ?>, '<?= $subj['category'] ?>')">
                                                <i class="fas fa-edit"></i>
                                                <span class="tooltip">Edit</span>
                                            </button>
                                            <button type="button" class="action-btn <?= $subj['status'] === 'active' ? 'btn-toggle' : 'btn-toggle inactive' ?>"
                                                onclick="toggleStatus(<?= $subj['id'] ?>)">
                                                <?php if ($subj['status'] === 'active'): ?>
                                                    <i class="fas fa-toggle-on"></i>
                                                    <span class="tooltip">Deactivate</span>
                                                <?php else: ?>
                                                    <i class="fas fa-toggle-off"></i>
                                                    <span class="tooltip">Activate</span>
                                                <?php endif; ?>
                                            </button>
                                            <button type="button" class="action-btn btn-delete" onclick="deleteRecord(<?= $subj['id'] ?>)">
                                                <i class="fas fa-trash"></i>
                                                <span class="tooltip">Delete</span>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </form>
            <?php endif; ?>
        </main>
    </div>
    <!-- Edit Modal -->
    <div class="modal-overlay" id="editModal">
        <div class="modal">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> Edit Subject</h3>
                <button class="modal-close" onclick="closeEditModal()">&times;</button>
            </div>
            <form id="editForm">
                <div class="modal-body">
                    <input type="hidden" id="editSubjectId" name="id">
                    <input type="hidden" name="action" value="update">
                    <div class="form-group">
                        <label for="editSubjectCode"><i class="fas fa-code"></i> Subject Code *</label>
                        <input type="text" id="editSubjectCode" name="code" class="form-control" required maxlength="20">
                    </div>
                    <div class="form-group">
                        <label for="editSubjectName"><i class="fas fa-tag"></i> Subject Name *</label>
                        <input type="text" id="editSubjectName" name="name" class="form-control" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label for="editLevelId"><i class="fas fa-layer-group"></i> Level *</label>
                        <select id="editLevelId" name="level_id" class="form-control" required onchange="updateCategoryOptionsModal()">
                            <option value="">Select Level</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['id'] ?>"><?= htmlspecialchars($level['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="editCategory"><i class="fas fa-tags"></i> Category *</label>
                        <select id="editCategory" name="category" class="form-control" required>
                            <option value="">Select Category</option>
                            <!-- Options will be populated by JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-power-off"></i> Status</label>
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="status" value="active" id="statusActive">
                                <i class="fas fa-check-circle status-active"></i>
                                <span class="status-active">Active</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="status" value="inactive" id="statusInactive">
                                <i class="fas fa-times-circle status-inactive"></i>
                                <span class="status-inactive">Inactive</span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Notification Container -->
    <div id="notificationContainer"></div>
    <script>
        let currentEditId = null;
        
        // Initialize dropdown functionality
        document.addEventListener('DOMContentLoaded', function() {
            // Toggle top-level dropdowns
            document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
                link.addEventListener('click', function(e) {
                    // Only prevent default if it's a dropdown toggle without a direct href
                    if (this.getAttribute('href') === '#') {
                        e.preventDefault();
                    }
                    const parent = this.closest('.dropdown');
                    parent.classList.toggle('active');
                });
            });

            // Toggle nested dropdowns
            document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href') === '#') {
                        e.preventDefault();
                    }
                    const parent = this.closest('.nested');
                    parent.classList.toggle('active');
                });
            });

            updateCategoryOptions();
        });

        // Notification System
        function showNotification(message, type = 'success') {
            const container = document.getElementById('notificationContainer');
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            // Add icon based on type
            let icon = 'check-circle';
            if (type === 'error') icon = 'exclamation-circle';
            if (type === 'warning') icon = 'exclamation-triangle';
            notification.innerHTML = `
                <i class="fas fa-${icon}" style="margin-right: 8px;"></i>
                ${message}
            `;
            container.appendChild(notification);
            setTimeout(() => {
                notification.classList.add('show');
            }, 10);
            setTimeout(() => {
                notification.classList.remove('show');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        function updateCategoryOptions() {
            const levelSelect = document.getElementById('level');
            const categorySelect = document.getElementById('category');
            const levelId = levelSelect.value;
            // Preserve current selection
            const currentValue = categorySelect.value;
            categorySelect.innerHTML = '<option value="">All Categories</option>';
            if (levelId === '1') {
                categorySelect.innerHTML += '<option value="compulsory">Compulsory</option><option value="elective">Elective</option>';
            } else if (levelId === '2') {
                categorySelect.innerHTML += '<option value="principal">Principal</option><option value="subsidiary">Subsidiary</option>';
            }
            // Restore selection if it's still valid
            if (currentValue) {
                categorySelect.value = currentValue;
            }
        }

        function updateCategoryOptionsModal() {
            const levelSelect = document.getElementById('editLevelId');
            const categorySelect = document.getElementById('editCategory');
            const levelId = levelSelect.value;
            categorySelect.innerHTML = '<option value="">Select Category</option>';
            if (levelId === '1') {
                categorySelect.innerHTML += '<option value="compulsory">Compulsory</option><option value="elective">Elective</option>';
            } else if (levelId === '2') {
                categorySelect.innerHTML += '<option value="principal">Principal</option><option value="subsidiary">Subsidiary</option>';
            }
        }

        function openEditModal(id, code, name, status, levelId, category) {
            currentEditId = id;
            document.getElementById('editSubjectId').value = id;
            document.getElementById('editSubjectCode').value = code;
            document.getElementById('editSubjectName').value = name;
            document.getElementById('editLevelId').value = levelId;
            // Update category options based on level
            updateCategoryOptionsModal();
            document.getElementById('editCategory').value = category;
            // Set radio button based on status
            if (status === 'active') {
                document.getElementById('statusActive').checked = true;
            } else {
                document.getElementById('statusInactive').checked = true;
            }
            document.getElementById('editModal').classList.add('active');
            document.getElementById('editSubjectCode').focus();
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
            document.getElementById('editForm').reset();
            currentEditId = null;
        }
        // Handle form submission
        document.getElementById('editForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('action', 'update');
            fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        // Update the table row
                        const row = document.getElementById(`subject-${currentEditId}`);
                        if (row) {
                            row.querySelector('.subject-code').textContent = data.new_code;
                            row.querySelector('.subject-name').textContent = data.new_name;
                            const statusSpan = row.querySelector('.status-active, .status-inactive');
                            statusSpan.className = data.new_status === 'active' ? 'status-active' : 'status-inactive';
                            statusSpan.textContent = data.new_status.charAt(0).toUpperCase() + data.new_status.slice(1);
                            const toggleBtn = row.querySelector('.btn-toggle');
                            const toggleIcon = toggleBtn.querySelector('i');
                            const tooltip = toggleBtn.querySelector('.tooltip');
                            if (data.new_status === 'active') {
                                toggleBtn.className = 'action-btn btn-toggle';
                                toggleIcon.className = 'fas fa-toggle-on';
                                tooltip.textContent = 'Deactivate';
                            } else {
                                toggleBtn.className = 'action-btn btn-toggle inactive';
                                toggleIcon.className = 'fas fa-toggle-off';
                                tooltip.textContent = 'Activate';
                            }
                        }
                        closeEditModal();
                        showNotification(data.message || 'Subject updated successfully!', 'success');
                    } else {
                        showNotification(data.message || 'Failed to update subject!', 'error');
                    }
                })
                .catch(error => {
                    showNotification('An error occurred: ' + error.message, 'error');
                });
        });

        function applySearch() {
            const val = document.getElementById('search_input').value.trim();
            let url = 'manage_subjects.php';
            if (val) url += '?search=' + encodeURIComponent(val);
            window.location = url;
        }

        function applyBulkAction() {
            const action = document.getElementById('bulk_action').value;
            if (!action) {
                showNotification('Please select a bulk action.', 'warning');
                return;
            }
            const checkboxes = document.querySelectorAll('input[name="ids[]"]:checked');
            const ids = Array.from(checkboxes).map(cb => cb.value);
            if (ids.length === 0) {
                showNotification('Please select at least one subject.', 'warning');
                return;
            }
            let confirmMessage = '';
            let icon = '';
            if (action === 'delete') {
                confirmMessage = `Are you sure you want to delete ${ids.length} selected subject(s)?`;
                icon = '🗑️';
            } else if (action === 'activate') {
                confirmMessage = `Are you sure you want to activate ${ids.length} selected subject(s)?`;
                icon = '✅';
            } else if (action === 'deactivate') {
                confirmMessage = `Are you sure you want to deactivate ${ids.length} selected subject(s)?`;
                icon = '⛔';
            }
            if (!confirm(icon + ' ' + confirmMessage)) return;
            const formData = new FormData();
            formData.append('bulk_action', action);
            ids.forEach(id => formData.append('ids[]', id));
            fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        let message = '';
                        if (action === 'delete') message = `${ids.length} subject(s) deleted successfully!`;
                        else if (action === 'activate') message = `${ids.length} subject(s) activated successfully!`;
                        else if (action === 'deactivate') message = `${ids.length} subject(s) deactivated successfully!`;
                        showNotification(message, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('An error occurred: ' + error.message, 'error');
                });
        }

        function toggleAll(checkbox) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checkbox.checked);
        }

        function toggleStatus(id) {
            const row = document.getElementById(`subject-${id}`);
            const subjectName = row.querySelector('.subject-name').textContent;
            const statusSpan = row.querySelector('.status-active, .status-inactive');
            const currentStatus = statusSpan.classList.contains('status-active') ? 'active' : 'inactive';
            const action = currentStatus === 'active' ? 'deactivate' : 'activate';
            const icon = currentStatus === 'active' ? '⛔' : '✅';
            if (!confirm(`${icon} Are you sure you want to ${action} subject "${subjectName}"?`)) return;
            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);
            fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Status updated successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('An error occurred: ' + error.message, 'error');
                });
        }

        function deleteRecord(id) {
            const row = document.getElementById(`subject-${id}`);
            const subjectCode = row.querySelector('.subject-code').textContent;
            const subjectName = row.querySelector('.subject-name').textContent;
            if (!confirm(`🗑️ Are you sure you want to delete subject "${subjectName}" (${subjectCode})?`)) return;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            fetch('manage_subjects.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message || 'Subject deleted successfully!', 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        showNotification('Error: ' + data.message, 'error');
                    }
                })
                .catch(error => {
                    showNotification('An error occurred: ' + error.message, 'error');
                });
        }
        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeEditModal();
            }
        });
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
    </script>
</body>

</html>