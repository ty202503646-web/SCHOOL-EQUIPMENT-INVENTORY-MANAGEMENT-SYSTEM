
<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

requireLogin();

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$isAdmin = ($_SESSION['role_name'] ?? '') === 'Administrator';
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
$editCategory = null;

// ======================================
// HANDLE FORM SUBMISSIONS
// ======================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $token)) {
        $error = "Invalid request. Please refresh the page.";

    } elseif (!$isStaff) {
        http_response_code(403);
        exit("You are not authorized to modify categories.");

    } else {

        $action = $_POST['action'] ?? '';

        // ADD CATEGORY
        if ($action === 'add') {

            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($name === '' || strlen($name) > 100) {
                $error = "Category name is required (maximum 100 characters).";

            } else {

                $stmt = $conn->prepare(
                    "INSERT INTO categories (category_name, description)
                     VALUES (?, NULLIF(?, ''))"
                );

                $stmt->bind_param("ss", $name, $description);

                try {
                    if ($stmt->execute()) {
                        $success = "Category added successfully!";
                    }
                } catch (mysqli_sql_exception $ex) {
                    $error = "Category name already exists.";
                }

                $stmt->close();
            }
        }

        // EDIT CATEGORY
        elseif ($action === 'edit') {

            $id = (int)($_POST['category_id'] ?? 0);
            $name = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($id <= 0 || $name === '' || strlen($name) > 100) {
                $error = "Please enter a valid category name.";

            } else {

                $stmt = $conn->prepare(
                    "UPDATE categories
                     SET category_name = ?, description = ?
                     WHERE category_id = ?
                     AND archived_at IS NULL"
                );

                $stmt->bind_param("ssi", $name, $description, $id);

                try {
                    if ($stmt->execute()) {
                        $success = "Category updated successfully!";
                    }
                } catch (mysqli_sql_exception $ex) {
                    $error = "Category name already exists.";
                }

                $stmt->close();
            }
        }

        // ARCHIVE CATEGORY
        elseif ($action === 'archive') {

            $id = (int)($_POST['category_id'] ?? 0);

            // Check if category is used by equipment
            $check = $conn->prepare(
                "SELECT COUNT(*) AS total
                 FROM equipment
                 WHERE category_id = ?"
            );

            $check->bind_param("i", $id);
            $check->execute();

            $used = (int)$check->get_result()
                ->fetch_assoc()['total'];

            $check->close();

            if ($id <= 0) {
                $error = "Invalid category.";

            } elseif ($used > 0) {
                $error = "This category is currently linked to equipment. It cannot be archived.";

            } else {

                $stmt = $conn->prepare(
                    "UPDATE categories
                     SET archived_at = NOW()
                     WHERE category_id = ?
                     AND archived_at IS NULL"
                );

                $stmt->bind_param("i", $id);
                $stmt->execute();

                if ($stmt->affected_rows > 0) {
                    $success = "Category archived successfully!";
                } else {
                    $error = "Category not found or already archived.";
                }

                $stmt->close();
            }
        }
    }
}

// ======================================
// LOAD CATEGORY FOR EDITING
// ======================================

