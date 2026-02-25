<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['academic_year_id']) || empty($_GET['academic_year_id'])) {
    echo json_encode(['success' => false, 'message' => 'Academic Year ID required']);
    exit;
}

$academic_year_id = intval($_GET['academic_year_id']);

try {
    $stmt = $pdo->prepare("
        SELECT id, term_name 
        FROM academic_terms 
        WHERE academic_year_id = ? 
        AND status = 'active'
        ORDER BY opening_date
    ");
    $stmt->execute([$academic_year_id]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $terms
    ]);
} catch (Exception $e) {
    error_log("Error fetching terms: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading terms'
    ]);
}
?>