<?php
session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Get user profile for header (include current balance)
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
    <title>Withdraw - Wallet</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root{
            --bg: #0f0f0f;
            --muted: #64748b;
            --accent: #ffd700;
            --card-bg: #0b0b0b;
            --surface: #0f1724;
        }
        html,body{height:100%;margin:0;padding:0;font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;background:var(--bg);color:#fff}
        .page { padding: 1.25rem; max-width: 920px; margin: 0 auto; }
        .topnav { display:flex; justify-content:space-between; align-items:center; gap:1rem; margin-bottom:1rem; }
        .link { color:var(--accent); text-decoration:none; font-weight:600 }

        .grid { display:grid; grid-template-columns: 1fr; gap:1rem; }
        @media(min-width:900px){ .grid{ grid-template-columns: 1fr 410px; } }

        .card { background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02)); border:1px solid rgba(255,255,255,0.04); padding:1.25rem; border-radius:12px; box-shadow: 0 6px 24px rgba(2,6,23,0.6); }

        .wallet-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:0.75rem }
        .page-title{font-size:1.15rem;font-weight:700;color:var(--accent)}
        .muted{color:var(--muted);font-size:0.95rem}

        form { display:block }
        .form-grid { display:grid; grid-template-columns: 1fr; gap:0.75rem; }
        @media(min-width:680px){ .form-grid{ grid-template-columns: 1fr 1fr; gap:0.75rem } }
        label{display:block;color:var(--muted);font-size:0.9rem;margin-bottom:0.35rem}
        input[type='text'], input[type='number']{width:100%;padding:0.65rem;border-radius:8px;border:1px solid rgba(255,255,255,0.06);background:rgba(255,255,255,0.02);color:#fff;font-size:1rem}

        .actions { display:flex; gap:0.5rem; margin-top:0.75rem }
        .btn { padding: 0.6rem 0.9rem; border-radius:8px; cursor:pointer; border:none; font-weight:700 }
        .btn-ghost{ background:transparent;border:1px solid rgba(255,255,255,0.06); color:#fff }
        .btn-primary{ background:var(--accent); color:#000 }

        /* right column: quick actions / balance */
        .balance-card { display:flex; flex-direction:column; gap:0.5rem; align-items:center; justify-content:center; padding:1rem }
        .balance-amount{ font-size:1.6rem; font-weight:800; color:var(--accent) }
        .balance-label{ color:var(--muted); font-size:0.9rem }

        /* history table */
        .history-wrap{ margin-top:1rem }
        .history-table{ width:100%; border-collapse:collapse; background:transparent }
        .history-table th, .history-table td{ padding:0.6rem 0.5rem; text-align:left; border-bottom:1px solid rgba(255,255,255,0.03); font-size:0.95rem }
        .history-table th{ color:var(--muted); font-weight:700; font-size:0.85rem }
        .history-scroll{ max-height:320px; overflow:auto; border-radius:8px; padding-right:8px }

        /* small screens tweaks */
        @media(max-width:520px){ .page{ padding:0.75rem } .grid{ grid-template-columns: 1fr } }
    </style>
</head>
<body>
    <div class="page">
        <div class="topnav"><a href="../home.php" class="link">← Back to Dashboard</a><span class="muted"><?php echo htmlspecialchars($profile['name']); ?></span></div>

        <div class="grid">
            <section class="card">
                <div class="wallet-header">
                    <div>
                        <div class="page-title">Request Withdrawal</div>
                        <div class="muted">Enter amount and UPI details to request a payout</div>
                    </div>
                    <div class="muted">Min withdrawal ₹100</div>
                </div>

                <form id="withdrawPageForm">
                    <div class="form-grid">
                        <div>
                            <label for="wAmount">Amount (₹)</label>
                            <input type="number" id="wAmount" min="100" placeholder="e.g. 1500" required>
                        </div>

                        <div>
                            <label for="wName">Account Holder Name</label>
                            <input type="text" id="wName" placeholder="Your name" required>
                        </div>

                        <div style="grid-column:1 / -1">
                            <label for="wUpi">UPI ID</label>
                            <input type="text" id="wUpi" placeholder="name@bank" required>
                        </div>
                    </div>

                    <div class="actions">
                        <button class="btn btn-primary" type="submit">Request Withdrawal</button>
                        <button type="button" class="btn btn-ghost" onclick="window.location.href='Recharge.php'">Open Recharge</button>
                    </div>
                </form>
            </section>

            <aside class="card balance-card">
                <div class="balance-amount">₹<?php echo number_format($profile['current_balance'] ?? 0,2); ?></div>
                <div class="balance-label">Available Balance</div>
                <div style="width:100%; margin-top:0.75rem"><button class="btn btn-primary" style="width:100%" onclick="window.location.href='Recharge.php'">Add Funds</button></div>
            </aside>
        </div>

        <section class="card history-wrap">
            <h3 style="margin:0 0 0.75rem 0">Your Withdrawal Requests</h3>
            <div class="history-scroll">
            <table class="history-table">
                <thead><tr><th>Date</th><th>Amount</th><th>UPI</th><th>Status</th></tr></thead>
                <tbody>
                <?php
                // Try primary table name first; if prepare fails (e.g., table missing due to legacy typo), try fallback
                $sqlPrimary = "SELECT requested_at, amount, upi_number, status FROM withdrawal_request WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20";
                $stmt = $conn->prepare($sqlPrimary);
                if (!$stmt) {
                    // fallback to legacy misspelled table name
                    $sqlFallback = "SELECT requested_at, amount, upi_number, status FROM withdrawl_request WHERE user_id = ? ORDER BY requested_at DESC LIMIT 20";
                    $stmt = $conn->prepare($sqlFallback);
                }

                if ($stmt) {
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $res = $stmt->get_result();
                    if ($res) {
                        while ($r = $res->fetch_assoc()) {
                            echo '<tr><td>'.htmlspecialchars($r['requested_at']).'</td><td>₹'.number_format($r['amount'],2).'</td><td>'.htmlspecialchars($r['upi_number']).'</td><td>'.htmlspecialchars($r['status']).'</td></tr>';
                        }
                    } else {
                        error_log("Withdraw.php: get_result failed: " . $conn->error);
                        echo '<tr><td colspan="4">Unable to load withdrawal requests at the moment.</td></tr>';
                    }
                    $stmt->close();
                } else {
                    error_log("Withdraw.php: prepare failed: " . $conn->error);
                    echo '<tr><td colspan="4">No withdrawal history available.</td></tr>';
                }
                ?>
                </tbody>
            </table>
            </div>
        </section>
    </div>

<script>
document.getElementById('withdrawPageForm').addEventListener('submit', async function(e){
    e.preventDefault();
    const amount = document.getElementById('wAmount').value;
    const name = document.getElementById('wName').value;
    const upi = document.getElementById('wUpi').value;
    if (!amount || amount < 100) return alert('Enter amount >= 100');

    if (!confirm(`Withdraw ₹${amount} to UPI ${upi}?`)) return;

    try {
        const fd = new FormData();
        fd.append('amount', amount);
        fd.append('upi_holder_name', name);
        fd.append('upi_number', upi);
        const res = await fetch('../api/withdraw_money.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (!json.success) return alert(json.message || 'Withdraw request failed');
        alert(json.message);
        location.reload();
    } catch (err) { console.error(err); alert('Something went wrong.'); }
});

// Top tab toggles: navigate between Recharge and Withdraw pages
const tabRechargeBtn = document.getElementById('tabRecharge');
const tabWithdrawBtn = document.getElementById('tabWithdraw');
if (tabRechargeBtn) tabRechargeBtn.addEventListener('click', ()=> window.location.href = 'Recharge.php');
if (tabWithdrawBtn) tabWithdrawBtn.classList.add('btn-primary');
</script>
</body>
</html>