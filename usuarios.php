<?php
/* AYONET · proayonet — Usuarios (Solo ADMIN)
 * - Este módulo solo crea/edita ADMINISTRADORES.
 * - Lista a todos los usuarios (clientes, técnicos y admins) como referencia.
 * - Sin creación/vínculo de clientes/técnicos aquí (se hace en sus propios módulos).
 * - Soft delete con usuarios.eliminado.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
  header("Location: login.php");
  exit;
}
if (strtolower($_SESSION['rol'] ?? '') !== 'admin') {
  http_response_code(403);
  echo "Acceso restringido (solo admin).";
  exit;
}

$ME_ID = (int) ($_SESSION['id_usuario'] ?? 0);
$ME_NAME = $_SESSION['nombre'] ?? 'ADMIN';

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_usuarios']))
  $_SESSION['csrf_usuarios'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_usuarios'];

/* ---------- Helpers ---------- */
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function pass_ok($p)
{
  $letters = preg_match_all('/[A-Za-zÁÉÍÓÚÑáéíóúñ]/u', $p);
  $digits = preg_match_all('/\d/', $p);
  $special = preg_match_all('/[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9]/u', $p);
  return $letters >= 6 && $digits >= 2 && $special >= 1;
}

/* ---------- Rol administrador (ID) ---------- */
$ADMIN_ROLE_ID = (int) $pdo->query("SELECT id_rol FROM public.roles WHERE LOWER(nombre_rol)='administrador' LIMIT 1")->fetchColumn();
if (!$ADMIN_ROLE_ID) {
  http_response_code(500);
  die('No existe el rol \"administrador\" en la tabla roles.');
}

/* ---------- Catálogos (solo mostramos ADMIN en el form) ---------- */
$roles = [
  ['id_rol' => $ADMIN_ROLE_ID, 'nombre_rol' => 'administrador']
];

