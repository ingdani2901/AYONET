<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['id_usuario'])) {
  header("Location: login.php");
  exit;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_contratos'])) {
  $_SESSION['csrf_contratos'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_contratos'];

/* ---------- Helpers ---------- */
function h($s)
{
  return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- FLASH MESSAGES ---------- */
$flash = null;

/* ---------- CAMBIAR ESTADO DE CONTRATO ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
  $accion = $_POST['accion'] ?? '';
  $id_contrato = isset($_POST['id_contrato']) ? (int) $_POST['id_contrato'] : 0;
  $observaciones = trim($_POST['observaciones'] ?? '');

  if ($id_contrato > 0 && in_array($accion, ['suspender', 'reactivar', 'cancelar', 'eliminar'])) {
    try {
      $pdo->beginTransaction();

      $nuevo_estado = '';
      $mensaje = '';

      switch ($accion) {
        case 'suspender':
          $nuevo_estado = 'suspendido';
          $mensaje = 'Contrato suspendido';
          break;
        case 'reactivar':
          $nuevo_estado = 'activo';
          $mensaje = 'Contrato reactivado';
          break;
        case 'cancelar':
          $nuevo_estado = 'cancelado';
          $mensaje = 'Contrato cancelado';
          break;
        case 'eliminar':
          $mensaje = 'Contrato eliminado (soft delete)';
          break;
      }

      if ($accion === 'eliminar') {
        // SOFT DELETE: marcar como eliminado
        $sql = "UPDATE contratos SET 
                eliminado = TRUE, 
                eliminado_en = NOW(),
                eliminado_por = :id_usuario,
                observaciones = :obs 
                WHERE id_contrato = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':id_usuario' => (int) $_SESSION['id_usuario'],
          ':obs' => $observaciones ?: "Contrato eliminado por el usuario",
          ':id' => $id_contrato
        ]);
      } else {
        // Cambiar estado normal
        $sql = "UPDATE contratos SET estado = :estado, observaciones = :obs WHERE id_contrato = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
          ':estado' => $nuevo_estado,
          ':obs' => $observaciones ?: "Estado cambiado a {$nuevo_estado}",
          ':id' => $id_contrato
        ]);
      }

      $pdo->commit();
      $flash = ['ok', $mensaje, 'El estado del contrato ha sido actualizado.'];

      // Redirigir para limpiar POST
      header("Location: contratos.php?estado=" . urlencode($filtro_estado) . "&success=1");
      exit;

    } catch (Throwable $e) {
      if ($pdo->inTransaction())
        $pdo->rollBack();
      $flash = ['error', 'Error', $e->getMessage() ?: 'No se pudo actualizar el contrato.'];
    }
  }
}

// Mostrar mensaje de éxito si viene de redirección
if (!empty($_GET['success'])) {
  $flash = ['ok', 'Operación exitosa', 'El estado del contrato se actualizó correctamente.'];
}

/* ---------- FILTRO POR ESTADO ---------- */
$filtro_estado = $_GET['estado'] ?? 'todos';
$where_conditions = ["co.eliminado IS NOT TRUE"];  // Solo contratos no eliminados
$params = [];

if ($filtro_estado !== 'todos') {
  if ($filtro_estado === 'eliminados') {
    $where_conditions = ["co.eliminado = TRUE"];  // Mostrar solo eliminados
  } else {
    $where_conditions[] = "co.estado = :estado";
    $params[':estado'] = $filtro_estado;
  }
}

// Opcional: también excluir clientes eliminados si quieres
// $where_conditions[] = "cl.eliminado IS NOT TRUE";

$where_clause = implode(' AND ', $where_conditions);

/* ---------- LISTADO DE CONTRATOS ---------- */
$sql = "
SELECT 
    co.id_contrato,
    cl.codigo_cliente,
    cl.nombre_completo as nombre_cliente,
    cl.email,
    cl.telefono,
    s.nombre_servicio,
    s.precio_base,
    co.monto_mensual,
    co.fecha_inicio_contrato,
    co.fecha_fin_contrato,
    co.estado,
    co.observaciones,
    co.id_servicio,
    co.eliminado,
    co.eliminado_en,
    co.eliminado_por,
    cl.eliminado as cliente_eliminado
FROM contratos co
JOIN clientes cl ON co.id_cliente = cl.id_cliente
JOIN servicios s ON co.id_servicio = s.id_servicio
WHERE {$where_clause}
ORDER BY 
    CASE co.estado 
        WHEN 'activo' THEN 1
        WHEN 'suspendido' THEN 2
        WHEN 'cancelado' THEN 3
        ELSE 4
    END,
    co.fecha_inicio_contrato DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ---------- ESTADÍSTICAS ---------- */
$stats_sql = "
SELECT 
    co.estado,
    COUNT(*) as cantidad,
    SUM(co.monto_mensual) as total_mensual
FROM contratos co
WHERE co.eliminado IS NOT TRUE
GROUP BY co.estado
";
$stats = $pdo->query($stats_sql)->fetchAll(PDO::FETCH_ASSOC);

$estadisticas = [
  'activo' => ['cantidad' => 0, 'total' => 0],
  'suspendido' => ['cantidad' => 0, 'total' => 0],
  'cancelado' => ['cantidad' => 0, 'total' => 0],
  'eliminados' => ['cantidad' => 0, 'total' => 0],
  'todos' => ['cantidad' => 0, 'total' => 0]
];

foreach ($stats as $stat) {
  if (isset($estadisticas[$stat['estado']])) {
    $estadisticas[$stat['estado']] = [
      'cantidad' => (int) $stat['cantidad'],
      'total' => (float) $stat['total_mensual']
    ];
  }
}

// Contar eliminados
$sql_eliminados = "SELECT COUNT(*) as cantidad, SUM(monto_mensual) as total FROM contratos WHERE eliminado = TRUE";
$eliminados = $pdo->query($sql_eliminados)->fetch(PDO::FETCH_ASSOC);
$estadisticas['eliminados'] = [
  'cantidad' => (int) $eliminados['cantidad'],
  'total' => (float) $eliminados['total']
];

// Calcular totales para "todos" (sin incluir eliminados)
$estadisticas['todos']['cantidad'] = $estadisticas['activo']['cantidad'] +
  $estadisticas['suspendido']['cantidad'] +
  $estadisticas['cancelado']['cantidad'];
$estadisticas['todos']['total'] = $estadisticas['activo']['total'] +
  $estadisticas['suspendido']['total'] +
  $estadisticas['cancelado']['total'];

$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolUI = strtoupper($_SESSION['rol'] ?? 'USUARIO');
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Gestión de Contratos</title>
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

    .danger {
      background: linear-gradient(90deg, #ef4444, #dc2626);
      color: #fff;
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

    .card {
      background: var(--glass);
      border: 1px solid rgba(255, 255, 255, .12);
      border-radius: 14px;
      padding: 15px;
      margin-bottom: 12px;
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

    .table-wrap {
      overflow: auto;
      max-height: calc(100vh - 300px);
    }

    .table-ayanet {
      width: 100%;
      min-width: 1000px;
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

    .btn-small {
      padding: 6px 10px;
      font-size: 0.8rem;
    }

    .estado-activo {
      color: #10b981;
      font-weight: 600;
    }

    .estado-suspendido {
      color: #f59e0b;
      font-weight: 600;
    }

    .estado-cancelado {
      color: #ef4444;
      font-weight: 600;
    }

    .estado-eliminado {
      color: #6b7280;
      font-weight: 600;
      text-decoration: line-through;
    }

    .badge {
      background: rgba(111, 0, 255, .18);
      border: 1px solid rgba(111, 0, 255, .35);
      color: #dcd6ff;
      border-radius: 999px;
      padding: 4px 10px;
      font-size: 0.8rem;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(5, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }

    @media (max-width: 1200px) {
      .stats-grid {
        grid-template-columns: repeat(3, 1fr);
      }
    }

    @media (max-width: 768px) {
      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
      }
    }

    .stat-card {
      background: rgba(255, 255, 255, .05);
      border-radius: 10px;
      padding: 15px;
      text-align: center;
      border: 1px solid rgba(255, 255, 255, .1);
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .stat-card:hover {
      transform: translateY(-2px);
      background: rgba(255, 255, 255, .08);
    }

    .stat-card.active {
      background: rgba(255, 255, 255, .12);
      border-color: var(--neon1);
    }

    .stat-number {
      font-size: 1.5rem;
      font-weight: 700;
      margin: 5px 0;
    }

    .stat-todos {
      border-left: 4px solid var(--neon1);
    }

    .stat-activo {
      border-left: 4px solid #10b981;
    }

    .stat-suspendido {
      border-left: 4px solid #f59e0b;
    }

    .stat-cancelado {
      border-left: 4px solid #ef4444;
    }

    .stat-eliminados {
      border-left: 4px solid #6b7280;
    }

    .filtro-info {
      background: rgba(255, 255, 255, .05);
      padding: 10px 15px;
      border-radius: 10px;
      margin-bottom: 15px;
      border-left: 4px solid var(--neon1);
    }

    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.7);
    }

    .modal-content {
      background: var(--panel);
      margin: 5% auto;
      padding: 20px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      width: 90%;
      max-width: 500px;
    }

    .modal-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 20px;
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

    .btn-print {
      background: linear-gradient(90deg, #8b5cf6, #a855f7);
    }

    .tabs-container {
      margin-bottom: 20px;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .tab-filtro {
      display: inline-block;
      padding: 8px 16px;
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .16);
      border-radius: 8px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .tab-filtro:hover {
      background: rgba(255, 255, 255, .12);
    }

    .tab-filtro.active {
      background: var(--neon1);
      color: #061022;
      font-weight: 600;
    }

    /* Loading overlay */
    .loading-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(6, 9, 21, 0.8);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      flex-direction: column;
    }

    .spinner {
      width: 50px;
      height: 50px;
      border: 5px solid rgba(255, 255, 255, 0.1);
      border-top: 5px solid var(--neon1);
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-bottom: 15px;
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    .loading-text {
      color: var(--neon1);
      font-size: 1.1rem;
      font-weight: 500;
    }

    .sin-resultados {
      text-align: center;
      padding: 30px;
      color: var(--muted);
    }

    .sin-resultados i {
      font-size: 3rem;
      margin-bottom: 15px;
      opacity: 0.5;
    }

    .cliente-eliminado {
      color: #6b7280 !important;
      font-style: italic;
    }

    .cliente-eliminado-badge {
      background: rgba(107, 114, 128, 0.2);
      color: #9ca3af;
      font-size: 0.7rem;
      padding: 2px 6px;
      border-radius: 4px;
      margin-left: 5px;
    }
  </style>
</head>

<body class="bg">
  <!-- Loading overlay -->
  <div class="loading-overlay" id="loadingOverlay">
    <div class="spinner"></div>
    <div class="loading-text">Procesando...</div>
  </div>

  <div class="wrap">
    <header class="topbar">
      <div class="brand">
        <div class="logo"></div>
        <div>
          <div style="font-weight:700;letter-spacing:.3px">AYONET · Gestión de Contratos</div>
          <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
        </div>
      </div>
      <div class="top-actions">
        <a class="btn ghost" href="../menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
        <a class="btn ghost" href="../clientes.php"><i class="fa-solid fa-users"></i> Clientes</a>
        <span class="pill"><?= h($rolUI) ?></span>
      </div>
    </header>

    <section class="panel">
      <!-- Estadísticas con Filtros -->
      <div class="stats-grid">
        <div class="stat-card stat-todos <?= $filtro_estado === 'todos' ? 'active' : '' ?>"
          onclick="filtrarContratos('todos')">
          <div style="color: var(--neon1);"><i class="fa-solid fa-layer-group"></i> Todos</div>
          <div class="stat-number"><?= $estadisticas['todos']['cantidad'] ?></div>
          <div style="font-size: 0.8rem; color: var(--muted);">
            $<?= number_format($estadisticas['todos']['total'], 2) ?></div>
        </div>
        <div class="stat-card stat-activo <?= $filtro_estado === 'activo' ? 'active' : '' ?>"
          onclick="filtrarContratos('activo')">
          <div style="color: #10b981;"><i class="fa-solid fa-play-circle"></i> Activos</div>
          <div class="stat-number"><?= $estadisticas['activo']['cantidad'] ?></div>
          <div style="font-size: 0.8rem; color: var(--muted);">
            $<?= number_format($estadisticas['activo']['total'], 2) ?></div>
        </div>
        <div class="stat-card stat-suspendido <?= $filtro_estado === 'suspendido' ? 'active' : '' ?>"
          onclick="filtrarContratos('suspendido')">
          <div style="color: #f59e0b;"><i class="fa-solid fa-pause-circle"></i> Suspendidos</div>
          <div class="stat-number"><?= $estadisticas['suspendido']['cantidad'] ?></div>
          <div style="font-size: 0.8rem; color: var(--muted);">
            $<?= number_format($estadisticas['suspendido']['total'], 2) ?></div>
        </div>
        <div class="stat-card stat-cancelado <?= $filtro_estado === 'cancelado' ? 'active' : '' ?>"
          onclick="filtrarContratos('cancelado')">
          <div style="color: #ef4444;"><i class="fa-solid fa-stop-circle"></i> Cancelados</div>
          <div class="stat-number"><?= $estadisticas['cancelado']['cantidad'] ?></div>
          <div style="font-size: 0.8rem; color: var(--muted);">
            $<?= number_format($estadisticas['cancelado']['total'], 2) ?></div>
        </div>
        <div class="stat-card stat-eliminados <?= $filtro_estado === 'eliminados' ? 'active' : '' ?>"
          onclick="filtrarContratos('eliminados')">
          <div style="color: #6b7280;"><i class="fa-solid fa-trash"></i> Eliminados</div>
          <div class="stat-number"><?= $estadisticas['eliminados']['cantidad'] ?></div>
          <div style="font-size: 0.8rem; color: var(--muted);">
            $<?= number_format($estadisticas['eliminados']['total'], 2) ?></div>
        </div>
      </div>

      <!-- Información del filtro actual -->
      <div class="filtro-info">
        <div style="display: flex; justify-content: space-between; align-items: center;">
          <div>
            <strong>Filtro actual:</strong>
            <span class="<?=
              $filtro_estado === 'activo' ? 'estado-activo' :
              ($filtro_estado === 'suspendido' ? 'estado-suspendido' :
                ($filtro_estado === 'cancelado' ? 'estado-cancelado' :
                  ($filtro_estado === 'eliminados' ? 'estado-eliminado' : '')))
              ?>">
              <?=
                $filtro_estado === 'todos' ? 'Todos los contratos' :
                ($filtro_estado === 'eliminados' ? 'Contratos eliminados' :
                  ucfirst($filtro_estado) . 's')
                ?>
            </span>
            <span style="color: var(--muted); margin-left: 10px;">
              (<?= count($contratos) ?> contrato<?= count($contratos) !== 1 ? 's' : '' ?>
              encontrado<?= count($contratos) !== 1 ? 's' : '' ?>)
            </span>
          </div>
          <?php if ($filtro_estado !== 'todos'): ?>
            <button class="btn ghost btn-small" onclick="filtrarContratos('todos')">
              <i class="fa-solid fa-times"></i> Limpiar filtro
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Tabs de filtro rápido -->
      <div class="tabs-container">
        <div class="tab-filtro <?= $filtro_estado === 'todos' ? 'active' : '' ?>" onclick="filtrarContratos('todos')">
          <i class="fa-solid fa-layer-group"></i> Todos
        </div>
        <div class="tab-filtro <?= $filtro_estado === 'activo' ? 'active' : '' ?>" onclick="filtrarContratos('activo')">
          <i class="fa-solid fa-play-circle"></i> Activos
        </div>
        <div class="tab-filtro <?= $filtro_estado === 'suspendido' ? 'active' : '' ?>"
          onclick="filtrarContratos('suspendido')">
          <i class="fa-solid fa-pause-circle"></i> Suspendidos
        </div>
        <div class="tab-filtro <?= $filtro_estado === 'cancelado' ? 'active' : '' ?>"
          onclick="filtrarContratos('cancelado')">
          <i class="fa-solid fa-stop-circle"></i> Cancelados
        </div>
        <div class="tab-filtro <?= $filtro_estado === 'eliminados' ? 'active' : '' ?>"
          onclick="filtrarContratos('eliminados')">
          <i class="fa-solid fa-trash"></i> Eliminados
        </div>
      </div>

      <!-- Tabla de Contratos -->
      <div class="card">
        <h3>Listado de Contratos <?= $filtro_estado !== 'todos' ? ' - ' .
          ($filtro_estado === 'eliminados' ? 'Eliminados' : ucfirst($filtro_estado) . 's') : '' ?></h3>

        <?php if (empty($contratos)): ?>
          <div class="sin-resultados">
            <i class="fa-solid fa-search"></i>
            <h4>No se encontraron contratos <?= $filtro_estado !== 'todos' ?
              ($filtro_estado === 'eliminados' ? 'Eliminados' : ucfirst($filtro_estado) . 's') : '' ?></h4>
            <p style="color: var(--muted);">
              <?php if ($filtro_estado !== 'todos'): ?>
                No hay contratos con estado "
                <?=
                  $filtro_estado === 'eliminados' ? 'eliminado' : ucfirst($filtro_estado)
                  ?>"
              <?php else: ?>
                No hay contratos registrados en el sistema
              <?php endif; ?>
            </p>
            <button class="btn ghost" onclick="filtrarContratos('todos')" style="margin-top: 15px;">
              <i class="fa-solid fa-layer-group"></i> Ver todos los contratos
            </button>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table id="tablaContratos" class="display compact table-ayanet" style="width:100%">
              <thead>
                <tr>
                  <th>Cliente</th>
                  <th>Servicio</th>
                  <th>Monto</th>
                  <th>Inicio</th>
                  <th>Fin</th>
                  <th>Estado</th>
                  <th>Acciones</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($contratos as $cont): ?>
                  <tr class="row <?= $cont['cliente_eliminado'] ? 'cliente-eliminado' : '' ?>">
                    <td>
                      <div><strong><?= h($cont['codigo_cliente']) ?></strong></div>
                      <div style="font-size: 0.8rem;"><?= h($cont['nombre_cliente']) ?>
                        <?php if ($cont['cliente_eliminado']): ?>
                          <span class="cliente-eliminado-badge">Cliente eliminado</span>
                        <?php endif; ?>
                      </div>
                      <div style="font-size: 0.7rem; color: var(--muted);"><?= h($cont['email']) ?></div>
                    </td>
                    <td>
                      <div><strong><?= h($cont['nombre_servicio']) ?></strong></div>
                      <div style="font-size: 0.8rem; color: var(--muted);">Base:
                        $<?= number_format($cont['precio_base'], 2) ?></div>
                    </td>
                    <td>
                      <div style="font-weight: 600;">$<?= number_format($cont['monto_mensual'], 2) ?></div>
                      <div style="font-size: 0.8rem; color: var(--muted);">/mes</div>
                    </td>
                    <td><?= h(date('d/m/Y', strtotime($cont['fecha_inicio_contrato']))) ?></td>
                    <td><?= $cont['fecha_fin_contrato'] ? h(date('d/m/Y', strtotime($cont['fecha_fin_contrato']))) : '—' ?>
                    </td>
                    <td>
                      <?php if ($cont['eliminado']): ?>
                        <span class="estado-eliminado">
                          <i class="fa-solid fa-trash"></i> Eliminado
                        </span>
                      <?php else: ?>
                        <span class="<?=
                          $cont['estado'] === 'activo' ? 'estado-activo' :
                          ($cont['estado'] === 'suspendido' ? 'estado-suspendido' : 'estado-cancelado')
                          ?>">
                          <i class="fa-solid <?=
                            $cont['estado'] === 'activo' ? 'fa-play-circle' :
                            ($cont['estado'] === 'suspendido' ? 'fa-pause-circle' : 'fa-stop-circle')
                            ?>"></i>
                          <?= h(ucfirst($cont['estado'])) ?>
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="actions">
                      <!-- BOTÓN DE IMPRESIÓN PARA TODOS LOS ESTADOS -->
                      <button class="btn btn-print btn-small" onclick="imprimirContrato(<?= (int) $cont['id_contrato'] ?>)">
                        <i class="fa-solid fa-print"></i> Imprimir
                      </button>

                      <?php if ($cont['eliminado']): ?>
                        <!-- Si está eliminado, solo puede ver -->
                        <span style="color: var(--muted); font-size: 0.8rem;">Eliminado</span>
                      <?php else: ?>
                        <?php if ($cont['estado'] === 'activo'): ?>
                          <button class="btn ghost btn-small"
                            onclick="suspenderContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-pause"></i> Suspender
                          </button>
                          <button class="btn ghost btn-small"
                            onclick="cancelarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-stop"></i> Cancelar
                          </button>
                          <button class="btn danger btn-small"
                            onclick="eliminarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-trash"></i> Eliminar
                          </button>
                        <?php elseif ($cont['estado'] === 'suspendido'): ?>
                          <button class="btn ghost btn-small"
                            onclick="reactivarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-play"></i> Reactivar
                          </button>
                          <button class="btn ghost btn-small"
                            onclick="cancelarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-stop"></i> Cancelar
                          </button>
                          <button class="btn danger btn-small"
                            onclick="eliminarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-trash"></i> Eliminar
                          </button>
                        <?php else: ?>
                          <span style="color: var(--muted); font-size: 0.8rem;">Solo lectura</span>
                          <button class="btn danger btn-small"
                            onclick="eliminarContrato(<?= (int) $cont['id_contrato'] ?>, '<?= h($cont['nombre_cliente']) ?>', '<?= h($cont['nombre_servicio']) ?>')">
                            <i class="fa-solid fa-trash"></i> Eliminar
                          </button>
                        <?php endif; ?>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </div>

  <!-- Modal para cambiar estado -->
  <div id="modalEstado" class="modal">
    <div class="modal-content">
      <h3 id="modalTitulo">Cambiar Estado del Contrato</h3>
      <form id="formEstado" method="post" onsubmit="return confirmarCambioEstado()">
        <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
        <input type="hidden" name="id_contrato" id="modalIdContrato">
        <input type="hidden" name="accion" id="modalAccion">

        <div id="modalInfo"
          style="background: rgba(255,255,255,0.05); padding: 10px; border-radius: 8px; margin-bottom: 15px;">
          <!-- Información del contrato -->
        </div>

        <label class="lab">Observaciones (opcional)</label>
        <textarea class="ctrl" name="observaciones" id="modalObservaciones" rows="3"
          placeholder="Motivo del cambio de estado..."></textarea>

        <div class="modal-actions">
          <button type="button" class="btn ghost" onclick="cerrarModal()">Cancelar</button>
          <button type="submit" class="btn primary" id="modalBoton">Confirmar</button>
        </div>
      </form>
    </div>
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
    let modal = document.getElementById('modalEstado');
    let form = document.getElementById('formEstado');

    // FUNCIÓN PARA MOSTRAR LOADING
    function mostrarLoading() {
      document.getElementById('loadingOverlay').style.display = 'flex';
    }

    // FUNCIÓN PARA OCULTAR LOADING
    function ocultarLoading() {
      document.getElementById('loadingOverlay').style.display = 'none';
    }

    // FUNCIÓN PARA FILTRAR CONTRATOS
    function filtrarContratos(estado) {
      mostrarLoading();
      window.location.href = 'contratos.php?estado=' + estado;
    }

    // FUNCIÓN PARA CONFIRMAR CAMBIO DE ESTADO
    function confirmarCambioEstado() {
      mostrarLoading();
      return true;
    }

    // FUNCIÓN PARA IMPRIMIR CONTRATO
    function imprimirContrato(id_contrato) {
      mostrarLoading();
      setTimeout(() => {
        window.open('imprimir_contrato.php?id=' + id_contrato, '_blank');
        ocultarLoading();
      }, 500);
    }

    function suspenderContrato(id, cliente, servicio) {
      document.getElementById('modalTitulo').textContent = 'Suspender Contrato';
      document.getElementById('modalIdContrato').value = id;
      document.getElementById('modalAccion').value = 'suspender';
      document.getElementById('modalBoton').innerHTML = '<i class="fa-solid fa-pause"></i> Suspender Contrato';
      document.getElementById('modalBoton').style.background = 'linear-gradient(90deg, #f59e0b, #f97316)';

      document.getElementById('modalInfo').innerHTML = `
                <div><strong>Cliente:</strong> ${cliente}</div>
                <div><strong>Servicio:</strong> ${servicio}</div>
                <div style="color: #f59e0b; margin-top: 5px;"><i class="fa-solid fa-exclamation-triangle"></i> El servicio se suspenderá temporalmente.</div>
            `;

      modal.style.display = 'block';
    }

    function reactivarContrato(id, cliente, servicio) {
      document.getElementById('modalTitulo').textContent = 'Reactivar Contrato';
      document.getElementById('modalIdContrato').value = id;
      document.getElementById('modalAccion').value = 'reactivar';
      document.getElementById('modalBoton').innerHTML = '<i class="fa-solid fa-play"></i> Reactivar Contrato';
      document.getElementById('modalBoton').style.background = 'linear-gradient(90deg, #10b981, #059669)';

      document.getElementById('modalInfo').innerHTML = `
                <div><strong>Cliente:</strong> ${cliente}</div>
                <div><strong>Servicio:</strong> ${servicio}</div>
                <div style="color: #10b981; margin-top: 5px;"><i class="fa-solid fa-check-circle"></i> El servicio se reactivará.</div>
            `;

      modal.style.display = 'block';
    }

    function cancelarContrato(id, cliente, servicio) {
      document.getElementById('modalTitulo').textContent = 'Cancelar Contrato';
      document.getElementById('modalIdContrato').value = id;
      document.getElementById('modalAccion').value = 'cancelar';
      document.getElementById('modalBoton').innerHTML = '<i class="fa-solid fa-stop"></i> Cancelar Contrato';
      document.getElementById('modalBoton').style.background = 'linear-gradient(90deg, #ef4444, #dc2626)';

      document.getElementById('modalInfo').innerHTML = `
                <div><strong>Cliente:</strong> ${cliente}</div>
                <div><strong>Servicio:</strong> ${servicio}</div>
                <div style="color: #ef4444; margin-top: 5px;"><i class="fa-solid fa-exclamation-circle"></i> Esta acción no se puede deshacer.</div>
            `;

      modal.style.display = 'block';
    }

    function eliminarContrato(id, cliente, servicio) {
      document.getElementById('modalTitulo').textContent = 'Eliminar Contrato';
      document.getElementById('modalIdContrato').value = id;
      document.getElementById('modalAccion').value = 'eliminar';
      document.getElementById('modalBoton').innerHTML = '<i class="fa-solid fa-trash"></i> Eliminar Contrato';
      document.getElementById('modalBoton').style.background = 'linear-gradient(90deg, #6b7280, #4b5563)';

      document.getElementById('modalInfo').innerHTML = `
                <div><strong>Cliente:</strong> ${cliente}</div>
                <div><strong>Servicio:</strong> ${servicio}</div>
                <div style="color: #6b7280; margin-top: 5px;">
                  <i class="fa-solid fa-exclamation-triangle"></i> 
                  Soft Delete: El contrato se marcará como eliminado pero permanecerá en la base de datos.
                </div>
                <div style="color: #9ca3af; font-size: 0.9rem; margin-top: 5px;">
                  Puedes ver los contratos eliminados en el filtro "Eliminados".
                </div>
            `;

      modal.style.display = 'block';
    }

    function cerrarModal() {
      modal.style.display = 'none';
      document.getElementById('modalObservaciones').value = '';
    }

    // Cerrar modal al hacer click fuera
    window.onclick = function (event) {
      if (event.target == modal) {
        cerrarModal();
      }
    }

    // Configurar DataTable solo si hay contratos
    <?php if (!empty($contratos)): ?>
      $(function () {
        $('#tablaContratos').DataTable({
          dom: '<"top"lf>rt<"bottom"ip><"clear">',
          searching: true,
          pageLength: 10,
          lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
          order: [[5, 'asc']], // Ordenar por estado
          responsive: false,
          autoWidth: false,
          language: {
            decimal: '',
            emptyTable: 'No hay contratos para mostrar',
            info: 'Mostrando _START_ a _END_ de _TOTAL_ contratos',
            infoEmpty: 'Mostrando 0 a 0 de 0 contratos',
            infoFiltered: '(filtrado de _MAX_ contratos totales)',
            lengthMenu: 'Mostrar _MENU_ contratos',
            loadingRecords: 'Cargando...',
            processing: 'Procesando...',
            search: 'Buscar:',
            zeroRecords: 'No se encontraron contratos coincidentes',
            paginate: {
              first: 'Primero',
              last: 'Último',
              next: 'Siguiente',
              previous: 'Anterior'
            }
          },
          initComplete: function () {
            ocultarLoading();
          }
        });
      });
    <?php else: ?>
      // Si no hay contratos, ocultar loading inmediatamente
      document.addEventListener('DOMContentLoaded', function () {
        ocultarLoading();
      });
    <?php endif; ?>

    // Ocultar loading cuando la página cargue completamente
    window.addEventListener('load', function () {
      setTimeout(ocultarLoading, 500);
    });

    // Mostrar loading al hacer click en enlaces
    document.addEventListener('click', function (e) {
      if (e.target.closest('a.btn')) {
        mostrarLoading();
      }
    });
  </script>
</body>

</html>