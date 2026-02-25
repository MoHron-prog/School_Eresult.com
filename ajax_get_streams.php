<?php
require_once 'config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;

if (!$class_id) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM streams 
        WHERE class_id = ? 
        AND status = 'active'
        ORDER BY name
    ");
    $stmt->execute([$class_id]);
    $streams = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($streams);
} catch (Exception $e) {
    error_log("Error fetching streams: " . $e->getMessage());
    echo json_encode([]);
}
?>