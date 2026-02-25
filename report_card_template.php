<?php
// report_card_template.php - FIXED VERSION
// MODIFIED: A-Level principal subjects count only grades A-E (excludes F, O, and any other grades)
// MODIFIED: Subsidiary subjects counted separately with D1-F9 grading
// FIXED: Principal count now correctly shows only subjects with grades A through E

require_once 'config.php';
require_once 'comment_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_teacher = ($user_role === 'teacher');

// Get teacher ID if user is a teacher
$teacher_id = null;
$teacher_info = null;
if ($is_teacher) {
    try {
        $stmt = $pdo->prepare("SELECT id, teacher_id, fullname FROM users WHERE id = ? AND role = 'teacher'");
        $stmt->execute([$user_id]);
        $teacher_info = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$teacher_info) {
            header("Location: index.php");
            exit;
        }
        $teacher_id = $teacher_info['id'];
    } catch (Exception $e) {
        error_log("Error fetching teacher info: " . $e->getMessage());
        header("Location: index.php");
        exit;
    }
} elseif (!$is_admin) {
    // Only admin and teachers can access this page
    header("Location: index.php");
    exit;
}

// Get parameters from POST
$academic_year_id = $_POST['academic_year'] ?? '';
$level_id = $_POST['level'] ?? '';
$term_id = $_POST['term'] ?? '';
$class_id = $_POST['class'] ?? '';
$stream_id = $_POST['stream'] ?? '';
$student_id = $_POST['student'] ?? '';

// Validate required fields
if (!$academic_year_id || !$level_id || !$term_id || !$class_id || !$stream_id || !$student_id) {
    die("Invalid parameters. Please go back and select all required fields.");
}

