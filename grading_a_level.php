<?php
require_once 'config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit;
}

// Fetch admin info
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = $admin ? htmlspecialchars($admin['fullname']) : "Admin";
    $email = $admin ? htmlspecialchars($admin['email']) : "—";
} catch (Exception $e) {
    $fullname = "Admin";
    $email = "—";
}

// Fetch school name for footer
$school_name = "School Management System";
try {
    $stmt = $pdo->prepare("SELECT school_name FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($school_info && !empty($school_info['school_name'])) {
        $school_name = htmlspecialchars($school_info['school_name']);
    }
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// Initialize variables
$message = '';
$error = '';

// Handle form submission for adding/updating grading
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate required fields
    $subject_category = $_POST['subject_category'] ?? '';
    $min_score = $_POST['min_score'] ?? '';
    $max_score = $_POST['max_score'] ?? '';
    $grade_letter = trim($_POST['grade_letter'] ?? '');
    $points = $_POST['points'] ?? '';
    $remark = trim($_POST['remark'] ?? '');
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

    // Validation
    $errors = [];

    if (!in_array($subject_category, ['Principal', 'Subsidiary'])) {
        $errors[] = "Please select a valid subject category.";
    }

    if (!is_numeric($min_score) || $min_score < 0 || $min_score > 100) {
        $errors[] = "Minimum score must be a number between 0 and 100.";
    }

    if (!is_numeric($max_score) || $max_score < 0 || $max_score > 100) {
        $errors[] = "Maximum score must be a number between 0 and 100.";
    }

    if ($min_score >= $max_score) {
        $errors[] = "Minimum score must be less than maximum score.";
    }

    if (empty($grade_letter)) {
        $errors[] = "Grade letter is required.";
    }

    // Validate grade letter based on category
    if ($subject_category === 'Principal') {
        $valid_principal_grades = ['A', 'B', 'C', 'D', 'E', 'O', 'F'];
        if (!in_array(strtoupper($grade_letter), $valid_principal_grades)) {
            $errors[] = "For Principal subjects, grade must be one of: A, B, C, D, E, O, F";
        }

        if (!is_numeric($points) || $points < 0 || $points > 6) {
            $errors[] = "Points must be between 0 and 6 for Principal subjects.";
        } else {
            // Validate points mapping
            $expected_points = [
                'A' => 6,
                'B' => 5,
                'C' => 4,
                'D' => 3,
                'E' => 2,
                'O' => 1,
                'F' => 0
            ];
            if ((int)$points !== $expected_points[strtoupper($grade_letter)]) {
                $errors[] = "Points must be " . $expected_points[strtoupper($grade_letter)] .
                    " for grade " . strtoupper($grade_letter);
            }
        }
    } else { // Subsidiary
        $valid_subsidiary_grades = [
            'D1',
            'D2',
            'C3',
            'C4',
            'C5',
            'C6',
            'P7',
            'P8',
            'F9'
        ];

        $grade_upper = strtoupper($grade_letter);
        if (!in_array($grade_upper, $valid_subsidiary_grades)) {
            $errors[] = "For Subsidiary subjects, grade must be one of: D1, D2, C3, C4, C5, C6, P7, P8, F9";
        }

        if (!is_numeric($points) || $points < 0 || $points > 1) {
            $errors[] = "Points must be 0 or 1 for Subsidiary subjects.";
        } else {
            // Validate points mapping based on the numeric part
            preg_match('/(\d+)$/', $grade_upper, $matches);
            if (isset($matches[1])) {
                $grade_num = (int)$matches[1];
                $expected_points = ($grade_num >= 1 && $grade_num <= 6) ? 1 : 0;
                if ((int)$points !== $expected_points) {
                    $errors[] = "Points must be " . $expected_points . " for grade " . $grade_upper;
                }
            }
        }
    }

    // Check for overlapping score ranges
    if (empty($errors)) {
        try {
            $sql = "SELECT id FROM alevel_grading_scale 
                    WHERE subject_category = :category 
                    AND ((:min BETWEEN min_score AND max_score) 
                         OR (:max BETWEEN min_score AND max_score)
                         OR (min_score BETWEEN :min AND :max)
                         OR (max_score BETWEEN :min AND :max))";

            if ($id > 0) {
                $sql .= " AND id != :id";
            }

            $stmt = $pdo->prepare($sql);
            $stmt->bindParam(':category', $subject_category);
            $stmt->bindParam(':min', $min_score);
            $stmt->bindParam(':max', $max_score);

            if ($id > 0) {
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
            }

            $stmt->execute();

            if ($stmt->rowCount() > 0) {
                $errors[] = "Score range overlaps with an existing grade in this category.";
            }
        } catch (PDOException $e) {
            error_log("Error checking score ranges: " . $e->getMessage());
        }
    }

    // If no errors, save to database
    if (empty($errors)) {
        try {
            if ($id > 0) {
                // Update existing record
                $stmt = $pdo->prepare("UPDATE alevel_grading_scale 
                                       SET subject_category = ?, min_score = ?, max_score = ?,
                                           grade_letter = ?, points = ?, remark = ?
                                       WHERE id = ?");
                $stmt->execute([
                    $subject_category,
                    $min_score,
                    $max_score,
                    strtoupper($grade_letter),
                    $points,
                    $remark,
                    $id
                ]);

                // Log activity
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) 
                                          VALUES (?, 'UPDATE_GRADE', ?, ?)");
                $log_stmt->execute([
                    $_SESSION['user_id'],
                    "Updated A-Level grade: $grade_letter for category: $subject_category",
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                $message = "Grade updated successfully!";
            } else {
                // Insert new record
                $stmt = $pdo->prepare("INSERT INTO alevel_grading_scale 
                                       (academic_level, subject_category, min_score, max_score, 
                                        grade_letter, points, remark) 
                                       VALUES ('A Level', ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $subject_category,
                    $min_score,
                    $max_score,
                    strtoupper($grade_letter),
                    $points,
                    $remark
                ]);

                // Log activity
                $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) 
                                          VALUES (?, 'ADD_GRADE', ?, ?)");
                $log_stmt->execute([
                    $_SESSION['user_id'],
                    "Added A-Level grade: $grade_letter for category: $subject_category",
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);

                $message = "Grade added successfully!";
            }
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) { // Duplicate entry
                $error = "A grade with this letter already exists for this category.";
            } else {
                $error = "Database error: " . $e->getMessage();
            }
            error_log("Error saving grade: " . $e->getMessage());
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    try {
        // Fetch grade info for logging
        $stmt = $pdo->prepare("SELECT grade_letter, subject_category FROM alevel_grading_scale WHERE id = ?");
        $stmt->execute([$id]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($grade) {
            $stmt = $pdo->prepare("DELETE FROM alevel_grading_scale WHERE id = ?");
            $stmt->execute([$id]);

            // Log activity
            $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address) 
                                      VALUES (?, 'DELETE_GRADE', ?, ?)");
            $log_stmt->execute([
                $_SESSION['user_id'],
                "Deleted A-Level grade: {$grade['grade_letter']} for category: {$grade['subject_category']}",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            $message = "Grade deleted successfully!";
        }
    } catch (PDOException $e) {
        $error = "Error deleting grade: " . $e->getMessage();
    }
}

// Fetch all grades
$grades = [];
try {
    $stmt = $pdo->query("SELECT * FROM alevel_grading_scale ORDER BY subject_category, min_score DESC");
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error fetching grades: " . $e->getMessage();
}

// Group grades by category
$principal_grades = array_filter($grades, function ($g) {
    return $g['subject_category'] === 'Principal';
});
$subsidiary_grades = array_filter($grades, function ($g) {
    return $g['subject_category'] === 'Subsidiary';
});

// Sort principal grades by points (descending)
usort($principal_grades, function ($a, $b) {
    return $b['points'] - $a['points'];
});

// Sort subsidiary grades by grade
usort($subsidiary_grades, function ($a, $b) {
    // Extract numeric parts
    preg_match('/(\d+)$/', $a['grade_letter'], $a_matches);
    preg_match('/(\d+)$/', $b['grade_letter'], $b_matches);

    $a_num = isset($a_matches[1]) ? (int)$a_matches[1] : 0;
    $b_num = isset($b_matches[1]) ? (int)$b_matches[1] : 0;

    return $a_num - $b_num;
});
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>A-Level Grading System - Admin Dashboard</title>
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
            --warning: #ffc107;
            --danger: #dc3545;
            --info: #17a2b8;
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

        /* Page Header */
        .page-header {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .page-header h2 {
            color: var(--primary);
            font-size: 1.5rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn {
            padding: 0.5rem 1rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.2s;
        }

        .btn:hover {
            background: #0f1d4d;
            transform: translateY(-1px);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
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

        .btn.danger {
            background: var(--danger);
        }

        .btn.danger:hover {
            background: #bb2d3b;
        }

        .btn.success {
            background: var(--success);
        }

        .btn.success:hover {
            background: #218838;
        }

        /* Alert Messages */
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
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

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 10000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            animation: fadeIn 0.3s;
            overflow-y: auto;
        }

        .modal-content {
            background-color: white;
            margin: 2% auto;
            padding: 1.5rem;
            border-radius: 12px;
            width: 90%;
            max-width: 650px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            animation: slideIn 0.3s;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 0.75rem;
            border-bottom: 2px solid #eee;
        }

        .modal-header h3 {
            color: var(--primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.3rem;
        }

        .close-btn {
            background: none;
            border: none;
            font-size: 1.8rem;
            cursor: pointer;
            color: #6c757d;
            transition: color 0.2s;
        }

        .close-btn:hover {
            color: var(--primary);
        }

        .modal-footer {
            margin-top: 1.5rem;
            display: flex;
            gap: 0.75rem;
            justify-content: flex-end;
            border-top: 1px solid #eee;
            padding-top: 1.5rem;
        }

        /* Form Styles */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.2rem;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-group.full-width {
            grid-column: span 2;
        }

        .form-group label {
            font-weight: 600;
            font-size: 0.9rem;
            color: #495057;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .form-group label i {
            color: var(--primary);
            font-size: 0.85rem;
        }

        .form-group label span.required {
            color: var(--danger);
            margin-left: 3px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 0.7rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26, 42, 108, 0.1);
        }

        .form-group input[readonly] {
            background: #e9ecef;
            cursor: not-allowed;
        }

        .hint-text {
            font-size: 0.8rem;
            color: #6c757d;
            margin-top: 0.25rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .hint-text i {
            font-size: 0.75rem;
            color: var(--info);
        }

        /* Table Styles - Clean and Simple */
        .table-container {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            margin-bottom: 1.5rem;
            overflow-x: auto;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f0f0;
        }

        .table-title {
            color: var(--primary);
            font-size: 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
        }

        .table-title i {
            font-size: 1.1rem;
        }

        .category-badge {
            background: #e9ecef;
            color: var(--primary);
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            background: #f8f9fa;
            color: var(--primary);
            padding: 0.75rem;
            text-align: left;
            font-size: 0.9rem;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
        }

        td {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
            font-size: 0.9rem;
        }

        tr:hover td {
            background: #f8f9fa;
        }

        .grade-cell {
            font-weight: 700;
            font-size: 1rem;
        }

        .grade-cell.principal {
            color: var(--primary);
        }

        .grade-cell.subsidiary {
            color: var(--secondary);
        }

        .score-range {
            font-family: cursive;
            font-weight: 600;
        }

        .points-badge {
            background: var(--primary);
            color: white;
            padding: 0.2rem 0.6rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            display: inline-block;
        }

        .remark-cell {
            max-width: 250px;
            color: #495057;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            color: white;
        }

        .action-btn.edit {
            background: var(--primary);
        }

        .action-btn.edit:hover {
            background: #0f1d4d;
        }

        .action-btn.delete {
            background: var(--danger);
        }

        .action-btn.delete:hover {
            background: #bb2d3b;
        }

        /* Delete Modal */
        .delete-modal {
            max-width: 400px;
            text-align: center;
        }

        .delete-modal .warning-icon {
            font-size: 3rem;
            color: var(--danger);
            margin-bottom: 1rem;
        }

        .delete-modal p {
            margin: 1rem 0;
            font-size: 1rem;
        }

        .delete-modal .grade-highlight {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 0.9rem;
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

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
            }
        }

        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full-width {
                grid-column: span 1;
            }

            .modal-footer {
                flex-direction: column;
            }

            .modal-footer .btn {
                width: 100%;
                justify-content: center;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }

            table {
                display: block;
                overflow-x: auto;
            }
        }

        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
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
                    <li class="nested active">
                        <a href="#" class="nav-link dropdown-toggle">
                            <span>A-Level Assessment</span>
                        </a>
                        <ul class="nested-menu">
                            <li><a href="enter_marks.php" class="nav-link">Add Marks</a></li>
                            <li><a href="marksheets.php" class="nav-link">View Marks</a></li>
                            <li><a href="grading_a_level.php" class="nav-link" style="background: rgba(255,255,255,0.2);">Set Grading</a></li>
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
            <div class="page-header">
                <h2>
                    <i class="fas fa-chart-line"></i>
                    A-Level Grading System
                </h2>
                <button class="btn success" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Grade
                </button>
            </div>

            <!-- Alert Messages -->
            <?php if ($message): ?>
                <div class="alert success">
                    <i class="fas fa-check-circle"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?= $error ?>
                </div>
            <?php endif; ?>

            <!-- Principal Grades Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-star" style="color: #ffc107;"></i>
                        Principal Subject Grades
                        <span class="category-badge"><?= count($principal_grades) ?> grades</span>
                    </h3>
                </div>

                <?php if (count($principal_grades) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Grade</th>
                                <th>Score Range</th>
                                <th>Points</th>
                                <th>Remark</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($principal_grades as $grade): ?>
                                <tr>
                                    <td class="grade-cell principal">
                                        <strong><?= htmlspecialchars($grade['grade_letter']) ?></strong>
                                    </td>
                                    <td class="score-range">
                                        <?= number_format($grade['min_score'], 1) ?> - <?= number_format($grade['max_score'], 1) ?>
                                    </td>
                                    <td>
                                        <span class="points-badge"><?= $grade['points'] ?> points</span>
                                    </td>
                                    <td class="remark-cell">
                                        <?= htmlspecialchars($grade['remark'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($grade)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="showDeleteModal(<?= $grade['id'] ?>, '<?= htmlspecialchars($grade['grade_letter']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-star"></i>
                        <p>No Principal grades defined yet. Click "Add New Grade" to create one.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Subsidiary Grades Table -->
            <div class="table-container">
                <div class="table-header">
                    <h3 class="table-title">
                        <i class="fas fa-layer-group" style="color: var(--secondary);"></i>
                        Subsidiary Subject Grades
                        <span class="category-badge"><?= count($subsidiary_grades) ?> grades</span>
                    </h3>
                </div>

                <?php if (count($subsidiary_grades) > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Grade</th>
                                <th>Score Range</th>
                                <th>Points</th>
                                <th>Remark</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subsidiary_grades as $grade): ?>
                                <tr>
                                    <td class="grade-cell subsidiary">
                                        <strong><?= htmlspecialchars($grade['grade_letter']) ?></strong>
                                    </td>
                                    <td class="score-range">
                                        <?= number_format($grade['min_score'], 1) ?> - <?= number_format($grade['max_score'], 1) ?>
                                    </td>
                                    <td>
                                        <span class="points-badge"><?= $grade['points'] ?> <?= $grade['points'] == 1 ? 'point' : 'points' ?></span>
                                    </td>
                                    <td class="remark-cell">
                                        <?= htmlspecialchars($grade['remark'] ?? '—') ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn edit" onclick="openEditModal(<?= htmlspecialchars(json_encode($grade)) ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="showDeleteModal(<?= $grade['id'] ?>, '<?= htmlspecialchars($grade['grade_letter']) ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-layer-group"></i>
                        <p>No Subsidiary grades defined yet. Click "Add New Grade" to create one.</p>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
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

    <!-- Add/Edit Grade Modal -->
    <div id="gradeModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">
                    <i class="fas fa-plus-circle"></i>
                    Add New Grade
                </h3>
                <button class="close-btn" onclick="closeModal()">&times;</button>
            </div>

            <form method="POST" id="gradeForm">
                <input type="hidden" name="id" id="gradeId" value="0">

                <div class="form-grid">
                    <!-- Academic Level (Read-only) -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-graduation-cap"></i>
                            Academic Level <span class="required">*</span>
                        </label>
                        <input type="text" value="A Level" readonly>
                        <input type="hidden" name="academic_level" value="A Level">
                    </div>

                    <!-- Subject Category -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-tag"></i>
                            Subject Category <span class="required">*</span>
                        </label>
                        <select name="subject_category" id="modalSubjectCategory" required>
                            <option value="">Select Category</option>
                            <option value="Principal">Principal</option>
                            <option value="Subsidiary">Subsidiary</option>
                        </select>
                    </div>

                    <!-- Minimum Score -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-arrow-left"></i>
                            Minimum Score (0-100) <span class="required">*</span>
                        </label>
                        <input type="number" name="min_score" id="modalMinScore" step="0.1" min="0" max="100" required>
                    </div>

                    <!-- Maximum Score -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-arrow-right"></i>
                            Maximum Score (0-100) <span class="required">*</span>
                        </label>
                        <input type="number" name="max_score" id="modalMaxScore" step="0.1" min="0" max="100" required>
                    </div>

                    <!-- Grade -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-font"></i>
                            Grade <span class="required">*</span>
                        </label>
                        <input type="text" name="grade_letter" id="modalGradeLetter" maxlength="5" required placeholder="e.g., A, B, D1, C3">
                        <div class="hint-text" id="modalGradeHint">
                            <i class="fas fa-info-circle"></i>
                            Principal: A,B,C,D,E,O,F | Subsidiary: D1, D2, C3, C4, C5, C6, P7, P8, F9
                        </div>
                    </div>

                    <!-- Points -->
                    <div class="form-group">
                        <label>
                            <i class="fas fa-star"></i>
                            Points <span class="required">*</span>
                        </label>
                        <input type="number" name="points" id="modalPoints" min="0" max="6" required>
                        <div class="hint-text" id="modalPointsHint">
                            <i class="fas fa-info-circle"></i>
                            Principal: 0-6 | Subsidiary: 0-1
                        </div>
                    </div>

                    <!-- Remark -->
                    <div class="form-group full-width">
                        <label>
                            <i class="fas fa-comment"></i>
                            Remark
                        </label>
                        <textarea name="remark" id="modalRemark" rows="3" placeholder="Optional description"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn success">
                        <i class="fas fa-save"></i> Save Grade
                    </button>
                    <button type="button" class="btn outline" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content delete-modal">
            <div class="warning-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <h3>Confirm Deletion</h3>
            <p>Are you sure you want to delete grade</p>
            <p class="grade-highlight" id="deleteGradeName"></p>
            <p style="color: var(--danger);">This action cannot be undone.</p>

            <div class="modal-footer" style="justify-content: center;">
                <a href="#" id="confirmDeleteBtn" class="btn danger">
                    <i class="fas fa-trash"></i> Yes, Delete
                </a>
                <button class="btn outline" onclick="closeDeleteModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </div>
    </div>

    <script>
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

        // Modal functions
        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add New Grade';
            document.getElementById('gradeId').value = '0';
            document.getElementById('modalSubjectCategory').value = '';
            document.getElementById('modalMinScore').value = '';
            document.getElementById('modalMaxScore').value = '';
            document.getElementById('modalGradeLetter').value = '';
            document.getElementById('modalPoints').value = '';
            document.getElementById('modalRemark').value = '';

            // Reset hints
            document.getElementById('modalGradeHint').innerHTML = '<i class="fas fa-info-circle"></i> Principal: A,B,C,D,E,O,F | Subsidiary: D1, D2, C3, C4, C5, C6, P7, P8, F9';
            document.getElementById('modalPointsHint').innerHTML = '<i class="fas fa-info-circle"></i> Principal: 0-6 | Subsidiary: 0-1';

            document.getElementById('gradeModal').style.display = 'block';
        }

        function openEditModal(grade) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Grade';
            document.getElementById('gradeId').value = grade.id;
            document.getElementById('modalSubjectCategory').value = grade.subject_category;
            document.getElementById('modalMinScore').value = grade.min_score;
            document.getElementById('modalMaxScore').value = grade.max_score;
            document.getElementById('modalGradeLetter').value = grade.grade_letter;
            document.getElementById('modalPoints').value = grade.points;
            document.getElementById('modalRemark').value = grade.remark || '';

            // Update hints based on category
            updateModalGradeHint(grade.subject_category);

            document.getElementById('gradeModal').style.display = 'block';
        }

        function closeModal() {
            document.getElementById('gradeModal').style.display = 'none';
        }

        // Delete modal functions
        function showDeleteModal(id, gradeName) {
            document.getElementById('deleteGradeName').textContent = gradeName;
            document.getElementById('confirmDeleteBtn').href = '?delete=' + id;
            document.getElementById('deleteModal').style.display = 'block';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Modal form validation based on category
        document.getElementById('modalSubjectCategory').addEventListener('change', function() {
            updateModalGradeHint(this.value);
        });

        function updateModalGradeHint(category) {
            const gradeHint = document.getElementById('modalGradeHint');
            const pointsHint = document.getElementById('modalPointsHint');

            if (category === 'Principal') {
                gradeHint.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Valid grades: A, B, C, D, E, O, F';
                pointsHint.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Points: A=6, B=5, C=4, D=3, E=2, O=1, F=0';
            } else if (category === 'Subsidiary') {
                gradeHint.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Valid grades: D1, D2, C3, C4, C5, C6, P7, P8, F9';
                pointsHint.innerHTML = '<i class="fas fa-check-circle" style="color: var(--success);"></i> Points: D1-6=1 point, P7-F9=0 points';
            } else {
                gradeHint.innerHTML = '<i class="fas fa-info-circle"></i> Principal: A,B,C,D,E,O,F | Subsidiary: D1, D2, C3, C4, C5, C6, P7, P8, F9';
                pointsHint.innerHTML = '<i class="fas fa-info-circle"></i> Principal: 0-6 | Subsidiary: 0-1';
            }
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }

        // Sidebar hover for mobile
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

        // Auto-dismiss alerts after 5 seconds
        setTimeout(() => {
            document.querySelectorAll('.alert').forEach(alert => {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 500);
            });
        }, 5000);
    </script>
</body>

</html>