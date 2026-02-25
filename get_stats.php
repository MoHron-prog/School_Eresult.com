<?php
require_once 'config.php';

function safeCount($pdo, $table, $condition = "1=1") {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $condition");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

header('Content-Type: application/json');

echo json_encode([
    'teachers' => safeCount($pdo, 'users', "role = 'teacher' AND status = 'active'"),
    'students' => safeCount($pdo, 'users', "role = 'student' AND status = 'active'"),
    'classes'  => safeCount($pdo, 'classes'),
    'streams'  => safeCount($pdo, 'streams'),
    'levels'   => safeCount($pdo, 'levels'),
    'subjects' => safeCount($pdo, 'subjects')
]);
?>