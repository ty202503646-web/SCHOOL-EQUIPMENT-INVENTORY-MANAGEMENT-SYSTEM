
<?php
// ======================================
// SCHOOL EQUIPMENT INVENTORY SYSTEM
// DATABASE CONNECTION
// ======================================

$host = "localhost";
$username = "root";
$password = "";
$database = "db_school_equipment_inventory";

$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

// Check database connection
if ($conn->connect_error) {
    die("Database Connection Failed: " .
        $conn->connect_error);
}

// Set character encoding
$conn->set_charset("utf8mb4");

?>
