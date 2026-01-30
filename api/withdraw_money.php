<?php
// Prevent PHP warnings/HTML from being emitted in API responses
ini_set('display_errors', '0');
error_reporting(0);

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$amount = floatval($_POST['amount'] ?? 0);
$upiHolderName = trim($_POST['upi_holder_name'] ?? '');
$upiNumber = trim($_POST['upi_number'] ?? '');

if ($amount < 100) {
    echo json_encode(['success' => false, 'message' => 'Minimum withdrawal amount is ₹100']);
    exit();
}

if (empty($upiHolderName) || empty($upiNumber)) {
    echo json_encode(['success' => false, 'message' => 'Please provide UPI details']);
    exit();
}

try {
    $conn = getDBConnection();
    
    // Check user balance from profiles
    $stmt = $conn->prepare("SELECT current_balance FROM profiles WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("User not found");
    }
    
    $walletRow = $result->fetch_assoc();
    $currentBalance = $walletRow['current_balance'];
    
    if ($currentBalance < $amount) {
        echo json_encode(['success' => false, 'message' => 'Insufficient balance. Your balance: ₹' . number_format($currentBalance, 2)]);
        exit();
    }
    
    $conn->begin_transaction();
    
    // Deduct amount from profiles.current_balance and increment total_withdrawn
    $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance - ?, total_withdrawn = total_withdrawn + ? WHERE id = ?");
    $stmt->bind_param("ddi", $amount, $amount, $userId);
    $stmt->execute();
    
    // Insert withdrawal request
    $stmt = $conn->prepare("INSERT INTO withdrawal_request (user_id, amount, upi_holder_name, upi_number, status, requested_at) 
                           VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("idss", $userId, $amount, $upiHolderName, $upiNumber);
    $stmt->execute();
    
    // Add transaction history
    $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, created_at) 
                           VALUES (?, 'withdrawal', ?, 'pending', NOW())");
    if (!$stmt) throw new Exception('Prepare failed (transaction_history insert): ' . $conn->error);
    $stmt->bind_param("id", $userId, $amount);
    $stmt->execute();
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Withdrawal request submitted successfully. Admin will process it soon.',
        'newBalance' => $currentBalance - $amount
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    error_log("Withdrawal error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Failed to process withdrawal. Please try again.']);
}
?>
