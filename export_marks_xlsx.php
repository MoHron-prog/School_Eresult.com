<?php
// export_marks_xlsx.php
require_once 'config.php';
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

// Check authentication
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'teacher'])) {
    header("Location: index.php");
    exit;
}

// Get parameters
$ay = (int)($_GET['academic_year_id'] ?? 0);
$term = (int)($_GET['term_id'] ?? 0);
$class = (int)($_GET['class_id'] ?? 0);
$stream = (int)($_GET['stream_id'] ?? 0);
$subject = (int)($_GET['subject_id'] ?? 0);
$exam_type = $_GET['exam_type'] ?? '';

// Validate required parameters
if (!$ay || !$term || !$class || !$stream || !$subject || !in_array($exam_type, ['Aol', 'Proj', 'EoC'])) {
    die("Invalid parameters.");
}

// Fetch school information
$stmt = $pdo->query("SELECT school_name, school_logo, address, motto FROM school_info LIMIT 1");
$school = $stmt->fetch(PDO::FETCH_ASSOC);
$school_name = $school['school_name'] ?? 'School Name';

// Fetch academic year, term, class, stream, subject details
$stmt = $pdo->prepare("SELECT year_name FROM academic_years WHERE id = ?");
$stmt->execute([$ay]);
$academic_year = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT term_name FROM academic_terms WHERE id = ?");
$stmt->execute([$term]);
$term_name = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT name FROM classes WHERE id = ?");
$stmt->execute([$class]);
$class_name = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT name FROM streams WHERE id = ?");
$stmt->execute([$stream]);
$stream_name = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT name FROM subjects WHERE id = ?");
$stmt->execute([$subject]);
$subject_name = $stmt->fetchColumn();

// Build query with role restrictions
$sql = "
    SELECT 
        s.student_id,
        s.registration_number,
        s.surname,
        s.other_names,
        sub.name AS subject_name,
        om.a1, om.a2, om.a3, om.a4, om.a5,
        om.project_score,
        om.eoc_score,
        u.fullname AS entered_by_name,
        om.updated_at
    FROM o_level_marks om
    JOIN students s ON om.student_id = s.student_id
    JOIN subjects sub ON om.subject_id = sub.id
    JOIN users u ON om.entered_by = u.id
    WHERE om.academic_year_id = ? 
      AND om.term_id = ?
      AND om.class_id = ?
      AND om.stream_id = ?
      AND om.subject_id = ?
      AND om.exam_type = ?
    ORDER BY s.surname, s.other_names
";

