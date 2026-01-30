<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

 $stmt = $conn->prepare("SELECT current_balance FROM profiles WHERE id = ?");
 $stmt->bind_param("i", $userId);
 $stmt->execute();
 $profile = $stmt->get_result()->fetch_assoc();
 $stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recharge - Wallet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{ --bg:#0f0f0f; --accent:#ffd700; --muted:#64748b }
        html,body{height:100%;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',system-ui,sans-serif;background:var(--bg);color:#fff}
        .page{max-width:920px;margin:0 auto;padding:1rem}
        .topnav{display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem}
        .link{color:var(--accent);text-decoration:none;font-weight:600}

        .grid{display:grid;grid-template-columns:1fr;gap:1rem}
        @media(min-width:900px){.grid{grid-template-columns:1fr 360px}}

        .card{background:linear-gradient(180deg,rgba(255,255,255,0.02),rgba(255,255,255,0.01));border:1px solid rgba(255,255,255,0.04);padding:1rem;border-radius:12px;box-shadow:0 6px 24px rgba(2,6,23,0.6)}
        .page-title{font-weight:700;color:var(--accent);font-size:1.1rem}
        .muted{color:var(--muted);font-size:0.95rem}

        .form-grid{display:grid;grid-template-columns:1fr;gap:0.75rem}
        @media(min-width:680px){.form-grid{grid-template-columns:1fr 1fr}}
        label{display:block;color:var(--muted);margin-bottom:.35rem}
        input[type=number],input[type=text]{width:100%;padding:.65rem;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.02);color:#fff}

        .actions{display:flex;gap:.5rem;margin-top:.6rem}
        .btn{padding:.6rem .9rem;border-radius:8px;border:none;cursor:pointer;font-weight:700}
        .btn-primary{background:var(--accent);color:#000}
        .btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.06);color:#fff}

        .balance-card{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:1rem}
        .balance-amount{font-size:1.5rem;font-weight:800;color:var(--accent)}
        .balance-label{color:var(--muted);font-size:.9rem}

        .history-scroll{max-height:320px;overflow:auto;border-radius:8px;padding-right:8px}
        .history-table{width:100%;border-collapse:collapse}
        .history-table th,.history-table td{padding:.6rem .5rem;text-align:left;border-bottom:1px solid rgba(255,255,255,0.03);font-size:.95rem}
        .history-table th{color:var(--muted);font-weight:700;font-size:.85rem}

        @media(max-width:520px){.page{padding:.5rem}}
    </style>
</head>
<body>
    <div class="page">
        <div class="topnav"><a href="../home.php" class="link">← Back to Dashboard</a><span class="muted"></span></div>

        <div class="grid">
            <section class="card">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.5rem">
                    <div>
                        <div class="page-title">Wallet</div>
                        <div class="muted">Welcome</div>
                    </div>
                    <div class="muted">Manage your wallet</div>
                </div>

                <form id="rechargeForm">
                    <div class="form-grid">
                        <div>
                            <label for="rechargeAmount">Amount (Min ₹100)</label>
                            <input type="number" id="rechargeAmount" min="100" placeholder="e.g. 1500" required>
                        </div>
                    </div>
                    <div class="actions">
                        <button class="btn btn-primary" type="submit">Proceed to Pay</button>
                        <button type="button" class="btn btn-ghost" onclick="window.location.href='Withdraw.php'">Withdraw</button>
                    </div>

                    <div style="margin-top:1rem">
                        <h3 style="margin:.75rem 0">Recent Recharges</h3>
                        <div class="history-scroll">
                        <table class="history-table">
                            <thead><tr><th>Date</th><th>Amount</th><th>Status</th></tr></thead>
                            <tbody>
                    <?php
                    $stmt = $conn->prepare("SELECT created_at, transaction_type, amount, status FROM transaction_history WHERE user_id = ? AND transaction_type = 'deposit' ORDER BY created_at DESC LIMIT 10");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    while ($row = $res->fetch_assoc()) {
                        echo '<tr><td>'.htmlspecialchars($row['created_at']).'</td><td>₹'.number_format($row['amount'],2).'</td><td>'.htmlspecialchars($row['status']).'</td></tr>';
                    }
                    $stmt->close();
                    ?>
                            </tbody>
                        </table>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="card balance-card">
                <div class="balance-amount">₹<?php echo number_format($profile['current_balance'] ?? 0,2); ?></div>
                <div class="balance-label">Available Balance</div>
                <div style="width:100%;margin-top:.6rem"><button class="btn btn-primary" style="width:100%" onclick="document.getElementById('rechargeAmount').focus()">Add Funds</button></div>
            </aside>
        </div>
    </div>

<script>
document.getElementById('rechargeForm').addEventListener('submit', async function(e){
    e.preventDefault();

    const amount = document.getElementById('rechargeAmount').value;
    if (!amount || amount < 100) {
        alert('Minimum ₹100');
        return;
    }

    try {
        const fd = new FormData();
        fd.append('amount', amount);

        const res = await fetch('../api/add_money.php', {
            method: 'POST',
            body: fd
        });

        const data = await res.json();

        if (!data.success) {
            alert(data.message || 'Payment failed');
            return;
        }

        /* 🔥 SUNPAY PAYMENT PAGE – NEW TAB */
        window.open(data.payUrl, '_blank');

    } catch (err) {
        console.error(err);
        alert('Something went wrong');
    }
});
</script>


</body>
</html>