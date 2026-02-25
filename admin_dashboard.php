<?php
require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch admin info
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

// Fetch school name for footer
$school_name = "School Management System"; // Default value
try {
    $stmt = $pdo->prepare("SELECT school_name FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($school_info && !empty($school_info['school_name'])) {
        $school_name = htmlspecialchars($school_info['school_name']);
    }
} catch (Exception $e) {
    // Use default if query fails
    error_log("Error fetching school info: " . $e->getMessage());
}

// Fetch all statistics from stats.php
function fetchStats($pdo)
{
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/stats.php');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);


        if ($response) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['total_students'])) {
                return $data;
            }
        }
    } catch (Exception $e) {
        // Fallback to direct database queries
    }

    // Fallback: Direct database queries if stats.php fails
    return getFallbackStats($pdo);
}

function getFallbackStats($pdo)
{
    $stats = [];

    // Helper function for direct counting
    function directCount($pdo, $table, $condition = "1=1")
    {
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $condition");
            $stmt->execute();
            return (int)$stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    // Student statistics
    $stats['total_students'] = directCount($pdo, 'students', "status = 'active'");
    $stats['male_students'] = directCount($pdo, 'students', "status = 'active' AND sex = 'male'");
    $stats['female_students'] = directCount($pdo, 'students', "status = 'active' AND sex = 'female'");
    $stats['day_students'] = directCount($pdo, 'students', "status = 'active' AND status_type = 'Day'");
    $stats['boarding_students'] = directCount($pdo, 'students', "status = 'active' AND status_type = 'Boarding'");
    $stats['o_level_students'] = 0;
    $stats['a_level_students'] = 0;

    // Level-based counts
    try {
        $stmt = $pdo->prepare("
            SELECT l.name, COUNT(s.id) as count 
            FROM students s 
            JOIN levels l ON s.level_id = l.id 
            WHERE s.status = 'active' 
            GROUP BY l.name
        ");
        $stmt->execute();
        $levelCounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($levelCounts as $level) {
            if (stripos($level['name'], 'O Level') !== false || $level['name'] === 'O Level') {
                $stats['o_level_students'] = (int)$level['count'];
            } elseif (stripos($level['name'], 'A Level') !== false || $level['name'] === 'A Level') {
                $stats['a_level_students'] = (int)$level['count'];
            }
        }
    } catch (PDOException $e) {
        // Ignore error
    }

    // Other statistics
    $stats['total_teachers'] = directCount($pdo, 'users', "role = 'teacher' AND status = 'active'");
    $stats['total_classes'] = directCount($pdo, 'classes');
    $stats['total_streams'] = directCount($pdo, 'streams');
    $stats['total_levels'] = directCount($pdo, 'levels');
    $stats['total_subjects'] = directCount($pdo, 'subjects');
    $stats['archived_students'] = directCount($pdo, 'archived_students');
    $stats['recent_students'] = directCount($pdo, 'students', "status = 'active' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");

    // Calculate percentages
    $total = $stats['total_students'];
    $stats['male_percentage'] = $total > 0 ? round(($stats['male_students'] / $total) * 100, 1) : 0;
    $stats['female_percentage'] = $total > 0 ? round(($stats['female_students'] / $total) * 100, 1) : 0;

    return $stats;
}

// Get distribution data for classes and streams by gender
function getClassStreamDistribution($pdo)
{
    $distribution = [
        'by_class' => [],
        'by_stream' => []
    ];

    try {
        // Distribution by class with gender breakdown
        $stmt = $pdo->prepare("
            SELECT 
                c.name as class_name,
                l.name as level_name,
                COUNT(s.id) as total,
                SUM(CASE WHEN s.sex = 'male' THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN s.sex = 'female' THEN 1 ELSE 0 END) as female_count
            FROM students s
            JOIN classes c ON s.class_id = c.id
            JOIN levels l ON s.level_id = l.id
            WHERE s.status = 'active'
            GROUP BY c.id, c.name, l.name
            ORDER BY l.name, c.name
        ");
        $stmt->execute();
        $distribution['by_class'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Distribution by stream with gender breakdown
        $stmt = $pdo->prepare("
            SELECT 
                st.name as stream_name,
                c.name as class_name,
                COUNT(s.id) as total,
                SUM(CASE WHEN s.sex = 'male' THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN s.sex = 'female' THEN 1 ELSE 0 END) as female_count
            FROM students s
            JOIN streams st ON s.stream_id = st.id
            JOIN classes c ON s.class_id = c.id
            WHERE s.status = 'active'
            GROUP BY st.id, st.name, c.name
            ORDER BY c.name, st.name
        ");
        $stmt->execute();
        $distribution['by_stream'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching distribution data: " . $e->getMessage());
    }

    return $distribution;
}

// Get all statistics
$stats = fetchStats($pdo);
$distribution = getClassStreamDistribution($pdo);

// Extract statistics for easy access
$total_students = $stats['total_students'] ?? 0;
$male_students = $stats['male_students'] ?? 0;
$female_students = $stats['female_students'] ?? 0;
$male_percentage = $stats['male_percentage'] ?? 0;
$female_percentage = $stats['female_percentage'] ?? 0;
$day_students = $stats['day_students'] ?? 0;
$boarding_students = $stats['boarding_students'] ?? 0;
$o_level_students = $stats['o_level_students'] ?? 0;
$a_level_students = $stats['a_level_students'] ?? 0;
$archived_students = $stats['archived_students'] ?? 0;
$recent_students = $stats['recent_students'] ?? 0;
$total_teachers = $stats['total_teachers'] ?? 0;
$total_classes = $stats['total_classes'] ?? 0;
$total_streams = $stats['total_streams'] ?? 0;
$total_levels = $stats['total_levels'] ?? 0;
$total_subjects = $stats['total_subjects'] ?? 0;

// --- PAGINATION, SEARCH & DATE FILTER FOR LOGS ---
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 5;
$offset = ($page - 1) * $limit;

$search_term = trim($_GET['search'] ?? '');
$start_date = trim($_GET['start_date'] ?? '');
$end_date = trim($_GET['end_date'] ?? '');

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

// Count total logs
try {
    $stmt_count = $pdo->prepare("
        SELECT COUNT(*) FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE $where_clause
    ");
    $stmt_count->execute($params);
    $total_rows = (int)$stmt_count->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $limit));
} catch (PDOException $e) {
    $total_rows = 0;
    $total_pages = 1;
}

// Fetch logs
$recent_logs = [];
try {
    $stmt = $pdo->prepare("
        SELECT al.action, al.description, al.created_at, u.fullname AS actor
        FROM activity_logs al
        JOIN users u ON al.user_id = u.id
        WHERE $where_clause
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    $stmt->execute($params);
    $recent_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error but continue
    error_log("Error fetching logs: " . $e->getMessage());
}

// Build base query string WITHOUT 'page'
$base_query = [];
if ($search_term !== '') $base_query['search'] = $search_term;
if ($start_date !== '') $base_query['start_date'] = $start_date;
if ($end_date !== '') $base_query['end_date'] = $end_date;
$base_query_str = $base_query ? '&' . http_build_query($base_query) : '';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        /* Footer */
        .footer {
            padding: 0.8rem 1.4rem;
            background: white;
            border-top: 1px solid #e9ecef;
            text-align: center;
            font-size: 0.85rem;
            color: #6c757d;
            flex-shrink: 0;
            margin-top: auto;
            box-shadow: 0 -1px 3px rgba(0, 0, 0, 0.05);
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .copyright {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .footer-links {
            display: flex;
            gap: 1rem;
        }

        .footer-links a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .footer-links a:hover {
            color: var(--secondary);
            text-decoration: underline;
        }

        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                text-align: center;
                gap: 0.8rem;
            }

            .footer-links {
                justify-content: center;
            }
        }

        /* Stats Header */
        .stats-header {
            margin-bottom: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }

        .stats-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
        }

        .button-group {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .btn {
            padding: 0.4rem 0.8rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: background 0.2s;
            white-space: nowrap;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .btn.secondary {
            background: var(--secondary);
        }

        .btn.secondary:hover {
            background: #8f1717;
        }

        .btn.outline {
            background: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }

        .btn.outline:hover {
            background: var(--primary);
            color: white;
        }

        /* Stats Cards */
        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }

        .stat-card {
            background: var(--card-bg);
            border-radius: 6px;
            padding: 0.8rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            text-align: center;
            min-height: 120px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .stat-card i {
            font-size: 1.4rem;
            color: var(--primary);
            margin-bottom: 0.4rem;
        }

        .stat-card h3 {
            font-size: 1.35rem;
            margin: 0.15rem 0;
            color: var(--secondary);
        }

        .stat-card p {
            color: #6c757d;
            font-size: 0.82rem;
            margin: 0;
        }

        .stat-card .subtext {
            font-size: 0.75rem;
            color: #adb5bd;
            margin-top: 0.3rem;
        }

        /* Detailed Statistics */
        .detailed-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1rem;
            flex-shrink: 0;
        }

        .detailed-card {
            background: white;
            border-radius: 6px;
            padding: 1rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            min-height: 200px;
        }

        .detailed-card h3 {
            color: var(--primary);
            margin-bottom: 0.8rem;
            font-size: 1.1rem;
            border-bottom: 1px solid #eee;
            padding-bottom: 0.5rem;
        }

        .stat-row {
            display: flex;
            justify-content: space-between;
            padding: 0.5rem 0;
            border-bottom: 1px solid #f8f9fa;
        }

        .stat-row:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #6c757d;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-label i {
            font-size: 0.8rem;
            color: var(--primary);
        }

        .stat-value {
            font-weight: 600;
            color: var(--text-dark);
            font-size: 0.95rem;
        }

        .progress-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            margin: 0.5rem 0;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            float: left;
            transition: width 0.5s ease;
        }

        .progress-fill.male {
            background: var(--primary);
        }

        .progress-fill.female {
            background: var(--secondary);
        }

        /* Distribution Tables */
        .distribution-section {
            margin-bottom: 1rem;
            flex-shrink: 0;
        }

        .distribution-section h3 {
            color: var(--primary);
            margin-bottom: 0.8rem;
            font-size: 1.1rem;
        }

        .distribution-tables {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 0.8rem;
            margin-bottom: 1rem;
        }

        .distribution-table-container {
            background: white;
            border-radius: 6px;
            padding: 1rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            max-height: 300px;
            overflow-y: auto;
            min-height: 300px;
        }

        .distribution-table {
            width: 100%;
            border-collapse: collapse;
        }

        .distribution-table th {
            background: var(--primary);
            color: white;
            padding: 0.6rem;
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .distribution-table td {
            padding: 0.6rem;
            border-bottom: 1px solid #eee;
            font-size: 0.85rem;
        }

        .distribution-table tr:hover {
            background: #f8f9fa;
        }

        .gender-count {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .gender-badge {
            padding: 0.2rem 0.5rem;
            border-radius: 3px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .gender-badge.male {
            background: rgba(26, 42, 108, 0.1);
            color: var(--primary);
        }

        .gender-badge.female {
            background: rgba(178, 31, 31, 0.1);
            color: var(--secondary);
        }

        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            flex: 1;
            min-height: 0;
        }

        @media (max-width: 1200px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }

        .chart-container,
        .logs-container {
            background: white;
            border-radius: 6px;
            padding: 1rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            min-height: 300px;
            overflow: hidden;
        }

        .chart-container h3,
        .logs-container h3 {
            margin-bottom: 1.2rem;
            color: var(--primary);
            font-size: 1.15rem;
            flex-shrink: 0;
        }

        .chart-wrapper {
            flex: 1;
            min-height: 0;
            position: relative;
            width: 100%;
            height: 300px;
        }

        /* Log Controls */
        .log-controls {
            display: flex;
            gap: 0.7rem;
            margin-bottom: 0.8rem;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .search-input {
            flex: 1;
            min-width: 120px;
            padding: 0.45rem 0.6rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.85rem;
        }

        /* Logs List */
        .logs-list {
            list-style: none;
            flex: 1;
            overflow-y: auto;
            margin: 0;
            padding: 0;
        }

        .log-item {
            padding: 0.55rem 0;
            border-bottom: 1px solid #eee;
            font-size: 0.88rem;
        }

        .log-item:last-child {
            border-bottom: none;
        }

        .log-item .action {
            font-weight: 600;
            color: var(--primary);
            display: block;
            margin-bottom: 0.1rem;
        }

        .log-item .description {
            color: #6c757d;
            margin-bottom: 0.3rem;
        }

        .log-item .time {
            font-size: 0.78rem;
            color: #888;
            display: block;
        }

        /* Pagination */
        .pagination {
            display: flex;
            gap: 0.35rem;
            margin-top: 0.8rem;
            justify-content: center;
            flex-wrap: wrap;
            flex-shrink: 0;
        }

        .pagination a,
        .pagination span {
            padding: 0.35rem 0.65rem;
            border: 1px solid #ddd;
            border-radius: 3px;
            text-decoration: none;
            color: #333;
            font-size: 0.85rem;
        }

        .pagination a:hover {
            background: var(--primary);
            color: white;
        }

        .pagination .current {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
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
        }

        @media (max-width: 768px) {
            .stats-cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .detailed-stats,
            .distribution-tables {
                grid-template-columns: 1fr;
            }

            .stats-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            .button-group {
                width: 100%;
                justify-content: flex-start;
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

            .log-controls {
                flex-direction: column;
            }

            .search-input {
                min-width: 100%;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .button-group {
                flex-direction: column;
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
            <li class="nav-item">
                <a href="academic_comment_management.php" class="nav-link">
                    <i class="fas fa-comment"></i>
                    <span>Comment Management</span>
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
        <header class="header" onclick="window.location='admin_dashboard.php'">
            <div class="admin-info">
                <h1>Welcome back, <?= $fullname ?>!</h1>
                <p><?= $email ?></p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <div class="stats-header">
                <h2>Dashboard Overview</h2>
                <div class="button-group">
                    <button class="btn" onclick="refreshStats()" id="refreshBtn">
                        <i class="fas fa-sync-alt"></i> Refresh
                    </button>
                    <a href="reports.php" class="btn secondary">
                        <i class="fas fa-chart-line"></i> Reports
                    </a>
                    <button class="btn outline" onclick="window.location.reload()">
                        <i class="fas fa-redo"></i> Reload
                    </button>
                </div>
            </div>

            <!-- Main Statistics Cards -->
            <div class="stats-cards">
                <div class="stat-card">
                    <i class="fas fa-user-graduate"></i>
                    <h3><?= $total_students ?></h3>
                    <p>Total Students</p>
                    <div class="subtext">Active Learners</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-male"></i>
                    <h3><?= $male_students ?></h3>
                    <p>Male Students</p>
                    <div class="subtext"><?= $male_percentage ?>%</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-female"></i>
                    <h3><?= $female_students ?></h3>
                    <p>Female Students</p>
                    <div class="subtext"><?= $female_percentage ?>%</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-chalkboard-teacher"></i>
                    <h3><?= $total_teachers ?></h3>
                    <p>Teachers</p>
                    <div class="subtext">Active Staff</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-school"></i>
                    <h3><?= $total_classes ?></h3>
                    <p>Classes</p>
                    <div class="subtext"><?= $total_streams ?> Streams</div>
                </div>
                <div class="stat-card">
                    <i class="fas fa-book-open"></i>
                    <h3><?= $total_subjects ?></h3>
                    <p>Subjects</p>
                    <div class="subtext"><?= $total_levels ?> Levels</div>
                </div>
            </div>

            <!-- Detailed Statistics -->
            <div class="detailed-stats">
                <div class="detailed-card">
                    <h3>Student Demographics</h3>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-venus-mars"></i> Gender Distribution:</span>
                        <span class="stat-value"><?= $male_students ?>M / <?= $female_students ?>F</span>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill male" style="width: <?= $male_percentage ?>%"></div>
                        <div class="progress-fill female" style="width: <?= $female_percentage ?>%"></div>
                    </div>

                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-sun"></i> Day Students:</span>
                        <span class="stat-value"><?= $day_students ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-bed"></i> Boarding Students:</span>
                        <span class="stat-value"><?= $boarding_students ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-certificate"></i> O-Level Students:</span>
                        <span class="stat-value"><?= $o_level_students ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-graduation-cap"></i> A-Level Students:</span>
                        <span class="stat-value"><?= $a_level_students ?></span>
                    </div>
                </div>

                <div class="detailed-card">
                    <h3>System Status</h3>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-clock"></i> Recently Added (7 days):</span>
                        <span class="stat-value"><?= $recent_students ?> students</span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-archive"></i> Archived Students:</span>
                        <span class="stat-value"><?= $archived_students ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-chalkboard"></i> Total Classes:</span>
                        <span class="stat-value"><?= $total_classes ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-stream"></i> Total Streams:</span>
                        <span class="stat-value"><?= $total_streams ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-layer-group"></i> Education Levels:</span>
                        <span class="stat-value"><?= $total_levels ?></span>
                    </div>
                    <div class="stat-row">
                        <span class="stat-label"><i class="fas fa-book"></i> Available Subjects:</span>
                        <span class="stat-value"><?= $total_subjects ?></span>
                    </div>
                </div>
            </div>

            <!-- Student Distribution by Class and Stream -->
            <div class="distribution-section">
                <h3>Student Distribution</h3>
                <div class="distribution-tables">
                    <div class="distribution-table-container">
                        <h4 style="color: var(--primary); margin-bottom: 0.8rem; font-size: 1rem;">By Class & Gender</h4>
                        <table class="distribution-table">
                            <thead>
                                <tr>
                                    <th>Class</th>
                                    <th>Level</th>
                                    <th>Gender Breakdown</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($distribution['by_class'])): ?>
                                    <?php foreach ($distribution['by_class'] as $class): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($class['class_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($class['level_name']) ?></td>
                                            <td>
                                                <div class="gender-count">
                                                    <span class="gender-badge male"><?= $class['male_count'] ?> M</span>
                                                    <span class="gender-badge female"><?= $class['female_count'] ?> F</span>
                                                </div>
                                            </td>
                                            <td><strong><?= $class['total'] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 1rem; color: #6c757d;">
                                            No distribution data available
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="distribution-table-container">
                        <h4 style="color: var(--primary); margin-bottom: 0.8rem; font-size: 1rem;">By Stream & Gender</h4>
                        <table class="distribution-table">
                            <thead>
                                <tr>
                                    <th>Stream</th>
                                    <th>Class</th>
                                    <th>Gender Breakdown</th>
                                    <th>Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($distribution['by_stream'])): ?>
                                    <?php foreach ($distribution['by_stream'] as $stream): ?>
                                        <tr>
                                            <td><strong><?= htmlspecialchars($stream['stream_name']) ?></strong></td>
                                            <td><?= htmlspecialchars($stream['class_name']) ?></td>
                                            <td>
                                                <div class="gender-count">
                                                    <span class="gender-badge male"><?= $stream['male_count'] ?> M</span>
                                                    <span class="gender-badge female"><?= $stream['female_count'] ?> F</span>
                                                </div>
                                            </td>
                                            <td><strong><?= $stream['total'] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" style="text-align: center; padding: 1rem; color: #6c757d;">
                                            No stream distribution data available
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Charts and Logs -->
            <div class="dashboard-grid">
                <div class="chart-container">
                    <h3>Student Distribution Chart</h3>
                    <div class="chart-wrapper">
                        <canvas id="userChart"></canvas>
                    </div>
                </div>

                <div class="logs-container">
                    <h3>Recent Activity</h3>
                    <div class="log-controls">
                        <form method="GET" style="display: flex; flex-wrap: wrap; gap: 0.7rem; flex: 1; align-items: flex-start;">
                            <input type="text" name="search" class="search-input" placeholder="Search activities..." value="<?= htmlspecialchars($search_term) ?>">
                            <input type="date" name="start_date" class="search-input" value="<?= htmlspecialchars($start_date) ?>" style="width: auto; min-width: 110px;">
                            <input type="date" name="end_date" class="search-input" value="<?= htmlspecialchars($end_date) ?>" style="width: auto; min-width: 110px;">
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                <button type="submit" class="btn"><i class="fas fa-filter"></i> Apply</button>
                                <?php if ($search_term || $start_date || $end_date): ?>
                                    <a href="admin_dashboard.php" class="btn" style="background: #6c757d;"><i class="fas fa-times"></i> Clear</a>
                                <?php endif; ?>
                            </div>
                        </form>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                            <a href="export_logs.php?format=csv<?= $base_query_str ?>" class="btn" style="background: #6c757d;">
                                <i class="fas fa-file-csv"></i> CSV
                            </a>
                        </div>
                    </div>

                    <ul class="logs-list">
                        <?php if (!empty($recent_logs)): ?>
                            <?php foreach ($recent_logs as $log): ?>
                                <li class="log-item">
                                    <span class="action"><?= htmlspecialchars($log['action']) ?></span>
                                    <span class="description"><?= htmlspecialchars($log['description']) ?></span>
                                    <span class="time"><?= date('M j, Y g:i A', strtotime($log['created_at'])) ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <li class="log-item" style="text-align: center; padding: 1rem; color: #6c757d;">
                                No activity found
                            </li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination">
                            <?php if ($page > 1): ?>
                                <a href="?page=1<?= $base_query_str ?>">&laquo; First</a>
                                <a href="?page=<?= $page - 1 ?><?= $base_query_str ?>">&lt; Prev</a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($total_pages, $page + 2);
                            if ($start > 1) echo '<span>...</span>';
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="?page=<?= $i ?><?= $base_query_str ?>" class="<?= $i === $page ? 'current' : '' ?>"><?= $i ?></a>
                            <?php endfor;
                            if ($end < $total_pages) echo '<span>...</span>';
                            ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?><?= $base_query_str ?>">Next &gt;</a>
                                <a href="?page=<?= $total_pages ?><?= $base_query_str ?>">Last &raquo;</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer Section -->
        <footer class="footer">
            <div class="footer-content">
                <div class="copyright">
                    <i class="far fa-copyright"></i>
                    <span><?= date('Y') ?> <?= $school_name ?>. All rights reserved.</span>
                </div>
                <div class="footer-links">
                    <a href="privacy.php">Privacy Policy</a>
                    <a href="terms.php">Terms of Service</a>
                    <a href="contact.php">Contact Us</a>
                </div>
            </div>
        </footer>
    </div>

    <script>
        // Toggle top-level dropdowns
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

        // Initialize main chart
        const ctx = document.getElementById('userChart').getContext('2d');
        const userChart = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Total Students', 'Male', 'Female', 'Day', 'Boarding', 'O-Level', 'A-Level'],
                datasets: [{
                    label: 'Student Count',
                    data: [
                        <?= $total_students ?>,
                        <?= $male_students ?>,
                        <?= $female_students ?>,
                        <?= $day_students ?>,
                        <?= $boarding_students ?>,
                        <?= $o_level_students ?>,
                        <?= $a_level_students ?>
                    ],
                    backgroundColor: [
                        'rgba(26, 42, 108, 0.7)',
                        'rgba(54, 162, 235, 0.7)',
                        'rgba(255, 99, 132, 0.7)',
                        'rgba(75, 192, 192, 0.7)',
                        'rgba(153, 102, 255, 0.7)',
                        'rgba(255, 159, 64, 0.7)',
                        'rgba(201, 203, 207, 0.7)'
                    ],
                    borderColor: [
                        'rgba(26, 42, 108, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(75, 192, 192, 1)',
                        'rgba(153, 102, 255, 1)',
                        'rgba(255, 159, 64, 1)',
                        'rgba(201, 203, 207, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1,
                            precision: 0
                        },
                        title: {
                            display: true,
                            text: 'Number of Students'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Categories'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.raw;
                                return label;
                            }
                        }
                    }
                },
                animation: {
                    duration: 1000,
                    easing: 'easeOutQuart'
                }
            }
        });

        // Helper function to update dashboard stats
        function updateDashboardStats(data) {
            // Update main cards
            const cards = document.querySelectorAll('.stat-card h3');
            if (cards.length >= 6 && data.total_students !== undefined) {
                cards[0].textContent = data.total_students || 0;
                cards[1].textContent = data.male_students || 0;
                cards[2].textContent = data.female_students || 0;
                cards[3].textContent = data.total_teachers || 0;
                cards[4].textContent = data.total_classes || 0;
                cards[5].textContent = data.total_subjects || 0;

                // Update subtext
                const subtexts = document.querySelectorAll('.stat-card .subtext');
                if (subtexts.length >= 6) {
                    subtexts[1].textContent = (data.male_percentage || 0) + '%';
                    subtexts[2].textContent = (data.female_percentage || 0) + '%';
                    subtexts[4].textContent = (data.total_streams || 0) + ' Streams';
                    subtexts[5].textContent = (data.total_levels || 0) + ' Levels';
                }
            }

            // Update detailed stats
            updateDetailedStats(data);

            // Update chart data
            if (userChart && data.total_students !== undefined) {
                userChart.data.datasets[0].data = [
                    data.total_students || 0,
                    data.male_students || 0,
                    data.female_students || 0,
                    data.day_students || 0,
                    data.boarding_students || 0,
                    data.o_level_students || 0,
                    data.a_level_students || 0
                ];

                // Update with animation
                userChart.update('active');
            }
        }

        function updateDetailedStats(data) {
            // First detailed card (Student Demographics)
            const detailedCard1 = document.querySelectorAll('.detailed-card')[0];
            if (detailedCard1) {
                const rows1 = detailedCard1.querySelectorAll('.stat-row');
                if (rows1.length >= 5) {
                    // Gender distribution
                    const genderText = (data.male_students || 0) + 'M / ' + (data.female_students || 0) + 'F';
                    if (rows1[0]) {
                        rows1[0].querySelector('.stat-value').textContent = genderText;
                    }

                    // Progress bars
                    const maleProgress = detailedCard1.querySelector('.progress-fill.male');
                    const femaleProgress = detailedCard1.querySelector('.progress-fill.female');
                    if (maleProgress && data.male_percentage !== undefined) {
                        maleProgress.style.width = (data.male_percentage || 0) + '%';
                    }
                    if (femaleProgress && data.female_percentage !== undefined) {
                        femaleProgress.style.width = (data.female_percentage || 0) + '%';
                    }

                    // Other stats
                    const statIndices = {
                        2: {
                            key: 'day_students',
                            default: 0
                        },
                        3: {
                            key: 'boarding_students',
                            default: 0
                        },
                        4: {
                            key: 'o_level_students',
                            default: 0
                        },
                        5: {
                            key: 'a_level_students',
                            default: 0
                        }
                    };

                    for (const [index, config] of Object.entries(statIndices)) {
                        if (rows1[index]) {
                            const value = data[config.key] !== undefined ? data[config.key] : config.default;
                            rows1[index].querySelector('.stat-value').textContent = value;
                        }
                    }
                }
            }

            // Second detailed card (System Status)
            const detailedCard2 = document.querySelectorAll('.detailed-card')[1];
            if (detailedCard2) {
                const rows2 = detailedCard2.querySelectorAll('.stat-row');
                if (rows2.length >= 6) {
                    const statIndices2 = {
                        0: {
                            key: 'recent_students',
                            suffix: ' students',
                            default: 0
                        },
                        1: {
                            key: 'archived_students',
                            suffix: '',
                            default: 0
                        },
                        2: {
                            key: 'total_classes',
                            suffix: '',
                            default: 0
                        },
                        3: {
                            key: 'total_streams',
                            suffix: '',
                            default: 0
                        },
                        4: {
                            key: 'total_levels',
                            suffix: '',
                            default: 0
                        },
                        5: {
                            key: 'total_subjects',
                            suffix: '',
                            default: 0
                        }
                    };

                    for (const [index, config] of Object.entries(statIndices2)) {
                        if (rows2[index]) {
                            const value = data[config.key] !== undefined ? data[config.key] : config.default;
                            rows2[index].querySelector('.stat-value').textContent = value + config.suffix;
                        }
                    }
                }
            }
        }

        // Refresh statistics function
        function refreshStats() {
            const refreshBtn = document.getElementById('refreshBtn');
            const originalHTML = refreshBtn.innerHTML;
            refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing...';
            refreshBtn.disabled = true;

            // Try multiple methods to fetch stats
            fetchStatsWithFallback()
                .then(data => {
                    updateDashboardStats(data);
                    // Show success message
                    showNotification('Statistics updated successfully!', 'success');
                })
                .catch(error => {
                    console.error('Error refreshing stats:', error);
                    showNotification('Failed to refresh statistics. Please try again.', 'error');
                })
                .finally(() => {
                    refreshBtn.innerHTML = originalHTML;
                    refreshBtn.disabled = false;
                });
        }

        // Try multiple methods to fetch stats
        async function fetchStatsWithFallback() {
            try {
                const response = await fetch('stats.php', {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    cache: 'no-cache',
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error(data.message || 'API returned an error');
                }

                return data;
            } catch (fetchError) {
                console.log('Fetch API failed, trying XMLHttpRequest...', fetchError);

                // Try XMLHttpRequest as fallback
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('GET', 'stats.php', true);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.timeout = 10000;
                    xhr.withCredentials = true;

                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const data = JSON.parse(xhr.responseText);
                                if (!data.success) {
                                    reject(new Error(data.message || 'API error'));
                                } else {
                                    resolve(data);
                                }
                            } catch (e) {
                                reject(new Error('Invalid JSON response'));
                            }
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };

                    xhr.onerror = function() {
                        reject(new Error('Network error'));
                    };

                    xhr.ontimeout = function() {
                        reject(new Error('Request timeout'));
                    };

                    xhr.send();
                }).catch(() => {
                    // Final fallback: return current page data
                    return {
                        total_students: <?= $total_students ?>,
                        male_students: <?= $male_students ?>,
                        female_students: <?= $female_students ?>,
                        male_percentage: <?= $male_percentage ?>,
                        female_percentage: <?= $female_percentage ?>,
                        day_students: <?= $day_students ?>,
                        boarding_students: <?= $boarding_students ?>,
                        o_level_students: <?= $o_level_students ?>,
                        a_level_students: <?= $a_level_students ?>,
                        archived_students: <?= $archived_students ?>,
                        recent_students: <?= $recent_students ?>,
                        total_teachers: <?= $total_teachers ?>,
                        total_classes: <?= $total_classes ?>,
                        total_streams: <?= $total_streams ?>,
                        total_levels: <?= $total_levels ?>,
                        total_subjects: <?= $total_subjects ?>,
                        success: true
                    };
                });
            }
        }

        // Show notification function
        function showNotification(message, type = 'info') {
            // Create notification element
            const notification = document.createElement('div');
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 18px;
                background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
                color: white;
                border-radius: 4px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                z-index: 10000;
                font-size: 14px;
                display: flex;
                align-items: center;
                gap: 8px;
                animation: slideIn 0.3s ease;
            `;

            // Add icon based on type
            let icon = 'fas fa-info-circle';
            if (type === 'success') icon = 'fas fa-check-circle';
            if (type === 'error') icon = 'fas fa-exclamation-circle';

            notification.innerHTML = `
                <i class="${icon}"></i>
                <span>${message}</span>
            `;

            document.body.appendChild(notification);

            // Remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);

            // Add animation styles
            if (!document.getElementById('notification-styles')) {
                const style = document.createElement('style');
                style.id = 'notification-styles';
                style.textContent = `
                    @keyframes slideIn {
                        from { transform: translateX(100%) translateY(-20px); opacity: 0; }
                        to { transform: translateX(0) translateY(0); opacity: 1; }
                    }
                    @keyframes slideOut {
                        from { transform: translateX(0); opacity: 1; }
                        to { transform: translateX(100%); opacity: 0; }
                    }
                `;
                document.head.appendChild(style);
            }
        }

        // Auto-refresh every 60 seconds
        let autoRefreshInterval;

        function startAutoRefresh() {
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
            }
            autoRefreshInterval = setInterval(refreshStats, 60000);
        }

        // Start auto-refresh after page loads
        setTimeout(() => {
            startAutoRefresh();
        }, 60000);

        // Stop auto-refresh when page is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.hidden) {
                if (autoRefreshInterval) {
                    clearInterval(autoRefreshInterval);
                    autoRefreshInterval = null;
                }
            } else {
                startAutoRefresh();
            }
        });

        // Add click animation to stats cards
        document.querySelectorAll('.stat-card').forEach(card => {
            card.addEventListener('click', function() {
                this.style.transform = 'translateY(-4px) scale(1.02)';
                setTimeout(() => {
                    this.style.transform = 'translateY(-2px)';
                }, 150);
            });
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
    </script>
</body>

</html>