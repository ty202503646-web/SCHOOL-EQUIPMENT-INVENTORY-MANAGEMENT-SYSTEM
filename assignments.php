
<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireLogin();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role_name'] ?? '';
$isAdmin = ($role === 'Administrator');
$canManage = in_array($role, ['Administrator', 'Inventory Staff'], true);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success = '';
$error = '';
$editAssignment = null;

// ======================================
// HANDLE FORM SUBMISSIONS
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";

    } elseif (!$canManage) {
        http_response_code(403);
        exit("You are not authorized to manage assignments.");

    } else {

        $action = $_POST['action'] ?? '';

        // ==================================
        // ADD NEW ASSIGNMENT
        // ==================================

        if ($action === 'add') {

            $equipment_id = (int)($_POST['equipment_id'] ?? 0);
            $assigned_to = trim($_POST['assigned_to'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $assigned_date = $_POST['assigned_date'] ?? date('Y-m-d');
            $expected_return_date = $_POST['expected_return_date'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');

            if (
                $equipment_id <= 0 ||
                $assigned_to === '' ||
                strlen($assigned_to) > 150
            ) {
                $error = "Please complete the required fields.";

            } elseif (
                $expected_return_date !== '' &&
                $expected_return_date < $assigned_date
            ) {
                $error = "Expected return date cannot be earlier than assignment date.";

            } else {

                try {

                    $conn->begin_transaction();

                    // Lock equipment record
                    $check = $conn->prepare(
                        "SELECT status
                         FROM equipment
                         WHERE equipment_id = ?
                         AND archived_at IS NULL
                         FOR UPDATE"
                    );

                    $check->bind_param("i", $equipment_id);
                    $check->execute();

                    $equipment = $check->get_result()->fetch_assoc();
                    $check->close();

                    if (!$equipment || $equipment['status'] !== 'Available') {

                        throw new Exception(
                            "Equipment is not available for assignment."
                        );
                    }

                    // Check active assignment
                    $check = $conn->prepare(
                        "SELECT assignment_id
                         FROM equipment_assignments
                         WHERE equipment_id = ?
                         AND assignment_status = 'Active'
                         LIMIT 1"
                    );

                    $check->bind_param("i", $equipment_id);
                    $check->execute();

                    $alreadyAssigned = $check->get_result()->num_rows > 0;
                    $check->close();

                    if ($alreadyAssigned) {
                        throw new Exception(
                            "This equipment already has an active assignment."
                        );
                    }

                    $assigned_by = (int)$_SESSION['user_id'];

                    // Insert assignment
                    $stmt = $conn->prepare(
                        "INSERT INTO equipment_assignments
                        (
                            equipment_id,
                            assigned_to,
                            department,
                            assigned_by,
                            assigned_date,
                            expected_return_date,
                            assignment_status,
                            remarks
                        )
                        VALUES (
                            ?, ?, NULLIF(?, ''),
                            ?, ?, NULLIF(?, ''),
                            'Active', NULLIF(?, '')
                        )"
                    );

                    $stmt->bind_param(
                        "ississs",
                        $equipment_id,
                        $assigned_to,
                        $department,
                        $assigned_by,
                        $assigned_date,
                        $expected_return_date,
                        $remarks
                    );

                    $stmt->execute();
                    $stmt->close();

                    // Automatically update equipment status
                    $stmt = $conn->prepare(
                        "UPDATE equipment
                         SET status = 'Assigned'
                         WHERE equipment_id = ?"
                    );

                    $stmt->bind_param("i", $equipment_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $success = "Equipment assigned successfully!";

                } catch (Throwable $ex) {

                    $conn->rollback();
                    $error = $ex->getMessage();
                }
            }
        }

        // ==================================
        // EDIT ASSIGNMENT
        // ==================================

        elseif ($action === 'edit') {

            $id = (int)($_POST['assignment_id'] ?? 0);
            $assigned_to = trim($_POST['assigned_to'] ?? '');
            $department = trim($_POST['department'] ?? '');
            $expected_return_date = $_POST['expected_return_date'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');

            if ($id <= 0 || $assigned_to === '' || strlen($assigned_to) > 150) {

                $error = "Please enter a valid recipient.";

            } else {

                $stmt = $conn->prepare(
                    "UPDATE equipment_assignments
                     SET assigned_to = ?,
                         department = NULLIF(?, ''),
                         expected_return_date = NULLIF(?, ''),
                         remarks = NULLIF(?, '')
                     WHERE assignment_id = ?
                     AND assignment_status = 'Active'"
                );

                $stmt->bind_param(
                    "ssssi",
                    $assigned_to,
                    $department,
                    $expected_return_date,
                    $remarks,
                    $id
                );

                if ($stmt->execute() && $stmt->affected_rows >= 0) {
                    $success = "Assignment updated successfully!";
                } else {
                    $error = "Unable to update assignment.";
                }

                $stmt->close();
            }
        }

        // ==================================
        // RETURN EQUIPMENT
        // ==================================

        elseif ($action === 'return') {

            $id = (int)($_POST['assignment_id'] ?? 0);

            if ($id <= 0) {

                $error = "Invalid assignment record.";

            } else {

                try {

                    $conn->begin_transaction();

                    // Lock assignment
                    $stmt = $conn->prepare(
                        "SELECT equipment_id, assignment_status
                         FROM equipment_assignments
                         WHERE assignment_id = ?
                         FOR UPDATE"
                    );

                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    $assignment = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    if (
                        !$assignment ||
                        $assignment['assignment_status'] !== 'Active'
                    ) {
                        throw new Exception(
                            "Assignment is not active or was already returned."
                        );
                    }

                    $equipment_id = (int)$assignment['equipment_id'];

                    // Update assignment record
                    $stmt = $conn->prepare(
                        "UPDATE equipment_assignments
                         SET assignment_status = 'Returned',
                             returned_date = CURDATE()
                         WHERE assignment_id = ?"
                    );

                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->close();

                    // Return equipment to available status
                    $stmt = $conn->prepare(
                        "UPDATE equipment
                         SET status = 'Available'
                         WHERE equipment_id = ?
                         AND status = 'Assigned'"
                    );

                    $stmt->bind_param("i", $equipment_id);
                    $stmt->execute();
                    $stmt->close();

                    $conn->commit();

                    $success = "Equipment returned successfully!";

                } catch (Throwable $ex) {

                    $conn->rollback();
                    $error = $ex->getMessage();
                }
            }
        }
    }
}

// ======================================
// LOAD ASSIGNMENT FOR EDITING
// ======================================

if (isset($_GET['edit']) && $canManage) {

    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT *
         FROM equipment_assignments
         WHERE assignment_id = ?
         AND assignment_status = 'Active'"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $editAssignment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ======================================
// LOAD AVAILABLE EQUIPMENT
// ======================================

$availableEquipment = $conn->query(
    "SELECT equipment_id, asset_tag, equipment_name
     FROM equipment
     WHERE status = 'Available'
     AND archived_at IS NULL
     ORDER BY equipment_name"
);

// ======================================
// LOAD ASSIGNMENT RECORDS
// ======================================

$assignments = $conn->query(
    "SELECT
        a.*,
        e.asset_tag,
        e.equipment_name
     FROM equipment_assignments a
     INNER JOIN equipment e
        ON a.equipment_id = e.equipment_id
     ORDER BY a.created_at DESC"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Equipment Assignments | SEIMS</title>

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

.status-active {
    background: #d1fae5;
    color: #065f46;
}

.status-returned {
    background: #e0e7ff;
    color: #3730a3;
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

<a href="assignments.php" class="active">
Assignments
</a>

<a href="verification.php">Inventory Verification</a>

<a href="reports.php">Reports</a>

<?php if ($isAdmin): ?>
<a href="users.php">User Management</a>
<?php endif; ?>

<hr>

<form action="logout.php" method="POST">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<button type="submit" class="btn btn-outline-light w-100">
Logout
</button>

</form>

</div>


<!-- MAIN CONTENT -->

<div class="col-md-9 col-lg-10 main-content">

<h3 class="brand fw-bold">Equipment Assignment Management</h3>

<p class="text-muted">
Assign, monitor, and track school equipment.
</p>


<!-- ALERTS -->

<?php if ($success): ?>

<div class="alert alert-success">
<?= e($success) ?>
</div>

<?php endif; ?>

<?php if ($error): ?>

<div class="alert alert-danger">
<?= e($error) ?>
</div>

<?php endif; ?>


<!-- ADD / EDIT FORM -->

<?php if ($canManage): ?>

<div class="card p-4 mb-4">

<h5 class="brand fw-bold mb-3">

<?= $editAssignment ? 'Edit Assignment' : 'New Equipment Assignment' ?>

</h5>

<form method="POST" action="assignments.php">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<input type="hidden" name="action"
value="<?= $editAssignment ? 'edit' : 'add' ?>">

<?php if ($editAssignment): ?>

<input type="hidden" name="assignment_id"
value="<?= (int)$editAssignment['assignment_id'] ?>">

<?php endif; ?>


<div class="row g-3">

<?php if (!$editAssignment): ?>

<div class="col-md-6">

<label class="form-label fw-semibold">
Select Equipment *
</label>

<select name="equipment_id" class="form-select" required>

<option value="">Choose available equipment</option>

<?php while ($eq = $availableEquipment->fetch_assoc()): ?>

<option value="<?= (int)$eq['equipment_id'] ?>">

<?= e($eq['asset_tag']) ?> -
<?= e($eq['equipment_name']) ?>

</option>

<?php endwhile; ?>

</select>

</div>

<?php endif; ?>


<div class="col-md-6">

<label class="form-label fw-semibold">
Assigned To *
</label>

<input type="text"
name="assigned_to"
class="form-control"
maxlength="150"
required
placeholder="Enter recipient's name"
value="<?= e($editAssignment['assigned_to'] ?? '') ?>">

</div>


<div class="col-md-6">

<label class="form-label fw-semibold">
Department
</label>

<select name="department" class="form-select">

<option value="">Select Department</option>

<?php
$departments = [
    'Administration',
    'Elementary',
    'Junior High School',
    'Senior High School',
    'Chinese Department',
    'IT Department',
    'Library',
    'Finance',
    'Maintenance',
    'Other'
];

$currentDepartment = $editAssignment['department'] ?? '';

foreach ($departments as $dept):
?>

<option value="<?= e($dept) ?>"
<?= $currentDepartment === $dept ? 'selected' : '' ?>>

<?= e($dept) ?>

</option>

<?php endforeach; ?>

</select>

</div>


<?php if (!$editAssignment): ?>

<div class="col-md-6">

<label class="form-label fw-semibold">
Assignment Date *
</label>

<input type="date"
name="assigned_date"
class="form-control"
required
value="<?= date('Y-m-d') ?>">

</div>

<?php endif; ?>


<div class="col-md-6">

<label class="form-label fw-semibold">
Expected Return Date
</label>

<input type="date"
name="expected_return_date"
class="form-control"
value="<?= e($editAssignment['expected_return_date'] ?? '') ?>">

</div>


<div class="col-12">

<label class="form-label fw-semibold">
Remarks
</label>

<textarea name="remarks"
class="form-control"
rows="2"
placeholder="Additional information..."><?= e($editAssignment['remarks'] ?? '') ?></textarea>

</div>


<div class="col-12">

<button type="submit" class="btn btn-green px-4">

<?= $editAssignment ? 'Update Assignment' : 'Save Assignment' ?>

</button>

<?php if ($editAssignment): ?>

<a href="assignments.php" class="btn btn-secondary">
Cancel
</a>

<?php endif; ?>

</div>

</div>

</form>

</div>

<?php endif; ?>


<!-- ASSIGNMENT RECORDS -->

<div class="card p-4">

<h5 class="fw-bold mb-3">Assignment Records</h5>


<!-- SEARCH AND STATUS FILTER -->

<div class="row g-2 mb-3">

<div class="col-md-8">

<input type="text"
id="assignmentSearch"
class="form-control"
placeholder="Search equipment, asset tag, recipient, department..."
autocomplete="off">

</div>

<div class="col-md-4">

<select id="statusFilter" class="form-select">

<option value="">All Assignments</option>

<option value="Active">Active</option>

<option value="Returned">Returned</option>

</select>

</div>

</div>


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
<th>Expected Return</th>
<th>Returned Date</th>
<th>Status</th>

<?php if ($canManage): ?>
<th>Actions</th>
<?php endif; ?>

</tr>

</thead>


<tbody id="assignmentTableBody">

<?php if ($assignments->num_rows > 0): ?>

<?php while ($row = $assignments->fetch_assoc()): ?>

<tr data-status="<?= e($row['assignment_status']) ?>">

<td><?= (int)$row['assignment_id'] ?></td>

<td><?= e($row['asset_tag']) ?></td>

<td><?= e($row['equipment_name']) ?></td>

<td><?= e($row['assigned_to']) ?></td>

<td><?= e($row['department'] ?: 'N/A') ?></td>

<td><?= e($row['assigned_date']) ?></td>

<td><?= e($row['expected_return_date'] ?: 'N/A') ?></td>

<td><?= e($row['returned_date'] ?: 'N/A') ?></td>

<td>

<?php if ($row['assignment_status'] === 'Active'): ?>

<span class="badge status-active">Active</span>

<?php else: ?>

<span class="badge status-returned">Returned</span>

<?php endif; ?>

</td>


<?php if ($canManage): ?>

<td class="text-nowrap">

<?php if ($row['assignment_status'] === 'Active'): ?>

<a href="assignments.php?edit=<?= (int)$row['assignment_id'] ?>"
class="btn btn-sm btn-primary">
Edit
</a>


<form method="POST"
action="assignments.php"
class="d-inline"
onsubmit="return confirm('Confirm equipment return?');">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<input type="hidden" name="action" value="return">

<input type="hidden" name="assignment_id"
value="<?= (int)$row['assignment_id'] ?>">

<button type="submit" class="btn btn-sm btn-success">
Return
</button>

</form>

<?php else: ?>

<span class="text-muted">Completed</span>

<?php endif; ?>

</td>

<?php endif; ?>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>

<td colspan="<?= $canManage ? 10 : 9 ?>"
class="text-center text-muted py-4">

No assignment records found.

</td>

</tr>

<?php endif; ?>

</tbody>

</table>

<p id="noResults" class="text-center text-muted d-none">
No matching assignment records found.
</p>

</div>

</div>

</div>

</div>

</div>


<!-- LIVE SEARCH -->

<script>

document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('assignmentSearch');
    const statusFilter = document.getElementById('statusFilter');
    const tableBody = document.getElementById('assignmentTableBody');
    const noResults = document.getElementById('noResults');

    function filterAssignments() {

        const keyword = searchInput.value.toLowerCase().trim();
        const selectedStatus = statusFilter.value;

        const rows = tableBody.querySelectorAll('tr');
        let visibleCount = 0;

        rows.forEach(function(row) {

            if (row.querySelector('td[colspan]')) {
                return;
            }

            const text = row.innerText.toLowerCase();
            const status = row.dataset.status;

            const matchesSearch = text.includes(keyword);

            const matchesStatus =
                selectedStatus === '' ||
                status === selectedStatus;

            if (matchesSearch && matchesStatus) {

                row.style.display = '';
                visibleCount++;

            } else {

                row.style.display = 'none';

            }

        });

        noResults.classList.toggle(
            'd-none',
            visibleCount > 0
        );
    }

    searchInput.addEventListener('input', filterAssignments);

    statusFilter.addEventListener('change', filterAssignments);

});

</script>

</body>
</html>

<?php
$assignments->free();
?>