if (isset($_GET['edit']) && $isStaff) {

    $id = (int)$_GET['edit'];

    $stmt = $conn->prepare(
        "SELECT *
         FROM categories
         WHERE category_id = ?
         AND archived_at IS NULL"
    );

    $stmt->bind_param("i", $id);
    $stmt->execute();

    $editCategory = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// ======================================
// SEARCH CATEGORIES
// ======================================

$search = trim($_GET['search'] ?? '');

$sql = "SELECT *
        FROM categories
        WHERE archived_at IS NULL";

$params = [];
$types = '';

if ($search !== '') {
    $sql .= " AND (category_name LIKE ? OR description LIKE ?)";
    $keyword = "%$search%";

    $params = [$keyword, $keyword];
    $types = "ss";
}

$sql .= " ORDER BY category_name ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$categoryList = $stmt->get_result();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Categories | SEIMS</title>

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

        <a href="categories.php" class="active">Categories</a>

        <a href="locations.php">Locations</a>

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

            <h3 class="brand fw-bold">Categories Management</h3>

            <p class="text-muted">
                Organize and manage school equipment categories.
            </p>

        </div>


        <!-- MESSAGES -->

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


        <!-- ADD / EDIT CATEGORY -->

        <?php if ($isStaff): ?>

        <div class="card p-4 mb-4">

            <h5 class="brand fw-bold mb-3">

                <?= $editCategory
                    ? 'Edit Category'
                    : 'Add New Category' ?>

            </h5>

            <form method="POST" action="categories.php">

                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">

                <input type="hidden" name="action"
                       value="<?= $editCategory ? 'edit' : 'add' ?>">

                <?php if ($editCategory): ?>

                    <input type="hidden" name="category_id"
                           value="<?= (int)$editCategory['category_id'] ?>">

                <?php endif; ?>


                <div class="row g-3">

                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Category Name *
                        </label>

                        <input type="text"
                               name="category_name"
                               class="form-control"
                               maxlength="100"
                               required
                               placeholder="Enter category name"
                               value="<?= e($editCategory['category_name'] ?? '') ?>">

                    </div>


                    <div class="col-md-6">

                        <label class="form-label fw-semibold">
                            Description
                        </label>

                        <input type="text"
                               name="description"
                               class="form-control"
                               placeholder="Enter category description"
                               value="<?= e($editCategory['description'] ?? '') ?>">

                    </div>


                    <div class="col-12">

                        <button type="submit" class="btn btn-green px-4">

                            <?= $editCategory
                                ? 'Update Category'
                                : 'Save Category' ?>

                        </button>


                        <?php if ($editCategory): ?>

                            <a href="categories.php"
                               class="btn btn-secondary">
                                Cancel
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </form>

        </div>

        <?php endif; ?>


        <!-- CATEGORY LIST -->

        <div class="card p-4">

            <h5 class="fw-bold mb-3">Category Records</h5>


            <!-- SEARCH -->

            <form method="GET" action="categories.php"
                  class="mb-3">

                <div class="row g-2">

                    <div class="col-md-8">

                        <input type="text"
                               name="search"
                               class="form-control"
                               placeholder="Search category..."
                               value="<?= e($search) ?>">

                    </div>

                    <div class="col-md-4 d-flex gap-2">

                        <button type="submit"
                                class="btn btn-green">
                            Search
                        </button>

                        <a href="categories.php"
                           class="btn btn-secondary">
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

                            <th>Category ID</th>

                            <th>Category Name</th>

                            <th>Description</th>

                            <th>Date Created</th>

                            <?php if ($isStaff): ?>
                                <th>Actions</th>
                            <?php endif; ?>

                        </tr>

                    </thead>


                    <tbody>

                    <?php if ($categoryList->num_rows > 0): ?>

                        <?php while ($category = $categoryList->fetch_assoc()): ?>

                            <tr>

                                <td>
                                    <?= (int)$category['category_id'] ?>
                                </td>

                                <td class="fw-semibold">
                                    <?= e($category['category_name']) ?>
                                </td>

                                <td>
                                    <?= e($category['description'] ?: 'N/A') ?>
                                </td>

                                <td>
                                    <?= e(date(
                                        'M d, Y',
                                        strtotime($category['created_at'])
                                    )) ?>
                                </td>


                                <?php if ($isStaff): ?>

                                <td class="text-nowrap">

                                    <a href="categories.php?edit=<?= (int)$category['category_id'] ?>"
                                       class="btn btn-sm btn-primary">
                                        Edit
                                    </a>


                                    <form method="POST"
                                          action="categories.php"
                                          class="d-inline"
                                          onsubmit="return confirm('Are you sure you want to archive this category?');">

                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($_SESSION['csrf_token']) ?>">

                                        <input type="hidden"
                                               name="action"
                                               value="archive">

                                        <input type="hidden"
                                               name="category_id"
                                               value="<?= (int)$category['category_id'] ?>">

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

                            <td colspan="<?= $isStaff ? 5 : 4 ?>"
                                class="text-center text-muted py-4">

                                No category records found.

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
