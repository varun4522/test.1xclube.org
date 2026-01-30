<?php
// Ensure API always returns JSON even on PHP errors
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);
register_shutdown_function(function(){
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        header('Content-Type: application/json');
        $msg = isset($err['message']) ? $err['message'] : 'Fatal error';
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $msg]);
        exit();
    }
});

session_start();
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$userId = $_SESSION['user_id'];
$planNum = intval($_POST['plan_num'] ?? 0);
$amount = floatval($_POST['amount'] ?? 0);

// Define investment plans (same as frontend)
$plans = [
    1 => ['amount' => 1000, 'return' => 1400, 'hours' => 1],
    2 => ['amount' => 3000, 'return' => 4000, 'hours' => 3],
    3 => ['amount' => 5000, 'return' => 6200, 'hours' => 10],
    4 => ['amount' => 8000, 'return' => 9800, 'hours' => 18],
    5 => ['amount' => 10000, 'return' => 12000, 'hours' => 24],
    6 => ['amount' => 15000, 'return' => 18000, 'hours' => 32],
    7 => ['amount' => 25000, 'return' => 30000, 'hours' => 40],
    8 => ['amount' => 43000, 'return' => 50000, 'hours' => 45],
    9 => ['amount' => 51000, 'return' => 60000, 'hours' => 50],
    10 => ['amount' => 64000, 'return' => 76000, 'hours' => 55],
    11 => ['amount' => 81500, 'return' => 94000, 'hours' => 62],
    12 => ['amount' => 95000, 'return' => 110000, 'hours' => 70],
    13 => ['amount' => 125000, 'return' => 150000, 'hours' => 80]
];

if (!isset($plans[$planNum])) {
    echo json_encode(['success' => false, 'message' => 'Invalid investment plan']);
    exit();
}

$plan = $plans[$planNum];

if ($amount != $plan['amount']) {
    echo json_encode(['success' => false, 'message' => 'Invalid amount for this plan']);
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
    
    $wallet = $result->fetch_assoc();
    $currentBalance = $wallet['current_balance'];
    
    // Prevent duplicate active investments for the same plan
    $planTypeCheck = 'plan_' . $planNum;
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM investments WHERE user_id = ? AND plan_type = ? AND status = 'active'");
    if (!$stmt) throw new Exception('Prepare failed (duplicate check): ' . $conn->error);
    $stmt->bind_param('is', $userId, $planTypeCheck);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res->fetch_assoc();
    if (!empty($row['cnt']) && (int)$row['cnt'] > 0) {
        echo json_encode(['success' => false, 'code' => 'DUPLICATE_PLAN', 'message' => 'You already have an active investment in this plan']);
        exit();
    }

    if ($currentBalance < $amount) {
        echo json_encode([
            'success' => false,
            'message' => 'Insufficient balance. Your balance: ₹' . number_format($currentBalance, 2)
        ]);
        exit();
    }
    
    $conn->begin_transaction();
    
    // Deduct amount from profiles.current_balance and increment total_invested
    $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance - ?, total_invested = total_invested + ? WHERE id = ?");
    $stmt->bind_param("ddi", $amount, $amount, $userId);
    $stmt->execute();
    
    // Calculate maturity date
    $maturityDate = date('Y-m-d H:i:s', strtotime("+{$plan['hours']} hours"));
    
    // Create investment
    $roi = (($plan['return'] - $plan['amount']) / $plan['amount']) * 100;
    $stmt = $conn->prepare("INSERT INTO investments (user_id, amount, plan_type, status, roi_percentage, maturity_date, created_at) 
                           VALUES (?, ?, ?, 'active', ?, ?, NOW())");
    $planType = 'plan_' . $planNum;
    $stmt->bind_param("idsds", $userId, $amount, $planType, $roi, $maturityDate);
    $stmt->execute();
    
    // Add transaction history
    $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, created_at) 
                           VALUES (?, 'investment', ?, 'completed', NOW())");
    if (!$stmt) throw new Exception('Prepare failed (transaction_history insert): ' . $conn->error);
    $stmt->bind_param("id", $userId, $amount);
    $stmt->execute();
    
    $conn->commit();
    
    $returnAmount = $plan['return'];
    
    echo json_encode([
        'success' => true,
        'message' => "Investment successful! You will receive ₹" . number_format($returnAmount, 2) . " after {$plan['hours']} hours",
        'newBalance' => $currentBalance - $amount,
        'returnAmount' => $returnAmount,
        'plan_hours' => $plan['hours'],
        'plan_num' => $planNum
    ]);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollback();
    error_log("Investment error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
