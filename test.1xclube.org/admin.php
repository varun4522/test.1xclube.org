<?php
session_start();
require_once 'config.php';

// Check admin authentication
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    header('Location: index.php');
    exit();
}

$conn = getDBConnection();

// Calculate statistics
$totalUsers = 0;
$totalInvestmentsAmount = 0;
$totalEarnings = 0;
$activePlansCount = 0;

// Get all users with additional stats (using id as primary key, no email)
$stmt = $conn->prepare("SELECT p.id, p.name, p.phone, p.referral_code, 
                        p.current_balance as current_balance,
                        (SELECT COUNT(*) FROM investments WHERE user_id = p.id) as investment_count,
                        (SELECT COALESCE(SUM(amount), 0) FROM investments WHERE user_id = p.id) as total_invested,
                        p.created_at as join_date
                        FROM profiles p
                        ORDER BY p.id DESC");

if (!$stmt) {
    error_log("Admin users query error: " . $conn->error);
    die("Database error loading users. Please check the database structure. Error: " . htmlspecialchars($conn->error));
}
if (!$stmt->execute()) {
    error_log("Admin users execute error: " . $stmt->error);
    die("Database error executing users query. Error: " . htmlspecialchars($stmt->error));
}
$result = $stmt->get_result();
$users = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate totals
$totalUsers = count($users);
foreach ($users as $user) {
    $totalInvestmentsAmount += $user['total_invested'] ?? 0;
}

// Get all investments with maturity date
$stmt = $conn->prepare("SELECT i.id, i.user_id, p.name, i.amount, i.plan_type, i.status, 
                        i.roi_percentage, i.created_at, i.maturity_date,
                        (i.amount * i.roi_percentage / 100) as expected_return
                        FROM investments i
                        JOIN profiles p ON i.user_id = p.id
                        ORDER BY i.created_at DESC");
if (!$stmt) {
    error_log("Admin investments query error: " . $conn->error);
    $investments = [];
} else {
    if (!$stmt->execute()) {
        error_log("Admin investments execute error: " . $stmt->error);
        $investments = [];
    } else {
        $result = $stmt->get_result();
        $investments = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Calculate earnings and active plans
foreach ($investments as $inv) {
    if ($inv['status'] === 'completed') {
        $totalEarnings += $inv['expected_return'] ?? 0;
    }
    if ($inv['status'] === 'active') {
        $activePlansCount++;
    }
}

// Get all withdrawal requests with UPI details
$stmt = $conn->prepare("SELECT w.id, w.user_id, p.name, w.amount, w.status, w.requested_at as created_at,
                        w.upi_holder_name, w.upi_number
                        FROM withdrawal_request w
                        JOIN profiles p ON w.user_id = p.id
                        ORDER BY w.requested_at DESC");
if (!$stmt) {
    error_log("Admin withdrawals query error: " . $conn->error);
    $withdrawals = [];
} else {
    if (!$stmt->execute()) {
        error_log("Admin withdrawals execute error: " . $stmt->error);
        $withdrawals = [];
    } else {
        $result = $stmt->get_result();
        $withdrawals = $result->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
}

// Count pending withdrawals
$pendingWithdrawalsCount = 0;
foreach ($withdrawals as $w) {
    if ($w['status'] === 'pending') {
        $pendingWithdrawalsCount++;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'delete_user') {
        $userId = $_POST['user_id'] ?? 0;
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("DELETE FROM withdrawal_request WHERE user_id = ?");
            if (!$stmt) {
                // Fallback for alternate table name (typo in some schemas)
                $stmt = $conn->prepare("DELETE FROM withdrawl_request WHERE user_id = ?");
                if (!$stmt) throw new Exception('Prepare failed (withdrawal_request|withdrawl_request): ' . $conn->error);
            }
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            $stmt = $conn->prepare("DELETE FROM transaction_history WHERE user_id = ?");
            if (!$stmt) throw new Exception('Prepare failed (transaction_history): ' . $conn->error);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            // No separate user_wallet table to delete; profile holds balances
            
            $stmt = $conn->prepare("DELETE FROM investments WHERE user_id = ?");
            if (!$stmt) throw new Exception('Prepare failed (investments): ' . $conn->error);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            // FIX: Removed deletion from 'users' table. 'profiles' is now the main user record.
            $stmt = $conn->prepare("DELETE FROM profiles WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed (profiles): ' . $conn->error);
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            
            $conn->commit();
            header('Location: admin.php?msg=user_deleted');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            error_log('Admin delete user error: ' . $e->getMessage());
            header('Location: admin.php?msg=error&error=' . urlencode($e->getMessage()));
            exit();
        }
    }
    
    if ($action === 'approve_withdrawal') {
        $withdrawalId = $_POST['withdrawal_id'] ?? 0;
        $userId = $_POST['user_id'] ?? 0;
        $amount = $_POST['amount'] ?? 0;
        
        $conn->begin_transaction();
        try {
            // Update withdrawal status
            $stmt = $conn->prepare("UPDATE withdrawal_request SET status = 'approved' WHERE id = ?");
            if (!$stmt) {
                $stmt = $conn->prepare("UPDATE withdrawl_request SET status = 'approved' WHERE id = ?");
                if (!$stmt) throw new Exception('Prepare failed (withdrawal_request update): ' . $conn->error);
            }
            $stmt->bind_param("i", $withdrawalId);
            $stmt->execute();
            
            // Add transaction history
            $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, transaction_type, amount, status, created_at) 
                                    VALUES (?, 'withdrawal', ?, 'completed', NOW())");
            if (!$stmt) throw new Exception('Prepare failed (transaction_history insert): ' . $conn->error);
            $stmt->bind_param("id", $userId, $amount);
            $stmt->execute();
            
            $conn->commit();
            header('Location: admin.php?msg=withdrawal_approved');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            header('Location: admin.php?msg=error&error=' . urlencode($e->getMessage()));
            exit();
        }
    }
    
    if ($action === 'reject_withdrawal') {
        $withdrawalId = $_POST['withdrawal_id'] ?? 0;
        $userId = $_POST['user_id'] ?? 0;
        $amount = $_POST['amount'] ?? 0;
        
        $conn->begin_transaction();
        try {
            // Update withdrawal status
            $stmt = $conn->prepare("UPDATE withdrawal_request SET status = 'rejected' WHERE id = ?");
            if (!$stmt) {
                $stmt = $conn->prepare("UPDATE withdrawl_request SET status = 'rejected' WHERE id = ?");
                if (!$stmt) throw new Exception('Prepare failed (withdrawal_request update): ' . $conn->error);
            }
            $stmt->bind_param("i", $withdrawalId);
            $stmt->execute();
            
            // Refund amount to profiles.current_balance
            $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance + ? WHERE id = ?");
            if (!$stmt) throw new Exception('Prepare failed (profiles update): ' . $conn->error);
            $stmt->bind_param("di", $amount, $userId);
            $stmt->execute();
            
            $conn->commit();
            header('Location: admin.php?msg=withdrawal_rejected');
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            header('Location: admin.php?msg=error&error=' . urlencode($e->getMessage()));
            exit();
        }
    }
}

// AJAX: fetch full user details (for admin UI)
if (isset($_GET['action']) && $_GET['action'] === 'get_user' && isset($_GET['user_id'])) {
    // ensure admin
    if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit();
    }

    $userId = intval($_GET['user_id']);
    $conn = getDBConnection();
    $out = ['success' => true];

    // profile
    $stmt = $conn->prepare("SELECT id, name, phone, referral_code, current_balance, total_invested, created_at FROM profiles WHERE id = ?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out['profile'] = $res->fetch_assoc() ?: null;
    $stmt->close();

    // investments
    $stmt = $conn->prepare("SELECT id, plan_type, amount, roi_percentage, status, maturity_date, created_at FROM investments WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out['investments'] = $res->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // transaction history (if table exists)
    $out['transactions'] = [];
    $stmt = $conn->prepare("SELECT id, transaction_type, amount, status, created_at FROM transaction_history WHERE user_id = ? ORDER BY created_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out['transactions'] = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    // withdrawals
    $stmt = $conn->prepare("SELECT id, amount, status, requested_at as created_at, upi_holder_name, upi_number FROM withdrawal_request WHERE user_id = ? ORDER BY requested_at DESC");
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $out['withdrawals'] = $res->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        // fallback try alternate table name
        $stmt = $conn->prepare("SELECT id, amount, status, requested_at as created_at, upi_holder_name, upi_number FROM withdrawl_request WHERE user_id = ? ORDER BY requested_at DESC");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            $out['withdrawals'] = $res->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
        }
    }

    $conn->close();
    header('Content-Type: application/json');
    echo json_encode($out);
    exit();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Admin Panel - 1x Club</title>
    <link rel="icon" type="image/svg+xml" href="logo.svg">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f0f0f 0%, #1a1a1a 50%, #0f0f0f 100%);
            min-height: 100vh;
            color: #f5f5f5;
            overflow-x: hidden;
        }

        /* Sidebar Navigation */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            height: 100vh;
            width: 280px;
            background: rgba(26, 26, 26, 0.98);
            backdrop-filter: blur(15px);
            border-right: 2px solid rgba(255, 215, 0, 0.3);
            z-index: 1000;
            transition: transform 0.3s ease;
            box-shadow: 4px 0 20px rgba(0, 0, 0, 0.5);
        }

        .sidebar.collapsed {
            transform: translateX(-100%);
        }

        .sidebar-header {
            padding: 2rem 1.5rem;
            border-bottom: 1px solid rgba(255, 215, 0, 0.2);
            text-align: center;
        }

        .sidebar-logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            display: block;
        }

        .sidebar-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: #ffd700;
            margin-bottom: 0.5rem;
        }

        .sidebar-subtitle {
            font-size: 0.875rem;
            color: #94a3b8;
        }

        .sidebar-nav {
            padding: 1rem 0;
        }

        .nav-item {
            margin: 0.25rem 0;
        }

        .nav-link {
            display: flex;
            align-items: center;
            padding: 0.875rem 1.5rem;
            color: #e5e5e5;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-weight: 500;
            cursor: pointer;
        }

        .nav-link:hover {
            background: rgba(255, 215, 0, 0.1);
            color: #ffd700;
            border-left-color: #ffd700;
        }

        .nav-link.active {
            background: rgba(255, 215, 0, 0.15);
            color: #ffd700;
            border-left-color: #ffd700;
        }

        .nav-icon {
            font-size: 1.25rem;
            margin-right: 0.75rem;
            width: 20px;
            text-align: center;
        }

        .nav-text {
            flex: 1;
        }

        .nav-badge {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            padding: 0.125rem 0.5rem;
            border-radius: 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s ease;
            min-height: 100vh;
        }

        .main-content.expanded {
            margin-left: 0;
        }

        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 1rem;
            left: 1rem;
            z-index: 1001;
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            border: none;
            border-radius: 8px;
            padding: 0.75rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
            font-weight: 600;
        }

        .menu-toggle:hover {
            background: linear-gradient(135deg, #ffed4e 0%, #fbbf24 100%);
        }

        .admin-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .admin-tab-pane {
            display: none;
        }

        .admin-tab-pane.active {
            display: block;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            padding: 1.5rem;
            border-radius: 12px;
            text-align: center;
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(255, 215, 0, 0.3);
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .stat-label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffd700;
        }

        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            font-weight: 600;
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, #ffed4e 0%, #fbbf24 100%);
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid rgba(255, 215, 0, 0.3);
            background: rgba(26, 26, 26, 0.8);
            color: #f5f5f5;
            border-radius: 8px;
            font-size: 1rem;
            margin-bottom: 1.5rem;
        }

        .search-input:focus {
            outline: none;
            border-color: #ffd700;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(26, 26, 26, 0.95);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 215, 0, 0.2);
        }

        .data-table th {
            background: rgba(35, 35, 35, 0.9);
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #ffd700;
            border-bottom: 2px solid rgba(255, 215, 0, 0.3);
        }

        .data-table td {
            padding: 1rem;
            border-bottom: 1px solid rgba(80, 80, 80, 0.5);
            color: #e5e5e5;
        }

        .data-table tr:hover {
            background: rgba(60, 60, 60, 0.6);
        }

        .btn-small {
            padding: 0.375rem 0.75rem;
            font-size: 0.875rem;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            margin-right: 0.5rem;
            transition: all 0.2s;
        }

        .btn-small.success {
            background: rgba(34, 197, 94, 0.3);
            color: #86efac;
            border: 1px solid rgba(34, 197, 94, 0.5);
        }

        .btn-small.danger {
            background: rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            border: 1px solid rgba(239, 68, 68, 0.5);
        }

        .btn-small.info {
            background: rgba(59, 130, 246, 0.12);
            color: #93c5fd;
            border: 1px solid rgba(59, 130, 246, 0.22);
        }

        .btn-small:hover {
            opacity: 0.8;
            transform: translateY(-1px);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .status-pending { background: rgba(234, 179, 8, 0.2); color: #fde047; }
        .status-completed { background: rgba(59, 130, 246, 0.2); color: #93c5fd; }
        .status-approved { background: rgba(34, 197, 94, 0.2); color: #86efac; }
        .status-rejected { background: rgba(239, 68, 68, 0.2); color: #fca5a5; }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.2);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .filter-buttons {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 0.5rem 1.5rem;
            border: 2px solid rgba(255, 215, 0, 0.3);
            background: rgba(26, 26, 26, 0.8);
            color: #e5e5e5;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
        }

        .filter-btn.active {
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            border-color: #ffd700;
        }

        .filter-btn:hover {
            background: rgba(255, 215, 0, 0.1);
            color: #ffd700;
        }

        .no-data {
            text-align: center;
            color: #94a3b8;
            padding: 3rem;
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }

            .main-content {
                margin-left: 0;
            }

            .menu-toggle {
                display: block;
            }

            .admin-container {
                padding: 1rem;
                margin-top: 3rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .section-header {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }

            .data-table {
                font-size: 0.75rem;
                display: block;
                overflow-x: auto;
            }

            .data-table th,
            .data-table td {
                padding: 0.5rem 0.25rem;
                font-size: 0.75rem;
            }

            .btn-small {
                padding: 0.25rem 0.5rem;
                font-size: 0.7rem;
            }

            .filter-buttons {
                flex-direction: column;
            }

            .filter-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <img src="logo.svg" alt="1x Club Logo" class="sidebar-logo">
            <div class="sidebar-title">Admin Panel</div>
            <div class="sidebar-subtitle">1x Club</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-item">
                <div class="nav-link active" onclick="showTab('usersTab')">
                    <span class="nav-icon">📊</span>
                    <span class="nav-text">Dashboard</span>
                </div>
            </div>
            <div class="nav-item">
                <div class="nav-link" onclick="showTab('investmentsTab')">
                    <span class="nav-icon">💼</span>
                    <span class="nav-text">Investments</span>
                </div>
            </div>
            <div class="nav-item">
                <div class="nav-link" onclick="showTab('paymentsTab')">
                    <span class="nav-icon">💳</span>
                    <span class="nav-text">Withdrawals</span>
                    <?php if ($pendingWithdrawalsCount > 0): ?>
                    <span class="nav-badge"><?php echo $pendingWithdrawalsCount; ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="nav-item">
                <div class="nav-link" onclick="logout()">
                    <span class="nav-icon">🚪</span>
                    <span class="nav-text">Logout</span>
                </div>
            </div>
        </nav>
    </div>

    <div class="main-content" id="mainContent">
        <div class="admin-container">
            <?php if (isset($_GET['msg'])): ?>
                <?php if ($_GET['msg'] === 'error'): ?>
                    <div class="alert alert-error">
                        ❌ Error: <?php echo htmlspecialchars($_GET['error'] ?? 'Unknown error'); ?>
                    </div>
                <?php else: ?>
                    <div class="alert alert-success">
                        ✅ <?php 
                            $messages = [
                                'user_deleted' => 'User deleted successfully',
                                'withdrawal_approved' => 'Withdrawal approved successfully',
                                'withdrawal_rejected' => 'Withdrawal rejected and amount refunded'
                            ];
                            echo $messages[$_GET['msg']] ?? 'Action completed';
                        ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div id="usersTab" class="admin-tab-pane active">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $totalUsers; ?></div>
                        <div class="stat-label">Total Users</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">₹<?php echo number_format($totalInvestmentsAmount, 0); ?></div>
                        <div class="stat-label">Total Investments</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value">₹<?php echo number_format($totalEarnings, 0); ?></div>
                        <div class="stat-label">Total Earnings</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $activePlansCount; ?></div>
                        <div class="stat-label">Active Plans</div>
                    </div>
                </div>

                <div class="section-header">
                    <h2 class="section-title">👥 User Management</h2>
                    <button class="btn btn-primary" onclick="location.reload()">🔄 Refresh</button>
                </div>

                <input 
                    type="text" 
                    id="userSearchInput" 
                    class="search-input"
                    placeholder="🔍 Search by name, phone, or email..." 
                    onkeyup="filterUsers()"
                />

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Phone</th>
                            <th>Total Invested</th>
                            <th>Current Balance</th>
                            <th>Join Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone']); ?></td>
                            <td>₹<?php echo number_format($user['total_invested'] ?? 0, 2); ?></td>
                            <td>₹<?php echo number_format($user['current_balance'] ?? 0, 2); ?></td>
                            <td><?php echo $user['join_date'] ? date('M d, Y', strtotime($user['join_date'])) : 'N/A'; ?></td>
                            <td>
                                <button class="btn-small info" onclick="fetchUserDetails(<?php echo $user['id']; ?>)" title="View user details">ℹ️ Info</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('⚠️ Delete this user? This will remove all their data permanently.');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" class="btn-small danger">🗑️ Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="investmentsTab" class="admin-tab-pane">
                <div class="section-header">
                    <h2 class="section-title">📈 Investment Management</h2>
                    <button class="btn btn-primary" onclick="location.reload()">🔄 Refresh</button>
                </div>

                <div class="filter-buttons">
                    <button class="filter-btn active" onclick="filterInvestments('all')">📊 All Investments</button>
                    <button class="filter-btn" onclick="filterInvestments('active')">⏳ Active</button>
                    <button class="filter-btn" onclick="filterInvestments('completed')">✓ Completed</button>
                </div>

                <input 
                    type="text" 
                    id="investmentSearchInput" 
                    class="search-input"
                    placeholder="🔍 Search by user name, plan, or amount..." 
                    onkeyup="filterInvestmentTable()"
                />

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Plan</th>
                            <th>Amount</th>
                            <th>Return</th>
                            <th>ROI %</th>
                            <th>Status</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                        </tr>
                    </thead>
                    <tbody id="investmentsTableBody">
                        <?php if (empty($investments)): ?>
                        <tr><td colspan="8" class="no-data">No investments found</td></tr>
                        <?php else: ?>
                            <?php foreach ($investments as $inv): ?>
                            <tr data-status="<?php echo $inv['status']; ?>">
                                <td><?php echo htmlspecialchars($inv['name']); ?></td>
                                <td><?php echo htmlspecialchars($inv['plan_type']); ?></td>
                                <td>₹<?php echo number_format($inv['amount'], 2); ?></td>
                                <td>₹<?php echo number_format($inv['expected_return'] ?? 0, 2); ?></td>
                                <td><?php echo htmlspecialchars($inv['roi_percentage']); ?>%</td>
                                <td><span class="status-badge status-<?php echo $inv['status']; ?>"><?php echo ucfirst($inv['status']); ?></span></td>
                                <td><?php echo date('M d, Y H:i', strtotime($inv['created_at'])); ?></td>
                                <td><?php echo $inv['maturity_date'] ? date('M d, Y H:i', strtotime($inv['maturity_date'])) : 'N/A'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div id="paymentsTab" class="admin-tab-pane">
                <div class="section-header">
                    <h2 class="section-title">💳 Withdrawal Management</h2>
                    <button class="btn btn-primary" onclick="location.reload()">🔄 Refresh</button>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Account Holder</th>
                            <th>UPI Number</th>
                            <th>Status</th>
                            <th>Request Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="paymentsTableBody">
                        <?php if (empty($withdrawals)): ?>
                        <tr><td colspan="7" class="no-data">No withdrawal requests found</td></tr>
                        <?php else: ?>
                            <?php foreach ($withdrawals as $withdrawal): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($withdrawal['name']); ?></td>
                                <td>₹<?php echo number_format($withdrawal['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($withdrawal['upi_holder_name'] ?? 'N/A'); ?></td>
                                <td><?php echo htmlspecialchars($withdrawal['upi_number'] ?? 'N/A'); ?></td>
                                <td><span class="status-badge status-<?php echo $withdrawal['status']; ?>"><?php echo ucfirst($withdrawal['status']); ?></span></td>
                                <td><?php echo date('M d, Y H:i', strtotime($withdrawal['created_at'])); ?></td>
                                <td>
                                    <?php if ($withdrawal['status'] === 'pending'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('✅ Approve this withdrawal?');">
                                        <input type="hidden" name="action" value="approve_withdrawal">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $withdrawal['id']; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $withdrawal['user_id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $withdrawal['amount']; ?>">
                                        <button type="submit" class="btn-small success">✓ Approve</button>
                                    </form>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('❌ Reject this withdrawal? Amount will be refunded to user wallet.');">
                                        <input type="hidden" name="action" value="reject_withdrawal">
                                        <input type="hidden" name="withdrawal_id" value="<?php echo $withdrawal['id']; ?>">
                                        <input type="hidden" name="user_id" value="<?php echo $withdrawal['user_id']; ?>">
                                        <input type="hidden" name="amount" value="<?php echo $withdrawal['amount']; ?>">
                                        <button type="submit" class="btn-small danger">✗ Reject</button>
                                    </form>
                                    <?php else: ?>
                                    <span style="color: #94a3b8; font-size: 0.875rem;">No actions</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- User Details Modal -->
    <div id="userDetailsModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:2000; align-items:center; justify-content:center;">
        <div style="background:#0f1724; color:#fff; width:90%; max-width:900px; border-radius:10px; padding:1rem; box-shadow:0 8px 40px rgba(0,0,0,0.6);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.5rem;">
                <h3 id="ud_name" style="margin:0; color:#ffd700">User Details</h3>
                <div>
                    <button onclick="closeUserModal()" class="btn-small" style="background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.06);">Close</button>
                </div>
            </div>

            <div id="ud_content" style="max-height:70vh; overflow:auto; padding-right:0.5rem;">
                <!-- Filled by JS -->
            </div>
        </div>
    </div>

    <script>
        // Sidebar toggle
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('expanded');
        }

        // Close sidebar on mobile when clicking outside
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const menuToggle = document.querySelector('.menu-toggle');
            
            if (window.innerWidth <= 768) {
                if (!sidebar.contains(event.target) && !menuToggle.contains(event.target)) {
                    sidebar.classList.add('collapsed');
                    document.getElementById('mainContent').classList.add('expanded');
                }
            }
        });

        // Tab switching
        function showTab(tabId) {
            // Remove active from all tabs and nav links
            document.querySelectorAll('.admin-tab-pane').forEach(pane => pane.classList.remove('active'));
            document.querySelectorAll('.nav-link').forEach(link => link.classList.remove('active'));
            
            // Add active to selected tab and nav link
            document.getElementById(tabId).classList.add('active');
            event.target.closest('.nav-link').classList.add('active');
            
            // Close sidebar on mobile after navigation
            if (window.innerWidth <= 768) {
                toggleSidebar();
            }
        }

        // Close / open user modal
        function closeUserModal() {
            document.getElementById('userDetailsModal').style.display = 'none';
            document.getElementById('ud_content').innerHTML = '';
        }

        // Fetch user details via AJAX and show modal
        function fetchUserDetails(userId) {
            fetch('admin.php?action=get_user&user_id=' + encodeURIComponent(userId), { credentials: 'same-origin' })
                .then(r => r.json())
                .then(data => {
                    if (!data || !data.success) {
                        alert('Failed to load user details');
                        return;
                    }

                    const p = data.profile || {};
                    let html = '';
                    html += '<section style="margin-bottom:1rem;">';
                    html += '<h4 style="margin:0 0 0.5rem 0; color:#ffd700">Profile</h4>';
                    html += '<div><strong>ID:</strong> ' + (p.id||'') + '</div>';
                    html += '<div><strong>Name:</strong> ' + (p.name||'') + '</div>';
                    html += '<div><strong>Phone:</strong> ' + (p.phone||'') + '</div>';
                    html += '<div><strong>Referral:</strong> ' + (p.referral_code||'') + '</div>';
                    html += '<div><strong>Balance:</strong> ₹' + (p.current_balance!==null ? Number(p.current_balance).toFixed(2) : '0.00') + '</div>';
                    html += '<div><strong>Total Invested:</strong> ₹' + (p.total_invested!==null ? Number(p.total_invested).toFixed(2) : '0.00') + '</div>';
                    html += '<div><strong>Joined:</strong> ' + (p.created_at ? new Date(p.created_at).toLocaleString() : '') + '</div>';
                    html += '</section>';

                    // Investments
                    html += '<section style="margin-bottom:1rem;">';
                    html += '<h4 style="margin:0 0 0.5rem 0; color:#ffd700">Investments</h4>';
                    if (data.investments && data.investments.length) {
                        html += '<table style="width:100%; border-collapse:collapse;">';
                        html += '<thead><tr style="color:#94a3b8"><th>Plan</th><th>Amount</th><th>ROI%</th><th>Status</th><th>Start</th><th>End</th></tr></thead>';
                        html += '<tbody>';
                        data.investments.forEach(inv => {
                            html += '<tr style="border-top:1px solid rgba(255,255,255,0.04);">';
                            html += '<td>' + (inv.plan_type||'') + '</td>';
                            html += '<td>₹' + Number(inv.amount||0).toFixed(2) + '</td>';
                            html += '<td>' + (inv.roi_percentage||0) + '%</td>';
                            html += '<td>' + (inv.status||'') + '</td>';
                            html += '<td>' + (inv.created_at ? new Date(inv.created_at).toLocaleString() : '') + '</td>';
                            html += '<td>' + (inv.maturity_date ? new Date(inv.maturity_date).toLocaleString() : 'N/A') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<div class="no-data">No investments</div>';
                    }
                    html += '</section>';

                    // Transactions
                    html += '<section style="margin-bottom:1rem;">';
                    html += '<h4 style="margin:0 0 0.5rem 0; color:#ffd700">Transactions</h4>';
                    if (data.transactions && data.transactions.length) {
                        html += '<table style="width:100%; border-collapse:collapse;">';
                        html += '<thead><tr style="color:#94a3b8"><th>Type</th><th>Amount</th><th>Status</th><th>Date</th></tr></thead>';
                        html += '<tbody>';
                        data.transactions.forEach(t => {
                            html += '<tr style="border-top:1px solid rgba(255,255,255,0.04);">';
                            html += '<td>' + (t.transaction_type||'') + '</td>';
                            html += '<td>₹' + Number(t.amount||0).toFixed(2) + '</td>';
                            html += '<td>' + (t.status||'') + '</td>';
                            html += '<td>' + (t.created_at ? new Date(t.created_at).toLocaleString() : '') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<div class="no-data">No transactions</div>';
                    }
                    html += '</section>';

                    // Withdrawals
                    html += '<section style="margin-bottom:1rem;">';
                    html += '<h4 style="margin:0 0 0.5rem 0; color:#ffd700">Withdrawals</h4>';
                    if (data.withdrawals && data.withdrawals.length) {
                        html += '<table style="width:100%; border-collapse:collapse;">';
                        html += '<thead><tr style="color:#94a3b8"><th>Amount</th><th>UPI Holder</th><th>UPI</th><th>Status</th><th>Date</th></tr></thead>';
                        html += '<tbody>';
                        data.withdrawals.forEach(w => {
                            html += '<tr style="border-top:1px solid rgba(255,255,255,0.04);">';
                            html += '<td>₹' + Number(w.amount||0).toFixed(2) + '</td>';
                            html += '<td>' + (w.upi_holder_name||'') + '</td>';
                            html += '<td>' + (w.upi_number||'') + '</td>';
                            html += '<td>' + (w.status||'') + '</td>';
                            html += '<td>' + (w.created_at ? new Date(w.created_at).toLocaleString() : '') + '</td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table>';
                    } else {
                        html += '<div class="no-data">No withdrawals</div>';
                    }
                    html += '</section>';

                    document.getElementById('ud_content').innerHTML = html;
                    document.getElementById('ud_name').textContent = (p.name ? p.name + ' — Details' : 'User Details');
                    document.getElementById('userDetailsModal').style.display = 'flex';
                })
                .catch(err => { console.error(err); alert('Failed to fetch user details'); });
        }

        // User search filter
        function filterUsers() {
            const input = document.getElementById('userSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('usersTableBody');
            const rows = table.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const textValue = cell.textContent || cell.innerText;
                        if (textValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }

        // Investment filter by status
        function filterInvestments(status) {
            const rows = document.querySelectorAll('#investmentsTableBody tr');
            const buttons = document.querySelectorAll('.filter-btn');
            
            // Update active button
            buttons.forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            // Filter rows
            rows.forEach(row => {
                if (status === 'all' || row.getAttribute('data-status') === status) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Investment search filter
        function filterInvestmentTable() {
            const input = document.getElementById('investmentSearchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('investmentsTableBody');
            const rows = table.getElementsByTagName('tr');

            for (let i = 0; i < rows.length; i++) {
                const cells = rows[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < cells.length; j++) {
                    const cell = cells[j];
                    if (cell) {
                        const textValue = cell.textContent || cell.innerText;
                        if (textValue.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                rows[i].style.display = found ? '' : 'none';
            }
        }

        // Logout function
        function logout() {
            if (confirm('🚪 Are you sure you want to logout?')) {
                window.location.href = 'index.php?logout=1';
            }
        }
    </script>
</body>
</html>