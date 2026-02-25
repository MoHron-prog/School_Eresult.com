<?php
/**
 * get_streams.php
 * AJAX endpoint to load streams based on class
 */

require_once 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;

if (!$class_id) {
    echo json_encode(['streams' => []]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM streams 
        WHERE class_id = ? AND status = 'active' 
        ORDER BY name
    ");
    $stmt->execute([$class_id]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['streams' => $streams]);
} catch (PDOException $e) {
    error_log("Error fetching streams: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}