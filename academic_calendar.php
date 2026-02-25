<?php
// academic_calendar.php
require_once 'config.php';


// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Initialize variables
$message = '';
$message_type = '';
$edit_year_id = null;
$edit_term_id = null;
$year_to_edit = null;
$term_to_edit = null;

// Fetch admin info
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$admin) throw new Exception("Admin user not found.");
    $fullname = htmlspecialchars($admin['fullname']);
    $email = htmlspecialchars($admin['email']);
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}

// Handle Add/Update Academic Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_year']) || isset($_POST['update_year']))) {
    try {
        $start_year = trim($_POST['start_year']);
        $display_format = $_POST['display_format'];
        $status = $_POST['status'] ?? 'active';
        $is_current = isset($_POST['is_current']) ? 1 : 0;

        // Validate start year
        if (empty($start_year) || !is_numeric($start_year) || strlen($start_year) != 4) {
            throw new Exception("Please enter a valid 4-digit start year.");
        }

        $start_year_int = (int)$start_year;
        if ($start_year_int < 2000 || $start_year_int > 2100) {
            throw new Exception("Start year must be between 2000 and 2100.");
        }

        // Generate year name based on format
        if ($display_format === 'single') {
            $year_name = $start_year;
        } else {
            $end_year = $start_year_int + 1;
            $year_name = $start_year . '/' . $end_year;
        }

        if (isset($_POST['update_year'])) {
            $year_id = $_POST['year_id'];

            // Check if year already exists (excluding current year)
            $stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_name = ? AND id != ?");
            $stmt->execute([$year_name, $year_id]);
            if ($stmt->fetch()) {
                throw new Exception("Academic year '{$year_name}' already exists.");
            }

            // If setting as current, reset all other years
            if ($is_current) {
                $pdo->prepare("UPDATE academic_years SET is_current = 0")->execute();
            }

            // Update academic year
            $stmt = $pdo->prepare("UPDATE academic_years SET year_name = ?, start_year = ?, display_format = ?, status = ?, is_current = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$year_name, $start_year_int, $display_format, $status, $is_current, $year_id]);

            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'UPDATE_ACADEMIC_YEAR', ?)");
            $log_stmt->execute([$_SESSION['user_id'], "Updated academic year: {$year_name}"]);

            $message = "Academic year '{$year_name}' updated successfully!";
            $edit_year_id = null;
        } else {
            // Check if year already exists - more thorough check
            $stmt = $pdo->prepare("SELECT id FROM academic_years WHERE year_name = ?");
            $stmt->execute([$year_name]);
            if ($stmt->fetch()) {
                throw new Exception("Academic year '{$year_name}' already exists in the database.");
            }

            // Also check by start year and format combination
            $stmt = $pdo->prepare("SELECT id FROM academic_years WHERE start_year = ? AND display_format = ?");
            $stmt->execute([$start_year_int, $display_format]);
            if ($stmt->fetch()) {
                throw new Exception("An academic year with start year {$start_year} and format '{$display_format}' already exists.");
            }

            // If setting as current, reset all other years
            if ($is_current) {
                $pdo->prepare("UPDATE academic_years SET is_current = 0")->execute();
            }

            // Insert academic year
            $stmt = $pdo->prepare("INSERT INTO academic_years (year_name, start_year, display_format, status, is_current) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$year_name, $start_year_int, $display_format, $status, $is_current]);

            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'ADD_ACADEMIC_YEAR', ?)");
            $log_stmt->execute([$_SESSION['user_id'], "Added academic year: {$year_name}"]);

            $message = "Academic year '{$year_name}' added successfully!";
        }

        $message_type = "success";

        // Redirect to avoid form resubmission
        header("Location: academic_calendar.php?message=" . urlencode($message) . "&message_type=" . $message_type);
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Add/Update Academic Term
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_term']) || isset($_POST['update_term']))) {
    try {
        $academic_year_id = $_POST['academic_year_id'];
        $term_name = trim($_POST['term_name']);
        $opening_date = $_POST['opening_date'];
        $closing_date = $_POST['closing_date'];
        $status = $_POST['status'] ?? 'inactive';
        $remarks = trim($_POST['remarks'] ?? '');

        // Validate required fields
        if (empty($academic_year_id) || empty($term_name) || empty($opening_date) || empty($closing_date)) {
            throw new Exception("Please fill in all required fields.");
        }

        // Validate term name
        $valid_terms = ['Term I', 'Term II', 'Term III'];
        if (!in_array($term_name, $valid_terms)) {
            throw new Exception("Invalid term name. Please select from: Term I, Term II, or Term III.");
        }

        // Validate dates
        $opening = new DateTime($opening_date);
        $closing = new DateTime($closing_date);
        $today = new DateTime();

        if ($opening >= $closing) {
            throw new Exception("Closing date must be after opening date.");
        }

        // Check if term duration is reasonable (at least 7 days)
        $interval = $opening->diff($closing);
        if ($interval->days < 7) {
            throw new Exception("Term duration should be at least 7 days.");
        }

        if (isset($_POST['update_term'])) {
            $term_id = $_POST['term_id'];

            // Check if term already exists for this academic year (excluding current term)
            $stmt = $pdo->prepare("SELECT id FROM academic_terms WHERE academic_year_id = ? AND term_name = ? AND id != ?");
            $stmt->execute([$academic_year_id, $term_name, $term_id]);
            if ($stmt->fetch()) {
                throw new Exception("Term '{$term_name}' already exists for this academic year.");
            }

            // If activating term, deactivate all other terms in the same academic year
            if ($status === 'active') {
                $pdo->prepare("UPDATE academic_terms SET status = 'inactive' WHERE academic_year_id = ?")->execute([$academic_year_id]);
            }

            // Update academic term
            $stmt = $pdo->prepare("UPDATE academic_terms SET academic_year_id = ?, term_name = ?, opening_date = ?, closing_date = ?, status = ?, remarks = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$academic_year_id, $term_name, $opening_date, $closing_date, $status, $remarks, $term_id]);

            // Get academic year name for logging
            $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
            $stmt->execute([$academic_year_id]);
            $academic_year_name = $stmt->fetchColumn();

            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'UPDATE_ACADEMIC_TERM', ?)");
            $log_stmt->execute([$_SESSION['user_id'], "Updated term '{$term_name}' for academic year: {$academic_year_name}"]);

            $message = "Term '{$term_name}' updated successfully!";
            $edit_term_id = null;
        } else {
            // Check if term already exists for this academic year
            $stmt = $pdo->prepare("SELECT id FROM academic_terms WHERE academic_year_id = ? AND term_name = ?");
            $stmt->execute([$academic_year_id, $term_name]);
            if ($stmt->fetch()) {
                throw new Exception("Term '{$term_name}' already exists for this academic year.");
            }

            // If activating term, deactivate all other terms in the same academic year
            if ($status === 'active') {
                $pdo->prepare("UPDATE academic_terms SET status = 'inactive' WHERE academic_year_id = ?")->execute([$academic_year_id]);
            }

            // Insert academic term
            $stmt = $pdo->prepare("INSERT INTO academic_terms (academic_year_id, term_name, opening_date, closing_date, status, remarks) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$academic_year_id, $term_name, $opening_date, $closing_date, $status, $remarks]);

            // Get academic year name for logging
            $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
            $stmt->execute([$academic_year_id]);
            $academic_year_name = $stmt->fetchColumn();

            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'ADD_ACADEMIC_TERM', ?)");
            $log_stmt->execute([$_SESSION['user_id'], "Added term '{$term_name}' for academic year: {$academic_year_name}"]);

            $message = "Term '{$term_name}' added successfully!";
        }

        $message_type = "success";

        // Redirect to avoid form resubmission
        header("Location: academic_calendar.php?message=" . urlencode($message) . "&message_type=" . $message_type);
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete Academic Year
if (isset($_GET['delete_year'])) {
    try {
        $year_id = $_GET['delete_year'];

        // Check if year has any terms
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM academic_terms WHERE academic_year_id = ?");
        $stmt->execute([$year_id]);
        $term_count = $stmt->fetchColumn();

        if ($term_count > 0) {
            throw new Exception("Cannot delete academic year because it has associated terms. Delete the terms first.");
        }

        // Check if year is marked as current
        $stmt = $pdo->prepare("SELECT is_current FROM academic_years WHERE id = ?");
        $stmt->execute([$year_id]);
        $is_current = $stmt->fetchColumn();

        if ($is_current) {
            throw new Exception("Cannot delete the current academic year. Please set another year as current first.");
        }

        // Get year name for logging
        $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
        $stmt->execute([$year_id]);
        $year_name = $stmt->fetchColumn();

        if (!$year_name) {
            throw new Exception("Academic year not found.");
        }

        // Delete academic year
        $stmt = $pdo->prepare("DELETE FROM academic_years WHERE id = ?");
        $stmt->execute([$year_id]);

        // Log activity
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'DELETE_ACADEMIC_YEAR', ?)");
        $log_stmt->execute([$_SESSION['user_id'], "Deleted academic year: {$year_name}"]);

        $message = "Academic year '{$year_name}' deleted successfully!";
        $message_type = "success";

        // Redirect
        header("Location: academic_calendar.php?message=" . urlencode($message) . "&message_type=" . $message_type);
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Delete Academic Term
if (isset($_GET['delete_term'])) {
    try {
        $term_id = $_GET['delete_term'];

        // Get term details for logging
        $stmt = $pdo->prepare("SELECT at.term_name, ay.year_name FROM academic_terms at JOIN academic_years ay ON at.academic_year_id = ay.id WHERE at.id = ?");
        $stmt->execute([$term_id]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$term) {
            throw new Exception("Academic term not found.");
        }

        // Check if term is active
        $stmt = $pdo->prepare("SELECT status FROM academic_terms WHERE id = ?");
        $stmt->execute([$term_id]);
        $term_status = $stmt->fetchColumn();

        if ($term_status === 'active') {
            throw new Exception("Cannot delete an active term. Please close the term first.");
        }

        // Delete academic term
        $stmt = $pdo->prepare("DELETE FROM academic_terms WHERE id = ?");
        $stmt->execute([$term_id]);

        // Log activity
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'DELETE_ACADEMIC_TERM', ?)");
        $log_stmt->execute([$_SESSION['user_id'], "Deleted term '{$term['term_name']}' for academic year: {$term['year_name']}"]);

        $message = "Term '{$term['term_name']}' deleted successfully!";
        $message_type = "success";

        // Redirect
        header("Location: academic_calendar.php?message=" . urlencode($message) . "&message_type=" . $message_type);
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Handle Term Status Toggle (Activate/Close)
if (isset($_GET['action']) && isset($_GET['term_id'])) {
    try {
        $term_id = $_GET['term_id'];
        $action = $_GET['action'];

        // Get term details
        $stmt = $pdo->prepare("SELECT at.*, ay.year_name FROM academic_terms at JOIN academic_years ay ON at.academic_year_id = ay.id WHERE at.id = ?");
        $stmt->execute([$term_id]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$term) {
            throw new Exception("Term not found.");
        }

        if ($action === 'activate') {
            // Deactivate all other terms in the same academic year first
            $pdo->prepare("UPDATE academic_terms SET status = 'inactive' WHERE academic_year_id = ?")->execute([$term['academic_year_id']]);

            // Activate this term
            $pdo->prepare("UPDATE academic_terms SET status = 'active' WHERE id = ?")->execute([$term_id]);

            $log_msg = "Activated term '{$term['term_name']}' for academic year: {$term['year_name']}";
            $message = "Term '{$term['term_name']}' activated successfully!";
        } elseif ($action === 'close') {
            $pdo->prepare("UPDATE academic_terms SET status = 'inactive' WHERE id = ?")->execute([$term_id]);

            $log_msg = "Closed term '{$term['term_name']}' for academic year: {$term['year_name']}";
            $message = "Term '{$term['term_name']}' closed successfully!";
        } else {
            throw new Exception("Invalid action.");
        }

        // Log activity
        $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'UPDATE_ACADEMIC_TERM', ?)");
        $log_stmt->execute([$_SESSION['user_id'], $log_msg]);

        $message_type = "success";

        // Redirect
        header("Location: academic_calendar.php?message=" . urlencode($message) . "&message_type=" . $message_type);
        exit;
    } catch (Exception $e) {
        $message = $e->getMessage();
        $message_type = "error";
    }
}

// Check for redirect messages
if (isset($_GET['message'])) {
    $message = $_GET['message'];
    $message_type = $_GET['message_type'] ?? 'success';
}

// Fetch data for display
$academic_years = $pdo->query("SELECT id, year_name, start_year, display_format, status, is_current FROM academic_years ORDER BY start_year DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch terms with academic year info
$terms = $pdo->query("
    SELECT at.*, ay.year_name 
    FROM academic_terms at 
    JOIN academic_years ay ON at.academic_year_id = ay.id 
    ORDER BY ay.start_year DESC, 
    CASE at.term_name 
        WHEN 'Term I' THEN 1 
        WHEN 'Term II' THEN 2 
        WHEN 'Term III' THEN 3 
        ELSE 4 
    END
")->fetchAll(PDO::FETCH_ASSOC);

// Prepare terms for display with formatted dates and duration
foreach ($terms as &$term) {
    $opening = new DateTime($term['opening_date']);
    $closing = new DateTime($term['closing_date']);
    $term['formatted_opening'] = $opening->format('M j, Y');
    $term['formatted_closing'] = $closing->format('M j, Y');
    $term['duration'] = $opening->diff($closing)->days + 1;
}
unset($term);

// Get current date for date pickers
$current_date = date('Y-m-d');

// Get default tab from URL or default to years
$active_tab = isset($_GET['tab']) && in_array($_GET['tab'], ['years', 'terms']) ? $_GET['tab'] : 'years';
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Calendar - School Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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
        }

        .alert {
            padding: 0.8rem 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            font-size: 0.9rem;
        }

        .alert.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .page-title {
            color: var(--primary);
            margin-bottom: .85rem;
            font-size: 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .form-container {
            background: white;
            border-radius: 6px;
            padding: 1.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .section-title {
            color: var(--primary);
            margin: 1.5rem 0 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
            font-size: 1.2rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.3rem;
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .form-group label.required::after {
            content: " *";
            color: #dc3545;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            transition: border-color 0.15s;
        }

        .form-control:focus {
            border-color: var(--primary);
            outline: none;
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .radio-group {
            display: flex;
            gap: 1rem;
            margin-top: 0.5rem;
        }

        .radio-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
        }

        .radio-label input[type="radio"] {
            width: 16px;
            height: 16px;
        }

        .checkbox-label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            margin-top: 0.5rem;
        }

        .checkbox-label input[type="checkbox"] {
            width: 16px;
            height: 16px;
        }

        .btn {
            padding: 0.6rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
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
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-sm {
            padding: 0.3rem 0.8rem;
            font-size: 0.8rem;
        }

        .btn-icon {
            padding: 0.3rem;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-icon-edit {
            background: #17a2b8;
            color: white;
        }

        .btn-icon-edit:hover {
            background: #138496;
        }

        .btn-icon-delete {
            background: #dc3545;
            color: white;
        }

        .btn-icon-delete:hover {
            background: #c82333;
        }

        .btn-icon-activate {
            background: #28a745;
            color: white;
        }

        .btn-icon-activate:hover {
            background: #218838;
        }

        .btn-icon-close {
            background: #ffc107;
            color: #212529;
        }

        .btn-icon-close:hover {
            background: #e0a800;
        }

        .btn-add {
            padding: 0.5rem .8rem;
            font-size: 0.9rem;
        }

        .form-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .tabs {
            display: flex;
            border-bottom: 2px solid #dee2e6;
            margin-bottom: 1.5rem;
        }

        .tab {
            padding: 0.8rem 1.5rem;
            background: none;
            border: none;
            font-size: 1rem;
            font-weight: 600;
            color: #6c757d;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }

        .tab.active {
            color: var(--primary);
        }

        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: var(--primary);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .table-container {
            background: white;
            border-radius: 6px;
            overflow: hidden;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
        }

        .table th,
        .table td {
            padding: 0.4rem .8rem;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }

        .table th {
            background: #f8f9fa;
            font-weight: 600;
            color: var(--primary);
        }

        .table tbody tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        .current-badge {
            background: #cce5ff;
            color: #004085;
            padding: 0.2rem 0.6rem;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }

        .actions {
            display: flex;
            gap: 0.3rem;
            flex-wrap: wrap;
        }

        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 1rem;
        }

        .modal.active {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .modal-content {
            background: white;
            border-radius: 8px;
            max-width: 600px;
            width: 100%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: var(--primary);
            color: white;
            padding: 1.2rem 1.5rem;
            border-radius: 8px 8px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3rem;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.8rem;
            cursor: pointer;
            line-height: 1;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: background-color 0.2s;
        }

        .close-btn:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .confirmation-text {
            margin-bottom: 1.5rem;
            font-size: 1rem;
            color: #495057;
            line-height: 1.5;
        }

        .modal-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid #dee2e6;
        }

        .add-form-container {
            background: white;
            border-radius: 6px;
            padding: 1.5rem;
            box-shadow: 0 2px 6px rgba(0, 0, 0, 0.05);
            margin-bottom: 1.5rem;
            display: none;
        }

        .add-form-container.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }

        .date-input-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .date-input-group .form-control {
            flex: 1;
        }

        .date-hint {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        .year-preview {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 2px;
            padding: 0.55rem;
            font-weight: 600;
            color: var(--primary);
            text-align: left;
            margin-top: 0.5rem;
        }

        .term-duration {
            font-size: 0.9rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }

        @media (max-width: 992px) {
            .sidebar {
                width: 60px;
            }

            .sidebar .sidebar-header span,
            .sidebar .nav-link span,
            .sidebar .dropdown-toggle::after {
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

            .form-grid {
                grid-template-columns: 1fr;
            }

            .table {
                display: block;
                overflow-x: auto;
            }

            .tabs {
                flex-direction: column;
            }

            .tab {
                width: 100%;
                text-align: left;
            }

            .modal-content {
                max-width: 95%;
                max-height: 90vh;
            }
        }

        @media (max-width: 576px) {

            .form-buttons,
            .actions,
            .modal-buttons {
                flex-direction: column;
            }

            .radio-group {
                flex-direction: column;
                gap: 0.5rem;
            }

            .page-title {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
            }

            .btn {
                width: 100%;
                justify-content: center;
            }

            .modal-content {
                max-height: 95vh;
            }
        }

        /* Scrollbar styling */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c1c1c1;
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a8a8a8;
        }

        /* Date picker improvements */
        input[type="date"] {
            position: relative;
        }

        input[type="date"]::-webkit-calendar-picker-indicator {
            background: transparent;
            bottom: 0;
            color: transparent;
            cursor: pointer;
            height: auto;
            left: 0;
            position: absolute;
            right: 0;
            top: 0;
            width: auto;
        }

        /* Validation styles */
        .form-control.invalid {
            border-color: #dc3545;
        }

        .validation-error {
            color: #dc3545;
            font-size: 0.85rem;
            margin-top: 0.25rem;
            display: none;
        }

        .validation-error.show {
            display: block;
        }

        /* Modal backdrop */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1999;
        }

        .modal-backdrop.active {
            display: block;
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
        <header class="header" onclick="window.location='admin_dashboard.php'">
            <div class="admin-info">
                <h1>Welcome back, <?= $fullname ?>!</h1>
                <p><?= $email ?></p>
            </div>
            <div class="role-tag">Admin</div>
        </header>

        <main class="main-content">
            <?php if (!empty($message)): ?>
                <div class="alert <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="page-title">
                <h2>Academic Calendar Management</h2>
                <div>
                    <button class="btn btn-success btn-add" onclick="openYearModal()">
                        <i class="fas fa-plus"></i> Add Academic Year
                    </button>
                    <button class="btn btn-success btn-add" onclick="openTermModal()">
                        <i class="fas fa-plus"></i> Add Academic Term
                    </button>
                </div>
            </div>

            <div class="tabs">
                <button class="tab <?= $active_tab === 'years' ? 'active' : '' ?>" onclick="switchTab('years')">Academic Years</button>
                <button class="tab <?= $active_tab === 'terms' ? 'active' : '' ?>" onclick="switchTab('terms')">Academic Terms</button>
            </div>

            <!-- Academic Years List -->
            <div id="years" class="tab-content <?= $active_tab === 'years' ? 'active' : '' ?>">
                <div class="form-container">
                    <h3 class="section-title">Academic Years</h3>
                    <?php if (empty($academic_years)): ?>
                        <p>No academic years found. Click "Add Academic Year" to create your first academic year.</p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Year</th>
                                        <th>Start Year</th>
                                        <th>Display Format</th>
                                        <th>Status</th>
                                        <th>Current</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($academic_years as $year): ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($year['year_name']) ?>
                                            </td>
                                            <td><?= htmlspecialchars($year['start_year']) ?></td>
                                            <td><?= ucfirst(htmlspecialchars($year['display_format'])) ?></td>
                                            <td>
                                                <span class="status-badge status-<?= $year['status'] ?>">
                                                    <?= ucfirst($year['status']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($year['is_current']): ?>
                                                    <span class="current-badge">Current</span>
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                            <td class="actions">
                                                <button class="btn-icon btn-icon-edit" onclick="editYear(<?= $year['id'] ?>, '<?= htmlspecialchars($year['year_name']) ?>', <?= $year['start_year'] ?>, '<?= $year['display_format'] ?>', '<?= $year['status'] ?>', <?= $year['is_current'] ?>)">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn-icon btn-icon-delete" onclick="confirmDeleteYear(<?= $year['id'] ?>, '<?= htmlspecialchars(addslashes($year['year_name'])) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Academic Terms List -->
            <div id="terms" class="tab-content <?= $active_tab === 'terms' ? 'active' : '' ?>">
                <div class="form-container">
                    <h3 class="section-title">Academic Terms</h3>
                    <?php if (empty($terms)): ?>
                        <p>No academic terms found. Click "Add Academic Term" to create your first term.</p>
                    <?php else: ?>
                        <div class="table-container">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Term</th>
                                        <th>Academic Year</th>
                                        <th>Opening Date</th>
                                        <th>Closing Date</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Remarks</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($terms as $term): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($term['term_name']) ?></td>
                                            <td><?= htmlspecialchars($term['year_name']) ?></td>
                                            <td><?= $term['formatted_opening'] ?></td>
                                            <td><?= $term['formatted_closing'] ?></td>
                                            <td><?= $term['duration'] ?> days</td>
                                            <td>
                                                <span class="status-badge status-<?= $term['status'] ?>">
                                                    <?= ucfirst($term['status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($term['remarks'] ?: '-') ?></td>
                                            <td class="actions">
                                                <button class="btn-icon btn-icon-edit" onclick="editTerm(<?= $term['id'] ?>, <?= $term['academic_year_id'] ?>, '<?= htmlspecialchars($term['term_name']) ?>', '<?= $term['opening_date'] ?>', '<?= $term['closing_date'] ?>', '<?= $term['status'] ?>', '<?= htmlspecialchars(addslashes($term['remarks'])) ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <?php if ($term['status'] === 'active'): ?>
                                                    <button class="btn-icon btn-icon-close" onclick="confirmCloseTerm(<?= $term['id'] ?>, '<?= htmlspecialchars(addslashes($term['term_name'])) ?>')" title="Close Term">
                                                        <i class="fas fa-lock"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn-icon btn-icon-activate" onclick="confirmActivateTerm(<?= $term['id'] ?>, '<?= htmlspecialchars(addslashes($term['term_name'])) ?>')" title="Activate Term">
                                                        <i class="fas fa-unlock"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <button class="btn-icon btn-icon-delete" onclick="confirmDeleteTerm(<?= $term['id'] ?>, '<?= htmlspecialchars(addslashes($term['term_name'])) ?>')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Modal Backdrop -->
    <div class="modal-backdrop" id="modalBackdrop"></div>

    <!-- Add Academic Year Modal -->
    <div id="addYearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Academic Year</h3>
                <button class="close-btn" onclick="closeModal('addYearModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addYearForm" method="POST">
                    <div class="form-group">
                        <label for="start_year" class="required">Start Year</label>
                        <input type="number"
                            name="start_year"
                            id="start_year"
                            class="form-control"
                            min="2000"
                            max="2100"
                            value="<?= date('Y') ?>"
                            required
                            onchange="updateYearName()">
                        <div class="validation-error" id="start_year_error"></div>
                        <small class="text-muted">The canonical start year (e.g., 2025)</small>
                    </div>

                    <div class="form-group">
                        <label class="required">Display Format</label>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio"
                                    name="display_format"
                                    value="single"
                                    checked
                                    onchange="updateYearName()">
                                Single Year (e.g., "2025")
                            </label>
                            <label class="radio-label">
                                <input type="radio"
                                    name="display_format"
                                    value="range"
                                    onchange="updateYearName()">
                                Year Range (e.g., "2025/2026")
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Preview</label>
                        <div class="year-preview" id="display_name">
                            <?= date('Y') ?>
                        </div>
                        <small class="text-muted">This will be generated automatically based on your selections</small>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select name="status" id="status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox"
                                    name="is_current"
                                    value="1">
                                Set as Current Academic Year
                            </label>
                            <small class="text-muted">Only one academic year can be current at a time</small>
                        </div>
                    </div>

                    <div class="modal-buttons">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addYearModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="add_year" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add Year
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Academic Term Modal -->
    <div id="addTermModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Add Academic Term</h3>
                <button class="close-btn" onclick="closeModal('addTermModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="addTermForm" method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="academic_year_id" class="required">Academic Year</label>
                            <select name="academic_year_id" id="academic_year_id" class="form-control" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= $year['id'] ?>" <?= $year['is_current'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="validation-error" id="academic_year_id_error"></div>
                        </div>

                        <div class="form-group">
                            <label for="term_name" class="required">Term Name</label>
                            <select name="term_name" id="term_name" class="form-control" required>
                                <option value="">Select Term</option>
                                <option value="Term I">Term I</option>
                                <option value="Term II">Term II</option>
                                <option value="Term III">Term III</option>
                            </select>
                            <div class="validation-error" id="term_name_error"></div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="opening_date" class="required">Opening Date</label>
                            <div class="date-input-group">
                                <input type="date"
                                    name="opening_date"
                                    id="opening_date"
                                    class="form-control"
                                    value="<?= $current_date ?>"
                                    required
                                    onchange="updateClosingDateMin()">
                            </div>
                            <div class="validation-error" id="opening_date_error"></div>
                            <small class="date-hint">Today's date is set as default</small>
                        </div>

                        <div class="form-group">
                            <label for="closing_date" class="required">Closing Date</label>
                            <div class="date-input-group">
                                <input type="date"
                                    name="closing_date"
                                    id="closing_date"
                                    class="form-control"
                                    required
                                    onchange="calculateTermDuration()">
                            </div>
                            <div class="validation-error" id="closing_date_error"></div>
                            <div class="term-duration" id="term_duration"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="term_status">Status</label>
                        <select name="status" id="term_status" class="form-control">
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                        </select>
                        <small class="text-muted">Only one term can be active at a time per academic year</small>
                    </div>

                    <div class="form-group">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks"
                            id="remarks"
                            class="form-control"
                            rows="3"
                            placeholder="Optional notes about this term"></textarea>
                    </div>

                    <div class="modal-buttons">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('addTermModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="add_term" class="btn btn-success">
                            <i class="fas fa-plus"></i> Add Term
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Academic Year Modal -->
    <div id="editYearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Academic Year</h3>
                <button class="close-btn" onclick="closeModal('editYearModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editYearForm" method="POST">
                    <input type="hidden" name="year_id" id="edit_year_id">

                    <div class="form-group">
                        <label for="edit_start_year" class="required">Start Year</label>
                        <input type="number"
                            name="start_year"
                            id="edit_start_year"
                            class="form-control"
                            min="2000"
                            max="2100"
                            required
                            onchange="updateEditYearName()">
                        <div class="validation-error" id="edit_start_year_error"></div>
                        <small class="text-muted">The canonical start year (e.g., 2025)</small>
                    </div>

                    <div class="form-group">
                        <label class="required">Display Format</label>
                        <div class="radio-group">
                            <label class="radio-label">
                                <input type="radio"
                                    name="display_format"
                                    value="single"
                                    id="edit_format_single"
                                    onchange="updateEditYearName()">
                                Single Year (e.g., "2025")
                            </label>
                            <label class="radio-label">
                                <input type="radio"
                                    name="display_format"
                                    value="range"
                                    id="edit_format_range"
                                    onchange="updateEditYearName()">
                                Year Range (e.g., "2025/2026")
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Preview</label>
                        <div class="year-preview" id="edit_display_name"></div>
                        <small class="text-muted">This will be generated automatically based on your selections</small>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_status">Status</label>
                            <select name="status" id="edit_status" class="form-control">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="checkbox-label">
                                <input type="checkbox"
                                    name="is_current"
                                    id="edit_is_current"
                                    value="1">
                                Set as Current Academic Year
                            </label>
                            <small class="text-muted">Only one academic year can be current at a time</small>
                        </div>
                    </div>

                    <div class="modal-buttons">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editYearModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="update_year" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Academic Term Modal -->
    <div id="editTermModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Edit Academic Term</h3>
                <button class="close-btn" onclick="closeModal('editTermModal')">&times;</button>
            </div>
            <div class="modal-body">
                <form id="editTermForm" method="POST">
                    <input type="hidden" name="term_id" id="edit_term_id">

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_academic_year_id" class="required">Academic Year</label>
                            <select name="academic_year_id" id="edit_academic_year_id" class="form-control" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= $year['id'] ?>">
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="validation-error" id="edit_academic_year_id_error"></div>
                        </div>

                        <div class="form-group">
                            <label for="edit_term_name" class="required">Term Name</label>
                            <select name="term_name" id="edit_term_name" class="form-control" required>
                                <option value="">Select Term</option>
                                <option value="Term I">Term I</option>
                                <option value="Term II">Term II</option>
                                <option value="Term III">Term III</option>
                            </select>
                            <div class="validation-error" id="edit_term_name_error"></div>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label for="edit_opening_date" class="required">Opening Date</label>
                            <div class="date-input-group">
                                <input type="date"
                                    name="opening_date"
                                    id="edit_opening_date"
                                    class="form-control"
                                    required
                                    onchange="updateEditClosingDateMin()">
                            </div>
                            <div class="validation-error" id="edit_opening_date_error"></div>
                        </div>

                        <div class="form-group">
                            <label for="edit_closing_date" class="required">Closing Date</label>
                            <div class="date-input-group">
                                <input type="date"
                                    name="closing_date"
                                    id="edit_closing_date"
                                    class="form-control"
                                    required
                                    onchange="calculateEditTermDuration()">
                            </div>
                            <div class="validation-error" id="edit_closing_date_error"></div>
                            <div class="term-duration" id="edit_term_duration"></div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="edit_term_status">Status</label>
                        <select name="status" id="edit_term_status" class="form-control">
                            <option value="inactive">Inactive</option>
                            <option value="active">Active</option>
                        </select>
                        <small class="text-muted">Only one term can be active at a time per academic year</small>
                    </div>

                    <div class="form-group">
                        <label for="edit_term_remarks">Remarks</label>
                        <textarea name="remarks"
                            id="edit_term_remarks"
                            class="form-control"
                            rows="3"
                            placeholder="Optional notes about this term"></textarea>
                    </div>

                    <div class="modal-buttons">
                        <button type="button" class="btn btn-secondary" onclick="closeModal('editTermModal')">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" name="update_term" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modals -->
    <div id="deleteYearModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Academic Year</h3>
                <button class="close-btn" onclick="closeModal('deleteYearModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="confirmation-text" id="deleteYearText"></p>
                <div class="modal-buttons">
                    <button class="btn btn-secondary" onclick="closeModal('deleteYearModal')">Cancel</button>
                    <a href="#" id="deleteYearLink" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <div id="deleteTermModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Delete Academic Term</h3>
                <button class="close-btn" onclick="closeModal('deleteTermModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="confirmation-text" id="deleteTermText"></p>
                <div class="modal-buttons">
                    <button class="btn btn-secondary" onclick="closeModal('deleteTermModal')">Cancel</button>
                    <a href="#" id="deleteTermLink" class="btn btn-danger">Delete</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Term Action Confirmation Modals -->
    <div id="activateTermModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Activate Term</h3>
                <button class="close-btn" onclick="closeModal('activateTermModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="confirmation-text" id="activateTermText"></p>
                <div class="modal-buttons">
                    <button class="btn btn-secondary" onclick="closeModal('activateTermModal')">Cancel</button>
                    <a href="#" id="activateTermLink" class="btn btn-success">Activate</a>
                </div>
            </div>
        </div>
    </div>

    <div id="closeTermModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Close Term</h3>
                <button class="close-btn" onclick="closeModal('closeTermModal')">&times;</button>
            </div>
            <div class="modal-body">
                <p class="confirmation-text" id="closeTermText"></p>
                <div class="modal-buttons">
                    <button class="btn btn-secondary" onclick="closeModal('closeTermModal')">Cancel</button>
                    <a href="#" id="closeTermLink" class="btn btn-warning">Close</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Tab switching functionality - Fixed version
        function switchTab(tabName) {
            // Update URL to preserve tab state
            const url = new URL(window.location);
            url.searchParams.set('tab', tabName);
            window.history.pushState({}, '', url);

            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });

            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked tab
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                if (tab.textContent.includes(tabName === 'years' ? 'Years' : 'Terms')) {
                    tab.classList.add('active');
                }
            });
        }

        // Open modals
        function openYearModal() {
            openModal('addYearModal');
            updateYearName(); // Initialize display name
        }

        function openTermModal() {
            openModal('addTermModal');
            updateClosingDateMin(); // Set min date for closing date
            calculateTermDuration(); // Calculate initial duration
        }

        // Update display name based on year and format selections
        function updateYearName() {
            const startYear = document.getElementById('start_year');
            const displayFormat = document.querySelector('#addYearForm input[name="display_format"]:checked');
            const displayName = document.getElementById('display_name');

            if (startYear && displayFormat && displayName) {
                const yearValue = startYear.value;
                if (yearValue && yearValue.length === 4) {
                    if (displayFormat.value === 'single') {
                        displayName.textContent = yearValue;
                    } else {
                        displayName.textContent = yearValue + '/' + (parseInt(yearValue) + 1);
                    }
                } else {
                    displayName.textContent = '';
                }
            }
        }

        // Update edit display name
        function updateEditYearName() {
            const startYear = document.getElementById('edit_start_year');
            const displayFormat = document.querySelector('#editYearForm input[name="display_format"]:checked');
            const displayName = document.getElementById('edit_display_name');

            if (startYear && displayFormat && displayName) {
                const yearValue = startYear.value;
                if (yearValue && yearValue.length === 4) {
                    if (displayFormat.value === 'single') {
                        displayName.textContent = yearValue;
                    } else {
                        displayName.textContent = yearValue + '/' + (parseInt(yearValue) + 1);
                    }
                } else {
                    displayName.textContent = '';
                }
            }
        }

        // Date picker functions
        function updateClosingDateMin() {
            const openingDate = document.getElementById('opening_date');
            const closingDate = document.getElementById('closing_date');

            if (openingDate && openingDate.value) {
                if (closingDate) {
                    closingDate.min = openingDate.value;

                    // If closing date is before opening date, reset it
                    if (closingDate.value && closingDate.value < openingDate.value) {
                        closingDate.value = openingDate.value;
                    }

                    calculateTermDuration();
                }
            }
        }

        function updateEditClosingDateMin() {
            const openingDate = document.getElementById('edit_opening_date');
            const closingDate = document.getElementById('edit_closing_date');

            if (openingDate && openingDate.value) {
                if (closingDate) {
                    closingDate.min = openingDate.value;

                    // If closing date is before opening date, reset it
                    if (closingDate.value && closingDate.value < openingDate.value) {
                        closingDate.value = openingDate.value;
                    }

                    calculateEditTermDuration();
                }
            }
        }

        function calculateTermDuration() {
            const openingDate = document.getElementById('opening_date');
            const closingDate = document.getElementById('closing_date');
            const durationDisplay = document.getElementById('term_duration');

            if (openingDate && closingDate && durationDisplay) {
                if (openingDate.value && closingDate.value) {
                    const opening = new Date(openingDate.value);
                    const closing = new Date(closingDate.value);

                    // Calculate difference in days
                    const diffTime = Math.abs(closing - opening);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

                    durationDisplay.textContent = `Duration: ${diffDays} days`;

                    // Show warning if duration is less than 7 days
                    if (diffDays < 7) {
                        durationDisplay.style.color = '#dc3545';
                    } else {
                        durationDisplay.style.color = '#28a745';
                    }
                } else {
                    durationDisplay.textContent = '';
                }
            }
        }

        function calculateEditTermDuration() {
            const openingDate = document.getElementById('edit_opening_date');
            const closingDate = document.getElementById('edit_closing_date');
            const durationDisplay = document.getElementById('edit_term_duration');

            if (openingDate && closingDate && durationDisplay) {
                if (openingDate.value && closingDate.value) {
                    const opening = new Date(openingDate.value);
                    const closing = new Date(closingDate.value);

                    // Calculate difference in days
                    const diffTime = Math.abs(closing - opening);
                    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;

                    durationDisplay.textContent = `Duration: ${diffDays} days`;

                    // Show warning if duration is less than 7 days
                    if (diffDays < 7) {
                        durationDisplay.style.color = '#dc3545';
                    } else {
                        durationDisplay.style.color = '#28a745';
                    }
                } else {
                    durationDisplay.textContent = '';
                }
            }
        }

        // Edit Year function
        function editYear(id, yearName, startYear, displayFormat, status, isCurrent) {
            document.getElementById('edit_year_id').value = id;
            document.getElementById('edit_start_year').value = startYear;

            if (displayFormat === 'single') {
                document.getElementById('edit_format_single').checked = true;
            } else {
                document.getElementById('edit_format_range').checked = true;
            }

            document.getElementById('edit_status').value = status;
            document.getElementById('edit_is_current').checked = isCurrent == 1;

            updateEditYearName();
            openModal('editYearModal');
        }

        // Edit Term function
        function editTerm(id, academicYearId, termName, openingDate, closingDate, status, remarks) {
            document.getElementById('edit_term_id').value = id;
            document.getElementById('edit_academic_year_id').value = academicYearId;
            document.getElementById('edit_term_name').value = termName;
            document.getElementById('edit_opening_date').value = openingDate;
            document.getElementById('edit_closing_date').value = closingDate;
            document.getElementById('edit_term_status').value = status;
            document.getElementById('edit_term_remarks').value = remarks.replace(/\\/g, '');

            // Update closing date min based on opening date
            updateEditClosingDateMin();
            calculateEditTermDuration();

            openModal('editTermModal');
        }

        // Modal functions
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            const backdrop = document.getElementById('modalBackdrop');

            if (modal) {
                modal.classList.add('active');
                if (backdrop) backdrop.classList.add('active');
                document.body.style.overflow = 'hidden'; // Prevent background scrolling
            }
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            const backdrop = document.getElementById('modalBackdrop');

            if (modal) {
                modal.classList.remove('active');
                if (backdrop) backdrop.classList.remove('active');
                document.body.style.overflow = 'auto'; // Restore scrolling
            }

            // Reset validation errors
            document.querySelectorAll('.validation-error').forEach(el => {
                el.classList.remove('show');
                el.textContent = '';
            });

            document.querySelectorAll('.form-control.invalid').forEach(el => {
                el.classList.remove('invalid');
            });
        }

        function confirmDeleteYear(yearId, yearName) {
            document.getElementById('deleteYearText').textContent =
                `Are you sure you want to delete the academic year "${yearName}"? This action cannot be undone.`;
            document.getElementById('deleteYearLink').href = `?delete_year=${yearId}`;
            openModal('deleteYearModal');
        }

        function confirmDeleteTerm(termId, termName) {
            document.getElementById('deleteTermText').textContent =
                `Are you sure you want to delete the term "${termName}"? This action cannot be undone.`;
            document.getElementById('deleteTermLink').href = `?delete_term=${termId}`;
            openModal('deleteTermModal');
        }

        function confirmActivateTerm(termId, termName) {
            document.getElementById('activateTermText').textContent =
                `Are you sure you want to activate the term "${termName}"? This will deactivate any other active term in the same academic year.`;
            document.getElementById('activateTermLink').href = `?action=activate&term_id=${termId}`;
            openModal('activateTermModal');
        }

        function confirmCloseTerm(termId, termName) {
            document.getElementById('closeTermText').textContent =
                `Are you sure you want to close the term "${termName}"?`;
            document.getElementById('closeTermLink').href = `?action=close&term_id=${termId}`;
            openModal('closeTermModal');
        }

        // Form validation
        function validateYearForm() {
            let isValid = true;
            const startYear = document.getElementById('start_year');
            const errorElement = document.getElementById('start_year_error');

            if (!startYear.value || startYear.value.length !== 4) {
                startYear.classList.add('invalid');
                errorElement.textContent = 'Please enter a valid 4-digit year';
                errorElement.classList.add('show');
                isValid = false;
            } else if (parseInt(startYear.value) < 2000 || parseInt(startYear.value) > 2100) {
                startYear.classList.add('invalid');
                errorElement.textContent = 'Year must be between 2000 and 2100';
                errorElement.classList.add('show');
                isValid = false;
            } else {
                startYear.classList.remove('invalid');
                errorElement.classList.remove('show');
            }

            return isValid;
        }

        function validateTermForm() {
            let isValid = true;

            // Validate academic year
            const academicYear = document.getElementById('academic_year_id');
            const academicYearError = document.getElementById('academic_year_id_error');
            if (!academicYear.value) {
                academicYear.classList.add('invalid');
                academicYearError.textContent = 'Please select an academic year';
                academicYearError.classList.add('show');
                isValid = false;
            } else {
                academicYear.classList.remove('invalid');
                academicYearError.classList.remove('show');
            }

            // Validate term name
            const termName = document.getElementById('term_name');
            const termNameError = document.getElementById('term_name_error');
            if (!termName.value) {
                termName.classList.add('invalid');
                termNameError.textContent = 'Please select a term name';
                termNameError.classList.add('show');
                isValid = false;
            } else {
                termName.classList.remove('invalid');
                termNameError.classList.remove('show');
            }

            // Validate dates
            const openingDate = document.getElementById('opening_date');
            const openingDateError = document.getElementById('opening_date_error');
            const closingDate = document.getElementById('closing_date');
            const closingDateError = document.getElementById('closing_date_error');

            if (!openingDate.value) {
                openingDate.classList.add('invalid');
                openingDateError.textContent = 'Please select an opening date';
                openingDateError.classList.add('show');
                isValid = false;
            } else {
                openingDate.classList.remove('invalid');
                openingDateError.classList.remove('show');
            }

            if (!closingDate.value) {
                closingDate.classList.add('invalid');
                closingDateError.textContent = 'Please select a closing date';
                closingDateError.classList.add('show');
                isValid = false;
            } else if (openingDate.value && closingDate.value < openingDate.value) {
                closingDate.classList.add('invalid');
                closingDateError.textContent = 'Closing date must be after opening date';
                closingDateError.classList.add('show');
                isValid = false;
            } else {
                closingDate.classList.remove('invalid');
                closingDateError.classList.remove('show');
            }

            return isValid;
        }

        // Close modal when clicking outside
        document.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
                document.getElementById('modalBackdrop').classList.remove('active');
                document.body.style.overflow = 'auto';
            }
        });

        // Escape key to close modal
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                    document.getElementById('modalBackdrop').classList.remove('active');
                    document.body.style.overflow = 'auto';
                });
            }
        });

        // Form submission handlers
        const addYearForm = document.getElementById('addYearForm');
        if (addYearForm) {
            addYearForm.addEventListener('submit', function(e) {
                if (!validateYearForm()) {
                    e.preventDefault();
                }
            });
        }

        const addTermForm = document.getElementById('addTermForm');
        if (addTermForm) {
            addTermForm.addEventListener('submit', function(e) {
                if (!validateTermForm()) {
                    e.preventDefault();
                }
            });
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Set current date for date pickers
            const today = new Date().toISOString().split('T')[0];
            const openingDate = document.getElementById('opening_date');
            const closingDate = document.getElementById('closing_date');

            if (openingDate && !openingDate.value) {
                openingDate.value = today;
            }

            if (closingDate && openingDate) {
                // Set closing date to 30 days from opening
                const closing = new Date(openingDate.value);
                closing.setDate(closing.getDate() + 30);
                closingDate.value = closing.toISOString().split('T')[0];

                // Update closing date min
                updateClosingDateMin();
                calculateTermDuration();
            }

            // Initialize edit forms date limits
            const editOpeningDate = document.getElementById('edit_opening_date');
            const editClosingDate = document.getElementById('edit_closing_date');

            if (editOpeningDate && editClosingDate) {
                // Update closing date min when opening date changes
                editOpeningDate.addEventListener('change', function() {
                    updateEditClosingDateMin();
                });
            }

            // Dropdown menu functionality
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

            // Real-time validation for year input
            const yearInput = document.getElementById('start_year');
            if (yearInput) {
                yearInput.addEventListener('input', function() {
                    updateYearName();
                    validateYearForm();
                });
            }

            // Real-time validation for edit year input
            const editYearInput = document.getElementById('edit_start_year');
            if (editYearInput) {
                editYearInput.addEventListener('input', function() {
                    updateEditYearName();
                });
            }

            // Add event listeners to all tab buttons
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabName = this.textContent.includes('Years') ? 'years' : 'terms';
                    switchTab(tabName);
                });
            });

            // Initialize year name preview
            updateYearName();
        });
    </script>
</body>

</html>