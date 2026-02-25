<?php
require_once 'config.php';

// Ensure admin access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

$success_msg = $error_msg = '';

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['teacher_id'])) {
    $teacher_id = trim($_POST['teacher_id']);
    $admin_id = $_SESSION['user_id'];

    // Fetch teacher info for logging BEFORE deletion
    $teacher_info = null;
    try {
        $info_stmt = $pdo->prepare("SELECT teacher_id, fullname FROM users WHERE id = ? AND role = 'teacher'");
        $info_stmt->execute([$teacher_id]);
        $teacher_info = $info_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Failed to fetch teacher info before deletion: " . $e->getMessage());
    }

    try {
        $pdo->beginTransaction();

        // Delete from users (teachers)
        $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'teacher'");
        $deleted = $stmt->execute([$teacher_id]);

        if ($deleted && $stmt->rowCount() > 0) {
            // Clean up related assignments
            $pdo->prepare("DELETE FROM teacher_assignments WHERE teacher_id = ?")->execute([$teacher_id]);
            $pdo->prepare("DELETE FROM teacher_classes WHERE teacher_id = ?")->execute([$teacher_id]);
            $pdo->prepare("DELETE FROM teacher_subjects WHERE teacher_id = ?")->execute([$teacher_id]);

            // --- LOG THE ACTION ---
            $log_action = 'DELETE_TEACHER';
            $log_desc = "Deleted teacher: " .
                ($teacher_info ? htmlspecialchars($teacher_info['teacher_id'] . ' - ' . $teacher_info['fullname']) : "ID $teacher_id");

            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = null;
            }

            $log_stmt = $pdo->prepare("
                INSERT INTO activity_logs (user_id, action, description, ip_address, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $log_stmt->execute([$admin_id, $log_action, $log_desc, $ip]);

            $pdo->commit();
            $success_msg = "Teacher deleted successfully.";
        } else {
            $pdo->rollBack();
            $error_msg = "Teacher not found or could not be deleted.";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Delete teacher error: " . $e->getMessage());
        $error_msg = "An error occurred while deleting the teacher.";
    }
}

// Fetch all active teachers for selection
try {
    $teachers_stmt = $pdo->query("
        SELECT id, fullname, email, teacher_id 
        FROM users 
        WHERE role = 'teacher' AND status = 'active'
        ORDER BY fullname
    ");
    $teachers = $teachers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $teachers = [];
    error_log("Failed to fetch teachers: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Delete Teacher</title>
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
            --danger: #dc3545;
            --modal-bg: #343a40;
            --modal-btn-ok: #00bcd4;
            --modal-btn-cancel: #6c757d;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            display: flex;
            background-color: var(--body-bg);
            color: var(--text-dark);
            min-height: 100vh;
            overflow: hidden;
        }

        /* Sidebar - Copied exactly from admin_dashboard.php */
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
            padding: 2rem;
            background: var(--body-bg);
        }

        .page-header {
            margin-bottom: 1.5rem;
            cursor: pointer;
        }

        .page-header h1 {
            color: var(--primary);
            font-size: 1.8rem;
        }

        .alert {
            padding: 0.75rem 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(40, 167, 69, 0.15);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .alert-danger {
            background: rgba(220, 53, 69, 0.15);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }

        .card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--text-dark);
        }

        select,
        button {
            width: 100%;
            padding: 0.65rem;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 1rem;
        }

        button.delete-btn {
            background: var(--danger);
            color: white;
            border: none;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }

        button.delete-btn:hover {
            background: #c82333;
        }

        button.delete-btn:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        /* Modal Styles */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s, visibility 0.3s;
        }

        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }

        .modal-content {
            background: var(--modal-bg);
            border-radius: 8px;
            width: 90%;
            max-width: 420px;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.3);
            color: #fff;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 1.2rem;
            line-height: 1.5;
        }

        .modal-body {
            font-size: 1rem;
            color: #e9ecef;
            margin-bottom: 1.8rem;
            line-height: 1.6;
        }

        .modal-buttons {
            display: flex;
            gap: 0.8rem;
            justify-content: center;
        }

        .btn-modal {
            padding: 0.65rem 1.5rem;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: transform 0.2s, background 0.2s;
        }

        .btn-modal.ok {
            background: var(--modal-btn-ok);
            color: white;
        }

        .btn-modal.ok:hover {
            background: #0097a7;
            transform: translateY(-2px);
        }

        .btn-modal.cancel {
            background: var(--modal-btn-cancel);
            color: white;
        }

        .btn-modal.cancel:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Responsive */
        @media (max-width: 992px) {
            .sidebar {
                width: 70px;
                overflow-x: hidden;
            }

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after {
                opacity: 0;
                white-space: nowrap;
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
        <div class="page-header" onclick="window.location='admin_dashboard.php'">
            <h1><i class="fas fa-trash-alt"></i> Delete Teacher</h1>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success_msg) ?></div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>

        <div class="card">
            <form method="POST" id="deleteForm">
                <div class="form-group">
                    <label for="teacher_id"><i class="fas fa-chalkboard-teacher"></i> Select Teacher to Delete</label>
                    <select name="teacher_id" id="teacher_id" required>
                        <option value="">-- Choose a teacher --</option>
                        <?php foreach ($teachers as $t): ?>
                            <option value="<?= $t['id'] ?>">
                                <?= htmlspecialchars($t['fullname']) ?> (<?= htmlspecialchars($t['teacher_id'] ?? 'ID: ' . $t['id']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="delete-btn" id="showConfirmBtn">
                    <i class="fas fa-user-times"></i> Delete Selected Teacher
                </button>
            </form>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal-overlay" id="confirmModal">
        <div class="modal-content">
            <div class="modal-title">Are you sure you want to permanently delete this teacher?</div>
            <div class="modal-body">This action cannot be undone.</div>
            <div class="modal-buttons">
                <button class="btn-modal ok" id="confirmDeleteBtn">OK</button>
                <button class="btn-modal cancel" id="cancelDeleteBtn">Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // Toggle dropdowns (same as dashboard)
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

        // Modal controls
        const modal = document.getElementById('confirmModal');
        const showConfirmBtn = document.getElementById('showConfirmBtn');
        const confirmDeleteBtn = document.getElementById('confirmDeleteBtn');
        const cancelDeleteBtn = document.getElementById('cancelDeleteBtn');
        const deleteForm = document.getElementById('deleteForm');

        showConfirmBtn.addEventListener('click', function() {
            const teacherSelect = document.getElementById('teacher_id');
            if (!teacherSelect.value) {
                alert('Please select a teacher to delete.');
                return;
            }
            modal.classList.add('active');
        });

        cancelDeleteBtn.addEventListener('click', function() {
            modal.classList.remove('active');
        });

        confirmDeleteBtn.addEventListener('click', function() {
            modal.classList.remove('active');
            deleteForm.submit(); // Submit the form
        });

        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.classList.contains('active')) {
                modal.classList.remove('active');
            }
        });
    </script>

</body>

</html>