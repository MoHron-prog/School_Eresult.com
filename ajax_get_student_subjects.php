<?php
require_once 'config.php';

$student_id = $_GET['student_id'] ?? 0;

$stmt = $pdo->prepare("SELECT subject_id FROM student_subjects WHERE student_id = ?");
$stmt->execute([$student_id]);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($subjects);
?>