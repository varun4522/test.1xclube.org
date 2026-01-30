//
<?php
session_start();
require_once 'config.php';

//  logout request (supports redirects from admin.php and home.php)
if (isset($_GET['logout']) && $_GET['logout']) {
    // Clear session data
    $_SESSION = [];

    // Clear session cookie if used
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
    header('Location: index.php');
    exit();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: home.php');
    exit();
}

$error = '';
$success = '';

// Check for signup success message
if (isset($_SESSION['signup_success'])) {
    $success = $_SESSION['signup_success'];
    unset($_SESSION['signup_success']);
}

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    
    // Check for admin credentials
    // Use environment variables for security
    $adminPhone = getenv('ADMIN_PHONE') ?: '9266563155';
    $adminPass = getenv('ADMIN_PASSWORD') ?: 'qwerty241302262uiop';
    
    if ($phone === $adminPhone && $password === $adminPass) {
        session_regenerate_id(true); // Prevent session fixation
        $_SESSION['user_id'] = 'admin';
        $_SESSION['is_admin'] = true;
        $_SESSION['admin_phone'] = $phone;
        header('Location: admin.php');
        exit();
    }
    
    // Validate input
    if (empty($phone) || empty($password)) {
        $error = 'Phone and password are required';
    } elseif (!preg_match('/^\d{10}$/', $phone)) {
        $error = 'Please enter a valid 10-digit phone number';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long';
    } else {
        // Get user from database using profiles table
        try {
            $conn = getDBConnection();
            
            // Query profiles table directly with plain password (id is the primary key now)
            $stmt = $conn->prepare("SELECT id, name, password 
                                   FROM profiles 
                                   WHERE phone = ? LIMIT 1");
            $stmt->bind_param("s", $phone);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                $error = 'Invalid phone or password';
            } else {
                $user = $result->fetch_assoc();
                
                if ($password !== $user['password']) {
                    $error = 'Invalid phone or password';
                } else {
                    // Login successful - regenerate session ID to prevent session fixation
                    session_regenerate_id(true);
                    
                    // Set session variables (id is now the user identifier)
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['phone'] = $phone;
                    
                    header('Location: home.php');
                    exit();
                }
            }
            
            $stmt->close();
            $conn->close();
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = 'A database connection error occurred. Please try again later.';
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
    <title>Login - 1x Club</title>
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

        .forgot-password {
            text-align: right;
            margin-bottom: 1.5rem;
        }

        .forgot-password a {
            color: #ffd700;
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }

        .forgot-password a:hover {
            text-decoration: underline;
            color: #ffed4e;
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
            <h1>Welcome Back! 👋</h1>
            <p>Login to access your investment dashboard</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-group">
                <label for="phone">📱 Phone Number</label>
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
                <label for="password">🔒 Password</label>
                <div class="password-input-wrapper">
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        placeholder="Enter your password" 
                        required
                        minlength="6"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword()">
                        👁️
                    </button>
                </div>
            </div>
            
            <div class="forgot-password">
                <a href="#" onclick="alert('Forgot password functionality would be implemented here'); return false;">Forgot Password?</a>
            </div>
            
            <button type="submit" class="login-btn">Login to Dashboard</button>
        </form>

        <div class="divider">or</div>
        
        <div class="signup-link">
            Don't have an account? <a href="signup.php">Create Account</a>
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
function togglePassword() {
    const field = document.getElementById('password');
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
