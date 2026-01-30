<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Get user profile and referral code
$stmt = $conn->prepare("SELECT name, referral_code FROM profiles WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$profile = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get referrals count and compute bonus earned
$stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM profiles WHERE referred_by = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$res = $stmt->get_result();
$data = $res->fetch_assoc();
$referralsCount = isset($data['cnt']) ? (int)$data['cnt'] : 0;
$stmt->close();

$bonusEarned = $referralsCount * 50.00;

$refCode = $profile['referral_code'] ?? '';
$refLink = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/signup.php?ref=' . urlencode($refCode);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Your Referral - 1x Club</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* copy of dashboard styles (trimmed to relevant parts) */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 50%, #0f0f0f 100%);
            min-height: 100vh;
            color: #f5f5f5;
            padding-bottom: 80px;
        }
        .dashboard-header {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            padding: 0.5rem 1rem;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 2px solid rgba(255, 215, 0, 0.3);
        }
        .logo { display: flex; align-items: center; gap: 0.4rem; }
        .logo-image { width: 24px; height: 24px; }
        .logo-text { font-size: 1.1rem; font-weight: 700; color: #ffd700; }
        .dashboard-container { max-width: 1400px; margin: 2rem auto; padding: 0 1rem; }
        .card{background:#fff;color:#111;padding:1.25rem;border-radius:12px;max-width:900px;margin:1rem auto}
        .referral-code { font-size: 1.75rem; font-weight: 700; letter-spacing: 3px; margin: 0.75rem 0; }
        .copy-btn { background: rgba(255, 255, 255, 0.2); color: white; border: 2px solid white; padding: 0.5rem 1.75rem; border-radius: 8px; cursor: pointer; font-weight: 600; }
        table{width:100%;border-collapse:collapse;margin-top:1rem}
        th,td{padding:.6rem;border-bottom:1px solid #eee;text-align:left}
        .topnav a { color:#ffd700; text-decoration:none }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="logo">
            <img src="logo.svg" alt="1x Club" class="logo-image">
            <span class="logo-text">1x Club</span>
        </div>
        <div><?php echo htmlspecialchars($profile['name']); ?></div>
    </header>

    <div class="dashboard-container">
        <div style="margin-bottom:1rem;">
            <a href="home.php" class="back-btn" style="background:transparent;color:#ffd700;border:2px solid #ffd700;padding:.5rem .9rem;border-radius:8px;text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">&larr; Back</a>
        </div>
        <div class="card">
            <h2 style="margin:0 0 .5rem 0; color:#111; font-weight:700;">Your Referral Code</h2>
            <div style="margin:.5rem 0 1rem; display:flex; align-items:center; gap:12px;">
                <button class="copy-btn" onclick="copyCode()" aria-label="Copy referral code" style="background:#ffd700;color:#000;border:none;padding:.6rem .9rem;border-radius:8px;">Copy</button>
                <div class="referral-code" id="refCode" style="background:#000;color:#ffd700;padding:.6rem 1rem;border-radius:8px;font-weight:700;"><?php echo htmlspecialchars($refCode); ?></div>
            </div>

            <div style="display:flex; gap:12px; margin-top:1rem; flex-wrap:wrap">
                <div style="flex:1; min-width:200px; background:#000; padding:1rem; border-radius:8px; text-align:center; border:1px solid #ffd700;">
                    <div style="font-size:1.25rem; font-weight:700; color:#ffd700;"><?php echo $referralsCount; ?></div>
                    <div style="color:#ffd700;">Users Joined</div>
                </div>
                <div style="flex:1; min-width:200px; background:#000; padding:1rem; border-radius:8px; text-align:center; border:1px solid #ffd700;">
                    <div style="font-size:1.25rem; font-weight:700; color:#ffd700;">₹<?php echo number_format($bonusEarned,2); ?></div>
                    <div style="color:#ffd700;">Referral Bonus Earned</div>
                </div>
            </div>
        </div>
    </div>

<script>
function copyCode(){
    const el = document.getElementById('refCode');
    const txt = el && el.textContent ? el.textContent.trim() : '';
    if (!txt) { alert('No referral code to copy'); return; }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(() => showCopied()).catch(() => fallbackCopy(txt));
    } else {
        fallbackCopy(txt);
    }
}

function fallbackCopy(text) {
    const ta = document.createElement('textarea');
    ta.value = text;
    // avoid scrolling to bottom
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        showCopied();
    } catch (e) {
        alert('Copy failed — please copy manually: ' + text);
    }
    document.body.removeChild(ta);
}

function showCopied() {
    const btn = document.querySelector('.copy-btn');
    if (!btn) { alert('Copied referral code'); return; }
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.disabled = true;
    setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 1600);
}
</script>
</body>
</html>