<?php
$host = "localhost";
$user = "root";
$pass = "";
$db   = "db_amc_clinic";

$conn = mysqli_connect($host, $user, $pass, $db);

if (!$conn) {
    die("Eroare conexiune MySQL: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
?>
