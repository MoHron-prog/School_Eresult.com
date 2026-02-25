<?php
require_once 'config.php';

// Check if user is logged in and has appropriate role
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

// Fetch school information with logo
$school_info = [];
try {
    $stmt = $pdo->prepare("SELECT school_name, school_logo, address, motto, phone, email FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// Format the address for display
$formatted_address = '';
if (!empty($school_info['address'])) {
    $formatted_address = nl2br(htmlspecialchars($school_info['address']));
} else {
    $formatted_address = 'P.O BOX 149<br>Lorikitae Cell, Lorengecorwa Ward Napak Town Council';
}

// Initialize variables
$marksheet_data = [];
$selected_data = [
    'academic_year' => $_POST['academic_year'] ?? '',
    'level' => $_POST['level'] ?? '',
    'class' => $_POST['class'] ?? '',
    'stream' => $_POST['stream'] ?? '',
    'term' => $_POST['term'] ?? '',
    'subject' => $_POST['subject'] ?? ''
];
$selected_academic_year_name = '';
$selected_class_name = '';
$selected_stream_name = '';
$selected_term_name = '';
$selected_level_name = '';

// Function to calculate total marks from A1-A5 and EoC
function calculateTotalMarks($a1, $a2, $a3, $a4, $a5, $eoc_score)
{
    // Calculate average of available activities (A1-A5)
    $activities = [];
    if ($a1 !== null && $a1 !== '') $activities[] = floatval($a1);
    if ($a2 !== null && $a2 !== '') $activities[] = floatval($a2);
    if ($a3 !== null && $a3 !== '') $activities[] = floatval($a3);
    if ($a4 !== null && $a4 !== '') $activities[] = floatval($a4);
    if ($a5 !== null && $a5 !== '') $activities[] = floatval($a5);

    $total = 0;
    if (!empty($activities)) {
        $average = array_sum($activities) / count($activities);
        // Convert average (0-3) to out of 20
        $activity_score = ($average / 3) * 20;
        $total += $activity_score;
    }

    if ($eoc_score !== null && $eoc_score !== '') {
        // EoC is already out of 80, no conversion needed
        $total += floatval($eoc_score);
    }

    // If no marks at all, return empty string
    if (empty($activities) && ($eoc_score === null || $eoc_score === '')) {
        return '';
    }

    return round($total, 1);
}

// Function to get grade, points, achievement level based on total marks
function getGradeAndPoints($level_id, $category, $total_marks, $pdo)
{
    // If no marks entered, return empty values
    if ($total_marks === '' || $total_marks === null) {
        return [
            'grade' => '',
            'points' => '',
            'achievement_level' => ''
        ];
    }

    if ($level_id == 2) { // A Level
        $stmt = $pdo->prepare("SELECT grade_letter, points FROM alevel_grading_scale WHERE subject_category = ? AND ? BETWEEN min_score AND max_score");
        $stmt->execute([$category, $total_marks]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($grade) {
            return [
                'grade' => $grade['grade_letter'],
                'points' => $grade['points'],
                'achievement_level' => '' // No achievement level for A Level
            ];
        }
    } else { // O Level
        $stmt = $pdo->prepare("SELECT grade_letter, achievement_level FROM olevel_grading_scale WHERE level_id = ? AND category = ? AND ? BETWEEN min_score AND max_score");
        $stmt->execute([$level_id, $category, $total_marks]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($grade) {
            return [
                'grade' => $grade['grade_letter'],
                'points' => '',
                'achievement_level' => $grade['achievement_level'] ?? ''
            ];
        }
    }

    return [
        'grade' => '—',
        'points' => '',
        'achievement_level' => '—'
    ];
}

// Function to determine result based on O-Level rules
function determineOlevelResult($compulsory_grades, $all_grades)
{
    // Rule 1: If any compulsory subject grade is blank → Result = 2
    foreach ($compulsory_grades as $grade) {
        if ($grade === '' || $grade === '—') {
            return 2;
        }
    }

    // Rule 2: If fewer than 7 grades are entered → Result = 2
    $entered_grades = array_filter($all_grades, function ($g) {
        return $g !== '' && $g !== '—';
    });
    if (count($entered_grades) < 7) {
        return 2;
    }

    // Rule 3: If at least one grade is A-D → Result = 1
    foreach ($entered_grades as $grade) {
        if (in_array($grade, ['A', 'B', 'C', 'D'])) {
            return 1;
        }
    }

    // Rule 4: If all entered grades are E → Result = 3
    $all_e = true;
    foreach ($entered_grades as $grade) {
        if ($grade !== 'E') {
            $all_e = false;
            break;
        }
    }
    if ($all_e) {
        return 3;
    }

    // Otherwise → Result = 4
    return 4;
}

// Handle form submission for loading marksheet
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_marksheet'])) {
    $selected_level = $_POST['level'] ?? '';
    $selected_class = $_POST['class'] ?? '';
    $selected_stream = $_POST['stream'] ?? '';
    $selected_term = $_POST['term'] ?? '';
    $selected_subject = $_POST['subject'] ?? '';
    $academic_year_id = $_POST['academic_year'] ?? '';

    if (!$selected_level || !$selected_class || !$selected_stream || !$selected_term || !$academic_year_id) {
        $message = "Please select all required fields.";
        $message_type = "error";
    } else {
        try {
            // Get selected data names for display
            $stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
            $stmt->execute([$academic_year_id]);
            $year = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_academic_year_name = $year['year_name'] ?? '';

            $stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
            $stmt->execute([$selected_class]);
            $class = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_class_name = $class['name'] ?? '';

            $stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
            $stmt->execute([$selected_stream]);
            $stream = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_stream_name = $stream['name'] ?? '';

            $stmt = $pdo->prepare("SELECT term_name FROM academic_terms WHERE id = ?");
            $stmt->execute([$selected_term]);
            $term = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_term_name = $term['term_name'] ?? '';

            $stmt = $pdo->prepare("SELECT name FROM levels WHERE id = ?");
            $stmt->execute([$selected_level]);
            $level = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_level_name = $level['name'] ?? '';

            // Determine which marks table to use based on level
            $marks_table = ($selected_level == 1) ? 'o_level_marks' : 'a_level_marks';

            // Fetch students
            $stmt = $pdo->prepare("
                SELECT s.id, s.student_id, s.surname, s.other_names, s.sex
                FROM students s
                WHERE s.class_id = ?
                AND s.stream_id = ?
                AND s.status = 'active'
                ORDER BY s.surname, s.other_names
            ");
            $stmt->execute([$selected_class, $selected_stream]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Fetch subjects for this class/level/stream based on user role
            $subjects_sql = "
                SELECT DISTINCT s.id, s.code, s.name, s.category
                FROM subjects s
                JOIN student_subjects ss ON s.id = ss.subject_id
                JOIN students st ON ss.student_id = st.id
                WHERE st.class_id = ? AND st.stream_id = ? AND s.level_id = ?
                AND s.status = 'active'
            ";

            // Apply role-based restrictions
            if (!$is_admin) {
                // For teachers, restrict to assigned subjects
                if ($is_admin || $_SESSION['role'] === 'admin') {
                    // Admin can see all
                } else {
                    // Check if teacher is a class teacher for this class
                    $is_class_teacher = false;
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM teacher_classes WHERE teacher_id = ? AND class_id = ? AND academic_year_id = ?");
                    $stmt->execute([$teacher_id, $selected_class, $academic_year_id]);
                    if ($stmt->fetchColumn() > 0) {
                        $is_class_teacher = true;
                    }

                    if (!$is_class_teacher) {
                        // Subject teacher - only show assigned subjects
                        $subjects_sql .= " AND s.id IN (
                            SELECT ts.subject_id 
                            FROM teacher_subjects ts 
                            WHERE ts.teacher_id = ? AND ts.level_id = ?
                        )";
                    }
                }
            }

            $subjects_params = [$selected_class, $selected_stream, $selected_level];
            if (!$is_admin && !$is_class_teacher) {
                $subjects_params[] = $teacher_id;
                $subjects_params[] = $selected_level;
            }

            $stmt = $pdo->prepare($subjects_sql);
            $stmt->execute($subjects_params);
            $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Sort subjects: compulsory first, then electives/principal/subsidiary
            usort($subjects, function ($a, $b) {
                $priority = [
                    'compulsory' => 1,
                    'principal' => 2,
                    'elective' => 3,
                    'subsidiary' => 4
                ];
                return $priority[$a['category']] - $priority[$b['category']];
            });

            // Process marks for each student
            foreach ($students as $student) {
                $student_data = [
                    'student_id' => $student['student_id'],
                    'name' => $student['surname'] . ' ' . $student['other_names'],
                    'sex' => $student['sex'],
                    'subjects' => []
                ];

                // Initialize subject data
                foreach ($subjects as $subject) {
                    $student_data['subjects'][$subject['id']] = [
                        'code' => $subject['code'],
                        'name' => $subject['name'],
                        'category' => $subject['category'],
                        'a1' => null,
                        'a2' => null,
                        'a3' => null,
                        'a4' => null,
                        'a5' => null,
                        'ps' => null,
                        'eoc' => null,
                        'total' => '',
                        'grade' => '',
                        'points' => ''
                    ];
                }

                // Fetch all marks for this student
                $stmt = $pdo->prepare("
                    SELECT subject_id, exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
                    FROM $marks_table
                    WHERE student_id = ?
                    AND academic_year_id = ?
                    AND term_id = ?
                    AND class_id = ?
                    AND stream_id = ?
                ");
                $stmt->execute([
                    $student['student_id'],
                    $academic_year_id,
                    $selected_term,
                    $selected_class,
                    $selected_stream
                ]);
                $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Consolidate marks by subject
                foreach ($marks as $mark) {
                    if (!isset($student_data['subjects'][$mark['subject_id']])) {
                        continue;
                    }

                    switch ($mark['exam_type']) {
                        case 'Aol':
                            $student_data['subjects'][$mark['subject_id']]['a1'] = $mark['a1'];
                            $student_data['subjects'][$mark['subject_id']]['a2'] = $mark['a2'];
                            $student_data['subjects'][$mark['subject_id']]['a3'] = $mark['a3'];
                            $student_data['subjects'][$mark['subject_id']]['a4'] = $mark['a4'];
                            $student_data['subjects'][$mark['subject_id']]['a5'] = $mark['a5'];
                            break;
                        case 'Proj':
                            $student_data['subjects'][$mark['subject_id']]['ps'] = $mark['project_score'];
                            break;
                        case 'EoC':
                            $student_data['subjects'][$mark['subject_id']]['eoc'] = $mark['eoc_score'];
                            break;
                    }
                }

                // Calculate totals and grades for each subject
                foreach ($subjects as $subject) {
                    $subj_id = $subject['id'];
                    $total = calculateTotalMarks(
                        $student_data['subjects'][$subj_id]['a1'],
                        $student_data['subjects'][$subj_id]['a2'],
                        $student_data['subjects'][$subj_id]['a3'],
                        $student_data['subjects'][$subj_id]['a4'],
                        $student_data['subjects'][$subj_id]['a5'],
                        $student_data['subjects'][$subj_id]['eoc']
                    );

                    $student_data['subjects'][$subj_id]['total'] = $total;

                    if ($total !== '') {
                        $grade_info = getGradeAndPoints($selected_level, $subject['category'], $total, $pdo);
                        $student_data['subjects'][$subj_id]['grade'] = $grade_info['grade'];
                        $student_data['subjects'][$subj_id]['points'] = $grade_info['points'];
                    }
                }

                $marksheet_data[] = $student_data;
            }

            // For O-Level, calculate result and grade counts
            if ($selected_level == 1) {
                foreach ($marksheet_data as &$student_row) {
                    $compulsory_grades = [];
                    $all_grades = [];
                    $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

                    foreach ($subjects as $subject) {
                        $grade = $student_row['subjects'][$subject['id']]['grade'];
                        if ($grade !== '' && $grade !== '—') {
                            $all_grades[] = $grade;
                            if (isset($grade_counts[$grade])) {
                                $grade_counts[$grade]++;
                            }
                        }

                        if ($subject['category'] === 'compulsory') {
                            $compulsory_grades[] = $grade;
                        }
                    }

                    $student_row['grade_counts'] = $grade_counts;
                    $student_row['result'] = determineOlevelResult($compulsory_grades, $all_grades);
                }
                unset($student_row); // Break reference
            }

            // For A-Level, calculate total points
            if ($selected_level == 2) {
                foreach ($marksheet_data as &$student_row) {
                    $total_points = 0;
                    foreach ($subjects as $subject) {
                        $points = $student_row['subjects'][$subject['id']]['points'];
                        if (is_numeric($points)) {
                            $total_points += $points;
                        }
                    }
                    $student_row['total_points'] = $total_points;
                }
                unset($student_row); // Break reference
            }
        } catch (Exception $e) {
            $message = "Error loading master marksheet: " . $e->getMessage();
            $message_type = "error";
            error_log("Error loading master marksheet: " . $e->getMessage());
        }
    }
}

// Fetch terms for selected academic year (if any)
$filtered_terms = [];
if (!empty($selected_data['academic_year'])) {
    try {
        $stmt = $pdo->prepare("SELECT id, term_name FROM academic_terms WHERE academic_year_id = ? AND status = 'active' ORDER BY id");
        $stmt->execute([$selected_data['academic_year']]);
        $filtered_terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error fetching filtered terms: " . $e->getMessage());
    }
}

// Check for error messages from redirect
if (isset($_GET['error'])) {
    $message = urldecode($_GET['error']);
    $message_type = "error";
}

// Check for session error messages
if (isset($_SESSION['error'])) {
    $message = $_SESSION['error'];
    $message_type = "error";
    unset($_SESSION['error']);
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Master Marksheet - Teacher Dashboard</title>
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

        .nav-link:hover,
        .nav-link.active {
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

        .nav-link:hover i,
        .nav-link.active i {
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

        .btn-warning {
            background: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-info {
            background: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        /* Marksheet Table */
        .marksheet-container {
            overflow-x: auto;
            margin-top: 1rem;
        }

        .marksheet-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            font-size: 0.85rem;
        }

        .marksheet-table th {
            background: var(--primary);
            color: white;
            padding: 0.6rem;
            text-align: center;
            font-weight: 600;
            position: sticky;
            top: 0;
            white-space: nowrap;
            border: 1px solid #ddd;
        }

        .marksheet-table td {
            padding: 0.4rem;
            border: 1px solid #ddd;
            text-align: center;
        }

        .marksheet-table tr:nth-child(even) {
            background: #f9f9f9;
        }

        .marksheet-table tr:hover {
            background: #f0f4ff;
        }

        .marksheet-table .total-col {
            font-weight: 600;
            background: #e8f4fd;
        }

        .marksheet-table .grade-col {
            font-weight: 600;
            background: #f0f8ff;
        }

        .marksheet-table .achievement-col {
            font-weight: 500;
            background: #fff3cd;
        }

        .marksheet-table .remark-col {
            font-weight: 500;
            background: #d1ecf1;
        }

        .marksheet-table .no-marks-row td {
            color: #999;
            font-style: italic;
        }

        .marksheet-table .fail-grade {
            color: #dc3545;
            font-weight: bold;
        }

        .marksheet-table .distinction-grade {
            color: #28a745;
            font-weight: bold;
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

        .empty-state h3 {
            margin-bottom: 1rem;
            color: var(--primary);
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

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            margin-top: 1rem;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Print Styles */
        @media print {

            /* Hide everything by default */
            body * {
                visibility: hidden;
            }

            /* Only show the marksheet content */
            .print-marksheet,
            .print-marksheet * {
                visibility: visible;
            }

            /* Position the marksheet */
            .print-marksheet {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                padding: 0.5in;
                margin: 0;
                background: white;
            }

            /* Hide all non-print elements */
            .no-print,
            .sidebar,
            .header,
            .role-tag,
            .action-buttons,
            .form-section:not(.print-marksheet),
            .info-box,
            .selection-summary,
            .btn,
            .logout-section,
            .nav-menu,
            .sidebar-header {
                display: none !important;
            }

            /* School Header Styles */
            .school-header {
                display: flex !important;
                align-items: center;
                margin-bottom: 20px;
                page-break-after: avoid;
                border-bottom: 2px solid #1a2a6c;
                padding-bottom: 10px;
                width: 100%;
            }

            .school-logo-container {
                flex: 0 0 100px;
                margin-right: 20px;
            }

            .school-logo {
                width: 100%;
                max-width: 100px;
                height: auto;
            }

            .school-details {
                flex: 1;
                text-align: center;
            }

            .school-details h2 {
                font-size: 18pt;
                margin: 0 0 5px 0;
                color: #000;
                font-weight: bold;
                text-transform: uppercase;
                letter-spacing: 1px;
            }

            .school-details p {
                margin: 2px 0;
                font-size: 10pt;
                line-height: 1.2;
            }

            .school-address {
                margin: 3px 0 !important;
                white-space: pre-line;
            }

            .school-contact {
                font-size: 9pt;
                margin: 3px 0 !important;
            }

            .school-motto {
                font-style: italic;
                margin: 5px 0 !important;
                font-weight: bold;
                font-size: 11pt;
            }

            /* Hide any icons in print */
            .marksheet-info i,
            .school-header i,
            .marksheet-table i {
                display: none !important;
            }

            /* Table Styles */
            .marksheet-table {
                width: 100%;
                border-collapse: collapse;
                box-shadow: none;
                font-size: 9pt;
                margin-top: 10px;
            }

            .marksheet-table th {
                background: #1a2a6c !important;
                color: white !important;
                padding: 6px 3px;
                border: 1px solid #000;
                font-weight: bold;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .marksheet-table td {
                padding: 4px 3px;
                border: 1px solid #000;
            }

            .marksheet-table .total-col {
                background: #e8f4fd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .marksheet-table .grade-col {
                background: #f0f8ff !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .marksheet-table .achievement-col {
                background: #fff3cd !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .marksheet-table .remark-col {
                background: #d1ecf1 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            /* Page settings */
            @page {
                size: A4 landscape;
                margin: 0.5in;
            }

            /* Ensure no page breaks inside tables */
            tr,
            td,
            th {
                page-break-inside: avoid;
            }
        }

        /* Screen styles - school header hidden by default */
        .school-header {
            display: none;
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

            .marksheet-table {
                font-size: 0.75rem;
            }

            .marksheet-table th,
            .marksheet-table td {
                padding: 0.25rem;
            }

            .action-buttons {
                flex-direction: column;
            }

            .action-buttons .btn {
                width: 100%;
                justify-content: center;
            }

            .marksheet-info {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
    </div>

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
                <a href="master_marksheet.php" class="nav-link active">
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
                <h1>Master Marksheet</h1>
                <p><?= $fullname ?> | <?= $email ?></p>
            </div>
            <div class="role-tag"><?= $is_admin ? 'Admin' : 'Teacher' ?></div>
        </header>
        <main class="main-content">
            <?php if (isset($message)): ?>
                <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'error' ?>">
                    <i class="fas fa-<?= $message_type === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <!-- Selection Form -->
            <div class="form-section no-print">
                <h3>Select Master Marksheet Criteria</h3>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> Select the criteria to generate master marksheet. Total marks are calculated as: (Average of Activities × 20/3) + EoC (out of 80).</p>
                    <p><i class="fas fa-file-pdf"></i> <strong>PDF Export:</strong> Click "Print / Save as PDF" and choose "Save as PDF" in the print dialog.</p>
                </div>

                <?php if (!empty($marksheet_data)): ?>
                    <div class="selection-summary">
                        <div class="selection-item">
                            <span class="selection-label">Academic Year:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_academic_year_name) ?></span>
                        </div>
                        <div class="selection-item">
                            <span class="selection-label">Level:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_level_name) ?></span>
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
                            <span class="selection-label">Term:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_term_name) ?></span>
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
                                    <option value="<?= $year['id'] ?>" <?= $selected_data['academic_year'] == $year['id'] ? 'selected' : '' ?>>
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
                                if (!empty($selected_data['academic_year'])):
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
                                <option value="1" <?= $selected_data['level'] == '1' ? 'selected' : '' ?>>O Level</option>
                                <option value="2" <?= $selected_data['level'] == '2' ? 'selected' : '' ?>>A Level</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select class="form-control" id="class" name="class" required>
                                <option value="">Select Level First</option>
                                <?php if ($selected_data['class'] && !empty($selected_class_name)): ?>
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
                                <?php if ($selected_data['stream'] && !empty($selected_stream_name)): ?>
                                    <option value="<?= $selected_data['stream'] ?>" selected>
                                        <?= htmlspecialchars($selected_stream_name) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="subject">Subject (Optional)</label>
                            <select class="form-control" id="subject" name="subject">
                                <option value="">Load All Subjects</option>
                                <?php if ($selected_data['subject']): ?>
                                    <option value="<?= $selected_data['subject'] ?>" selected>
                                        <?= htmlspecialchars($selected_data['subject']) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="load_marksheet" class="btn btn-primary">
                            <i class="fas fa-file-alt"></i> Load Master Marksheet
                        </button>
                        <button type="button" id="clearForm" class="btn btn-danger">
                            <i class="fas fa-times"></i> Clear Form
                        </button>
                    </div>
                </form>
            </div>

            <!-- Marksheet Display -->
            <?php if (!empty($marksheet_data)): ?>
                <div class="form-section print-marksheet">
                    <!-- School Header for Print -->
                    <div class="school-header">
                        <div class="school-logo-container">
                            <?php if (!empty($school_info['school_logo'])): ?>
                                <img src="<?= htmlspecialchars($school_info['school_logo']) ?>" alt="School Logo" class="school-logo">
                            <?php else: ?>
                                <div style="width: 100px; height: 100px; background: #f0f0f0; display: flex; align-items: center; justify-content: center; border: 1px solid #ddd;">
                                    <span style="font-size: 10pt; color: #666;">Logo</span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="school-details">
                            <h2><?= htmlspecialchars($school_info['school_name'] ?? 'NAPAK SEED SECONDARY SCHOOL') ?></h2>
                            <p class="school-address">
                                <?= strip_tags(str_replace('<br>', ', ', $formatted_address)) ?>
                            </p>
                            <p class="school-contact">
                                Email: <?= htmlspecialchars($school_info['email'] ?? 'napakseed@gmail.com') ?> |
                                Tel: <?= htmlspecialchars($school_info['phone'] ?? '0200 912 924/0770 880 274') ?>
                            </p>
                            <p class="school-motto">
                                "<?= htmlspecialchars($school_info['motto'] ?? 'Achieving excellence together') ?>"
                            </p>
                        </div>
                    </div>

                    <h3 class="no-print">Master Marksheet for <?= htmlspecialchars($selected_class_name) ?> <?= htmlspecialchars($selected_stream_name) ?></h3>

                    <!-- Marksheet Table -->
                    <div class="marksheet-container">
                        <table class="marksheet-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Gender</th>
                                    <?php
                                    // Display subject headers
                                    foreach ($subjects as $subject):
                                        $subject_label = htmlspecialchars($subject['code']);
                                    ?>
                                        <th colspan="<?= $selected_level == 1 ? '2' : '3' ?>"><?= $subject_label ?></th>
                                    <?php endforeach; ?>

                                    <?php if ($selected_level == 1): // O-Level 
                                    ?>
                                        <th colspan="5">TT Grades</th>
                                        <th>RESULT</th>
                                    <?php else: // A-Level 
                                    ?>
                                        <th>Total Points</th>
                                    <?php endif; ?>
                                </tr>
                                <tr>
                                    <th></th>
                                    <th></th>
                                    <th></th>
                                    <?php foreach ($subjects as $subject): ?>
                                        <th>TT</th>
                                        <th>GD</th>
                                        <?php if ($selected_level == 2): // A-Level 
                                        ?>
                                            <th>PT</th>
                                        <?php endif; ?>
                                    <?php endforeach; ?>

                                    <?php if ($selected_level == 1): // O-Level 
                                    ?>
                                        <th>A</th>
                                        <th>B</th>
                                        <th>C</th>
                                        <th>D</th>
                                        <th>E</th>
                                        <th></th>
                                    <?php else: // A-Level 
                                    ?>
                                        <th></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marksheet_data as $index => $student): ?>
                                    <tr>
                                        <td><?= $index + 1 ?></td>
                                        <td style="text-align: left;"><?= htmlspecialchars($student['name']) ?></td>
                                        <td><?= htmlspecialchars($student['sex']) ?></td>

                                        <?php foreach ($subjects as $subject):
                                            $subj_id = $subject['id'];
                                            $total = $student['subjects'][$subj_id]['total'];
                                            $grade = $student['subjects'][$subj_id]['grade'];
                                            $points = $student['subjects'][$subj_id]['points'];

                                            // Determine grade class for styling
                                            $grade_class = '';
                                            if ($grade !== '' && $grade !== '—') {
                                                if (in_array($grade, ['A', 'B'])) {
                                                    $grade_class = 'distinction-grade';
                                                } elseif ($grade === 'E' || $grade === 'F') {
                                                    $grade_class = 'fail-grade';
                                                }
                                            }
                                        ?>
                                            <td><?= $total !== '' ? $total : '' ?></td>
                                            <td class="<?= $grade_class ?>"><?= $grade !== '' ? $grade : '' ?></td>
                                            <?php if ($selected_level == 2): // A-Level 
                                            ?>
                                                <td><?= $points !== '' ? $points : '' ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>

                                        <?php if ($selected_level == 1): // O-Level 
                                        ?>
                                            <td><?= $student['grade_counts']['A'] ?></td>
                                            <td><?= $student['grade_counts']['B'] ?></td>
                                            <td><?= $student['grade_counts']['C'] ?></td>
                                            <td><?= $student['grade_counts']['D'] ?></td>
                                            <td><?= $student['grade_counts']['E'] ?></td>
                                            <td>
                                                <?php
                                                // Display result as number 1-4
                                                echo $student['result'];
                                                ?>
                                            </td>
                                        <?php else: // A-Level 
                                        ?>
                                            <td><?= $student['total_points'] ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="no-print" style="margin-top: 20px; padding: 10px; background: #f8f9fa; border-left: 4px solid #17a2b8;">
                        <p><strong>Note:</strong> Total Marks = (Average of Activities × 20/3) + EoC (out of 80)</p>
                        <?php if ($selected_level == 1): ?>
                            <p><small><em>O-Level Result Logic: 1=Pass, 2=Fail (missing compulsory or <7 subjects), 3=Fail (all E), 4=Incomplete</em></small></p>
                        <?php else: ?>
                            <p><small><em>A-Level: Principal Subjects (A=6, B=5, C=4, D=3, E=2, F=0), Subsidiary (1-6=1 point, 7-9=0 points)</em></small></p>
                        <?php endif; ?>
                    </div>

                    <div class="action-buttons no-print">
                        <button type="button" onclick="window.print()" class="btn btn-success">
                            <i class="fas fa-print"></i> Print / Save as PDF
                        </button>
                    </div>
                </div>
            <?php elseif (isset($_POST['load_marksheet'])): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Marks Found</h3>
                    <p>No marks found for the selected criteria. Please ensure marks have been entered for this class.</p>
                    <p style="margin-top: 1rem; color: var(--primary);">
                        <i class="fas fa-lightbulb"></i> Go to <a href="enter_marks.php" style="color: var(--primary); font-weight: bold;">Enter Marks</a> to add marks.
                    </p>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            // Show loading spinner during form submission
            $('#selectionForm').on('submit', function() {
                $('#loadingSpinner').show();
            });

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
                            let options = '<option value="">Load All Subjects</option>';
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

            // Clear form button
            $('#clearForm').on('click', function() {
                window.location.href = 'master_marksheet.php';
            });

            // Form validation
            $('#selectionForm').on('submit', function(e) {
                const requiredFields = ['academic_year', 'term', 'level', 'class', 'stream'];
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