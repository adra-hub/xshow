<?php
require_once __DIR__ . '/config.php';

if (Config::isInstalled()) {
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    $errors = [];
    
    // Validate username
    if (strlen($username) < 3) {
        $errors[] = 'Username must be at least 3 characters long';
    }
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        $errors[] = 'Username can only contain letters, numbers, underscores, and hyphens';
    }
    
    // Validate password
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters long';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = 'Password must contain at least one uppercase letter';
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = 'Password must contain at least one lowercase letter';
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = 'Password must contain at least one number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = 'Password must contain at least one special character';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Passwords do not match';
    }
    
    if (empty($errors)) {
        try {
            if (Config::install($username, $password)) {
                // Create session for immediate login
                session_start();
                $_SESSION['authenticated'] = true;
                header('Location: index.php');
                exit;
            } else {
                $errors[] = 'Failed to save configuration';
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install XShow</title>
    <link rel="stylesheet" href="/xshow/assets/css/install.css">

</head>
<body>
    <div class="install-container">
        <div class="install-card">
            <div class="install-header">
                <h1>Install XShow</h1>
                <p>Create your administrator account</p>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="error-list">
                    <?php foreach($errors as $error): ?>
                        <div class="error-item"><?php echo htmlspecialchars($error); ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="install-form">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="relative">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            value="<?php echo htmlspecialchars($username ?? ''); ?>"
                            placeholder="Enter username" 
                            required
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="relative">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter password" 
                            required
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="relative">
                        <input 
                            type="password" 
                            id="confirm_password" 
                            name="confirm_password" 
                            placeholder="Confirm password" 
                            required
                            class="form-control"
                        >
                    </div>
                </div>

                <div class="password-requirements">
                    <h4>Password Requirements:</h4>
                    <ul>
                        <li>At least 8 characters long</li>
                        <li>Contains uppercase and lowercase letters</li>
                        <li>Contains numbers</li>
                        <li>Contains special characters</li>
                    </ul>
                </div>

                <button type="submit" class="install-btn">Install Now</button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.install-form');
        const password = document.getElementById('password');
        const confirmPassword = document.getElementById('confirm_password');
        const username = document.getElementById('username');

        form.addEventListener('submit', function(e) {
            let errors = [];

            // Username validation
            if (username.value.length < 3) {
                errors.push('Username must be at least 3 characters long');
            }
            if (!/^[a-zA-Z0-9_-]+$/.test(username.value)) {
                errors.push('Username can only contain letters, numbers, underscores, and hyphens');
            }

            // Password validation
            if (password.value.length < 8) {
                errors.push('Password must be at least 8 characters long');
            }
            if (!/[A-Z]/.test(password.value)) {
                errors.push('Password must contain at least one uppercase letter');
            }
            if (!/[a-z]/.test(password.value)) {
                errors.push('Password must contain at least one lowercase letter');
            }
            if (!/[0-9]/.test(password.value)) {
                errors.push('Password must contain at least one number');
            }
            if (!/[^A-Za-z0-9]/.test(password.value)) {
                errors.push('Password must contain at least one special character');
            }
            if (password.value !== confirmPassword.value) {
                errors.push('Passwords do not match');
            }

            if (errors.length > 0) {
                e.preventDefault();
                const errorList = document.querySelector('.error-list') || document.createElement('div');
                errorList.className = 'error-list';
                errorList.innerHTML = errors.map(error => `<div class="error-item">${error}</div>`).join('');
                
                if (!document.querySelector('.error-list')) {
                    form.insertBefore(errorList, form.firstChild);
                }
            }
        });
    });
    </script>
</body>
</html>