// Apply role restrictions for non-admin
if ($_SESSION['role'] !== 'admin') {
    $user_id = $_SESSION['user_id'];
    $allowed_class_ids = [];
    $allowed_subject_ids = [];

    $stmt = $pdo->prepare("
        SELECT DISTINCT 
            COALESCE(ta.class_id, tc.class_id) as class_id,
            ta.subject_id
        FROM teacher_assignments ta
        LEFT JOIN teacher_classes tc ON ta.teacher_id = tc.teacher_id 
            AND ta.academic_year_id = tc.academic_year_id
        WHERE ta.teacher_id = ? AND ta.status = 'active'
          AND (ta.assignment_type = 'Subject Teacher' OR ta.assignment_type = 'Class Teacher')
    ");
    $stmt->execute([$user_id]);
    $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $allowed_class_ids = array_unique(array_filter(array_column($perms, 'class_id')));
    $allowed_subject_ids = array_unique(array_filter(array_column($perms, 'subject_id')));

    if (!in_array($class, $allowed_class_ids) || !in_array($subject, $allowed_subject_ids)) {
        die("Access denied.");
    }
}

// Execute query
$stmt = $pdo->prepare($sql);
$stmt->execute([$ay, $term, $class, $stream, $subject, $exam_type]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create new Spreadsheet object
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set document properties
$spreadsheet->getProperties()
    ->setCreator($school_name)
    ->setTitle("O-Level Marks - {$subject_name}")
    ->setDescription("Exported marks for {$subject_name}");

// Set headers and school information
$sheet->setCellValue('A1', strtoupper($school_name));
$sheet->mergeCells('A1:F1');
$sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

$sheet->setCellValue('A2', 'O-LEVEL MARKS REPORT');
$sheet->mergeCells('A2:F2');
$sheet->getStyle('A2')->getFont()->setBold(true)->setSize(14);

$sheet->setCellValue('A3', "Subject: {$subject_name}");
$sheet->setCellValue('A4', "Class: {$class_name} - {$stream_name}");
$sheet->setCellValue('A5', "Term: {$term_name}, Academic Year: {$academic_year}");
$sheet->setCellValue('A6', "Exam Type: " . $exam_type . " (" . getExamTypeFullName($exam_type) . ")");
$sheet->setCellValue('A7', "Export Date: " . date('F j, Y'));

// Set column headers
$headers = ['S/N', 'Reg. No', 'Student Name', 'A1', 'A2', 'A3', 'A4', 'A5', 'Total', 'Grade', 'Remark'];
if ($exam_type === 'Proj') {
    $headers = ['S/N', 'Reg. No', 'Student Name', 'Project Score', 'Grade', 'Remark'];
} elseif ($exam_type === 'EoC') {
    $headers = ['S/N', 'Reg. No', 'Student Name', 'End of Cycle', 'Grade', 'Remark'];
}

$headerRow = 9;
$col = 'A';
foreach ($headers as $header) {
    $sheet->setCellValue($col . $headerRow, $header);
    $col++;
}

// Style headers
$headerStyle = [
    'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
    'fill' => [
        'fillType' => Fill::FILL_SOLID,
        'startColor' => ['rgb' => '1a2a6c']
    ],
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => '000000']
        ]
    ],
    'alignment' => [
        'horizontal' => Alignment::HORIZONTAL_CENTER,
        'vertical' => Alignment::VERTICAL_CENTER
    ]
];
$sheet->getStyle('A' . $headerRow . ':' . chr(65 + count($headers) - 1) . $headerRow)->applyFromArray($headerStyle);

// Add data rows
$row = $headerRow + 1;
$serial = 1;
$total_sum = 0;
$max_score = ($exam_type === 'Aol') ? 100 : (($exam_type === 'Proj') ? 40 : 100);

foreach ($records as $record) {
    $sheet->setCellValue('A' . $row, $serial);
    $sheet->setCellValue('B' . $row, $record['registration_number']);
    $sheet->setCellValue('C' . $row, $record['surname'] . ' ' . $record['other_names']);
    
    if ($exam_type === 'Aol') {
        $a1 = (float)($record['a1'] ?? 0);
        $a2 = (float)($record['a2'] ?? 0);
        $a3 = (float)($record['a3'] ?? 0);
        $a4 = (float)($record['a4'] ?? 0);
        $a5 = (float)($record['a5'] ?? 0);
        $total = $a1 + $a2 + $a3 + $a4 + $a5;
        
        $sheet->setCellValue('D' . $row, $a1);
        $sheet->setCellValue('E' . $row, $a2);
        $sheet->setCellValue('F' . $row, $a3);
        $sheet->setCellValue('G' . $row, $a4);
        $sheet->setCellValue('H' . $row, $a5);
        $sheet->setCellValue('I' . $row, $total);
        
        $grade = calculateGrade($total, $max_score);
        $sheet->setCellValue('J' . $row, $grade['grade']);
        $sheet->setCellValue('K' . $row, $grade['remark']);
        
        $total_sum += $total;
    } elseif ($exam_type === 'Proj') {
        $score = (float)($record['project_score'] ?? 0);
        $sheet->setCellValue('D' . $row, $score);
        
        $grade = calculateGrade($score, $max_score);
        $sheet->setCellValue('E' . $row, $grade['grade']);
        $sheet->setCellValue('F' . $row, $grade['remark']);
        
        $total_sum += $score;
    } elseif ($exam_type === 'EoC') {
        $score = (float)($record['eoc_score'] ?? 0);
        $sheet->setCellValue('D' . $row, $score);
        
        $grade = calculateGrade($score, $max_score);
        $sheet->setCellValue('E' . $row, $grade['grade']);
        $sheet->setCellValue('F' . $row, $grade['remark']);
        
        $total_sum += $score;
    }
    
    $row++;
    $serial++;
}

