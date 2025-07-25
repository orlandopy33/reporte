<?php
$host = "localhost";
$user = "root";
$pass = "q1w2e3r4!!.2464";
$db = "callerid";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Error de conexión: " . $conn->connect_error);
}
?>
