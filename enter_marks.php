<?php
require_once 'config.php';

// Check if user is logged in and is a teacher or admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header("Location: index.php");
    exit;
}

$is_admin = ($_SESSION['role'] === 'admin');
$teacher_id = $_SESSION['user_id'];

// Fetch teacher info
try {
    $stmt = $pdo->prepare("SELECT fullname, email, teacher_id FROM users WHERE id = ?");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacher) {
        throw new Exception("Teacher not found.");
    }
    $fullname = htmlspecialchars($teacher['fullname']);
    $email = htmlspecialchars($teacher['email']);
    $teacher_id_display = htmlspecialchars($teacher['teacher_id'] ?? '—');
} catch (Exception $e) {
    $fullname = "Teacher";
    $email = "—";
    $teacher_id_display = "—";
}

// Fetch academic years
$academic_years = [];
try {
    $stmt = $pdo->prepare("SELECT id, year_name FROM academic_years WHERE status = 'active' ORDER BY start_year DESC");
    $stmt->execute();
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
}

// Fetch levels where teacher has subjects assigned
$teacher_levels = [];
try {
    if (!$is_admin) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT l.id, l.name 
            FROM teacher_subjects ts
            JOIN subjects s ON ts.subject_id = s.id
            JOIN levels l ON s.level_id = l.id
            WHERE ts.teacher_id = ? AND l.status = 'active'
            ORDER BY l.name
        ");
        $stmt->execute([$teacher_id]);
    } else {
        // Admin can see all levels
        $stmt = $pdo->prepare("SELECT id, name FROM levels WHERE status = 'active' ORDER BY name");
        $stmt->execute();
    }
    $teacher_levels = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching teacher levels: " . $e->getMessage());
}

// Handle form submission
$message = '';
$message_type = '';