// Add summary row
$summaryRow = $row + 1;
$sheet->setCellValue('A' . $summaryRow, 'SUMMARY');
$sheet->mergeCells('A' . $summaryRow . ':C' . $summaryRow);
$sheet->getStyle('A' . $summaryRow)->getFont()->setBold(true);

$sheet->setCellValue('D' . $summaryRow, 'Total Students: ' . count($records));
$sheet->setCellValue('E' . $summaryRow, 'Total Score: ' . $total_sum);
$sheet->setCellValue('F' . $summaryRow, 'Average: ' . (count($records) > 0 ? round($total_sum / count($records), 2) : 0));
$sheet->setCellValue('G' . $summaryRow, 'Highest: ' . getHighestScore($records, $exam_type));
$sheet->setCellValue('H' . $summaryRow, 'Lowest: ' . getLowestScore($records, $exam_type));

// Auto-size columns
foreach (range('A', chr(65 + count($headers) - 1)) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

// Add borders to data
$dataStyle = [
    'borders' => [
        'allBorders' => [
            'borderStyle' => Border::BORDER_THIN,
            'color' => ['rgb' => 'CCCCCC']
        ]
    ]
];
$dataStartRow = $headerRow + 1;
$dataEndRow = $row - 1;
$dataEndCol = chr(65 + count($headers) - 1);
$sheet->getStyle('A' . $dataStartRow . ':' . $dataEndCol . $dataEndRow)->applyFromArray($dataStyle);

// Set alignment for numeric columns
$sheet->getStyle('D:' . $dataEndCol)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

// Set output headers
$filename = "O_Level_Marks_{$subject_name}_{$class_name}_{$stream_name}_{$term_name}_{$academic_year}.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Write file to output
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');

exit;

// Helper functions
function getExamTypeFullName($type) {
    $types = [
        'Aol' => 'Activity of Integration',
        'Proj' => 'Project',
        'EoC' => 'End of Cycle'
    ];
    return $types[$type] ?? $type;
}

function calculateGrade($score, $max_score) {
    $percentage = ($score / $max_score) * 100;
    
    if ($percentage >= 90) return ['grade' => 'A', 'remark' => 'Excellent'];
    if ($percentage >= 80) return ['grade' => 'B', 'remark' => 'Very Good'];
    if ($percentage >= 70) return ['grade' => 'C', 'remark' => 'Good'];
    if ($percentage >= 60) return ['grade' => 'D', 'remark' => 'Satisfactory'];
    if ($percentage >= 50) return ['grade' => 'E', 'remark' => 'Pass'];
    return ['grade' => 'F', 'remark' => 'Fail'];
}

function getHighestScore($records, $exam_type) {
    $scores = [];
    foreach ($records as $record) {
        if ($exam_type === 'Aol') {
            $scores[] = ($record['a1'] ?? 0) + ($record['a2'] ?? 0) + ($record['a3'] ?? 0) + 
                       ($record['a4'] ?? 0) + ($record['a5'] ?? 0);
        } elseif ($exam_type === 'Proj') {
            $scores[] = $record['project_score'] ?? 0;
        } elseif ($exam_type === 'EoC') {
            $scores[] = $record['eoc_score'] ?? 0;
        }
    }
    return !empty($scores) ? max($scores) : 0;
}

function getLowestScore($records, $exam_type) {
    $scores = [];
    foreach ($records as $record) {
        if ($exam_type === 'Aol') {
            $scores[] = ($record['a1'] ?? 0) + ($record['a2'] ?? 0) + ($record['a3'] ?? 0) + 
                       ($record['a4'] ?? 0) + ($record['a5'] ?? 0);
        } elseif ($exam_type === 'Proj') {
            $scores[] = $record['project_score'] ?? 0;
        } elseif ($exam_type === 'EoC') {
            $scores[] = $record['eoc_score'] ?? 0;
        }
    }
    return !empty($scores) ? min($scores) : 0;
}
?>