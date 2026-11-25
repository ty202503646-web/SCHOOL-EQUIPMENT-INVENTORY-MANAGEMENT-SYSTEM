
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

$error = '';
$success = '';
$editLocation = null;

// =====================================
// HANDLE ADD, EDIT AND ARCHIVE
// =====================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";

    } elseif (!$canManage) {
        http_response_code(403);
        exit("You are not authorized to manage locations.");

    } else {

        $action = $_POST['action'] ?? '';

        // ADD LOCATION
        if ($action === 'add') {

            $name = trim($_POST['location_name'] ?? '');
            $building = trim($_POST['building'] ?? '');
            $floor = trim($_POST['floor'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '' || strlen($name) > 150) {
                $error = "Location name is required (maximum 150 characters).";

            } else {

                $check = $conn->prepare(
                    "SELECT location_id FROM locations
                     WHERE location_name = ?
                     AND building = ?
                     AND floor = ?
                     AND archived_at IS NULL"
                );

                $check->bind_param("sss", $name, $building, $floor);
                $check->execute();

                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = "This location already exists.";

                } else {

                    $stmt = $conn->prepare(
                        "INSERT INTO locations
                         (location_name, building, floor, description)
                         VALUES (?, NULLIF(?, ''), NULLIF(?, ''), NULLIF(?, ''))"
                    );

                    $stmt->bind_param(
                        "ssss",
                        $name,
                        $building,
                        $floor,
                        $description
                    );

                    if ($stmt->execute()) {
                        $success = "Location added successfully!";
                    } else {
                        $error = "Unable to save location.";
                    }

                    $stmt->close();
                }
            }
        }

        // EDIT LOCATION
        elseif ($action === 'edit') {

            $id = (int)($_POST['location_id'] ?? 0);
            $name = trim($_POST['location_name'] ?? '');
            $building = trim($_POST['building'] ?? '');
            $floor = trim($_POST['floor'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($id <= 0 || $name === '' || strlen($name) > 150) {
                $error = "Please enter a valid location name.";

            } else {

                $check = $conn->prepare(
                    "SELECT location_id FROM locations
                     WHERE location_name = ?
                     AND building = ?
                     AND floor = ?
                     AND location_id != ?
                     AND archived_at IS NULL"
                );

                $check->bind_param("sssi", $name, $building, $floor, $id);
                $check->execute();

                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if ($exists) {
                    $error = "This location already exists.";

                } else {

                    $stmt = $conn->prepare(
                        "UPDATE locations
                         SET location_name = ?,
                             building = NULLIF(?, ''),
                             floor = NULLIF(?, ''),
                             description = NULLIF(?, '')
                         WHERE location_id = ?
                         AND archived_at IS NULL"
                    );

                    $stmt->bind_param(
                        "ssssi",
                        $name,
                        $building,
                        $floor,
                        $description,
                        $id
                    );

                    if ($stmt->execute() && $stmt->affected_rows >= 0) {
                        $success = "Location updated successfully!";
                    } else {
                        $error = "Unable to update location.";
                    }

                    $stmt->close();
                }
            }
        }

        // ARCHIVE LOCATION
        elseif ($action === 'archive') {

            $id = (int)($_POST['location_id'] ?? 0);

            if ($id <= 0) {
                $error = "Invalid location.";

            } else {

                // Check if equipment is using this location
                $check = $conn->prepare(
                    "SELECT COUNT(*) AS total
                     FROM equipment
                     WHERE location_id = ?"
                );

                $check->bind_param("i", $id);
                $check->execute();

                $used = (int)$check->get_result()
                    ->fetch_assoc()['total'];

                $check->close();

                if ($used > 0) {

                    $error = "This location is currently linked to equipment and cannot be archived.";

                } else {

                    $stmt = $conn->prepare(
                        "UPDATE locations
                         SET archived_at = NOW()
                         WHERE location_id = ?
                         AND archived_at IS NULL"
                    );

                    $stmt->bind_param("i", $id);
                    $stmt->execute();

                    if ($stmt->affected_rows > 0) {
                        $success = "Location archived successfully!";
                    } else {
                        $error = "Location not found or already archived.";
                    }

                    $stmt->close();
                }
            }
        }
    }
}

// =====================================
// LOAD LOCATION FOR EDITING
// =====================================

if (isset($_GET['edit']) && $canManage) {

    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT * FROM locations
         WHERE location_id = ?
         AND archived_at IS NULL"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $editLocation = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// =====================================
// SEARCH AND FILTER
// =====================================

$search = trim($_GET['search'] ?? '');
$buildingFilter = trim($_GET['building'] ?? '');
$floorFilter = trim($_GET['floor'] ?? '');

$sql = "SELECT * FROM locations
        WHERE archived_at IS NULL";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (
        location_name LIKE ?
        OR building LIKE ?
        OR floor LIKE ?
        OR description LIKE ?
    )";

    $keyword = "%$search%";

    array_push(
        $params,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );

    $types .= "ssss";
}

