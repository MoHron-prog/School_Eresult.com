<?php
// bulk_report_card_print.php
// Description: Generate and print multiple student report cards at once
// Supports both O-Level and A-Level formats with automatic layout adaptation
// FIXED: Page break logic - no blank first page, proper page breaks between students
// ADDED: Head Teacher and Class Teacher comments integration
// MODIFIED: Excluded project marks from A-Level total marks calculation
// MODIFIED: A-Level principal subjects count only grades A-E (excludes F)
// MODIFIED: Subsidiary subjects use D1-F9 grading scale
// FIXED: Added function_exists checks for comment helper functions to resolve VSCode errors
// FIXED: Comment section layout updated to use table format from table.html

require_once 'config.php';
require_once 'comment_helper.php';

// Check if comment helper functions exist, if not define them here as fallback
if (!function_exists('getHeadTeacherComment')) {
    /**
     * Fallback function for getHeadTeacherComment if not defined in comment_helper.php
     */
    function getHeadTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id, $total_points = null, $grade_counts = null)
    {
        // Try to include the file again (in case it failed)
        @include_once 'comment_helper.php';

        // If function now exists, call it
        if (function_exists('getHeadTeacherComment')) {
            return getHeadTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id, $total_points, $grade_counts);
        }

        // Otherwise return a default comment
        error_log("getHeadTeacherComment function not found - using fallback");
        return getDefaultFallbackComment($level_id, 'head_teacher', $total_points, $grade_counts);
    }
}

if (!function_exists('getClassTeacherComment')) {
    /**
     * Fallback function for getClassTeacherComment if not defined in comment_helper.php
     */
    function getClassTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id, $total_points = null, $grade_counts = null)
    {
        // Try to include the file again (in case it failed)
        @include_once 'comment_helper.php';

        // If function now exists, call it
        if (function_exists('getClassTeacherComment')) {
            return getClassTeacherComment($pdo, $student_id, $academic_year_id, $term_id, $level_id, $total_points, $grade_counts);
        }

        // Otherwise return a default comment
        error_log("getClassTeacherComment function not found - using fallback");
        return getDefaultFallbackComment($level_id, 'class_teacher', $total_points, $grade_counts);
    }
}

/**
 * Fallback default comment generator
 */
if (!function_exists('getDefaultFallbackComment')) {
    function getDefaultFallbackComment($level_id, $comment_type, $total_points = null, $grade_counts = null)
    {
        if ($level_id == 2 && $total_points !== null) {
            // A-Level default comments based on total points
            if ($total_points >= 24) {
                return "Excellent performance. Maintain this outstanding standard.";
            } elseif ($total_points >= 18) {
                return "Very good performance. Keep up the good work.";
            } elseif ($total_points >= 12) {
                return "Good performance. There is room for improvement.";
            } elseif ($total_points >= 6) {
                return "Satisfactory performance. More effort is needed.";
            } else {
                return "Needs significant improvement. Seek academic support.";
            }
        } elseif ($level_id == 1 && is_array($grade_counts)) {
            // O-Level default comments based on grade counts
            $total_passing = ($grade_counts['A'] ?? 0) + ($grade_counts['B'] ?? 0) + ($grade_counts['C'] ?? 0) + ($grade_counts['D'] ?? 0);
            $total_grades = array_sum($grade_counts ?? []);

            if (($grade_counts['A'] ?? 0) >= 5) {
                return "Outstanding performance. Excellent work!";
            } elseif ($total_passing >= 6) {
                return "Very good performance. Keep it up.";
            } elseif ($total_passing >= 4) {
                return "Good performance. Continue working hard.";
            } elseif ($total_grades >= 5) {
                return "Satisfactory performance. More effort required.";
            } else {
                return "Needs improvement. Please seek academic guidance.";
            }
        }

        // Generic fallback
        return ($comment_type === 'head_teacher')
            ? "Keep working hard and strive for excellence."
            : "A student with potential who should work harder to achieve better results.";
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$is_admin = ($user_role === 'admin');
$is_teacher = ($user_role === 'teacher');

// Strict access control - only admin and class teachers
if (!$is_admin && !$is_teacher) {
    die("Unauthorized Access: This module is only accessible to Administrators and Class Teachers.");
}

// Get teacher ID if user is a teacher
$teacher_id = null;
$teacher_info = null;
$teacher_classes = [];

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

        // Get teacher's assigned classes for this academic year
        $stmt = $pdo->prepare("
            SELECT DISTINCT tc.class_id, tc.stream_id, c.name as class_name, s.name as stream_name
            FROM teacher_classes tc
            JOIN classes c ON tc.class_id = c.id
            LEFT JOIN streams s ON tc.stream_id = s.id
            WHERE tc.teacher_id = ? AND tc.is_class_teacher = 1
        ");
        $stmt->execute([$teacher_id]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($teacher_classes)) {
            die("You are not assigned as a class teacher for any class.");
        }
    } catch (Exception $e) {
        error_log("Error fetching teacher info: " . $e->getMessage());
        header("Location: index.php");
        exit;
    }
}

// Fetch user details for display
try {
    $stmt = $pdo->prepare("SELECT fullname, email FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $fullname = htmlspecialchars($user['fullname'] ?? 'User');
    $email = htmlspecialchars($user['email'] ?? '');
} catch (Exception $e) {
    $fullname = "User";
    $email = "—";
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

// Fetch school information
$school_info = [];
try {
    $stmt = $pdo->prepare("SELECT school_name, school_logo, address, motto, phone, email FROM school_info ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $school_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error fetching school info: " . $e->getMessage());
}

// ==================== HELPER FUNCTIONS ====================

/**
 * Get teacher initials from full name
 */
function getTeacherInitials($fullname)
{
    if (empty($fullname) || $fullname === '—') return '—';

    $name_parts = explode(' ', trim($fullname));
    $initials = '';
    foreach ($name_parts as $part) {
        if (!empty($part)) {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    return $initials ?: '—';
}

/**
 * Get teacher initials for subject - FIXED VERSION
 * Returns first letters of teacher's name
 */
function getSubjectTeacherInitials($pdo, $subject_id, $class_id, $academic_year_id)
{
    try {
        // First try to get the subject teacher from teacher_assignments with specific class
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
            return getTeacherInitials($teacher['fullname']);
        }

        // If not found, try teacher_subjects table
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
            return getTeacherInitials($teacher['fullname']);
        }

        // If still not found, try to get any teacher assigned to this subject without class filter
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
            return getTeacherInitials($teacher['fullname']);
        }
    } catch (Exception $e) {
        error_log("Error getting teacher initials: " . $e->getMessage());
    }

    return '—';
}

/**
 * Calculate total marks for A-Level student - MODIFIED to exclude project marks
 */
function calculateALevelTotalMarks($marks)
{
    $activities = [];
    if (!empty($marks['a1']) && $marks['a1'] !== null) $activities[] = floatval($marks['a1']);
    if (!empty($marks['a2']) && $marks['a2'] !== null) $activities[] = floatval($marks['a2']);
    if (!empty($marks['a3']) && $marks['a3'] !== null) $activities[] = floatval($marks['a3']);
    if (!empty($marks['a4']) && $marks['a4'] !== null) $activities[] = floatval($marks['a4']);
    if (!empty($marks['a5']) && $marks['a5'] !== null) $activities[] = floatval($marks['a5']);

    $total = 0;
    if (!empty($activities)) {
        $average = array_sum($activities) / count($activities);
        $activity_score = ($average / 3) * 20;
        $total += $activity_score;
    }

    if (!empty($marks['eoc']) && $marks['eoc'] !== null) {
        $total += floatval($marks['eoc']);
    }

    return (empty($activities) && empty($marks['eoc'])) ? '' : round($total, 1);
}

/**
 * Calculate O-Level CA (20%)
 */
function calculateOLevelCA($marks)
{
    $activities = [];
    if (!empty($marks['a1']) && $marks['a1'] !== null) $activities[] = floatval($marks['a1']);
    if (!empty($marks['a2']) && $marks['a2'] !== null) $activities[] = floatval($marks['a2']);
    if (!empty($marks['a3']) && $marks['a3'] !== null) $activities[] = floatval($marks['a3']);
    if (!empty($marks['a4']) && $marks['a4'] !== null) $activities[] = floatval($marks['a4']);
    if (!empty($marks['a5']) && $marks['a5'] !== null) $activities[] = floatval($marks['a5']);

    if (empty($activities)) return '';

    $average = array_sum($activities) / count($activities);
    return round(($average / 3) * 20, 1);
}

/**
 * Get grade and points based on level and category
 * For A-Level:
 * - Principal subjects: Use A-F grading scale
 * - Subsidiary subjects: Use D1-F9 grading scale
 */
function getStudentGradeAndPoints($pdo, $level_id, $category, $total_marks)
{
    if ($total_marks === '' || $total_marks === null) {
        return ['grade' => '', 'points' => '', 'achievement' => '', 'remark' => '', 'is_passing' => false];
    }

    if ($level_id == 2) { // A-Level
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
                // For principal subjects, grades A through E are considered passing
                // Grade F is not passing
                $passing_principal_grades = ['A', 'B', 'C', 'D', 'E'];
                return [
                    'grade' => $grade['grade_letter'],
                    'points' => $grade['points'],
                    'achievement' => $grade['remark'] ?? '',
                    'remark' => $grade['remark'] ?? '',
                    'is_passing' => in_array($grade['grade_letter'], $passing_principal_grades)
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
                // For subsidiary subjects, all grades are counted (D1 through F9)
                return [
                    'grade' => $grade['grade_letter'],
                    'points' => $grade['points'],
                    'achievement' => $grade['remark'] ?? '',
                    'remark' => $grade['remark'] ?? '',
                    'is_passing' => true
                ];
            }
        }
    } else { // O-Level
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
                'remark' => $grade['achievement_level'] ?? '',
                'is_passing' => true
            ];
        }
    }

    return ['grade' => '—', 'points' => '', 'achievement' => '—', 'remark' => '—', 'is_passing' => false];
}

