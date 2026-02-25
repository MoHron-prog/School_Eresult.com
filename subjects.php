<?php
require_once 'config.php';

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch active levels for filter dropdown
$levels = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY id");
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $levels = [];
}

// Get filter & sort inputs
$level_filter = $_GET['level'] ?? '';
$category_filter = $_GET['category'] ?? '';
$search_filter = trim($_GET['search'] ?? '');
$sort_by = $_GET['sort'] ?? 'code';
$sort_order = ($_GET['order'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// Validate sort column
$allowed_sort = ['code', 'name', 'level_name', 'category'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'code';
}

// Build query
$where = "1=1";
$params = [];

if ($level_filter !== '') {
    $where .= " AND s.level_id = ?";
    $params[] = (int)$level_filter;
}

if ($category_filter !== '') {
    if (in_array($category_filter, ['compulsory', 'elective', 'principal', 'subsidiary'])) {
        $where .= " AND s.category = ?";
        $params[] = $category_filter;
    }
}

if ($search_filter !== '') {
    $where .= " AND (s.code LIKE ? OR s.name LIKE ?)";
    $params[] = "%$search_filter%";
    $params[] = "%$search_filter%";
}

// Map sort_by to actual column/table
$sort_column = match ($sort_by) {
    'level_name' => 'l.name',
    'category' => 's.category',
    default => "s.$sort_by",
};

// Fetch subjects with filters
$sql = "
    SELECT s.id, s.code, s.name, s.category, s.status, l.name AS level_name
    FROM subjects s
    JOIN levels l ON s.level_id = l.id
    WHERE $where
    ORDER BY $sort_column " . strtoupper($sort_order);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Subjects</title>
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
            padding: 1.5rem;
            flex: 1;
            background: var(--body-bg);
        }

        .filter-section {
            background: white;
            padding: 1.2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            margin-bottom: 1.5rem;
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
        }

        .form-group label {
            margin-bottom: 0.35rem;
            font-size: 0.9rem;
            color: #495057;
            font-weight: 600;
        }

        .form-control {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
        }

        .btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .btn-secondary {
            background: #6c757d;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.85rem 1rem;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f9fa;
            font-weight: 700;
            color: var(--primary);
            position: sticky;
            top: 0;
        }

        th a {
            color: inherit;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        th a:hover {
            text-decoration: underline;
        }

        .sort-icon {
            font-size: 0.8rem;
            opacity: 0.6;
        }

        .category-badge {
            padding: 0.25rem 0.6rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-compulsory,
        .badge-principal {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-elective,
        .badge-subsidiary {
            background: #fff3cd;
            color: #856404;
        }

        .no-data {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
            font-style: italic;
        }

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
                <h1>View Subjects</h1>
            </div>
            <div class="role-tag">Admin</div>
        </header>
        <main class="main-content">
            <!-- Filters -->
            <div class="filter-section">
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="form-group" style="min-width: 150px;">
                            <label for="level">Level</label>
                            <select name="level" id="level" class="form-control" onchange="updateCategoryOptions(); this.form.submit()">
                                <option value="">All Levels</option>
                                <?php foreach ($levels as $level): ?>
                                    <option value="<?= $level['id'] ?>" <?= $level_filter == $level['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="min-width: 150px;">
                            <label for="category">Category</label>
                            <select name="category" id="category" class="form-control" onchange="this.form.submit()">
                                <option value="">All Categories</option>
                                <!-- Options will be updated by JS -->
                            </select>
                        </div>

                        <div class="form-group" style="flex: 1; min-width: 200px;">
                            <label for="search">Search Subject (Code or Name)</label>
                            <input type="text" name="search" id="search" class="form-control" value="<?= htmlspecialchars($search_filter) ?>" placeholder="e.g. MTC, Mathematics">
                        </div>

                        <div class="form-group" style="display: flex; align-items: end;">
                            <button type="submit" class="btn" style="height: fit-content;">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Results Table -->
            <div class="table-container">
                <?php if (empty($subjects)): ?>
                    <div class="no-data">No subjects found. <?= $search_filter || $level_filter || $category_filter ? 'Try adjusting your filters.' : 'Add a subject to get started.' ?></div>
                <?php else: ?>
                    <table>
                        <thead>
                            <tr>
                                <th>
                                    <a href="?<?= buildSortUrl('code', $sort_order, $level_filter, $category_filter, $search_filter) ?>">
                                        Code
                                        <?= getSortIcon('code', $sort_by, $sort_order) ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= buildSortUrl('name', $sort_order, $level_filter, $category_filter, $search_filter) ?>">
                                        Subject Name
                                        <?= getSortIcon('name', $sort_by, $sort_order) ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= buildSortUrl('level_name', $sort_order, $level_filter, $category_filter, $search_filter) ?>">
                                        Level
                                        <?= getSortIcon('level_name', $sort_by, $sort_order) ?>
                                    </a>
                                </th>
                                <th>
                                    <a href="?<?= buildSortUrl('category', $sort_order, $level_filter, $category_filter, $search_filter) ?>">
                                        Category
                                        <?= getSortIcon('category', $sort_by, $sort_order) ?>
                                    </a>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subj): ?>
                                <tr>
                                    <td><?= htmlspecialchars($subj['code']) ?></td>
                                    <td><?= htmlspecialchars($subj['name']) ?></td>
                                    <td><?= htmlspecialchars($subj['level_name']) ?></td>
                                    <td>
                                        <span class="category-badge badge-<?= $subj['category'] ?>">
                                            <?= ucfirst(htmlspecialchars($subj['category'])) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        // Add dropdown toggle functionality (same as in admin_dashboard.php)
        // Toggle top-level dropdowns (e.g., Teacher Management)
        document.querySelectorAll('.dropdown > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.dropdown');
                parent.classList.toggle('active');
            });
        });

        // Toggle nested dropdowns (e.g., O-Level, A-Level)
        document.querySelectorAll('.nested > .dropdown-toggle').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const parent = this.closest('.nested');
                parent.classList.toggle('active');
            });
        });

        function updateCategoryOptions() {
            const levelSelect = document.getElementById('level');
            const categorySelect = document.getElementById('category');
            const selectedLevel = levelSelect.value;

            // Reset
            categorySelect.innerHTML = '<option value="">All Categories</option>';

            if (selectedLevel === '1') {
                categorySelect.innerHTML += `
                    <option value="compulsory" <?= ($category_filter === 'compulsory') ? 'selected' : '' ?>>Compulsory</option>
                    <option value="elective" <?= ($category_filter === 'elective') ? 'selected' : '' ?>>Elective</option>
                `;
            } else if (selectedLevel === '2') {
                categorySelect.innerHTML += `
                    <option value="principal" <?= ($category_filter === 'principal') ? 'selected' : '' ?>>Principal</option>
                    <option value="subsidiary" <?= ($category_filter === 'subsidiary') ? 'selected' : '' ?>>Subsidiary</option>
                `;
            }
        }

        // Initialize on load
        document.addEventListener('DOMContentLoaded', function() {
            updateCategoryOptions();
            // Set category if already selected
            <?php if ($level_filter && $category_filter): ?>
                document.getElementById('category').value = <?= json_encode($category_filter) ?>;
            <?php endif; ?>
        });

        function buildSortUrl(col, currentOrder, level, cat, search) {
            const order = (col === <?= json_encode($sort_by) ?> && currentOrder === 'asc') ? 'desc' : 'asc';
            const params = new URLSearchParams();
            if (level) params.set('level', level);
            if (cat) params.set('category', cat);
            if (search) params.set('search', search);
            params.set('sort', col);
            params.set('order', order);
            return params.toString();
        }
    </script>

    <?php
    // Helper functions for sorting links
    function buildSortUrl($col, $current_order, $level, $cat, $search)
    {
        $order = ($col === ($_GET['sort'] ?? '') && $current_order === 'asc') ? 'desc' : 'asc';
        $params = [];
        if ($level !== '') $params['level'] = $level;
        if ($cat !== '') $params['category'] = $cat;
        if ($search !== '') $params['search'] = $search;
        $params['sort'] = $col;
        $params['order'] = $order;
        return http_build_query($params);
    }

    function getSortIcon($col, $sort_by, $sort_order)
    {
        if ($col !== $sort_by) return '<span class="sort-icon">↕</span>';
        return $sort_order === 'asc'
            ? '<span class="sort-icon">↑</span>'
            : '<span class="sort-icon">↓</span>';
    }
    ?>
</body>

</html>