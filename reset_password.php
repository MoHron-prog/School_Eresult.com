<?php
// reset_password.php - Password Reset Module
require_once 'config.php';

// Prevent direct access without POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // Clear any existing session messages
    if (isset($_SESSION['reset_message'])) unset($_SESSION['reset_message']);
    if (isset($_SESSION['reset_error'])) unset($_SESSION['reset_error']);
}

$success_message = '';
$error_message = '';

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Initialize validation errors array
    $errors = [];

    // Validate email
    if (empty($email)) {
        $errors[] = "Email address is required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate passwords
    if (empty($new_password)) {
        $errors[] = "New password is required.";
    } elseif (strlen($new_password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
        $errors[] = "Password must contain at least one uppercase letter, one lowercase letter, and one number.";
    }

    if (empty($confirm_password)) {
        $errors[] = "Please confirm your new password.";
    } elseif ($new_password !== $confirm_password) {
        $errors[] = "Passwords do not match.";
    }

    // If no validation errors, proceed with database operations
    if (empty($errors)) {
        try {
            // Check if email exists in database
            $stmt = $pdo->prepare("SELECT id, email FROM users WHERE email = ? AND status = 'active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $errors[] = "User account with this email does not exist or is inactive.";
            } else {
                // Hash the new password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                // Update password in database
                $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
                $result = $update_stmt->execute([$hashed_password, $email]);

                if ($result) {
                    // Log the password reset activity
                    $log_stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
                    $log_stmt->execute([
                        $user['id'],
                        'PASSWORD_RESET',
                        'Password reset via reset password form',
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
                    ]);

                    $success_message = "Password has been reset successfully. You can now <a href='index.php' class='alert-link'>log in</a>.";

                    // Clear form data
                    $email = $new_password = $confirm_password = '';
                } else {
                    $errors[] = "Failed to update password. Please try again.";
                }
            }
        } catch (PDOException $e) {
            // Log the error securely
            error_log("Password reset error: " . $e->getMessage());
            $errors[] = "A system error occurred. Please try again later.";
        }
    }

    // Combine errors into a single message
    if (!empty($errors)) {
        $error_message = implode("<br>", $errors);
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - School Management System</title>

    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        body {
            background: linear-gradient(135deg, #1a2a6c, #b21f1f, #1a2a6c);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .reset-container {
            background-color: white;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            max-width: 450px;
            width: 100%;
            overflow: hidden;
            line-height: 0.9;
        }

        .reset-header {
            background: #1a2a6c;
            color: white;
            padding: 5px 10px;
            text-align: center;

        }

        .reset-header h2 {
            font-size: 1.6em;
            margin: 0;
            font-weight: 600;
        }

        .reset-header p {
            margin: 10px 0 10px;
            opacity: 0.9;
        }

        .reset-body {
            padding: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
        }

        .form-control {
            padding: 12px 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #1a2a6c;
            box-shadow: 0 0 0 0.25rem rgba(26, 42, 108, 0.25);
        }

        .password-strength {
            height: 5px;
            margin-top: 5px;
            border-radius: 3px;
            transition: all 0.3s;
        }

        .password-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
        }

        .btn-reset {
            background: #1a2a6c;
            color: white;
            border: none;
            padding: 10px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-reset:hover {
            background: #e02b0f;
            transform: translateY(-2px);
        }

        .btn-back {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-back:hover {
            background: #5a6268;
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
        }

        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #666;
            cursor: pointer;
        }

        .input-group {
            position: relative;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }

        .login-link a {
            color: #1a2a6c;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .password-criteria {
            list-style-type: none;
            padding-left: 0;
            font-size: 0.85rem;
        }

        .password-criteria li {
            margin-bottom: 5px;
        }

        .password-criteria i {
            margin-right: 8px;
        }

        .valid-criteria {
            color: #28a745;
        }

        .invalid-criteria {
            color: #dc3545;
        }
    </style>
</head>

<body>
    <div class="reset-container">
        <div class="reset-header">
            <h2><i class="fas fa-key me-2"></i>Reset Password</h2>
            <p>Enter your email and new password</p>
        </div>

        <div class="reset-body">
            <?php if ($success_message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php echo $success_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php echo $error_message; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <form id="resetPasswordForm" method="POST" action="" autocomplete="off">
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <input type="email"
                        class="form-control"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars($email ?? ''); ?>"
                        placeholder="Enter your registered email"
                        required>
                    <div class="form-text">Enter the email address associated with your account.</div>
                </div>

                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <div class="input-group">
                        <input type="password"
                            class="form-control"
                            id="new_password"
                            name="new_password"
                            placeholder="Enter new password"
                            required>
                        <button type="button" class="password-toggle" id="toggleNewPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="password-strength" id="passwordStrength"></div>

                    <div class="password-requirements">
                        <small>Password must be at least 8 characters and include:</small>
                        <ul class="password-criteria mt-2">
                            <li id="criteria-length"><i class="fas fa-check-circle valid-criteria" id="icon-length"></i> At least 8 characters</li>
                            <li id="criteria-uppercase"><i class="fas fa-check-circle valid-criteria" id="icon-uppercase"></i> One uppercase letter</li>
                            <li id="criteria-lowercase"><i class="fas fa-check-circle valid-criteria" id="icon-lowercase"></i> One lowercase letter</li>
                            <li id="criteria-number"><i class="fas fa-check-circle valid-criteria" id="icon-number"></i> One number</li>
                        </ul>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password"
                            class="form-control"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Confirm new password"
                            required>
                        <button type="button" class="password-toggle" id="toggleConfirmPassword">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                    <div class="form-text" id="passwordMatchMessage"></div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn-reset">
                        <i class="fas fa-sync-alt me-2"></i>Reset Password
                    </button>
                    <a href="index.php" class="btn btn-back">
                        <i class="fas fa-arrow-left me-2"></i>Back to Login
                    </a>
                </div>
            </form>

            <div class="login-link">
                Remember your password? <a href="index.php">Log in here</a>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Custom JavaScript for form validation -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const newPasswordInput = document.getElementById('new_password');
            const confirmPasswordInput = document.getElementById('confirm_password');
            const toggleNewPasswordBtn = document.getElementById('toggleNewPassword');
            const toggleConfirmPasswordBtn = document.getElementById('toggleConfirmPassword');
            const passwordStrengthBar = document.getElementById('passwordStrength');
            const passwordMatchMessage = document.getElementById('passwordMatchMessage');
            const form = document.getElementById('resetPasswordForm');

            // Password visibility toggle
            toggleNewPasswordBtn.addEventListener('click', function() {
                const type = newPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                newPasswordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });

            toggleConfirmPasswordBtn.addEventListener('click', function() {
                const type = confirmPasswordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                confirmPasswordInput.setAttribute('type', type);
                this.innerHTML = type === 'password' ? '<i class="fas fa-eye"></i>' : '<i class="fas fa-eye-slash"></i>';
            });

            // Password strength checker
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                let strength = 0;

                // Update criteria icons
                updateCriteriaIcon('length', password.length >= 8);
                updateCriteriaIcon('uppercase', /[A-Z]/.test(password));
                updateCriteriaIcon('lowercase', /[a-z]/.test(password));
                updateCriteriaIcon('number', /[0-9]/.test(password));

                // Calculate strength
                if (password.length >= 8) strength += 25;
                if (/[A-Z]/.test(password)) strength += 25;
                if (/[a-z]/.test(password)) strength += 25;
                if (/[0-9]/.test(password)) strength += 25;

                // Update strength bar
                passwordStrengthBar.style.width = strength + '%';

                if (strength < 50) {
                    passwordStrengthBar.style.backgroundColor = '#dc3545';
                } else if (strength < 75) {
                    passwordStrengthBar.style.backgroundColor = '#ffc107';
                } else {
                    passwordStrengthBar.style.backgroundColor = '#28a745';
                }

                // Check password match
                checkPasswordMatch();
            });

            // Password match checker
            confirmPasswordInput.addEventListener('input', checkPasswordMatch);

            function checkPasswordMatch() {
                const password = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;

                if (confirmPassword === '') {
                    passwordMatchMessage.textContent = '';
                    passwordMatchMessage.className = 'form-text';
                    return;
                }

                if (password === confirmPassword) {
                    passwordMatchMessage.innerHTML = '<i class="fas fa-check-circle text-success me-1"></i> Passwords match';
                    passwordMatchMessage.className = 'form-text text-success';
                } else {
                    passwordMatchMessage.innerHTML = '<i class="fas fa-times-circle text-danger me-1"></i> Passwords do not match';
                    passwordMatchMessage.className = 'form-text text-danger';
                }
            }

            function updateCriteriaIcon(criteriaId, isValid) {
                const icon = document.getElementById(`icon-${criteriaId}`);
                const criteriaItem = document.getElementById(`criteria-${criteriaId}`);

                if (isValid) {
                    icon.className = 'fas fa-check-circle valid-criteria';
                    criteriaItem.classList.add('valid-criteria');
                    criteriaItem.classList.remove('invalid-criteria');
                } else {
                    icon.className = 'fas fa-times-circle invalid-criteria';
                    criteriaItem.classList.add('invalid-criteria');
                    criteriaItem.classList.remove('valid-criteria');
                }
            }

            // Form validation
            form.addEventListener('submit', function(event) {
                let valid = true;
                const email = document.getElementById('email').value;
                const password = newPasswordInput.value;
                const confirmPassword = confirmPasswordInput.value;

                // Email validation
                if (!email || !validateEmail(email)) {
                    showFieldError('email', 'Please enter a valid email address.');
                    valid = false;
                } else {
                    clearFieldError('email');
                }

                // Password validation
                if (password.length < 8) {
                    showFieldError('new_password', 'Password must be at least 8 characters.');
                    valid = false;
                } else if (!/[A-Z]/.test(password) || !/[a-z]/.test(password) || !/[0-9]/.test(password)) {
                    showFieldError('new_password', 'Password must include uppercase, lowercase, and number.');
                    valid = false;
                } else {
                    clearFieldError('new_password');
                }

                // Confirm password validation
                if (password !== confirmPassword) {
                    showFieldError('confirm_password', 'Passwords do not match.');
                    valid = false;
                } else {
                    clearFieldError('confirm_password');
                }

                if (!valid) {
                    event.preventDefault();
                    event.stopPropagation();
                }
            });

            function validateEmail(email) {
                const re = /^(([^<>()\[\]\\.,;:\s@"]+(\.[^<>()\[\]\\.,;:\s@"]+)*)|(".+"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
                return re.test(String(email).toLowerCase());
            }

            function showFieldError(fieldId, message) {
                const field = document.getElementById(fieldId);
                const group = field.closest('.mb-3') || field.closest('.mb-4');

                // Remove existing error
                clearFieldError(fieldId);

                // Add error class to input
                field.classList.add('is-invalid');

                // Add error message
                const errorDiv = document.createElement('div');
                errorDiv.className = 'invalid-feedback';
                errorDiv.textContent = message;
                group.appendChild(errorDiv);
            }

            function clearFieldError(fieldId) {
                const field = document.getElementById(fieldId);
                const group = field.closest('.mb-3') || field.closest('.mb-4');

                // Remove error class
                field.classList.remove('is-invalid');

                // Remove error message
                const existingError = group.querySelector('.invalid-feedback');
                if (existingError) {
                    existingError.remove();
                }
            }
        });
    </script>
</body>

</html>