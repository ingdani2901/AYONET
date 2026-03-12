<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
  header("Location: login.php");
  exit;
}
$ROL = $_SESSION['rol'] ?? 'cliente';
$IDU = (int) ($_SESSION['id_usuario'] ?? 0);

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_incidencias'])) {
  $_SESSION['csrf_incidencias'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_incidencias'];

/* ---------- Helpers ---------- */
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function estadoClass($e)
{
  $e = strtolower((string) $e);
  return [
    'abierta' => 'badge open',
    'asignada' => 'badge assign',
    'en_proceso' => 'badge prog',
    'resuelta' => 'badge done',
    'cerrada' => 'badge closed',
  ][$e] ?? 'badge open';
}
function getIdClientePorUsuario(PDO $pdo, int $idu): int
{
  $q = $pdo->prepare("SELECT id_cliente FROM public.clientes WHERE id_usuario=?");
  $q->execute([$idu]);
  return ($r = $q->fetch()) ? (int) $r['id_cliente'] : 0;
}
function getIdTecnicoPorUsuario(PDO $pdo, int $idu): int
{
  $q = $pdo->prepare("SELECT id_tecnico FROM public.tecnicos WHERE id_usuario=?");
  $q->execute([$idu]);
  return ($r = $q->fetch()) ? (int) $r['id_tecnico'] : 0;
}

/* ---------- POST (crear / asignar / actualizar) ---------- */
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
  try {
    if (isset($_POST['accion']) && $_POST['accion'] === 'crear' && $ROL === 'cliente') {
      $id_contrato = (int) ($_POST['id_contrato'] ?? 0);
      $prioridad = (int) ($_POST['prioridad'] ?? 2);
      $descripcion = trim($_POST['descripcion'] ?? '');

      if (!$id_contrato || $descripcion === '')
        throw new RuntimeException("Faltan datos.");

      // validar que el contrato es del cliente logueado
      $chk = $pdo->prepare("
        SELECT c.id_contrato
          FROM public.contratos c
          JOIN public.clientes cl ON cl.id_cliente = c.id_cliente
         WHERE c.id_contrato=? AND cl.id_usuario=?");
      $chk->execute([$id_contrato, $IDU]);
      if (!$chk->fetch())
        throw new RuntimeException("Contrato inválido para este usuario.");

      $ins = $pdo->prepare("
        INSERT INTO public.incidencias (id_contrato, id_tecnico, fecha_registro, descripcion, solucion, estado, prioridad)
        VALUES (?, NULL, NOW(), ?, NULL, 'abierta', ?)");
      $ins->execute([$id_contrato, $descripcion, $prioridad]);

      $flash = ['ok', 'Incidencia creada', 'Tu reporte fue enviado.'];

    } elseif (isset($_POST['accion']) && $_POST['accion'] === 'asignar' && $ROL === 'admin') {
      $id_incidencia = (int) ($_POST['id_incidencia'] ?? 0);
      $id_tecnico = (int) ($_POST['id_tecnico'] ?? 0);
      if (!$id_incidencia || !$id_tecnico)
        throw new RuntimeException("Selecciona incidencia y técnico.");

      // Actualizar incidencia
      $pdo->prepare("UPDATE public.incidencias SET id_tecnico=?, estado='asignada' WHERE id_incidencia=?")
        ->execute([$id_tecnico, $id_incidencia]);

      // 🔔 NUEVO: Notificar al técnico asignado
      require_once __DIR__ . '/functions/notificaciones.php';
      $mensajeTecnico = "📋 NUEVA INCIDENCIA ASIGNADA #$id_incidencia\nHas sido asignado a una nueva incidencia. Revisa el módulo para más detalles.";
      $notificado = notificarTecnico($pdo, $id_tecnico, $mensajeTecnico, $id_incidencia, 'alta');

      $mensajeFlash = $notificado ?
        "Incidencia asignada y técnico notificado." :
        "Incidencia asignada (error en notificación).";

      $flash = ['ok', 'Incidencia asignada', $mensajeFlash];

    } elseif (isset($_POST['accion']) && $_POST['accion'] === 'actualizar' && $ROL === 'tecnico') {
      $id_incidencia = (int) ($_POST['id_incidencia'] ?? 0);
      $estado = (string) ($_POST['estado'] ?? '');
      $solucion = trim($_POST['solucion'] ?? '');
      $valid = ['asignada', 'en_proceso', 'resuelta', 'cerrada'];
      if (!$id_incidencia || !in_array($estado, $valid, true))
        throw new RuntimeException("Datos inválidos.");

      // validar que la incidencia está asignada a este técnico
      $miTec = getIdTecnicoPorUsuario($pdo, $IDU);
      $q = $pdo->prepare("SELECT id_tecnico FROM public.incidencias WHERE id_incidencia=?");
      $q->execute([$id_incidencia]);
      $row = $q->fetch();
      if (!$row || (int) $row['id_tecnico'] !== $miTec)
        throw new RuntimeException("No tienes permiso sobre esta incidencia.");

      $pdo->prepare("UPDATE public.incidencias SET estado=?, solucion=NULLIF(?, '') WHERE id_incidencia=?")
        ->execute([$estado, $solucion, $id_incidencia]);

      $flash = ['ok', 'Incidencia actualizada', 'Se guardaron los cambios.'];
    }
  } catch (Throwable $e) {
    $flash = ['error', 'Error', $e->getMessage()];
  }
}

/* ---------- DATOS PARA FORMULARIO IZQUIERDA ---------- */
$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolUI = strtoupper($ROL);

/* Combos según rol */
$comboContratosCliente = [];
$comboTecnicos = [];
$comboMisIncidencias = [];
$comboIncidenciasSinTec = [];

if ($ROL === 'cliente') {
  $stmt = $pdo->prepare("
    SELECT c.id_contrato, s.nombre_servicio
      FROM public.contratos c
      JOIN public.clientes cl ON cl.id_cliente = c.id_cliente
      JOIN public.servicios s ON s.id_servicio = c.id_servicio
     WHERE cl.id_usuario = ?
     ORDER BY c.id_contrato DESC");
  $stmt->execute([$IDU]);
  $comboContratosCliente = $stmt->fetchAll(PDO::FETCH_ASSOC);

} elseif ($ROL === 'admin') {
  $comboTecnicos = $pdo->query("
    SELECT t.id_tecnico, t.nombre_completo
      FROM public.tecnicos t
     WHERE t.activo IS TRUE
     ORDER BY t.nombre_completo")->fetchAll(PDO::FETCH_ASSOC);

  $comboIncidenciasSinTec = $pdo->query("
    SELECT i.id_incidencia, COALESCE(u.nombre_completo,'Cliente') AS cliente,
           s.nombre_servicio, i.prioridad, i.descripcion
      FROM public.incidencias i
      JOIN public.contratos c ON c.id_contrato=i.id_contrato
      JOIN public.clientes  cl ON cl.id_cliente=c.id_cliente
      JOIN public.usuarios  u  ON u.id_usuario=cl.id_usuario
      JOIN public.servicios s  ON s.id_servicio=c.id_servicio
     WHERE i.id_tecnico IS NULL AND i.estado = 'abierta'
     ORDER BY i.prioridad DESC, i.fecha_registro DESC")->fetchAll(PDO::FETCH_ASSOC);

} elseif ($ROL === 'tecnico') {
  $idTec = getIdTecnicoPorUsuario($pdo, $IDU);
  if ($idTec) {
    $q = $pdo->prepare("
      SELECT i.id_incidencia, s.nombre_servicio, i.estado, i.prioridad, i.descripcion
        FROM public.incidencias i
        JOIN public.contratos c ON c.id_contrato=i.id_contrato
        JOIN public.servicios s ON s.id_servicio=c.id_servicio
       WHERE i.id_tecnico = ?
       ORDER BY i.prioridad DESC, i.fecha_registro DESC");
    $q->execute([$idTec]);
    $comboMisIncidencias = $q->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* ---------- LISTADO (tabla derecha) ---------- */
if ($ROL === 'cliente') {
  $list = $pdo->prepare("
    SELECT i.id_incidencia, i.estado, i.prioridad, i.fecha_registro, i.descripcion,
           c.id_contrato, s.nombre_servicio
      FROM public.incidencias i
      JOIN public.contratos c ON c.id_contrato = i.id_contrato
      JOIN public.clientes  cl ON cl.id_cliente = c.id_cliente
      JOIN public.usuarios  u  ON u.id_usuario = cl.id_usuario
      JOIN public.servicios s  ON s.id_servicio = c.id_servicio
     WHERE u.id_usuario = ?
     ORDER BY i.prioridad DESC, i.fecha_registro DESC");
  $list->execute([$IDU]);
  $rows = $list->fetchAll(PDO::FETCH_ASSOC);

} elseif ($ROL === 'admin') {
  $estado = $_GET['estado'] ?? '';
  $where = '';
  $params = [];
  if ($estado !== '') {
    $where = "WHERE i.estado = ?";
    $params[] = $estado;
  }
  $sql = "
    SELECT i.id_incidencia, i.estado, i.prioridad, i.fecha_registro, i.descripcion,
           c.id_contrato, s.nombre_servicio, u.nombre_completo AS cliente,
           t.nombre_completo as tecnico_nombre
      FROM public.incidencias i
      JOIN public.contratos c ON c.id_contrato = i.id_contrato
      JOIN public.clientes  cl ON cl.id_cliente = c.id_cliente
      JOIN public.usuarios  u  ON u.id_usuario = cl.id_usuario
      JOIN public.servicios s  ON s.id_servicio = c.id_servicio
      LEFT JOIN public.tecnicos t ON t.id_tecnico = i.id_tecnico
      $where
     ORDER BY i.prioridad DESC, i.fecha_registro DESC";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

} else { // tecnico
  $idTec = getIdTecnicoPorUsuario($pdo, $IDU);
  $st = $pdo->prepare("
    SELECT i.id_incidencia, i.estado, i.prioridad, i.fecha_registro, i.descripcion,
           c.id_contrato, s.nombre_servicio
      FROM public.incidencias i
      JOIN public.contratos c ON c.id_contrato = i.id_contrato
      JOIN public.servicios s ON s.id_servicio = c.id_servicio
     WHERE i.id_tecnico = ?
     ORDER BY i.prioridad DESC, i.fecha_registro DESC");
  $st->execute([$idTec ?: 0]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Incidencias</title>
  <link href="https://fonts.googleapis/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
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
      overflow: hidden;
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
      height: 100vh;
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
      padding: 16px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
    }

    .hdr {
      display: flex;
      align-items: end;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 16px;
    }

    .grid {
      height: calc(100% - 80px);
      display: grid;
      grid-template-columns: 380px 1fr;
      gap: 16px
    }

    @media (max-width:1180px) {
      .grid {
        grid-template-columns: 1fr
      }
    }

    .card {
      background: var(--glass);
      border: 1px solid rgba(255, 255, 255, .12);
      border-radius: 14px;
      padding: 16px;
      height: 100%;
      overflow: auto;
    }

    .card h3 {
      margin: 0 0 12px;
      font-size: 1.1rem;
      color: var(--neon1);
    }

    .form-grid {
      display: grid;
      grid-template-columns: 1fr;
      gap: 12px;
    }

    .form-grid .full {
      grid-column: 1 / -1;
    }

    .lab {
      font-size: .8rem;
      color: #d8e4ff;
      margin-bottom: 4px;
      display: block;
      font-weight: 500;
    }

    .ctrl {
      width: 100%;
      padding: 10px 12px;
      border-radius: 10px;
      background: rgba(255, 255, 255, .08);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, .16);
      outline: none;
      font-family: "Poppins", sans-serif;
      font-size: 0.9rem;
    }

    .ctrl:focus {
      border-color: var(--neon1);
      box-shadow: 0 0 0 2px rgba(0, 212, 255, 0.1);
    }

    .ctrl::placeholder {
      color: #d8e4ff97
    }

    textarea.ctrl {
      min-height: 80px;
      resize: vertical;
    }

    .table-wrap {
      height: 100%;
      overflow: auto;
    }

    .dataTables_wrapper {
      font-size: 0.85rem;
    }

    .dataTables_wrapper .dataTables_length select {
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .16);
      color: #fff;
      border-radius: 8px;
      padding: 4px
    }

    .dataTables_wrapper .dataTables_filter input {
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .16);
      color: #fff;
      border-radius: 8px;
      padding: 6px;
      font-size: 0.85rem;
    }

    .dataTables_wrapper .dataTables_info {
      color: #cfe1ff;
      font-size: 0.8rem;
    }

    .dataTables_wrapper .dataTables_paginate .paginate_button {
      color: #fff !important;
      border: 1px solid rgba(255, 255, 255, .16);
      background: rgba(255, 255, 255, .06);
      border-radius: 8px;
      margin: 0 2px;
      padding: 4px 8px;
      font-size: 0.8rem;
    }

    .table-ayanet {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.85rem;
    }

    th,
    td {
      padding: 8px 6px;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    th {
      color: #cfe1ff;
      font-weight: 600;
      font-size: 0.8rem;
      white-space: nowrap;
    }

    td {
      vertical-align: top;
    }

    tr:hover {
      background: rgba(255, 255, 255, .03);
    }

    .actions {
      white-space: nowrap;
    }

    .actions .btn {
      margin-right: 4px;
      padding: 6px 8px;
      font-size: 0.75rem;
    }

    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: .7rem;
      font-weight: 600;
      background: rgba(111, 0, 255, .22);
      border: 1px solid rgba(111, 0, 255, .35);
      color: #dcd6ff;
    }

    .badge.open {
      background: rgba(0, 212, 255, .18);
      border-color: rgba(0, 212, 255, .35)
    }

    .badge.assign {
      background: rgba(255, 255, 255, .12)
    }

    .badge.prog {
      background: rgba(255, 196, 0, .22);
      border-color: rgba(255, 196, 0, .45)
    }

    .badge.done {
      background: rgba(0, 200, 140, .20);
      border-color: rgba(0, 200, 140, .45)
    }

    .badge.closed {
      background: rgba(160, 160, 160, .20);
      border-color: rgba(160, 160, 160, .45)
    }

    .badge.prioridad-alta {
      background: rgba(255, 71, 87, 0.25);
      border-color: rgba(255, 71, 87, 0.4);
      color: #ffb8c2;
    }

    .badge.prioridad-media {
      background: rgba(255, 196, 0, 0.25);
      border-color: rgba(255, 196, 0, 0.4);
      color: #ffeeba;
    }

    .badge.prioridad-baja {
      background: rgba(0, 212, 255, 0.25);
      border-color: rgba(0, 212, 255, 0.4);
      color: #b8f0ff;
    }

    .info-box {
      background: rgba(59, 130, 246, 0.1);
      border: 1px solid rgba(59, 130, 246, 0.3);
      border-radius: 8px;
      padding: 10px;
      margin-bottom: 12px;
      font-size: 0.8rem;
    }

    .info-box i {
      color: #3b82f6;
      margin-right: 5px;
    }

    /* BUSCADOR PARA SELECT DE TÉCNICOS */
    .tech-search-container {
      position: relative;
      margin-bottom: 8px;
    }

    .tech-search-input {
      width: 100%;
      padding: 8px 10px;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #fff;
      font-size: 0.85rem;
    }

    .tech-search-input::placeholder {
      color: #cfe1ff97;
    }

    .tech-count {
      font-size: 0.75rem;
      color: #cfe1ff;
      margin-top: 4px;
      text-align: right;
    }

    /* Select más compacto */
    .tech-select {
      height: auto !important;
      max-height: 150px;
      font-size: 0.85rem;
    }

    /* Mejoras para descripción en tabla */
    .descripcion-cell {
      max-width: 200px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .descripcion-cell:hover {
      white-space: normal;
      overflow: visible;
      background: rgba(0, 0, 0, 0.3);
      position: relative;
      z-index: 10;
    }

    /* Botones más compactos */
    .btn-compact {
      padding: 6px 8px !important;
      font-size: 0.75rem !important;
      margin-right: 4px !important;
    }

    /* Formulario más compacto */
    .compact-form {
      max-height: 100%;
      overflow-y: auto;
    }

    .compact-form .form-grid {
      gap: 10px;
    }

    .compact-form textarea.ctrl {
      min-height: 60px;
    }
  </style>
</head>

<body class="bg">

  <div class="wrap">
    <!-- TOP -->
    <header class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <div style="font-weight:700;letter-spacing:.3px; font-size:0.95rem;">AYONET · Incidencias</div>
          <small style="color:#cfe1ff; font-size:0.75rem;">Sesión de: <?= h($nombreUser) ?></small>
        </div>
      </div>
      <div class="top-actions">
        <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
        <span class="pill"><?= h($rolUI) ?></span>
      </div>
    </header>

    <!-- MAIN -->
    <section class="panel">
      <div class="hdr">
        <div>
          <h2 style="margin:0; font-size:1.4rem;">Incidencias</h2>
        </div>
        <?php if ($ROL === 'admin'): ?>
          <form method="get" style="display:flex;gap:8px;align-items:center">
            <label style="font-size:0.85rem;">Estado</label>
            <select class="ctrl" name="estado" onchange="this.form.submit()" style="font-size:0.85rem; padding:6px 8px;">
              <option value="">Todas</option>
              <?php foreach (['abierta', 'asignada', 'en_proceso', 'resuelta', 'cerrada'] as $e): ?>
                <option value="<?= $e ?>" <?= (!empty($_GET['estado']) && $_GET['estado'] === $e) ? 'selected' : '' ?>>
                  <?= $e ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php else: ?>
          <div></div><?php endif; ?>
      </div>

      <div class="grid">
        <!-- Formulario lateral -->
        <div class="card compact-form">
          <?php if ($ROL === 'cliente'): ?>
            <h3>Nueva incidencia</h3>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="accion" value="crear">
              <div class="form-grid">
                <div class="full">
                  <label class="lab">Contrato</label>
                  <select class="ctrl" name="id_contrato" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($comboContratosCliente as $c): ?>
                      <option value="<?= (int) $c['id_contrato'] ?>">#<?= (int) $c['id_contrato'] ?> —
                        <?= h($c['nombre_servicio']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="lab">Prioridad</label>
                  <select class="ctrl" name="prioridad" required>
                    <option value="1">Baja</option>
                    <option value="2" selected>Media</option>
                    <option value="3">Alta</option>
                  </select>
                </div>
                <div class="full">
                  <label class="lab">Descripción</label>
                  <textarea class="ctrl" name="descripcion" rows="4" placeholder="Describe tu problema"
                    required></textarea>
                </div>
              </div>
              <div style="margin-top:12px">
                <button class="btn primary" type="submit"><i class="fa-solid fa-paper-plane"></i> Enviar</button>
              </div>
            </form>

          <?php elseif ($ROL === 'admin'): ?>
            <h3>Asignación rápida</h3>

            <?php if (count($comboIncidenciasSinTec) > 0): ?>
              <div class="info-box">
                <i class="fa-solid fa-circle-info"></i>
                Tienes <strong><?= count($comboIncidenciasSinTec) ?></strong> incidencias pendientes de asignación
              </div>
            <?php endif; ?>

            <form method="post">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="accion" value="asignar">
              <div class="form-grid">
                <div class="full">
                  <label class="lab">Incidencia</label>
                  <select class="ctrl" name="id_incidencia" required>
                    <option value="">Sin asignar / abiertas…</option>
                    <?php foreach ($comboIncidenciasSinTec as $i): ?>
                      <option value="<?= (int) $i['id_incidencia'] ?>">#<?= (int) $i['id_incidencia'] ?> —
                        <?= h($i['cliente']) ?> / <?= h($i['nombre_servicio']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="full">
                  <label class="lab">Técnico</label>

                  <!-- BUSCADOR DE TÉCNICOS -->
                  <div class="tech-search-container">
                    <input type="text" id="techSearch" class="tech-search-input" placeholder="🔍 Buscar técnico..."
                      autocomplete="off">
                    <div id="techCount" class="tech-count"></div>
                  </div>

                  <select class="ctrl tech-select" name="id_tecnico" id="selectTecnico" required size="6">
                    <option value="">Selecciona técnico…</option>
                    <?php foreach ($comboTecnicos as $t): ?>
                      <option value="<?= (int) $t['id_tecnico'] ?>"><?= h($t['nombre_completo']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>
              <div style="margin-top:12px">
                <button class="btn primary" type="submit">
                  <i class="fa-solid fa-user-check"></i> Asignar
                </button>
              </div>
            </form>

          <?php else: /* tecnico */ ?>
            <h3>Actualizar incidencia</h3>

            <?php if (count($comboMisIncidencias) > 0): ?>
              <div class="info-box">
                <i class="fa-solid fa-circle-info"></i>
                Tienes <strong><?= count($comboMisIncidencias) ?></strong> incidencias asignadas
              </div>
            <?php endif; ?>

            <form method="post" id="formTec">
              <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
              <input type="hidden" name="accion" value="actualizar">
              <div class="form-grid">
                <div class="full">
                  <label class="lab">Incidencia asignada</label>
                  <select class="ctrl" name="id_incidencia" id="selInc" required>
                    <option value="">Selecciona…</option>
                    <?php foreach ($comboMisIncidencias as $i): ?>
                      <option value="<?= (int) $i['id_incidencia'] ?>">#<?= (int) $i['id_incidencia'] ?> —
                        <?= h($i['nombre_servicio']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <label class="lab">Estado</label>
                  <select class="ctrl" name="estado" required>
                    <?php foreach (['asignada', 'en_proceso', 'resuelta', 'cerrada'] as $e): ?>
                      <option value="<?= $e ?>"><?= $e ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="full">
                  <label class="lab">Solución</label>
                  <textarea class="ctrl" name="solucion" rows="3"
                    placeholder="Describe el trabajo realizado..."></textarea>
                </div>
              </div>
              <div style="margin-top:12px">
                <button class="btn primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <!-- Tabla -->
        <div class="card">
          <h3>Listado de Incidencias</h3>
          <div class="table-wrap">
            <table id="tablaInc" class="display compact table-ayanet" style="width:100%">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Contrato</th>
                  <th>Servicio</th>
                  <?php if ($ROL === 'admin'): ?>
                    <th>Cliente</th>
                    <th>Técnico</th>
                  <?php endif; ?>
                  <th>Prioridad</th>
                  <th>Estado</th>
                  <th>Fecha</th>
                  <th>Descripción</th>
                  <?php if ($ROL !== 'cliente'): ?>
                    <th>Acciones</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <tr>
                    <td><strong>#<?= (int) $r['id_incidencia'] ?></strong></td>
                    <td>#<?= (int) $r['id_contrato'] ?></td>
                    <td><?= h($r['nombre_servicio']) ?></td>
                    <?php if ($ROL === 'admin'): ?>
                      <td><?= h($r['cliente']) ?></td>
                      <td>
                        <?php if (!empty($r['tecnico_nombre'])): ?>
                          <?= h($r['tecnico_nombre']) ?>
                        <?php else: ?>
                          <span style="color:#888; font-style:italic; font-size:0.8rem;">Sin asignar</span>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
                    <td>
                      <?php
                      $prioridadClass = 'badge';
                      if ($r['prioridad'] == 3)
                        $prioridadClass .= ' prioridad-alta';
                      elseif ($r['prioridad'] == 2)
                        $prioridadClass .= ' prioridad-media';
                      else
                        $prioridadClass .= ' prioridad-baja';
                      ?>
                      <span class="<?= $prioridadClass ?>">
                        <?= (int) $r['prioridad'] == 3 ? 'Alta' : ((int) $r['prioridad'] == 2 ? 'Media' : 'Baja') ?>
                      </span>
                    </td>
                    <td><span class="<?= estadoClass($r['estado']) ?>"><?= h($r['estado']) ?></span></td>
                    <td style="white-space: nowrap; font-size:0.8rem;">
                      <?= date('d/m/Y H:i', strtotime($r['fecha_registro'])) ?>
                    </td>
                    <td class="descripcion-cell" title="<?= h($r['descripcion']) ?>">
                      <?= h(substr($r['descripcion'], 0, 50)) . (strlen($r['descripcion']) > 50 ? '...' : '') ?>
                    </td>
                    <?php if ($ROL !== 'cliente'): ?>
                      <td class="actions">
                        <?php if ($ROL === 'admin'): ?>
                          <button class="btn ghost btn-compact" onclick="asignarRapida(<?= (int) $r['id_incidencia'] ?>)"
                            title="Asignar">
                            <i class="fa-solid fa-user-check"></i>
                          </button>
                        <?php else: ?>
                          <button class="btn ghost btn-compact" onclick="prefillTec(<?= (int) $r['id_incidencia'] ?>)"
                            title="Actualizar">
                            <i class="fa-regular fa-pen-to-square"></i>
                          </button>
                        <?php endif; ?>
                      </td>
                    <?php endif; ?>
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
        timer: 3000,
        background: '#0c1133',
        color: '#fff'
      });
    </script>
  <?php endif; ?>

  <script>
    $(function () {
      $('#tablaInc').DataTable({
        dom: 'lfrtip',
        searching: true,
        pageLength: 10,
        lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
        order: [[0, 'desc']],
        responsive: true,
        language: {
          emptyTable: 'No hay datos disponibles',
          info: 'Mostrando _START_ a _END_ de _TOTAL_',
          infoEmpty: 'Mostrando 0 a 0 de 0',
          infoFiltered: '(filtrado de _MAX_ en total)',
          lengthMenu: 'Mostrar _MENU_',
          loadingRecords: 'Cargando...',
          processing: 'Procesando...',
          search: 'Buscar:',
          zeroRecords: 'Sin coincidencias',
          paginate: { first: 'Primero', last: 'Último', next: 'Siguiente', previous: 'Anterior' }
        },
        columnDefs: [
          { responsivePriority: 1, targets: 0 },
          { responsivePriority: 2, targets: -1 }
        ]
      });

      // BUSCADOR DE TÉCNICOS EN TIEMPO REAL
      function initTechSearch() {
        const searchInput = document.getElementById('techSearch');
        const selectTecnico = document.getElementById('selectTecnico');
        const techCount = document.getElementById('techCount');

        if (!searchInput || !selectTecnico) return;

        // Contar técnicos totales
        const totalTechs = selectTecnico.options.length - 1; // -1 por la opción vacía
        techCount.textContent = `${totalTechs} técnicos disponibles`;

        searchInput.addEventListener('input', function () {
          const searchTerm = this.value.toLowerCase().trim();
          let visibleCount = 0;

          // Recorrer todas las opciones (empezando desde 1 para saltar la opción vacía)
          for (let i = 1; i < selectTecnico.options.length; i++) {
            const option = selectTecnico.options[i];
            const techName = option.text.toLowerCase();

            if (searchTerm === '' || techName.includes(searchTerm)) {
              option.style.display = '';
              visibleCount++;
            } else {
              option.style.display = 'none';
            }
          }

          // Actualizar contador
          techCount.textContent = `${visibleCount} de ${totalTechs} técnicos`;

          // Si hay solo un resultado después de buscar, seleccionarlo automáticamente
          if (visibleCount === 1 && searchTerm !== '') {
            for (let i = 1; i < selectTecnico.options.length; i++) {
              const option = selectTecnico.options[i];
              if (option.style.display !== 'none') {
                selectTecnico.value = option.value;
                break;
              }
            }
          }
        });

        // Limpiar búsqueda cuando se cambia la selección manualmente
        selectTecnico.addEventListener('change', function () {
          if (this.value !== '') {
            searchInput.value = '';
            // Mostrar todos los técnicos nuevamente
            for (let i = 1; i < this.options.length; i++) {
              this.options[i].style.display = '';
            }
            techCount.textContent = `${totalTechs} técnicos disponibles`;
          }
        });
      }

      // Inicializar buscador de técnicos
      initTechSearch();
    });

    // Admin: botón de tabla que selecciona la incidencia en el formulario de asignación
    function asignarRapida(id) {
      const sel = document.querySelector('select[name="id_incidencia"]');
      if (!sel) return;
      for (const o of sel.options) { if (o.value == id) { sel.value = id; break; } }
      sel.focus();

      // Scroll suave al formulario
      document.querySelector('.card').scrollIntoView({ behavior: 'smooth' });
    }

    // Técnico: botón de tabla que pre-llena el form con la incidencia de esa fila
    function prefillTec(id) {
      const sel = document.getElementById('selInc');
      if (!sel) return;
      for (const o of sel.options) { if (o.value == id) { sel.value = id; break; } }
      sel.focus();

      // Scroll suave al formulario
      document.querySelector('.card').scrollIntoView({ behavior: 'smooth' });
    }
  </script>
</body>

</html>