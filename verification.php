
<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireLogin();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role_name'] ?? '';
$canManage = in_array($role, ['Administrator', 'Inventory Staff'], true);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';

// ======================================
// SAVE VERIFICATION
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";

    } elseif (!$canManage) {
        http_response_code(403);
        exit("You are not authorized to perform inventory verification.");

    } else {

        $equipment_id = (int)($_POST['equipment_id'] ?? 0);
        $actual_condition = trim($_POST['actual_condition'] ?? '');
        $verification_status = trim($_POST['verification_status'] ?? '');
        $actual_location = trim($_POST['actual_location'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        $validConditions = [
            'Good',
            'Fair',
            'Poor',
            'Damaged'
        ];

        $validStatuses = [
            'Verified',
            'Missing',
            'Damaged',
            'Needs Repair'
        ];

        if (
            $equipment_id <= 0 ||
            !in_array($actual_condition, $validConditions, true) ||
            !in_array($verification_status, $validStatuses, true)
        ) {

            $error = "Please complete all required fields correctly.";

        } else {

            try {

                $verified_by = (int)$_SESSION['user_id'];
                $verification_date = date('Y-m-d');

                $stmt = $conn->prepare(
                    "SELECT equipment_id
                     FROM equipment
                     WHERE equipment_id = ?
                     AND archived_at IS NULL"
                );

                $stmt->bind_param("i", $equipment_id);
                $stmt->execute();

                $equipment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$equipment) {
                    throw new Exception("Equipment record not found.");
                }

                $stmt = $conn->prepare(
                    "INSERT INTO equipment_verifications
                    (
                        equipment_id,
                        verified_by,
                        verification_date,
                        actual_condition,
                        verification_status,
                        actual_location,
                        remarks
                    )
                    VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), NULLIF(?, ''))"
                );

                $stmt->bind_param(
                    "iisssss",
                    $equipment_id,
                    $verified_by,
                    $verification_date,
                    $actual_condition,
                    $verification_status,
                    $actual_location,
                    $remarks
                );

                $stmt->execute();
                $stmt->close();

                $success = "Inventory verification saved successfully!";

            } catch (Throwable $ex) {
                $error = $ex->getMessage();
            }
        }
    }
}

// ======================================
// LOAD EQUIPMENT
// ======================================

$equipmentList = $conn->query(
    "SELECT equipment_id, asset_tag, equipment_name, status
     FROM equipment
     WHERE archived_at IS NULL
     ORDER BY equipment_name"
);

// ======================================
// LOAD VERIFICATION HISTORY
// ======================================

$history = $conn->query(
    "SELECT
        v.*,
        e.asset_tag,
        e.equipment_name
     FROM equipment_verifications v
     INNER JOIN equipment e
        ON v.equipment_id = e.equipment_id
     ORDER BY v.created_at DESC"
);

// ======================================
// DASHBOARD COUNTS
// ======================================

$totalEquipment = $conn->query(
    "SELECT COUNT(*) AS total
     FROM equipment
     WHERE archived_at IS NULL"
)->fetch_assoc()['total'];

$totalVerified = $conn->query(
    "SELECT COUNT(*) AS total
     FROM (
        SELECT equipment_id
        FROM equipment_verifications
        GROUP BY equipment_id
     ) AS verified"
)->fetch_assoc()['total'];

$totalMissing = $conn->query(
    "SELECT COUNT(*) AS total
     FROM (
        SELECT equipment_id
        FROM equipment_verifications v1
        WHERE verification_id = (
            SELECT MAX(v2.verification_id)
            FROM equipment_verifications v2
            WHERE v2.equipment_id = v1.equipment_id
        )
        AND verification_status = 'Missing'
     ) AS missing"
)->fetch_assoc()['total'];

$totalPending = max(0, (int)$totalEquipment - (int)$totalVerified);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Inventory Verification | SEIMS</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
rel="stylesheet">

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

.sidebar a:hover,
.sidebar a.active {
    background: #166534;
}

.main-content {
    padding: 30px;
}

.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 3px 12px rgba(0,0,0,.04);
}

.brand {
    color: #14532d;
}

.btn-green {
    background: #14532d;
    color: white;
}

.btn-green:hover {
    background: #166534;
    color: white;
}

.stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #14532d;
}

</style>

</head>

<body>

<div class="container-fluid">
<div class="row">

<!-- SIDEBAR -->

<div class="col-md-3 col-lg-2 sidebar p-3">

<h5 class="fw-bold text-center py-3">SEIMS</h5>

<hr>

<a href="dashboard.php">Dashboard</a>
<a href="equipment.php">Equipment Management</a>
<a href="categories.php">Categories</a>
<a href="locations.php">Locations</a>
<a href="assignments.php">Assignments</a>
<a href="verification.php" class="active">Inventory Verification</a>
<a href="reports.php">Reports</a>