// Store all form data in session to persist after POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_SESSION['form_data'] = $_POST;

    if (isset($_POST['load_students'])) {
        // Load students based on selection
        $selected_level = $_POST['level'] ?? '';
        $selected_class = $_POST['class'] ?? '';
        $selected_stream = $_POST['stream'] ?? '';
        $selected_term = $_POST['term'] ?? '';
        $selected_exam_type = $_POST['exam_type'] ?? '';
        $selected_subject = $_POST['subject'] ?? '';

        if (!$selected_level || !$selected_class || !$selected_stream || !$selected_term || !$selected_exam_type || !$selected_subject) {
            $message = "Please select all required fields.";
            $message_type = "error";
        }
    } elseif (isset($_POST['save_marks'])) {
        // Save marks to database
        $selected_level = $_POST['level'] ?? '';
        $selected_class = $_POST['class'] ?? '';
        $selected_stream = $_POST['stream'] ?? '';
        $selected_term = $_POST['term'] ?? '';
        $selected_exam_type = $_POST['exam_type'] ?? '';
        $selected_subject = $_POST['subject'] ?? '';
        $academic_year_id = $_POST['academic_year'] ?? '';

        try {
            // Begin transaction
            $pdo->beginTransaction();

            // Check which exam type we're saving
            if ($selected_exam_type === 'Aol') {
                // Save AoL marks (A1-A5)
                foreach ($_POST['student_marks'] as $student_id => $marks) {
                    $a1 = !empty($marks['a1']) ? round(floatval($marks['a1']), 1) : NULL;
                    $a2 = !empty($marks['a2']) ? round(floatval($marks['a2']), 1) : NULL;
                    $a3 = !empty($marks['a3']) ? round(floatval($marks['a3']), 1) : NULL;
                    $a4 = !empty($marks['a4']) ? round(floatval($marks['a4']), 1) : NULL;
                    $a5 = !empty($marks['a5']) ? round(floatval($marks['a5']), 1) : NULL;

                    // Validate marks (0-3 with 1 decimal place)
                    if ($a1 !== NULL && ($a1 < 0 || $a1 > 3)) {
                        throw new Exception("A1 mark for student $student_id must be between 0 and 3");
                    }
                    if ($a2 !== NULL && ($a2 < 0 || $a2 > 3)) {
                        throw new Exception("A2 mark for student $student_id must be between 0 and 3");
                    }
                    if ($a3 !== NULL && ($a3 < 0 || $a3 > 3)) {
                        throw new Exception("A3 mark for student $student_id must be between 0 and 3");
                    }
                    if ($a4 !== NULL && ($a4 < 0 || $a4 > 3)) {
                        throw new Exception("A4 mark for student $student_id must be between 0 and 3");
                    }
                    if ($a5 !== NULL && ($a5 < 0 || $a5 > 3)) {
                        throw new Exception("A5 mark for student $student_id must be between 0 and 3");
                    }

                    // Check if marks already exist for this student, term, subject, and exam type
                    $level_name = ($selected_level == 1) ? 'o_level_marks' : 'a_level_marks';

                    $check_stmt = $pdo->prepare("
                        SELECT id FROM $level_name 
                        WHERE student_id = ? 
                        AND academic_year_id = ? 
                        AND term_id = ? 
                        AND subject_id = ? 
                        AND exam_type = ?
                    ");
                    $check_stmt->execute([$student_id, $academic_year_id, $selected_term, $selected_subject, $selected_exam_type]);
                    $existing = $check_stmt->fetch();

                    if ($existing) {
                        // Update existing record
                        $update_stmt = $pdo->prepare("
                            UPDATE $level_name 
                            SET a1 = ?, a2 = ?, a3 = ?, a4 = ?, a5 = ?, 
                                entered_by = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $update_stmt->execute([$a1, $a2, $a3, $a4, $a5, $_SESSION['user_id'], $existing['id']]);
                    } else {
                        // Insert new record
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO $level_name 
                            (student_id, academic_year_id, term_id, class_id, stream_id, 
                             subject_id, exam_type, a1, a2, a3, a4, a5, entered_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([
                            $student_id,
                            $academic_year_id,
                            $selected_term,
                            $selected_class,
                            $selected_stream,
                            $selected_subject,
                            $selected_exam_type,
                            $a1,
                            $a2,
                            $a3,
                            $a4,
                            $a5,
                            $_SESSION['user_id']
                        ]);
                    }
                }
            } elseif ($selected_exam_type === 'Proj') {
                // Save Project marks
                foreach ($_POST['student_marks'] as $student_id => $marks) {
                    $project_score = !empty($marks['project_score']) ? round(floatval($marks['project_score']), 1) : NULL;

                    // Validate mark (0-10 with 1 decimal place)
                    if ($project_score !== NULL && ($project_score < 0 || $project_score > 10)) {
                        throw new Exception("Project score for student $student_id must be between 0 and 10");
                    }

                    // Check if marks already exist
                    $level_name = ($selected_level == 1) ? 'o_level_marks' : 'a_level_marks';

                    $check_stmt = $pdo->prepare("
                        SELECT id FROM $level_name 
                        WHERE student_id = ? 
                        AND academic_year_id = ? 
                        AND term_id = ? 
                        AND subject_id = ? 
                        AND exam_type = ?
                    ");
                    $check_stmt->execute([$student_id, $academic_year_id, $selected_term, $selected_subject, $selected_exam_type]);
                    $existing = $check_stmt->fetch();

                    if ($existing) {
                        // Update existing record
                        $update_stmt = $pdo->prepare("
                            UPDATE $level_name 
                            SET project_score = ?, entered_by = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $update_stmt->execute([$project_score, $_SESSION['user_id'], $existing['id']]);
                    } else {
                        // Insert new record
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO $level_name 
                            (student_id, academic_year_id, term_id, class_id, stream_id, 
                             subject_id, exam_type, project_score, entered_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([
                            $student_id,
                            $academic_year_id,
                            $selected_term,
                            $selected_class,
                            $selected_stream,
                            $selected_subject,
                            $selected_exam_type,
                            $project_score,
                            $_SESSION['user_id']
                        ]);
                    }
                }
            } elseif ($selected_exam_type === 'EoC') {
                // Save EoC marks
                foreach ($_POST['student_marks'] as $student_id => $marks) {
                    $eoc_score = !empty($marks['eoc_score']) ? round(floatval($marks['eoc_score']), 1) : NULL;

                    // Validate mark (0-80 with 1 decimal place)
                    if ($eoc_score !== NULL && ($eoc_score < 0 || $eoc_score > 80)) {
                        throw new Exception("EoC score for student $student_id must be between 0 and 80");
                    }

                    // Check if marks already exist
                    $level_name = ($selected_level == 1) ? 'o_level_marks' : 'a_level_marks';

                    $check_stmt = $pdo->prepare("
                        SELECT id FROM $level_name 
                        WHERE student_id = ? 
                        AND academic_year_id = ? 
                        AND term_id = ? 
                        AND subject_id = ? 
                        AND exam_type = ?
                    ");
                    $check_stmt->execute([$student_id, $academic_year_id, $selected_term, $selected_subject, $selected_exam_type]);
                    $existing = $check_stmt->fetch();

                    if ($existing) {
                        // Update existing record
                        $update_stmt = $pdo->prepare("
                            UPDATE $level_name 
                            SET eoc_score = ?, entered_by = ?, updated_at = CURRENT_TIMESTAMP 
                            WHERE id = ?
                        ");
                        $update_stmt->execute([$eoc_score, $_SESSION['user_id'], $existing['id']]);
                    } else {
                        // Insert new record
                        $insert_stmt = $pdo->prepare("
                            INSERT INTO $level_name 
                            (student_id, academic_year_id, term_id, class_id, stream_id, 
                             subject_id, exam_type, eoc_score, entered_by) 
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $insert_stmt->execute([
                            $student_id,
                            $academic_year_id,
                            $selected_term,
                            $selected_class,
                            $selected_stream,
                            $selected_subject,
                            $selected_exam_type,
                            $eoc_score,
                            $_SESSION['user_id']
                        ]);
                    }
                }
            }

            $pdo->commit();
            $message = "Marks saved successfully!";
            $message_type = "success";
        } catch (Exception $e) {
            $pdo->rollBack();
            $message = "Error saving marks: " . $e->getMessage();
            $message_type = "error";
        }
    }
}

// Initialize variables for student data
$students = [];
$selected_data = [
    'academic_year' => $_POST['academic_year'] ?? $_SESSION['form_data']['academic_year'] ?? '',
    'level' => $_POST['level'] ?? $_SESSION['form_data']['level'] ?? '',
    'class' => $_POST['class'] ?? $_SESSION['form_data']['class'] ?? '',
    'stream' => $_POST['stream'] ?? $_SESSION['form_data']['stream'] ?? '',
    'term' => $_POST['term'] ?? $_SESSION['form_data']['term'] ?? '',
    'exam_type' => $_POST['exam_type'] ?? $_SESSION['form_data']['exam_type'] ?? '',
    'subject' => $_POST['subject'] ?? $_SESSION['form_data']['subject'] ?? ''
];

// Also fetch the actual data for selected values to display
$selected_academic_year_name = '';
$selected_class_name = '';
$selected_stream_name = '';
$selected_subject_name = '';
$selected_term_name = '';

// Get academic year name
if ($selected_data['academic_year']) {
    try {
        $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
        $stmt->execute([$selected_data['academic_year']]);
        $year = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_academic_year_name = $year['year_name'] ?? '';
    } catch (Exception $e) {
        error_log("Error fetching academic year name: " . $e->getMessage());
    }
}

// Get class name
if ($selected_data['class']) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
        $stmt->execute([$selected_data['class']]);
        $class = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_class_name = $class['name'] ?? '';
    } catch (Exception $e) {
        error_log("Error fetching class name: " . $e->getMessage());
    }
}

// Get stream name
if ($selected_data['stream']) {
    try {
        $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
        $stmt->execute([$selected_data['stream']]);
        $stream = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_stream_name = $stream['name'] ?? '';
    } catch (Exception $e) {
        error_log("Error fetching stream name: " . $e->getMessage());
    }
}

// Get subject name
if ($selected_data['subject']) {
    try {
        $stmt = $pdo->prepare("SELECT code, name FROM subjects WHERE id = ?");
        $stmt->execute([$selected_data['subject']]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_subject_name = ($subject['code'] ?? '') . ' - ' . ($subject['name'] ?? '');
    } catch (Exception $e) {
        error_log("Error fetching subject name: " . $e->getMessage());
    }
}

// Get term name
if ($selected_data['term']) {
    try {
        $stmt = $pdo->prepare("SELECT term_name FROM academic_terms WHERE id = ?");
        $stmt->execute([$selected_data['term']]);
        $term = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_term_name = $term['term_name'] ?? '';
    } catch (Exception $e) {
        error_log("Error fetching term name: " . $e->getMessage());
    }
}

// Fetch terms for selected academic year (if any)
$filtered_terms = [];
if ($selected_data['academic_year']) {
    try {
        $stmt = $pdo->prepare("SELECT id, term_name FROM academic_terms WHERE academic_year_id = ? AND status = 'active' ORDER BY id");
        $stmt->execute([$selected_data['academic_year']]);
        $filtered_terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching filtered terms: " . $e->getMessage());
    }
}

// If form was submitted to load students, fetch them
if (
    isset($_POST['load_students']) && !empty($selected_data['level']) && !empty($selected_data['class']) &&
    !empty($selected_data['stream']) && !empty($selected_data['term']) && !empty($selected_data['exam_type']) &&
    !empty($selected_data['subject']) && !empty($selected_data['academic_year'])
) {

    try {
        // Fetch students in the selected class and stream who are assigned to the selected subject
        $stmt = $pdo->prepare("
            SELECT s.id, s.student_id, s.surname, s.other_names, s.sex, 
                   s.level_id, s.class_id, s.stream_id
            FROM students s
            JOIN student_subjects ss ON s.id = ss.student_id
            WHERE s.class_id = ? 
            AND s.stream_id = ?
            AND ss.subject_id = ? 
            AND s.status = 'active'
            ORDER BY s.surname, s.other_names
        ");
        $stmt->execute([$selected_data['class'], $selected_data['stream'], $selected_data['subject']]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch existing marks for these students
        $level_name = ($selected_data['level'] == 1) ? 'o_level_marks' : 'a_level_marks';
        $existing_marks = [];

        if (!empty($students)) {
            $student_ids = array_column($students, 'student_id');
            $placeholders = implode(',', array_fill(0, count($student_ids), '?'));

            $marks_stmt = $pdo->prepare("
                SELECT student_id, a1, a2, a3, a4, a5, project_score, eoc_score 
                FROM $level_name 
                WHERE student_id IN ($placeholders) 
                AND academic_year_id = ? 
                AND term_id = ? 
                AND subject_id = ? 
                AND exam_type = ?
            ");
            $params = array_merge($student_ids, [
                $selected_data['academic_year'],
                $selected_data['term'],
                $selected_data['subject'],
                $selected_data['exam_type']
            ]);
            $marks_stmt->execute($params);

            while ($mark = $marks_stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_marks[$mark['student_id']] = $mark;
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching students: " . $e->getMessage());
        $message = "Error loading students: " . $e->getMessage();
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Enter Marks - Teacher Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
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

        /* Form Section */
        .form-section {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .form-section h3 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-size: 1.3rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--primary);
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.95rem;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        .btn {
            padding: 0.5rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 0.95rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #152255;
        }

        .btn-success {
            background: #28a745;
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        /* Marks Table */
        .marks-table-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .marks-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }

        .marks-table th {
            background: var(--primary);
            color: white;
            padding: 0.75rem;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .marks-table td {
            padding: 0.75rem;
            border-bottom: 1px solid #eee;
        }

        .marks-table tr:hover {
            background: #f8f9fa;
        }

        .marks-table input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid #ced4da;
            border-radius: 4px;
            font-size: 0.9rem;
            text-align: center;
        }

        .marks-table input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(26, 42, 108, 0.25);
        }

        /* Message Styles */
        .alert {
            padding: 0.75rem 1.25rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            font-weight: 600;
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

        /* Info Box */
        .info-box {
            background: #e8f4fd;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .info-box p {
            margin: 0;
            color: #2c3e50;
            font-size: 0.9rem;
        }

        .info-box i {
            color: var(--primary);
            margin-right: 0.5rem;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: #dee2e6;
        }

        /* Loading */
        .loading {
            color: #6c757d;
            font-style: italic;
        }

        /* Selection Summary */
        .selection-summary {
            background: #f8f9fa;
            border-left: 4px solid var(--primary);
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            display: flex;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .selection-item {
            display: flex;
            flex-direction: column;
        }

        .selection-label {
            font-size: 0.85rem;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }

        .selection-value {
            font-weight: 600;
            color: var(--primary);
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
            .form-grid {
                grid-template-columns: 1fr;
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

            .selection-summary {
                flex-direction: column;
                gap: 0.5rem;
            }
        }

        @media (max-width: 576px) {
            .marks-table {
                font-size: 0.85rem;
            }

            .marks-table th,
            .marks-table td {
                padding: 0.5rem;
            }

            .main-content {
                padding: 1rem;
            }

            .form-section {
                padding: 1rem;
            }
        }
    </style>
</head>

<body>
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-chalkboard-teacher"></i>
            <span><?= $is_admin ? 'Admin Portal' : 'Teacher Portal' ?></span>
        </div>
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="<?= $is_admin ? 'admin_dashboard.php' : 'teacher_dashboard.php' ?>" class="nav-link">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="enter_marks.php" class="nav-link active">
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
                <h1>Enter Marks</h1>
                <p><?= $fullname ?> | <?= $email ?></p>
            </div>
            <div class="role-tag"><?= $is_admin ? 'Admin' : 'Teacher' ?></div>
        </header>

        <main class="main-content">
            <?php if ($message): ?>
                <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Selection Form -->
            <div class="form-section">
                <h3>Select Class and Exam</h3>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> Only students assigned to the selected subject and stream will be loaded for marks entry.</p>
                </div>

                <?php if ($selected_data['level'] && $selected_data['class'] && $selected_data['stream'] && $selected_data['subject']): ?>
                    <div class="selection-summary">
                        <div class="selection-item">
                            <span class="selection-label">Academic Year:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_academic_year_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Level:</span>
                            <span class="selection-value">
                                <?php
                                $level_name = '';
                                foreach ($teacher_levels as $level) {
                                    if ($level['id'] == $selected_data['level']) {
                                        $level_name = $level['name'];
                                        break;
                                    }
                                }
                                echo htmlspecialchars($level_name);
                                ?>
                            </span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Class:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_class_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Stream:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_stream_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Subject:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_subject_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Term:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_term_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Exam Type:</span>
                            <span class="selection-value">
                                <?php
                                $exam_type_names = [
                                    'Aol' => 'Activity of Integration',
                                    'Proj' => 'Project',
                                    'EoC' => 'End of Cycle'
                                ];
                                echo htmlspecialchars($exam_type_names[$selected_data['exam_type']] ?? $selected_data['exam_type']);
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="POST" id="selectionForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <select class="form-control" id="academic_year" name="academic_year" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= $year['id'] ?>"
                                        <?= $selected_data['academic_year'] == $year['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="term">Term *</label>
                            <select class="form-control" id="term" name="term" required>
                                <option value="">Select Term</option>
                                <?php
                                if ($selected_data['academic_year']):
                                    foreach ($filtered_terms as $term):
                                        $selected = ($selected_data['term'] == $term['id']) ? 'selected' : '';
                                ?>
                                        <option value="<?= $term['id'] ?>" <?= $selected ?>>
                                            <?= htmlspecialchars($term['term_name']) ?>
                                        </option>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="level">Level *</label>
                            <select class="form-control" id="level" name="level" required>
                                <option value="">Select Level</option>
                                <?php foreach ($teacher_levels as $level): ?>
                                    <option value="<?= $level['id'] ?>"
                                        <?= $selected_data['level'] == $level['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($level['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select class="form-control" id="class" name="class" required>
                                <option value="">Select Level First</option>
                                <?php if ($selected_data['class'] && $selected_class_name): ?>
                                    <option value="<?= $selected_data['class'] ?>" selected>
                                        <?= htmlspecialchars($selected_class_name) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="stream">Stream *</label>
                            <select class="form-control" id="stream" name="stream" required>
                                <option value="">Select Class First</option>
                                <?php if ($selected_data['stream'] && $selected_stream_name): ?>
                                    <option value="<?= $selected_data['stream'] ?>" selected>
                                        <?= htmlspecialchars($selected_stream_name) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="subject">Subject *</label>
                            <select class="form-control" id="subject" name="subject" required>
                                <option value="">Select Stream First</option>
                                <?php if ($selected_data['subject'] && $selected_subject_name): ?>
                                    <option value="<?= $selected_data['subject'] ?>" selected>
                                        <?= htmlspecialchars($selected_subject_name) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="exam_type">Exam Type *</label>
                            <select class="form-control" id="exam_type" name="exam_type" required>
                                <option value="">Select Exam Type</option>
                                <option value="Aol" <?= $selected_data['exam_type'] == 'Aol' ? 'selected' : '' ?>>AoI (Activity of Integration)</option>
                                <option value="Proj" <?= $selected_data['exam_type'] == 'Proj' ? 'selected' : '' ?>>Project</option>
                                <option value="EoC" <?= $selected_data['exam_type'] == 'EoC' ? 'selected' : '' ?>>EoC (End of Cycle)</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="load_students" class="btn btn-primary">
                            <i class="fas fa-users"></i> Load Students
                        </button>
                        <button type="button" id="clearForm" class="btn btn-danger">
                            <i class="fas fa-times"></i> Clear Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Marks Entry Form -->
            <?php if (!empty($students) && !empty($selected_data['exam_type'])): ?>
                <div class="form-section">
                    <h3>Enter Marks for <?=
                                        $selected_data['exam_type'] == 'Aol' ? 'Activity of Integration (AoI)' : ($selected_data['exam_type'] == 'Proj' ? 'Project' : 'End of Cycle (EoC)')
                                        ?></h3>

                    <div class="info-box">
                        <p><i class="fas fa-user-check"></i> Showing <?= count($students) ?> student(s) assigned to this subject in the selected stream.</p>
                    </div>

                    <form method="POST" id="marksForm">
                        <input type="hidden" name="academic_year" value="<?= $selected_data['academic_year'] ?>">
                        <input type="hidden" name="level" value="<?= $selected_data['level'] ?>">
                        <input type="hidden" name="class" value="<?= $selected_data['class'] ?>">
                        <input type="hidden" name="stream" value="<?= $selected_data['stream'] ?>">
                        <input type="hidden" name="term" value="<?= $selected_data['term'] ?>">
                        <input type="hidden" name="exam_type" value="<?= $selected_data['exam_type'] ?>">
                        <input type="hidden" name="subject" value="<?= $selected_data['subject'] ?>">

                        <div class="marks-table-container">
                            <table class="marks-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Sex</th>
                                        <?php if ($selected_data['exam_type'] == 'Aol'): ?>
                                            <th>A1 (0-3)</th>
                                            <th>A2 (0-3)</th>
                                            <th>A3 (0-3)</th>
                                            <th>A4 (0-3)</th>
                                            <th>A5 (0-3)</th>
                                        <?php elseif ($selected_data['exam_type'] == 'Proj'): ?>
                                            <th>PS (0-10)</th>
                                        <?php elseif ($selected_data['exam_type'] == 'EoC'): ?>
                                            <th>EoC (0-80)</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $index => $student):
                                        $existing_mark = $existing_marks[$student['student_id']] ?? [];
                                    ?>
                                        <tr>
                                            <td><?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($student['student_id']) ?></td>
                                            <td><?= htmlspecialchars($student['surname'] . ' ' . $student['other_names']) ?></td>
                                            <td><?= htmlspecialchars($student['sex']) ?></td>

                                            <?php if ($selected_data['exam_type'] == 'Aol'): ?>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][a1]"
                                                        step="0.1" min="0" max="3"
                                                        value="<?= htmlspecialchars($existing_mark['a1'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][a2]"
                                                        step="0.1" min="0" max="3"
                                                        value="<?= htmlspecialchars($existing_mark['a2'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][a3]"
                                                        step="0.1" min="0" max="3"
                                                        value="<?= htmlspecialchars($existing_mark['a3'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][a4]"
                                                        step="0.1" min="0" max="3"
                                                        value="<?= htmlspecialchars($existing_mark['a4'] ?? '') ?>">
                                                </td>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][a5]"
                                                        step="0.1" min="0" max="3"
                                                        value="<?= htmlspecialchars($existing_mark['a5'] ?? '') ?>">
                                                </td>
                                            <?php elseif ($selected_data['exam_type'] == 'Proj'): ?>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][project_score]"
                                                        step="0.1" min="0" max="10"
                                                        value="<?= htmlspecialchars($existing_mark['project_score'] ?? '') ?>">
                                                </td>
                                            <?php elseif ($selected_data['exam_type'] == 'EoC'): ?>
                                                <td>
                                                    <input type="number" name="student_marks[<?= $student['student_id'] ?>][eoc_score]"
                                                        step="0.1" min="0" max="80"
                                                        value="<?= htmlspecialchars($existing_mark['eoc_score'] ?? '') ?>">
                                                </td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div style="margin-top: 1.5rem; text-align: right;">
                            <button type="submit" name="save_marks" class="btn btn-success">
                                <i class="fas fa-save"></i> Save All Marks
                            </button>
                        </div>
                    </form>
                </div>
            <?php elseif (isset($_POST['load_students'])): ?>
                <div class="empty-state">
                    <i class="fas fa-users-slash"></i>
                    <p>No students found assigned to this subject in the selected class and stream.</p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            // Store selected values
            const selectedAcademicYear = '<?= $selected_data['academic_year'] ?>';
            const selectedLevel = '<?= $selected_data['level'] ?>';
            const selectedClass = '<?= $selected_data['class'] ?>';
            const selectedStream = '<?= $selected_data['stream'] ?>';
            const selectedSubject = '<?= $selected_data['subject'] ?>';
            const selectedTerm = '<?= $selected_data['term'] ?>';

            // Load terms when academic year is selected
            $('#academic_year').on('change', function() {
                const academicYearId = $(this).val();
                const termSelect = $('#term');

                if (!academicYearId) {
                    termSelect.html('<option value="">Select Academic Year First</option>');
                    return;
                }

                termSelect.html('<option value="">Loading...</option>');

                $.ajax({
                    url: 'ajax_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_terms',
                        academic_year: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.terms.length > 0) {
                            let options = '<option value="">Select Term</option>';
                            $.each(data.terms, function(index, term) {
                                const selected = (term.id == selectedTerm && academicYearId == selectedAcademicYear) ? 'selected' : '';
                                options += `<option value="${term.id}" ${selected}>${term.term_name}</option>`;
                            });
                            termSelect.html(options);
                        } else {
                            termSelect.html('<option value="">No active terms found</option>');
                        }
                    },
                    error: function() {
                        termSelect.html('<option value="">Error loading terms</option>');
                    }
                });
            });

            // If academic year is already selected, load its terms
            if (selectedAcademicYear) {
                setTimeout(function() {
                    $('#academic_year').trigger('change');
                }, 100);
            }

            // Fetch classes based on selected level
            $('#level').on('change', function() {
                const levelId = $(this).val();
                const classSelect = $('#class');
                const streamSelect = $('#stream');
                const subjectSelect = $('#subject');

                if (!levelId) {
                    classSelect.html('<option value="">Select Level First</option>');
                    streamSelect.html('<option value="">Select Class First</option>');
                    subjectSelect.html('<option value="">Select Stream First</option>');
                    return;
                }

                // Show loading
                classSelect.html('<option value="">Loading...</option>');
                streamSelect.html('<option value="">Select Class First</option>');
                subjectSelect.html('<option value="">Select Stream First</option>');

                loadClasses(levelId);
            });

            function loadClasses(levelId) {
                const classSelect = $('#class');

                $.ajax({
                    url: 'ajax_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_classes',
                        level: levelId,
                        teacher_id: '<?= $teacher_id ?>',
                        is_admin: '<?= $is_admin ? 1 : 0 ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.classes.length > 0) {
                            let options = '<option value="">Select Class</option>';
                            $.each(data.classes, function(index, cls) {
                                const selected = (cls.id == selectedClass) ? 'selected' : '';
                                options += `<option value="${cls.id}" ${selected}>${cls.name}</option>`;
                            });
                            classSelect.html(options);

                            // If we have a previously selected class, trigger change to load streams
                            if (selectedClass && selectedLevel == levelId) {
                                setTimeout(function() {
                                    $('#class').val(selectedClass).trigger('change');
                                }, 200);
                            }
                        } else {
                            classSelect.html('<option value="">No classes found</option>');
                        }
                    },
                    error: function() {
                        classSelect.html('<option value="">Error loading classes</option>');
                    }
                });
            }

            // Fetch streams based on selected class
            $('#class').on('change', function() {
                const classId = $(this).val();
                const streamSelect = $('#stream');
                const subjectSelect = $('#subject');

                if (!classId) {
                    streamSelect.html('<option value="">Select Class First</option>');
                    subjectSelect.html('<option value="">Select Stream First</option>');
                    return;
                }

                loadStreams(classId);
            });

            function loadStreams(classId) {
                const streamSelect = $('#stream');
                streamSelect.html('<option value="">Loading...</option>');
                $('#subject').html('<option value="">Select Stream First</option>');

                $.ajax({
                    url: 'ajax_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_streams',
                        class: classId,
                        teacher_id: '<?= $teacher_id ?>',
                        is_admin: '<?= $is_admin ? 1 : 0 ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.streams.length > 0) {
                            let options = '<option value="">Select Stream</option>';
                            $.each(data.streams, function(index, stream) {
                                const selected = (stream.id == selectedStream) ? 'selected' : '';
                                options += `<option value="${stream.id}" ${selected}>${stream.name}</option>`;
                            });
                            streamSelect.html(options);

                            // If we have a previously selected stream, trigger change to load subjects
                            if (selectedStream && selectedClass == classId) {
                                setTimeout(function() {
                                    $('#stream').val(selectedStream).trigger('change');
                                }, 200);
                            }
                        } else {
                            streamSelect.html('<option value="">No streams found</option>');
                        }
                    },
                    error: function() {
                        streamSelect.html('<option value="">Error loading streams</option>');
                    }
                });
            }

            // Fetch subjects based on selected stream
            $('#stream').on('change', function() {
                const streamId = $(this).val();
                const classId = $('#class').val();
                const levelId = $('#level').val();

                if (!streamId || !classId || !levelId) {
                    $('#subject').html('<option value="">Select Stream First</option>');
                    return;
                }

                loadSubjects(levelId, classId, streamId);
            });

            function loadSubjects(levelId, classId, streamId) {
                const subjectSelect = $('#subject');
                subjectSelect.html('<option value="">Loading...</option>');

                $.ajax({
                    url: 'ajax_handler.php',
                    type: 'GET',
                    data: {
                        action: 'get_subjects',
                        level: levelId,
                        class: classId,
                        stream: streamId,
                        teacher_id: '<?= $teacher_id ?>',
                        is_admin: '<?= $is_admin ? 1 : 0 ?>'
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.subjects.length > 0) {
                            let options = '<option value="">Select Subject</option>';
                            $.each(data.subjects, function(index, subject) {
                                const selected = (subject.id == selectedSubject) ? 'selected' : '';
                                options += `<option value="${subject.id}" ${selected}>${subject.code} - ${subject.name}</option>`;
                            });
                            subjectSelect.html(options);
                        } else {
                            subjectSelect.html('<option value="">No subjects found</option>');
                        }
                    },
                    error: function() {
                        subjectSelect.html('<option value="">Error loading subjects</option>');
                    }
                });
            }

            // Initialize dropdowns if we have selected values
            if (selectedLevel && selectedClass) {
                loadClasses(selectedLevel);
            }

            // Form validation
            $('#marksForm').on('submit', function(e) {
                let isValid = true;

                $(this).find('input[type="number"]').each(function() {
                    const value = parseFloat($(this).val());
                    const min = parseFloat($(this).attr('min'));
                    const max = parseFloat($(this).attr('max'));

                    if ($(this).val() && (value < min || value > max)) {
                        isValid = false;
                        $(this).css('border-color', 'red');
                        alert(`Value must be between ${min} and ${max}`);
                    } else {
                        $(this).css('border-color', '');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                }
            });

            // Clear form button
            $('#clearForm').on('click', function() {
                // Clear session data
                $.ajax({
                    url: 'ajax_handler.php',
                    type: 'POST',
                    data: {
                        action: 'clear_session'
                    },
                    success: function() {
                        // Reload the page to clear all selections
                        window.location.href = 'enter_marks.php';
                    }
                });
            });

            // Prevent form submission on Enter key in input fields
            $('input[type="number"]').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    return false;
                }
            });

            // Validate required fields before submitting the form
            $('#selectionForm').on('submit', function(e) {
                const requiredFields = ['academic_year', 'term', 'level', 'class', 'stream', 'subject', 'exam_type'];
                let isValid = true;
                let missingFields = [];

                requiredFields.forEach(function(field) {
                    const value = $('#' + field).val();
                    if (!value) {
                        isValid = false;
                        missingFields.push(field.replace('_', ' '));
                        $('#' + field).css('border-color', 'red');
                    } else {
                        $('#' + field).css('border-color', '');
                    }
                });

                if (!isValid) {
                    e.preventDefault();
                    alert('Please fill in all required fields: ' + missingFields.join(', '));
                }
            });
        });
    </script>
</body>

</html>