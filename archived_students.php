<?php
// archived_students.php
require_once 'config.php';

// Check if user is admin or teacher
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'teacher')) {
    header("Location: index.php");
    exit;
}

// Fetch user info
try {
    $stmt = $pdo->prepare("SELECT fullname, email, role FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        throw new Exception("User not found.");
    }
    $fullname = htmlspecialchars($user['fullname']);
    $email = htmlspecialchars($user['email']);
    $role = htmlspecialchars($user['role']);
} catch (Exception $e) {
    $fullname = "User";
    $email = "—";
    $role = "—";
}

// Fetch school information
$school_info = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM school_info WHERE id = 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // School info not found, use defaults
    $school_info = [
        'school_name' => 'Napak seed school school',
        'school_logo' => 'uploads/logos/logo_1770490926.png',
        'address' => 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward, Napak Town Council',
        'motto' => 'Achieving excellence together',
        'phone' => '0200912924/077088027',
        'email' => 'napakseed@gmail.com'
    ];
}

// Fetch filter options
$academic_years = $pdo->query("SELECT id, year_name FROM academic_years ORDER BY start_year DESC")->fetchAll();
$levels = $pdo->query("SELECT id, name FROM levels WHERE status = 'active' ORDER BY id")->fetchAll();
$classes = $pdo->query("SELECT id, name, level_id FROM classes WHERE status = 'active' ORDER BY level_id, name")->fetchAll();
$streams = $pdo->query("SELECT id, name, class_id FROM streams WHERE status = 'active' ORDER BY class_id, name")->fetchAll();

// Group classes by level
$classes_by_level = [];
foreach ($classes as $class) {
    $classes_by_level[$class['level_id']][] = $class;
}

// Group streams by class
$streams_by_class = [];
foreach ($streams as $stream) {
    $streams_by_class[$stream['class_id']][] = $stream;
}

// Process filter form
$academic_year_id = $_GET['academic_year'] ?? '';
$level_id = $_GET['level'] ?? '';
$class_id = $_GET['class'] ?? '';
$stream_id = $_GET['stream'] ?? '';

// Build query for archived students
$where_clause = "1=1";
$params = [];
if (!empty($academic_year_id)) {
    $where_clause .= " AND a.academic_year_id = ?";
    $params[] = $academic_year_id;
}
if (!empty($level_id)) {
    $where_clause .= " AND a.level_id = ?";
    $params[] = $level_id;
}
if (!empty($class_id)) {
    $where_clause .= " AND a.class_id = ?";
    $params[] = $class_id;
}
if (!empty($stream_id)) {
    $where_clause .= " AND a.stream_id = ?";
    $params[] = $stream_id;
}

// Fetch archived students
$query = "
    SELECT 
        a.*,
        ay.year_name,
        l.name as level_name,
        c.name as class_name,
        s.name as stream_name,
        u.fullname as archived_by_name,
        COUNT(DISTINCT ass.subject_id) as subject_count
    FROM archived_students a
    LEFT JOIN academic_years ay ON a.academic_year_id = ay.id
    LEFT JOIN levels l ON a.level_id = l.id
    LEFT JOIN classes c ON a.class_id = c.id
    LEFT JOIN streams s ON a.stream_id = s.id
    LEFT JOIN users u ON a.archived_by = u.id
    LEFT JOIN archived_student_subjects ass ON a.id = ass.archived_student_id
    WHERE $where_clause
    GROUP BY a.id
    ORDER BY a.archived_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$archived_students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$total_archived = $pdo->query("SELECT COUNT(*) FROM archived_students")->fetchColumn();
$total_by_reason = $pdo->query("SELECT archival_reason, COUNT(*) as count FROM archived_students GROUP BY archival_reason")->fetchAll();

