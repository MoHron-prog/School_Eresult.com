<?php
require_once 'config.php';

// Redirect if not admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch levels (O Level / A Level)
$levels = [];
try {
    $stmt = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY id");
    $levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Failed to load levels.";
}

$message = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $level_id = (int)($_POST['level_id'] ?? 0);
    $category = trim($_POST['category'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');

    // Validation
    if (!$level_id || !$category || !$code || !$name) {
        $message = "All fields are required.";
    } elseif (!in_array($category, ['compulsory', 'elective', 'principal', 'subsidiary'])) {
        $message = "Invalid category.";
    } else {
        // Validate category against level
        $allowed = false;
        if ($level_id == 1 && in_array($category, ['compulsory', 'elective'])) {
            $allowed = true;
        } elseif ($level_id == 2 && in_array($category, ['principal', 'subsidiary'])) {
            $allowed = true;
        }

        if (!$allowed) {
            $message = "Category does not match the selected level.";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO subjects (level_id, category, code, name) VALUES (?, ?, ?, ?)");
                $stmt->execute([$level_id, $category, $code, $name]);
                $success = true;
                $message = "Subject added successfully!";
                // Reset form
                $code = $name = '';
            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $message = "A subject with this code already exists for the selected level.";
                } else {
                    $message = "Failed to save subject. Please try again.";
                }
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Add Subject</title>
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
        .dropdown.active > .nav-link.dropdown-toggle::after,
        .nested.active > .nav-link.dropdown-toggle::after {
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
        .dropdown.active > .dropdown-menu {
            max-height: 1000px;
            padding: 0.45rem 0 0.45rem 1.2rem;
        }
        .nested.active > .nested-menu {
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
        .form-container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            padding: 1.5rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
        }
        .form-container h2 {
            margin-bottom: 1.2rem;
            color: var(--primary);
            font-size: 1.4rem;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
            color: #495057;
        }
        .form-control {
            width: 100%;
            padding: 0.55rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
        }
        .btn {
            padding: 0.55rem 1.2rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 600;
           margin-left: 30%;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #0f1d4d;
        }
        .btn.secondary {
            background: #6c757d;
            color: white;
            text-decoration: none;
            display: inline-block;
            margin-left: 10px;
        }
        .btn.secondary:hover {
            background: #5a6268;
        }
        .alert {
            padding: 0.75rem;
            margin-bottom: 1.2rem;
            border-radius: 4px;
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
        @media (max-width: 992px) {
            .sidebar {
                width: 60px;
            }
            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after,
            .sidebar .nested > .nav-link::after {
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

            <!-- Subjects - This should be active by default -->
            <li class="nav-item dropdown active">
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
                <h1>Add New Subject</h1>
            </div>
            <div class="role-tag">Admin</div>
        </header>
        <main class="main-content">
            <div class="form-container">
                <h2>Add New Subject</h2>
                
                <?php if ($message): ?>
                    <div class="alert <?= $success ? 'alert-success' : 'alert-danger' ?>">
                        <?= htmlspecialchars($message) ?>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="level_id">Level</label>
                        <select name="level_id" id="level_id" class="form-control" required onchange="updateCategoryOptions()">
                            <option value="">-- Select Level --</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['id'] ?>" <?= (isset($_POST['level_id']) && $_POST['level_id'] == $level['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($level['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="category">Subject Category</label>
                        <select name="category" id="category" class="form-control" required>
                            <option value="">-- Select Category --</option>
                            <!-- Options populated by JS -->
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="code">Subject Code</label>
                        <input type="text" name="code" id="code" class="form-control" value="<?= htmlspecialchars($code ?? '') ?>" required maxlength="20">
                    </div>

                    <div class="form-group">
                        <label for="name">Subject Name</label>
                        <input type="text" name="name" id="name" class="form-control" value="<?= htmlspecialchars($name ?? '') ?>" required maxlength="100">
                    </div>

                    <button type="submit" class="btn"><i class="fas fa-save"></i> Save Subject</button>
                    <a href="admin_dashboard.php" class="btn secondary">Cancel</a>
                </form>
            </div>
        </main>
    </div>

    <script>
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

        // Function to update category options based on level selection
        function updateCategoryOptions() {
            const levelSelect = document.getElementById('level_id');
            const categorySelect = document.getElementById('category');
            const levelId = levelSelect.value;

            // Clear current options
            categorySelect.innerHTML = '<option value="">-- Select Category --</option>';

            if (levelId === '1') {
                // O Level
                categorySelect.innerHTML += `
                    <option value="compulsory">Compulsory</option>
                    <option value="elective">Elective</option>
                `;
            } else if (levelId === '2') {
                // A Level
                categorySelect.innerHTML += `
                    <option value="principal">Principal</option>
                    <option value="subsidiary">Subsidiary</option>
                `;
            }
        }

        // Restore category selection on reload (if form was submitted)
        <?php if (isset($_POST['level_id']) && isset($_POST['category'])): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const levelId = <?= json_encode($_POST['level_id']) ?>;
                const category = <?= json_encode($_POST['category']) ?>;
                document.getElementById('level_id').value = levelId;
                updateCategoryOptions();
                document.getElementById('category').value = category;
            });
        <?php endif; ?>

        // Open the Subjects Management dropdown by default since we're on add_subject.php
        document.addEventListener('DOMContentLoaded', function() {
            const subjectsDropdown = document.querySelector('.dropdown.active');
            if (subjectsDropdown) {
                subjectsDropdown.classList.add('active');
            }
        });
    </script>
</body>
</html>