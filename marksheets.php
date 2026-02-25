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
    // Convert newlines to HTML line breaks
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
$selected_subject_name = '';
$selected_term_name = '';
$selected_level_name = '';

// Function to check if student has any marks entered
function hasAnyMarks($student_marks)
{
    // Check A1-A5
    if (!empty(array_filter($student_marks['aol'], function ($value) {
        return $value !== null && $value !== '';
    }))) {
        return true;
    }

    // Check project score
    if ($student_marks['proj']['project_score'] !== null && $student_marks['proj']['project_score'] !== '') {
        return true;
    }

    // Check EoC score
    if ($student_marks['eoc']['eoc_score'] !== null && $student_marks['eoc']['eoc_score'] !== '') {
        return true;
    }

    return false;
}

// Function to get grade, points, achievement level, and remark based on total marks
function getGradeAndPointsAndAchievement($level_id, $category, $total_marks, $pdo, $has_marks)
{
    // If no marks entered, return empty values
    if (!$has_marks) {
        return [
            'grade' => '',
            'points' => '',
            'achievement_level' => '',
            'remark' => ''
        ];
    }

    if ($level_id == 2) { // A Level
        $stmt = $pdo->prepare("SELECT grade_letter, points, remark FROM alevel_grading_scale WHERE subject_category = ? AND ? BETWEEN min_score AND max_score");
        $stmt->execute([$category, $total_marks]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($grade) {
            return [
                'grade' => $grade['grade_letter'],
                'points' => $grade['points'],
                'achievement_level' => '', // No achievement level for A Level
                'remark' => $grade['remark'] ?? ''
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
                'achievement_level' => $grade['achievement_level'] ?? '',
                'remark' => '' // No remark for O Level (achievement level is sufficient)
            ];
        }
    }
    return [
        'grade' => '—',
        'points' => '',
        'achievement_level' => '—',
        'remark' => '—'
    ];
}

// Calculate total marks from A1-A5 and EoC
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['load_marksheet'])) {
    $selected_level = $_POST['level'] ?? '';
    $selected_class = $_POST['class'] ?? '';
    $selected_stream = $_POST['stream'] ?? '';
    $selected_term = $_POST['term'] ?? '';
    $selected_subject = $_POST['subject'] ?? '';
    $academic_year_id = $_POST['academic_year'] ?? '';

    if (
        !$selected_level || !$selected_class || !$selected_stream ||
        !$selected_term || !$selected_subject || !$academic_year_id
    ) {
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

            $stmt = $pdo->prepare("SELECT code, name, category FROM subjects WHERE id = ?");
            $stmt->execute([$selected_subject]);
            $subject = $stmt->fetch(PDO::FETCH_ASSOC);
            $selected_subject_name = ($subject['code'] ?? '') . ' - ' . ($subject['name'] ?? '');
            $subject_category = $subject['category'] ?? '';

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

            // Process marks for each student
            foreach ($students as $student) {
                // Fetch all marks for this student in one query
                $stmt = $pdo->prepare("
                    SELECT exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
                    FROM $marks_table 
                    WHERE student_id = ? 
                        AND academic_year_id = ? 
                        AND term_id = ? 
                        AND subject_id = ? 
                        AND class_id = ? 
                        AND stream_id = ?
                ");
                $stmt->execute([
                    $student['student_id'],
                    $academic_year_id,
                    $selected_term,
                    $selected_subject,
                    $selected_class,
                    $selected_stream
                ]);
                $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Initialize marks array
                $student_marks = [
                    'aol' => ['a1' => null, 'a2' => null, 'a3' => null, 'a4' => null, 'a5' => null],
                    'proj' => ['project_score' => null],
                    'eoc' => ['eoc_score' => null]
                ];

                // Consolidate marks from different exam types
                foreach ($marks as $mark) {
                    switch ($mark['exam_type']) {
                        case 'Aol':
                            $student_marks['aol']['a1'] = $mark['a1'];
                            $student_marks['aol']['a2'] = $mark['a2'];
                            $student_marks['aol']['a3'] = $mark['a3'];
                            $student_marks['aol']['a4'] = $mark['a4'];
                            $student_marks['aol']['a5'] = $mark['a5'];
                            break;
                        case 'Proj':
                            $student_marks['proj']['project_score'] = $mark['project_score'];
                            break;
                        case 'EoC':
                            $student_marks['eoc']['eoc_score'] = $mark['eoc_score'];
                            break;
                    }
                }

                // Check if student has any marks
                $has_marks = hasAnyMarks($student_marks);

                // Calculate total marks
                $total_marks = calculateTotalMarks(
                    $student_marks['aol']['a1'],
                    $student_marks['aol']['a2'],
                    $student_marks['aol']['a3'],
                    $student_marks['aol']['a4'],
                    $student_marks['aol']['a5'],
                    $student_marks['eoc']['eoc_score']
                );

                // Get grade, points, achievement level and remark
                $grade_info = getGradeAndPointsAndAchievement($selected_level, $subject_category, $total_marks, $pdo, $has_marks);

                $marksheet_data[] = [
                    'student_id' => $student['student_id'],
                    'name' => $student['surname'] . ' ' . $student['other_names'],
                    'sex' => $student['sex'],
                    'a1' => $student_marks['aol']['a1'] ?? '—',
                    'a2' => $student_marks['aol']['a2'] ?? '—',
                    'a3' => $student_marks['aol']['a3'] ?? '—',
                    'a4' => $student_marks['aol']['a4'] ?? '—',
                    'a5' => $student_marks['aol']['a5'] ?? '—',
                    'ps' => $student_marks['proj']['project_score'] ?? '—',
                    'eoc' => $student_marks['eoc']['eoc_score'] ?? '—',
                    'total' => $total_marks,
                    'grade' => $grade_info['grade'],
                    'points' => $grade_info['points'],
                    'achievement_level' => $grade_info['achievement_level'],
                    'remark' => $grade_info['remark']
                ];
            }
        } catch (Exception $e) {
            $message = "Error loading marksheet: " . $e->getMessage();
            $message_type = "error";
            error_log("Error loading marksheet: " . $e->getMessage());
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

// Handle CSV export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['export_csv'])) {
    // Check if we have the required data from previous load
    $selected_level = $_POST['level'] ?? '';
    $selected_class = $_POST['class'] ?? '';
    $selected_stream = $_POST['stream'] ?? '';
    $selected_term = $_POST['term'] ?? '';
    $selected_subject = $_POST['subject'] ?? '';
    $academic_year_id = $_POST['academic_year'] ?? '';

    if (
        !$selected_level || !$selected_class || !$selected_stream ||
        !$selected_term || !$selected_subject || !$academic_year_id
    ) {
        // Redirect back to form with error
        header("Location: marksheets.php?error=Please+load+marksheet+first+before+exporting.");
        exit;
    }

    try {
        // Get selected data names for display in export
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

        $stmt = $pdo->prepare("SELECT code, name, category FROM subjects WHERE id = ?");
        $stmt->execute([$selected_subject]);
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        $selected_subject_name = ($subject['code'] ?? '') . ' - ' . ($subject['name'] ?? '');
        $subject_category = $subject['category'] ?? '';

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

        // Fetch students for export
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

        // Process marks for each student for export
        $export_data = [];
        foreach ($students as $student) {
            // Fetch all marks for this student in one query
            $stmt = $pdo->prepare("
                SELECT exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
                FROM $marks_table 
                WHERE student_id = ? 
                    AND academic_year_id = ? 
                    AND term_id = ? 
                    AND subject_id = ? 
                    AND class_id = ? 
                    AND stream_id = ?
            ");
            $stmt->execute([
                $student['student_id'],
                $academic_year_id,
                $selected_term,
                $selected_subject,
                $selected_class,
                $selected_stream
            ]);
            $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Initialize marks array
            $student_marks = [
                'aol' => ['a1' => null, 'a2' => null, 'a3' => null, 'a4' => null, 'a5' => null],
                'proj' => ['project_score' => null],
                'eoc' => ['eoc_score' => null]
            ];

            // Consolidate marks from different exam types
            foreach ($marks as $mark) {
                switch ($mark['exam_type']) {
                    case 'Aol':
                        $student_marks['aol']['a1'] = $mark['a1'];
                        $student_marks['aol']['a2'] = $mark['a2'];
                        $student_marks['aol']['a3'] = $mark['a3'];
                        $student_marks['aol']['a4'] = $mark['a4'];
                        $student_marks['aol']['a5'] = $mark['a5'];
                        break;
                    case 'Proj':
                        $student_marks['proj']['project_score'] = $mark['project_score'];
                        break;
                    case 'EoC':
                        $student_marks['eoc']['eoc_score'] = $mark['eoc_score'];
                        break;
                }
            }

            // Check if student has any marks
            $has_marks = hasAnyMarks($student_marks);

            // Calculate total marks
            $total_marks = calculateTotalMarks(
                $student_marks['aol']['a1'],
                $student_marks['aol']['a2'],
                $student_marks['aol']['a3'],
                $student_marks['aol']['a4'],
                $student_marks['aol']['a5'],
                $student_marks['eoc']['eoc_score']
            );

            // Get grade, points, achievement level and remark
            $grade_info = getGradeAndPointsAndAchievement($selected_level, $subject_category, $total_marks, $pdo, $has_marks);

            $export_data[] = [
                'student_id' => $student['student_id'],
                'name' => $student['surname'] . ' ' . $student['other_names'],
                'sex' => $student['sex'],
                'a1' => $student_marks['aol']['a1'] ?? '',
                'a2' => $student_marks['aol']['a2'] ?? '',
                'a3' => $student_marks['aol']['a3'] ?? '',
                'a4' => $student_marks['aol']['a4'] ?? '',
                'a5' => $student_marks['aol']['a5'] ?? '',
                'ps' => $student_marks['proj']['project_score'] ?? '',
                'eoc' => $student_marks['eoc']['eoc_score'] ?? '',
                'total' => $total_marks,
                'grade' => $grade_info['grade'],
                'points' => $grade_info['points'],
                'achievement_level' => $grade_info['achievement_level'],
                'remark' => $grade_info['remark']
            ];
        }

        if (empty($export_data)) {
            header("Location: marksheets.php?error=No+data+to+export.+Please+ensure+marks+have+been+entered.");
            exit;
        }

        // Generate CSV content
        $csvContent = '';

        // Add school information as comments/header
        $csvContent .= "# " . ($school_info['school_name'] ?? 'NAPAK SEED SECONDARY SCHOOL') . "\n";
        $csvContent .= "# Address: " . ($school_info['address'] ?? 'P.O BOX 149, Lorikitae Cell, Lorengecorwa Ward Napak Town Council') . "\n";
        $csvContent .= "# Subject: " . $selected_subject_name . "\n";
        $csvContent .= "# Class: " . $selected_level_name . ' - ' . $selected_class_name . ' ' . $selected_stream_name . "\n";
        $csvContent .= "# Term: " . $selected_term_name . "\n";
        $csvContent .= "# Academic Year: " . $selected_academic_year_name . "\n";
        $csvContent .= "# Teacher: " . $fullname . "\n";
        $csvContent .= "# Export Date: " . date('Y-m-d H:i:s') . "\n";
        $csvContent .= "# Note: Total Marks = (Average of Activities × 20/3) + EoC (out of 80)\n";
        $csvContent .= "\n";

        // Add headers based on level
        $headers = ['#', 'Student ID', 'Name', 'Gender', 'A1', 'A2', 'A3', 'A4', 'A5', 'PS', 'EoC', 'Total', 'Grade'];
        if ($selected_level == 2) {
            $headers[] = 'Points';
            $headers[] = 'Remark';
        } else {
            $headers[] = 'Achievement Level';
        }

        $csvContent .= implode(',', $headers) . "\n";

        // Add data rows
        foreach ($export_data as $index => $row) {
            $rowData = [
                $index + 1,
                $row['student_id'],
                '"' . str_replace('"', '""', $row['name']) . '"',
                $row['sex'],
                is_numeric($row['a1']) ? $row['a1'] : '',
                is_numeric($row['a2']) ? $row['a2'] : '',
                is_numeric($row['a3']) ? $row['a3'] : '',
                is_numeric($row['a4']) ? $row['a4'] : '',
                is_numeric($row['a5']) ? $row['a5'] : '',
                is_numeric($row['ps']) ? $row['ps'] : '',
                is_numeric($row['eoc']) ? $row['eoc'] : '',
                is_numeric($row['total']) ? $row['total'] : '',
                $row['grade']
            ];

            if ($selected_level == 2) {
                $rowData[] = is_numeric($row['points']) ? $row['points'] : '';
                $rowData[] = '"' . str_replace('"', '""', $row['remark']) . '"';
            } else {
                $rowData[] = $row['achievement_level'];
            }

            $csvContent .= implode(',', $rowData) . "\n";
        }

        // Set filename
        $filename = "marksheet_" .
            str_replace([' ', '-'], '_', $selected_subject_name) . "_" .
            $selected_level_name . "_" .
            $selected_class_name . "_" .
            $selected_stream_name . "_" .
            str_replace(' ', '_', $selected_term_name) . "_" .
            date('Y-m-d') . ".csv";

        // Output CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');

        // Output BOM for UTF-8 to ensure Excel opens it correctly
        echo "\xEF\xBB\xBF";
        echo $csvContent;
        exit;
    } catch (Exception $e) {
        header("Location: marksheets.php?error=Error+exporting+CSV:+" . urlencode($e->getMessage()));
        exit;
    }
}

// Check for error messages from redirect
if (isset($_GET['error'])) {
    $message = urldecode($_GET['error']);
    $message_type = "error";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>View Marksheets - Teacher Dashboard</title>
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

        /* ============ FIXED PRINT STYLES ============ */
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

            /* School Header Styles - FIXED WHITE SPACE */
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

            /* Marksheet Info - NO ICONS, CLEAN LAYOUT */
            .marksheet-info {
                display: flex;
                justify-content: space-between;
                margin: 15px 0;
                font-size: 10pt;
                page-break-after: avoid;
                background: #f5f5f5;
                padding: 10px 15px;
                width: 100%;
            }

            .marksheet-info div {
                display: flex;
                flex-direction: column;
                gap: 3px;
            }

            .marksheet-info p {
                margin: 0;
                padding: 0;
            }

            .marksheet-info strong {
                color: #1a2a6c;
                font-weight: 700;
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

            /* Signature line */
            .signature-line {
                margin-top: 30px;
                border-top: 1px solid #000;
                width: 200px;
                padding-top: 5px;
                text-align: center;
            }

            .teacher-signature {
                display: flex;
                justify-content: space-between;
                margin-top: 40px;
            }
        }

        /* Screen styles - school header hidden by default */
        .school-header {
            display: none;
        }

        /* Marksheet info on screen - NO ICONS */
        .marksheet-info {
            display: flex;
            justify-content: space-between;
            margin: 15px 0;
            padding: 10px 15px;
            background: #f5f5f5;
            font-size: 0.95rem;
        }

        .marksheet-info div {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .marksheet-info p {
            margin: 0;
            padding: 0;
        }

        .marksheet-info strong {
            color: var(--primary);
        }

        /* Hide all icons in marksheet info */
        .marksheet-info i {
            display: none !important;
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
                <a href="marksheets.php" class="nav-link active">
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
                <h1>View Marksheets</h1>
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
                <h3>Select Marksheet Criteria</h3>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> Select the criteria to generate marksheet. Total marks are calculated as: (Average of Activities × 20/3) + EoC (out of 80).</p>
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
                            <span class="selection-label">Subject:</span>
                            <span class="selection-value"><?= htmlspecialchars($selected_subject_name) ?></span>
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
                            <label for="subject">Subject *</label>
                            <select class="form-control" id="subject" name="subject" required>
                                <option value="">Select Stream First</option>
                                <?php if ($selected_data['subject'] && !empty($selected_subject_name)): ?>
                                    <option value="<?= $selected_data['subject'] ?>" selected>
                                        <?= htmlspecialchars($selected_subject_name) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" name="load_marksheet" class="btn btn-primary">
                            <i class="fas fa-file-alt"></i> Load Marksheet
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
                                <img src="<?= htmlspecialchars($school_info['school_logo']) ?>"
                                    alt="School Logo"
                                    class="school-logo">
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

                    <!-- Marksheet Info Section - NO ICONS -->
                    <div class="marksheet-info">
                        <div>
                            <p><strong>Subject:</strong> <?= htmlspecialchars($selected_subject_name) ?></p>
                            <p><strong>Class:</strong> <?= htmlspecialchars($selected_level_name) ?> - <?= htmlspecialchars($selected_class_name) ?> <?= htmlspecialchars($selected_stream_name) ?></p>
                        </div>
                        <div>
                            <p><strong>Term:</strong> <?= htmlspecialchars($selected_term_name) ?></p>
                            <p><strong>Academic Year:</strong> <?= htmlspecialchars($selected_academic_year_name) ?></p>
                        </div>
                        <div>
                            <p><strong>Teacher:</strong> <?= htmlspecialchars($fullname) ?></p>
                            <p><strong>Date:</strong> <?= date('Y-m-d') ?></p>
                        </div>
                    </div>

                    <h3 class="no-print">Marksheet for <?= htmlspecialchars($selected_subject_name) ?></h3>
                    <div class="marksheet-container">
                        <table class="marksheet-table">
                            <thead>
                                <tr>
                                    <th rowspan="2">#</th>
                                    <th rowspan="2">Student ID</th>
                                    <th rowspan="2">Name</th>
                                    <th rowspan="2">Gender</th>
                                    <th colspan="5">Activities (0-3)</th>
                                    <th rowspan="2">PS<br>(0-10)</th>
                                    <th rowspan="2">EoC<br>(0-80)</th>
                                    <th rowspan="2">Total<br>(100)</th>
                                    <th rowspan="2">Grade</th>
                                    <?php if ($selected_level == 2): // A Level 
                                    ?>
                                        <th rowspan="2">Points</th>
                                        <th rowspan="2">Remark</th>
                                    <?php else: // O Level 
                                    ?>
                                        <th rowspan="2">Achievement<br>Level</th>
                                    <?php endif; ?>
                                </tr>
                                <tr>
                                    <th>A1</th>
                                    <th>A2</th>
                                    <th>A3</th>
                                    <th>A4</th>
                                    <th>A5</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($marksheet_data as $index => $row):
                                    // Check if student has any marks by checking if total is empty or if any marks are present
                                    $has_marks = ($row['total'] !== '' && $row['total'] !== null) ||
                                        $row['a1'] !== '—' || $row['a2'] !== '—' ||
                                        $row['a3'] !== '—' || $row['a4'] !== '—' ||
                                        $row['a5'] !== '—' || $row['ps'] !== '—' ||
                                        $row['eoc'] !== '—';
                                ?>
                                    <tr<?= !$has_marks ? ' class="no-marks-row"' : '' ?>>
                                        <td><?= $index + 1 ?></td>
                                        <td><?= htmlspecialchars($row['student_id']) ?></td>
                                        <td style="text-align: left;"><?= htmlspecialchars($row['name']) ?></td>
                                        <td><?= htmlspecialchars($row['sex']) ?></td>
                                        <td><?= $row['a1'] ?></td>
                                        <td><?= $row['a2'] ?></td>
                                        <td><?= $row['a3'] ?></td>
                                        <td><?= $row['a4'] ?></td>
                                        <td><?= $row['a5'] ?></td>
                                        <td><?= $row['ps'] ?></td>
                                        <td><?= $row['eoc'] ?></td>
                                        <td class="total-col"><?= $row['total'] !== '' ? $row['total'] : '' ?></td>
                                        <td class="grade-col"><?= $has_marks ? $row['grade'] : '' ?></td>
                                        <?php if ($selected_level == 2): // A Level 
                                        ?>
                                            <td><?= $has_marks ? $row['points'] : '' ?></td>
                                            <td class="remark-col"><?= $has_marks ? htmlspecialchars($row['remark']) : '' ?></td>
                                        <?php else: // O Level 
                                        ?>
                                            <td class="achievement-col"><?= $has_marks ? htmlspecialchars($row['achievement_level']) : '' ?></td>
                                        <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="no-print" style="margin-top: 10px; padding: 10px; background: #f8f9fa; border-left: 4px solid #17a2b8;">
                        <p><strong>Note:</strong> Total Marks = (Average of Activities × 20/3) + EoC (out of 80)</p>
                        <p><small><em>Students with no marks entered will have blank fields in Total, Grade, <?= $selected_level == 2 ? 'Points, Remark' : 'Achievement Level' ?> columns.</em></small></p>
                    </div>

                    <!-- Teacher Signature for Print -->
                    <div class="teacher-signature no-print" style="display: none;">
                        <div class="signature-line">Teacher's Signature</div>
                        <div class="signature-line">Date: <?= date('d/m/Y') ?></div>
                    </div>

                    <div class="action-buttons no-print">
                        <button type="button" onclick="window.print()" class="btn btn-success">
                            <i class="fas fa-print"></i> Print / Save as PDF
                        </button>
                        <button type="submit" name="export_csv" form="selectionForm" class="btn btn-primary">
                            <i class="fas fa-file-csv"></i> Export to Excel (CSV)
                        </button>
                    </div>
                </div>

                <!-- Signature for Print - Only visible when printing -->
                <div style="display: none;">
                    <div id="print-signature">
                        <div style="display: flex; justify-content: space-between; margin-top: 40px; width: 100%;">
                            <div style="border-top: 1px solid #000; width: 200px; padding-top: 5px; text-align: center;">Teacher's Signature</div>
                            <div style="border-top: 1px solid #000; width: 200px; padding-top: 5px; text-align: center;">Date: <?= date('d/m/Y') ?></div>
                        </div>
                    </div>
                </div>

            <?php elseif (isset($_POST['load_marksheet'])): ?>
                <div class="empty-state">
                    <i class="fas fa-file-alt"></i>
                    <h3>No Marks Found</h3>
                    <p>No marks found for the selected criteria. Please ensure marks have been entered for this subject.</p>
                    <p style="margin-top: 1rem; color: var(--primary);">
                        <i class="fas fa-lightbulb"></i> Go to <a href="enter_marks.php" style="color: var(--primary); font-weight: bold;">Enter Marks</a> to add marks.
                    </p>
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

            // Clear form button
            $('#clearForm').on('click', function() {
                window.location.href = 'marksheets.php';
            });

            // Form validation
            $('#selectionForm').on('submit', function(e) {
                const requiredFields = ['academic_year', 'term', 'level', 'class', 'stream', 'subject'];
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