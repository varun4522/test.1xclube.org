<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

$stmt = $conn->prepare("SELECT requested_at, amount, upi_number, status FROM withdrawal_request WHERE user_id = ? ORDER BY requested_at DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Withdraw History</title>
    <style>body{font-family:Arial;padding:20px;background:#0f0f0f;color:#fff}.card{background:#fff;color:#000;padding:1rem;border-radius:10px;max-width:900px;margin:auto}.link{color:#ffd700;text-decoration:none}</style>
</head>
<body>
    <div class="card"><a class="link" href="../home.php">← Back to Dashboard</a>
        <h2>Withdrawal History</h2>
        <table style="width:100%;border-collapse:collapse">
            <thead><tr><th>Date</th><th>Amount</th><th>UPI</th><th>Status</th></tr></thead>
            <tbody>
                <?php while($row = $res->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['requested_at']); ?></td>
                    <td>₹<?php echo number_format($row['amount'],2); ?></td>
                    <td><?php echo htmlspecialchars($row['upi_number']); ?></td>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</body>
</html>