// For teachers, verify they have access to this class
if ($is_teacher && $teacher_id) {
    try {
        $access_check = $pdo->prepare("
            SELECT COUNT(*) FROM teacher_classes 
            WHERE teacher_id = ? AND class_id = ? AND academic_year_id = ? AND is_class_teacher = 1
        ");
        $access_check->execute([$teacher_id, $class_id, $academic_year_id]);
        if ($access_check->fetchColumn() == 0) {
            die("You do not have permission to access this class.");
        }
    } catch (Exception $e) {
        error_log("Error checking teacher access: " . $e->getMessage());
        die("Error verifying access.");
    }
}

// ==================== HELPER FUNCTIONS ====================

/**
 * Calculate total marks based on marksheet logic
 * Total = (Average of Activities × 20/3) + EoC (out of 80)
 */
function calculateTotalMarksHelper($marks)
{
    $activities = [];
    if (!empty($marks['a1']) && $marks['a1'] !== null && $marks['a1'] !== '')
        $activities[] = floatval($marks['a1']);
    if (!empty($marks['a2']) && $marks['a2'] !== null && $marks['a2'] !== '')
        $activities[] = floatval($marks['a2']);
    if (!empty($marks['a3']) && $marks['a3'] !== null && $marks['a3'] !== '')
        $activities[] = floatval($marks['a3']);
    if (!empty($marks['a4']) && $marks['a4'] !== null && $marks['a4'] !== '')
        $activities[] = floatval($marks['a4']);
    if (!empty($marks['a5']) && $marks['a5'] !== null && $marks['a5'] !== '')
        $activities[] = floatval($marks['a5']);

    $total = 0;
    if (!empty($activities)) {
        $average = array_sum($activities) / count($activities);
        // Convert average (0-3) to out of 20
        $activity_score = ($average / 3) * 20;
        $total += $activity_score;
    }

    // Add EoC score (out of 80)
    if (!empty($marks['eoc']) && $marks['eoc'] !== null && $marks['eoc'] !== '') {
        $total += floatval($marks['eoc']);
    }

    // Add project score if exists (for A-Level)
    if (!empty($marks['project']) && $marks['project'] !== null && $marks['project'] !== '') {
        $total += floatval($marks['project']);
    }

    // If no marks entered at all, return empty
    if (empty($activities) && empty($marks['eoc']) && empty($marks['project'])) {
        return '';
    }

    return round($total, 1);
}

/**
 * Calculate CA (20%) for O-Level - same as marksheet
 */
function calculateCAHelper($marks)
{
    $activities = [];
    if (!empty($marks['a1']) && $marks['a1'] !== null && $marks['a1'] !== '')
        $activities[] = floatval($marks['a1']);
    if (!empty($marks['a2']) && $marks['a2'] !== null && $marks['a2'] !== '')
        $activities[] = floatval($marks['a2']);
    if (!empty($marks['a3']) && $marks['a3'] !== null && $marks['a3'] !== '')
        $activities[] = floatval($marks['a3']);
    if (!empty($marks['a4']) && $marks['a4'] !== null && $marks['a4'] !== '')
        $activities[] = floatval($marks['a4']);
    if (!empty($marks['a5']) && $marks['a5'] !== null && $marks['a5'] !== '')
        $activities[] = floatval($marks['a5']);

    if (empty($activities)) {
        return '';
    }

    $average = array_sum($activities) / count($activities);
    // Convert average (0-3) to percentage out of 20%
    return round(($average / 3) * 20, 1);
}

/**
 * Get grade and points based on level and category - FIXED for A-Level
 * For A-Level principal subjects: returns A-F grades
 * For A-Level subsidiary subjects: returns D1-F9 grades
 */
function getGradeAndPointsHelper($pdo, $level_id, $category, $total_marks)
{
    if ($total_marks === '' || $total_marks === null) {
        return ['grade' => '', 'points' => '', 'achievement' => '', 'remark' => ''];
    }

    if ($level_id == 2) { // A Level
        if ($category == 'principal') {
            // Principal subjects use A-F grading scale
            $stmt = $pdo->prepare("
                SELECT grade_letter, points, remark 
                FROM alevel_grading_scale 
                WHERE subject_category = 'principal' AND ? BETWEEN min_score AND max_score
                ORDER BY points DESC
                LIMIT 1
            ");
            $stmt->execute([$total_marks]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($grade) {
                return [
                    'grade' => $grade['grade_letter'],
                    'points' => $grade['points'],
                    'achievement' => $grade['remark'] ?? '',
                    'remark' => $grade['remark'] ?? ''
                ];
            }
        } else {
            // Subsidiary subjects use D1-F9 grading scale
            $stmt = $pdo->prepare("
                SELECT grade_letter, points, remark 
                FROM alevel_grading_scale 
                WHERE subject_category = 'subsidiary' AND ? BETWEEN min_score AND max_score
                ORDER BY points DESC
                LIMIT 1
            ");
            $stmt->execute([$total_marks]);
            $grade = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($grade) {
                return [
                    'grade' => $grade['grade_letter'],
                    'points' => $grade['points'],
                    'achievement' => $grade['remark'] ?? '',
                    'remark' => $grade['remark'] ?? ''
                ];
            }
        }
    } else { // O Level
        $stmt = $pdo->prepare("
            SELECT grade_letter, achievement_level 
            FROM olevel_grading_scale 
            WHERE level_id = ? AND category = ? AND ? BETWEEN min_score AND max_score
            LIMIT 1
        ");
        $stmt->execute([$level_id, $category, $total_marks]);
        $grade = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($grade) {
            return [
                'grade' => $grade['grade_letter'],
                'points' => '',
                'achievement' => $grade['achievement_level'] ?? '',
                'remark' => $grade['achievement_level'] ?? ''
            ];
        }
    }

    return ['grade' => '—', 'points' => '', 'achievement' => '—', 'remark' => '—'];
}

/**
 * Get teacher initials from full name
 */
function getTeacherInitialsHelper($teacher_name)
{
    if (empty($teacher_name)) return '—';
    $name_parts = explode(' ', trim($teacher_name));
    $initials = '';
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return $initials ?: '—';
}

/**
 * Get subject teacher initials - matches marksheet logic
 */
function getSubjectTeacherInitialsHelper($pdo, $subject_id, $class_id, $academic_year_id)
{
    try {
        // First try teacher_assignments with specific class
        $stmt = $pdo->prepare("
            SELECT u.fullname 
            FROM users u
            JOIN teacher_assignments ta ON u.id = ta.teacher_id
            WHERE ta.subject_id = ? 
              AND ta.class_id = ? 
              AND ta.academic_year_id = ? 
              AND ta.assignment_type = 'Subject Teacher'
              AND ta.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$subject_id, $class_id, $academic_year_id]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher && !empty($teacher['fullname'])) {
            return getTeacherInitialsHelper($teacher['fullname']);
        }

        // Try teacher_subjects table
        $stmt = $pdo->prepare("
            SELECT u.fullname 
            FROM users u
            JOIN teacher_subjects ts ON u.id = ts.teacher_id
            WHERE ts.subject_id = ? 
            LIMIT 1
        ");
        $stmt->execute([$subject_id]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher && !empty($teacher['fullname'])) {
            return getTeacherInitialsHelper($teacher['fullname']);
        }

        // Try any teacher assignment without class filter
        $stmt = $pdo->prepare("
            SELECT u.fullname 
            FROM users u
            JOIN teacher_assignments ta ON u.id = ta.teacher_id
            WHERE ta.subject_id = ? 
              AND ta.academic_year_id = ? 
              AND ta.assignment_type = 'Subject Teacher'
              AND ta.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$subject_id, $academic_year_id]);
        $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($teacher && !empty($teacher['fullname'])) {
            return getTeacherInitialsHelper($teacher['fullname']);
        }
    } catch (Exception $e) {
        error_log("Error getting teacher initials: " . $e->getMessage());
    }

    return '—';
}

/**
 * Determine O-Level result (1=Pass, 2=Incomplete, 3=Fail, 4=Other)
 */
function determineOlevelResultHelper($compulsory_grades, $all_grades)
{
    // Check if all compulsory subjects have grades
    foreach ($compulsory_grades as $grade) {
        if ($grade === '' || $grade === '—') {
            return 2; // Incomplete
        }
    }

    $entered_grades = array_filter($all_grades, function ($g) {
        return $g !== '' && $g !== '—';
    });

    if (count($entered_grades) < 7) {
        return 2; // Incomplete
    }

    // Check for passing grades (A-D)
    foreach ($entered_grades as $grade) {
        if (in_array($grade, ['A', 'B', 'C', 'D'])) {
            return 1; // Pass
        }
    }

    // Check if all are E
    $all_e = true;
    foreach ($entered_grades as $grade) {
        if ($grade !== 'E') {
            $all_e = false;
            break;
        }
    }

    return $all_e ? 3 : 4; // 3=Fail, 4=Other
}

/**
 * Get next term opening date
 */
function getNextTermOpeningDateHelper($pdo, $academic_year_id, $current_term_id)
{
    try {
        // Try next term in same academic year
        $stmt = $pdo->prepare("
            SELECT opening_date
            FROM academic_terms 
            WHERE academic_year_id = ? AND id > ?
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$academic_year_id, $current_term_id]);
        $next_term = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($next_term && !empty($next_term['opening_date'])) {
            return date('d/m/Y', strtotime($next_term['opening_date']));
        }

        // Try first term of next academic year
        $stmt = $pdo->prepare("
            SELECT id FROM academic_years 
            WHERE id > ? AND status = 'active'
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([$academic_year_id]);
        $next_academic_year = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($next_academic_year) {
            $stmt = $pdo->prepare("
                SELECT opening_date
                FROM academic_terms 
                WHERE academic_year_id = ?
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([$next_academic_year['id']]);
            $first_term = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($first_term && !empty($first_term['opening_date'])) {
                return date('d/m/Y', strtotime($first_term['opening_date']));
            }
        }

        return 'TBA';
    } catch (Exception $e) {
        error_log("Error getting next term: " . $e->getMessage());
        return 'TBA';
    }
}

// ==================== FETCH DATA ====================
try {
    // Get student details
    $stmt = $pdo->prepare("
        SELECT s.*, l.name as level_name, c.name as class_name, st.name as stream_name,
               ay.year_name, ay.start_year
        FROM students s
        JOIN levels l ON s.level_id = l.id
        JOIN classes c ON s.class_id = c.id
        JOIN streams st ON s.stream_id = st.id
        JOIN academic_years ay ON s.academic_year_id = ay.id
        WHERE s.id = ? AND s.class_id = ? AND s.stream_id = ? AND s.status = 'active'
    ");
    $stmt->execute([$student_id, $class_id, $stream_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$student) {
        die("Student not found or not active.");
    }

    // Get current term details
    $stmt = $pdo->prepare("
        SELECT id, term_name, opening_date, closing_date, status 
        FROM academic_terms 
        WHERE id = ? AND academic_year_id = ?
    ");
    $stmt->execute([$term_id, $academic_year_id]);
    $current_term = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_term) {
        die("Term not found.");
    }

    // Get school information
    $stmt = $pdo->prepare("SELECT school_name, school_logo, address, motto, phone, email FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get ONLY student's assigned subjects (no extra subjects)
    $stmt = $pdo->prepare("
        SELECT s.id, s.code, s.name, s.category
        FROM subjects s 
        JOIN student_subjects ss ON s.id = ss.subject_id 
        WHERE ss.student_id = ? AND s.status = 'active'
        ORDER BY 
            CASE s.category 
                WHEN 'principal' THEN 1
                WHEN 'subsidiary' THEN 2
                WHEN 'compulsory' THEN 3 
                WHEN 'elective' THEN 4 
            END,
            s.name
    ");
    $stmt->execute([$student_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Determine marks table based on level
    $marks_table = ($level_id == 1) ? 'o_level_marks' : 'a_level_marks';

    // Get all marks for the student in one query
    $subject_ids = array_column($subjects, 'id');
    $subject_marks = [];
    $total_marks_sum = 0;
    $subject_count = 0;

    // A-Level counters - FIXED: Principal count only for grades A-E
    $principal_count = 0;
    $subsidiary_count = 0;
    $total_points_sum = 0;

    // For O-Level
    $all_grades = [];
    $compulsory_grades = [];
    $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

    if (!empty($subject_ids)) {
        $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
        $params = array_merge([$student['student_id'], $academic_year_id, $term_id], $subject_ids);

        $stmt = $pdo->prepare("
            SELECT subject_id, exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
            FROM $marks_table
            WHERE student_id = ? AND academic_year_id = ? AND term_id = ?
              AND subject_id IN ($placeholders)
        ");
        $stmt->execute($params);
        $marks_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group marks by subject
        $marks_by_subject = [];
        foreach ($marks_data as $mark) {
            $subj_id = $mark['subject_id'];
            if (!isset($marks_by_subject[$subj_id])) {
                $marks_by_subject[$subj_id] = [
                    'a1' => null,
                    'a2' => null,
                    'a3' => null,
                    'a4' => null,
                    'a5' => null,
                    'project' => null,
                    'eoc' => null
                ];
            }

            if ($mark['exam_type'] == 'Aol') {
                $marks_by_subject[$subj_id]['a1'] = $mark['a1'];
                $marks_by_subject[$subj_id]['a2'] = $mark['a2'];
                $marks_by_subject[$subj_id]['a3'] = $mark['a3'];
                $marks_by_subject[$subj_id]['a4'] = $mark['a4'];
                $marks_by_subject[$subj_id]['a5'] = $mark['a5'];
            } elseif ($mark['exam_type'] == 'Proj') {
                $marks_by_subject[$subj_id]['project'] = $mark['project_score'];
            } elseif ($mark['exam_type'] == 'EoC') {
                $marks_by_subject[$subj_id]['eoc'] = $mark['eoc_score'];
            }
        }
    }

    // Process each subject
    foreach ($subjects as $subject) {
        $subject_id = $subject['id'];
        $marks = $marks_by_subject[$subject_id] ?? [
            'a1' => null,
            'a2' => null,
            'a3' => null,
            'a4' => null,
            'a5' => null,
            'project' => null,
            'eoc' => null
        ];

        $total = calculateTotalMarksHelper($marks);
        $grade_info = getGradeAndPointsHelper($pdo, $level_id, $subject['category'], $total);
        $teacher_initials = getSubjectTeacherInitialsHelper($pdo, $subject_id, $class_id, $academic_year_id);

        // Track for summaries
        if ($total !== '' && $total !== null) {
            $total_marks_sum += $total;
            $subject_count++;

            if ($level_id == 2) { // A-Level - FIXED COUNTING LOGIC
                // Add points for ALL subjects (principal and subsidiary)
                if (!empty($grade_info['points']) && is_numeric($grade_info['points'])) {
                    $total_points_sum += $grade_info['points'];
                }

                // FIXED: Principal subjects - count only grades A through E (excludes F, O, etc.)
                if ($subject['category'] == 'principal') {
                    // Valid principal grades are A, B, C, D, E
                    $valid_principal_grades = ['A', 'B', 'C', 'D', 'E'];
                    if (!empty($grade_info['grade']) && in_array($grade_info['grade'], $valid_principal_grades)) {
                        $principal_count++;
                    }
                    // Grades like F, O, or any other are NOT counted
                }

                // Subsidiary subjects - count all (they use D1-F9 scale)
                if ($subject['category'] == 'subsidiary') {
                    // All subsidiary subjects are counted regardless of grade
                    $subsidiary_count++;
                }
            } else { // O-Level
                if (!empty($grade_info['grade']) && $grade_info['grade'] !== '—') {
                    $all_grades[] = $grade_info['grade'];
                    if (isset($grade_counts[$grade_info['grade']])) {
                        $grade_counts[$grade_info['grade']]++;
                    }
                    if ($subject['category'] == 'compulsory') {
                        $compulsory_grades[] = $grade_info['grade'];
                    }
                }
            }
        }

        $subject_marks[] = [
            'subject' => $subject,
            'marks' => $marks,
            'total' => $total,
            'grade' => $grade_info['grade'],
            'points' => $grade_info['points'] ?? '',
            'achievement' => $grade_info['achievement'] ?? '',
            'ca' => ($level_id == 1) ? calculateCAHelper($marks) : null,
            'teacher_initials' => $teacher_initials
        ];
    }

    $average = ($subject_count > 0) ? round($total_marks_sum / $subject_count, 1) : 0;
    $result = ($level_id == 1) ? determineOlevelResultHelper($compulsory_grades, $all_grades) : null;

    // Get comments from academic_comments table
    $head_teacher_comment = '';
    $class_teacher_comment = '';

    if ($level_id == 2) { // A-Level
        $head_teacher_comment = getHeadTeacherComment(
            $pdo,
            $student_id,
            $academic_year_id,
            $term_id,
            $level_id,
            $total_points_sum,
            null
        );

        $class_teacher_comment = getClassTeacherComment(
            $pdo,
            $student_id,
            $academic_year_id,
            $term_id,
            $level_id,
            $total_points_sum,
            null
        );
    } else { // O-Level
        $head_teacher_comment = getHeadTeacherComment(
            $pdo,
            $student_id,
            $academic_year_id,
            $term_id,
            $level_id,
            null,
            $grade_counts
        );

        $class_teacher_comment = getClassTeacherComment(
            $pdo,
            $student_id,
            $academic_year_id,
            $term_id,
            $level_id,
            null,
            $grade_counts
        );
    }

    // Get next term opening date
    $next_term_begins = getNextTermOpeningDateHelper($pdo, $academic_year_id, $term_id);
} catch (Exception $e) {
    die("Error generating report: " . $e->getMessage());
}

// Format address
$formatted_address = '';
if (!empty($school_info['address'])) {
    $formatted_address = nl2br(htmlspecialchars($school_info['address']));
} else {
    $formatted_address = 'P.O BOX 149<br>Lorikitae Cell, Lorengecorwa Ward Napak Town Council';
}

// Format dates for display
$date_of_issue = !empty($current_term['closing_date']) ? date('d/m/Y', strtotime($current_term['closing_date'])) : date('d/m/Y');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($student['surname'] . ' ' . $student['other_names']) ?> - Report Card</title>
    <style>
        /* A4 Portrait Exact Dimensions */
        @page {
            size: 8.27in 11.69in;
            margin: 0.5in 0.5in 0.5in 0.5in;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Century Gothic;
        }

        body {
            background: white;
            line-height: 1.25;
            color: #000;
            font-size: 11pt;
            width: 7.27in;
            margin: 0 auto;
        }

        .report-card {
            width: 100%;
            background: white;
            border: 2px solid #000;
            padding: 0.15in;

        }

        /* School Header */
        .school-header {
            display: flex;
            align-items: center;
            margin-bottom: 0.1in;
            border-bottom: 2px solid #000;
            padding-bottom: 0.08in;
        }

        .school-logo-container {
            flex: 0 0 0.8in;
            margin-right: 0.15in;
        }

        .school-logo {
            width: 100%;
            max-width: 1.5in;
            height: auto;
            border: none;
            margin-left: 0.8in;
        }

        .school-details {
            flex: 1;
            text-align: center;
            margin-right: 0.6in;
        }

        .school-details h1 {
            font-size: 14pt;
            margin: 0 0 0.03in 0;
            color: #000;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .school-details p {
            margin: 0.02in 0;
            font-size: 9pt;
        }

        .school-address {
            margin: 0.02in 0;
        }

        .school-contact {
            font-size: 8.5pt;
        }

        .school-motto {
            font-style: italic;
            margin: 0.03in 0;
            font-weight: bold;
            font-size: 10pt;
        }

        /* Report Title */
        .report-title {
            text-align: center;
            font-size: 12pt;
            font-weight: bold;
            color: #000;
            margin: 0.1in 0 0.08in 0;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid #000;
            padding-bottom: 0.05in;
        }

        /* Student Information */
        .student-info {
            margin: 0.1in 0;
            padding: 0.1in;
            border: 1px solid #000;
            background: #fff;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.08in;
        }

        .info-item {
            display: flex;
            flex-direction: column;
        }

        .info-label {
            font-size: 9pt;
            font-weight: bold;
            color: #000;
        }

        .info-value {
            font-size: 10pt;
            font-weight: normal;
            border-bottom: 1px dotted #999;
            padding: 0.02in 0;
        }

        /* Tables */
        .marks-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.1in 0;
            font-size: 9pt;
            border: 1px solid #000;
        }

        .marks-table th {
            background: #fff;
            color: #000;
            padding: 0.05in 0.03in;
            text-align: center;
            font-weight: bold;
            border: 1px solid #000;
            font-size: 9pt;
        }

        .marks-table th:first-child {
            text-align: left;
            padding-left: 0.08in;
        }

        .marks-table td {
            padding: 0.04in 0.03in;
            border: 1px solid #000;
            text-align: center;
            font-size: 9pt;
        }

        .marks-table td:first-child {
            text-align: left;
            font-weight: 500;
            padding-left: 0.08in;
        }

        .marks-table tr:nth-child(even) {
            background: #fff;
        }

        .subject-name {
            text-align: left;
            font-weight: 500;
            padding-left: 0.08in;
        }

        /* Result Section - Maintaining Numeric Codes */
        .result-section {
            margin: 0.1in 0;
            font-weight: bold;
            font-size: 12pt;
        }

        /* Comments Section */
        .comments-section {
            margin: 0.1in 0;
            width: 100%;
            border: 1px solid #000;
        }

        .comment-row {
            display: flex;
            border-bottom: 1px solid #000;
        }

        .comment-row:last-child {
            border-bottom: none;
        }

        .comment-label-cell {
            width: 33%;
            padding: 0.06in;
            font-weight: bold;
            border-right: 1px solid #000;
            background: #f5f5f5;
            display: flex;
            align-items: center;
        }

        .comment-content-cell {
            width: 65%;
            padding: 0.06in;
            min-height: 0.3in;
        }

        .signature-row {
            display: flex;
            margin-top: 0.35in;
            gap: 0.2in;
        }

        .signature-cell {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 0.1in;
            margin-bottom: 0.05in;
        }

        .signature-label {
            font-weight: bold;
            white-space: nowrap;
        }

        .signature-line {
            border-bottom: 2px dotted #000;
            flex: 1;
            height: 0.1in;
            display: inline-block;
        }

        /* Points Table for A-Level - FIXED: Now shows correct principal count */
        .points-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.06in 0;
            border: 1px solid #000;
        }

        .points-table th {
            background: #fff;
            color: #000;
            padding: 0.04in 0.05in;
            border: 1px solid #000;
            text-align: center;
            font-weight: bold;
            font-size: 10pt;
        }

        .points-table td {
            border: 1px solid #000;
            padding: 0.04in 0.05in;
            text-align: center;
            width: 33.33%;
            font-size: 12pt;
            font-weight: bold;
        }

        /* Grading Scale - UPDATED to show correct A-Level scales */
        .grading-scale {
            margin-top: 0.1in;
            padding: 0.08in;
            border: 1px solid #000;
            background: #fff;
            font-size: 8.5pt;
            text-align: center;
        }

        .grading-scale table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0.05in;
        }

        .no-top-left-border {
            border-top: none !important;
            border-left: none !important;
        }

        .text-align-left {
            text-align: left !important;
            width: 140px;
        }

        .grading-scale th {
            background: #fff;
            color: #000;
            padding: 0.03in;
            border: 1px solid #000;
            font-weight: bold;
            font-size: 8.5pt;
        }

        .grading-scale td {
            padding: 0.03in;
            border: 1px solid #000;
            text-align: center;
            font-size: 8.5pt;
        }

        /* Footer Section */
        .footer-section {
            margin-top: 0.1in;
            border: 1px solid #000;
            width: 100%;
            table-layout: fixed;
        }

        .footer-row {
            display: flex;
            width: 100%;
            border-bottom: 1px solid #000;
        }

        .footer-row:last-child {
            border-bottom: none;
        }

        .footer-cell {
            flex: 1;
            min-width: 0;
            padding: 0.04in 0.02in;
            border-right: 1px solid #000;
            text-align: center;
            font-size: 8.5pt;
            word-wrap: break-word;
            overflow-wrap: break-word;
            hyphens: auto;
            line-height: 1.0;
        }

        .footer-cell:last-child {
            border-right: none;
        }

        .footer-label {
            font-weight: bold;
            color: #000;
            display: block;
            text-align: center;
        }

        .footer-value {
            display: block;
            text-align: center;
            margin-top: 0.02in;
        }

        /* Invalid stamp */
        .invalid-stamp {
            text-align: center;
            margin-top: 0.1in;
            font-size: 8.5pt;
            font-style: italic;
            color: #000;
        }

        /* Print-specific styles */
        @media print {
            body {
                background: white;
                width: 7.27in;
                margin: 0 auto;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .report-card {
                border: 2px solid #000;
                box-shadow: none;
                page-break-inside: avoid;
                break-inside: avoid;
                border: 6px double #004080;


            }
        }
    </style>
</head>

<body>
    <div class="report-card">
        <!-- School Header -->
        <div class="school-header">
            <div class="school-logo-container">
                <?php if (!empty($school_info['school_logo'])): ?>
                    <img src="<?= htmlspecialchars($school_info['school_logo']) ?>" alt="School Logo" class="school-logo">
                <?php else: ?>
                    <div style="width: 0.8in; height: 0.8in; border: 1px solid #000; display: flex; align-items: center; justify-content: center;">
                        <span style="font-size: 9pt;">Logo</span>
                    </div>
                <?php endif; ?>
            </div>
            <div class="school-details">
                <h1><?= htmlspecialchars($school_info['school_name'] ?? 'NAPAK SEED SECONDARY SCHOOL') ?></h1>
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

        <!-- Report Title -->
        <div class="report-title">
            <?= ($student['level_id'] == 1) ? 'O-LEVEL REPORT CARD' : 'ADVANCED LEVEL REPORT CARD' ?>
        </div>

        <!-- Student Information -->
        <div class="student-info">
            <div class="info-item">
                <span class="info-label">STUDENT NAME:</span>
                <span class="info-value"><?= htmlspecialchars($student['surname'] . ' ' . $student['other_names']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">ADM NO:</span>
                <span class="info-value"><?= htmlspecialchars($student['student_id']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">SEX:</span>
                <span class="info-value"><?= ucfirst($student['sex']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">CLASS:</span>
                <span class="info-value"><?= htmlspecialchars($student['class_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">STREAM:</span>
                <span class="info-value"><?= htmlspecialchars($student['stream_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">TERM:</span>
                <span class="info-value"><?= htmlspecialchars($current_term['term_name'] ?? 'Term I') ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">YEAR:</span>
                <span class="info-value"><?= htmlspecialchars($student['year_name']) ?></span>
            </div>
        </div>

        <!-- Continuous Assessment & Summative Assessment Title -->
        <div style="font-weight: bold; text-align: center; margin: 0.05in 0; font-size: 10pt;">
            CONTINUOUS ASSESSMENT & SUMMATIVE ASSESSMENT
        </div>

        <?php if ($student['level_id'] == 1): ?>
            <!-- O-Level Report Card Format -->
            <table class="marks-table">
                <thead>
                    <tr>
                        <th>SUBJECT NAME</th>
                        <th>CA (20%)</th>
                        <th>EoC (80%)</th>
                        <th>TOTAL (100%)</th>
                        <th>GRADE</th>
                        <th>ACHIEVEMENT LEVEL</th>
                        <th>TEACHER'S INITIALS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subject_marks as $subject_data): ?>
                        <tr>
                            <td class="subject-name"><?= htmlspecialchars($subject_data['subject']['name']) ?></td>
                            <td><?= ($subject_data['ca'] !== '' && $subject_data['ca'] !== null) ? $subject_data['ca'] : '-' ?></td>
                            <td><?= (!empty($subject_data['marks']['eoc']) && $subject_data['marks']['eoc'] !== null) ? $subject_data['marks']['eoc'] : '-' ?></td>
                            <td><?= ($subject_data['total'] !== '') ? $subject_data['total'] : '-' ?></td>
                            <td><?= $subject_data['grade'] ?: '-' ?></td>
                            <td><?= $subject_data['achievement'] ?: '-' ?></td>
                            <td><?= $subject_data['teacher_initials'] ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- RESULT - Maintaining Numeric Code -->
            <div class="result-section">
                RESULT: <?= $result !== null ? $result : '-' ?>
            </div>
        <?php else: ?>
            <!-- A-Level Report Card Format -->
            <table class="marks-table">
                <thead>
                    <tr>
                        <th>SUBJECTS</th>
                        <th>MARKS (100%)</th>
                        <th>SUBJECT GRADE</th>
                        <th>REMARKS</th>
                        <th>TEACHERS' INITIALS</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subject_marks as $subject_data): ?>
                        <tr>
                            <td class="subject-name"><?= htmlspecialchars($subject_data['subject']['name']) ?></td>
                            <td><?= ($subject_data['total'] !== '') ? $subject_data['total'] : '-' ?></td>
                            <td><?= $subject_data['grade'] ?: '-' ?></td>
                            <td><?= $subject_data['achievement'] ?: '-' ?></td>
                            <td><?= $subject_data['teacher_initials'] ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- A-Level Points Summary - FIXED: Now correctly shows principal count (A-E only) -->
            <table class="points-table">
                <thead>
                    <tr>
                        <th>PRINCIPAL PASSES</th>
                        <th>SUBSIDIARY PASSES</th>
                        <th>TOTAL POINTS</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?= $principal_count ?></td>
                        <td><?= $subsidiary_count ?></td>
                        <td><?= $total_points_sum ?></td>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Comments Section - From academic_comments table -->
        <div class="comments-section">
            <!-- Class Teacher Comment -->
            <div class="comment-row">
                <div class="comment-label-cell">CLASS TEACHER'S COMMENT</div>
                <div class="comment-content-cell">
                    <?= htmlspecialchars($class_teacher_comment ?: 'No class teacher comment available for this performance range.') ?>
                </div>
            </div>

            <!-- Head Teacher Comment -->
            <div class="comment-row">
                <div class="comment-label-cell">HEAD TEACHER'S COMMENT</div>
                <div class="comment-content-cell">
                    <?= htmlspecialchars($head_teacher_comment ?: 'No head teacher comment available for this performance range.') ?>
                </div>
            </div>
        </div>

        <!-- Signatures -->
        <div class="signature-row">
            <div class="signature-cell">
                <span class="signature-label">CLASS TEACHER'S SIGNATURE:</span>
                <span class="signature-line"></span>
            </div>
            <div class="signature-cell">
                <span class="signature-label">HEAD TEACHER'S SIGNATURE:</span>
                <span class="signature-line"></span>
            </div>
        </div>

        <!-- Remarks Section -->
        <div class="remarks-section">
            <!-- Grading Scale - UPDATED to show correct scales -->
            <div class="grading-scale">
                <?php if ($student['level_id'] == 1): ?>
                    <!-- O-Level Grading Scale -->
                    <table>
                        <thead>
                            <tr>
                                <td class="no-top-left-border"></td>
                                <td colspan="5"><strong>GRADING SCALE</strong></td>
                            </tr>
                            <tr>
                                <th class="text-align-left">SCORE</th>
                                <th>80-100</th>
                                <th>70-79</th>
                                <th>60-69</th>
                                <th>50-59</th>
                                <th>0-49</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-align-left"><strong>GRADE</strong></td>
                                <td>A</td>
                                <td>B</td>
                                <td>C</td>
                                <td>D</td>
                                <td>E</td>
                            </tr>
                            <tr>
                                <td class="text-align-left"><strong>ACHIEVEMENT LEVEL</strong></td>
                                <td>Exceptional</td>
                                <td>Outstanding</td>
                                <td>Satisfactory</td>
                                <td>Basic</td>
                                <td>Elementary</td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <!-- A-Level Grading Scale - Shows both principal and subsidiary scales -->
                    <table>
                        <thead>
                            <tr>
                                <td class="no-top-left-border"></td>
                                <td colspan="9"><strong>GRADING SCALE</strong></td>
                            </tr>
                            <tr>
                                <th class="text-align-left">SCORE RANGE</th>
                                <th>85-100</th>
                                <th>80-84</th>
                                <th>70-79</th>
                                <th>65-69</th>
                                <th>60-64</th>
                                <th>50-59</th>
                                <th>40-49</th>
                                <th>35-39</th>
                                <th>0-34</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="text-align-left"><strong>PRINCIPAL GRADE</strong></td>
                                <td>A</td>
                                <td>A</td>
                                <td>B</td>
                                <td>B</td>
                                <td>C</td>
                                <td>D</td>
                                <td>E</td>
                                <td>F</td>
                                <td>F</td>
                            </tr>
                            <tr>
                                <td class="text-align-left"><strong>SUBSIDIARY GRADE</strong></td>
                                <td>D1</td>
                                <td>D2</td>
                                <td>C3</td>
                                <td>C4</td>
                                <td>C5</td>
                                <td>C6</td>
                                <td>P7</td>
                                <td>P8</td>
                                <td>F9</td>
                            </tr>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Footer Section -->
            <div class="footer-section">
                <div class="footer-row">
                    <div class="footer-cell"><span class="footer-label">STUDENT PAY CODE</span></div>
                    <div class="footer-cell"><span class="footer-label">OUTSTANDING FEES:</span></div>
                    <div class="footer-cell"><span class="footer-label">NEXT TERM FEES</span></div>
                    <div class="footer-cell"><span class="footer-label">DATE OF ISSUE:</span></div>
                    <div class="footer-cell"><span class="footer-label">NEXT TERM BEGINS:</span></div>
                </div>
                <div class="footer-row">
                    <div class="footer-cell"><span class="footer-value"></span></div>
                    <div class="footer-cell"><span class="footer-value"></span></div>
                    <div class="footer-cell"><span class="footer-value"></span></div>
                    <div class="footer-cell"><span class="footer-value"><?= htmlspecialchars($date_of_issue) ?></span></div>
                    <div class="footer-cell"><span class="footer-value"><?= htmlspecialchars($next_term_begins) ?></span></div>
                </div>
            </div>

            <!-- Invalid stamp -->
            <div class="invalid-stamp">
                This report card is invalid without school stamp
            </div>
        </div>
    </div>

    <script>
        if (window.parent && window.parent.document.getElementById('reportFrame')) {
            window.parent.document.getElementById('loadingSpinner').style.display = 'none';
        }
    </script>
</body>

</html>