/**
 * Calculate class positions for all students (kept for internal use but not displayed)
 */
function calculateClassPositions($pdo, $class_id, $stream_id, $academic_year_id, $term_id, $level_id)
{
    $positions = [];

    try {
        // Get all students in the class
        $stmt = $pdo->prepare("
            SELECT id, student_id 
            FROM students 
            WHERE class_id = ? AND stream_id = ? AND status = 'active'
        ");
        $stmt->execute([$class_id, $stream_id]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($students)) return $positions;

        $student_ids = array_column($students, 'student_id');
        $marks_table = ($level_id == 1) ? 'o_level_marks' : 'a_level_marks';

        // Get all marks for all students in one query
        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
        $params = array_merge($student_ids, [$academic_year_id, $term_id]);

        $stmt = $pdo->prepare("
            SELECT student_id, subject_id, exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
            FROM $marks_table
            WHERE student_id IN ($placeholders) AND academic_year_id = ? AND term_id = ?
        ");
        $stmt->execute($params);
        $all_marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group marks by student
        $student_marks = [];
        foreach ($all_marks as $mark) {
            $student_id = $mark['student_id'];
            if (!isset($student_marks[$student_id])) {
                $student_marks[$student_id] = [];
            }

            $key = $mark['subject_id'] . '_' . $mark['exam_type'];
            $student_marks[$student_id][$key] = $mark;
        }

        // Calculate average for each student
        $averages = [];
        foreach ($students as $student) {
            $student_id = $student['student_id'];
            $marks_data = $student_marks[$student_id] ?? [];

            // Group marks by subject
            $subject_totals = [];
            foreach ($marks_data as $mark) {
                $subj_id = $mark['subject_id'];
                if (!isset($subject_totals[$subj_id])) {
                    $subject_totals[$subj_id] = [
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
                    $subject_totals[$subj_id]['a1'] = $mark['a1'];
                    $subject_totals[$subj_id]['a2'] = $mark['a2'];
                    $subject_totals[$subj_id]['a3'] = $mark['a3'];
                    $subject_totals[$subj_id]['a4'] = $mark['a4'];
                    $subject_totals[$subj_id]['a5'] = $mark['a5'];
                } elseif ($mark['exam_type'] == 'Proj') {
                    $subject_totals[$subj_id]['project'] = $mark['project_score'];
                } elseif ($mark['exam_type'] == 'EoC') {
                    $subject_totals[$subj_id]['eoc'] = $mark['eoc_score'];
                }
            }

            // Calculate total marks
            $total = 0;
            $count = 0;
            foreach ($subject_totals as $subj_marks) {
                $activities = [];
                if (!empty($subj_marks['a1']) && $subj_marks['a1'] !== null) $activities[] = $subj_marks['a1'];
                if (!empty($subj_marks['a2']) && $subj_marks['a2'] !== null) $activities[] = $subj_marks['a2'];
                if (!empty($subj_marks['a3']) && $subj_marks['a3'] !== null) $activities[] = $subj_marks['a3'];
                if (!empty($subj_marks['a4']) && $subj_marks['a4'] !== null) $activities[] = $subj_marks['a4'];
                if (!empty($subj_marks['a5']) && $subj_marks['a5'] !== null) $activities[] = $subj_marks['a5'];

                if (!empty($activities)) {
                    $avg_activity = array_sum($activities) / count($activities);
                    $activity_score = ($avg_activity / 3) * 20;
                    $total += $activity_score;

                    if (!empty($subj_marks['eoc']) && $subj_marks['eoc'] !== null) {
                        $total += $subj_marks['eoc'];
                    }
                    $count++;
                } elseif (!empty($subj_marks['eoc']) && $subj_marks['eoc'] !== null) {
                    $total += $subj_marks['eoc'];
                    $count++;
                }
            }

            if ($count > 0) {
                $averages[$student['id']] = $total / $count;
            }
        }

        // Sort and assign positions
        arsort($averages);
        $position = 1;
        foreach ($averages as $student_id => $avg) {
            $positions[$student_id] = $position++;
        }
    } catch (Exception $e) {
        error_log("Error calculating positions: " . $e->getMessage());
    }

    return $positions;
}

/**
 * Determine O-Level result (1=Pass, 2=Incomplete, 3=Fail, 4=Other)
 */
function determineOlevelResult($compulsory_grades, $all_grades)
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
function getNextTermOpeningDate($pdo, $academic_year_id, $current_term_id)
{
    try {
        // First try to get next term in the same academic year
        $stmt = $pdo->prepare("
            SELECT opening_date, term_name
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

        // If no next term in same academic year, try to get first term of next academic year
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

// ==================== AJAX HANDLER ====================

if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    header('Content-Type: application/json');

    $action = $_GET['action'] ?? '';

    if ($action == 'get_terms') {
        $academic_year_id = $_GET['academic_year'] ?? 0;
        try {
            $stmt = $pdo->prepare("
                SELECT id, term_name 
                FROM academic_terms 
                WHERE academic_year_id = ?
                ORDER BY id
            ");
            $stmt->execute([$academic_year_id]);
            $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'terms' => $terms]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action == 'get_classes') {
        $level_id = $_GET['level'] ?? 0;
        $academic_year_id = $_GET['academic_year'] ?? 0;

        try {
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT c.id, c.name 
                    FROM classes c
                    WHERE c.level_id = ? AND c.status = 'active'
                    ORDER BY c.name
                ");
                $stmt->execute([$level_id]);
            } else {
                $teacher_class_ids = array_column($teacher_classes, 'class_id');
                if (empty($teacher_class_ids)) {
                    echo json_encode(['success' => true, 'classes' => []]);
                    exit;
                }

                $placeholders = implode(',', array_fill(0, count($teacher_class_ids), '?'));
                $params = array_merge($teacher_class_ids, [$level_id]);

                $stmt = $pdo->prepare("
                    SELECT DISTINCT c.id, c.name 
                    FROM classes c
                    WHERE c.id IN ($placeholders) AND c.level_id = ? AND c.status = 'active'
                    ORDER BY c.name
                ");
                $stmt->execute($params);
            }

            $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'classes' => $classes]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action == 'get_streams') {
        $class_id = $_GET['class'] ?? 0;

        try {
            if ($is_admin) {
                $stmt = $pdo->prepare("
                    SELECT s.id, s.name 
                    FROM streams s
                    WHERE s.class_id = ? AND s.status = 'active'
                    ORDER BY s.name
                ");
                $stmt->execute([$class_id]);
            } else {
                $teacher_streams = array_filter($teacher_classes, function ($tc) use ($class_id) {
                    return $tc['class_id'] == $class_id && !empty($tc['stream_id']);
                });

                if (empty($teacher_streams)) {
                    echo json_encode(['success' => true, 'streams' => []]);
                    exit;
                }

                $stream_ids = array_column($teacher_streams, 'stream_id');
                $placeholders = implode(',', array_fill(0, count($stream_ids), '?'));

                $stmt = $pdo->prepare("
                    SELECT s.id, s.name 
                    FROM streams s
                    WHERE s.id IN ($placeholders) AND s.status = 'active'
                    ORDER BY s.name
                ");
                $stmt->execute($stream_ids);
            }

            $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'streams' => $streams]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action == 'get_student_count') {
        $class_id = $_GET['class'] ?? 0;
        $stream_id = $_GET['stream'] ?? 0;
        $academic_year_id = $_GET['academic_year'] ?? 0;
        $term_id = $_GET['term'] ?? 0;
        $level_id = $_GET['level'] ?? 0;

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as total
                FROM students
                WHERE class_id = ? AND stream_id = ? AND academic_year_id = ? AND status = 'active'
            ");
            $stmt->execute([$class_id, $stream_id, $academic_year_id]);
            $student_count = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

            $marks_table = ($level_id == 1) ? 'o_level_marks' : 'a_level_marks';

            $stmt = $pdo->prepare("
                SELECT COUNT(DISTINCT student_id) as count
                FROM $marks_table
                WHERE academic_year_id = ? AND term_id = ? AND class_id = ? AND stream_id = ?
            ");
            $stmt->execute([$academic_year_id, $term_id, $class_id, $stream_id]);
            $marks_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

            echo json_encode([
                'success' => true,
                'student_count' => $student_count,
                'marks_count' => $marks_count,
                'has_marks' => ($marks_count > 0)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    exit;
}

// ==================== BULK REPORT GENERATION ====================

$generate_bulk = isset($_POST['generate_bulk']);
$students_data = [];
$class_info = [];
$stream_info = [];
$level_info = [];
$term_info = [];
$academic_year_info = [];
$positions = [];
$has_data = false;
$selected_values = [
    'academic_year' => '',
    'level' => '',
    'term' => '',
    'class' => '',
    'stream' => ''
];

if ($generate_bulk) {
    // Get and validate parameters
    $selected_values['academic_year'] = $_POST['academic_year'] ?? 0;
    $selected_values['level'] = $_POST['level'] ?? 0;
    $selected_values['term'] = $_POST['term'] ?? 0;
    $selected_values['class'] = $_POST['class'] ?? 0;
    $selected_values['stream'] = $_POST['stream'] ?? 0;

    $academic_year_id = $selected_values['academic_year'];
    $level_id = $selected_values['level'];
    $term_id = $selected_values['term'];
    $class_id = $selected_values['class'];
    $stream_id = $selected_values['stream'];

    // Validate required fields
    if (!$academic_year_id || !$level_id || !$term_id || !$class_id || !$stream_id) {
        $error_message = "Please select all required fields.";
    } else {
        // For teachers, verify access
        if ($is_teacher && $teacher_id) {
            $has_access = false;
            foreach ($teacher_classes as $tc) {
                if ($tc['class_id'] == $class_id && (empty($tc['stream_id']) || $tc['stream_id'] == $stream_id)) {
                    $has_access = true;
                    break;
                }
            }

            if (!$has_access) {
                $error_message = "You do not have permission to access this class/stream.";
            }
        }

        if (!isset($error_message)) {
            try {
                // Get academic year info
                $stmt = $pdo->prepare("SELECT id, year_name FROM academic_years WHERE id = ?");
                $stmt->execute([$academic_year_id]);
                $academic_year_info = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get class info
                $stmt = $pdo->prepare("SELECT id, name FROM classes WHERE id = ?");
                $stmt->execute([$class_id]);
                $class_info = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get stream info
                $stmt = $pdo->prepare("SELECT id, name FROM streams WHERE id = ?");
                $stmt->execute([$stream_id]);
                $stream_info = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get level info
                $stmt = $pdo->prepare("SELECT id, name FROM levels WHERE id = ?");
                $stmt->execute([$level_id]);
                $level_info = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get term info
                $stmt = $pdo->prepare("SELECT id, term_name, opening_date, closing_date FROM academic_terms WHERE id = ?");
                $stmt->execute([$term_id]);
                $term_info = $stmt->fetch(PDO::FETCH_ASSOC);

                // Get all students in the class
                $stmt = $pdo->prepare("
                    SELECT s.*, ay.year_name
                    FROM students s
                    JOIN academic_years ay ON s.academic_year_id = ay.id
                    WHERE s.class_id = ? AND s.stream_id = ? AND s.academic_year_id = ? AND s.status = 'active'
                    ORDER BY s.surname, s.other_names
                ");
                $stmt->execute([$class_id, $stream_id, $academic_year_id]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($students)) {
                    $error_message = "No active students found in the selected class and stream.";
                } else {
                    // Get all subjects for this level
                    $stmt = $pdo->prepare("
                        SELECT id, code, name, category
                        FROM subjects
                        WHERE level_id = ? AND status = 'active'
                        ORDER BY 
                            CASE category 
                                WHEN 'principal' THEN 1
                                WHEN 'subsidiary' THEN 2
                                WHEN 'compulsory' THEN 3
                                WHEN 'elective' THEN 4
                            END,
                            name
                    ");
                    $stmt->execute([$level_id]);
                    $all_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $subjects_by_id = [];
                    foreach ($all_subjects as $subj) {
                        $subjects_by_id[$subj['id']] = $subj;
                    }

                    // Get student subjects
                    $student_ids = array_column($students, 'id');
                    $student_subjects_map = [];

                    if (!empty($student_ids)) {
                        $placeholders = implode(',', array_fill(0, count($student_ids), '?'));
                        $stmt = $pdo->prepare("
                            SELECT student_id, subject_id
                            FROM student_subjects
                            WHERE student_id IN ($placeholders)
                        ");
                        $stmt->execute($student_ids);
                        $student_subjects_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

                        foreach ($student_subjects_raw as $ss) {
                            if (!isset($student_subjects_map[$ss['student_id']])) {
                                $student_subjects_map[$ss['student_id']] = [];
                            }
                            $student_subjects_map[$ss['student_id']][] = $ss['subject_id'];
                        }
                    }

                    // Determine marks table
                    $marks_table = ($level_id == 1) ? 'o_level_marks' : 'a_level_marks';

                    // Get all marks for all students
                    $student_codes = array_column($students, 'student_id');
                    $placeholders = implode(',', array_fill(0, count($student_codes), '?'));
                    $params = array_merge($student_codes, [$academic_year_id, $term_id, $class_id, $stream_id]);

                    $stmt = $pdo->prepare("
                        SELECT student_id, subject_id, exam_type, a1, a2, a3, a4, a5, project_score, eoc_score
                        FROM $marks_table
                        WHERE student_id IN ($placeholders) 
                          AND academic_year_id = ? AND term_id = ? 
                          AND class_id = ? AND stream_id = ?
                    ");
                    $stmt->execute($params);
                    $all_marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

                    // Group marks by student and subject
                    $marks_by_student = [];
                    foreach ($all_marks as $mark) {
                        $student_code = $mark['student_id'];
                        $subject_id = $mark['subject_id'];

                        if (!isset($marks_by_student[$student_code])) {
                            $marks_by_student[$student_code] = [];
                        }
                        if (!isset($marks_by_student[$student_code][$subject_id])) {
                            $marks_by_student[$student_code][$subject_id] = [
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
                            $marks_by_student[$student_code][$subject_id]['a1'] = $mark['a1'];
                            $marks_by_student[$student_code][$subject_id]['a2'] = $mark['a2'];
                            $marks_by_student[$student_code][$subject_id]['a3'] = $mark['a3'];
                            $marks_by_student[$student_code][$subject_id]['a4'] = $mark['a4'];
                            $marks_by_student[$student_code][$subject_id]['a5'] = $mark['a5'];
                        } elseif ($mark['exam_type'] == 'Proj') {
                            $marks_by_student[$student_code][$subject_id]['project'] = $mark['project_score'];
                        } elseif ($mark['exam_type'] == 'EoC') {
                            $marks_by_student[$student_code][$subject_id]['eoc'] = $mark['eoc_score'];
                        }
                    }

                    // Calculate class positions
                    $positions = calculateClassPositions($pdo, $class_id, $stream_id, $academic_year_id, $term_id, $level_id);

                    // Build student data array
                    $has_marks = false;
                    foreach ($students as $student) {
                        $student_code = $student['student_id'];
                        $student_id_num = $student['id'];

                        // Get student's subjects
                        $student_subject_ids = $student_subjects_map[$student_id_num] ?? [];
                        $student_subjects = [];

                        foreach ($student_subject_ids as $subj_id) {
                            if (isset($subjects_by_id[$subj_id])) {
                                $student_subjects[] = $subjects_by_id[$subj_id];
                            }
                        }

                        // If no subjects assigned, use all subjects for the level (fallback)
                        if (empty($student_subjects)) {
                            $student_subjects = $all_subjects;
                        }

                        $student_marks_data = [];
                        $total_marks_sum = 0;
                        $subject_count = 0;
                        $all_grades = [];
                        $compulsory_grades = [];
                        $principal_count = 0;
                        $subsidiary_count = 0;
                        $total_points_sum = 0;

                        // For O-Level grade counts
                        $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

                        foreach ($student_subjects as $subject) {
                            $subject_id = $subject['id'];
                            $marks = $marks_by_student[$student_code][$subject_id] ?? [
                                'a1' => null,
                                'a2' => null,
                                'a3' => null,
                                'a4' => null,
                                'a5' => null,
                                'project' => null,
                                'eoc' => null
                            ];

                            // Calculate total based on level
                            if ($level_id == 2) { // A-Level
                                $total = calculateALevelTotalMarks($marks);
                            } else { // O-Level
                                $activities = [];
                                if (!empty($marks['a1']) && $marks['a1'] !== null) $activities[] = $marks['a1'];
                                if (!empty($marks['a2']) && $marks['a2'] !== null) $activities[] = $marks['a2'];
                                if (!empty($marks['a3']) && $marks['a3'] !== null) $activities[] = $marks['a3'];
                                if (!empty($marks['a4']) && $marks['a4'] !== null) $activities[] = $marks['a4'];
                                if (!empty($marks['a5']) && $marks['a5'] !== null) $activities[] = $marks['a5'];

                                $total = 0;
                                if (!empty($activities)) {
                                    $average = array_sum($activities) / count($activities);
                                    $total += ($average / 3) * 20;
                                }
                                if (!empty($marks['eoc']) && $marks['eoc'] !== null) {
                                    $total += $marks['eoc'];
                                }
                                $total = (empty($activities) && empty($marks['eoc'])) ? '' : round($total, 1);
                            }

                            // Get grade
                            $grade_info = getStudentGradeAndPoints($pdo, $level_id, $subject['category'], $total);

                            // Get teacher initials
                            $teacher_initials = getSubjectTeacherInitials($pdo, $subject_id, $class_id, $academic_year_id);

                            // Track for summaries
                            if ($total !== '' && $total !== null) {
                                $total_marks_sum += $total;
                                $subject_count++;
                                $has_marks = true;

                                if ($level_id == 2) { // A-Level
                                    // For principal subjects, count only grades A through E (not F)
                                    if ($subject['category'] == 'principal') {
                                        // Check if grade exists and is A through E
                                        if (!empty($grade_info['grade']) && in_array($grade_info['grade'], ['A', 'B', 'C', 'D', 'E'])) {
                                            $principal_count++;
                                        }
                                    }
                                    // For subsidiary subjects, count all (D1 through F9)
                                    if ($subject['category'] == 'subsidiary') {
                                        $subsidiary_count++;
                                    }
                                    // Add points for all subjects (both principal and subsidiary)
                                    if (!empty($grade_info['points']) && is_numeric($grade_info['points'])) {
                                        $total_points_sum += $grade_info['points'];
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

                            $student_marks_data[] = [
                                'subject' => $subject,
                                'marks' => $marks,
                                'total' => $total,
                                'grade' => $grade_info['grade'],
                                'points' => $grade_info['points'],
                                'achievement' => $grade_info['achievement'],
                                'teacher_initials' => $teacher_initials,
                                'ca' => ($level_id == 1) ? calculateOLevelCA($marks) : null
                            ];
                        }

                        $average = ($subject_count > 0) ? round($total_marks_sum / $subject_count, 1) : 0;
                        $position = $positions[$student_id_num] ?? 0;
                        $result = ($level_id == 1) ? determineOlevelResult($compulsory_grades, $all_grades) : null;

                        // Get comments based on performance - using the functions with fallbacks
                        $head_teacher_comment = '';
                        $class_teacher_comment = '';

                        if ($level_id == 2) { // A-Level
                            $head_teacher_comment = getHeadTeacherComment(
                                $pdo,
                                $student_id_num,
                                $academic_year_id,
                                $term_id,
                                $level_id,
                                $total_points_sum,
                                null
                            );

                            $class_teacher_comment = getClassTeacherComment(
                                $pdo,
                                $student_id_num,
                                $academic_year_id,
                                $term_id,
                                $level_id,
                                $total_points_sum,
                                null
                            );
                        } else { // O-Level
                            $head_teacher_comment = getHeadTeacherComment(
                                $pdo,
                                $student_id_num,
                                $academic_year_id,
                                $term_id,
                                $level_id,
                                null,
                                $grade_counts
                            );

                            $class_teacher_comment = getClassTeacherComment(
                                $pdo,
                                $student_id_num,
                                $academic_year_id,
                                $term_id,
                                $level_id,
                                null,
                                $grade_counts
                            );
                        }

                        $students_data[] = [
                            'student' => $student,
                            'marks' => $student_marks_data,
                            'total_marks' => $total_marks_sum,
                            'average' => $average,
                            'position' => $position,
                            'result' => $result,
                            'principal_count' => $principal_count,
                            'subsidiary_count' => $subsidiary_count,
                            'total_points' => $total_points_sum,
                            'all_grades' => $all_grades,
                            'grade_counts' => $grade_counts,
                            'head_teacher_comment' => $head_teacher_comment,
                            'class_teacher_comment' => $class_teacher_comment
                        ];
                    }

                    if (!$has_marks) {
                        $warning_message = "No marks found for the selected term. Please ensure marks have been entered.";
                    }

                    $has_data = true;
                }
            } catch (Exception $e) {
                $error_message = "Error generating reports: " . $e->getMessage();
                error_log("Bulk report error: " . $e->getMessage());
            }
        }
    }
}

// Format date for display
$date_of_issue = !empty($term_info['closing_date']) ? date('d/m/Y', strtotime($term_info['closing_date'])) : date('d/m/Y');

// Get next term opening date
$next_term_begins = 'TBA';
if ($generate_bulk && isset($academic_year_id) && isset($term_id)) {
    $next_term_begins = getNextTermOpeningDate($pdo, $academic_year_id, $term_id);
}

// Format address
$formatted_address = '';
if (!empty($school_info['address'])) {
    $formatted_address = nl2br(htmlspecialchars($school_info['address']));
} else {
    $formatted_address = 'P.O BOX 149<br>Lorikitae Cell, Lorengecorwa Ward Napak Town Council';
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Bulk Report Card Printing - School Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        /* Main Interface Styles */
        :root {
            --primary: #1a2a6c;
            --secondary: #b21f1f;
            --sidebar-bg: #2c3e50;
            --sidebar-hover: #34495e;
            --text-light: #ecf0f1;
            --body-bg: #f8f9fa;
            --card-bg: #ffffff;
            --text-dark: #212529;
            --border-color: #dee2e6;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
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
        }

        body {
            display: flex;
            background-color: var(--body-bg);
            color: var(--text-dark);
            height: 100vh;
            line-height: 1.1;
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
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: var(--warning);
            color: #212529;
        }

        .btn-warning:hover {
            background: #e0a800;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #138496;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background: #5a6268;
        }

        /* Alert Messages */
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

        .alert-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }

        .alert-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
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

        /* Loading Spinner */
        .loading-spinner {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            z-index: 1000;
            background: rgba(255, 255, 255, 0.8);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            text-align: center;
        }

        .spinner {
            border: 4px solid rgba(0, 0, 0, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        /* Preview Card */
        .preview-card {
            background: white;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
        }

        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #f0f4ff;
        }

        .preview-header h3 {
            color: var(--primary);
            font-size: 1.3rem;
        }

        .student-count-badge {
            background: var(--primary);
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        /* Print Container */
        .print-container {
            background: white;
            margin: 0;
            padding: 0;
        }

        /* Report Card Styles - A4 Portrait */
        .report-card {
            width: 100%;
            max-width: 8.27in;
            margin: 0 auto;
            background: white;
            border: 2px solid #000;
            padding: 0.15in;
            font-family: 'Century Gothic', sans-serif;
            box-sizing: border-box;
        }

        .report-card-page {
            page-break-inside: avoid;
            break-inside: avoid;
        }

        .report-card-page:not(:last-child) {
            page-break-after: always;
            break-after: page;
        }

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
            margin-right: 0.8in;
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

        .result-section {
            margin: 0.1in 0;
            font-weight: bold;
            font-size: 12pt;
        }

        /* Comments section styling from table.html */
        .comments-table {
            width: 100%;
            border-collapse: collapse;
            border: 1px solid #000;
            table-layout: fixed;
            margin: 0.1in 0;
        }

        .comments-table td {
            padding: 2px 5px;
            font-size: 12px;
        }

        .comments-left-cell {
            width: 33%;
            font-weight: bold;
            vertical-align: middle;
            text-align: left;
            border-bottom: 1px solid #000;
            border-right: 1px solid #000;
        }

        .comment-row-cell {
            display: flex;
            align-items: center;
            height: 30px;
            vertical-align: middle;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;


        }

        .signature-row-cell {
            height: 25px;
            text-align: right;
            font-weight: bold;
            vertical-align: bottom;
            border-bottom: 1px solid #000;


        }

        .signature-line {
            display: inline-block;
            width: 50%;
            border-bottom: 1px dotted #000;
            margin-left: 8px;
        }

        .remarks-section {
            margin: 0.1in 0;
            width: 100%;
        }

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
            padding: 0.06in 0.04in;
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
            font-size: 8pt;
        }

        .points-table td {
            border: 1px solid #000;
            padding: 0.04in 0.05in;
            text-align: center;
            width: 33.33%;
            font-size: 10pt;
            font-weight: bold;
        }

        .invalid-stamp {
            text-align: center;
            margin-top: 0.1in;
            font-size: 8.5pt;
            font-style: italic;
            color: #000;
        }

        @media print {

            .sidebar,
            .header,
            .form-section,
            .preview-header,
            .btn,
            .alert,
            .info-box,
            .loading-spinner {
                display: none !important;
            }

            body {
                background: white;
                width: 7.27in;
                margin: 0 auto;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            .main-wrapper,
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
                overflow: visible !important;
            }

            .print-container {
                margin: 0;
                padding: 0;
                background: white;
            }

            .report-card {
                border: 2px solid #000;
                box-shadow: none;
                page-break-inside: avoid;
                break-inside: avoid;
                border: 6px double #004080;
            }

            .report-card-page:not(:last-child) {
                page-break-after: always;
                break-after: page;
            }

            .report-card-page:first-child {
                page-break-before: auto;
            }
        }

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

            .main-wrapper {
                margin-left: 70px;
                width: calc(100% - 70px);
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
        }

        /* Logo placeholder */
        .logo-placeholder {
            width: 0.8in;
            height: 0.8in;
            border: 1px solid #000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9pt;
        }

        /* Utility classes */
        .no-print {
            page-break-inside: avoid;
        }

        .text-center {
            text-align: center;
        }

        .font-bold {
            font-weight: bold;
        }

        .mt-1 {
            margin-top: 0.1in;
        }

        .mb-1 {
            margin-bottom: 0.1in;
        }
    </style>
</head>

<body>
    <!-- Loading Spinner -->
    <div class="loading-spinner" id="loadingSpinner">
        <div class="spinner"></div>
        <p>Loading student data...</p>
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
                <a href="master_marksheet.php" class="nav-link">
                    <i class="fas fa-table"></i>
                    <span>Master Marksheets</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="print_single_report_card.php" class="nav-link">
                    <i class="fas fa-print"></i>
                    <span>Print Single Report</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="bulk_report_card_print.php" class="nav-link active">
                    <i class="fas fa-print"></i>
                    <span>Bulk Report Print</span>
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
                <h1>Bulk Report Card Printing</h1>
                <p><?= $fullname ?> | <?= $email ?></p>
            </div>
            <div class="role-tag"><?= $is_admin ? 'Admin' : 'Class Teacher' ?></div>
        </header>

        <main class="main-content">
            <!-- Selection Form -->
            <div class="form-section no-print">
                <h3>Generate Bulk Report Cards</h3>
                <div class="info-box">
                    <p><i class="fas fa-info-circle"></i> Select criteria to generate report cards for all students in the selected class and stream. Each student will appear on a separate page.</p>
                </div>

                <form method="POST" id="bulkReportForm" action="bulk_report_card_print.php">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="academic_year">Academic Year *</label>
                            <select class="form-control" id="academic_year" name="academic_year" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= $year['id'] ?>" <?= ($selected_values['academic_year'] == $year['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="level">Level *</label>
                            <select class="form-control" id="level" name="level" required>
                                <option value="">Select Level</option>
                                <option value="1" <?= ($selected_values['level'] == 1) ? 'selected' : '' ?>>O Level</option>
                                <option value="2" <?= ($selected_values['level'] == 2) ? 'selected' : '' ?>>A Level</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="term">Term *</label>
                            <select class="form-control" id="term" name="term" required>
                                <option value="">Select Academic Year First</option>
                                <?php if (!empty($term_info) && isset($term_info['term_name'])): ?>
                                    <option value="<?= $term_info['id'] ?>" selected>
                                        <?= htmlspecialchars($term_info['term_name']) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="class">Class *</label>
                            <select class="form-control" id="class" name="class" required>
                                <option value="">Select Level First</option>
                                <?php if (!empty($class_info) && isset($class_info['name'])): ?>
                                    <option value="<?= $class_info['id'] ?>" selected>
                                        <?= htmlspecialchars($class_info['name']) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="stream">Stream *</label>
                            <select class="form-control" id="stream" name="stream" required>
                                <option value="">Select Class First</option>
                                <?php if (!empty($stream_info) && isset($stream_info['name'])): ?>
                                    <option value="<?= $stream_info['id'] ?>" selected>
                                        <?= htmlspecialchars($stream_info['name']) ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>&nbsp;</label>
                            <div class="btn-group" style="display: flex; gap: 0.5rem;">
                                <button type="submit" name="generate_bulk" class="btn btn-primary" id="generateBtn" style="flex: 1;">
                                    <i class="fas fa-file-alt"></i> Generate Reports
                                </button>
                                <button type="button" id="checkAvailability" class="btn btn-info" style="flex: 0 0 auto;">
                                    <i class="fas fa-search"></i> Check
                                </button>
                            </div>
                        </div>
                    </div>
                </form>

                <!-- Student Count Preview -->
                <div id="studentPreview" style="display: none; margin-top: 1rem; padding: 1rem; background: #f8f9fa; border-radius: 4px;">
                    <div id="previewContent"></div>
                </div>

                <?php if (isset($error_message)): ?>
                    <div class="alert alert-error" style="margin-top: 1rem;">
                        <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error_message) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($warning_message)): ?>
                    <div class="alert alert-warning" style="margin-top: 1rem;">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($warning_message) ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($has_data && !empty($students_data)): ?>
                <!-- Print Controls -->
                <div class="preview-card no-print">
                    <div class="preview-header">
                        <h3>
                            <i class="fas fa-users"></i>
                            <?= htmlspecialchars($class_info['name'] ?? '') ?> <?= htmlspecialchars($stream_info['name'] ?? '') ?> -
                            <?= htmlspecialchars($term_info['term_name'] ?? '') ?> -
                            <?= htmlspecialchars($academic_year_info['year_name'] ?? '') ?>
                        </h3>
                        <div class="student-count-badge">
                            <i class="fas fa-user-graduate"></i> <?= count($students_data) ?> Students
                        </div>
                    </div>

                    <div class="btn-group" style="display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem;">
                        <button onclick="window.print()" class="btn btn-success">
                            <i class="fas fa-print"></i> Print All Report Cards
                        </button>
                        <a href="bulk_report_card_print.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Selection
                        </a>
                    </div>
                </div>

                <!-- PRINT CONTAINER - All report cards -->
                <div class="print-container">
                    <?php foreach ($students_data as $index => $student_data):
                        $student = $student_data['student'];
                        $student_marks = $student_data['marks'];
                        $average = $student_data['average'];
                        $result = $student_data['result'];
                        $principal_count = $student_data['principal_count'] ?? 0;
                        $subsidiary_count = $student_data['subsidiary_count'] ?? 0;
                        $total_points = $student_data['total_points'] ?? 0;
                        $all_grades = $student_data['all_grades'] ?? [];
                        $head_teacher_comment = $student_data['head_teacher_comment'] ?? '';
                        $class_teacher_comment = $student_data['class_teacher_comment'] ?? '';

                        // Calculate grade counts for O-Level
                        $grade_counts = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
                        foreach ($all_grades as $grade) {
                            if (isset($grade_counts[$grade])) {
                                $grade_counts[$grade]++;
                            }
                        }
                    ?>
                        <!-- Each student in its own page container -->
                        <div class="report-card-page">
                            <div class="report-card">
                                <!-- School Header -->
                                <div class="school-header">
                                    <div class="school-logo-container">
                                        <?php if (!empty($school_info['school_logo'])): ?>
                                            <img src="<?= htmlspecialchars($school_info['school_logo']) ?>" alt="School Logo" class="school-logo">
                                        <?php else: ?>
                                            <div class="logo-placeholder">
                                                <span>Logo</span>
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
                                    <?= ($level_id == 1) ? 'O-LEVEL REPORT CARD' : 'ADVANCED LEVEL REPORT CARD' ?>
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
                                        <span class="info-value"><?= htmlspecialchars($class_info['name'] ?? '') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">STREAM:</span>
                                        <span class="info-value"><?= htmlspecialchars($stream_info['name'] ?? '') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">TERM:</span>
                                        <span class="info-value"><?= htmlspecialchars($term_info['term_name'] ?? 'Term I') ?></span>
                                    </div>
                                    <div class="info-item">
                                        <span class="info-label">YEAR:</span>
                                        <span class="info-value"><?= htmlspecialchars($student['year_name'] ?? '') ?></span>
                                    </div>
                                </div>

                                <!-- Continuous Assessment & Summative Assessment Title -->
                                <div class="text-center font-bold" style="margin: 0.05in 0; font-size: 10pt;">
                                    CONTINUOUS ASSESSMENT & SUMMATIVE ASSESSMENT
                                </div>

                                <?php if ($level_id == 1): ?>
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
                                            <?php foreach ($student_marks as $subject_data): ?>
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

                                    <!-- RESULT -->
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
                                            <?php foreach ($student_marks as $subject_data): ?>
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

                                    <!-- A-Level Points Summary -->
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
                                                <td><?= $total_points ?></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                <?php endif; ?>

                                <!-- Comments Section - Table format from table.html -->
                                <table class="comments-table">
                                    <!-- CLASS TEACHER SECTION -->
                                    <tr>
                                        <td class="comments-left-cell" rowspan="2">
                                            CLASS TEACHER’S COMMENT
                                        </td>
                                        <td class="comment-row-cell">
                                            <?= htmlspecialchars($class_teacher_comment ?: '') ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="signature-row-cell">
                                            SIGNATURE <span class="signature-line"></span>
                                        </td>
                                    </tr>

                                    <!-- HEAD TEACHER SECTION -->
                                    <tr>
                                        <td class="comments-left-cell" rowspan="2">
                                            HEAD TEACHER’S COMMENT
                                        </td>
                                        <td class="comment-row-cell">
                                            <?= htmlspecialchars($head_teacher_comment ?: '') ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td class="signature-row-cell">
                                            SIGNATURE <span class="signature-line"></span>
                                        </td>
                                    </tr>
                                </table>

                                <!-- Remarks Section -->
                                <div class="remarks-section">
                                    <!-- Grading Scale -->
                                    <div class="grading-scale">
                                        <?php if ($level_id == 1): ?>
                                            <!-- O-Level Grading Scale -->
                                            <table>
                                                <thead>
                                                    <tr>
                                                        <td class="no-top-left-border"></td>
                                                        <td colspan="6"><strong>GRADING SCALE</strong></td>
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
                                            <!-- A-Level Grading Scale - Combined for Principal (A-F) and Subsidiary (D1-F9) -->
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
                                            <div class="footer-cell"><span class="footer-label">OUTSTANDING FEES</span></div>
                                            <div class="footer-cell"><span class="footer-label">NEXT TERM FEES</span></div>
                                            <div class="footer-cell"><span class="footer-label">DATE OF ISSUE</span></div>
                                            <div class="footer-cell"><span class="footer-label">NEXT TERM BEGINS</span></div>
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
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        $(document).ready(function() {
            // Show loading spinner on form submit
            $('#bulkReportForm').on('submit', function() {
                $('#loadingSpinner').show();
            });

            // Store user role for AJAX
            const isAdmin = <?= $is_admin ? 'true' : 'false' ?>;
            const teacherId = '<?= $teacher_id ?? '' ?>';

            // Load terms when academic year changes
            $('#academic_year').on('change', function() {
                const academicYearId = $(this).val();
                const termSelect = $('#term');

                if (!academicYearId) {
                    termSelect.html('<option value="">Select Academic Year First</option>');
                    return;
                }

                termSelect.html('<option value="">Loading...</option>');

                $.ajax({
                    url: 'bulk_report_card_print.php?ajax=1',
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
                                options += `<option value="${term.id}">${term.term_name}</option>`;
                            });
                            termSelect.html(options);
                        } else {
                            termSelect.html('<option value="">No terms found</option>');
                        }
                    },
                    error: function() {
                        termSelect.html('<option value="">Error loading terms</option>');
                    }
                });
            });

            // Load classes based on level
            $('#level').on('change', function() {
                const levelId = $(this).val();
                const academicYearId = $('#academic_year').val();
                const classSelect = $('#class');
                const streamSelect = $('#stream');
                const studentPreview = $('#studentPreview');

                if (!levelId || !academicYearId) {
                    classSelect.html('<option value="">Select Level and Academic Year First</option>');
                    return;
                }

                classSelect.html('<option value="">Loading...</option>');
                streamSelect.html('<option value="">Select Class First</option>');
                studentPreview.hide();

                $.ajax({
                    url: 'bulk_report_card_print.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_classes',
                        level: levelId,
                        academic_year: academicYearId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.classes.length > 0) {
                            let options = '<option value="">Select Class</option>';
                            $.each(data.classes, function(index, cls) {
                                options += `<option value="${cls.id}">${cls.name}</option>`;
                            });
                            classSelect.html(options);
                        } else {
                            classSelect.html('<option value="">No classes found</option>');
                        }
                    },
                    error: function() {
                        classSelect.html('<option value="">Error loading classes</option>');
                    }
                });
            });

            // Load streams based on class
            $('#class').on('change', function() {
                const classId = $(this).val();
                const streamSelect = $('#stream');
                const studentPreview = $('#studentPreview');

                if (!classId) {
                    streamSelect.html('<option value="">Select Class First</option>');
                    return;
                }

                streamSelect.html('<option value="">Loading...</option>');
                studentPreview.hide();

                $.ajax({
                    url: 'bulk_report_card_print.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_streams',
                        class: classId
                    },
                    dataType: 'json',
                    success: function(data) {
                        if (data.success && data.streams.length > 0) {
                            let options = '<option value="">Select Stream</option>';
                            $.each(data.streams, function(index, stream) {
                                options += `<option value="${stream.id}">${stream.name}</option>`;
                            });
                            streamSelect.html(options);
                        } else {
                            streamSelect.html('<option value="">No streams found</option>');
                        }
                    },
                    error: function() {
                        streamSelect.html('<option value="">Error loading streams</option>');
                    }
                });
            });

            // Check availability button
            $('#checkAvailability').on('click', function() {
                const academicYearId = $('#academic_year').val();
                const levelId = $('#level').val();
                const termId = $('#term').val();
                const classId = $('#class').val();
                const streamId = $('#stream').val();

                if (!academicYearId || !levelId || !termId || !classId || !streamId) {
                    alert('Please select all fields first');
                    return;
                }

                $('#loadingSpinner').show();
                $('#studentPreview').hide();

                $.ajax({
                    url: 'bulk_report_card_print.php?ajax=1',
                    type: 'GET',
                    data: {
                        action: 'get_student_count',
                        academic_year: academicYearId,
                        level: levelId,
                        term: termId,
                        class: classId,
                        stream: streamId
                    },
                    dataType: 'json',
                    success: function(data) {
                        $('#loadingSpinner').hide();

                        if (data.success) {
                            // Get selected names for display
                            const academicYear = $('#academic_year option:selected').text();
                            const level = $('#level option:selected').text();
                            const term = $('#term option:selected').text();
                            const className = $('#class option:selected').text();
                            const streamName = $('#stream option:selected').text();

                            let html = '<h4 style="margin-bottom: 0.5rem;">Preview Summary</h4>';
                            html += '<table style="width: 100%; border-collapse: collapse;">';
                            html += '<tr><td style="padding: 5px;"><strong>Academic Year:</strong></td><td>' + academicYear + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Level:</strong></td><td>' + level + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Term:</strong></td><td>' + term + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Class:</strong></td><td>' + className + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Stream:</strong></td><td>' + streamName + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Students Found:</strong></td><td>' + data.student_count + '</td></tr>';
                            html += '<tr><td style="padding: 5px;"><strong>Students with Marks:</strong></td><td>' + data.marks_count + '</td></tr>';

                            if (!data.has_marks) {
                                html += '<tr><td colspan="2" style="color: #856404; padding-top: 10px;">';
                                html += '<i class="fas fa-exclamation-triangle"></i> Warning: No marks found for this term.';
                                html += '</td></tr>';
                            }

                            html += '</table>';

                            $('#previewContent').html(html);
                            $('#studentPreview').show();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    },
                    error: function() {
                        $('#loadingSpinner').hide();
                        alert('Error checking availability');
                    }
                });
            });

            // Preserve selected values after page reload
            <?php if (!empty($selected_values['academic_year']) && !empty($selected_values['level'])): ?>
                // Trigger change events to load dependent dropdowns
                setTimeout(function() {
                    if ($('#academic_year').val()) {
                        $('#academic_year').trigger('change');

                        setTimeout(function() {
                            if ($('#level').val()) {
                                $('#level').trigger('change');

                                setTimeout(function() {
                                    if ($('#class').val() && $('#class option:selected').text() !== 'Select Class') {
                                        $('#class').trigger('change');
                                    }
                                }, 500);
                            }
                        }, 500);
                    }
                }, 100);
            <?php endif; ?>
        });
    </script>
</body>

</html>
<?php
// Helper function for ordinal suffixes
function getOrdinalSuffix($num)
{
    if ($num % 100 >= 11 && $num % 100 <= 13) return 'th';
    switch ($num % 10) {
        case 1:
            return 'st';
        case 2:
            return 'nd';
        case 3:
            return 'rd';
        default:
            return 'th';
    }
}
?>