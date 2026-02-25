<?php
require_once 'config.php';

// Ensure only admin can access
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch admin info for header
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($admin['fullname'] ?? 'Admin');
    $email_header = htmlspecialchars($admin['email'] ?? '—');
} catch (Exception $e) {
    $fullname = "Admin";
    $email_header = "—";
}

// Initialize message variables (for future use when saving settings)
$message = '';
$message_type = '';

// TODO: Add form handling logic here when implementing actual settings
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>System Settings</title>
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
background-color: var(--body-bg);
color: var(--text-dark);
height: 100vh;
overflow: hidden;
}
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
padding: 1.5rem;
flex: 1;
overflow-y: auto;
}
.card {
background: white;
border-radius: 8px;
box-shadow: 0 2px 10px rgba(0,0,0,0.08);
padding: 1.5rem;
max-width: 800px;
margin: 0 auto;
}
.card h2 {
color: var(--primary);
margin-bottom: 1.2rem;
font-size: 1.4rem;
}
.form-group {
margin-bottom: 1.2rem;
}
.form-group label {
display: block;
margin-bottom: 0.4rem;
font-weight: 600;
color: #495057;
}
.form-control {
width: 100%;
padding: 0.6rem 0.8rem;
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
transition: background 0.2s;
}
.btn:hover {
background: #0f1d4d;
}
.btn-secondary {
background: #6c757d;
}
.alert {
padding: 0.75rem 1rem;
margin-bottom: 1.2rem;
border-radius: 4px;
font-weight: 500;
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
.actions {
display: flex;
gap: 0.75rem;
margin-top: 1rem;
}
@media (max-width: 768px) {
.sidebar { width: 70px; }
.main-wrapper { margin-left: 70px; width: calc(100% - 70px); }
.sidebar:hover { width: 280px; }
.sidebar:hover + .main-wrapper { margin-left: 280px; width: calc(100% - 280px); }
}
</style>
</head>
<body>

<!-- Sidebar (identical to admin_dashboard.php) -->
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
<li class="nested">
<a href="#" class="nav-link dropdown-toggle"><span>O-Level Assessment</span></a>
<ul class="nested-menu">
<li><a href="marks_o_level_add.php" class="nav-link">Add Marks</a></li>
<li><a href="marks_o_level_view.php" class="nav-link">View Marks</a></li>
<li><a href="grading_o_level.php" class="nav-link">Set Grading</a></li>
</ul>
</li>
<li class="nested">
<a href="#" class="nav-link dropdown-toggle"><span>A-Level Assessment</span></a>
<ul class="nested-menu">
<li><a href="marks_a_level_add.php" class="nav-link">Add Marks</a></li>
<li><a href="marks_a_level_view.php" class="nav-link">View Marks</a></li>
<li><a href="grading_a_level.php" class="nav-link">Set Grading</a></li>
</ul>
</li>
<li class="nested">
<a href="#" class="nav-link dropdown-toggle"><span>Examinations</span></a>
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
<li class="nav-item dropdown">
<a href="#" class="nav-link dropdown-toggle">
<i class="fas fa-cog"></i>
<span>More</span>
</a>
<ul class="dropdown-menu">
<li><a href="export_logs.php" class="nav-link">Export Logs</a></li>
<li><a href="settings.php" class="nav-link active">Settings</a></li>
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
<header class="header">
<div class="admin-info">
<h1>Welcome back, <?= $fullname ?>!</h1>
<p><?= $email_header ?></p>
</div>
<div class="role-tag">Admin</div>
</header>
<main class="main-content">
<div class="card">
<h2><i class="fas fa-cog"></i> System Settings</h2>

<?php if ($message): ?>
<div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<!-- Example settings form (customize as needed) -->
<form method="POST">
<div class="form-group">
<label for="school_year">Current Academic Year</label>
<input type="text" id="school_year" name="school_year" class="form-control" placeholder="e.g., 2025/2026">
</div>

<div class="form-group">
<label for="timezone">Timezone</label>
<select id="timezone" name="timezone" class="form-control">
<option value="Africa/Kampala">Africa/Kampala (UTC+3)</option>
<option value="UTC">UTC</option>
<!-- Add more as needed -->
</select>
</div>

<div class="form-group">
<label>
<input type="checkbox" name="enable_auto_backup" value="1"> Enable Automatic Database Backup
</label>
</div>

<div class="actions">
<button type="submit" class="btn">
<i class="fas fa-save"></i> Save Settings
</button>
<a href="admin_dashboard.php" class="btn btn-secondary">
<i class="fas fa-arrow-left"></i> Back to Dashboard
</a>
</div>
</form>
</div>
</main>
</div>

<script>
// Dropdown toggle logic (same as admin_dashboard.php)
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
</script>
</body>
</html>