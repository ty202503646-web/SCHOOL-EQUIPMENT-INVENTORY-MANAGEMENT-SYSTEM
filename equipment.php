
<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireLogin();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$isStaff = in_array(
    $_SESSION['role_name'] ?? '',
    ['Administrator', 'Inventory Staff'],
    true
);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error = '';
$success = '';

$editEquipment = null;

// ======================================
// HANDLE FORM SUBMISSIONS
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";

    } elseif (!$isStaff) {
        http_response_code(403);
        exit("You are not authorized to modify equipment.");

    } else {

        $action = $_POST['action'] ?? '';

        // ==================================
        // ADD EQUIPMENT
        // ==================================

        if ($action === 'add') {

            $name = trim($_POST['equipment_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = (int)($_POST['category_id'] ?? 0);
            $location = (int)($_POST['location_id'] ?? 0);
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $serial = trim($_POST['serial_number'] ?? '');
            $purchaseDate = $_POST['purchase_date'] ?? '';
            $purchaseCost = $_POST['purchase_cost'] ?? '';
            $condition = $_POST['equipment_condition'] ?? 'Good';
            $status = $_POST['status'] ?? 'Available';
            $remarks = trim($_POST['remarks'] ?? '');

            $allowedConditions = [
                'Good', 'Fair', 'Needs Repair', 'Damaged'
            ];

            $allowedStatuses = [
                'Available', 'Assigned',
                'Under Maintenance', 'Retired', 'Missing'
            ];

            if (
                $name === '' ||
                $category <= 0 ||
                $location <= 0 ||
                !in_array($condition, $allowedConditions, true) ||
                !in_array($status, $allowedStatuses, true)
            ) {
                $error = "Please complete all required fields.";

            } elseif (
                ($purchaseDate !== '' &&
                !DateTime::createFromFormat('Y-m-d', $purchaseDate))
                ||
                ($purchaseCost !== '' &&
                (!is_numeric($purchaseCost) || $purchaseCost < 0))
            ) {
                $error = "Please enter a valid purchase date and cost.";

            } else {

                $temporaryTag = 'TEMP-' . bin2hex(random_bytes(8));

                $stmt = $conn->prepare(
                    "INSERT INTO equipment (
                        asset_tag,
                        equipment_name,
                        description,
                        category_id,
                        location_id,
                        brand,
                        model,
                        serial_number,
                        purchase_date,
                        purchase_cost,
                        equipment_condition,
                        status,
                        remarks
                    ) VALUES (
                        ?, ?, ?, ?, ?, ?, ?,
                        NULLIF(?, ''),
                        NULLIF(?, ''),
                        NULLIF(?, ''),
                        ?, ?, ?
                    )"
                );

                $stmt->bind_param(
                    "sssiiisssssss",
                    $temporaryTag,
                    $name,
                    $description,
                    $category,
                    $location,
                    $brand,
                    $model,
                    $serial,
                    $purchaseDate,
                    $purchaseCost,
                    $condition,
                    $status,
                    $remarks
                );

                try {
                    if ($stmt->execute()) {

                        $newId = $conn->insert_id;
                        $assetTag = 'SJSFI-' . str_pad(
                            $newId,
                            4,
                            '0',
                            STR_PAD_LEFT
                        );

                        $update = $conn->prepare(
                            "UPDATE equipment
                             SET asset_tag = ?
                             WHERE equipment_id = ?"
                        );

                        $update->bind_param("si", $assetTag, $newId);
                        $update->execute();
                        $update->close();

                        $success = "Equipment added successfully! Asset Tag: " . $assetTag;

                    } else {
                        $error = "Unable to add equipment.";
                    }

                } catch (mysqli_sql_exception $ex) {
                    $error = "Unable to save equipment. Please check if the serial number already exists.";
                }

                $stmt->close();
            }
        }

        // ==================================
        // EDIT EQUIPMENT
        // ==================================

        elseif ($action === 'edit') {

            $id = (int)($_POST['equipment_id'] ?? 0);
            $name = trim($_POST['equipment_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $category = (int)($_POST['category_id'] ?? 0);
            $location = (int)($_POST['location_id'] ?? 0);
            $brand = trim($_POST['brand'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $serial = trim($_POST['serial_number'] ?? '');
            $purchaseDate = $_POST['purchase_date'] ?? '';
            $purchaseCost = $_POST['purchase_cost'] ?? '';
            $condition = $_POST['equipment_condition'] ?? '';
            $status = $_POST['status'] ?? '';
            $remarks = trim($_POST['remarks'] ?? '');

            $allowedConditions = [
                'Good', 'Fair', 'Needs Repair', 'Damaged'
            ];

            $allowedStatuses = [
                'Available', 'Assigned',
                'Under Maintenance', 'Retired', 'Missing'
            ];

            if (
                $id <= 0 || $name === '' ||
                $category <= 0 || $location <= 0 ||
                !in_array($condition, $allowedConditions, true) ||
                !in_array($status, $allowedStatuses, true)
            ) {
                $error = "Please check the equipment information.";

            } elseif (
                ($purchaseDate !== '' &&
                !DateTime::createFromFormat('Y-m-d', $purchaseDate))
                ||
                ($purchaseCost !== '' &&
                (!is_numeric($purchaseCost) || $purchaseCost < 0))
            ) {
                $error = "Please enter a valid purchase date and cost.";

            } else {

                $stmt = $conn->prepare(
                    "UPDATE equipment SET
                        equipment_name = ?,
                        description = ?,
                        category_id = ?,
                        location_id = ?,
                        brand = ?,
                        model = ?,
                        serial_number = NULLIF(?, ''),
                        purchase_date = NULLIF(?, ''),
                        purchase_cost = NULLIF(?, ''),
                        equipment_condition = ?,
                        status = ?,
                        remarks = ?
                     WHERE equipment_id = ?
                     AND archived_at IS NULL"
                );

                $stmt->bind_param(
                    "ssiissssssssi",
                    $name,
                    $description,
                    $category,
                    $location,
                    $brand,
                    $model,
                    $serial,
                    $purchaseDate,
                    $purchaseCost,
                    $condition,
                    $status,
                    $remarks,
                    $id
                );

                try {
                    if ($stmt->execute()) {
                        $success = "Equipment updated successfully!";
                    } else {
                        $error = "Unable to update equipment.";
                    }
                } catch (mysqli_sql_exception $ex) {
                    $error = "Unable to update. Check if the serial number is already used.";
                }

                $stmt->close();
            }
        }

        // ==================================
        // ARCHIVE EQUIPMENT
        // ==================================

        elseif ($action === 'archive') {

            $id = (int)($_POST['equipment_id'] ?? 0);

            $check = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM equipment_assignments
                 WHERE equipment_id = ?
                 AND assignment_status = 'Active'"
            );

            $check->bind_param("i", $id);
            $check->execute();
            $activeAssignments = (int)$check->get_result()
                ->fetch_assoc()['total'];
            $check->close();

            if ($id <= 0) {
                $error = "Invalid equipment record.";

            } elseif ($activeAssignments > 0) {
                $error = "This equipment has an active assignment. Return it before archiving.";

            } else {

                $stmt = $conn->prepare(
                    "UPDATE equipment
                     SET archived_at = NOW()
                     WHERE equipment_id = ?
                     AND archived_at IS NULL"
                );

                $stmt->bind_param("i", $id);
                $stmt->execute();

                $success = "Equipment archived successfully!";

                $stmt->close();
            }
        }
    }
}

// ======================================
// LOAD EDIT RECORD
// ======================================

if (isset($_GET['edit']) && $isStaff) {

    $editId = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT * FROM equipment
         WHERE equipment_id = ?
         AND archived_at IS NULL"
    );

    $stmt->bind_param("i", $editId);
    $stmt->execute();

    $editEquipment = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ======================================
// LOAD CATEGORIES AND LOCATIONS
// ======================================

$categories = $conn->query(
    "SELECT category_id, category_name
     FROM categories ORDER BY category_name"
);

$locations = $conn->query(
    "SELECT location_id, location_name
     FROM locations ORDER BY location_name"
);

// ======================================
// SEARCH AND FILTER
// ======================================

$search = trim($_GET['search'] ?? '');
$filterCategory = (int)($_GET['category'] ?? 0);
$filterStatus = $_GET['status'] ?? '';

$sql = "SELECT e.*, c.category_name, l.location_name
        FROM equipment e
        INNER JOIN categories c ON e.category_id = c.category_id
        INNER JOIN locations l ON e.location_id = l.location_id
        WHERE e.archived_at IS NULL";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (
        e.asset_tag LIKE ?
        OR e.equipment_name LIKE ?
        OR e.brand LIKE ?
        OR e.serial_number LIKE ?
    )";

    $keyword = "%$search%";
    array_push($params, $keyword, $keyword, $keyword, $keyword);
    $types .= 'ssss';
}

if ($filterCategory > 0) {
    $sql .= " AND e.category_id = ?";
    $params[] = $filterCategory;
    $types .= 'i';
}

$validFilterStatuses = [
    'Available', 'Assigned', 'Under Maintenance', 'Retired', 'Missing'
];

if (in_array($filterStatus, $validFilterStatuses, true)) {
    $sql .= " AND e.status = ?";
    $params[] = $filterStatus;
    $types .= 's';
}

$sql .= " ORDER BY e.equipment_id DESC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$equipmentList = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Equipment Management | SEIMS</title>

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
        <a href="equipment.php" class="active">Equipment Management</a>
        <a href="categories.php">Categories</a>
        <a href="locations.php">Locations</a>
        <a href="assignments.php">Assignments</a>
        <a href="verification.php">Inventory Verification</a>
        <a href="reports.php">Reports</a>

        <?php if (($_SESSION['role_name'] ?? '') === 'Administrator'): ?>
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

        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">

            <div>
                <h3 class="brand fw-bold">Equipment Management</h3>
                <p class="text-muted mb-0">
                    Manage and monitor school equipment records.
                </p>
            </div>

            <span class="badge bg-success">
                <?= e($_SESSION['role_name']) ?>
            </span>

        </div>

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

        <?php if ($isStaff): ?>

        <!-- ADD / EDIT FORM -->
        <div class="card p-4 mb-4">

            <h5 class="brand fw-bold mb-3">
                <?= $editEquipment ? 'Edit Equipment' : 'Add New Equipment' ?>
            </h5>

            <form method="POST" action="equipment.php">

                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action"
                       value="<?= $editEquipment ? 'edit' : 'add' ?>">

                <?php if ($editEquipment): ?>
                    <input type="hidden" name="equipment_id"
                           value="<?= (int)$editEquipment['equipment_id'] ?>">
                <?php endif; ?>

                <div class="row g-3">

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">
                            Equipment Name *
                        </label>

                        <input type="text" name="equipment_name"
                               class="form-control" required maxlength="150"
                               value="<?= e($editEquipment['equipment_name'] ?? '') ?>"
                               placeholder="e.g. Desktop Computer">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Category *</label>

                        <select name="category_id" class="form-select" required>
                            <option value="">Select Category</option>

                            <?php while ($cat = $categories->fetch_assoc()): ?>
                                <option value="<?= $cat['category_id'] ?>"
                                    <?= (($editEquipment['category_id'] ?? '') == $cat['category_id']) ? 'selected' : '' ?>>
                                    <?= e($cat['category_name']) ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Location *</label>

                        <select name="location_id" class="form-select" required>
                            <option value="">Select Location</option>

                            <?php while ($loc = $locations->fetch_assoc()): ?>
                                <option value="<?= $loc['location_id'] ?>"
                                    <?= (($editEquipment['location_id'] ?? '') == $loc['location_id']) ? 'selected' : '' ?>>
                                    <?= e($loc['location_name']) ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Brand</label>

                        <input type="text" name="brand" class="form-control"
                               value="<?= e($editEquipment['brand'] ?? '') ?>"
                               placeholder="e.g. Dell">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Model</label>

                        <input type="text" name="model" class="form-control"
                               value="<?= e($editEquipment['model'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Serial Number</label>

                        <input type="text" name="serial_number"
                               class="form-control"
                               value="<?= e($editEquipment['serial_number'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Purchase Date</label>

                        <input type="date" name="purchase_date"
                               class="form-control"
                               value="<?= e($editEquipment['purchase_date'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Purchase Cost</label>

                        <input type="number" name="purchase_cost"
                               class="form-control" min="0" step="0.01"
                               value="<?= e($editEquipment['purchase_cost'] ?? '') ?>">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Condition *</label>

                        <select name="equipment_condition" class="form-select" required>

                            <?php foreach (['Good','Fair','Needs Repair','Damaged'] as $condition): ?>
                                <option value="<?= e($condition) ?>"
                                    <?= (($editEquipment['equipment_condition'] ?? 'Good') === $condition) ? 'selected' : '' ?>>
                                    <?= e($condition) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Status *</label>

                        <select name="status" class="form-select" required>

                            <?php foreach (['Available','Assigned','Under Maintenance','Retired','Missing'] as $status): ?>
                                <option value="<?= e($status) ?>"
                                    <?= (($editEquipment['status'] ?? 'Available') === $status) ? 'selected' : '' ?>>
                                    <?= e($status) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Description</label>

                        <textarea name="description" class="form-control"
                                  rows="2"><?= e($editEquipment['description'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-semibold">Remarks</label>

                        <textarea name="remarks" class="form-control"
                                  rows="2"><?= e($editEquipment['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="col-12 d-flex gap-2">

                        <button type="submit" class="btn btn-green px-4">
                            <?= $editEquipment ? 'Update Equipment' : 'Save Equipment' ?>
                        </button>

                        <?php if ($editEquipment): ?>
                            <a href="equipment.php" class="btn btn-secondary">
                                Cancel
                            </a>
                        <?php endif; ?>

                    </div>

                </div>
            </form>
        </div>

        <?php endif; ?>

        <!-- SEARCH AND FILTER -->
        <div class="card p-4 mb-4">

            <h5 class="fw-bold mb-3">Equipment Records</h5>

            <form method="GET" action="equipment.php">
                <div class="row g-2">

                    <div class="col-md-5">
                        <input type="text" name="search"
                               class="form-control"
                               placeholder="Search asset tag, name, brand..."
                               value="<?= e($search) ?>">
                    </div>

                    <div class="col-md-3">
                        <select name="category" class="form-select">
                            <option value="0">All Categories</option>

                            <?php
                            $filterCategories = $conn->query(
                                "SELECT * FROM categories ORDER BY category_name"
                            );

                            while ($fc = $filterCategories->fetch_assoc()):
                            ?>
                                <option value="<?= $fc['category_id'] ?>"
                                    <?= $filterCategory == $fc['category_id'] ? 'selected' : '' ?>>
                                    <?= e($fc['category_name']) ?>
                                </option>
                            <?php endwhile; ?>

                        </select>
                    </div>

                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">All Status</option>

                            <?php foreach (['Available','Assigned','Under Maintenance','Retired','Missing'] as $s): ?>
                                <option value="<?= e($s) ?>"
                                    <?= $filterStatus === $s ? 'selected' : '' ?>>
                                    <?= e($s) ?>
                                </option>
                            <?php endforeach; ?>

                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button type="submit" class="btn btn-green">
                            Search
                        </button>

                        <a href="equipment.php" class="btn btn-secondary">
                            Reset
                        </a>
                    </div>

                </div>
            </form>

        </div>

        <!-- EQUIPMENT TABLE -->
        <div class="card p-3">

            <div class="table-responsive">

                <table class="table table-hover align-middle">

                    <thead class="table-success">
                        <tr>
                            <th>Asset Tag</th>
                            <th>Equipment Name</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Condition</th>
                            <th>Status</th>

                            <?php if ($isStaff): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if ($equipmentList->num_rows > 0): ?>

                        <?php while ($item = $equipmentList->fetch_assoc()): ?>

                            <tr>
                                <td class="fw-semibold">
                                    <?= e($item['asset_tag']) ?>
                                </td>

                                <td>
                                    <?= e($item['equipment_name']) ?>
                                    <br>
                                    <small class="text-muted">
                                        <?= e($item['brand']) ?>
                                    </small>
                                </td>

                                <td><?= e($item['category_name']) ?></td>

                                <td><?= e($item['location_name']) ?></td>

                                <td><?= e($item['equipment_condition']) ?></td>

                                <td>
                                    <?php
                                    $badge = match($item['status']) {
                                        'Available' => 'success',
                                        'Assigned' => 'primary',
                                        'Under Maintenance' => 'warning',
                                        'Retired' => 'secondary',
                                        'Missing' => 'danger',
                                        default => 'secondary'
                                    };
                                    ?>

                                    <span class="badge bg-<?= $badge ?>">
                                        <?= e($item['status']) ?>
                                    </span>
                                </td>

                                <?php if ($isStaff): ?>

                                    <td class="text-nowrap">

                                        <a href="equipment.php?edit=<?= (int)$item['equipment_id'] ?>"
                                           class="btn btn-sm btn-primary">
                                            Edit
                                        </a>

                                        <form method="POST"
                                              action="equipment.php"
                                              class="d-inline"
                                              onsubmit="return confirm('Are you sure you want to archive this equipment?');">

                                            <input type="hidden" name="csrf_token"
                                                   value="<?= e($_SESSION['csrf_token']) ?>">

                                            <input type="hidden" name="action"
                                                   value="archive">

                                            <input type="hidden" name="equipment_id"
                                                   value="<?= (int)$item['equipment_id'] ?>">

                                            <button type="submit"
                                                    class="btn btn-sm btn-danger">
                                                Archive
                                            </button>

                                        </form>

                                    </td>

                                <?php endif; ?>

                            </tr>

                        <?php endwhile; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="<?= $isStaff ? 7 : 6 ?>"
                                class="text-center text-muted py-4">
                                No equipment records found.
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
$stmt->close();
?>
