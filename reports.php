
<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireLogin();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role_name'] ?? '';
$isAdmin = ($role === 'Administrator');

$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$reportType = $_GET['report_type'] ?? 'all';

$allowedTypes = ['all', 'equipment', 'assignments', 'verification'];

if (!in_array($reportType, $allowedTypes, true)) {
    $reportType = 'all';
}

if (
    ($startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) ||
    ($endDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate))
) {
    exit("Invalid date format.");
}

if ($startDate !== '' && $endDate !== '' && $startDate > $endDate) {
    exit("Start date cannot be later than end date.");
}

// ======================================
// DASHBOARD SUMMARY
// ======================================

$equipmentSummary = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN archived_at IS NULL AND status = 'Available' THEN 1 ELSE 0 END) AS available,
        SUM(CASE WHEN archived_at IS NULL AND status = 'Assigned' THEN 1 ELSE 0 END) AS assigned,
        SUM(CASE WHEN archived_at IS NOT NULL THEN 1 ELSE 0 END) AS archived
     FROM equipment"
)->fetch_assoc();

$assignmentSummary = $conn->query(
    "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN assignment_status = 'Active' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN assignment_status = 'Returned' THEN 1 ELSE 0 END) AS returned
     FROM equipment_assignments"
)->fetch_assoc();

$verificationSummary = $conn->query(
    "SELECT
        COUNT(DISTINCT equipment_id) AS verified_equipment,
        SUM(CASE WHEN verification_status = 'Verified' THEN 1 ELSE 0 END) AS verified,
        SUM(CASE WHEN verification_status = 'Missing' THEN 1 ELSE 0 END) AS missing,
        SUM(CASE WHEN verification_status = 'Damaged' THEN 1 ELSE 0 END) AS damaged,
        SUM(CASE WHEN verification_status = 'Needs Repair' THEN 1 ELSE 0 END) AS needs_repair
     FROM equipment_verifications"
)->fetch_assoc();

// ======================================
// FILTERED REPORT DATA
// ======================================

$equipmentRows = [];
$assignmentRows = [];
$verificationRows = [];

if ($reportType === 'all' || $reportType === 'equipment') {

    $sql = "SELECT
                equipment_id,
                asset_tag,
                equipment_name,
                status,
                archived_at
            FROM equipment";

    $conditions = [];
    $types = "";
    $params = [];

    if ($reportType === 'equipment') {
        $conditions[] = "DATE(COALESCE(archived_at, '9999-12-31')) >= ?";
        $conditions[] = "DATE(COALESCE(archived_at, '9999-12-31')) <= ?";

        if ($startDate !== '') {
            $conditions[0] = "(archived_at IS NULL OR DATE(archived_at) >= ?)";
            $types .= "s";
            $params[] = $startDate;
        } else {
            array_shift($conditions);
        }

        if ($endDate !== '') {
            $conditions[] = "(archived_at IS NULL OR DATE(archived_at) <= ?)";
            $types .= "s";
            $params[] = $endDate;
        } else {
            array_pop($conditions);
        }
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY equipment_id DESC";

    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $equipmentRows[] = $row;
    }

    $stmt->close();
}

// ======================================
// ASSIGNMENT REPORT
// ======================================

if ($reportType === 'all' || $reportType === 'assignments') {

    $sql = "SELECT
                a.assignment_id,
                e.asset_tag,
                e.equipment_name,
                a.assigned_to,
                a.department,
                a.assigned_date,
                a.expected_return_date,
                a.returned_date,
                a.assignment_status
            FROM equipment_assignments a
            INNER JOIN equipment e
                ON a.equipment_id = e.equipment_id";

    $conditions = [];
    $types = "";
    $params = [];

    if ($startDate !== '') {
        $conditions[] = "a.assigned_date >= ?";
        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== '') {
        $conditions[] = "a.assigned_date <= ?";
        $types .= "s";
        $params[] = $endDate;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY a.assignment_id DESC";

    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $assignmentRows[] = $row;
    }

    $stmt->close();
}

// ======================================
// VERIFICATION REPORT
// ======================================

if ($reportType === 'all' || $reportType === 'verification') {

    $sql = "SELECT
                v.verification_id,
                e.asset_tag,
                e.equipment_name,
                v.verification_date,
                v.actual_condition,
                v.verification_status,
                v.actual_location,
                v.remarks
            FROM equipment_verifications v
            INNER JOIN equipment e
                ON v.equipment_id = e.equipment_id";

    $conditions = [];
    $types = "";
    $params = [];

    if ($startDate !== '') {
        $conditions[] = "v.verification_date >= ?";
        $types .= "s";
        $params[] = $startDate;
    }

    if ($endDate !== '') {
        $conditions[] = "v.verification_date <= ?";
        $types .= "s";
        $params[] = $endDate;
    }

    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }

    $sql .= " ORDER BY v.verification_id DESC";

    $stmt = $conn->prepare($sql);

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $verificationRows[] = $row;
    }

    $stmt->close();
}

// ======================================
// CSV EXPORT
// ======================================

