<?php
session_start();
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: home.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $name     = trim($_POST['name'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';
    $refCode  = trim($_POST['referral_code'] ?? '');

    if ($name === '' || $phone === '' || $password === '') {
        $error = 'All fields are required';
    } elseif (!preg_match('/^\d{10}$/', $phone)) {
        $error = 'Invalid phone number';
    } elseif (strlen($password) < 6) {
        $error = 'Password too short';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match';
    } else {

        try {
            $conn = getDBConnection();

            // Phone check
            if ($error === '') {
                $stmt = $conn->prepare("SELECT id FROM profiles WHERE phone=?");
                $stmt->bind_param("s", $phone);
                $stmt->execute();
                $stmt->store_result();

                if ($stmt->num_rows > 0) {
                    $error = 'Phone already registered';
                }
                $stmt->close();
            }

            // Referral check
            $referrerId = null;
            if ($error === '' && $refCode !== '') {
                $stmt = $conn->prepare("SELECT id FROM profiles WHERE referral_code=?");
                $stmt->bind_param("s", $refCode);
                $stmt->execute();
                $res = $stmt->get_result();

                if ($res->num_rows === 0) {
                    $error = 'Invalid referral code';
                } else {
                    $refRow = $res->fetch_assoc();
                    $referrerId = isset($refRow['id']) ? (int)$refRow['id'] : null;
                }
                $stmt->close();
            }

            if ($error === '') {
                $conn->begin_transaction();

                $newRef = generateUniqueReferralCode($conn);
                $uniqueId = generateUnique4DigitId($conn); // This generates the profiles.id

                // 1. Insert into profiles table
                if ($referrerId === null) {
                    $stmt = $conn->prepare(
                        "INSERT INTO profiles (id,name,phone,password,referral_code,referred_by)
                         VALUES (?,?,?,?,?,NULL)"
                    );
                    if (!$stmt) {
                        throw new Exception("Prepare failed (profiles no-ref): " . $conn->error);
                    }
                    $stmt->bind_param("issss", $uniqueId, $name, $phone, $password, $newRef);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare(
                        "INSERT INTO profiles (id,name,phone,password,referral_code,referred_by)
                         VALUES (?,?,?,?,?,?)"
                    );
                    if (!$stmt) {
                        throw new Exception("Prepare failed (profiles with-ref): " . $conn->error);
                    }
                    $stmt->bind_param("issssi", $uniqueId, $name, $phone, $password, $newRef, $referrerId);
                    $stmt->execute();
                }
                $stmt->close();

                // Assign profiles.id to userId for wallet creation
                $userId = $uniqueId;

                // No separate user_wallet table: profiles holds balance columns.
                // Ensure new user has default zeroed balance columns (if columns exist they have defaults from migration).

                // 3. Process Referral Bonus (if applicable)
                if ($referrerId) {
                    $bonus = 50.00;
                    
                    // Update referrer's profiles balance fields
                    $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance + ?, total_earned = total_earned + ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ddi", $bonus, $bonus, $referrerId);
                        $stmt->execute();
                        $stmt->close();
                    }

                    // Log bonus transaction
                    $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, amount, transaction_type, status) VALUES (?, ?, 'bonus', 'completed')");
                    if (!$stmt) {
                        throw new Exception("Prepare failed (transaction_history): " . $conn->error);
                    }
                    $stmt->bind_param("id", $referrerId, $bonus);
                    $stmt->execute();
                    $stmt->close();

                    // Also update referrer's aggregate fields on profiles (if columns exist)
                    $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance + ?, total_earned = total_earned + ? WHERE id = ?");
                    if ($stmt) {
                        $stmt->bind_param("ddi", $bonus, $bonus, $referrerId);
                        $stmt->execute();
                        $stmt->close();
                    }
                }

                // 4. Process Signup Bonus for the new user
                $signupBonus = 50.00;
                
                // Update the NEW user's balance fields on profiles
                $stmt = $conn->prepare("UPDATE profiles SET current_balance = current_balance + ?, total_earned = total_earned + ? WHERE id = ?");
                if ($stmt) {
                    $stmt->bind_param("ddi", $signupBonus, $signupBonus, $userId);
                    $stmt->execute();
                    $stmt->close();
                }

                // Log signup bonus transaction
                $stmt = $conn->prepare("INSERT INTO transaction_history (user_id, amount, transaction_type, status) VALUES (?, ?, 'bonus', 'completed')");
                if ($stmt) {
                    $stmt->bind_param("id", $userId, $signupBonus);
                    $stmt->execute();
                    $stmt->close();
                }

                $conn->commit();

                // Redirect to login page after successful signup
                session_regenerate_id(true);
                $_SESSION['signup_success'] = 'Account created successfully! Please login.';
                header('Location: index.php');
                exit;
            }

        } catch (Exception $e) {
            if (isset($conn)) $conn->rollback();
            $error = 'Signup failed: ' . $e->getMessage();
            error_log('Signup error: ' . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Create Account - 1x Club</title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            color: #f5f5f5;
        }

        .login-container {
            background: rgba(26, 26, 26, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 3rem;
            max-width: 450px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 215, 0, 0.3);
        }

        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
        }

        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }

        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            color: #ffd700;
            margin-bottom: 0.5rem;
        }

        .login-header p {
            color: #e5e5e5;
            font-size: 0.95rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-size: 0.9rem;
        }

        .alert-error {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
        }

        .alert-success {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            color: #86efac;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #e5e5e5;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input {
            width: 100%;
            padding: 0.875rem 1rem;
            border: 2px solid rgba(255, 215, 0, 0.3);
            border-radius: 10px;
            font-size: 1rem;
            transition: all 0.2s;
            background: rgba(45, 45, 45, 0.8);
            color: #f5f5f5;
        }

        .form-group input:focus {
            outline: none;
            border-color: #ffd700;
            box-shadow: 0 0 0 3px rgba(255, 215, 0, 0.2);
            background: rgba(45, 45, 45, 0.9);
        }

        .password-input-wrapper {
            position: relative;
        }

        .password-input-wrapper input {
            padding-right: 3rem;
        }

        .toggle-password {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1.25rem;
            opacity: 0.7;
            transition: opacity 0.2s;
            color: #e5e5e5;
        }

        .toggle-password:hover {
            opacity: 1;
        }

        .login-btn {
            width: 100%;
            padding: 1rem;
            background: linear-gradient(135deg, #ffd700 0%, #f59e0b 100%);
            color: #000000;
            border: none;
            border-radius: 10px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 215, 0, 0.6);
            background: linear-gradient(135deg, #ffed4e 0%, #fbbf24 100%);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .signup-link {
            text-align: center;
            margin-top: 1.5rem;
            color: #e5e5e5;
            font-size: 0.9rem;
        }

        .signup-link a {
            color: #ffd700;
            text-decoration: none;
            font-weight: 600;
        }

        .signup-link a:hover {
            text-decoration: underline;
            color: #ffed4e;
        }

        .divider {
            text-align: center;
            margin: 1.5rem 0;
            color: #94a3b8;
            font-size: 0.875rem;
            position: relative;
        }

        .divider::before,
        .divider::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: rgba(255, 215, 0, 0.3);
        }

        .divider::before {
            left: 0;
        }

        .divider::after {
            right: 0;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255, 215, 0, 0.2);
        }

        .feature-item {
            text-align: center;
        }

        .feature-icon {
            font-size: 2rem;
        }

        @media (max-width: 768px) {
            body {
                padding: 0.5rem;
            }

            .login-container {
                padding: 2rem 1.5rem;
                max-width: 100%;
            }

            .logo {
                width: 60px;
                height: 60px;
            }

            .login-header h1 {
                font-size: 1.5rem;
            }

            .login-header p {
                font-size: 0.875rem;
            }

            .form-group input {
                padding: 0.75rem;
                font-size: 16px; /* Prevents zoom on iOS */
            }

            .login-btn {
                padding: 0.875rem;
                font-size: 1rem;
            }

            .feature-icon {
                font-size: 1.75rem;
            }
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem 1rem;
            }

            .login-header h1 {
                font-size: 1.25rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo-section">
            <img src="logo.svg" alt="1x Club Logo" class="logo">
        </div>

        <div class="login-header">
            <h1>Join 1x Club! 🚀</h1>
            <p>Create your account and start investing</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="signup.php">
            <div class="form-group">
                <label for="name">Full Name</label>
                <input 
                    type="text" 
                    id="name" 
                    name="name" 
                    placeholder="Enter your full name" 
                    required
                    value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                >
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input 
                    type="tel" 
                    id="phone" 
                    name="phone" 
                    placeholder="Enter your 10-digit phone number" 
                    required
                    pattern="[0-9]{10}"
                    value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>"
                >
            </div>
            
            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-input-wrapper">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter your password (min 6 characters)" 
                        required
                        minlength="6"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword('password')">
                        👁️
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <div class="password-input-wrapper">
                    <input 
                        type="password" 
                        id="confirm_password" 
                        name="confirm_password" 
                        placeholder="Confirm your password" 
                        required
                        minlength="6"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword('confirm_password')">
                        👁️
                    </button>
                </div>
            </div>

            <div class="form-group">
                <label for="referral_code">Referral Code (Optional)</label>
                <input 
                    type="text" 
                    id="referral_code" 
                    name="referral_code" 
                    placeholder="Enter referral code if you have one"
                    value="<?php echo isset($_POST['referral_code']) ? htmlspecialchars($_POST['referral_code']) : ''; ?>"
                >
            </div>
            
            <button type="submit" class="login-btn">Create Account</button>
        </form>

        <div class="divider">or</div>
        
        <div class="signup-link">
            Already have an account? <a href="index.php">Login</a>
        </div>

        <div class="features">
            <div class="feature-item">
                <div class="feature-icon">💰</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">🔒</div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">⚡</div>
            </div>
        </div>
    </div>

    <script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = event.currentTarget;
    if (field.type === 'password') {
        field.type = 'text';
        button.textContent = '🙈';
    } else {
        field.type = 'password';
        button.textContent = '👁️';
    }
}
    </script>
</body>
</html>