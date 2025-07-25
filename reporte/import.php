<?php
include 'db.php';
if (isset($_FILES['csv']) && $_FILES['csv']['error'] == 0 && isset($_POST['grupo'])) {
    $grupo = $conn->real_escape_string($_POST['grupo']);
    $file = fopen($_FILES['csv']['tmp_name'], 'r');
    while (($data = fgetcsv($file, 1000, ",")) !== FALSE) {
        $numero = $conn->real_escape_string($data[0]);
        $nombre = $conn->real_escape_string($data[1]);
        $apellido = $conn->real_escape_string($data[2]);
        $conn->query("INSERT INTO clientes (numero, nombre, apellido, grupo) VALUES ('$numero', '$nombre', '$apellido', '$grupo')");
    }
    fclose($file);
}
header('Location: index.php');