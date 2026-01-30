<?php
// config.php - self contained (no upward require_once)
define('DB_HOST', 'localhost');
define('DB_USER', 'invest');
define('DB_PASS', 'invest');
define('DB_NAME', 'invest');

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die('DB Connection Failed: ' . $conn->connect_error);
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function generateUniqueReferralCode(mysqli $conn): string
{
    $letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $digits  = '0123456789';

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = '';
        for ($i = 0; $i < 4; $i++) {
            $code .= $letters[random_int(0, 25)];
        }
        for ($i = 0; $i < 4; $i++) {
            $code .= $digits[random_int(0, 9)];
        }

        $stmt = $conn->prepare(
            "SELECT id FROM profiles WHERE referral_code = ?"
        );
        $stmt->bind_param("s", $code);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $stmt->close();
            return $code;
        }
        $stmt->close();
    }

    throw new Exception("Unable to generate unique referral code");
}

function generateUnique4DigitId(mysqli $conn): int
{
    // Generate unique 4-digit ID (1000-9999)
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $id = random_int(1000, 9999);

        $stmt = $conn->prepare("SELECT id FROM profiles WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows === 0) {
            $stmt->close();
            return $id;
        }
        $stmt->close();
    }

    throw new Exception("Unable to generate unique 4-digit ID");
}
/**
 * Note: The legacy `user_wallet` table has been removed from project usage.
 * Balances are stored on the `profiles` table using these columns:
 *  - current_balance
 *  - total_invested
 *  - total_earned
 *  - total_withdrawn
 * Use these fields instead of `user_wallet` going forward.
 */
