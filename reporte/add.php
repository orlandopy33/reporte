<?php
include 'db.php';
$numero = $_POST['numero'];
$nombre = $_POST['nombre'];
$apellido = $_POST['apellido'];
$grupo = $_POST['grupo'];
$conn->query("INSERT INTO clientes (numero, nombre, apellido, grupo) VALUES ('$numero', '$nombre', '$apellido', '$grupo')");
header('Location: index.php');