<?php if ($role === 'Administrator'): ?>
<a href="users.php">User Management</a>
<?php endif; ?>

<hr>

<form action="logout.php" method="POST">
<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<button class="btn btn-outline-light w-100">
Logout
</button>
</form>

</div>

<!-- MAIN CONTENT -->

<div class="col-md-9 col-lg-10 main-content">

<h3 class="brand fw-bold">Inventory Verification</h3>

<p class="text-muted">
Verify the physical existence, condition, and location of school equipment.
</p>

<!-- ALERTS -->

<?php if ($success): ?>
<div class="alert alert-success"><?= e($success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
<div class="alert alert-danger"><?= e($error) ?></div>
<?php endif; ?>

<!-- DASHBOARD -->

<div class="row g-3 mb-4">

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Total Equipment</small>
<div class="stat-number"><?= (int)$totalEquipment ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Verified Equipment</small>
<div class="stat-number"><?= (int)$totalVerified ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Pending Verification</small>
<div class="stat-number"><?= (int)$totalPending ?></div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Missing Equipment</small>
<div class="stat-number"><?= (int)$totalMissing ?></div>
</div>
</div>

</div>

<!-- VERIFICATION FORM -->

<?php if ($canManage): ?>

<div class="card p-4 mb-4">

<h5 class="brand fw-bold mb-3">
New Inventory Verification
</h5>

<form method="POST" action="verification.php">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<div class="row g-3">

<div class="col-md-6">

<label class="form-label fw-semibold">
Select Equipment *
</label>

<select name="equipment_id" class="form-select" required>

<option value="">Choose equipment</option>

<?php while ($eq = $equipmentList->fetch_assoc()): ?>

<option value="<?= (int)$eq['equipment_id'] ?>">
<?= e($eq['asset_tag']) ?> -
<?= e($eq['equipment_name']) ?>
</option>

<?php endwhile; ?>

</select>

</div>

<div class="col-md-6">

<label class="form-label fw-semibold">
Actual Condition *
</label>

<select name="actual_condition" class="form-select" required>

<option value="">Select condition</option>
<option value="Good">Good</option>
<option value="Fair">Fair</option>
<option value="Poor">Poor</option>
<option value="Damaged">Damaged</option>

</select>

</div>

<div class="col-md-6">

<label class="form-label fw-semibold">
Verification Result *
</label>

<select name="verification_status" class="form-select" required>

<option value="">Select result</option>
<option value="Verified">Verified / Found</option>
<option value="Missing">Missing</option>
<option value="Damaged">Damaged</option>
<option value="Needs Repair">Needs Repair</option>

</select>

</div>

<div class="col-md-6">

<label class="form-label fw-semibold">
Actual Location
</label>

<input type="text"
name="actual_location"
class="form-control"
maxlength="255"
placeholder="Enter actual equipment location">

</div>

<div class="col-12">

<label class="form-label fw-semibold">
Remarks
</label>

<textarea name="remarks"
class="form-control"
rows="2"
placeholder="Enter verification notes or discrepancies"></textarea>

</div>

<div class="col-12">

<button type="submit" class="btn btn-green px-4">
Save Verification
</button>

</div>

</div>
</form>

</div>

<?php endif; ?>

<!-- VERIFICATION HISTORY -->

<div class="card p-4">

<h5 class="fw-bold mb-3">Verification History</h5>

<div class="table-responsive">

<table class="table table-hover align-middle">

<thead class="table-success">

<tr>
<th>ID</th>
<th>Asset Tag</th>
<th>Equipment</th>
<th>Date</th>
<th>Condition</th>
<th>Result</th>
<th>Actual Location</th>
<th>Remarks</th>
</tr>

</thead>

<tbody>

<?php if ($history->num_rows > 0): ?>

<?php while ($row = $history->fetch_assoc()): ?>

<tr>

<td><?= (int)$row['verification_id'] ?></td>
<td><?= e($row['asset_tag']) ?></td>
<td><?= e($row['equipment_name']) ?></td>
<td><?= e($row['verification_date']) ?></td>
<td><?= e($row['actual_condition']) ?></td>
<td>
<span class="badge bg-secondary">
<?= e($row['verification_status']) ?>
</span>
</td>
<td><?= e($row['actual_location'] ?: 'N/A') ?></td>
<td><?= e($row['remarks'] ?: 'N/A') ?></td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="8" class="text-center text-muted py-4">
No verification records found.
</td>
</tr>

<?php endif; ?>

</tbody>
</table>

</div>
</div>

</div>
</div>
</div>

</body>
</html>

<?php
$equipmentList->free();
$history->free();
?>