/* ---------- CRUD ---------- */
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
  $accion = $_POST['accion'] ?? '';
  try {
    if ($accion === 'crear' || $accion === 'actualizar') {
      $id = (int) ($_POST['id_usuario'] ?? 0);
      $nombre = trim($_POST['nombre_completo'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $telefono = trim($_POST['telefono'] ?? '');
      // Forzamos rol admin SIEMPRE en backend
      $rol_id = $ADMIN_ROLE_ID;

      $password = $_POST['password'] ?? '';
      $new_password = $_POST['new_password'] ?? '';

      if ($nombre === '' || $email === '')
        throw new RuntimeException('Faltan campos obligatorios.');
      if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new RuntimeException('Email inválido.');
      if ($accion === 'crear' && !pass_ok($password))
        throw new RuntimeException('Contraseña débil (mín. 6 letras, 2 números y 1 especial).');

      // Duplicados por email
      $q = $pdo->prepare("SELECT COUNT(*) FROM public.usuarios WHERE LOWER(email)=LOWER(:e) AND id_usuario<>:id");
      $q->execute([':e' => $email, ':id' => $id]);
      if ($q->fetchColumn() > 0)
        throw new RuntimeException('Ya existe un usuario con ese email.');

      if ($accion === 'crear') {
        // Insert usuario admin
        $pdo->prepare("
          INSERT INTO public.usuarios
            (id_rol, nombre_completo, email, telefono, password_hash, fecha_creacion, eliminado)
          VALUES (:r,:n,:e,:t,:p,NOW(),FALSE)
        ")->execute([
              ':r' => $rol_id,
              ':n' => $nombre,
              ':e' => $email,
              ':t' => ($telefono ?: null),
              ':p' => password_hash($password, PASSWORD_DEFAULT)
            ]);

        $flash = ['ok', 'Administrador creado'];

      } else { // actualizar
        if ($id <= 0)
          throw new RuntimeException('ID inválido');
        if ($id === $ME_ID)
          throw new RuntimeException('No puedes editarte a ti mismo.');

        // Verifica que el usuario objetivo sea ADMIN; si no, aquí no lo editamos
        $currRole = (int) $pdo->prepare("SELECT id_rol FROM public.usuarios WHERE id_usuario=:id")
          ->execute([':id' => $id]) ?
          (int) $pdo->query("SELECT id_rol FROM public.usuarios WHERE id_usuario=" . $id)->fetchColumn() : 0;
        if ($currRole !== $ADMIN_ROLE_ID) {
          throw new RuntimeException('Este módulo solo edita administradores. Usa Clientes/Técnicos para los demás.');
        }

        $pdo->beginTransaction();
        $pdo->prepare("
          UPDATE public.usuarios
             SET id_rol=:r, nombre_completo=:n, email=:e, telefono=:t
           WHERE id_usuario=:id
        ")->execute([
              ':r' => $rol_id,
              ':n' => $nombre,
              ':e' => $email,
              ':t' => ($telefono ?: null),
              ':id' => $id
            ]);

        if ($new_password !== '') {
          if (!pass_ok($new_password))
            throw new RuntimeException('Contraseña nueva débil.');
          $pdo->prepare("UPDATE public.usuarios SET password_hash=:p WHERE id_usuario=:id")
            ->execute([':p' => password_hash($new_password, PASSWORD_DEFAULT), ':id' => $id]);
        }
        $pdo->commit();

        $flash = ['ok', 'Administrador actualizado'];
      }
    }

    if ($accion === 'toggle') {
      $id = (int) ($_POST['id_usuario'] ?? 0);
      $nuevo = (int) ($_POST['nuevo'] ?? 1);
      if ($id === $ME_ID)
        throw new RuntimeException('No puedes desactivarte.');

      // Solo permitimos activar/desactivar admins aquí
      $isAdmin = (int) $pdo->prepare("SELECT id_rol FROM public.usuarios WHERE id_usuario=:id")
        ->execute([':id' => $id]) ?
        (int) $pdo->query("SELECT id_rol FROM public.usuarios WHERE id_usuario=" . $id)->fetchColumn() : 0;
      if ($isAdmin !== $ADMIN_ROLE_ID) {
        throw new RuntimeException('Aquí solo puedes activar/desactivar administradores.');
      }

      $pdo->prepare("
        UPDATE public.usuarios 
           SET eliminado=:e, eliminado_en = CASE WHEN :e THEN NOW() ELSE NULL END 
         WHERE id_usuario=:id
      ")->execute([':e' => $nuevo ? 1 : 0, ':id' => $id]);

      $flash = ['ok', $nuevo ? 'Administrador desactivado' : 'Administrador activado'];
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction())
      $pdo->rollBack();
    $flash = ['error', 'Error', $e->getMessage()];
  }
}

/* ---------- Listado (todos los usuarios para consulta) ----------
 * === CORRECCIÓN: Se agrega "WHERE u.id_usuario <> :me_id" ===
 * Esto evita que el admin actual se vea a sí mismo en la lista.
 */
