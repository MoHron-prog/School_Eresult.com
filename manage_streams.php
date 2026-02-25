<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Handle POST actions
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
                $stmt = $pdo->prepare("DELETE FROM streams WHERE id IN ($placeholders)");
            } elseif ($action === 'activate') {
                $stmt = $pdo->prepare("UPDATE streams SET status = 'active' WHERE id IN ($placeholders)");
            } elseif ($action === 'deactivate') {
                $stmt = $pdo->prepare("UPDATE streams SET status = 'inactive' WHERE id IN ($placeholders)");
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
                if (empty($name)) throw new Exception('Stream name required.');

                $stmt = $pdo->prepare("SELECT class_id FROM streams WHERE id = ?");
                $stmt->execute([$id]);
                $class_id = $stmt->fetchColumn();
                if ($class_id === false) throw new Exception('Stream not found.');

                $stmt = $pdo->prepare("SELECT id FROM streams WHERE name = ? AND class_id = ? AND id != ?");
                $stmt->execute([$name, $class_id, $id]);
                if ($stmt->fetch()) throw new Exception('Stream name already exists in this class.');

                $stmt = $pdo->prepare("UPDATE streams SET name = ? WHERE id = ?");
                $stmt->execute([$name, $id]);
                echo json_encode(['success' => true, 'new_name' => htmlspecialchars($name)]);
            } elseif ($action === 'toggle_status') {
                $stmt = $pdo->prepare("UPDATE streams SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
                $stmt->execute([$id]);
                $new = $pdo->query("SELECT status FROM streams WHERE id = $id")->fetchColumn();
                echo json_encode(['success' => true, 'new_status' => $new]);
            } elseif ($action === 'delete') {
                $stmt = $pdo->prepare("DELETE FROM streams WHERE id = ?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');
$limit = 10;
$offset = ($page - 1) * $limit;

$where = "1=1";
$params = [];
if ($search) {
    $where .= " AND s.name LIKE ?";
    $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM streams s
    JOIN classes c ON s.class_id = c.id
    JOIN levels l ON c.level_id = l.id
    WHERE $where
");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$pages = max(1, ceil($total / $limit));

$stmt = $pdo->prepare("
    SELECT s.*, c.name AS class_name, l.name AS level_name
    FROM streams s
    JOIN classes c ON s.class_id = c.id
    JOIN levels l ON c.level_id = l.id
    WHERE $where
    ORDER BY l.name, c.name, s.name
    LIMIT ? OFFSET ?
");
$params[] = $limit;
$params[] = $offset;
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$base_query = [];
if ($search) $base_query['search'] = $search;
$base_query_str = $base_query ? '&' . http_build_query($base_query) : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Streams</title>
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

        .action-btn {
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 0.3rem;
            transition: all 0.2s;
            font-weight: 600;
        }

        .btn-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-edit:hover {
            background: #138496;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
        }

        .btn-delete:hover {
            background: #c82333;
        }

        .btn-toggle {
            background: #28a745;
            color: white;
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

        .editable-cell {
            min-width: 150px;
            position: relative;
        }

        .editable-input {
            padding: 0.4rem 0.6rem;
            border: 1px solid #aaa;
            border-radius: 4px;
            font-size: 0.9rem;
            width: 150px;
            margin-right: 0.4rem;
        }

        .editable-actions {
            display: inline-block;
        }

        .editable-actions button {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
            margin: 0 0.2rem;
            min-width: auto;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
        }

        .btn-cancel {
            background: #6c757d;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            padding: 0.35rem 0.75rem;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .btn-cancel:hover {
            background: #5a6268;
        }

        .pagination {
            display: flex;
            gap: 0.3rem;
            margin-top: 1.2rem;
            justify-content: center;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #333;
            font-size: 0.85rem;
            transition: all 0.2s;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .pagination .current {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
                <h1>Manage Streams</h1>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <div class="controls-row">
                <div class="search-section">
                    <label for="search_input">Search:</label>
                    <div class="search-container">
                        <input type="text" id="search_input" class="search-input"
                            placeholder="Enter stream name..."
                            value="<?= htmlspecialchars($search) ?>"
                            onkeypress="if(event.keyCode==13) applySearch()">
                        <button class="search-btn" onclick="applySearch()" type="button">
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
                        <button class="apply-btn" onclick="applyBulkAction()" type="button">
                            <i class="fas fa-check"></i> Apply
                        </button>
                    </div>
                </div>
            </div>

            <form id="streams_form">
                <table>
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select_all" onchange="toggleAll(this)"></th>
                            <th>S/N</th>
                            <th>Stream Name</th>
                            <th>Class</th>
                            <th>Level</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                            <tr>
                                <td><input type="checkbox" name="ids[]" value="<?= $item['id'] ?>" class="row-checkbox"></td>
                                <td><?= (($page - 1) * 10) + $i + 1 ?></td>
                                <td class="editable-cell" data-value="<?= htmlspecialchars($item['name']) ?>">
                                    <span class="text"><?= htmlspecialchars($item['name']) ?></span>
                                    <span class="edit" style="display:none;">
                                        <input type="text" class="editable-input" value="<?= htmlspecialchars($item['name']) ?>">
                                        <span class="editable-actions">
                                            <button class="btn-edit" type="button" onclick="saveEdit(this, <?= $item['id'] ?>)">Save</button>
                                            <button class="btn-cancel" type="button" onclick="cancelEdit(this)">Cancel</button>
                                        </span>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($item['class_name']) ?></td>
                                <td><?= htmlspecialchars($item['level_name']) ?></td>
                                <td>
                                    <span class="<?= $item['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= ucfirst($item['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="action-btn btn-edit" type="button" onclick="startEdit(this)">Edit</button>
                                    <button class="action-btn <?= $item['status'] === 'active' ? 'btn-toggle' : 'btn-toggle inactive' ?>"
                                        type="button" onclick="toggleStatus(<?= $item['id'] ?>)">
                                        <?= $item['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                    <button class="action-btn btn-delete" type="button" onclick="deleteRecord(<?= $item['id'] ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>

            <?php if ($pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=1<?= $base_query_str ?>">&laquo; First</a>
                        <a href="?page=<?= $page - 1 ?><?= $base_query_str ?>">&lt; Prev</a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($pages, $page + 2);
                    if ($start > 1) echo '<span>...</span>';
                    for ($i = $start; $i <= $end; $i++): ?>
                        <a href="?page=<?= $i ?><?= $base_query_str ?>" class="<?= $i === $page ? 'current' : '' ?>"><?= $i ?></a>
                    <?php endfor;
                    if ($end < $pages) echo '<span>...</span>';
                    ?>
                    <?php if ($page < $pages): ?>
                        <a href="?page=<?= $page + 1 ?><?= $base_query_str ?>">Next &gt;</a>
                        <a href="?page=<?= $pages ?><?= $base_query_str ?>">Last &raquo;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        function applySearch() {
            const val = document.getElementById('search_input').value.trim();
            let url = 'manage_streams.php';
            if (val) url += '?search=' + encodeURIComponent(val);
            window.location = url;
        }

        function applyBulkAction() {
            const action = document.getElementById('bulk_action').value;
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            const checkboxes = document.querySelectorAll('input[name="ids[]"]:checked');
            const ids = Array.from(checkboxes).map(cb => cb.value);
            if (ids.length === 0) {
                alert('Please select at least one stream.');
                return;
            }

            let confirmMessage = '';
            if (action === 'delete') {
                confirmMessage = `Are you sure you want to delete ${ids.length} selected stream(s)?`;
            } else if (action === 'activate') {
                confirmMessage = `Are you sure you want to activate ${ids.length} selected stream(s)?`;
            } else if (action === 'deactivate') {
                confirmMessage = `Are you sure you want to deactivate ${ids.length} selected stream(s)?`;
            }

            if (!confirm(confirmMessage)) return;

            const formData = new FormData();
            formData.append('bulk_action', action);
            ids.forEach(id => formData.append('ids[]', id));

            fetch('manage_streams.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred: ' + error.message);
                });
        }

        function toggleAll(checkbox) {
            document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = checkbox.checked);
        }

        function startEdit(btn) {
            const cell = btn.closest('tr').querySelector('.editable-cell');
            cell.querySelector('.text').style.display = 'none';
            cell.querySelector('.edit').style.display = 'inline';
            cell.querySelector('.editable-input').select();
        }

        function cancelEdit(btn) {
            const cell = btn.closest('.editable-cell');
            cell.querySelector('.text').style.display = 'inline';
            cell.querySelector('.edit').style.display = 'none';
        }

        function saveEdit(btn, id) {
            const cell = btn.closest('.editable-cell');
            const input = cell.querySelector('.editable-input');
            const newValue = input.value.trim();
            const oldValue = cell.dataset.value;

            if (!newValue) {
                alert('Stream name is required.');
                return;
            }
            if (newValue === oldValue) {
                cancelEdit(btn);
                return;
            }

            const formData = new FormData();
            formData.append('action', 'update');
            formData.append('id', id);
            formData.append('name', newValue);

            fetch('manage_streams.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        cell.querySelector('.text').textContent = data.new_name;
                        cell.dataset.value = data.new_name;
                        cancelEdit(btn);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred: ' + error.message);
                });
        }

        function toggleStatus(id) {
            if (!confirm('Are you sure you want to toggle the status of this stream?')) return;

            const formData = new FormData();
            formData.append('action', 'toggle_status');
            formData.append('id', id);

            fetch('manage_streams.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred: ' + error.message);
                });
        }

        function deleteRecord(id) {
            if (!confirm('Are you sure you want to delete this stream?')) return;

            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch('manage_streams.php', {
                    method: 'POST',
                    body: formData
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('An error occurred: ' + error.message);
                });
        }

        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                link.closest('.dropdown').classList.toggle('active');
            });
        });
        document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                link.closest('.nested').classList.toggle('active');
            });
        });
    </script>
</body>

</html>