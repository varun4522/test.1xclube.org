<?php
// Process matured investments: credit returns to user balances
// Run from project root: php scripts/process_mature_investments.php

require_once __DIR__ . '/../config.php';

// Only allow CLI execution
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$conn = getDBConnection();

// Find active investments that have matured
$sql = "SELECT id, user_id, amount, roi_percentage FROM investments WHERE status = 'active' AND maturity_date <= NOW()";
$res = $conn->query($sql);

if (!$res) {
    echo "Query error: " . $conn->error . "\n";
    exit(1);
}

$processed = 0;
while ($row = $res->fetch_assoc()) {
    $invId = (int)$row['id'];
    $userId = (int)$row['user_id'];
    $amount = (float)$row['amount'];
    $roi = (float)$row['roi_percentage'];
    $returnAmount = round($amount * (1 + $roi / 100), 2);

    try {
        $conn->begin_transaction();

        // Credit user's balance
        $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance + ? WHERE id = ?");
        if (!$stmt) throw new Exception('Prepare failed (profiles update): ' . $conn->error);
        $stmt->bind_param('di', $returnAmount, $userId);
        if (!$stmt->execute()) throw new Exception('Execute failed (profiles update): ' . $stmt->error);

        // Mark investment as completed
        $stmt = $conn->prepare("UPDATE investments SET status = 'completed' WHERE id = ?");
        if (!$stmt) throw new Exception('Prepare failed (investments update): ' . $conn->error);
        $stmt->bind_param('i', $invId);
        if (!$stmt->execute()) throw new Exception('Execute failed (investments update): ' . $stmt->error);

        // Insert transaction history for credit
        $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, created_at) VALUES (?, 'investment_return', ?, 'completed', NOW())");
        if (!$stmt) throw new Exception('Prepare failed (transaction_history insert): ' . $conn->error);
        $stmt->bind_param('id', $userId, $returnAmount);
        if (!$stmt->execute()) throw new Exception('Execute failed (transaction_history insert): ' . $stmt->error);

        $conn->commit();
        $processed++;
        echo "Processed investment #{$invId} for user {$userId}: credited ₹" . number_format($returnAmount,2) . "\n";
    } catch (Exception $e) {
        $conn->rollback();
        echo "Failed to process investment #{$invId}: " . $e->getMessage() . "\n";
    }
}

if ($processed === 0) {
    echo "No matured investments to process.\n";
}

$conn->close();

?>
