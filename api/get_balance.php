<?php
session_start();
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
try {
    $conn = getDBConnection();
    $stmt = $conn->prepare("SELECT current_balance FROM profiles WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res->num_rows === 0) {
        echo json_encode(['success' => true, 'current_balance' => 0.00]);
        exit();
    }
    $row = $res->fetch_assoc();
    echo json_encode(['success' => true, 'current_balance' => floatval($row['current_balance'])]);
} catch (Exception $e) {
    error_log('get_balance error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error']);
}

exit();
