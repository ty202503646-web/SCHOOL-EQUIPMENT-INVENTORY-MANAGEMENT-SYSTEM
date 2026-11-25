
<?php
require_once 'includes/auth.php';
requireLogin();

$fullName = htmlspecialchars($_SESSION['full_name']);
$roleName = htmlspecialchars($_SESSION['role_name']);
$token = $_SESSION['csrf_token'] ?? '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dashboard | SEIMS</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background: #f4f7f5;
            font-family: Arial, sans-serif;
        }

        .sidebar {
            background: #14532d;
            min-height: 100vh;
            color: white;
        }

        .sidebar a {
            display: block;
            color: white;
            text-decoration: none;
            padding: 13px 20px;
            border-radius: 7px;
            margin-bottom: 5px;
        }

        .sidebar a:hover {
            background: #166534;
        }

        .main-content {
            padding: 30px;
        }

        .stat-card {
            border: none;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0,0,0,.04);
        }

        .brand {
            color: #14532d;
        }
    </style>
</head>

<body>

<div class="container-fluid">
    <div class="row">

        <!-- SIDEBAR -->
        <div class="col-md-3 col-lg-2 sidebar p-3">

            <h5 class="fw-bold text-center py-3">
                SEIMS
            </h5>

            <hr>

            <a href="dashboard.php">Dashboard</a>

            <?php if ($roleName !== 'Viewer'): ?>
                <a href="equipment.php">Equipment Management</a>
                <a href="categories.php">Categories</a>
                <a href="locations.php">Locations</a>
                <a href="assignments.php">Assignments</a>
                <a href="verification.php">Inventory Verification</a>
            <?php endif; ?>

            <a href="reports.php">Reports</a>

            <?php if ($roleName === 'Administrator'): ?>
                <a href="users.php">User Management</a>
            <?php endif; ?>

            <hr>

            <form action="logout.php" method="POST">
                <input type="hidden" name="csrf_token"
                    value="<?= htmlspecialchars($token) ?>">
                <button type="submit" class="btn btn-outline-light w-100">
                    Logout
                </button>
            </form>

        </div>

        <!-- MAIN CONTENT -->
        <div class="col-md-9 col-lg-10 main-content">

            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h3 class="brand fw-bold">Dashboard</h3>
                    <p class="text-muted mb-0">
                        School Equipment Inventory Monitoring System
                    </p>
                </div>

                <div class="text-end">
                    <strong><?= $fullName ?></strong>
                    <br>
                    <span class="badge bg-success">
                        <?= $roleName ?>
                    </span>
                </div>
            </div>

            <div class="alert alert-success">
                Welcome, <?= $fullName ?>!
                You have successfully logged in to SEIMS.
            </div>

            <h5 class="fw-bold mb-3">Inventory Overview</h5>

            <div class="row g-3">

                <div class="col-md-6 col-xl-3">
                    <div class="card stat-card">
                        <h6 class="text-muted">Total Equipment</h6>
                        <h2 class="brand fw-bold">0</h2>
                        <small>Registered equipment</small>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="card stat-card">
                        <h6 class="text-muted">Available</h6>
                        <h2 class="text-success fw-bold">0</h2>
                        <small>Available equipment</small>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="card stat-card">
                        <h6 class="text-muted">Assigned</h6>
                        <h2 class="text-primary fw-bold">0</h2>
                        <small>Assigned equipment</small>
                    </div>
                </div>

                <div class="col-md-6 col-xl-3">
                    <div class="card stat-card">
                        <h6 class="text-muted">Under Maintenance</h6>
                        <h2 class="text-warning fw-bold">0</h2>
                        <small>Equipment for repair</small>
                    </div>
                </div>

            </div>

            <div class="card stat-card mt-4">
                <h5 class="brand fw-bold">System Information</h5>
                <p class="text-muted mb-1">
                    Welcome to the School Equipment Inventory Monitoring System.
                </p>
                <p class="text-muted mb-0">
                    Manage, monitor, verify, and track school equipment records
                    in one centralized system.
                </p>
            </div>

        </div>
    </div>
</div>

</body>
</html>
