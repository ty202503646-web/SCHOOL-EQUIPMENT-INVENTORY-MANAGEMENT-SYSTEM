
<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit;
}

$error = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";
    } else {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $stmt = $conn->prepare(
            "SELECT u.user_id, u.full_name, u.username,
                    u.password, u.role_id, u.account_status,
                    r.role_name
             FROM users u
             INNER JOIN roles r ON u.role_id = r.role_id
             WHERE u.username = ?
             LIMIT 1"
        );

        $stmt->bind_param("s", $username);
        $stmt->execute();

        $result = $stmt->get_result();
        $user = $result->fetch_assoc();
        $stmt->close();

        if (
            $user &&
            $user['account_status'] === 'Active' &&
            password_verify($password, $user['password'])
        ) {

            session_regenerate_id(true);

            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['role_name'] = $user['role_name'];

            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            header("Location: dashboard.php");
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
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | School Equipment Inventory</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            min-height: 100vh;
            background: #f1f5f2;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: Arial, sans-serif;
        }

        .login-card {
            width: 100%;
            max-width: 430px;
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 5px 25px rgba(0,0,0,.08);
        }

        .brand {
            color: #14532d;
        }

        .btn-green {
            background: #14532d;
            color: white;
            border: none;
        }

        .btn-green:hover {
            background: #166534;
            color: white;
        }

        .form-control {
            padding: 11px;
        }
    </style>
</head>

<body>

<div class="login-card">

    <div class="text-center mb-4">
        <h3 class="brand fw-bold">SEIMS</h3>

        <h5 class="fw-bold mt-3">
            School Equipment Inventory
        </h5>

        <p class="text-muted">
            Monitoring System
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">

        <input type="hidden" name="csrf_token"
            value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

        <div class="mb-3">
            <label class="form-label fw-semibold">Username</label>
            <input type="text" name="username"
                class="form-control"
                placeholder="Enter your username"
                autocomplete="username"
                required>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold">Password</label>
            <input type="password" name="password"
                class="form-control"
                placeholder="Enter your password"
                autocomplete="current-password"
                required>
        </div>

        <button type="submit" class="btn btn-green w-100 py-2">
            Login
        </button>

    </form>

    <p class="text-center text-muted mt-4 mb-0">
        School Equipment Inventory Monitoring System
    </p>

</div>

</body>
</html>
