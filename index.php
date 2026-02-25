<?php

require_once 'config.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = "Please fill in both fields.";
    } else {
        $stmt = $pdo->prepare("SELECT id, username, fullname, role, password, status FROM users WHERE username = ? AND (role = 'admin' OR role = 'teacher')");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && $user['status'] == 'active' && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];

            // Redirect based on role
            if ($user['role'] === 'admin') {
                header("Location: admin_dashboard.php");
            } else if ($user['role'] === 'teacher') {
                header("Location: teacher_dashboard.php");
            } else {
                header("Location: index.php");
            }
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Login - School Management</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: linear-gradient(135deg, #1a2a6c, #b21f1f, #1a2a6c);
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
        }

        .login-container {
            background: #fff;
            padding: 2.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 480px;
            width: 100%;
        }

        .login-container h2,h3,
        p {
            text-align: center;
            margin-bottom: 1.2rem;
            color: #1a2a6c;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            font-weight: 600;
        }

        .form-group input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ccc;
            border-radius: 6px;
            font-size: 1rem;
        }

        .btn {
            width: 100%;
            padding: 0.85rem;
            background: #1a2a6c;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1.05rem;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn:hover {
            background: #0f1d4d;
        }

        .error {
            color: #d32f2f;
            text-align: center;
            margin-bottom: 1rem;
            font-weight: 500;
        }

        .forgot-password {
            text-align: center;
            margin-top: 0.8rem;
        }

        .forgot-password a {
            color: #1a2a6c;
            text-decoration: none;
            font-size: 0.95rem;
        }

        .forgot-password a:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <div class="login-container">
        <h2>NAPAK SEED SECONDARY SCHOOL</h2>
        <h3>Welcome Back!</h3>
        <p>Login into Your Account</p>
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autocomplete="off" />
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="off" />
            </div>
            <button type="submit" class="btn">Login</button>
            <div class="forgot-password">
                <a href="reset_password.php">Forgot Password?</a>
            </div>
        </form>
    </div>
</body>

</html>