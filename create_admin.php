
<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

$countResult = $conn->query("SELECT COUNT(*) AS total FROM users");
$userCount = (int) $countResult->fetch_assoc()['total'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $userCount === 0) {

    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = "Invalid request. Please refresh the page.";
    } elseif (
        $fullName === '' ||
        !preg_match('/^[a-zA-Z0-9_]{4,50}$/', $username) ||
        strlen($password) < 10
    ) {
        $error = "Please check your details. Username must be 4-50 characters and password at least 10 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        $roleStmt = $conn->prepare(
            "SELECT role_id FROM roles WHERE role_name = 'Administrator' LIMIT 1"
        );
        $roleStmt->execute();
        $role = $roleStmt->get_result()->fetch_assoc();
        $roleStmt->close();

        if (!$role) {
            $error = "Administrator role was not found.";
        } else {
            $roleId = (int) $role['role_id'];
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare(
                "INSERT INTO users
                (role_id, full_name, username, email, password)
                VALUES (?, ?, ?, NULLIF(?, ''), ?)"
            );

            $stmt->bind_param(
                "issss",
                $roleId,
                $fullName,
                $username,
                $email,
                $hashedPassword
            );

            try {
                if ($stmt->execute()) {
                    $success = "Administrator account created successfully!";
                    $userCount = 1;
                } else {
                    $error = "Unable to create account.";
                }
            } catch (mysqli_sql_exception $e) {
                $error = "Username or email may already exist.";
            }

            $stmt->close();
        }
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Administrator</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f1f5f2;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .register-card {
            width: 100%;
            max-width: 480px;
            padding: 35px;
            border-radius: 15px;
            background: white;
            box-shadow: 0 5px 25px rgba(0,0,0,.08);
        }
        .brand { color: #14532d; }
    </style>
</head>
<body>

<div class="register-card">
    <h3 class="brand fw-bold text-center">School Equipment Inventory</h3>
    <p class="text-muted text-center mb-4">Initial Administrator Setup</p>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($success) ?>
            <br><a href="login.php">Proceed to Login</a>
        </div>
    <?php endif; ?>

    <?php if ($userCount === 0): ?>

    <form method="POST">
        <input type="hidden" name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" name="full_name" class="form-control"
                required maxlength="150">
        </div>

        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" name="username" class="form-control"
                required minlength="4" maxlength="50"
                pattern="[a-zA-Z0-9_]+">
        </div>

        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control"
                maxlength="100">
        </div>

        <div class="mb-3">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control"
                required minlength="10">
        </div>

        <div class="mb-3">
            <label class="form-label">Confirm Password</label>
            <input type="password" name="confirm_password"
                class="form-control" required minlength="10">
        </div>

        <button type="submit" class="btn btn-success w-100">
            Create Administrator Account
        </button>
    </form>

    <?php else: ?>
        <div class="alert alert-info">
            An account already exists. Initial setup is closed.
        </div>
        <a href="login.php" class="btn btn-success w-100">Go to Login</a>
    <?php endif; ?>
</div>

</body>
</html>
