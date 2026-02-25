<?php
require_once 'config.php';

session_start();

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Validate teacher ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid teacher ID']);
    exit;
}

$teacher_id = (int)$_GET['id'];

try {
    // Fetch teacher basic information
    $stmt = $pdo->prepare("
        SELECT id, teacher_id, username, email, fullname, gender, position, status
        FROM users 
        WHERE id = ? AND role = 'teacher'
    ");
    $stmt->execute([$teacher_id]);
    $teacher = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$teacher) {
        throw new Exception("Teacher not found");
    }

    // Fetch teacher's assigned subjects
    $stmt = $pdo->prepare("
        SELECT ts.subject_id 
        FROM teacher_subjects ts
        WHERE ts.teacher_id = ?
    ");
    $stmt->execute([$teacher_id]);
    $subject_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Fetch teacher's assigned classes
    $stmt = $pdo->prepare("
        SELECT tc.class_id 
        FROM teacher_classes tc
        WHERE tc.teacher_id = ? AND tc.academic_year_id = 1
    ");
    $stmt->execute([$teacher_id]);
    $class_ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

    // Prepare response data
    $response = [
        'success' => true,
        'teacher' => [
            'id' => $teacher['id'],
            'teacher_id' => $teacher['teacher_id'],
            'username' => $teacher['username'],
            'email' => $teacher['email'],
            'fullname' => $teacher['fullname'],
            'gender' => $teacher['gender'],
            'position' => $teacher['position'],
            'status' => $teacher['status']
        ],
        'subject_ids' => $subject_ids,
        'class_ids' => $class_ids
    ];

    echo json_encode($response);
} catch (Exception $e) {
    error_log("Error fetching teacher data: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load teacher data: ' . $e->getMessage()
    ]);
}
