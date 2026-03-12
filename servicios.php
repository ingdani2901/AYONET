<?php
/* AYONET · Servicios (Catálogo) - CON SOFT DELETE */
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
  header("Location: login.php");
  exit;
}

$ROL = strtolower($_SESSION['rol'] ?? 'cliente');
$ES_ADMIN = ($ROL === 'admin');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_servicios'])) {
  $_SESSION['csrf_servicios'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_servicios'];

/* ---------- Helpers ---------- */
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function rx($re, $s)
{
  return (bool) preg_match($re, (string) $s);
}
function nombreOK($s)
{
  return rx('/^[\p{L}0-9\s.\-+&()\/]{2,100}$/u', $s);
}
function esDecimal($s)
{
  return rx('/^\d{1,10}(\.\d{1,2})?$/', $s);
}

/* ---------- Carga para edición ---------- */
$edit = null;
if (!empty($_GET['editar'])) {
  $id = (int) $_GET['editar'];
  $q = $pdo->prepare("SELECT id_servicio, nombre_servicio, precio_base, COALESCE(activo,true) AS activo FROM public.servicios WHERE id_servicio=:id AND (eliminado = FALSE OR eliminado IS NULL) LIMIT 1");
  $q->execute([':id' => $id]);
  $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
}

/* ---------- Acciones POST (solo admin) ---------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
  if (!$ES_ADMIN) {
    $flash = ['error', 'Acceso restringido', 'Solo un administrador puede modificar servicios.'];
  } else {
    $accion = $_POST['accion'] ?? '';
    try {
      if ($accion === 'crear' || $accion === 'actualizar') {
        $id = isset($_POST['id_servicio']) ? (int) $_POST['id_servicio'] : 0;
        $nombre = trim($_POST['nombre_servicio'] ?? '');
        $precio = trim($_POST['precio_base'] ?? '');
        $activo = isset($_POST['activo']) ? 1 : 0;

        // Validación
        $err = [];
        if ($nombre === '' || !nombreOK($nombre))
          $err[] = 'Nombre inválido (2–100 caracteres).';
        if ($precio === '' || !esDecimal($precio))
          $err[] = 'Precio inválido (ej. 350 o 350.00).';
        if ($err)
          throw new RuntimeException(implode("\n", $err));

        // Duplicado por nombre (case-insensitive) - excluyendo eliminados
        $q = $pdo->prepare("SELECT COUNT(*) FROM public.servicios WHERE LOWER(nombre_servicio)=LOWER(:n) AND id_servicio<>:id AND (eliminado = FALSE OR eliminado IS NULL)");
        $q->execute([':n' => $nombre, ':id' => $id]);
        if ((int) $q->fetchColumn() > 0)
          throw new RuntimeException('Ya existe un servicio con ese nombre.');

        if ($accion === 'crear') {
          $ins = $pdo->prepare("
            INSERT INTO public.servicios (nombre_servicio, precio_base, activo, eliminado)
            VALUES (:n, :p, :a, FALSE)
          ");
          $ins->execute([':n' => $nombre, ':p' => number_format((float) $precio, 2, '.', ''), ':a' => $activo ? 1 : 0]);
          $flash = ['ok', 'Servicio creado', 'El servicio ha sido registrado exitosamente.'];
        } else {
          if ($id <= 0)
            throw new RuntimeException('ID inválido para actualizar.');
          $up = $pdo->prepare("
            UPDATE public.servicios
               SET nombre_servicio=:n, precio_base=:p, activo=:a
             WHERE id_servicio=:id AND (eliminado = FALSE OR eliminado IS NULL)
          ");
          $up->execute([
            ':n' => $nombre,
            ':p' => number_format((float) $precio, 2, '.', ''),
            ':a' => $activo ? 1 : 0,
            ':id' => $id
          ]);
          $flash = ['ok', 'Servicio actualizado', 'Los cambios han sido guardados.'];
        }
      }

      if ($accion === 'toggle_activo') {
        $id = (int) ($_POST['id_servicio'] ?? 0);
        $nuevo = (int) ($_POST['nuevo'] ?? 0);
        if ($id <= 0)
          throw new RuntimeException('ID inválido.');
        $pdo->prepare("UPDATE public.servicios SET activo=:a WHERE id_servicio=:id AND (eliminado = FALSE OR eliminado IS NULL)")
          ->execute([':a' => $nuevo ? 1 : 0, ':id' => $id]);
        $flash = ['ok', $nuevo ? 'Servicio activado' : 'Servicio desactivado', 'El estado ha sido actualizado.'];
      }

      // NUEVO: Eliminación lógica (soft delete)
      if ($accion === 'eliminar') {
        $id = (int) ($_POST['id_servicio'] ?? 0);
        if ($id <= 0)
          throw new RuntimeException('ID inválido.');

        // Verificar si el servicio está siendo usado en contratos activos
        $q = $pdo->prepare("SELECT COUNT(*) FROM contratos WHERE id_servicio = :id AND estado = 'activo'");
        $q->execute([':id' => $id]);
        $tieneContratos = (int) $q->fetchColumn() > 0;

        if ($tieneContratos) {
          throw new RuntimeException('No se puede eliminar el servicio porque está siendo usado en contratos activos.');
        }

        $pdo->prepare("UPDATE public.servicios SET eliminado = TRUE, activo = FALSE WHERE id_servicio=:id")
          ->execute([':id' => $id]);
        $flash = ['ok', 'Servicio eliminado', 'El servicio ha sido eliminado del listado.'];
      }

    } catch (Throwable $e) {
      $flash = ['error', 'Error', $e->getMessage() ?: 'No se pudo guardar.'];
    }
  }
}

/* ---------- Listado ---------- */
// SOLO servicios no eliminados
$servicios = $pdo->query("
  SELECT id_servicio, nombre_servicio, precio_base, COALESCE(activo,true) AS activo
    FROM public.servicios
   WHERE (eliminado = FALSE OR eliminado IS NULL)
   ORDER BY id_servicio DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- UI vars ---------- */
$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolUI = strtoupper($_SESSION['rol'] ?? 'USUARIO');
$isEdit = is_array($edit);
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Servicios</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
  <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
  <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --neon1: #00d4ff;
      --neon2: #6a00ff;
      --neon3: #ff007a;
      --panel: #0f163b;
      --muted: #cfe1ff;
      --glass: rgba(255, 255, 255, .07);
      --danger: #ff4757;
    }

    * {
      box-sizing: border-box
    }

    html,
    body {
      height: 100%
    }

    body {
      margin: 0;
      font-family: "Poppins", sans-serif;
      color: #fff;
      background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
    }

    .bg::before,
    .bg::after {
      content: "";
      position: fixed;
      z-index: -2;
      width: 65vmax;
      height: 65vmax;
      border-radius: 50%;
      filter: blur(90px);
      opacity: .35;
      animation: float 18s ease-in-out infinite;
    }

    .bg::before {
      background: radial-gradient(closest-side, var(--neon2), transparent 65%);
      top: -20vmax;
      left: -10vmax;
    }

    .bg::after {
      background: radial-gradient(closest-side, var(--neon3), transparent 65%);
      bottom: -25vmax;
      right: -15vmax;
      animation-delay: -6s;
    }

    @keyframes float {
      0% {
        transform: translateY(0)
      }

      50% {
        transform: translateY(-30px)
      }

      100% {
        transform: translateY(0)
      }
    }

    .wrap {
      min-height: 100vh;
      display: grid;
      grid-template-rows: 64px 1fr;
      gap: 12px;
      padding: 12px;
    }

    .topbar {
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      border-radius: 16px;
      backdrop-filter: blur(12px);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 12px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .logo {
      width: 32px;
      height: 32px;
      border-radius: 10px;
      background: radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%), radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%), #0c1133;
      border: 1px solid rgba(255, 255, 255, .18);
    }

    .pill {
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #051027;
      font-weight: 800;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: .78rem
    }

    .top-actions {
      display: flex;
      gap: 8px;
      align-items: center
    }

    .btn {
      border: none;
      border-radius: 10px;
      padding: 8px 12px;
      cursor: pointer;
      font-weight: 700;
      font-size: 0.85rem;
    }

    .ghost {
      background: rgba(255, 255, 255, .08);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, .16)
    }

    .primary {
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #061022
    }

    .btn-danger {
      background: rgba(255, 71, 87, 0.2);
      color: #ff4757;
      border: 1px solid rgba(255, 71, 87, 0.4);
    }

    .btn-danger:hover {
      background: rgba(255, 71, 87, 0.3);
      border-color: #ff4757;
    }

    .btn:disabled {
      opacity: .55;
      cursor: not-allowed
    }

    .panel {
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      border-radius: 16px;
      backdrop-filter: blur(12px);
      padding: 12px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
    }

    .grid {
      height: 100%;
      display: grid;
      grid-template-columns: 350px 1fr;
      gap: 12px;
      align-items: start;
    }

    @media (max-width:1180px) {
      .grid {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr;
      }
    }

    .card {
      background: var(--glass);
      border: 1px solid rgba(255, 255, 255, .12);
      border-radius: 14px;
      padding: 15px;
    }

    .grid>.card:first-child {
      height: fit-content;
      position: sticky;
      top: 12px;
    }

    .card h3 {
      margin: 0 0 12px;
      font-size: 1.2rem;
    }

    .lab {
      font-size: .85rem;
      color: #d8e4ff;
      display: block;
      margin-bottom: 4px;
    }

    .ctrl {
      width: 100%;
      margin: 0 0 12px;
      padding: 10px;
      border-radius: 10px;
      background: rgba(255, 255, 255, .08);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, .16);
      outline: none;
      font-size: 0.9rem;
    }

    .ctrl::placeholder {
      color: #d8e4ff97
    }

    .table-container {
      overflow: hidden;
      border-radius: 12px;
      border: 1px solid rgba(255, 255, 255, .12);
    }

    .table-ayanet {
      width: 100%;
      border-collapse: collapse;
      background: rgba(255, 255, 255, .04);
    }

    th,
    td {
      padding: 10px 8px;
      text-align: left;
      white-space: normal;
      word-wrap: break-word;
      word-break: break-word;
      font-size: 0.85rem;
    }

    th {
      color: #cfe1ff;
      font-weight: 700;
      background: rgba(255, 255, 255, .08);
    }

    tbody tr {
      background: rgba(255, 255, 255, .06);
      border-bottom: 1px solid rgba(255, 255, 255, .08);
    }

    tbody tr:hover {
      background: rgba(255, 255, 255, .09);
    }

    .actions {
      white-space: nowrap;
    }

    .actions .btn {
      margin-right: 6px;
      padding: 6px 10px;
      font-size: 0.8rem;
    }

    .toggle {
      appearance: none;
      width: 42px;
      height: 26px;
      border-radius: 999px;
      position: relative;
      outline: none;
      background: #7b7b7b;
      cursor: pointer;
      transition: .2s;
      border: 1px solid rgba(255, 255, 255, .2)
    }

    .toggle::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 20px;
      height: 20px;
      border-radius: 50%;
      background: #fff;
      transition: .2s
    }

    .toggle:checked {
      background: #2dd4bf
    }

    .toggle:checked::after {
      left: 19px
    }

    .dataTables_wrapper .dataTables_length select,
    .dataTables_wrapper .dataTables_filter input {
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .16);
      color: #fff;
      border-radius: 8px;
      padding: 6px
    }

    .dataTables_wrapper .dataTables_info {
      color: #cfe1ff
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
      color: #fff !important;
      border: 1px solid rgba(255, 255, 255, .16);
      background: rgba(255, 255, 255, .06);
      border-radius: 8px;
      margin: 0 2px
    }

    .form-actions {
      display: flex;
      gap: 8px;
      margin-top: 15px;
      flex-wrap: wrap;
    }

    .btn-small {
      padding: 6px 10px;
      font-size: 0.8rem;
    }

    .admin-only {
      margin-top: 10px;
      color: #cfe1ff;
      font-size: 0.8rem;
      text-align: center;
      padding: 8px;
      background: rgba(255, 255, 255, .05);
      border-radius: 8px;
    }
  </style>
