<?php
require_once '../config.php';
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['teacher', 'admin'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

try {
    $stmt = $pdo->query("
        SELECT id, year_name 
        FROM academic_years 
        WHERE status = 'active' 
        ORDER BY start_year DESC
    ");
    $academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $academic_years
    ]);
} catch (Exception $e) {
    error_log("Error fetching academic years: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error loading academic years'
    ]);
}
?>