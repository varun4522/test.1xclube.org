<?php
session_start();
require_once 'config.php';

// Handle logout request
if (isset($_GET['logout']) && $_GET['logout']) {
    // Unset all session variables
    $_SESSION = [];

    // If session uses cookies, clear the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    // Destroy the session and redirect to login
    session_destroy();
    header('Location: index.php');
    exit();
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$userId = $_SESSION['user_id'];
$conn = getDBConnection();

// Get user profile (using id as primary key, no email)
$stmt = $conn->prepare("SELECT name, phone, referral_code FROM profiles WHERE id = ?");
if (!$stmt) {
    error_log("Profile query error: " . $conn->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $userId);
if (!$stmt->execute()) {
    error_log("Profile execute error: " . $stmt->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$profile = $result->fetch_assoc();
if (!$profile) {
    error_log("Profile not found for user_id: " . $userId);
    die("Profile not found. Please contact support.");
}
$stmt->close();

// Get wallet data
$stmt = $conn->prepare("SELECT current_balance, total_invested FROM profiles WHERE id = ?");
if (!$stmt) {
    error_log("Wallet query error: " . $conn->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $userId);
if (!$stmt->execute()) {
    error_log("Wallet execute error: " . $stmt->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$wallet = $result->fetch_assoc();
if (!$wallet) {
    error_log("Wallet not found for user_id: " . $userId);
    die("Wallet not found. Please contact support.");
}
$stmt->close();

// Get active investments count
$stmt = $conn->prepare("SELECT COUNT(*) as active_count FROM investments WHERE user_id = ? AND status = 'active'");
if (!$stmt) {
    error_log("Active count query error: " . $conn->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $userId);
if (!$stmt->execute()) {
    error_log("Active count execute error: " . $stmt->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$activeCountRow = $result->fetch_assoc();
$activeCount = $activeCountRow['active_count'] ?? 0;
$stmt->close();

// Get total invested (sum of all investments)
$stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) as total_invested FROM investments WHERE user_id = ?");
if (!$stmt) {
    error_log("Total invested query error: " . $conn->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $userId);
if (!$stmt->execute()) {
    error_log("Total invested execute error: " . $stmt->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$totalInvestedRow = $result->fetch_assoc();
$totalInvested = $totalInvestedRow['total_invested'] ?? 0;
$stmt->close();

// Get active investments list
$stmt = $conn->prepare("SELECT plan_type, amount, roi_percentage, status, maturity_date FROM investments WHERE user_id = ? AND status = 'active' ORDER BY created_at DESC");
if (!$stmt) {
    error_log("Investments query error: " . $conn->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($conn->error));
}
$stmt->bind_param("i", $userId);
if (!$stmt->execute()) {
    error_log("Investments execute error: " . $stmt->error);
    die("Database error. Please contact support. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$investments = [];
while ($row = $result->fetch_assoc()) {
    $row['return_amount'] = $row['amount'] + ($row['amount'] * $row['roi_percentage'] / 100);
    $investments[] = $row;
}
$stmt->close();

$conn->close();

// Build a list of active plan numbers for this user so frontend can lock buttons
$activePlanNums = [];
foreach ($investments as $inv) {
    if (isset($inv['plan_type']) && preg_match('/plan_(\d+)/', $inv['plan_type'], $m)) {
        $activePlanNums[] = (int)$m[1];
    }
}
$activePlanNums = array_values(array_unique($activePlanNums));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>1x Club - Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #000000;
            color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            margin: 0;
            padding: 0;
            min-height: 100vh;
        }
        .dashboard-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem;
            z-index: 1000;
            border-bottom: 1px solid rgba(255, 215, 0, 0.3);
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 76px;
            box-sizing: border-box;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .header-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        .wallet-link {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #ffd700;
            text-decoration: none;
            font-weight: 700;
            background: rgba(255, 215, 0, 0.06);
            padding: 0.4rem 0.6rem;
            border-radius: 8px;
            border: 1px solid rgba(255,215,0,0.12);
        }
        .wallet-icon { font-size: 1.05rem; }
        .header-balance { font-size: 0.95rem; color: #ffffff; }
        .logo {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .logo-image {
            width: 40px;
            height: 40px;
        }
        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
            color: #ffd700;
        }
        .user-greeting {
            font-size: 0.875rem;
            color: #9ca3af;
            margin-bottom: 0.25rem;
        }
        .user-name-header {
            font-size: 1.125rem;
            font-weight: 600;
            color: #ffffff;
        }
        .dashboard-container {
            padding: 0 1rem;
            /* reserve space for fixed header and tab bar */
            padding-top: calc(76px + 1rem); /* header height plus extra spacing to avoid overlap */
            padding-bottom: calc(80px + env(safe-area-inset-bottom));
            min-height: calc(100vh - 76px - (56px + env(safe-area-inset-bottom))); /* viewport minus header and tabbar */
            max-height: calc(100vh - 76px - (56px + env(safe-area-inset-bottom)));
            overflow-y: auto; /* allow main content to scroll */
            -webkit-overflow-scrolling: touch;
        }
        .banner-container {
            position: relative;
            z-index: 1;
            width: 100%;
            height: 200px;
            border-radius: 16px;
            overflow: hidden;
            margin-top: 5.25rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
            background: #f8fafc;
        }
        .banner-slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            transition: opacity 0.8s ease-in-out;
            border-radius: 16px;
            overflow: hidden;
        }
        .banner-slide img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .banner-slide.active { opacity: 1; }
        .wallet-summary {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 3rem;
            margin-bottom: 2rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 215, 0, 0.3);
        }
        .wallet-title { font-size: 1.25rem; font-weight: 600; color: #ffd700; margin-bottom: 1.5rem; text-align: center; }
          /* Wallet grid: enforce 2 columns so it appears as 2x2 on mobile and desktop
              Use grid-auto-rows so all cards in a row match height */
          /* fixed square cards: columns set in px so width == height */
          .wallet-grid { display: grid; grid-template-columns: repeat(2, minmax(140px, 1fr)); grid-auto-rows: minmax(110px, 1fr); gap: 1rem; justify-items: stretch; align-items: stretch; max-width: 560px; margin: 0 auto; }

        /* Slightly tighter card spacing on very small screens */
        @media (max-width: 420px) {
            .wallet-grid { grid-template-columns: repeat(2, minmax(110px, 1fr)); grid-auto-rows: minmax(100px, 1fr); gap: 2.6rem; }
            .wallet-card { padding: 0.7rem; }
            .wallet-value { font-size: 1.1rem; }
            .wallet-label { font-size: 0.72rem; }
        }
        .wallet-card {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            /* fill the grid cell so width == height */
            width: 100%;
            height: 100%;
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.12);
            transition: transform 200ms ease, box-shadow 200ms ease;
            will-change: transform;
        }

        .wallet-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 30px rgba(0,0,0,0.18);
        }
        .wallet-card.success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .wallet-card.warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); }
        .wallet-card.info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .wallet-value { font-size: 1.4rem; font-weight: 700; margin-bottom: 0.45rem; }
        .wallet-label { opacity: 0.9; font-size: 0.8rem; }
        /* Slightly larger card size on wider screens for visual balance */
        @media (min-width: 768px) {
            .wallet-grid { grid-template-columns: repeat(2, 170px); grid-auto-rows: 170px; }
            .wallet-value { font-size: 1.6rem; }
            .wallet-label { font-size: 0.9rem; }
        }
        .dashboard-tabs {
            position: fixed !important;
            bottom: 0 !important;
            left: 0 !important;
            right: 0 !important;
            display: flex;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            /* horizontal padding and include safe-area inset at bottom for devices with home indicator */
            padding: 0.5rem 1rem calc(0.5rem + env(safe-area-inset-bottom));
            box-shadow: 0 -4px 20px rgba(0, 0, 0, 0.15);
            z-index: 2000 !important;
            border-top: 1px solid rgba(255, 215, 0, 0.3);
        }
        .tab-btn {
            flex: 1;
            padding: 0.5rem;
            background: transparent;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            color: #64748b;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.25rem;
            min-height: 56px;
            position: relative;
            z-index: 2001;
            pointer-events: auto;
        }
        .tab-btn.center-btn { margin-top: -30px; }
        .tab-btn.center-btn .tab-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            box-shadow: 0 -4px 20px rgba(255, 215, 0, 0.4), 0 4px 20px rgba(255, 215, 0, 0.3);
            border: 4px solid rgba(0, 0, 0, 0.95);
        }
        .tab-btn.active { color: #ffd700; }
        .tab-btn.center-btn.active { color: #000000; }
        .tab-icon { font-size: 1.5rem; transition: transform 0.2s; }
        .tab-label { font-size: 0.75rem; font-weight: 500; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .tab-title { font-size: 1.5rem; font-weight: 600; color: #ffd700; margin-bottom: 1.5rem; text-align: center; }
        .plans-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 1rem; margin-top: 2rem; }
        .plan-card {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 10px;
            padding: 1rem;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            transition: all 0.3s;
            border: 2px solid transparent;
            color: #ffffff;
        }
        .plan-card:hover { transform: translateY(-5px); box-shadow: 0 8px 30px rgba(255, 215, 0, 0.2); border-color: #ffd700; }
        .plan-badge {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            padding: 0.35rem 0.75rem;
            border-radius: 16px;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 0.75rem;
            font-size: 0.875rem;
        }
        .plan-amount { font-size: 1.5rem; font-weight: 700; color: #ffd700; margin-bottom: 0.75rem; }
        .plan-details { margin: 1rem 0; }
        .plan-detail-item {
            display: flex;
            justify-content: space-between;
            padding: 0.4rem 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: #9ca3af;
            font-size: 0.875rem;
        }
        .plan-detail-item:last-child { border-bottom: none; }
        .plan-detail-item strong { color: #ffffff; }
        .invest-btn {
            width: 100%;
            padding: 0.625rem;
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.875rem;
        }
        .invest-btn:hover { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); transform: scale(1.02); }
        .payment-section { display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-top: 2rem; }
        .payment-card { background: rgba(26, 26, 26, 0.95); border-radius: 12px; padding: 1.5rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); color: #ffffff; }
        .payment-card-title { font-size: 1.25rem; font-weight: 600; color: #ffd700; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: #9ca3af; font-weight: 500; }
        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
            background: rgba(255, 255, 255, 0.05);
            color: #ffffff;
        }
        .form-group input:focus { outline: none; border-color: #ffd700; box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.1); }
        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary { background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%); color: #000000; }
        .btn-danger { background: #ef4444; color: white; }
        .referral-code-card {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            padding: 1.75rem;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .referral-code { font-size: 1.75rem; font-weight: 700; letter-spacing: 3px; margin: 0.75rem 0; }
        .copy-btn {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 2px solid white;
            padding: 0.5rem 1.75rem;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            margin-top: 0.5rem;
        }
        .referral-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1.25rem; }
        .referral-stat-card { background: rgba(26, 26, 26, 0.95); padding: 1.5rem; border-radius: 12px; text-align: center; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05); color: #ffffff; }
        .referral-stat-value { font-size: 1.8rem; font-weight: 700; color: #ffd700; margin-bottom: 0.5rem; }
        .referral-stat-label { color: #9ca3af; font-size: 0.9rem; }
        .profile-card { background: rgba(26, 26, 26, 0.95); border-radius: 16px; padding: 2rem; box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08); max-width: 600px; margin: 0 auto; color: #ffffff; }
        .profile-header { text-align: center; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 2px solid rgba(255, 215, 0, 0.3); }
        .profile-avatar {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 auto 0.875rem;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
        }
        .profile-name { font-size: 1.35rem; font-weight: 600; color: #ffffff; }
        .profile-info-item {
            padding: 0.875rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .profile-label { color: #9ca3af; font-weight: 500; font-size: 0.9rem; }
        .profile-value { color: #ffffff; font-weight: 600; font-size: 1rem; }
        .investments-table {
            background: rgba(26, 26, 26, 0.95);
            border-radius: 12px;
            padding: 0.25rem 0.5rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 215, 0, 0.3);
            margin-top: 2rem;
            /* allow parent scrolling but avoid internal vertical scroll */
            max-height: none;
            overflow: visible;
        }
        .data-table { width: 100%; border-collapse: collapse; background: rgba(45, 45, 45, 0.95); border-radius: 8px; overflow: hidden; table-layout: fixed; word-break: break-word; }
        .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
        /* allow header and cell text to wrap so they don't get truncated with ellipsis */
        .data-table th { background: rgba(35, 35, 35, 0.95); padding: 0.5rem 0.6rem; text-align: left; font-weight: 600; color: #ffd700; border-bottom: 2px solid rgba(255, 215, 0, 0.4); font-size: 0.95rem; white-space: normal; }
        .data-table td { padding: 0.45rem 0.6rem; border-bottom: 1px solid rgba(80, 80, 80, 0.5); color: #f5f5f5; font-size: 0.95rem; white-space: normal; }
        /* keep status badges on a single line */
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500; white-space: nowrap; display: inline-block; }
        .data-table tr:hover { background: rgba(60, 60, 60, 0.6); }
        .status-badge { padding: 0.25rem 0.75rem; border-radius: 12px; font-size: 0.875rem; font-weight: 500; }
        .status-badge.active { background: #d1fae5; color: #065f46; }
        .status-badge.completed { background: rgba(255, 215, 0, 0.2); color: #ffd700; border: 1px solid rgba(255, 215, 0, 0.4); }
        .no-data { text-align: center; color: #9ca3af; padding: 2rem; }

        @media (max-width: 768px) {
            .dashboard-container { padding: 1rem; padding-bottom: 6rem; }
            .plans-grid { grid-template-columns: repeat(2, 1fr); gap: 0.75rem; }
            .payment-section { grid-template-columns: 1fr; }
            .form-group input { font-size: 16px; }
            .data-table { font-size: 0.875rem; }
            .data-table th, .data-table td { padding: 0.75rem; }
        }
        @media (min-width: 769px) {
            .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 2rem; }
            .plans-grid { grid-template-columns: repeat(4, 1fr); }
            /* keep wallet as 2x2 on larger screens for a compact matrix */
            .wallet-grid { grid-template-columns: repeat(2, 170px); grid-auto-rows: 170px; }
        }
    </style>
</head>
<body>
    <header class="dashboard-header">
        <div class="header-left">
            <div class="logo">
                <img src="logo.svg" alt="1x Club Logo" class="logo-image">
                <span class="logo-text">1x Club</span>
            </div>
            <!-- user greeting removed -->
        </div>
        <div class="header-right">
            <a href="wallet/Recharge.php" class="wallet-link" title="Available Balance">
                <i class="fas fa-wallet wallet-icon" aria-hidden="true"></i>
                <span id="headerBalance">₹<?php echo number_format($wallet['current_balance'], 2); ?></span>
            </a>
        </div>
    </header>
        <div class="header-right">
            <a href="wallet/Recharge.php" class="wallet-link" title="Available Balance">
                <i class="fas fa-wallet wallet-icon" aria-hidden="true"></i>
                <span id="headerBalance">₹<?php echo number_format($wallet['current_balance'], 2); ?></span>
            </a>
        </div>

    <div class="dashboard-container">
        <div class="dashboard-tabs">
            <a class="tab-btn" href="referral.php" style="text-decoration:none; display:flex; flex-direction:column; align-items:center;" title="Referral">
                <i class="tab-icon fas fa-gift"></i>
                <span class="tab-label">Referral</span>
            </a>
            <button class="tab-btn" data-tab="profile">
                <i class="tab-icon fas fa-user"></i>
                <span class="tab-label">Profile</span>
            </button>
            <button class="tab-btn center-btn active" data-tab="home">
                <i class="tab-icon fas fa-home"></i>
                <span class="tab-label">Home</span>
            </button>
            <a class="tab-btn" href="wallet/Recharge.php" style="text-decoration: none; display: flex; flex-direction: column; align-items: center;" title="Open Wallet">
                <i class="tab-icon fas fa-wallet"></i>
                <span class="tab-label">Wallet</span>
            </a>
            <a id="historyLink" class="tab-btn" href="history.php" onclick="window.location.href='history.php'" style="text-decoration:none; display:flex; flex-direction:column; align-items:center;" title="History">
                <i class="tab-icon fas fa-chart-line"></i>
                <span class="tab-label">History</span>
            </a>
        </div>

        <div class="tab-content">
            <div id="homeTab" class="tab-pane active">
                <div class="banner-container">
                    <div class="banner-slide active">
                        <img src="https://i.ibb.co/rRdcFFdL/ban5.png" alt="Banner 1">
                    </div>
                    <div class="banner-slide">
                        <img src="https://i.ibb.co/F474P7zf/ban4.png" alt="Banner 2">
                    </div>
                </div>

                <!-- wallet summary removed -->

                <div class="investments-table" style="margin-bottom: 2rem;">
                    <h2 class="tab-title">My Active Investments</h2>
                    <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Plan</th>
                                <th>Amount</th>
                                <th>Return</th>
                                <th>Status</th>
                                <th>End Date</th>
                            </tr>
                        </thead>
                        <tbody id="investmentsTbody">
                            <?php if (empty($investments)): ?>
                                <tr><td colspan="5" class="no-data">No active investments</td></tr>
                            <?php else: ?>
                                <?php foreach ($investments as $inv): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($inv['plan_type']); ?></td>
                                    <td>₹<?php echo number_format($inv['amount'], 2); ?></td>
                                    <td>₹<?php echo number_format($inv['amount'] * (1 + $inv['roi_percentage']/100), 2); ?></td>
                                    <td><span class="status-badge <?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                    <td><?php echo $inv['maturity_date'] ? date('M d, Y H:i', strtotime($inv['maturity_date'])) : 'N/A'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>

                <h2 class="tab-title">Investment Plans</h2>
                
                <div class="plans-grid">
                    <?php
                    $plans = [
                        ['num' => 1, 'amount' => 1000, 'return' => 1400, 'hours' => 1],
                        ['num' => 2, 'amount' => 3000, 'return' => 4000, 'hours' => 3],
                        ['num' => 3, 'amount' => 5000, 'return' => 6200, 'hours' => 10],
                        ['num' => 4, 'amount' => 8000, 'return' => 9800, 'hours' => 18],
                        ['num' => 5, 'amount' => 10000, 'return' => 12000, 'hours' => 24],
                        ['num' => 6, 'amount' => 15000, 'return' => 18000, 'hours' => 32],
                        ['num' => 7, 'amount' => 25000, 'return' => 30000, 'hours' => 40],
                        ['num' => 8, 'amount' => 43000, 'return' => 50000, 'hours' => 45],
                        ['num' => 9, 'amount' => 51000, 'return' => 60000, 'hours' => 50],
                        ['num' => 10, 'amount' => 64000, 'return' => 76000, 'hours' => 55],
                        ['num' => 11, 'amount' => 81500, 'return' => 94000, 'hours' => 62],
                        ['num' => 12, 'amount' => 95000, 'return' => 110000, 'hours' => 70],
                        ['num' => 13, 'amount' => 125000, 'return' => 150000, 'hours' => 80]
                    ];
                    
                    foreach ($plans as $plan):
                        $profit = $plan['return'] - $plan['amount'];
                    ?>
                    <div class="plan-card">
                        <div class="plan-badge">Plan <?php echo $plan['num']; ?></div>
                        <div class="plan-amount">₹<?php echo number_format($plan['amount']); ?></div>
                        <div class="plan-details">
                            <div class="plan-detail-item">
                                <span>Duration</span>
                                <strong><?php echo $plan['hours']; ?> Hour<?php echo $plan['hours'] > 1 ? 's' : ''; ?></strong>
                            </div>
                            <div class="plan-detail-item">
                                <span>Return Amount</span>
                                <strong>₹<?php echo number_format($plan['return']); ?></strong>
                            </div>
                            <div class="plan-detail-item">
                                <span>Profit</span>
                                <strong style="color: #10b981;">₹<?php echo number_format($profit); ?></strong>
                            </div>
                        </div>
                        <?php
                            $isLocked = in_array($plan['num'], $activePlanNums);
                            $btnText = $isLocked ? 'Locked' : 'Invest Now';
                        ?>
                        <button class="invest-btn" data-plan="<?php echo $plan['num']; ?>" <?php if ($isLocked) echo 'disabled style="opacity:0.6;cursor:not-allowed;"'; ?> onclick="investNow(<?php echo $plan['num']; ?>, <?php echo $plan['amount']; ?>, '<?php echo $plan['hours']; ?> hours', <?php echo $plan['return']; ?>)"><?php echo $btnText; ?></button>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>



            

            <div id="profileTab" class="tab-pane">
                <h2 class="tab-title">Your Profile</h2>
                
                <div class="profile-card">
                    <div class="profile-header">
                        <div class="profile-avatar"><?php echo strtoupper(substr($profile['name'], 0, 1)); ?></div>
                        <div class="profile-name"><?php echo htmlspecialchars($profile['name']); ?></div>
                    </div>

                    <div class="profile-info">
                        <div class="profile-info-item">
                            <span class="profile-label">Phone Number</span>
                            <span class="profile-value"><?php echo htmlspecialchars($profile['phone']); ?></span>
                        </div>
                        <div class="profile-info-item">
                            <span class="profile-label">Referral Code</span>
                            <span class="profile-value"><?php echo htmlspecialchars($profile['referral_code']); ?></span>
                        </div>
                    </div>

                    <a href="?logout=1" class="btn btn-danger" style="margin: 1.5rem auto 0; display: block; width: fit-content;" onclick="return confirm('Are you sure you want to logout?')">Logout</a>
                </div>
            </div>

            
        </div>
    </div>

    <script>
        let currentSlide = 0;
        const slides = document.querySelectorAll('.banner-slide');
        
        function showSlide(index) {
            slides.forEach(slide => slide.classList.remove('active'));
            slides[index].classList.add('active');
        }
        
        function nextSlide() {
            currentSlide = (currentSlide + 1) % slides.length;
            showSlide(currentSlide);
        }
        
        setInterval(nextSlide, 3000);

        const tabButtons = document.querySelectorAll('.tab-btn');
        const tabPanes = document.querySelectorAll('.tab-pane');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                const tabName = button.getAttribute('data-tab');
                // if element does not have a data-tab (e.g., anchor link to external page), do nothing here
                if (!tabName) return;

                tabButtons.forEach(btn => btn.classList.remove('active'));
                tabPanes.forEach(pane => pane.classList.remove('active'));

                button.classList.add('active');
                const target = document.getElementById(tabName + 'Tab');
                if (target) target.classList.add('active');
            });
        });

        function copyReferralCode() {
            const code = '<?php echo $profile['referral_code']; ?>';
            navigator.clipboard.writeText(code).then(() => {
                alert('Referral code copied: ' + code);
            }).catch(() => {
                alert('Failed to copy referral code');
            });
        }

        // Add Money Form Handler (only if present on page)
        const addMoneyFormEl = document.getElementById('addMoneyForm');
        if (addMoneyFormEl) {
            addMoneyFormEl.addEventListener('submit', async function(e) {
                e.preventDefault();
                const amount = document.getElementById('addAmount').value;
                
                if (amount < 100) {
                    alert('Minimum amount is ₹100');
                    return;
                }
                
                if (amount > 100000) {
                    alert('Maximum amount is ₹1,00,000');
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('amount', amount);
                    
                    const response = await fetch('api/add_money.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Create form and submit to payment gateway
                        const form = document.createElement('form');
                        form.method = 'POST';
                        form.action = result.paymentUrl;
                        
                        for (const key in result.paymentData) {
                            const input = document.createElement('input');
                            input.type = 'hidden';
                            input.name = key;
                            input.value = result.paymentData[key];
                            form.appendChild(input);
                        }
                        
                        document.body.appendChild(form);
                        form.submit();
                    } else {
                        alert(result.message || 'Failed to initiate payment');
                    }
                } catch (error) {
                    console.error('Payment error:', error);
                    alert('Something went wrong. Please try again.');
                }
            });
        }

        // Withdrawal Form Handler (only if present on page)
        const withdrawFormEl = document.getElementById('withdrawForm');
        if (withdrawFormEl) {
            withdrawFormEl.addEventListener('submit', async function(e) {
                e.preventDefault();
                const amount = document.getElementById('withdrawAmount').value;
                const upiHolderName = document.getElementById('upiHolderName').value;
                const upiNumber = document.getElementById('upiNumber').value;
                
                if (amount < 100) {
                    alert('Minimum withdrawal amount is ₹100');
                    return;
                }
                
                if (!confirm(`Withdraw ₹${amount} to UPI: ${upiNumber}?`)) {
                    return;
                }
                
                try {
                    const formData = new FormData();
                    formData.append('amount', amount);
                    formData.append('upi_holder_name', upiHolderName);
                    formData.append('upi_number', upiNumber);
                    
                    const response = await fetch('api/withdraw_money.php', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        alert(result.message);
                        withdrawFormEl.reset();
                        location.reload();
                    } else {
                        alert(result.message || 'Withdrawal failed');
                    }
                } catch (error) {
                    console.error('Withdrawal error:', error);
                    alert('Something went wrong. Please try again.');
                }
            });
        }

        // Investment Function
        function investNow(planNum, amount, duration, returnAmount) {
            if (!confirm(`Invest ₹${Number(amount).toLocaleString()} for ${duration}?\n\nYou will receive ₹${Number(returnAmount).toLocaleString()} at maturity.`)) {
                return;
            }

            // Fetch fresh balance from server to avoid stale client-side value
            fetch('api/get_balance.php', { credentials: 'same-origin' })
                .then(res => res.json().catch(() => { throw new Error('Unable to verify balance'); }))
                .then(data => {
                    if (!data || !data.success) throw new Error(data && data.message ? data.message : 'Unable to verify balance. Please login again.');
                    const balance = Number(data.current_balance || 0);
                    if (Number(amount) > balance) {
                        // Redirect to recharge with prefill
                        window.location.href = 'wallet/Recharge.php?prefill=' + encodeURIComponent(amount);
                        return null;
                    }

                    const formData = new FormData();
                    formData.append('plan_num', planNum);
                    formData.append('amount', amount);

                    return fetch('api/invest.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin'
                    });
                })
                .then(res => {
                    if (!res) return null;
                    return res.json().catch(() => { throw new Error('Invalid server response'); });
                })
                .then(resObj => {
                    if (!resObj) return null;

                    if (!resObj.success) {
                        if (resObj.code === 'INSUFFICIENT_FUNDS') {
                            window.location.href = 'wallet/Recharge.php?prefill=' + encodeURIComponent(amount);
                            return null;
                        }
                        if (resObj.code === 'DUPLICATE_PLAN') {
                            alert(resObj.message || 'You already have an active investment in this plan.');
                            // disable the button to reflect lock
                            const btn = document.querySelector('.invest-btn[data-plan="' + planNum + '"]');
                            if (btn) { btn.disabled = true; btn.textContent = 'Locked'; btn.style.opacity = '0.6'; btn.style.cursor = 'not-allowed'; }
                            return null;
                        }
                        throw new Error(resObj.message || 'Investment failed');
                    }

                    // Update UI without reload
                    try {
                        // Update available balance
                        if (typeof resObj.newBalance !== 'undefined') {
                            const balEl = document.getElementById('availableBalance');
                            if (balEl) balEl.textContent = '₹' + Number(resObj.newBalance).toFixed(2);
                            const headerBal = document.getElementById('headerBalance');
                            if (headerBal) headerBal.textContent = '₹' + Number(resObj.newBalance).toFixed(2);
                        } else {
                            // Fallback: fetch balance
                            fetch('api/get_balance.php').then(r => r.json()).then(d => {
                                const balEl = document.getElementById('availableBalance');
                                const headerBal = document.getElementById('headerBalance');
                                if (d && d.success) {
                                    if (balEl) balEl.textContent = '₹' + Number(d.current_balance || 0).toFixed(2);
                                    if (headerBal) headerBal.textContent = '₹' + Number(d.current_balance || 0).toFixed(2);
                                }
                            }).catch(() => { });
                        }

                        // Update total invested and active count
                        const totalInvEl = document.getElementById('totalInvested');
                        if (totalInvEl) {
                            const cur = parseFloat((totalInvEl.textContent || '0').replace(/[^0-9.-]+/g, '') || 0);
                            totalInvEl.textContent = '₹' + (cur + Number(amount)).toFixed(2);
                        }
                        const activeEl = document.getElementById('activeInvestmentsCount');
                        if (activeEl) {
                            const curA = parseInt(activeEl.textContent || '0') || 0;
                            activeEl.textContent = curA + 1;
                        }

                        // Append new row to investments table
                        const tbody = document.getElementById('investmentsTbody');
                        if (tbody) {
                            // remove no-data row if present
                            const noData = tbody.querySelector('.no-data');
                            if (noData) noData.parentElement.remove();

                            const hours = resObj.plan_hours || (function(){ const m = {1:1,2:3,3:10,4:18,5:24,6:32,7:40,8:45,9:50,10:55,11:62,12:70,13:80}; return m[planNum] || 10; })();
                            const matDate = new Date();
                            matDate.setHours(matDate.getHours() + hours);
                            const matStr = matDate.toLocaleString();

                            const tr = document.createElement('tr');
                            tr.innerHTML = '<td>plan_' + planNum + '</td>' +
                                           '<td>₹' + Number(amount).toFixed(2) + '</td>' +
                                           '<td>₹' + Number(resObj.returnAmount || returnAmount).toFixed(2) + '</td>' +
                                           '<td><span class="status-badge active">Active</span></td>' +
                                           '<td>' + matStr + '</td>';
                            tbody.appendChild(tr);
                        }

                        // disable the plan button (lock)
                        const btn = document.querySelector('.invest-btn[data-plan="' + planNum + '"]');
                        if (btn) { btn.disabled = true; btn.textContent = 'Locked'; btn.style.opacity = '0.6'; btn.style.cursor = 'not-allowed'; }

                        alert(resObj.message || 'Investment started');
                    } catch (uiErr) {
                        console.error('UI update error', uiErr);
                        // fallback to reload
                        location.reload();
                    }

                    return null;
                })
                .catch(err => {
                    console.error('Investment error:', err);
                    alert(err.message || 'Something went wrong. Please try again.');
                });
        }

        // current available balance from server
        const currentBalance = Number(<?php echo isset($wallet['current_balance']) ? floatval($wallet['current_balance']) : 0; ?>);
    </script>
</body>
</html>
