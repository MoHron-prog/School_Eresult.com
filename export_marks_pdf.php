<?php
require_once 'config.php';
require_once 'vendor/autoload.php'; // Load TCPDF


if (!isset($_SESSION['user_id'])) {
    die('Unauthorized access.');
}

// Validate inputs
$level = $_GET['level'] ?? '';
$subject_id = (int)($_GET['subject_id'] ?? 0);
$term_id = (int)($_GET['term_id'] ?? 0);
$exam_type = $_GET['exam_type'] ?? '';
$class_id = (int)($_GET['class_id'] ?? 0);
$stream_id = !empty($_GET['stream_id']) ? (int)$_GET['stream_id'] : null;

if (!in_array($level, ['O', 'A']) || !$subject_id || !$term_id || !in_array($exam_type, ['Aol', 'Proj', 'EoC'])) {
    die('Invalid parameters.');
}

// Determine table and level name
$table = $level === 'A' ? 'student_marks_alevel' : 'student_marks_olevel';
$level_name = $level === 'A' ? 'A Level' : 'O Level';

try {
    // Build query
    $conditions = "sm.subject_id = ? AND sm.term_id = ? AND sm.exam_type = ?";
    $params = [$subject_id, $term_id, $exam_type];

    if ($class_id) {
        $conditions .= " AND s.class_id = ?";
        $params[] = $class_id;
    }
    if ($stream_id) {
        $conditions .= " AND s.stream_id = ?";
        $params[] = $stream_id;
    }

    $sql = "
        SELECT 
            s.student_id,
            s.surname,
            s.other_names,
            sub.name AS subject_name,
            t.term_name,
            sm.a1, sm.a2, sm.a3, sm.a4, sm.a5,
            sm.project_score,
            sm.eoc_score
        FROM $table sm
        JOIN students s ON sm.student_id = s.student_id
        JOIN subjects sub ON sm.subject_id = sub.id
        JOIN academic_terms t ON sm.term_id = t.id
        WHERE $conditions
        ORDER BY s.surname, s.other_names
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $marks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($marks)) {
        die('No marks found for the selected criteria.');
    }

    $subject_name = htmlspecialchars($marks[0]['subject_name']);
    $term_name = htmlspecialchars($marks[0]['term_name']);

    // Initialize TCPDF
    $pdf = new tcpdf('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('School Management System');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle("$level_name Marks Sheet");
    $pdf->SetSubject("$subject_name - $term_name");
    $pdf->SetKeywords("$level_name, marks, $subject_name");

    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(10, 10, 10, true);
    $pdf->SetAutoPageBreak(TRUE, 10);

    $pdf->AddPage();

    // School Header
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'NAMUGONGO SECONDARY SCHOOL', 0, 1, 'C');
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 6, "$level_name MARKS SHEET", 0, 1, 'C');
    $pdf->Ln(5);

    // Info line
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, "Subject: $subject_name | Term: $term_name | Exam Type: $exam_type", 0, 1, 'C');
    $pdf->Ln(5);

    // Table setup
    $pdf->SetFont('helvetica', 'B', 9);
    $cols = ['S/N', 'Student ID', 'Student Name'];
    $col_widths = [10, 25, 45];

    if ($exam_type === 'Aol') {
        $cols = array_merge($cols, ['A1', 'A2', 'A3', 'A4', 'A5', 'Total']);
        $col_widths = array_merge($col_widths, [12, 12, 12, 12, 12, 15]);
    } elseif ($exam_type === 'Proj') {
        $cols[] = 'Project Score';
        $col_widths[] = 25;
    } elseif ($exam_type === 'EoC') {
        $cols[] = 'End of Cycle';
        $col_widths[] = 25;
    }

    // Table header
    $pdf->SetFillColor(26, 42, 108);
    $pdf->SetTextColor(255);
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.2);
    $header_height = 7;
    foreach ($cols as $i => $col) {
        $pdf->Cell($col_widths[$i], $header_height, $col, 1, 0, 'C', true);
    }
    $pdf->Ln();

    // Table data
    $pdf->SetTextColor(0);
    $pdf->SetFont('helvetica', '', 9);
    foreach ($marks as $index => $m) {
        $pdf->Cell(10, 6, $index + 1, 1, 0, 'C');
        $pdf->Cell(25, 6, $m['student_id'], 1, 0, 'L');
        $pdf->Cell(45, 6, $m['surname'] . ' ' . $m['other_names'], 1, 0, 'L');

        if ($exam_type === 'Aol') {
            $a1 = $m['a1'] !== null ? number_format($m['a1'], 1) : '-';
            $a2 = $m['a2'] !== null ? number_format($m['a2'], 1) : '-';
            $a3 = $m['a3'] !== null ? number_format($m['a3'], 1) : '-';
            $a4 = $m['a4'] !== null ? number_format($m['a4'], 1) : '-';
            $a5 = $m['a5'] !== null ? number_format($m['a5'], 1) : '-';
            $total = ($m['a1'] + $m['a2'] + $m['a3'] + $m['a4'] + $m['a5']);
            $total = $m['a1'] !== null ? number_format($total, 1) : '-';
            $pdf->Cell(12, 6, $a1, 1, 0, 'C');
            $pdf->Cell(12, 6, $a2, 1, 0, 'C');
            $pdf->Cell(12, 6, $a3, 1, 0, 'C');
            $pdf->Cell(12, 6, $a4, 1, 0, 'C');
            $pdf->Cell(12, 6, $a5, 1, 0, 'C');
            $pdf->Cell(15, 6, $total, 1, 0, 'C');
        } elseif ($exam_type === 'Proj') {
            $score = $m['project_score'] !== null ? number_format($m['project_score'], 1) : '-';
            $pdf->Cell(25, 6, $score, 1, 0, 'C');
        } elseif ($exam_type === 'EoC') {
            $score = $m['eoc_score'] !== null ? number_format($m['eoc_score'], 1) : '-';
            $pdf->Cell(25, 6, $score, 1, 0, 'C');
        }
        $pdf->Ln();
    }

    // Output
    $filename = "$level" . "_Level_Marks_" . preg_replace('/[^a-zA-Z0-9]/', '_', $subject_name) . ".pdf";
    $pdf->Output($filename, 'D');
} catch (Exception $e) {
    error_log("PDF Export Error: " . $e->getMessage());
    die('Error generating PDF: ' . $e->getMessage());
}