if ($buildingFilter !== '') {
    $sql .= " AND building = ?";
    $params[] = $buildingFilter;
    $types .= "s";
}

if ($floorFilter !== '') {
    $sql .= " AND floor = ?";
    $params[] = $floorFilter;
    $types .= "s";
}

$sql .= " ORDER BY building, floor, location_name";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$locationList = $stmt->get_result();

// =====================================
// GET FILTER OPTIONS
// =====================================

$buildingOptions = $conn->query(
    "SELECT DISTINCT building FROM locations
     WHERE archived_at IS NULL
     AND building IS NOT NULL
     AND building != ''
     ORDER BY building"
);

$floorOptions = $conn->query(
    "SELECT DISTINCT floor FROM locations
     WHERE archived_at IS NULL
     AND floor IS NOT NULL
     AND floor != ''
     ORDER BY floor"
);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Locations | SEIMS</title>

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

<a href="locations.php" class="active">Locations</a>

<a href="assignments.php">Assignments</a>

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

<div class="mb-4">

<h3 class="brand fw-bold">Locations Management</h3>

<p class="text-muted">
Manage school buildings, floors, rooms, and equipment locations.
</p>

</div>


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

<?= $editLocation ? 'Edit Location' : 'Add New Location' ?>

</h5>

<form method="POST" action="locations.php">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<input type="hidden" name="action"
value="<?= $editLocation ? 'edit' : 'add' ?>">

<?php if ($editLocation): ?>

<input type="hidden" name="location_id"
value="<?= (int)$editLocation['location_id'] ?>">

<?php endif; ?>


<div class="row g-3">

<div class="col-md-6">

<label class="form-label fw-semibold">
Location Name *
</label>

<input type="text"
name="location_name"
class="form-control"
maxlength="150"
required
placeholder="e.g. Computer Laboratory"
value="<?= e($editLocation['location_name'] ?? '') ?>">

</div>


<div class="col-md-6">

<label class="form-label fw-semibold">
Building
</label>

<input type="text"
name="building"
class="form-control"
maxlength="100"
placeholder="e.g. Main Building"
value="<?= e($editLocation['building'] ?? '') ?>">

</div>


<div class="col-md-6">

<label class="form-label fw-semibold">
Floor
</label>

<input type="text"
name="floor"
class="form-control"
maxlength="50"
placeholder="e.g. 3rd Floor"
value="<?= e($editLocation['floor'] ?? '') ?>">

</div>


<div class="col-md-6">

<label class="form-label fw-semibold">
Description
</label>

<input type="text"
name="description"
class="form-control"
placeholder="Enter location description"
value="<?= e($editLocation['description'] ?? '') ?>">

</div>


<div class="col-12">

<button type="submit" class="btn btn-green px-4">

<?= $editLocation ? 'Update Location' : 'Save Location' ?>

</button>

<?php if ($editLocation): ?>

<a href="locations.php" class="btn btn-secondary">
Cancel
</a>

