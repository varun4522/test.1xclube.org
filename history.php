<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Fetch basic profile for header (no longer display name)
$stmt = $conn->prepare("SELECT id FROM profiles WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch transactions
$stmt = $conn->prepare("SELECT id, amount, transaction_type, status, created_at FROM transaction_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 200");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
$transactions = [];
while ($row = $res->fetch_assoc()) { $transactions[] = $row; }
$stmt->close();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Transaction History</title>
    <style>
        :root{--bg:#0f0f0f;--accent:#ffd700;--muted:#94a3b8}
        html,body{height:100%;overflow-x:hidden;overflow-y:hidden;touch-action:pan-y;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:#fff}

        /* compact fixed header */
        .topnav{position:fixed;top:0;left:0;right:0;z-index:1100;display:flex;align-items:center;justify-content:space-between;padding:.5rem .75rem;background:rgba(8,8,8,0.96);border-bottom:1px solid rgba(255,215,0,0.05);height:56px}
        .topnav .title{font-weight:700;color:var(--accent);font-size:1rem}
        .topnav .sub{color:var(--muted);font-size:.85rem}
        .link{color:var(--accent);text-decoration:none;font-weight:600}

        /* page container sits under header and above tabbar */
        .page{max-width:920px;margin:0 auto;padding:0 0 1rem;padding-top:56px;box-sizing:border-box;height:calc(100vh - 56px);overflow:hidden}

        .card{background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));border:1px solid rgba(255,255,255,0.04);padding:1rem;border-radius:12px;margin:0.3rem 0 0.6rem 0;box-shadow:0 6px 24px rgba(2,6,23,0.6)}

        /* scrollable history area */
        .history-scroll{max-height:calc(100% - 120px);height:100%;overflow:auto;-webkit-overflow-scrolling:touch;padding-right:8px;-ms-overflow-style:none;scrollbar-width:none}
        .history-scroll::-webkit-scrollbar{display:none;height:0;width:0}

        table{width:100%;border-collapse:collapse}
        th,td{padding:.6rem;border-bottom:1px solid rgba(255,255,255,0.03);text-align:left;font-size:.95rem}
        thead th{color:var(--muted);font-weight:700;font-size:.85rem}

        /* Responsive: small screens convert rows to stacked cards */
        @media(max-width:720px){
            table thead{display:none}
            table, tbody, tr, td{display:block;width:99%}
            tr{margin:0 0 .6rem;border-radius:8px;background:rgba(255,255,255,0.02);padding:.6rem}
            td{display:flex;justify-content:space-between;padding:.45rem 0;border-bottom:0}
            td:before{content:attr(data-label);color:var(--muted);font-weight:600;margin-right:.75rem}
        }
    </style>
</head>
<body>
    <div class="topnav"><a href="home.php" class="link">←</a><div><div class="title">Transaction History</div></div><div></div></div>
    <div class="page">
        <div class="card">
            <p class="muted" style="margin:0 0 .75rem 0">Showing recent activity for your account.</p>
            <div class="history-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr><td colspan="4">No transactions found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td data-label="Date"><?php echo htmlspecialchars($t['created_at']); ?></td>
                                    <td data-label="Type"><?php echo htmlspecialchars($t['transaction_type']); ?></td>
                                    <td data-label="Amount">₹<?php echo number_format($t['amount'],2); ?></td>
                                    <td data-label="Status"><?php echo htmlspecialchars($t['status']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>