$q = $pdo->prepare("
SELECT
  u.id_usuario,
  u.nombre_completo,
  u.email,
  u.telefono,
  u.id_rol,
  r.nombre_rol,
  u.fecha_creacion,
  u.eliminado
FROM public.usuarios u
JOIN public.roles r ON r.id_rol = u.id_rol
WHERE u.id_usuario <> :me_id
ORDER BY u.id_usuario DESC
");
$q->execute([':me_id' => $ME_ID]);
$usuarios = $q->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Usuarios</title>

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

    /* Contenedor con scroll para el formulario */
    .form-container {
      max-height: calc(100vh - 200px);
      overflow-y: auto;
      padding-right: 5px;
    }

    /* Estilos personalizados para el scroll */
    .form-container::-webkit-scrollbar {
      width: 6px;
    }

    .form-container::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 3px;
    }

    .form-container::-webkit-scrollbar-thumb {
      background: var(--neon1);
      border-radius: 3px;
    }

    .form-container::-webkit-scrollbar-thumb:hover {
      background: var(--neon3);
    }

    /* password eye */
    .pwd-wrap {
      position: relative;
    }

    .eye-btn {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      background: transparent;
      border: none;
      color: #fff;
      cursor: pointer;
      padding: 0;
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

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 500;
    }

    .badge-success {
      background: #dcfce7;
      color: #166534;
    }

    .badge-danger {
      background: #fef2f2;
      color: #dc2626;
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

    .hidden {
      display: none !important
    }

    .password-rules {
      font-size: 0.75rem;
      color: #cfe1ff;
      margin: -8px 0 12px 0;
      display: block;
    }
  </style>
</head>

<body class="bg">
  <div class="wrap">
    <header class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <div style="font-weight:700;letter-spacing:.3px">AYONET · Usuarios</div>
          <small style="color:#cfe1ff">Sesión de: <?= h($ME_NAME) ?></small>
        </div>
      </div>
      <div class="top-actions">
        <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
        <span class="pill">ADMIN</span>
      </div>
    </header>

    <section class="panel">
      <div class="grid">
        <!-- Formulario: SOLO ADMIN CON SCROLL -->
        <div class="card">
          <h3>Nuevo / Editar administrador</h3>
          <div class="form-container">
            <form id="formUser" method="post" onsubmit="return validarPwd()">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="accion" id="accion" value="crear">
              <input type="hidden" name="id_usuario" id="id_usuario" value="">
              <!-- rol fijo admin -->
              <input type="hidden" name="rol_id" id="rol_id" value="<?= $ADMIN_ROLE_ID ?>">

              <label class="lab">Nombre completo</label>
              <input class="ctrl" type="text" name="nombre_completo" id="nombre_completo" required>

              <label class="lab">Email (login)</label>
              <input class="ctrl" type="email" name="email" id="email" required>

              <label class="lab">Teléfono</label>
              <input class="ctrl" type="text" name="telefono" id="telefono" placeholder="Opcional">

              <!-- Passwords con ojito -->
              <div id="boxPwd" class="pwd-wrap">
                <label class="lab">Contraseña (crear)</label>
                <input class="ctrl" type="password" name="password" id="password"
                  placeholder="Mín. 6 letras, 2 números y 1 especial">
                <button class="eye-btn" type="button" onclick="togglePwd('password', this)"><i
                    class="fa-regular fa-eye"></i></button>
              </div>

              <div id="boxNewPwd" class="pwd-wrap hidden">
                <label class="lab">Nueva contraseña (opcional)</label>
                <input class="ctrl" type="password" name="new_password" id="new_password"
                  placeholder="Dejar vacío para no cambiar">
                <button class="eye-btn" type="button" onclick="togglePwd('new_password', this)"><i
                    class="fa-regular fa-eye"></i></button>
              </div>

              <div class="password-rules">
                Debe contener al menos <b>6 letras</b>, <b>2 números</b> y <b>1 carácter especial</b>.
              </div>

              <div class="form-actions">
                <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
                <a class="btn ghost btn-small" href="usuarios.php"><i class="fa-solid fa-times"></i> Limpiar</a>
              </div>
            </form>
          </div>
        </div>

        <!-- Listado: muestra todos -->
        <div class="card">
          <h3>Listado de Usuarios</h3>
          <div class="table-container">
            <table id="tablaUsuarios" class="display compact table-ayanet" style="width:100%">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Email</th>
                  <th>Rol</th>
                  <th>Creado</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($usuarios as $u):
                  $isAdminRow = ((int) $u['id_rol'] === $ADMIN_ROLE_ID);
                  ?>
                  <tr>
                    <td><?= h($u['nombre_completo']) ?></td>
                    <td><?= h($u['email']) ?></td>
                    <td><?= h($u['nombre_rol']) ?></td>
                    <td><?= h(substr((string) $u['fecha_creacion'], 0, 16)) ?></td>
                    <td>
                      <?php if ($u['eliminado']): ?>
                        <span class="badge badge-danger">❌ Inactivo</span>
                      <?php else: ?>
                        <span class="badge badge-success">✅ Activo</span>
                      <?php endif; ?>
                    </td>
                    <td class="actions">
                      <?php if ($isAdminRow): ?>
                        <button class="btn ghost btn-small" onclick='editar(<?= json_encode([
                          'id_usuario' => $u['id_usuario'],
                          'nombre_completo' => $u['nombre_completo'],
                          'email' => $u['email'],
                          'telefono' => $u['telefono'],
                          'id_rol' => $u['id_rol']
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                          <i class="fa-regular fa-pen-to-square"></i> Editar
                        </button>
                        <?php if (!$u['eliminado']): ?>
                          <button class="btn btn-danger btn-small" onclick="toggleUser(<?= (int) $u['id_usuario'] ?>,1)">
                            <i class="fa-regular fa-circle-xmark"></i> Desactivar
                          </button>
                        <?php else: ?>
                          <button class="btn ghost btn-small" onclick="toggleUser(<?= (int) $u['id_usuario'] ?>,0)">
                            <i class="fa-regular fa-circle-check"></i> Activar
                          </button>
                        <?php endif; ?>
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
        text: <?= json_encode($flash[2] ?? '') ?>,
        timer: 3000
      });
    </script>
  <?php endif; ?>

  <script>
    $(function () {
      $('#tablaUsuarios').DataTable({
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        searching: true,
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
        order: [[3, 'desc']],
        responsive: false,
        autoWidth: false,
        language: {
          decimal: '',
          emptyTable: 'No hay usuarios registrados',
          info: 'Mostrando _START_ a _END_ de _TOTAL_ usuarios',
          infoEmpty: 'Mostrando 0 a 0 de 0 usuarios',
          infoFiltered: '(filtrado de _MAX_ usuarios totales)',
          lengthMenu: 'Mostrar _MENU_ usuarios',
          loadingRecords: 'Cargando...',
          processing: 'Procesando...',
          search: 'Buscar:',
          zeroRecords: 'No se encontraron usuarios coincidentes',
          paginate: {
            first: 'Primero',
            last: 'Último',
            next: 'Siguiente',
            previous: 'Anterior'
          }
        }
      });
    });

    function validarPwd() {
      const mode = document.getElementById('accion').value;
      const strong = (p) => (p.match(/[A-Za-zÁÉÍÓÚÑáéíóúñ]/gu) || []).length >= 6 &&
        (p.match(/\d/g) || []).length >= 2 &&
        /[^A-Za-zÁÉÍÓÚÑáéíóúñ0-9]/u.test(p);
      if (mode === 'crear') {
        const p = document.getElementById('password').value;
        if (!strong(p)) {
          Swal.fire({ icon: 'error', title: 'Contraseña débil', text: 'Mínimo 6 letras, 2 números y 1 carácter especial.' });
          return false;
        }
      } else {
        const np = document.getElementById('new_password').value;
        if (np && !strong(np)) {
          Swal.fire({ icon: 'error', title: 'Contraseña débil', text: 'La nueva contraseña no cumple las reglas.' });
          return false;
        }
      }
      return true;
    }

    // Ojito ver/ocultar
    function togglePwd(id, btn) {
      const inp = document.getElementById(id);
      const icon = btn.querySelector('i');
      if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.remove('fa-eye'); icon.classList.add('fa-eye-slash');
      } else {
        inp.type = 'password';
        icon.classList.remove('fa-eye-slash'); icon.classList.add('fa-eye');
      }
    }

    // Editar (solo admins)
    function editar(u) {
      if (u.id_usuario === <?= (int) $ME_ID ?>) {
        Swal.fire({ icon: 'error', title: 'No permitido', text: 'No puedes editar tu propio usuario.' }); return;
      }
      $('#accion').val('actualizar');
      $('#id_usuario').val(u.id_usuario);
      $('#nombre_completo').val(u.nombre_completo);
      $('#email').val(u.email);
      $('#telefono').val(u.telefono ?? '');

      document.getElementById('boxPwd').classList.add('hidden');
      document.getElementById('boxNewPwd').classList.remove('hidden');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // Activar / Desactivar (solo admins)
    function toggleUser(id, nuevo) {
      Swal.fire({
        icon: nuevo ? 'warning' : 'question',
        title: nuevo ? '¿Desactivar administrador?' : '¿Activar administrador?',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#ff4757',
        background: '#0c1133',
        color: '#fff'
      }).then(r => {
        if (r.isConfirmed) {
          const f = document.createElement('form');
          f.method = 'post'; f.action = 'usuarios.php';
          f.innerHTML = `
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="accion" value="toggle">
            <input type="hidden" name="id_usuario" value="${id}">
            <input type="hidden" name="nuevo" value="${nuevo}">
          `;
          document.body.appendChild(f); f.submit();
        }
      });
    }
  </script>
</body>

</html>