if (isset($_GET['export']) && $_GET['export'] === 'csv') {

    if ($reportType === 'equipment') {
        $headers = [
            'Equipment ID',
            'Asset Tag',
            'Equipment Name',
            'Status',
            'Archived At'
        ];

        $rows = [];

        foreach ($equipmentRows as $r) {
            $rows[] = [
                $r['equipment_id'],
                $r['asset_tag'],
                $r['equipment_name'],
                $r['status'],
                $r['archived_at']
            ];
        }

        $filename = 'equipment_report.csv';

    } elseif ($reportType === 'assignments') {
        $headers = [
            'Assignment ID',
            'Asset Tag',
            'Equipment',
            'Assigned To',
            'Department',
            'Assigned Date',
            'Expected Return',
            'Returned Date',
            'Status'
        ];

        $rows = [];

        foreach ($assignmentRows as $r) {
            $rows[] = [
                $r['assignment_id'],
                $r['asset_tag'],
                $r['equipment_name'],
                $r['assigned_to'],
                $r['department'],
                $r['assigned_date'],
                $r['expected_return_date'],
                $r['returned_date'],
                $r['assignment_status']
            ];
        }

        $filename = 'assignment_report.csv';

    } elseif ($reportType === 'verification') {
        $headers = [
            'Verification ID',
            'Asset Tag',
            'Equipment',
            'Verification Date',
            'Condition',
            'Result',
            'Actual Location',
            'Remarks'
        ];

        $rows = [];

        foreach ($verificationRows as $r) {
            $rows[] = [
                $r['verification_id'],
                $r['asset_tag'],
                $r['equipment_name'],
                $r['verification_date'],
                $r['actual_condition'],
                $r['verification_status'],
                $r['actual_location'],
                $r['remarks']
            ];
        }

        $filename = 'verification_report.csv';

    } else {
        exit("Please select a specific report type before exporting.");
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        fputcsv($output, $row);
    }

    fclose($output);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Reports & History | SEIMS</title>

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

.stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #14532d;
}

