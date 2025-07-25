<?php include 'db.php'; ?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>Gestión de Clientes - Caller ID</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8f9fa; }
    .container { max-width: 1000px; }
    .table thead { background-color: #343a40; color: white; }
  </style>
</head>
<body class="container mt-4">
  <h3 class="mb-4 text-center">📞 Contactos de Caller ID</h3>

  <form action="" method="get" class="row g-2 mb-4">
    <div class="col-md-6">
      <input type="text" name="search" class="form-control" placeholder="Buscar por nombre, apellido, número o grupo" value="<?= isset($_GET['search']) ? htmlspecialchars($_GET['search']) : '' ?>">
    </div>
    <div class="col-md-4">
      <button class="btn btn-outline-primary w-100" type="submit">Buscar</button>
    </div>
    <div class="col-md-2">
      <a href="?delete_group=1&search=<?= isset($_GET['search']) ? urlencode($_GET['search']) : '' ?>" onclick="return confirm('¿Eliminar todos los contactos de este grupo?')" class="btn btn-outline-danger w-100">Borrar grupo</a>
    </div>
  </form>

  <form action="add.php" method="post" class="row g-3 mb-4">
    <div class="col-md-2">
      <input type="text" name="numero" class="form-control" placeholder="Número" required>
    </div>
    <div class="col-md-2">
      <input type="text" name="nombre" class="form-control" placeholder="Nombre" required>
    </div>
    <div class="col-md-2">
      <input type="text" name="apellido" class="form-control" placeholder="Apellido" required>
    </div>
    <div class="col-md-3">
      <input type="text" name="grupo" class="form-control" placeholder="Grupo (ej: fibra col)" required>
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary w-100" type="submit">Agregar</button>
    </div>
  </form>

  <form action="import.php" method="post" enctype="multipart/form-data" class="mb-4">
    <div class="row g-3">
      <div class="col-md-6">
        <input type="file" name="csv" accept=".csv" class="form-control" required>
      </div>
      <div class="col-md-6">
        <input type="text" name="grupo" class="form-control" placeholder="Grupo para importación" required>
      </div>
    </div>
    <button class="btn btn-success mt-2 w-100" type="submit">Importar desde CSV</button>
  </form>

  <table class="table table-bordered">
    <thead>
      <tr>
        <th>Número</th><th>Nombre</th><th>Apellido</th><th>Grupo</th><th>Acciones</th>
      </tr>
    </thead>
    <tbody>
      <?php
      if (isset($_GET['delete_group']) && isset($_GET['search'])) {
        $grp = $conn->real_escape_string($_GET['search']);
        $conn->query("DELETE FROM clientes WHERE grupo LIKE '%$grp%'");
        header("Location: index.php");
        exit;
      }

      $cond = "";
      $q = "";
      if (!empty($_GET['search'])) {
        $q = $conn->real_escape_string($_GET['search']);
        $cond = "WHERE numero LIKE '%$q%' OR nombre LIKE '%$q%' OR apellido LIKE '%$q%' OR grupo LIKE '%$q%'";
      }

      $limit = 30;
      $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
      $offset = ($page - 1) * $limit;

      $total_res = $conn->query("SELECT COUNT(*) AS total FROM clientes $cond");
      $total_row = $total_res->fetch_assoc();
      $total = $total_row['total'];
      $pages = ceil($total / $limit);

      $res = $conn->query("SELECT * FROM clientes $cond ORDER BY nombre LIMIT $limit OFFSET $offset");
      while ($row = $res->fetch_assoc()):
      ?>
        <tr>
          <td><?= $row['numero'] ?></td>
          <td><?= $row['nombre'] ?></td>
          <td><?= $row['apellido'] ?></td>
          <td><?= $row['grupo'] ?></td>
          <td>
            <a href="edit.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-warning">Editar</a>
            <a href="delete.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Eliminar?')">Eliminar</a>
          </td>
        </tr>
      <?php endwhile; ?>
    </tbody>
  </table>

  <nav>
    <ul class="pagination justify-content-center">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
          <a class="page-link" href="?search=<?= urlencode($q) ?>&page=<?= $i ?>"><?= $i ?></a>
        </li>
      <?php endfor; ?>
    </ul>
  </nav>
</body>
</html>