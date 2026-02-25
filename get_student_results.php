<?php
// get_student_results.php
require_once 'config.php';

// Check if it's an AJAX request
if (!isset($_GET['ajax'])) {
    die("Invalid request.");
}

// Get parameters
$student_id = (int)($_GET['student_id'] ?? 0);
$ay = (int)($_GET['academic_year_id'] ?? 0);
$term = (int)($_GET['term_id'] ?? 0);
$class = (int)($_GET['class_id'] ?? 0);
$stream = (int)($_GET['stream_id'] ?? 0);
$subject = (int)($_GET['subject_id'] ?? 0);
$exam_type = $_GET['exam_type'] ?? '';

// Validate parameters
if (!$student_id || !$ay || !$term || !$class || !$stream || !$subject || !in_array($exam_type, ['Aol', 'Proj', 'EoC'])) {
    die("Invalid parameters.");
}

// Fetch student details
$stmt = $pdo->prepare("
    SELECT s.student_id, s.registration_number, s.surname, s.other_names, 
           s.date_of_birth, s.gender, c.name AS class_name, st.name AS stream_name
    FROM students s
    JOIN classes c ON s.class_id = c.id
    JOIN streams st ON s.stream_id = st.id
    WHERE s.student_id = ?
");
$stmt->execute([$student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student not found.");
}

// Fetch marks for the selected criteria
$sql = "
    SELECT 
        om.*,
        sub.name AS subject_name,
        ay.year_name AS academic_year,
        t.term_name,
        u.fullname AS entered_by
    FROM o_level_marks om
    JOIN subjects sub ON om.subject_id = sub.id
    JOIN academic_years ay ON om.academic_year_id = ay.id
    JOIN academic_terms t ON om.term_id = t.id
    JOIN users u ON om.entered_by = u.id
    WHERE om.student_id = ?
      AND om.academic_year_id = ?
      AND om.term_id = ?
      AND om.class_id = ?
      AND om.stream_id = ?
      AND om.subject_id = ?
      AND om.exam_type = ?
";

$stmt = $pdo->prepare($sql);
$stmt->execute([$student_id, $ay, $term, $class, $stream, $subject, $exam_type]);
$marks = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$marks) {
    echo '<div class="alert alert-warning">No marks found for this student.</div>';
    exit;
}

// Calculate totals and grades
$total = 0;
$grade = 'N/A';
$remark = 'N/A';

if ($exam_type === 'Aol') {
    $a1 = (float)($marks['a1'] ?? 0);
    $a2 = (float)($marks['a2'] ?? 0);
    $a3 = (float)($marks['a3'] ?? 0);
    $a4 = (float)($marks['a4'] ?? 0);
    $a5 = (float)($marks['a5'] ?? 0);
    $total = $a1 + $a2 + $a3 + $a4 + $a5;
    $percentage = ($total / 100) * 100;
    list($grade, $remark) = calculateGrade($total);
} elseif ($exam_type === 'Proj') {
    $total = (float)($marks['project_score'] ?? 0);
    $percentage = ($total / 40) * 100;
    list($grade, $remark) = calculateGrade($total, 40);
} elseif ($exam_type === 'EoC') {
    $total = (float)($marks['eoc_score'] ?? 0);
    $percentage = ($total / 100) * 100;
    list($grade, $remark) = calculateGrade($total);
}
?>

<div class="student-results">
    <!-- Student Information -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Student Information</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-4">
                    <p><strong>Name:</strong> <?= htmlspecialchars($student['surname'] . ' ' . $student['other_names']) ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Registration No:</strong> <?= htmlspecialchars($student['registration_number']) ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Class:</strong> <?= htmlspecialchars($student['class_name'] . ' ' . $student['stream_name']) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Marks Information -->
    <div class="card mb-4">
        <div class="card-header bg-light">
            <h5 class="mb-0">Marks Details</h5>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-3">
                    <strong>Subject:</strong> <?= htmlspecialchars($marks['subject_name']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Academic Year:</strong> <?= htmlspecialchars($marks['academic_year']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Term:</strong> <?= htmlspecialchars($marks['term_name']) ?>
                </div>
                <div class="col-md-3">
                    <strong>Exam Type:</strong> <?= htmlspecialchars($exam_type) ?>
                </div>
            </div>

            <?php if ($exam_type === 'Aol'): ?>
                <table class="table table-bordered">
                    <thead class="table-secondary">
                        <tr>
                            <th>A1</th>
                            <th>A2</th>
                            <th>A3</th>
                            <th>A4</th>
                            <th>A5</th>
                            <th>Total</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= $marks['a1'] ?? 0 ?></td>
                            <td><?= $marks['a2'] ?? 0 ?></td>
                            <td><?= $marks['a3'] ?? 0 ?></td>
                            <td><?= $marks['a4'] ?? 0 ?></td>
                            <td><?= $marks['a5'] ?? 0 ?></td>
                            <td><strong><?= $total ?></strong></td>
                            <td><?= round($percentage, 2) ?>%</td>
                            <td><span class="badge bg-primary"><?= $grade ?></span></td>
                            <td><?= $remark ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php elseif ($exam_type === 'Proj'): ?>
                <table class="table table-bordered">
                    <thead class="table-secondary">
                        <tr>
                            <th>Project Score</th>
                            <th>Out of</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?= $total ?></strong></td>
                            <td>40</td>
                            <td><?= round($percentage, 2) ?>%</td>
                            <td><span class="badge bg-primary"><?= $grade ?></span></td>
                            <td><?= $remark ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php elseif ($exam_type === 'EoC'): ?>
                <table class="table table-bordered">
                    <thead class="table-secondary">
                        <tr>
                            <th>End of Cycle Score</th>
                            <th>Out of</th>
                            <th>Percentage</th>
                            <th>Grade</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong><?= $total ?></strong></td>
                            <td>100</td>
                            <td><?= round($percentage, 2) ?>%</td>
                            <td><span class="badge bg-primary"><?= $grade ?></span></td>
                            <td><?= $remark ?></td>
                        </tr>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Additional Information -->
    <div class="card">
        <div class="card-header bg-light">
            <h5 class="mb-0">Additional Information</h5>
        </div>
        <div class="card-body">
            <p><strong>Entered By:</strong> <?= htmlspecialchars($marks['entered_by']) ?></p>
            <p><strong>Last Updated:</strong> <?= date('F j, Y g:i A', strtotime($marks['updated_at'])) ?></p>
            <p><strong>Date Entered:</strong> <?= date('F j, Y', strtotime($marks['created_at'])) ?></p>
        </div>
    </div>
</div>

<?php
// Helper function
function calculateGrade($score, $maxScore = 100) {
    $percentage = ($score / $maxScore) * 100;
    
    if ($percentage >= 90) return ['A', 'Excellent'];
    if ($percentage >= 80) return ['B', 'Very Good'];
    if ($percentage >= 70) return ['C', 'Good'];
    if ($percentage >= 60) return ['D', 'Satisfactory'];
    if ($percentage >= 50) return ['E', 'Pass'];
    return ['F', 'Fail'];
}
?>