<?php
/**
 * get_classes.php
 * AJAX endpoint to load classes based on level
 */

require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$level_id = isset($_GET['level_id']) ? (int)$_GET['level_id'] : 0;

if (!$level_id) {
    echo json_encode(['classes' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM classes 
        WHERE level_id = ? AND status = 'active' 
        ORDER BY name
    ");
    $stmt->execute([$level_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['classes' => $classes]);
} catch (PDOException $e) {
    error_log("Error fetching classes: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}