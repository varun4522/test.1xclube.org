<?php
// Backfill script: create a `user_wallet` row with zero balances for every profile.
// Run from project root: `php scripts/backfill_wallets.php`
require_once __DIR__ . '/../config.php';

// Only allow CLI execution to avoid web exposure
if (php_sapi_name() !== 'cli') {
    echo "This script must be run from the command line.\n";
    exit(1);
}

$conn = getDBConnection();

$res = $conn->query("SELECT id FROM profiles");
if (!$res) {
    echo "Failed to fetch profiles: " . $conn->error . "\n";
    exit(1);
}

$total = 0;
$created = 0;
$skipped = 0;

while ($row = $res->fetch_assoc()) {
    $total++;
    $userId = (int)$row['id'];

    // Try helper first
    $ok = false;
    if (function_exists('ensureUserWalletExists')) {
        $ok = @ensureUserWalletExists($conn, $userId);
    }

    if (!$ok) {
        // Fallback: attempt INSERT IGNORE minimal
        $stmt = $conn->prepare("INSERT IGNORE INTO user_wallet (user_id, current_balance) VALUES (?, 0.00)");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $created += ($stmt->affected_rows > 0) ? 1 : 0;
            } else {
                // ignore individual failures but log
                echo "Failed to insert wallet for user {$userId}: " . $stmt->error . "\n";
            }
            $stmt->close();
        } else {
            echo "No compatible user_wallet schema or insufficient privileges for user {$userId}.\n";
            $skipped++;
        }
    } else {
        // helper returned true — ensure row exists (it may have been existing)
        // Check if current_balance row exists
        $stmt = $conn->prepare("SELECT current_balance FROM user_wallet WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $stmt->store_result();
            if ($stmt->num_rows === 0) {
                // no row created by helper? try insert
                $stmt->close();
                $stmt2 = $conn->prepare("INSERT IGNORE INTO user_wallet (user_id, current_balance) VALUES (?, 0.00)");
                if ($stmt2) { $stmt2->bind_param('i', $userId); $stmt2->execute(); if ($stmt2->affected_rows>0) $created++; $stmt2->close(); }
            } else {
                $stmt->close();
                $skipped++;
            }
        } else {
            $skipped++;
        }
    }
}

echo "Profiles scanned: {$total}\n";
echo "Wallets created: {$created}\n";
echo "Wallets already present/skipped: {$skipped}\n";

$conn->close();

exit(0);
