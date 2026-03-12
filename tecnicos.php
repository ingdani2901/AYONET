<?php
/* AYONET · Técnicos (CRUD según tu tabla actual)
    Tabla: public.tecnicos
    - id_tecnico        serial PK
    - especialidad      character varying(100)
    - disponibilidad    text
    - activo            boolean
    - nombre_completo   character varying(200)
    - id_usuario        integer
    - email             character varying(255)
    - password_hash     character varying(255)
    - fecha_registro    timestamp without time zone
    - eliminado         boolean                  <-- USADO PARA SOFT DELETE
    - eliminado_en      timestamp with time zone <-- USADO PARA SOFT DELETE
*/

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
  header('Location: login.php');
  exit;
}

date_default_timezone_set('America/Mexico_City');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_tecnicos'])) {
  $_SESSION['csrf_tecnicos'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_tecnicos'];

/* ---------- Helpers ---------- */
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function soloLetras($s)
{
  return (bool) preg_match('/^[\p{L}\s.\'-]+$/u', $s);
}
function esEmail($s)
{
  return filter_var($s, FILTER_VALIDATE_EMAIL) !== false;
}
function pass_ok($p)
{
  $p = (string) $p;
  $letters = @preg_match_all('/\p{L}/u', $p);
  $letters = $letters === false ? 0 : (int) $letters;
  $digits = @preg_match_all('/\d/', $p);
  $digits = $digits === false ? 0 : (int) $digits;
  $special = @preg_match_all('/[^\p{L}\d]/u', $p);
  $special = $special === false ? 0 : (int) $special;
  return ($letters >= 6 && $digits >= 2 && $special >= 1);
}

$flash = null;
$edit = null;
$isEdit = false;

/* ---------- POST: crear / actualizar / toggle_activo / eliminar ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
  $accion = $_POST['accion'] ?? '';
  try {
    if ($accion === 'crear' || $accion === 'actualizar') {
      $idTec = isset($_POST['id_tecnico']) ? (int) $_POST['id_tecnico'] : 0;
      $nombre = trim($_POST['nombre_completo'] ?? '');
      $email = trim($_POST['email'] ?? '');
      $especialidad = trim($_POST['especialidad'] ?? '');
      $disponibilidad = trim($_POST['disponibilidad'] ?? '');
      $activo = isset($_POST['activo']) ? 1 : 0;
      $password = trim($_POST['password'] ?? '');
      $new_password = trim($_POST['new_password'] ?? '');

      $err = [];
      if ($nombre === '' || !soloLetras($nombre))
        $err[] = 'Nombre inválido';
      if ($email === '' || !esEmail($email))
        $err[] = 'Email inválido';
      if ($accion === 'crear' && $password !== '' && !pass_ok($password))
        $err[] = 'Contraseña débil (mín. 6 letras, 2 números y 1 especial)';
      if ($accion === 'actualizar' && $new_password !== '' && !pass_ok($new_password))
        $err[] = 'La nueva contraseña es débil (mín. 6 letras, 2 números y 1 especial)';

      if ($err) {
        // PRESERVAR DATOS DEL FORMULARIO EN CASO DE ERROR
        $edit = [
          'id_tecnico' => $idTec,
          'nombre_completo' => $nombre,
          'email' => $email,
          'especialidad' => $especialidad,
          'disponibilidad' => $disponibilidad,
          'activo' => $activo
        ];
        $isEdit = ($accion === 'actualizar');
        throw new RuntimeException(implode("\n", $err));
      }

      if ($accion === 'crear') {
        // Generar password si no se proporcionó
        if ($password === '')
          $password = bin2hex(random_bytes(4)) . '!A1';

        // Verificar duplicidad de email
        $qdu = $pdo->prepare("SELECT COUNT(*) FROM public.tecnicos WHERE LOWER(email)=LOWER(:e) AND eliminado=FALSE");
        $qdu->execute([':e' => $email]);
        if ((int) $qdu->fetchColumn() > 0)
          throw new RuntimeException("Ya existe un técnico con ese email.");

        // Crear técnico
        $q = $pdo->prepare("
                        INSERT INTO public.tecnicos 
                        (nombre_completo, email, password_hash, especialidad, disponibilidad, activo, id_usuario, fecha_registro) 
                        VALUES (:n, :mail, :ph, :esp, :disp, :a, :uid, NOW())
                    ");
        $q->execute([
          ':n' => $nombre,
          ':mail' => $email,
          ':ph' => password_hash($password, PASSWORD_DEFAULT),
          ':esp' => ($especialidad ?: null),
          ':disp' => ($disponibilidad ?: null),
          ':a' => $activo ? 1 : 0,
          ':uid' => (int) $_SESSION['id_usuario']
        ]);
        $txtPass = ($password ? " · Contraseña: $password" : "");
        $flash = ['ok', 'Técnico creado', "Email: $email$txtPass"];
      } else {
        if ($idTec <= 0)
          throw new RuntimeException('ID técnico inválido');

        // Verificar duplicidad de email (excluyendo el actual)
        $qdu = $pdo->prepare("SELECT COUNT(*) FROM public.tecnicos WHERE LOWER(email)=LOWER(:e) AND id_tecnico<>:id AND eliminado=FALSE");
        $qdu->execute([':e' => $email, ':id' => $idTec]);
        if ((int) $qdu->fetchColumn() > 0)
          throw new RuntimeException("Ya existe otro técnico con ese email.");

        // Actualizar técnico
        $sql = "UPDATE public.tecnicos SET nombre_completo=:n, email=:mail, especialidad=:esp, disponibilidad=:disp, activo=:a";
        if ($new_password !== '')
          $sql .= ", password_hash=:ph";
        $sql .= " WHERE id_tecnico=:id AND eliminado=FALSE";

        $params = [
          ':n' => $nombre,
          ':mail' => $email,
          ':esp' => ($especialidad ?: null),
          ':disp' => ($disponibilidad ?: null),
          ':a' => $activo ? 1 : 0,
          ':id' => $idTec
        ];
        if ($new_password !== '')
          $params[':ph'] = password_hash($new_password, PASSWORD_DEFAULT);

        $q = $pdo->prepare($sql);
        $q->execute($params);
        $flash = ['ok', 'Técnico actualizado', ($new_password !== '' ? 'Contraseña actualizada.' : 'Datos guardados.')];
      }
    }

    if ($accion === 'toggle_activo') {
      $idTec = (int) ($_POST['id_tecnico'] ?? 0);
      $nuevo = (int) ($_POST['nuevo'] ?? 0);
      if ($idTec <= 0)
        throw new RuntimeException('ID técnico inválido');
      $pdo->prepare("UPDATE public.tecnicos SET activo=:a WHERE id_tecnico=:id AND eliminado=FALSE")
        ->execute([':a' => $nuevo ? 1 : 0, ':id' => $idTec]);
      $flash = ['ok', 'Disponibilidad actualizada', null];
    }

    if ($accion === 'eliminar') {
      $idTec = (int) ($_POST['id_tecnico'] ?? 0);
      if ($idTec <= 0)
        throw new RuntimeException('ID técnico inválido');

      // --- LÓGICA DE SOFT DELETE ---
      $pdo->prepare("UPDATE public.tecnicos SET eliminado=TRUE, eliminado_en=NOW(), activo=FALSE WHERE id_tecnico=:id")
        ->execute([':id' => $idTec]);
      $flash = ['ok', 'Técnico Eliminado', '(El técnico ha sido enviado a la papelera)'];
      // --- FIN DE MODIFICACIÓN ---
    }

  } catch (Throwable $e) {
    $flash = ['error', 'Error', $e->getMessage() ?: 'No se pudo guardar.'];
  }
}

/* ---------- GET: cargar para edición ---------- */
if (isset($_GET['editar'])) {
  $idTec = (int) $_GET['editar'];
  $q = $pdo->prepare("
        SELECT id_tecnico, nombre_completo, email, especialidad, disponibilidad, activo, id_usuario 
        FROM public.tecnicos 
        WHERE id_tecnico=:id AND eliminado=FALSE LIMIT 1
    ");
  $q->execute([':id' => $idTec]);
  $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
  $isEdit = is_array($edit);
}

/* ---------- LISTADO ---------- */
$tecnicos = $pdo->query("
    SELECT id_tecnico, nombre_completo, email, especialidad, disponibilidad, activo, id_usuario, fecha_registro 
    FROM public.tecnicos 
    WHERE eliminado=FALSE
    ORDER BY id_tecnico DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Datos UI ---------- */
$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolPill = strtoupper($_SESSION['rol'] ?? 'USUARIO');
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Técnicos</title>

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

    /* --- Estilos para el campo de contraseña con ojito --- */
    .password-wrapper {
      position: relative;
      display: flex;
      align-items: center;
    }

    .password-wrapper .ctrl {
      width: 100%;
      padding-right: 40px;
      /* Espacio para el ojito */
      margin-bottom: 12px;
    }

    .toggle-password {
      position: absolute;
      right: 10px;
      background: none;
      border: none;
      color: #cfe1ff;
      cursor: pointer;
      font-size: 1rem;
      padding: 5px;
      z-index: 2;
    }

    .toggle-password:hover {
      color: var(--neon1);
    }

    .table-wrap {
      overflow: auto;
    }

    .table-ayanet {
      width: 100%;
      min-width: 800px;
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

    tr.row {
      background: rgba(255, 255, 255, .06);
      border: 1px solid rgba(255, 255, 255, .12)
    }

    tr.row:hover {
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

    /* --- Estilos de DataTables --- */
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

    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
      background: rgba(255, 255, 255, .12) !important;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
      background: rgba(255, 255, 255, .15) !important;
    }

    /* --- Fin Estilos de DataTables --- */

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
          <div style="font-weight:700;letter-spacing:.3px">AYONET · Técnicos</div>
          <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
        </div>
      </div>
      <div class="top-actions">
        <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
        <span class="pill"><?= h($rolPill) ?></span>
      </div>
    </header>

    <section class="panel">
      <div class="grid">
        <div class="card">
          <h3><?= $isEdit ? 'Editar técnico' : 'Nuevo técnico' ?></h3>
          <form method="post" onsubmit="return validar()">
            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
            <input type="hidden" name="accion" value="<?= $isEdit ? 'actualizar' : 'crear' ?>">
            <?php if ($isEdit): ?><input type="hidden" name="id_tecnico"
                value="<?= (int) $edit['id_tecnico'] ?>"><?php endif; ?>

            <label class="lab">Nombre completo</label>
            <input class="ctrl" type="text" name="nombre_completo" id="nombre"
              value="<?= h($edit['nombre_completo'] ?? '') ?>" required>

            <label class="lab">Email</label>
            <input class="ctrl" type="email" name="email" id="email" value="<?= h($edit['email'] ?? '') ?>" required>

            <?php if (!$isEdit): ?>
              <label class="lab">Contraseña</label>
              <div class="password-wrapper">
                <input class="ctrl" type="password" name="password" id="password" placeholder="Ej. Tecnico2025!">
                <button type="button" class="toggle-password" onclick="togglePassword('password', this)"
                  title="Mostrar contraseña">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              <span class="password-rules">Mín. 6 letras, 2 números y 1 carácter especial</span>
            <?php else: ?>
              <label class="lab">Nueva contraseña (opcional)</label>
              <div class="password-wrapper">
                <input class="ctrl" type="password" name="new_password" id="new_password"
                  placeholder="Dejar vacío para no cambiar">
                <button type="button" class="toggle-password" onclick="togglePassword('new_password', this)"
                  title="Mostrar contraseña">
                  <i class="fa-solid fa-eye"></i>
                </button>
              </div>
              <span class="password-rules">Mín. 6 letras, 2 números y 1 carácter especial</span>
            <?php endif; ?>

            <label class="lab">Especialidad (opcional)</label>
            <input class="ctrl" type="text" name="especialidad" id="especialidad" maxlength="100"
              value="<?= h($edit['especialidad'] ?? '') ?>" placeholder="Fibra, Radioenlace, etc.">

            <label class="lab">Disponibilidad (opcional)</label>
            <textarea class="ctrl" name="disponibilidad" id="disponibilidad" rows="3"
              placeholder="Horario, zonas, etc."><?= h($edit['disponibilidad'] ?? '') ?></textarea>

            <label class="lab" style="display:flex;align-items:center;gap:8px">
              <input type="checkbox" class="toggle" name="activo" <?= isset($edit) ? (($edit['activo'] ?? true) ? 'checked' : '') : 'checked' ?>>
              Activo para asignaciones
            </label>

            <div class="form-actions">
              <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i>
                <?= $isEdit ? 'Actualizar' : 'Guardar' ?></button>
              <?php if ($isEdit): ?>
                <a class="btn ghost btn-small" href="tecnicos.php"><i class="fa-solid fa-times"></i> Cancelar</a>
              <?php endif; ?>
            </div>

            <?php if ($isEdit): ?>
              <div style="margin-top:12px; color:#cfe1ff; font-size:0.8rem;">
                Creado por usuario ID: <span class="pill"
                  style="padding:2px 8px; font-size:.7rem;"><?= (int) $edit['id_usuario'] ?></span>
              </div>
            <?php endif; ?>
          </form>
        </div>

        <div class="card">
          <h3>Listado de Técnicos</h3>
          <div class="table-wrap">
            <table id="tablaTecnicos" class="display compact table-ayanet" style="width:100%">
              <thead>
                <tr>
                  <th>Nombre</th>
                  <th>Email</th>
                  <th>Especialidad</th>
                  <th>Disponibilidad</th>
                  <th>Activo</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($tecnicos as $t): ?>
                  <tr class="row">
                    <td><?= h($t['nombre_completo']) ?></td>
                    <td><?= h($t['email']) ?></td>
                    <td><?= h($t['especialidad']) ?></td>
                    <td><?= h($t['disponibilidad']) ?></td>
                    <td>
                      <form method="post" style="display:inline" onsubmit="return toggleActivo(event)">
                        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                        <input type="hidden" name="accion" value="toggle_activo">
                        <input type="hidden" name="id_tecnico" value="<?= (int) $t['id_tecnico'] ?>">
                        <input type="hidden" name="nuevo" value="<?= $t['activo'] ? 0 : 1 ?>">
                        <input type="checkbox" class="toggle" <?= $t['activo'] ? 'checked' : '' ?>
                          onchange="this.form.submit()" title="Cambiar estado activo">
                      </form>
                    </td>
                    <td class="actions">
                      <button class="btn ghost btn-small" onclick="confirmarEditar(<?= (int) $t['id_tecnico'] ?>)">
                        <i class="fa-regular fa-pen-to-square"></i> Editar
                      </button>

                      <button class="btn ghost btn-small" onclick="confirmarEliminar(<?= (int) $t['id_tecnico'] ?>)">
                        <i class="fa-regular fa-trash-can"></i> Eliminar
                      </button>
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
    // --- Función para mostrar/ocultar contraseña ---
    function togglePassword(fieldId, button) {
      const input = document.getElementById(fieldId);
      const icon = button.querySelector('i');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
        button.setAttribute('title', 'Ocultar contraseña');
      } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
        button.setAttribute('title', 'Mostrar contraseña');
      }
    }

    function validar() {
      const nombre = document.getElementById('nombre').value.trim();
      const email = document.getElementById('email').value.trim();

      // Regex corregida
      const rxNom = /^[\p{L}\s.'-]+$/u;

      if (!rxNom.test(nombre)) {
        Swal.fire({ icon: 'error', title: 'Nombre inválido', text: 'Usa solo letras y espacios.' });
        return false;
      }
      if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
        Swal.fire({ icon: 'error', title: 'Email inválido', text: 'Ingresa un correo válido.' });
        return false;
      }

      // Validación de contraseña
      const pwField = document.querySelector('input[name="password"]');
      const newPwField = document.querySelector('input[name="new_password"]');
      const pw = pwField ? pwField.value.trim() : '';
      const npw = newPwField ? newPwField.value.trim() : '';

      const strong = (p) => {
        if (!p) return true;
        const letters = (p.match(/\p{L}/gu) || []).length;
        const digits = (p.match(/\d/g) || []).length;
        const special = (p.match(/[^\p{L}\d]/u) || []).length;
        return letters >= 6 && digits >= 2 && special >= 1;
      };

      if (pwField && pw !== '' && !strong(pw)) {
        Swal.fire({ icon: 'error', title: 'Contraseña débil', text: 'Mínimo 6 letras, 2 números y 1 carácter especial.' });
        return false;
      }
      if (newPwField && npw !== '' && !strong(npw)) {
        Swal.fire({ icon: 'error', title: 'Contraseña débil', text: 'La nueva contraseña no cumple las reglas.' });
        return false;
      }

      return true;
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

    // --- Alerta para Editar ---
    function confirmarEditar(id) {
      Swal.fire({
        title: '¿Editar técnico?',
        text: 'Se cargarán los datos del técnico en el formulario para su edición.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Sí, editar',
        cancelButtonText: 'Cancelar'
      }).then((result) => {
        if (result.isConfirmed) {
          window.location.href = 'tecnicos.php?editar=' + id;
        }
      });
    }

    // --- Alerta para Eliminar (Soft Delete) ---
    function confirmarEliminar(id) {
      Swal.fire({
        icon: 'warning',
        title: '¿Eliminar técnico?',
        html: `¿Estás seguro de que quieres eliminar a este técnico?<br><small>Se marcará como eliminado (soft delete) y no aparecerá en la lista.</small>`,
        showCancelButton: true,
        confirmButtonText: 'Sí, eliminar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#d33'
      }).then((result) => {
        if (result.isConfirmed) {
          // Crear formulario temporal para eliminar
          const form = document.createElement('form');
          form.method = 'post';
          form.action = 'tecnicos.php';

          const csrf = document.createElement('input');
          csrf.name = 'csrf';
          csrf.value = '<?= h($CSRF) ?>';
          form.appendChild(csrf);

          const accion = document.createElement('input');
          accion.name = 'accion';
          accion.value = 'eliminar';
          form.appendChild(accion);

          const idInput = document.createElement('input');
          idInput.name = 'id_tecnico';
          idInput.value = id;
          form.appendChild(idInput);

          document.body.appendChild(form);
          form.submit();
        }
      });
    }

    // --- Inicialización de DataTables ---
    $(function () {
      $('#tablaTecnicos').DataTable({
        dom: '<"top"lf>rt<"bottom"ip><"clear">',
        searching: true,
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
        order: [[0, 'asc']],
        responsive: false,
        autoWidth: false,
        language: {
          decimal: '',
          emptyTable: 'No hay técnicos registrados',
          info: 'Mostrando _START_ a _END_ de _TOTAL_ técnicos',
          infoEmpty: 'Mostrando 0 a 0 de 0 técnicos',
          infoFiltered: '(filtrado de _MAX_ técnicos totales)',
          lengthMenu: 'Mostrar _MENU_ técnicos',
          loadingRecords: 'Cargando...',
          processing: 'Procesando...',
          search: 'Buscar:',
          zeroRecords: 'No se encontraron técnicos coincidentes',
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