</head>

<body class="bg">
  <div class="wrap">
    <header class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <div style="font-weight:700;letter-spacing:.3px">AYONET · Servicios</div>
          <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
        </div>
      </div>
      <div class="top-actions">
        <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
        <span class="pill"><?= h($rolUI) ?></span>
      </div>
    </header>

    <section class="panel">
      <div class="grid">
        <!-- Formulario -->
        <div class="card">
          <h3><?= $isEdit ? 'Editar servicio' : 'Nuevo servicio' ?></h3>
          <form method="post" onsubmit="return validar()" <?= $ES_ADMIN ? '' : 'style="opacity:.65;pointer-events:none" title="Solo admin puede modificar"' ?>>
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="accion" value="<?= $isEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($isEdit): ?>
              <input type="hidden" name="id_servicio" value="<?= (int) $edit['id_servicio'] ?>">
            <?php endif; ?>

            <label class="lab">Nombre del servicio</label>
            <input class="ctrl" type="text" name="nombre_servicio" id="nombre_servicio"
              value="<?= h($isEdit ? ($edit['nombre_servicio'] ?? '') : '') ?>" placeholder="Ej. Fibra 100 Mbps"
              required>

            <label class="lab">Precio base (MXN)</label>
            <input class="ctrl" type="number" name="precio_base" id="precio_base" step="0.01" inputmode="decimal"
              value="<?= h($isEdit ? ($edit['precio_base'] ?? '') : '') ?>" placeholder="Ej. 350.00" required>

            <label class="lab" style="display:flex;align-items:center;gap:8px">
              <input type="checkbox" class="toggle" name="activo" <?= $isEdit ? (($edit['activo'] ?? true) ? 'checked' : '') : 'checked' ?>>
              Activo (visible para contratación)
            </label>

            <div class="form-actions">
              <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i>
                <?= $isEdit ? 'Actualizar' : 'Guardar' ?></button>
              <?php if ($isEdit): ?>
                <a class="btn ghost btn-small" href="servicios.php"><i class="fa-solid fa-times"></i> Cancelar</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$ES_ADMIN): ?>
            <div class="admin-only">Solo los administradores pueden crear o editar servicios.</div>
          <?php endif; ?>
        </div>

        <!-- Tabla CON BOTÓN ELIMINAR -->
        <div class="card">
          <h3>Listado de Servicios</h3>
          <div class="table-container">
            <table id="tablaServicios" class="display compact table-ayanet" style="width:100%">
              <thead>
                <tr>
                  <th>Servicio</th>
                  <th>Precio</th>
                  <th>Activo</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($servicios as $s): ?>
                  <tr>
                    <td><?= h($s['nombre_servicio']) ?></td>
                    <td>$<?= number_format((float) $s['precio_base'], 2) ?></td>
                    <td>
                      <?php if ($ES_ADMIN): ?>
                        <form method="post" style="display:inline" onsubmit="return toggleActivo(event)">
                          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                          <input type="hidden" name="accion" value="toggle_activo">
                          <input type="hidden" name="id_servicio" value="<?= (int) $s['id_servicio'] ?>">
                          <input type="hidden" name="nuevo" value="<?= $s['activo'] ? 0 : 1 ?>">
                          <input type="checkbox" class="toggle" <?= $s['activo'] ? 'checked' : '' ?>
                            onchange="this.form.submit()" title="Cambiar estado activo">
                        </form>
                      <?php else: ?>
                        <span style="color: <?= $s['activo'] ? '#2dd4bf' : '#ff5a8f' ?>; font-weight: 600;">
                          <?= $s['activo'] ? '✅ Activo' : '❌ Inactivo' ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="actions">
                      <?php if ($ES_ADMIN): ?>
                        <button class="btn ghost btn-small" onclick="editar(<?= (int) $s['id_servicio'] ?>)">
                          <i class="fa-regular fa-pen-to-square"></i> Editar
                        </button>
                        <button class="btn btn-danger btn-small"
                          onclick="eliminarServicio(<?= (int) $s['id_servicio'] ?>, '<?= h($s['nombre_servicio']) ?>')">
                          <i class="fa-regular fa-trash-can"></i> Eliminar
                        </button>
                      <?php else: ?>
                        <span style="color: var(--muted); font-size: 0.8rem;">Solo lectura</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>
  </div>

  <?php if ($flash): ?>
    <script>
      Swal.fire({
        icon: <?= json_encode($flash[0] === 'ok' ? 'success' : 'error') ?>,
        title: <?= json_encode($flash[1]) ?>,
        text: <?= json_encode($flash[2]) ?>,
        timer: 3000
      });
    </script>
  <?php endif; ?>

  <script>
    // Validación ligera front
    function validar() {
      const nom = document.getElementById('nombre_servicio').value.trim();
      const pre = document.getElementById('precio_base').value.trim();
      const rxNom = /^[\p{L}0-9\s.\-+&()\/]{2,100}$/u;
      const rxPre = /^\d{1,10}(\.\d{1,2})?$/;

      if (!rxNom.test(nom)) {
        Swal.fire({ icon: 'error', title: 'Nombre inválido', text: 'Usa 2–100 caracteres.' });
        return false;
      }
      if (!rxPre.test(pre)) {
        Swal.fire({ icon: 'error', title: 'Precio inválido', text: 'Ejemplo: 350 o 350.00' });
        return false;
      }
      return true;
    }

    // Confirmación para EDITAR: redirige a ?editar=ID
    function editar(id) {
      Swal.fire({
        title: '¿Editar servicio?',
        text: 'Se cargará la información para modificarla.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, editar',
        cancelButtonText: 'Cancelar'
      }).then(r => {
        if (r.isConfirmed) {
          location.href = 'servicios.php?editar=' + id;
        }
      });
    }

    function toggleActivo(event) {
      event.preventDefault();
      const form = event.target;
      Swal.fire({
        title: '¿Cambiar estado?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, cambiar',
        cancelButtonText: 'Cancelar'
      }).then((result) => {
        if (result.isConfirmed) {
          form.submit();
        }
      });
    }

    // NUEVA FUNCIÓN: Eliminar servicio
    function eliminarServicio(id, nombre) {
      Swal.fire({
        title: '¿Eliminar servicio?',
        html: `¿Estás seguro de que quieres eliminar el servicio:<br><strong>"${nombre}"</strong>?<br><small>Esta acción no se puede deshacer.</small>`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ff4757',
        background: '#0c1133',
        color: '#fff'
      }).then((result) => {
        if (result.isConfirmed) {
          // Crear formulario temporal para eliminar
          const form = document.createElement('form');
          form.method = 'post';
          form.action = 'servicios.php';

          const csrf = document.createElement('input');
          csrf.name = 'csrf';
          csrf.value = '<?= h($CSRF) ?>';
          form.appendChild(csrf);

          const accion = document.createElement('input');
          accion.name = 'accion';
          accion.value = 'eliminar';
          form.appendChild(accion);

          const idInput = document.createElement('input');
          idInput.name = 'id_servicio';
          idInput.value = id;
          form.appendChild(idInput);

          document.body.appendChild(form);
          form.submit();
        }
      });
    }

    // DataTables
    $(function () {
      $('#tablaServicios').DataTable({
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        searching: true,
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
        order: [[0, 'asc']],
        responsive: false,
        autoWidth: false,
        language: {
          decimal: '',
          emptyTable: 'No hay servicios registrados',
          info: 'Mostrando _START_ a _END_ de _TOTAL_ servicios',
          infoEmpty: 'Mostrando 0 a 0 de 0 servicios',
          infoFiltered: '(filtrado de _MAX_ servicios totales)',
          lengthMenu: 'Mostrar _MENU_ servicios',
          loadingRecords: 'Cargando...',
          processing: 'Procesando...',
          search: 'Buscar:',
          zeroRecords: 'No se encontraron servicios coincidentes',
          paginate: {
            first: 'Primero',
            last: 'Último',
            next: 'Siguiente',
            previous: 'Anterior'
          }
        }
      });
    });
  </script>
</body>

</html>