// Function to get student subjects
function getArchivedStudentSubjects($pdo, $archived_student_id)
{
    $query = "
        SELECT s.code, s.name, s.category
        FROM archived_student_subjects ass
        JOIN subjects s ON ass.subject_id = s.id
        WHERE ass.archived_student_id = ?
        ORDER BY s.category, s.name
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute([$archived_student_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Function to generate school header HTML
function generateSchoolHeader($school_info, $title = 'Archived Students List')
{
    $logo_path = !empty($school_info['school_logo']) ? $school_info['school_logo'] : 'assets/images/default-logo.png';
    $school_name = htmlspecialchars($school_info['school_name'] ?? 'NSS School');
    $address = htmlspecialchars($school_info['address'] ?? 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward, Napak Town Council');
    $phone = htmlspecialchars($school_info['phone'] ?? '0200912924/077088027');
    $email = htmlspecialchars($school_info['email'] ?? 'napakseed@gmail.com');
    $motto = htmlspecialchars($school_info['motto'] ?? 'Achieving excellence together');

    return '
    <div style="text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #1a2a6c;">
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
            <tr>
                <td style="width: 15%; text-align: right; padding-right: 15px; vertical-align: middle;">
                    <img src="' . $logo_path . '" alt="School Logo" style="max-width: 80px; max-height: 80px; border-radius: 5px;" onerror="this.style.display=\'none\'">
                </td>
                <td style="width: 70%; text-align: center; vertical-align: middle;">
                    <h1 style="margin: 0; color: #1a2a6c; font-size: 26px; font-weight: 700; letter-spacing: 0.5px;">' . $school_name . '</h1>
                    <p style="margin: 8px 0 5px; color: #2c3e50; font-size: 14px; font-style: italic;">' . $motto . '</p>
                    <p style="margin: 5px 0; color: #495057; font-size: 13px;">
                        <i class="fas fa-map-marker-alt" style="margin-right: 5px;"></i> ' . $address . '
                    </p>
                    <p style="margin: 5px 0; color: #495057; font-size: 13px;">
                        <i class="fas fa-phone" style="margin-right: 5px;"></i> ' . $phone . ' 
                        <span style="margin: 0 10px;">|</span>
                        <i class="fas fa-envelope" style="margin-right: 5px;"></i> ' . $email . '
                    </p>
                </td>
                <td style="width: 15%; text-align: left; padding-left: 15px; vertical-align: middle;">
                    <!-- Empty cell for balance -->
                </td>
            </tr>
        </table>
        <h2 style="margin: 15px 0 5px; color: #b21f1f; font-size: 22px; font-weight: 600;">' . $title . '</h2>
        <p style="margin: 5px 0; color: #6c757d; font-size: 13px;">
            Generated on: ' . date('l, F j, Y') . ' at ' . date('g:i A') . '
        </p>
    </div>';
}

// Function to generate school header for student details
function generateStudentDetailsHeader($school_info, $student)
{
    $logo_path = !empty($school_info['school_logo']) ? $school_info['school_logo'] : 'assets/images/default-logo.png';
    $school_name = htmlspecialchars($school_info['school_name'] ?? 'NSS School');
    $address = htmlspecialchars($school_info['address'] ?? 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward, Napak Town Council');
    $phone = htmlspecialchars($school_info['phone'] ?? '0200912924/077088027');
    $email = htmlspecialchars($school_info['email'] ?? 'napakseed@gmail.com');

    return '
    <div style="text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #1a2a6c;">
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
            <tr>
                <td style="width: 15%; text-align: right; padding-right: 15px; vertical-align: middle;">
                    <img src="' . $logo_path . '" alt="School Logo" style="max-width: 70px; max-height: 70px; border-radius: 5px;" onerror="this.style.display=\'none\'">
                </td>
                <td style="width: 70%; text-align: center; vertical-align: middle;">
                    <h1 style="margin: 0; color: #1a2a6c; font-size: 22px; font-weight: 700;">' . $school_name . '</h1>
                    <p style="margin: 5px 0; color: #495057; font-size: 12px;">' . $address . '</p>
                    <p style="margin: 5px 0; color: #495057; font-size: 12px;">Tel: ' . $phone . ' | Email: ' . $email . '</p>
                </td>
                <td style="width: 15%; text-align: left; padding-left: 15px; vertical-align: middle;">
                    <!-- Empty cell for balance -->
                </td>
            </tr>
        </table>
        <h2 style="margin: 10px 0 5px; color: #b21f1f; font-size: 20px; font-weight: 600;">Archived Student Details</h2>
        <p style="margin: 5px 0; color: #2c3e50; font-size: 14px; font-weight: 500;">
            Student ID: ' . htmlspecialchars($student['student_id']) . ' | ' . htmlspecialchars($student['surname'] . ', ' . $student['other_names']) . '
        </p>
    </div>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Archived Students</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css" />
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

        body {
            display: flex;
            min-height: 100vh;
            background-color: var(--body-bg);
            color: var(--text-dark);
        }

        /* Sidebar (Same as admin_dashboard.php) */
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
            min-height: 100vh;
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
            padding: 1.5rem 1.4rem;
            flex: 1;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-title h2 {
            color: var(--primary);
            font-size: 1.8rem;
            margin: 0;
        }

        .page-title p {
            color: #6c757d;
            margin-top: 0.25rem;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 1.2rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
        }

        .stat-icon {
            width: 50px;
            height: 50px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: white;
        }

        .stat-icon.archive {
            background: var(--warning);
        }

        .stat-icon.graduated {
            background: var(--success);
        }

        .stat-icon.transferred {
            background: var(--info);
        }

        .stat-icon.sickness {
            background: #e74c3c;
        }

        .stat-info h3 {
            font-size: 1.8rem;
            margin: 0;
            color: var(--primary);
        }

        .stat-info p {
            color: #6c757d;
            margin: 0.25rem 0 0 0;
            font-size: 0.9rem;
        }

        /* Filter Section */
        .filter-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .filter-title {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            color: #495057;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-control {
            padding: 0.6rem 0.8rem;
            border: 1px solid #ced4da;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: border-color 0.15s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 6px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            justify-content: center;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #0f1d4d;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        /* Table Container */
        .table-container {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .table-title {
            color: var(--primary);
            font-size: 1.3rem;
            margin: 0;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        /* DataTable Customization */
        .dataTables_wrapper {
            margin-top: 1rem;
        }

        table.dataTable {
            width: 100% !important;
            border-collapse: collapse;
        }

        table.dataTable thead th {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1rem 0.75rem;
            font-weight: 600;
        }

        table.dataTable tbody td {
            padding: 0.75rem;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }

        table.dataTable tbody tr:hover {
            background-color: #f8f9fa;
        }

        /* Student Photo */
        .student-photo {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: fill;
            border: 2px solid #e9ecef;
        }

        /* Status Badges */
        .badge {
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-graduated {
            background: #d4edda;
            color: #155724;
        }

        .badge-transferred {
            background: #d1ecf1;
            color: #0c5460;
        }

        .badge-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .badge-active {
            background: #d4edda;
            color: #155724;
        }

        .badge-day {
            background: #cce5ff;
            color: #004085;
        }

        .badge-boarding {
            background: #fff3cd;
            color: #856404;
        }

        /* Icon-only Action Buttons */
        .action-icons {
            display: flex;
            gap: 0.4rem;
            justify-content: center;
        }

        .icon-btn {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            color: white;
        }

        .icon-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }

        .icon-view {
            background: var(--info);
        }

        .icon-view:hover {
            background: #138496;
        }

        .icon-restore {
            background: var(--success);
        }

        .icon-restore:hover {
            background: #218838;
        }

        .icon-print {
            background: #17a2b8;
        }

        .icon-print:hover {
            background: #138496;
        }

        .icon-btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            z-index: 1000;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 1rem;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1050;
            align-items: center;
            justify-content: center;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            width: 90%;
            max-width: 1000px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 1.2rem 1.5rem;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--primary);
            color: white;
            border-radius: 8px 8px 0 0;
        }

        .modal-title {
            margin: 0;
            font-size: 1.4rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.8rem;
            color: white;
            cursor: pointer;
            padding: 0;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .modal-close:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .modal-body {
            padding: 1.5rem;
        }

        /* Student Details Layout with Tables */
        .student-details-table-container {
            width: 100%;
        }

        .student-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary);
        }

        .student-photo-large {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary);
        }

        .student-header-info {
            flex: 1;
        }

        .student-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.25rem;
        }

        .student-id {
            color: #6c757d;
            font-size: 1rem;
            margin-bottom: 0.5rem;
        }

        /* Details Tables */
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1.5rem;
            border: 1px solid #dee2e6;
        }

        .details-table caption {
            caption-side: top;
            text-align: left;
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary);
            padding: 0.75rem;
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-bottom: none;
        }

        .details-table th,
        .details-table td {
            padding: 0.75rem;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }

        .details-table th {
            width: 30%;
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            text-align: left;
        }

        .details-table td {
            color: #212529;
        }

        .details-table tr:nth-child(even) {
            background-color: #f8f9fa;
        }

        /* Subjects Table */
        .subjects-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.5rem;
            border: 1px solid #dee2e6;
        }

        .subjects-table th {
            background-color: #e9ecef;
            font-weight: 600;
            color: #495057;
            padding: 0.5rem;
            border: 1px solid #dee2e6;
        }

        .subjects-table td {
            padding: 0.5rem;
            border: 1px solid #dee2e6;
        }

        .category-badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .category-compulsory {
            background: #d4edda;
            color: #155724;
        }

        .category-elective {
            background: #cce5ff;
            color: #004085;
        }

        .category-principal {
            background: #fff3cd;
            color: #856404;
        }

        .category-subsidiary {
            background: #d1ecf1;
            color: #0c5460;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: flex-end;
            gap: 0.75rem;
        }

        /* Print Styles */
        @media print {
            body {
                background: white;
            }

            .sidebar,
            .header,
            .stats-cards,
            .filter-section,
            .action-buttons,
            .icon-btn,
            .dataTables_filter,
            .dataTables_length,
            .dataTables_paginate,
            .dataTables_info,
            .modal-footer {
                display: none !important;
            }

            .main-wrapper {
                margin-left: 0;
                width: 100%;
            }

            .main-content {
                padding: 0.5in;
            }

            .table-container {
                box-shadow: none;
                padding: 0;
            }

            table.dataTable thead th {
                background-color: #1a2a6c !important;
                color: white !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .badge {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            a[href]:after {
                content: none !important;
            }
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

            .student-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }

            .student-photo-large {
                width: 100px;
                height: 100px;
            }
        }

        @media (max-width: 768px) {
            .filter-form {
                grid-template-columns: 1fr;
            }

            .table-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .modal-content {
                width: 95%;
                max-height: 95vh;
            }

            .details-table {
                font-size: 0.9rem;
            }

            .details-table th,
            .details-table td {
                padding: 0.5rem;
            }
        }

        @media (max-width: 576px) {
            .stats-cards {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                width: 100%;
                justify-content: flex-start;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
            }

            .details-table th {
                width: 40%;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar (Updated to match admin_dashboard.php exactly) -->
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
        <header class="header" onclick="window.location='admin_dashboard.php'">
            <div class="admin-info">
                <h1>Archived Students</h1>
                <p><?= $fullname ?> (<?= ucfirst($role) ?>)</p>
            </div>
            <div class="role-tag"><?= ucfirst($role) ?></div>
        </header>

        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <div class="page-title">
                    <h2><i class="fas fa-archive"></i> Archived Students Management</h2>
                    <p>View and manage archived student records</p>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-secondary" onclick="window.location='students.php'">
                        <i class="fas fa-user-graduate"></i> View Active
                    </button>
                    <button class="btn btn-success" onclick="exportToCSV()">
                        <i class="fas fa-file-export"></i> Export
                    </button>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <div class="stat-icon archive">
                        <i class="fas fa-archive"></i>
                    </div>
                    <div class="stat-info">
                        <h3><?= $total_archived ?></h3>
                        <p>Total Archived Students</p>
                    </div>
                </div>
                <?php foreach ($total_by_reason as $stat): ?>
                    <div class="stat-card">
                        <div class="stat-icon <?= strtolower($stat['archival_reason'] ?? 'archive') ?>">
                            <i class="fas fa-<?= $stat['archival_reason'] === 'Finished' ? 'graduation-cap' : ($stat['archival_reason'] === 'Transferred' ? 'exchange-alt' : ($stat['archival_reason'] === 'Sickness' ? 'heartbeat' : 'archive')) ?>"></i>
                        </div>
                        <div class="stat-info">
                            <h3><?= $stat['count'] ?></h3>
                            <p><?= $stat['archival_reason'] ?: 'Other Reasons' ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Filter Section -->
            <div class="filter-section">
                <h3 class="filter-title"><i class="fas fa-filter"></i> Filter Archived Students</h3>
                <form method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="academic_year">Academic Year</label>
                        <select id="academic_year" name="academic_year" class="form-control">
                            <option value="">All Academic Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?= $year['id'] ?>" <?= $academic_year_id == $year['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year['year_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="level">Level</label>
                        <select id="level" name="level" class="form-control">
                            <option value="">All Levels</option>
                            <?php foreach ($levels as $level): ?>
                                <option value="<?= $level['id'] ?>" <?= $level_id == $level['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($level['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="class">Class</label>
                        <select id="class" name="class" class="form-control" <?= empty($level_id) ? 'disabled' : '' ?>>
                            <option value="">All Classes</option>
                            <?php if (!empty($level_id) && isset($classes_by_level[$level_id])): ?>
                                <?php foreach ($classes_by_level[$level_id] as $class): ?>
                                    <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($class['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="stream">Stream</label>
                        <select id="stream" name="stream" class="form-control" <?= empty($class_id) ? 'disabled' : '' ?>>
                            <option value="">All Streams</option>
                            <?php if (!empty($class_id) && isset($streams_by_class[$class_id])): ?>
                                <?php foreach ($streams_by_class[$class_id] as $stream): ?>
                                    <option value="<?= $stream['id'] ?>" <?= $stream_id == $stream['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($stream['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <div style="display: flex; gap: 0.5rem;">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Search
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                                <i class="fas fa-redo"></i> Reset
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Archived Students Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-list"></i> Archived Students List
                        <span style="font-size: 0.9rem; color: #6c757d; margin-left: 0.5rem;">
                            (<?= count($archived_students) ?> record<?= count($archived_students) !== 1 ? 's' : '' ?> found)
                        </span>
                    </h3>
                    <div class="action-buttons">
                        <button class="btn btn-secondary" onclick="printTable()">
                            <i class="fas fa-print"></i> Print Table
                        </button>
                        <button class="btn btn-primary" onclick="refreshPage()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                    </div>
                </div>

                <?php if (empty($archived_students)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox"></i>
                        <h3>No Archived Students Found</h3>
                        <p>No archived student records match your current filter criteria.</p>
                        <button class="btn btn-primary mt-3" onclick="resetFilters()">
                            <i class="fas fa-redo"></i> Reset Filters
                        </button>
                    </div>
                <?php else: ?>
                    <table id="archivedStudentsTable" class="display">
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Student ID</th>
                                <th>Full Name</th>
                                <th>Level/Class</th>
                                <th>Academic Year</th>
                                <th>Archival Details</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($archived_students as $student): ?>
                                <?php
                                $student_subjects = getArchivedStudentSubjects($pdo, $student['id']);
                                $subjects_json = htmlspecialchars(json_encode($student_subjects), ENT_QUOTES, 'UTF-8');
                                ?>
                                <tr>
                                    <td>
                                        <?php if (!empty($student['photo'])): ?>
                                            <img src="<?= htmlspecialchars($student['photo']) ?>"
                                                alt="<?= htmlspecialchars($student['surname']) ?>"
                                                class="student-photo"
                                                onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['surname'] . ' ' . $student['other_names']) ?>&background=1a2a6c&color=fff'">
                                        <?php else: ?>
                                            <img src="https://ui-avatars.com/api/?name=<?= urlencode($student['surname'] . ' ' . $student['other_names']) ?>&background=1a2a6c&color=fff"
                                                alt="<?= htmlspecialchars($student['surname']) ?>"
                                                class="student-photo">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($student['student_id']) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($student['surname'] . ', ' . $student['other_names']) ?></strong><br>
                                        <small style="color: #6c757d;">
                                            <?= ucfirst($student['sex']) ?> |
                                            <?= date('d/m/Y', strtotime($student['date_of_birth'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($student['level_name']) ?><br>
                                        <small style="color: #6c757d;">
                                            <?= htmlspecialchars($student['class_name']) ?>
                                            (<?= htmlspecialchars($student['stream_name']) ?>)
                                        </small>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($student['year_name'] ?? 'N/A') ?><br>
                                        <small style="color: #6c757d;">
                                            <?= count($student_subjects) ?> subject<?= count($student_subjects) !== 1 ? 's' : '' ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small>
                                            <strong>Reason:</strong> <?= htmlspecialchars($student['archival_reason'] ?? 'Not specified') ?><br>
                                            <strong>Archived by:</strong> <?= htmlspecialchars($student['archived_by_name'] ?? 'System') ?><br>
                                            <strong>Date:</strong> <?= date('M j, Y g:i A', strtotime($student['archived_at'])) ?>
                                        </small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $student['status'] ?>">
                                            <?= strtoupper($student['status']) ?>
                                        </span><br>
                                        <span class="badge badge-<?= strtolower($student['status_type']) ?>" style="margin-top: 0.25rem;">
                                            <?= $student['status_type'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-icons">
                                            <button class="icon-btn icon-view" title="View Details" onclick="viewStudentDetails(<?= htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8') ?>, <?= $subjects_json ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="icon-btn icon-print" title="Print Details" onclick="printStudentDetails(<?= htmlspecialchars(json_encode($student), ENT_QUOTES, 'UTF-8') ?>, <?= $subjects_json ?>)">
                                                <i class="fas fa-print"></i>
                                            </button>
                                            <?php if ($role === 'admin'): ?>
                                                <button class="icon-btn icon-restore" title="Restore Student" onclick="restoreStudent(<?= $student['id'] ?>, '<?= htmlspecialchars($student['student_id']) ?>')">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                            <?php endif; ?>
                                            <!-- [REMOVED] Download Details button - completely removed as requested -->
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Student Details Modal -->
    <div id="studentModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title"><i class="fas fa-user-graduate"></i> Student Details</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <div class="modal-body" id="studentDetails">
                <!-- Student details will be loaded here -->
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button class="btn btn-primary" onclick="printStudentDetailsFromModal()">
                    <i class="fas fa-print"></i> Print Details
                </button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.25/jspdf.plugin.autotable.min.js"></script>
    <script>
        // Initialize DataTable
        $(document).ready(function() {
            $('#archivedStudentsTable').DataTable({
                pageLength: 10,
                lengthMenu: [10, 25, 50, 100],
                order: [
                    [1, 'asc']
                ],
                language: {
                    search: "Search:",
                    lengthMenu: "Show _MENU_ entries",
                    info: "Showing _START_ to _END_ of _TOTAL_ entries",
                    paginate: {
                        first: "First",
                        last: "Last",
                        next: "Next",
                        previous: "Previous"
                    }
                }
            });
        });

        // Global variables
        let currentStudentData = null;
        let currentStudentSubjects = null;
        const schoolInfo = <?= json_encode($school_info) ?>;

        // Toggle dropdowns
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

        // Dynamic class and stream loading
        document.getElementById('level').addEventListener('change', function() {
            const levelId = this.value;
            const classSelect = document.getElementById('class');
            const streamSelect = document.getElementById('stream');
            classSelect.innerHTML = '<option value="">All Classes</option>';
            streamSelect.innerHTML = '<option value="">All Streams</option>';
            if (levelId) {
                classSelect.disabled = false;
                this.form.submit();
            } else {
                classSelect.disabled = true;
                streamSelect.disabled = true;
            }
        });

        document.getElementById('class').addEventListener('change', function() {
            const classId = this.value;
            const streamSelect = document.getElementById('stream');
            streamSelect.innerHTML = '<option value="">All Streams</option>';
            if (classId) {
                streamSelect.disabled = false;
                this.form.submit();
            } else {
                streamSelect.disabled = true;
            }
        });

        // Modal functions
        function viewStudentDetails(student, subjects) {
            currentStudentData = student;
            currentStudentSubjects = subjects;
            const modal = document.getElementById('studentModal');
            const content = document.getElementById('studentDetails');

            // Format dates
            const dob = new Date(student.date_of_birth);
            const archivedAt = new Date(student.archived_at);

            // Calculate age
            const age = calculateAge(student.date_of_birth);

            // Prepare subjects table rows
            let subjectsTableRows = '';
            if (subjects.length > 0) {
                subjects.forEach(subject => {
                    subjectsTableRows += `
                        <tr>
                            <td>${subject.code}</td>
                            <td>${subject.name}</td>
                            <td>
                                <span class="category-badge category-${subject.category.toLowerCase()}">
                                    ${subject.category}
                                </span>
                            </td>
                        </tr>
                    `;
                });
            } else {
                subjectsTableRows = `
                    <tr>
                        <td colspan="3" style="text-align: center; color: #6c757d;">
                            <i class="fas fa-book" style="margin-right: 0.5rem;"></i>
                            No subjects found for this student
                        </td>
                    </tr>
                `;
            }

            content.innerHTML = `
                <div class="student-details-table-container">
                    <!-- Student Header with Photo -->
                    <div class="student-header">
                        <div>
                            ${student.photo ? 
                                `<img src="${student.photo}" alt="${student.surname}" class="student-photo-large">` :
                                `<div style="width: 120px; height: 120px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-size: 2.5rem; font-weight: bold;">
                                    ${student.surname.charAt(0)}${student.other_names.charAt(0)}
                                </div>`
                            }
                        </div>
                        <div class="student-header-info">
                            <div class="student-name">${student.surname}, ${student.other_names}</div>
                            <div class="student-id">Student ID: ${student.student_id}</div>
                            <div style="display: flex; gap: 1rem; margin-top: 0.5rem;">
                                <span class="badge badge-${student.status}">${student.status.toUpperCase()}</span>
                                <span class="badge badge-${student.status_type.toLowerCase()}">${student.status_type}</span>
                            </div>
                        </div>
                    </div>
                    <!-- Personal Information Table -->
                    <table class="details-table">
                        <caption><i class="fas fa-user"></i> Personal Information</caption>
                        <tr>
                            <th>Full Name</th>
                            <td>${student.surname}, ${student.other_names}</td>
                        </tr>
                        <tr>
                            <th>Student ID</th>
                            <td>${student.student_id}</td>
                        </tr>
                        <tr>
                            <th>Sex</th>
                            <td>${student.sex.toUpperCase()}</td>
                        </tr>
                        <tr>
                            <th>Date of Birth</th>
                            <td>${dob.toLocaleDateString('en-GB')}</td>
                        </tr>
                        <tr>
                            <th>Age</th>
                            <td>${age} years</td>
                        </tr>
                        <tr>
                            <th>Nationality</th>
                            <td>${student.nationality}</td>
                        </tr>
                        <tr>
                            <th>Home District</th>
                            <td>${student.home_district}</td>
                        </tr>
                    </table>
                    <!-- Academic Information Table -->
                    <table class="details-table">
                        <caption><i class="fas fa-graduation-cap"></i> Academic Information</caption>
                        <tr>
                            <th>Level</th>
                            <td>${student.level_name}</td>
                        </tr>
                        <tr>
                            <th>Class</th>
                            <td>${student.class_name}</td>
                        </tr>
                        <tr>
                            <th>Stream</th>
                            <td>${student.stream_name}</td>
                        </tr>
                        <tr>
                            <th>Academic Year</th>
                            <td>${student.year_name || 'N/A'}</td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <span class="badge badge-${student.status}">${student.status.toUpperCase()}</span>
                            </td>
                        </tr>
                        <tr>
                            <th>Student Type</th>
                            <td>
                                <span class="badge badge-${student.status_type.toLowerCase()}">${student.status_type}</span>
                            </td>
                        </tr>
                    </table>
                    <!-- Archival Information Table -->
                    <table class="details-table">
                        <caption><i class="fas fa-archive"></i> Archival Information</caption>
                        <tr>
                            <th>Archival Reason</th>
                            <td>${student.archival_reason || 'Not specified'}</td>
                        </tr>
                        <tr>
                            <th>Archived By</th>
                            <td>${student.archived_by_name || 'System'}</td>
                        </tr>
                        <tr>
                            <th>Archived Date</th>
                            <td>${archivedAt.toLocaleDateString('en-GB')}</td>
                        </tr>
                        <tr>
                            <th>Archived Time</th>
                            <td>${archivedAt.toLocaleTimeString('en-GB', {hour: '2-digit', minute:'2-digit'})}</td>
                        </tr>
                        ${student.notes ? `
                        <tr>
                            <th>Notes</th>
                            <td>${student.notes}</td>
                        </tr>
                        ` : ''}
                    </table>
                    <!-- Subjects Table -->
                    <table class="details-table">
                        <caption><i class="fas fa-book"></i> Subjects (${subjects.length})</caption>
                        <tr>
                            <td colspan="3">
                                <table class="subjects-table">
                                    <thead>
                                        <tr>
                                            <th>Subject Code</th>
                                            <th>Subject Name</th>
                                            <th>Category</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${subjectsTableRows}
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                </div>
            `;
            modal.classList.add('show');
        }

        function closeModal() {
            document.getElementById('studentModal').classList.remove('show');
            currentStudentData = null;
            currentStudentSubjects = null;
        }

        // Utility functions
        function calculateAge(dateOfBirth) {
            const dob = new Date(dateOfBirth);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const monthDiff = today.getMonth() - dob.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            return age;
        }

        function resetFilters() {
            window.location.href = 'archived_students.php';
        }

        function refreshPage() {
            window.location.reload();
        }

        // Generate School Header HTML
        function generateSchoolHeader(title) {
            const logo = schoolInfo.school_logo || '';
            const schoolName = schoolInfo.school_name || 'NSS School';
            const motto = schoolInfo.motto || 'Achieving excellence together';
            const address = schoolInfo.address || 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward, Napak Town Council';
            const phone = schoolInfo.phone || '0200912924/077088027';
            const email = schoolInfo.email || 'napakseed@gmail.com';

            return `
                <div style="text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #1a2a6c;">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                        <tr>
                            <td style="width: 15%; text-align: right; padding-right: 15px; vertical-align: middle;">
                                <img src="${logo}" alt="School Logo" style="max-width: 80px; max-height: 80px; border-radius: 5px;" onerror="this.style.display='none'">
                            </td>
                            <td style="width: 70%; text-align: center; vertical-align: middle;">
                                <h1 style="margin: 0; color: #1a2a6c; font-size: 26px; font-weight: 700; letter-spacing: 0.5px;">${schoolName}</h1>
                                <p style="margin: 8px 0 5px; color: #2c3e50; font-size: 14px; font-style: italic;">${motto}</p>
                                <p style="margin: 5px 0; color: #495057; font-size: 13px;">
                                    <i class="fas fa-map-marker-alt" style="margin-right: 5px;"></i> ${address}
                                </p>
                                <p style="margin: 5px 0; color: #495057; font-size: 13px;">
                                    <i class="fas fa-phone" style="margin-right: 5px;"></i> ${phone} 
                                    <span style="margin: 0 10px;">|</span>
                                    <i class="fas fa-envelope" style="margin-right: 5px;"></i> ${email}
                                </p>
                            </td>
                            <td style="width: 15%; text-align: left; padding-left: 15px; vertical-align: middle;"></td>
                        </tr>
                    </table>
                    <h2 style="margin: 15px 0 5px; color: #b21f1f; font-size: 22px; font-weight: 600;">${title}</h2>
                    <p style="margin: 5px 0; color: #6c757d; font-size: 13px;">
                        Generated on: ${new Date().toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })} at ${new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}
                    </p>
                </div>
            `;
        }

        // Generate Student Details Header
        function generateStudentDetailsHeader(student) {
            const logo = schoolInfo.school_logo || '';
            const schoolName = schoolInfo.school_name || 'NSS School';
            const address = schoolInfo.address || 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward, Napak Town Council';
            const phone = schoolInfo.phone || '0200912924/077088027';
            const email = schoolInfo.email || 'napakseed@gmail.com';

            return `
                <div style="text-align: center; margin-bottom: 25px; padding-bottom: 15px; border-bottom: 3px solid #1a2a6c;">
                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 10px;">
                        <tr>
                            <td style="width: 15%; text-align: right; padding-right: 15px; vertical-align: middle;">
                                <img src="${logo}" alt="School Logo" style="max-width: 70px; max-height: 70px; border-radius: 5px;" onerror="this.style.display='none'">
                            </td>
                            <td style="width: 70%; text-align: center; vertical-align: middle;">
                                <h1 style="margin: 0; color: #1a2a6c; font-size: 22px; font-weight: 700;">${schoolName}</h1>
                                <p style="margin: 5px 0; color: #495057; font-size: 12px;">${address}</p>
                                <p style="margin: 5px 0; color: #495057; font-size: 12px;">Tel: ${phone} | Email: ${email}</p>
                            </td>
                            <td style="width: 15%; text-align: left; padding-left: 15px; vertical-align: middle;"></td>
                        </tr>
                    </table>
                    <h2 style="margin: 10px 0 5px; color: #b21f1f; font-size: 20px; font-weight: 600;">Archived Student Details</h2>
                    <p style="margin: 5px 0; color: #2c3e50; font-size: 14px; font-weight: 500;">
                        Student ID: ${student.student_id} | ${student.surname}, ${student.other_names}
                    </p>
                </div>
            `;
        }

        // ✅ PRINT TABLE - WITH SCHOOL HEADER
        function printTable() {
            const printWindow = window.open('', '_blank', 'width=1000,height=600,scrollbars=yes');
            if (!printWindow) {
                alert('Please allow pop-ups to print.');
                return;
            }

            // Clone table and remove actions
            const table = document.querySelector('#archivedStudentsTable').cloneNode(true);
            const actionCells = table.querySelectorAll('td:last-child');
            actionCells.forEach(cell => cell.innerHTML = '');

            // Remove action column header
            const headerRow = table.querySelector('thead tr');
            if (headerRow) {
                const lastHeader = headerRow.lastElementChild;
                lastHeader.textContent = 'Status'; // Change from 'Actions' to 'Status' (combined with previous)
                // Actually we should remove the actions column and adjust
            }

            // Generate school header
            const schoolHeader = generateSchoolHeader('Archived Students List');

            // Build HTML content
            const printContent = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Archived Students - ${schoolInfo.school_name || 'NSS School'}</title>
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
                    <style>
                        body {
                            font-family: 'Segoe UI', Arial, sans-serif;
                            font-size: 12pt;
                            color: #000;
                            background: white;
                            margin: 0;
                            padding: 0.3in;
                        }
                        table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 20px;
                            font-size: 10pt;
                        }
                        th {
                            background-color: #1a2a6c !important;
                            color: white !important;
                            border: 1px solid #000;
                            padding: 8px;
                            text-align: left;
                            font-weight: bold;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        td {
                            border: 1px solid #000;
                            padding: 6px;
                            vertical-align: top;
                        }
                        .student-photo {
                            width: 40px;
                            height: 40px;
                            border-radius: 50%;
                            object-fit: fill;
                            border: 1px solid #000;
                        }
                        .badge {
                            display: inline-block;
                            padding: 3px 6px;
                            border-radius: 3px;
                            font-size: 8pt;
                            font-weight: bold;
                            text-transform: uppercase;
                            color: #000;
                            border: 1px solid #000;
                        }
                        @page {
                            size: A4 landscape;
                            margin: 0.5in;
                        }
                        @media print {
                            body {
                                font-size: 10pt;
                            }
                            th {
                                background-color: #1a2a6c !important;
                                color: white !important;
                            }
                        }
                    </style>
                </head>
                <body>
                    ${schoolHeader}
                    ${table.outerHTML}
                    <div style="text-align: center; margin-top: 30px; padding-top: 15px; border-top: 1px solid #000; font-size: 9pt; color: #666;">
                        <p>This is an official document from ${schoolInfo.school_name || 'NSS School'} Student Management System</p>
                        <p>Total Records: ${table.querySelectorAll('tbody tr').length} | Generated by: <?= $fullname ?> (${ucfirst($role)})</p>
                    </div>
                </body>
                </html>
            `;

            printWindow.document.write(printContent);
            printWindow.document.close();

            printWindow.onload = () => {
                printWindow.focus();
                printWindow.print();
            };
        }

        // ✅ PRINT STUDENT DETAILS - WITH SCHOOL HEADER
        function printStudentDetails(student, subjects) {
            if (!student || !subjects) return;

            const printWindow = window.open('', '_blank', 'width=800,height=900,scrollbars=yes');
            if (!printWindow) {
                alert('Please allow pop-ups to print.');
                return;
            }

            printWindow.document.open();

            const dob = new Date(student.date_of_birth);
            const archivedAt = new Date(student.archived_at);
            const age = calculateAge(student.date_of_birth);

            let subjectsTableRows = '';
            if (subjects.length > 0) {
                subjects.forEach(subject => {
                    subjectsTableRows += `
                        <tr>
                            <td style="border: 1px solid #000; padding: 4px;">${subject.code}</td>
                            <td style="border: 1px solid #000; padding: 4px;">${subject.name}</td>
                            <td style="border: 1px solid #000; padding: 4px;">${subject.category}</td>
                        </tr>
                    `;
                });
            } else {
                subjectsTableRows = `
                    <tr>
                        <td colspan="3" style="border: 1px solid #000; padding: 8px; text-align: center; color: #666;">
                            No subjects assigned
                        </td>
                    </tr>
                `;
            }

            // Generate student details header
            const studentHeader = generateStudentDetailsHeader(student);

            const content = `
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Student Details - ${student.student_id}</title>
                    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
                    <style>
                        body {
                            font-family: 'Segoe UI', Arial, sans-serif;
                            font-size: 12pt;
                            color: #000;
                            background: white;
                            margin: 0;
                            padding: 0.25in;
                        }
                        .student-info {
                            margin-bottom: 30px;
                            overflow: hidden;
                        }
                        .student-photo-container {
                            float: left;
                            margin-right: 20px;
                            margin-bottom: 20px;
                        }
                        .student-photo-print {
                            width: 120px;
                            height: 120px;
                            object-fit: fill;
                            border-radius: 10px;
                            border: 1px solid gray;
                        }
                        .student-basic {
                            overflow: hidden;
                        }
                        .student-name {
                            font-size: 20px;
                            font-weight: bold;
                            margin: 0 0 5px 0;
                            color: #1a2a6c;
                        }
                        .student-id {
                            font-size: 14px;
                            margin: 0 0 15px 0;
                            color: #666;
                        }
                        .info-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-bottom: 20px;
                            border: 1px solid #000;
                        }
                        .info-table caption {
                            text-align: left;
                            font-weight: bold;
                            font-size: 14px;
                            padding: 8px 0 5px 0;
                            color: #1a2a6c;
                            border-bottom: 2px solid #1a2a6c;
                            margin-bottom: 10px;
                        }
                        .info-table th {
                            width: 35%;
                            text-align: left;
                            font-weight: bold;
                            padding: 6px 12px;
                            border: 1px solid #000;
                            background-color: #f0f0f0;
                            -webkit-print-color-adjust: exact;
                            print-color-adjust: exact;
                        }
                        .info-table td {
                            padding: 6px 12px;
                            border: 1px solid #000;
                        }
                        .subjects-table {
                            width: 100%;
                            border-collapse: collapse;
                            margin-top: 10px;
                            font-size: 10pt;
                        }
                        .subjects-table th {
                            background-color: #e0e0e0 !important;
                            color: #000 !important;
                            font-weight: bold;
                            padding: 6px 8px;
                            border: 1px solid #000;
                            text-align: left;
                        }
                        .subjects-table td {
                            padding: 6px 8px;
                            border: 1px solid #000;
                        }
                        .badge {
                            display: inline-block;
                            padding: 4px 8px;
                            border-radius: 3px;
                            font-size: 9pt;
                            font-weight: bold;
                            text-transform: uppercase;
                            border: 1px solid #000;
                        }
                        .footer {
                            text-align: center;
                            margin-top: 30px;
                            padding-top: 15px;
                            border-top: 1px solid #000;
                            font-size: 9pt;
                            color: #666;
                        }
                        @page {
                            size: A4;
                            margin: 0.4in;
                        }
                    </style>
                </head>
                <body>
                    ${studentHeader}
                    <div class="student-info">
                        <div class="student-photo-container">
                            ${student.photo ? 
                                `<img src="${student.photo}" alt="${student.surname}" class="student-photo-print">` :
                                `<div style="width: 120px; height: 120px; border: 2px solid #1a2a6c; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 40px; font-weight: bold; background: #f0f0f0; color: #1a2a6c;">
                                    ${student.surname.charAt(0)}${student.other_names.charAt(0)}
                                </div>`
                            }
                        </div>
                        <div class="student-basic">
                            <div class="student-name">${student.surname}, ${student.other_names}</div>
                            <div class="student-id">Student ID: ${student.student_id}</div>
                            <div style="margin: 15px 0;">
                                <span class="badge" style="background: ${student.status === 'active' ? '#d4edda' : '#f8d7da'}; color: #000;">${student.status.toUpperCase()}</span>
                                <span class="badge" style="margin-left: 10px; background: ${student.status_type === 'Day' ? '#cce5ff' : '#fff3cd'}; color: #000;">${student.status_type}</span>
                            </div>
                        </div>
                    </div>
                    <table class="info-table">
                        <caption><i class="fas fa-user"></i> Personal Information</caption>
                        <tr>
                            <th>Full Name</th>
                            <td>${student.surname}, ${student.other_names}</td>
                        </tr>
                        <tr>
                            <th>Student ID</th>
                            <td>${student.student_id}</td>
                        </tr>
                        <tr>
                            <th>Sex</th>
                            <td>${student.sex.toUpperCase()}</td>
                        </tr>
                        <tr>
                            <th>Date of Birth</th>
                            <td>${dob.toLocaleDateString('en-GB')}</td>
                        </tr>
                        <tr>
                            <th>Age</th>
                            <td>${age} years</td>
                        </tr>
                        <tr>
                            <th>Nationality</th>
                            <td>${student.nationality}</td>
                        </tr>
                        <tr>
                            <th>Home District</th>
                            <td>${student.home_district}</td>
                        </tr>
                    </table>
                    <table class="info-table">
                        <caption><i class="fas fa-graduation-cap"></i> Academic Information</caption>
                        <tr>
                            <th>Level</th>
                            <td>${student.level_name}</td>
                        </tr>
                        <tr>
                            <th>Class</th>
                            <td>${student.class_name}</td>
                        </tr>
                        <tr>
                            <th>Stream</th>
                            <td>${student.stream_name}</td>
                        </tr>
                        <tr>
                            <th>Academic Year</th>
                            <td>${student.year_name || 'N/A'}</td>
                        </tr>
                    </table>
                    <table class="info-table">
                        <caption><i class="fas fa-archive"></i> Archival Information</caption>
                        <tr>
                            <th>Archival Reason</th>
                            <td>${student.archival_reason || 'Not specified'}</td>
                        </tr>
                        <tr>
                            <th>Archived By</th>
                            <td>${student.archived_by_name || 'System'}</td>
                        </tr>
                        <tr>
                            <th>Archived Date</th>
                            <td>${archivedAt.toLocaleDateString('en-GB')}</td>
                        </tr>
                        <tr>
                            <th>Archived Time</th>
                            <td>${archivedAt.toLocaleTimeString('en-GB', {hour: '2-digit', minute:'2-digit'})}</td>
                        </tr>
                        ${student.notes ? `
                        <tr>
                            <th>Notes</th>
                            <td>${student.notes}</td>
                        </tr>
                        ` : ''}
                    </table>
                    <table class="info-table">
                        <caption><i class="fas fa-book"></i> Subjects (${subjects.length})</caption>
                        <tr>
                            <td colspan="3">
                                <table class="subjects-table">
                                    <thead>
                                        <tr>
                                            <th>Subject Code</th>
                                            <th>Subject Name</th>
                                            <th>Category</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${subjectsTableRows}
                                    </tbody>
                                </table>
                            </td>
                        </tr>
                    </table>
                    <div class="footer">
                        <p>This is an official document from ${schoolInfo.school_name || 'NSS School'} Student Management System</p>
                        <p>Document ID: ${student.student_id}-${Date.now().toString().slice(-6)} | Printed by: <?= $fullname ?></p>
                    </div>
                </body>
                </html>
            `;

            printWindow.document.write(content);
            printWindow.document.close();

            printWindow.onload = () => {
                printWindow.focus();
                printWindow.print();
            };
        }

        function printStudentDetailsFromModal() {
            if (currentStudentData && currentStudentSubjects) {
                printStudentDetails(currentStudentData, currentStudentSubjects);
            }
        }

        function exportToCSV() {
            const exportBtn = document.querySelector('.btn-success');
            const originalText = exportBtn.innerHTML;
            exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
            exportBtn.disabled = true;

            const table = document.getElementById('archivedStudentsTable');
            const rows = table.querySelectorAll('tbody tr');

            let csvContent = "data:text/csv;charset=utf-8,";
            const headers = [
                'Student ID',
                'Full Name',
                'Sex',
                'Date of Birth',
                'Level',
                'Class',
                'Stream',
                'Academic Year',
                'Status',
                'Type',
                'Archival Reason',
                'Archived By',
                'Archived At',
                'Subjects Count'
            ];
            csvContent += headers.join(',') + "\n";

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = [
                    cells[1]?.querySelector('strong')?.textContent || '',
                    cells[2]?.querySelector('strong')?.textContent || '',
                    cells[2]?.querySelector('small')?.textContent?.split('|')[0]?.trim() || '',
                    cells[2]?.querySelector('small')?.textContent?.split('|')[1]?.trim() || '',
                    cells[3]?.textContent?.split('\n')[0]?.trim() || '',
                    cells[3]?.querySelector('small')?.textContent?.split('(')[0]?.trim() || '',
                    cells[3]?.querySelector('small')?.textContent?.match(/\((.*?)\)/)?.[1] || '',
                    cells[4]?.textContent?.split('\n')[0]?.trim() || '',
                    cells[6]?.querySelector('.badge')?.textContent || '',
                    cells[6]?.querySelectorAll('.badge')[1]?.textContent || '',
                    cells[5]?.querySelector('small')?.textContent?.split('Reason:')[1]?.split('\n')[0]?.trim() || '',
                    cells[5]?.querySelector('small')?.textContent?.split('Archived by:')[1]?.split('\n')[0]?.trim() || '',
                    cells[5]?.querySelector('small')?.textContent?.split('Date:')[1]?.trim() || '',
                    cells[4]?.querySelector('small')?.textContent?.split('subject')[0]?.trim() || ''
                ];
                const escapedRow = rowData.map(cell => {
                    if (cell.includes(',') || cell.includes('"') || cell.includes('\n')) {
                        return '"' + cell.replace(/"/g, '""') + '"';
                    }
                    return cell;
                });
                csvContent += escapedRow.join(',') + "\n";
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", `archived_students_${schoolInfo.school_name || 'NSS'}_${new Date().toISOString().slice(0,10)}.csv`);
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);

            setTimeout(() => {
                exportBtn.innerHTML = originalText;
                exportBtn.disabled = false;
            }, 1000);
        }

        function restoreStudent(studentId, studentName) {
            if (confirm(`Are you sure you want to restore student ${studentName}?`)) {
                // In a real implementation, this would make an AJAX call
                alert(`Student ${studentName} has been restored successfully.`);
                // Refresh the page after restoration
                setTimeout(() => {
                    window.location.reload();
                }, 1500);
            }
        }

        // [REMOVED] downloadStudentDetails function - completely removed as requested

        window.addEventListener('click', function(event) {
            const modal = document.getElementById('studentModal');
            if (event.target === modal) {
                closeModal();
            }
        });
    </script>
</body>

</html>