<?php endif; ?>

</div>

</div>

</form>

</div>

<?php endif; ?>


<!-- LOCATION RECORDS -->

<div class="card p-4">

<h5 class="fw-bold mb-3">Location Records</h5>


<!-- SEARCH AND FILTER -->

<form method="GET" action="locations.php" class="mb-4">

<div class="row g-2">

<div class="col-md-4">

<input type="text"
name="search"
id="locationSearch"
class="form-control"
placeholder="Search location, building, floor..."
value="<?= e($search) ?>"
autocomplete="off">

</div>


<div class="col-md-3">

<select name="building" class="form-select">

<option value="">All Buildings</option>

<?php while ($b = $buildingOptions->fetch_assoc()): ?>

<option value="<?= e($b['building']) ?>"
<?= $buildingFilter === $b['building'] ? 'selected' : '' ?>>

<?= e($b['building']) ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="col-md-2">

<select name="floor" class="form-select">

<option value="">All Floors</option>

<?php while ($f = $floorOptions->fetch_assoc()): ?>

<option value="<?= e($f['floor']) ?>"
<?= $floorFilter === $f['floor'] ? 'selected' : '' ?>>

<?= e($f['floor']) ?>

</option>

<?php endwhile; ?>

</select>

</div>


<div class="col-md-3 d-flex gap-2">

<button type="submit" class="btn btn-green">
Filter
</button>

<a href="locations.php" class="btn btn-secondary">
Reset
</a>

</div>

</div>

</form>


<!-- TABLE -->

<div class="table-responsive">

<table class="table table-hover align-middle">

<thead class="table-success">

<tr>

<th>Location ID</th>

<th>Location Name</th>

<th>Building</th>

<th>Floor</th>

<th>Description</th>

<th>Date Created</th>

<?php if ($canManage): ?>
<th>Actions</th>
<?php endif; ?>

</tr>

</thead>


<tbody id="locationTableBody">

<?php if ($locationList->num_rows > 0): ?>

<?php while ($location = $locationList->fetch_assoc()): ?>

<tr>

<td><?= (int)$location['location_id'] ?></td>

<td class="fw-semibold">
<?= e($location['location_name']) ?>
</td>

<td><?= e($location['building'] ?: 'N/A') ?></td>

<td><?= e($location['floor'] ?: 'N/A') ?></td>

<td><?= e($location['description'] ?: 'N/A') ?></td>

<td>
<?= e(date('M d, Y', strtotime($location['created_at']))) ?>
</td>


<?php if ($canManage): ?>

<td class="text-nowrap">

<a href="locations.php?edit=<?= (int)$location['location_id'] ?>"
class="btn btn-sm btn-primary">
Edit
</a>


<form method="POST"
action="locations.php"
class="d-inline"
onsubmit="return confirm('Are you sure you want to archive this location?');">

<input type="hidden" name="csrf_token"
value="<?= e($_SESSION['csrf_token']) ?>">

<input type="hidden" name="action" value="archive">

<input type="hidden" name="location_id"
value="<?= (int)$location['location_id'] ?>">

<button type="submit" class="btn btn-sm btn-danger">
Archive
</button>

</form>

</td>

<?php endif; ?>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>

<td colspan="<?= $canManage ? 7 : 6 ?>"
class="text-center text-muted py-4">

No location records found.

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


<script>
document.addEventListener('DOMContentLoaded', function () {

    const searchInput = document.getElementById('locationSearch');
    const tableBody = document.getElementById('locationTableBody');

    searchInput.addEventListener('input', function () {

        const searchValue = this.value.toLowerCase().trim();

        const rows = tableBody.querySelectorAll('tr');

        rows.forEach(function (row) {

            // Ignore the empty-record message
            if (row.querySelector('td[colspan]')) {
                return;
            }

            const rowText = row.innerText.toLowerCase();

            if (rowText.includes(searchValue)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }

        });

    });

});
</script>



</body>

</html>

<?php
$stmt->close();
?>
