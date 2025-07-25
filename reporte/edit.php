<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'];
    $numero = $_POST['numero'];
    $nombre = $_POST['nombre'];
    $apellido = $_POST['apellido'];
    $grupo = $_POST['grupo'];

    $conn->query("UPDATE clientes SET numero='$numero', nombre='$nombre', apellido='$apellido', grupo='$grupo' WHERE id=$id");
    header('Location: index.php');
    exit;
}

$id = $_GET['id'];
$res = $conn->query("SELECT * FROM clientes WHERE id=$id");
$data = $res->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><title>Editar</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="container mt-4">
  <h3>Editar contacto</h3>
  <form method="post">
    <input type="hidden" name="id" value="<?= $data['id'] ?>">
    <div class="mb-3">
      <label>Número</label>
      <input type="text" name="numero" value="<?= $data['numero'] ?>" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>Nombre</label>
      <input type="text" name="nombre" value="<?= $data['nombre'] ?>" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>Apellido</label>
      <input type="text" name="apellido" value="<?= $data['apellido'] ?>" class="form-control" required>
    </div>
    <div class="mb-3">
      <label>Grupo</label>
      <input type="text" name="grupo" value="<?= $data['grupo'] ?>" class="form-control" required>
    </div>
    <button class="btn btn-primary">Guardar cambios</button>
    <a href="index.php" class="btn btn-secondary">Volver</a>
  </form>
</body>
</html>