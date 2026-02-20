<?php
require "admin/db.php";   // ✅ CORRECT


$username = "admin";
$password = password_hash("admin123", PASSWORD_DEFAULT);
$role = "admin";

$sql = "INSERT IGNORE INTO users (username, password, role)
        VALUES ('$username', '$password', '$role')";

if (mysqli_query($conn, $sql)) {
    if (mysqli_affected_rows($conn) > 0) {
        echo "Admin created successfully";
    } else {
        echo "Admin already exists";
    }
} else {
    echo "Error: " . mysqli_error($conn);
}
?>
