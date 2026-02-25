<?php
require_once 'config.php';
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit;
}

$academic_year_id = isset($_GET['academic_year_id']) ? intval($_GET['academic_year_id']) : 0;

if (!$academic_year_id) {
    echo json_encode([]);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT id, term_name 
        FROM academic_terms 
        WHERE academic_year_id = ?
        ORDER BY id
    ");
    $stmt->execute([$academic_year_id]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($terms);
} catch (Exception $e) {
    error_log("Error fetching terms: " . $e->getMessage());
    echo json_encode([]);
}
?>