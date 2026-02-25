<?php
require_once 'config.php';

// Check if user is logged in and is a teacher
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: index.php");
    exit;
}

// Fetch teacher info
try {
    $stmt = $pdo->prepare("SELECT fullname, email, teacher_id FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacher) {
        throw new Exception("Teacher not found.");
    }
    $fullname = htmlspecialchars($teacher['fullname']);
    $email = htmlspecialchars($teacher['email']);
    $teacher_id = htmlspecialchars($teacher['teacher_id']);
} catch (Exception $e) {
    $fullname = "Teacher";
    $email = "—";
    $teacher_id = "—";
}

// Fetch teacher's assignments and subjects
$teaching_subjects = [];
$teaching_classes = [];
$student_counts = [];

try {
    // Fetch teacher's subjects with student counts
    $stmt = $pdo->prepare("
        SELECT 
            ts.subject_id,
            s.name AS subject_name,
            s.code AS subject_code,
            l.name AS level_name,
            l.id AS level_id,
            COUNT(DISTINCT ss.student_id) AS student_count
        FROM teacher_subjects ts
        JOIN subjects s ON ts.subject_id = s.id
        JOIN levels l ON s.level_id = l.id
        LEFT JOIN student_subjects ss ON s.id = ss.subject_id
        LEFT JOIN students st ON ss.student_id = st.id AND st.status = 'active'
        WHERE ts.teacher_id = ?
        GROUP BY ts.subject_id, s.name, s.code, l.name
        ORDER BY l.name, s.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $teaching_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch teacher's classes with student counts
    $stmt = $pdo->prepare("
        SELECT 
            tc.class_id,
            c.name AS class_name,
            l.name AS level_name,
            COUNT(DISTINCT s.id) AS student_count
        FROM teacher_classes tc
        JOIN classes c ON tc.class_id = c.id
        JOIN levels l ON c.level_id = l.id
        LEFT JOIN students s ON c.id = s.class_id AND s.status = 'active'
        WHERE tc.teacher_id = ?
        GROUP BY tc.class_id, c.name, l.name
        ORDER BY l.name, c.name
    ");
    $stmt->execute([$_SESSION['user_id']]);
    $teaching_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also get total student count across all subjects
    $total_students = 0;
    foreach ($teaching_subjects as $subject) {
        $total_students += $subject['student_count'];
    }
} catch (Exception $e) {
    error_log("Error fetching teacher data: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Teacher Dashboard</title>
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

        /* Welcome Section */
        .welcome-section {
            background: linear-gradient(135deg, var(--primary), #3a506b);
            color: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        .welcome-section h2 {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .welcome-section p {
            font-size: 1rem;
            opacity: 0.9;
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
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-card i {
            font-size: 2rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
        }

        .stat-card h3 {
            font-size: 1.5rem;
            margin: 0.25rem 0;
            color: var(--secondary);
        }

        .stat-card p {
            color: #6c757d;
            font-size: 0.9rem;
            margin: 0;
        }

        /* Teaching Sections */
        .teaching-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .teaching-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        /* Tables */
        .teaching-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
        }

        .teaching-table th {
            background: #f8f9fa;
            color: var(--primary);
            padding: 0.8rem;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }

        .teaching-table td {
            padding: 0.8rem;
            border-bottom: 1px solid #eee;
        }

        .teaching-table tr:hover {
            background: #f8f9fa;
        }

        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-primary {
            background: rgba(26, 42, 108, 0.1);
            color: var(--primary);
        }

        .badge-secondary {
            background: rgba(178, 31, 31, 0.1);
            color: var(--secondary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        /* Mobile Responsive */
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
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
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

            .welcome-section h2 {
                font-size: 1.5rem;
            }
        }

        @media (max-width: 576px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }

            .teaching-table {
                display: block;
                overflow-x: auto;
            }

            .teaching-section {
                padding: 1rem;
            }

            .main-content {
                padding: 1rem;
            }

            .welcome-section {
                padding: 1rem;
            }

            .welcome-section h2 {
                font-size: 1.3rem;
            }
        }

        /* Mobile Menu Toggle */
        .mobile-menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            width: 40px;
            height: 40px;
            font-size: 1.2rem;
            cursor: pointer;
        }

        @media (max-width: 576px) {
            .mobile-menu-toggle {
                display: block;
            }

            .sidebar {
                transform: translateX(-100%);
                transition: transform 0.3s ease;
                width: 280px;
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .main-wrapper {
                margin-left: 0;
                width: 100%;
            }
        }
    </style>
</head>

<body>
    <!-- Mobile Menu Toggle -->
    <button class="mobile-menu-toggle" id="menuToggle">
        <i class="fas fa-bars"></i>
    </button>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Teacher Portal</span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="teacher_dashboard.php" class="nav-link">
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
                    <i class="fas fa-file-alt"></i>
                    <span>Master Marksheets</span>
                </a>
            </li>

            <li class="nav-item">
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-print"></i>
                    <span>Report Center</span>
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
                <h1>Welcome, <?= $fullname ?>!</h1>
                <p><?= $email ?> | ID: <?= $teacher_id ?></p>
            </div>
            <div class="role-tag">Teacher</div>
        </header>

        <main class="main-content">
            <!-- Welcome Section -->
            <div class="welcome-section">
                <h2>Teaching Dashboard</h2>
                <p>Manage your classes, enter marks, and view student progress</p>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <i class="fas fa-book"></i>
                    <h3><?= count($teaching_subjects) ?></h3>
                    <p>Subjects Teaching</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-school"></i>
                    <h3><?= count($teaching_classes) ?></h3>
                    <p>Classes Assigned</p>
                </div>
                <div class="stat-card">
                    <i class="fas fa-user-graduate"></i>
                    <h3><?= $total_students ?></h3>
                    <p>Total Students</p>
                </div>
            </div>

            <!-- Teaching Subjects -->
            <div class="teaching-section">
                <h3>Subjects You're Teaching</h3>
                <?php if (!empty($teaching_subjects)): ?>
                    <table class="teaching-table">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Code</th>
                                <th>Level</th>
                                <th>Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teaching_subjects as $subject): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($subject['subject_name']) ?></strong></td>
                                    <td><span class="badge badge-primary"><?= htmlspecialchars($subject['subject_code']) ?></span></td>
                                    <td><?= htmlspecialchars($subject['level_name']) ?></td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?= $subject['student_count'] ?> students
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-book"></i>
                        <p>No subjects assigned yet. Please contact the administrator.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Teaching Classes -->
            <div class="teaching-section">
                <h3>Classes You're Assigned To</h3>
                <?php if (!empty($teaching_classes)): ?>
                    <table class="teaching-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Level</th>
                                <th>Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teaching_classes as $class): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($class['class_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($class['level_name']) ?></td>
                                    <td>
                                        <span class="badge badge-secondary">
                                            <?= $class['student_count'] ?> students
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-school"></i>
                        <p>No classes assigned yet. Please contact the administrator.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Quick Actions -->
            <div class="teaching-section">
                <h3>Quick Actions</h3>
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <a href="enter_marks.php" class="btn" style="background: var(--primary); color: white; padding: 0.8rem 1.5rem; border-radius: 4px; text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-edit"></i>
                        Enter Marks
                    </a>
                    <a href="marksheets.php" class="btn" style="background: var(--secondary); color: white; padding: 0.8rem 1.5rem; border-radius: 4px; text-decoration: none; display: flex; align-items: center; gap: 0.5rem;">
                        <i class="fas fa-file-alt"></i>
                        View Marksheets
                    </a>
                </div>
            </div>
        </main>
    </div>

    <script>
        // Mobile menu toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar');

        menuToggle.addEventListener('click', function() {
            sidebar.classList.toggle('active');
        });

        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            if (window.innerWidth <= 576) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.remove('active');
                }
            }
        });

        // Fix sidebar hover behavior for tablet
        if (window.innerWidth <= 992 && window.innerWidth > 576) {
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
    </script>
</body>

</html>