.table th {
    white-space: nowrap;
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
<a href="verification.php">Inventory Verification</a>
<a href="reports.php" class="active">Reports</a>

<?php if ($isAdmin): ?>
<a href="users.php">User Management</a>
<?php endif; ?>

<hr>

<form action="logout.php" method="POST">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token'] ?? '') ?>">

<button type="submit" class="btn btn-outline-light w-100">
Logout
</button>

</form>

</div>

<!-- MAIN CONTENT -->

<div class="col-md-9 col-lg-10 main-content">

<h3 class="brand fw-bold">Reports & History</h3>

<p class="text-muted">
Monitor equipment records, assignments, and inventory verification results.
</p>

<!-- SUMMARY CARDS -->

<div class="row g-3 mb-4">

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Total Equipment</small>
<div class="stat-number">
<?= (int)$equipmentSummary['total'] ?>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Available Equipment</small>
<div class="stat-number">
<?= (int)$equipmentSummary['available'] ?>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Assigned Equipment</small>
<div class="stat-number">
<?= (int)$equipmentSummary['assigned'] ?>
</div>
</div>
</div>

<div class="col-md-3">
<div class="card p-3">
<small class="text-muted">Archived Equipment</small>
<div class="stat-number">
<?= (int)$equipmentSummary['archived'] ?>
</div>
</div>
</div>

</div>

<!-- ASSIGNMENT AND VERIFICATION SUMMARY -->

<div class="row g-3 mb-4">

<div class="col-md-4">
<div class="card p-3">
<h6 class="fw-bold">Assignment Summary</h6>
<div class="d-flex justify-content-between">
<span>Total Assignments</span>
<strong><?= (int)$assignmentSummary['total'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Active</span>
<strong><?= (int)$assignmentSummary['active'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Returned</span>
<strong><?= (int)$assignmentSummary['returned'] ?></strong>
</div>
</div>
</div>

<div class="col-md-4">
<div class="card p-3">
<h6 class="fw-bold">Verification Summary</h6>
<div class="d-flex justify-content-between">
<span>Equipment Verified</span>
<strong><?= (int)$verificationSummary['verified_equipment'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Verified Records</span>
<strong><?= (int)$verificationSummary['verified'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Missing</span>
<strong><?= (int)$verificationSummary['missing'] ?></strong>
</div>
</div>
</div>

<div class="col-md-4">
<div class="card p-3">
<h6 class="fw-bold">Equipment Condition</h6>
<div class="d-flex justify-content-between">
<span>Damaged</span>
<strong><?= (int)$verificationSummary['damaged'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Needs Repair</span>
<strong><?= (int)$verificationSummary['needs_repair'] ?></strong>
</div>
<div class="d-flex justify-content-between">
<span>Verification Records</span>
<strong>
<?= (int)$verificationSummary['verified']
 + (int)$verificationSummary['missing']
 + (int)$verificationSummary['damaged']
 + (int)$verificationSummary['needs_repair'] ?>
</strong>
</div>
</div>
</div>

</div>

<!-- FILTERS -->

<div class="card p-4 mb-4">

<h5 class="fw-bold mb-3">Generate Report</h5>

<form method="GET" action="reports.php">

<div class="row g-3">

<div class="col-md-3">

<label class="form-label">Report Type</label>

<select name="report_type" class="form-select">

<option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>
All Reports
</option>

<option value="equipment" <?= $reportType === 'equipment' ? 'selected' : '' ?>>
Equipment
</option>

<option value="assignments" <?= $reportType === 'assignments' ? 'selected' : '' ?>>
Assignments
</option>

<option value="verification" <?= $reportType === 'verification' ? 'selected' : '' ?>>
Verification
</option>

</select>

</div>

<div class="col-md-3">

<label class="form-label">Start Date</label>

<input type="date"
name="start_date"
class="form-control"
value="<?= e($startDate) ?>">

</div>

<div class="col-md-3">

<label class="form-label">End Date</label>

<input type="date"
name="end_date"
class="form-control"
value="<?= e($endDate) ?>">

</div>

<div class="col-md-3 d-flex align-items-end">

<button type="submit" class="btn btn-success w-100">
Generate Report
</button>

</div>

</div>

</form>

<div class="mt-3 d-flex gap-2 flex-wrap">

<a href="reports.php" class="btn btn-secondary btn-sm">
Clear Filters
</a>

<?php if ($reportType !== 'all'): ?>

<a href="?report_type=<?= e($reportType) ?>&start_date=<?= e($startDate) ?>&end_date=<?= e($endDate) ?>&export=csv"
class="btn btn-success btn-sm">

Download CSV

</a>

<?php endif; ?>

</div>

</div>

<!-- EQUIPMENT REPORT -->

<?php if ($reportType === 'all' || $reportType === 'equipment'): ?>

<div class="card p-4 mb-4">

<h5 class="fw-bold mb-3">Equipment Report</h5>

<div class="table-responsive">

<table class="table table-hover align-middle">

<thead class="table-success">
<tr>
<th>ID</th>
<th>Asset Tag</th>
<th>Equipment Name</th>
<th>Status</th>
<th>Archive Date</th>
</tr>
</thead>

<tbody>

<?php if (count($equipmentRows) > 0): ?>

<?php foreach ($equipmentRows as $row): ?>

<tr>
<td><?= (int)$row['equipment_id'] ?></td>
<td><?= e($row['asset_tag']) ?></td>
<td><?= e($row['equipment_name']) ?></td>
<td><?= e($row['status']) ?></td>
<td><?= e($row['archived_at'] ?: 'Active') ?></td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="5" class="text-center text-muted py-3">
No equipment records found.
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>

<!-- ASSIGNMENT REPORT -->

<?php if ($reportType === 'all' || $reportType === 'assignments'): ?>

<div class="card p-4 mb-4">

<h5 class="fw-bold mb-3">Assignment Report</h5>

<div class="table-responsive">

<table class="table table-hover align-middle">

<thead class="table-success">
<tr>
<th>ID</th>
<th>Asset Tag</th>
<th>Equipment</th>
<th>Assigned To</th>
<th>Department</th>
<th>Assigned Date</th>
<th>Return Date</th>
<th>Status</th>
</tr>
</thead>

<tbody>

<?php if (count($assignmentRows) > 0): ?>

<?php foreach ($assignmentRows as $row): ?>

<tr>
<td><?= (int)$row['assignment_id'] ?></td>
<td><?= e($row['asset_tag']) ?></td>
<td><?= e($row['equipment_name']) ?></td>
<td><?= e($row['assigned_to']) ?></td>
<td><?= e($row['department'] ?: 'N/A') ?></td>
<td><?= e($row['assigned_date']) ?></td>
<td><?= e($row['returned_date'] ?: 'N/A') ?></td>
<td><?= e($row['assignment_status']) ?></td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="8" class="text-center text-muted py-3">
No assignment records found.
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>

<!-- VERIFICATION REPORT -->

<?php if ($reportType === 'all' || $reportType === 'verification'): ?>

<div class="card p-4 mb-4">

<h5 class="fw-bold mb-3">Inventory Verification Report</h5>

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
<th>Location</th>
<th>Remarks</th>
</tr>
</thead>

<tbody>

<?php if (count($verificationRows) > 0): ?>

<?php foreach ($verificationRows as $row): ?>

<tr>
<td><?= (int)$row['verification_id'] ?></td>
<td><?= e($row['asset_tag']) ?></td>
<td><?= e($row['equipment_name']) ?></td>
<td><?= e($row['verification_date']) ?></td>
<td><?= e($row['actual_condition']) ?></td>
<td><?= e($row['verification_status']) ?></td>
<td><?= e($row['actual_location'] ?: 'N/A') ?></td>
<td><?= e($row['remarks'] ?: 'N/A') ?></td>
</tr>

<?php endforeach; ?>

<?php else: ?>

<tr>
<td colspan="8" class="text-center text-muted py-3">
No verification records found.
</td>
</tr>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

<?php endif; ?>

</div>
</div>
</div>

</body>
</html>
