<?php
session_start();

// Seguridad mínima: exige login
if (!isset($_SESSION['id_usuario'])) {
  header("Location: login.php");
  exit;
}

// Datos para UI (compatibles con tu login)
$nombre = $_SESSION['nombre'] ?? 'Usuario';
$apepat = $_SESSION['apepat'] ?? '';
$rol = $_SESSION['rol'] ?? 'cliente'; // 'admin' | 'tecnico' | 'cliente'
$nombreUsuario = trim($nombre . ' ' . $apepat);
$id_usuario = $_SESSION['id_usuario'] ?? 0;

// Conexión BD
require_once __DIR__ . '/db.php';

/** Ejecuta COUNT(*) seguro; si falla, devuelve null */
function kpi_count(PDO $pdo, string $sql, array $params = [])
{
  try {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return ($v === false || $v === null) ? null : (int) $v;
  } catch (Throwable $e) {
    return null;
  }
}

# ================= KPIs (ajustados a tu DDL) =================
# 1) Clientes activos: clientes con al menos un contrato en estado 'activo'
$clientesActivos = kpi_count(
  $pdo,
  "SELECT COUNT(DISTINCT c.id_cliente)
     FROM public.clientes c
     JOIN public.contratos ct ON ct.id_cliente = c.id_cliente
    WHERE ct.estado = 'activo'"
);

# 2) Pagos hoy: registros en pagos con fecha de hoy
$pagosHoy = kpi_count(
  $pdo,
  "SELECT COUNT(*) FROM public.pagos p
    WHERE DATE(p.fecha_pago) = CURRENT_DATE"
);

# 3) Incidencias abiertas: 'abierta' o 'en_proceso'
$incidenciasAbiertas = kpi_count(
  $pdo,
  "SELECT COUNT(*) FROM public.incidencias i
    WHERE i.estado IN ('abierta','en_proceso')"
);

# 4) Recibos pendientes: pendientes o vencidos
$recibosPendientes = kpi_count(
  $pdo,
  "SELECT COUNT(*) FROM public.facturas f
    WHERE f.estado = 'vencida'
       OR (f.estado = 'pendiente' AND f.fecha_vencimiento < CURRENT_DATE)"
);

// === OBTENER NOTIFICACIONES PARA ADMIN ===
$notificaciones_admin = [];
$total_notificaciones_admin = 0;

try {
  // Notificaciones de incidencias nuevas (últimas 24 horas)
  $stmt_incidencias = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM public.incidencias 
        WHERE fecha_registro >= NOW() - INTERVAL '24 hours'
        AND estado = 'abierta'
    ");
  $stmt_incidencias->execute();
  $incidencias_nuevas = $stmt_incidencias->fetchColumn();

  // Notificaciones de incidencias sin asignar
  $stmt_sin_asignar = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM public.incidencias 
        WHERE id_tecnico IS NULL 
        AND estado = 'abierta'
    ");
  $stmt_sin_asignar->execute();
  $incidencias_sin_asignar = $stmt_sin_asignar->fetchColumn();

  // Notificaciones de la tabla notificaciones (si existe)
  $notificaciones_directas = 0;
  try {
    $stmt_notif = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM public.notificaciones 
            WHERE leido = false
            AND (id_usuario = ? OR id_usuario IS NULL)
        ");
    $stmt_notif->execute([$id_usuario]);
    $notificaciones_directas = $stmt_notif->fetchColumn();
  } catch (PDOException $e) {
    // Si la tabla notificaciones no existe, ignorar
    $notificaciones_directas = 0;
  }

  $total_notificaciones_admin = $incidencias_nuevas + $incidencias_sin_asignar + $notificaciones_directas;

  // Preparar datos para el panel de notificaciones
  $notificaciones_admin = [
    'incidencias_nuevas' => $incidencias_nuevas,
    'incidencias_sin_asignar' => $incidencias_sin_asignar,
    'notificaciones_directas' => $notificaciones_directas
  ];

} catch (PDOException $e) {
  $total_notificaciones_admin = 0;
  $notificaciones_admin = [];
}

function fmt_kpi($v)
{
  return ($v === null) ? '—' : number_format($v, 0, '.', ',');
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AYONET | Panel</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    :root {
      --neon1: #00d4ff;
      --neon2: #6a00ff;
      --neon3: #ff007a;
      --muted: #c7d1ff;
      --glass: rgba(255, 255, 255, .06);
      --success: #00ff88;
      --warning: #ffaa00;
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
      overflow: hidden;
      background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
    }

    /* Glow */
    .bg::before,
    .bg::after {
      content: "";
      position: fixed;
      z-index: -2;
      width: 60vmax;
      height: 60vmax;
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

    /* Layout */
    .app {
      display: grid;
      grid-template-columns: 82px 1fr;
      height: 100vh;
      gap: 16px;
      padding: 18px
    }

    .sidebar {
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      border-radius: 18px;
      padding: 12px 10px;
      backdrop-filter: blur(12px);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
    }

    .logo {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      margin: 6px 0 12px;
      background: radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%),
        radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%), #0c1133;
      box-shadow: 0 0 18px rgba(106, 0, 255, .35) inset, 0 0 25px rgba(0, 212, 255, .18);
      border: 1px solid rgba(255, 255, 255, .18);
    }

    .navbtn {
      width: 100%;
      border: none;
      background: transparent;
      color: #dfe6ff;
      cursor: pointer;
      display: grid;
      place-items: center;
      padding: 10px 0;
      border-radius: 12px;
      position: relative;
    }

    .navbtn i {
      font-size: 1.25rem
    }

    .navbtn.active,
    .navbtn:hover {
      background: rgba(255, 255, 255, .08);
      color: #fff
    }

    .navbtn .tip {
      position: absolute;
      top: -42px;
      left: 50%;
      transform: translateX(-50%);
      white-space: nowrap;
      background: rgba(15, 20, 50, 0.9);
      border: 1px solid rgba(255, 255, 255, .1);
      padding: 6px 10px;
      border-radius: 8px;
      font-size: .8rem;
      color: #cfe1ff;
      opacity: 0;
      pointer-events: none;
      transition: .25s;
      box-shadow: 0 4px 12px rgba(0, 0, 0, .25);
    }

    .navbtn:hover .tip {
      opacity: 1;
      transform: translateX(-50%) translateY(-4px)
    }

    /* Main */
    .main {
      display: grid;
      grid-template-rows: 68px 1fr;
      gap: 16px
    }

    .topbar {
      display: grid;
      grid-template-columns: auto 1fr auto;
      align-items: center;
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      border-radius: 18px;
      backdrop-filter: blur(12px);
      padding: 0 16px 0 12px;
      box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
      position: relative;
      z-index: 100;
    }

    .brand-title {
      display: flex;
      align-items: center;
      gap: 12px
    }

    .brand-title h1 {
      font-size: 1rem;
      margin: 0;
      letter-spacing: .5px;
      color: #cfe1ff
    }

    .pill {
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #051027;
      font-weight: 800;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: .8rem;
      margin-left: 10px
    }

    /* Reloj */
    .topclock {
      text-align: center;
      line-height: 1.1
    }

    #clockTop {
      font-weight: 800;
      font-size: 20px;
      letter-spacing: .5px;
      text-shadow: 0 0 12px rgba(0, 212, 255, .25), 0 0 28px rgba(106, 0, 255, .25)
    }

    #dateTop {
      font-size: 12.5px;
      color: #cfe1ff;
      opacity: .95
    }

    /* NOTIFICACIONES - VERSIÓN CORREGIDA */
    .user {
      display: flex;
      align-items: center;
      gap: 10px;
      position: relative;
    }

    .notifications {
      position: relative;
      margin-right: 15px;
    }

    .notification-btn {
      background: rgba(255, 255, 255, .08);
      border: 1px solid rgba(255, 255, 255, .16);
      color: #cfe1ff;
      padding: 10px 15px;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      z-index: 101;
    }

    .notification-btn:hover,
    .notification-btn.active {
      background: rgba(0, 212, 255, 0.15);
      border-color: var(--neon1);
      box-shadow: 0 0 15px rgba(0, 212, 255, 0.3);
    }

    .notification-badge {
      position: absolute;
      top: -8px;
      right: -8px;
      background: var(--danger);
      color: white;
      border-radius: 50%;
      width: 20px;
      height: 20px;
      font-size: 0.7rem;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      z-index: 102;
    }

    /* PANEL DE NOTIFICACIONES - VERSIÓN FUNCIONAL */
    .notification-panel {
      display: none;
      position: fixed;
      top: 90px;
      right: 30px;
      width: 450px;
      max-height: 600px;
      overflow-y: auto;
      background: rgba(8, 12, 35, 0.98);
      border: 2px solid var(--neon1);
      border-radius: 16px;
      padding: 25px;
      z-index: 10000;
      backdrop-filter: blur(25px);
      box-shadow: 0 25px 80px rgba(0, 0, 0, 0.8);
      animation: slideDown 0.3s ease-out;
    }

    @keyframes slideDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .notification-item {
      padding: 15px;
      margin-bottom: 12px;
      background: rgba(255, 255, 255, .08);
      border-radius: 10px;
      border-left: 4px solid var(--neon1);
      transition: all 0.3s ease;
    }

    .notification-item:hover {
      background: rgba(255, 255, 255, .12);
      transform: translateX(5px);
    }

    .notification-item.warning {
      border-left-color: var(--warning);
    }

    .notification-item.danger {
      border-left-color: var(--danger);
    }

    .notification-title {
      font-weight: 600;
      margin-bottom: 8px;
      color: var(--neon1);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .notification-desc {
      font-size: 0.85rem;
      color: var(--muted);
      margin-bottom: 5px;
      line-height: 1.4;
    }

    .notification-meta {
      font-size: 0.75rem;
      color: #888;
      display: flex;
      justify-content: space-between;
    }

    .no-notifications {
      text-align: center;
      color: var(--muted);
      padding: 30px 20px;
    }

    .no-notifications i {
      font-size: 3rem;
      margin-bottom: 15px;
      color: var(--success);
    }

    .user .avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: linear-gradient(90deg, var(--neon1), var(--neon2));
      box-shadow: 0 0 12px rgba(0, 212, 255, .45)
    }

    .user small {
      color: #cfe1ff
    }

    .logout {
      border: none;
      border-radius: 12px;
      padding: 8px 12px;
      cursor: pointer;
      background: #ff315e;
      color: white
    }

    .logout:hover {
      filter: brightness(1.05)
    }

    /* Viewport */
    .viewport {
      position: relative;
      overflow: hidden;
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      border-radius: 18px;
      backdrop-filter: blur(12px);
      box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
    }

    .slider {
      height: 100%;
      display: flex;
      gap: 0;
      transition: transform .55s cubic-bezier(.2, .7, .2, 1);
      will-change: transform
    }

    .view {
      flex: 0 0 100%;
      width: 100%;
      padding: 18px 18px 22px;
      overflow-y: auto;
    }

    .view h2 {
      margin: 0 0 8px;
      font-size: 1.8rem;
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .view p {
      color: #cfd6ff;
      font-size: 1rem;
      margin-bottom: 25px;
    }

    /* NUEVO DISEÑO MEJORADO PARA TARJETAS */
    .grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 20px;
      margin-top: 20px;
    }

    .card-module {
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.08), rgba(255, 255, 255, 0.03));
      border: 1px solid rgba(255, 255, 255, 0.12);
      border-radius: 20px;
      padding: 25px;
      position: relative;
      overflow: hidden;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      backdrop-filter: blur(10px);
      min-height: 200px;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .card-module::before {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.1), transparent);
      transition: left 0.6s;
    }

    .card-module:hover::before {
      left: 100%;
    }

    .card-module:hover {
      transform: translateY(-8px);
      box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(0, 212, 255, 0.2);
      border-color: rgba(0, 212, 255, 0.3);
    }

    .module-header {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 15px;
    }

    .module-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      background: linear-gradient(135deg, var(--neon1), var(--neon2));
      box-shadow: 0 8px 20px rgba(0, 212, 255, 0.3);
      transition: transform 0.3s ease;
    }

    .card-module:hover .module-icon {
      transform: scale(1.1) rotate(5deg);
    }

    .module-badge {
      background: rgba(255, 71, 87, 0.2);
      color: #ff4757;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
      border: 1px solid rgba(255, 71, 87, 0.3);
    }

    .module-content h3 {
      margin: 0 0 8px 0;
      font-size: 1.3rem;
      font-weight: 600;
      color: #fff;
    }

    .module-description {
      color: #bcd1ff;
      font-size: 0.9rem;
      line-height: 1.4;
      margin-bottom: 20px;
    }

    .module-features {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin-bottom: 20px;
    }

    .feature-tag {
      background: rgba(0, 212, 255, 0.1);
      color: #88e7ff;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 0.75rem;
      border: 1px solid rgba(0, 212, 255, 0.2);
    }

    .module-actions {
      display: flex;
      gap: 10px;
      align-items: center;
      justify-content: space-between;
    }

    .btn-module {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #051027;
      text-decoration: none;
      padding: 12px 20px;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.9rem;
      border: none;
      cursor: pointer;
      transition: all 0.3s ease;
      box-shadow: 0 4px 15px rgba(0, 212, 255, 0.3);
      position: relative;
      overflow: hidden;
    }

    .btn-module::before {
      content: "";
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
      transition: left 0.5s;
    }

    .btn-module:hover::before {
      left: 100%;
    }

    .btn-module:hover {
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(0, 212, 255, 0.4);
    }

    .btn-module.secondary {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: none;
    }

    .btn-module.secondary:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(0, 212, 255, 0.3);
    }

    /* Efectos de partículas en cards */
    .particles {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
      z-index: 1;
    }

    .particle {
      position: absolute;
      background: var(--neon1);
      border-radius: 50%;
      opacity: 0;
      animation: float-particle 6s ease-in-out infinite;
    }

    @keyframes float-particle {

      0%,
      100% {
        transform: translateY(0) translateX(0);
        opacity: 0;
      }

      50% {
        opacity: 0.3;
      }
    }

    /* Animaciones específicas por módulo */
    .card-module.contratos::after {
      content: "📄";
      position: absolute;
      bottom: -20px;
      right: -20px;
      font-size: 120px;
      opacity: 0.03;
      transform: rotate(15deg);
      z-index: 0;
    }

    .card-module.clientes::after {
      content: "👥";
      position: absolute;
      bottom: -20px;
      right: -20px;
      font-size: 120px;
      opacity: 0.03;
      transform: rotate(15deg);
      z-index: 0;
    }

    .card-module.servicios::after {
      content: "📶";
      position: absolute;
      bottom: -20px;
      right: -20px;
      font-size: 120px;
      opacity: 0.03;
      transform: rotate(15deg);
      z-index: 0;
    }

    @media (max-width:1100px) {
      .grid {
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      }
    }

    @media (max-width:720px) {
      .app {
        grid-template-columns: 70px 1fr
      }

      .grid {
        grid-template-columns: 1fr;
      }

      .module-actions {
        flex-direction: column;
        align-items: stretch;
      }

      .btn-module {
        justify-content: center;
      }

      .notification-panel {
        width: 350px;
        right: 10px;
        left: 10px;
        margin: 0 auto;
      }
    }

    @media (max-width:480px) {
      .notification-panel {
        width: 95vw;
        right: 2.5vw;
        left: 2.5vw;
      }
    }
  </style>
</head>

<body class="bg">
  <div class="app">
    <!-- SIDEBAR -->
    <aside class="sidebar">
      <div class="logo" title="AYONET"></div>

      <!-- Inicio -->
      <button class="navbtn active" data-target="dashboard">
        <i class="fa-solid fa-house"></i><span class="tip">Inicio</span>
      </button>

      <?php if ($rol === 'admin' || $rol === 'tecnico') { ?>
        <button class="navbtn" data-target="clientes">
          <i class="fa-solid fa-users"></i><span class="tip">Clientes</span>
        </button>
        <button class="navbtn" data-target="servicios">
          <i class="fa-solid fa-wifi"></i><span class="tip">Servicios</span>
        </button>
        <button class="navbtn" data-target="contratos">
          <i class="fa-solid fa-file-contract"></i><span class="tip">Contratos</span>
        </button>
        <button class="navbtn" data-target="recibos">
          <i class="fa-solid fa-receipt"></i><span class="tip">Recibos</span>
        </button>
      <?php } ?>

      <button class="navbtn" data-target="pagos">
        <i class="fa-solid fa-credit-card"></i><span class="tip">Pagos</span>
      </button>
      <!-- AGREGAR ESTE BOTÓN DE REPORTES -->
      <button class="navbtn" data-target="reportes">
        <i class="fa-solid fa-chart-bar"></i><span class="tip">Reportes</span>
      </button>

      <button class="navbtn" data-target="incidencias">
        <i class="fa-solid fa-triangle-exclamation"></i><span class="tip">Incidencias</span>
      </button>

      <?php if ($rol === 'admin') { ?>
        <button class="navbtn" data-target="tecnicos">
          <i class="fa-solid fa-screwdriver-wrench"></i><span class="tip">Técnicos</span>
        </button>
        <button class="navbtn" data-target="usuarios">
          <i class="fa-solid fa-id-badge"></i><span class="tip">Usuarios</span>
        </button>
      <?php } ?>
    </aside>

    <!-- MAIN -->
    <section class="main">
      <header class="topbar">
        <div class="brand-title">
          <h1>AYONET · <span style="color:#8ee7ff">Panel</span></h1>
          <span class="pill"><?php echo strtoupper(htmlspecialchars($rol)); ?></span>
        </div>
        <div class="topclock">
          <div id="clockTop">--:--:--</div>
          <div id="dateTop">Cargando...</div>
        </div>
        <div class="user">
          <!-- NOTIFICACIONES - VERSIÓN CORREGIDA -->
          <div class="notifications">
            <button class="notification-btn" onclick="toggleNotifications(event)">
              <i class="fas fa-bell"></i>
              <?php if ($total_notificaciones_admin > 0): ?>
                <span class="notification-badge"><?php echo $total_notificaciones_admin; ?></span>
              <?php endif; ?>
            </button>

            <!-- PANEL DE NOTIFICACIONES - VERSIÓN FUNCIONAL -->
            <div class="notification-panel" id="notificationPanel">
              <h4 style="margin-bottom: 15px; color: var(--neon1); text-align: center;">Alertas del Sistema</h4>

              <?php if ($total_notificaciones_admin > 0): ?>
                <!-- Incidencias nuevas -->
                <?php if ($notificaciones_admin['incidencias_nuevas'] > 0): ?>
                  <div class="notification-item">
                    <div class="notification-title">
                      <span>Incidencias Nuevas</span>
                      <span class="badge" style="
                        background: var(--neon1);
                        color: #061022;
                        border-radius: 12px;
                        padding: 2px 8px;
                        font-size: 0.7rem;
                        font-weight: bold;
                      "><?php echo $notificaciones_admin['incidencias_nuevas']; ?></span>
                    </div>
                    <div class="notification-desc">
                      Nuevas incidencias reportadas en las últimas 24 horas.
                    </div>
                  </div>
                <?php endif; ?>

                <!-- Incidencias sin asignar -->
                <?php if ($notificaciones_admin['incidencias_sin_asignar'] > 0): ?>
                  <div class="notification-item warning">
                    <div class="notification-title">
                      <span>Incidencias Sin Asignar</span>
                      <span class="badge" style="
                        background: var(--warning);
                        color: #061022;
                        border-radius: 12px;
                        padding: 2px 8px;
                        font-size: 0.7rem;
                        font-weight: bold;
                      "><?php echo $notificaciones_admin['incidencias_sin_asignar']; ?></span>
                    </div>
                    <div class="notification-desc">
                      Incidencias pendientes de asignación a técnicos.
                    </div>
                  </div>
                <?php endif; ?>

                <!-- Notificaciones directas -->
                <?php if ($notificaciones_admin['notificaciones_directas'] > 0): ?>
                  <div class="notification-item danger">
                    <div class="notification-title">
                      <span>Notificaciones Directas</span>
                      <span class="badge" style="
                        background: var(--danger);
                        color: white;
                        border-radius: 12px;
                        padding: 2px 8px;
                        font-size: 0.7rem;
                        font-weight: bold;
                      "><?php echo $notificaciones_admin['notificaciones_directas']; ?></span>
                    </div>
                    <div class="notification-desc">
                      Notificaciones del sistema pendientes de revisión.
                    </div>
                  </div>
                <?php endif; ?>

                <div style="text-align: center; margin-top: 15px;">
                  <a href="incidencias.php" class="btn-module">
                    <i class="fa-solid fa-arrow-right"></i> Ver Incidencias
                  </a>
                </div>

              <?php else: ?>
                <div class="no-notifications">
                  <i class="fas fa-check-circle"></i>
                  <p>¡Todo en orden!<br>No hay alertas pendientes</p>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="avatar"></div>
          <div>
            <div><?php echo htmlspecialchars($nombreUsuario); ?></div>
            <small>Bienvenid@</small>
          </div>
          <form action="logout.php" method="post" style="margin:0"><button class="logout">Salir</button></form>
        </div>
      </header>

      <div class="viewport">
        <div class="slider" id="slider">
          <!-- INICIO -->
          <section class="view" id="dashboard">
            <h2>Inicio</h2>
            <p>Resúmenes rápidos de tu operación. Desliza o usa el menú lateral.</p>
            <div class="grid">
              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-users"></i>
                  </div>
                  <span class="module-badge">Activos</span>
                </div>
                <div class="module-content">
                  <h3>Clientes Activos</h3>
                  <div class="module-description">Clientes con contrato vigente en el sistema</div>
                  <div class="feature-tag"><?php echo fmt_kpi($clientesActivos); ?> registros</div>
                </div>
              </div>

              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-credit-card"></i>
                  </div>
                  <span class="module-badge">Hoy</span>
                </div>
                <div class="module-content">
                  <h3>Pagos del Día</h3>
                  <div class="module-description">Pagos registrados en la fecha actual</div>
                  <div class="feature-tag"><?php echo fmt_kpi($pagosHoy); ?> pagos</div>
                </div>
              </div>

              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                  </div>
                  <span class="module-badge">Abiertas</span>
                </div>
                <div class="module-content">
                  <h3>Incidencias Activas</h3>
                  <div class="module-description">Reportes pendientes de resolución</div>
                  <div class="feature-tag"><?php echo fmt_kpi($incidenciasAbiertas); ?> casos</div>
                </div>
              </div>

              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-receipt"></i>
                  </div>
                  <span class="module-badge">Pendientes</span>
                </div>
                <div class="module-content">
                  <h3>Recibos por Cobrar</h3>
                  <div class="module-description">Recibos pendientes o vencidos</div>
                  <div class="feature-tag"><?php echo fmt_kpi($recibosPendientes); ?> recibos</div>
                </div>
              </div>
            </div>
          </section>

          <?php if ($rol === 'admin' || $rol === 'tecnico') { ?>
            <!-- CLIENTES -->
            <section class="view" id="clientes">
              <h2>Clientes</h2>
              <p>Gestiona toda la información de tus clientes y su historial completo.</p>
              <div class="grid">
                <div class="card-module clientes">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-users"></i>
                    </div>
                    <span class="module-badge">Gestión</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Clientes</h3>
                    <div class="module-description">Administra el registro completo de clientes, direcciones y contactos.
                    </div>
                    <div class="module-features">
                      <span class="feature-tag">Registro</span>
                      <span class="feature-tag">Edición</span>
                      <span class="feature-tag">Consultas</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="clientes.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder al Módulo
                    </a>
                  </div>
                </div>
              </div>
            </section>

            <!-- SERVICIOS -->
            <section class="view" id="servicios">
              <h2>Servicios</h2>
              <p>Administra los planes de internet y configura los servicios disponibles.</p>
              <div class="grid">
                <div class="card-module servicios">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-wifi"></i>
                    </div>
                    <span class="module-badge">Configuración</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Servicios</h3>
                    <div class="module-description">Configura planes, precios y características de los servicios de
                      internet.</div>
                    <div class="module-features">
                      <span class="feature-tag">Planes</span>
                      <span class="feature-tag">Precios</span>
                      <span class="feature-tag">Velocidades</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="servicios.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder al Módulo
                    </a>
                  </div>
                </div>
              </div>
            </section>

            <!-- CONTRATOS -->
            <section class="view" id="contratos">
              <h2>Contratos</h2>
              <p>Gestiona los contratos de servicios, renovaciones y cancelaciones.</p>
              <div class="grid">
                <div class="card-module contratos">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-file-contract"></i>
                    </div>
                    <span class="module-badge">Legal</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Contratos</h3>
                    <div class="module-description">Crea, renueva y administra contratos de servicios con clientes.</div>
                    <div class="module-features">
                      <span class="feature-tag">Crear</span>
                      <span class="feature-tag">Renovar</span>
                      <span class="feature-tag">Cancelar</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="contratos/contratos.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder al Módulo
                    </a>
                    <a href="contratos/imprimir_contrato.php" class="btn-module secondary" target="_blank">
                      <i class="fa-solid fa-print"></i> Imprimir
                    </a>
                  </div>
                </div>
              </div>
            </section>

            <!-- RECIBOS -->
            <section class="view" id="recibos">
              <h2>Recibos</h2>
              <p>Controla la emisión, estado y administración de recibos de pago.</p>
              <div class="grid">
                <div class="card-module">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-receipt"></i>
                    </div>
                    <span class="module-badge">Administración</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Recibos</h3>
                    <div class="module-description">Consulta, genera y administra todos los recibos del sistema.</div>
                    <div class="module-features">
                      <span class="feature-tag">Consultar</span>
                      <span class="feature-tag">Generar</span>
                      <span class="feature-tag">Estados</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="recibos.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder
                    </a>
                  </div>
                </div>

                <div class="card-module">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-plus"></i>
                    </div>
                    <span class="module-badge">Generación</span>
                  </div>
                  <div class="module-content">
                    <h3>Generar Recibos</h3>
                    <div class="module-description">Generación masiva de recibos para el período actual.</div>
                    <div class="module-features">
                      <span class="feature-tag">Mensual</span>
                      <span class="feature-tag">Automático</span>
                      <span class="feature-tag">Masivo</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="generar_recibos.php" class="btn-module">
                      <i class="fa-solid fa-bolt"></i> Generar
                    </a>
                  </div>
                </div>
              </div>
            </section>
          <?php } ?>

          <!-- PAGOS -->
          <section class="view" id="pagos">
            <h2>Pagos</h2>
            <p>Registra, consulta y administra los pagos de los clientes.</p>
            <div class="grid">
              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-credit-card"></i>
                  </div>
                  <span class="module-badge">Historial</span>
                </div>
                <div class="module-content">
                  <h3>Historial de Pagos</h3>
                  <div class="module-description">Consulta completa de todos los pagos registrados en el sistema.</div>
                  <div class="module-features">
                    <span class="feature-tag">Consultas</span>
                    <span class="feature-tag">Filtros</span>
                    <span class="feature-tag">Reportes</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="pagos.php" class="btn-module">
                    <i class="fa-solid fa-arrow-right"></i> Acceder
                  </a>
                </div>
              </div>

              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-money-bill-wave"></i>
                  </div>
                  <span class="module-badge">Registro</span>
                </div>
                <div class="module-content">
                  <h3>Registrar Pago</h3>
                  <div class="module-description">Registro manual de nuevos pagos de clientes.</div>
                  <div class="module-features">
                    <span class="feature-tag">Manual</span>
                    <span class="feature-tag">Rápido</span>
                    <span class="feature-tag">Individual</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="registrar_pago.php" class="btn-module">
                    <i class="fa-solid fa-plus"></i> Registrar
                  </a>
                </div>
              </div>
            </div>
          </section>
          <!-- NUEVA SECCIÓN: REPORTES -->
          <section class="view" id="reportes">
            <h2>Reportes</h2>
            <p>Genera reportes avanzados, estadísticas y análisis de tu operación.</p>
            <div class="grid">
              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-chart-line"></i>
                  </div>
                  <span class="module-badge">Análisis</span>
                </div>
                <div class="module-content">
                  <h3>Reportes Avanzados</h3>
                  <div class="module-description">Genera reportes detallados de pagos, morosidad y estadísticas del
                    sistema.</div>
                  <div class="module-features">
                    <span class="feature-tag">Pagos</span>
                    <span class="feature-tag">Morosidad</span>
                    <span class="feature-tag">Estadísticas</span>
                    <span class="feature-tag">Gráficos</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="reportes.php" class="btn-module">
                    <i class="fa-solid fa-arrow-right"></i> Acceder a Reportes
                  </a>
                </div>
              </div>

              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-file-invoice-dollar"></i>
                  </div>
                  <span class="module-badge">Financiero</span>
                </div>
                <div class="module-content">
                  <h3>Estado de Cuentas</h3>
                  <div class="module-description">Consulta facturas pendientes y reportes de morosidad por cliente.
                  </div>
                  <div class="module-features">
                    <span class="feature-tag">Pendientes</span>
                    <span class="feature-tag">Vencidos</span>
                    <span class="feature-tag">Contacto</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="reportes.php?tipo_reporte=pendientes" class="btn-module">
                    <i class="fa-solid fa-search"></i> Ver Pendientes
                  </a>
                </div>
              </div>
            </div>
          </section>

          <!-- INCIDENCIAS -->
          <section class="view" id="incidencias">
            <h2>Incidencias</h2>
            <p>Gestiona reportes de problemas técnicos y su seguimiento.</p>
            <div class="grid">
              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                  </div>
                  <span class="module-badge">Gestión</span>
                </div>
                <div class="module-content">
                  <h3>Gestionar Incidencias</h3>
                  <div class="module-description">Administra todas las incidencias reportadas en el sistema.</div>
                  <div class="module-features">
                    <span class="feature-tag">Seguimiento</span>
                    <span class="feature-tag">Asignación</span>
                    <span class="feature-tag">Estados</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="incidencias.php" class="btn-module">
                    <i class="fa-solid fa-arrow-right"></i> Acceder
                  </a>
                </div>
              </div>
            </div>
          </section>

          <?php if ($rol === 'admin') { ?>
            <!-- TÉCNICOS -->
            <section class="view" id="tecnicos">
              <h2>Técnicos</h2>
              <p>Administra el equipo de técnicos y sus asignaciones.</p>
              <div class="grid">
                <div class="card-module">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-screwdriver-wrench"></i>
                    </div>
                    <span class="module-badge">Personal</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Técnicos</h3>
                    <div class="module-description">Registra y administra el personal técnico disponible.</div>
                    <div class="module-features">
                      <span class="feature-tag">Registro</span>
                      <span class="feature-tag">Especialidades</span>
                      <span class="feature-tag">Disponibilidad</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="tecnicos.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder
                    </a>
                  </div>
                </div>
              </div>
            </section>

            <!-- USUARIOS -->
            <section class="view" id="usuarios">
              <h2>Usuarios</h2>
              <p>Gestiona usuarios administrativos y permisos del sistema.</p>
              <div class="grid">
                <div class="card-module">
                  <div class="module-header">
                    <div class="module-icon">
                      <i class="fa-solid fa-id-badge"></i>
                    </div>
                    <span class="module-badge">Administración</span>
                  </div>
                  <div class="module-content">
                    <h3>Gestionar Usuarios</h3>
                    <div class="module-description">Administra usuarios, roles y permisos del sistema.</div>
                    <div class="module-features">
                      <span class="feature-tag">Roles</span>
                      <span class="feature-tag">Permisos</span>
                      <span class="feature-tag">Seguridad</span>
                    </div>
                  </div>
                  <div class="module-actions">
                    <a href="usuarios.php" class="btn-module">
                      <i class="fa-solid fa-arrow-right"></i> Acceder
                    </a>
                  </div>
                </div>
              </div>
            </section>
          <?php } ?>

          <!-- CUENTA -->
          <section class="view" id="cuenta">
            <h2>Mi Cuenta</h2>
            <p>Gestiona tu información personal, seguridad y preferencias.</p>
            <div class="grid">
              <div class="card-module">
                <div class="module-header">
                  <div class="module-icon">
                    <i class="fa-solid fa-user"></i>
                  </div>
                  <span class="module-badge">Personal</span>
                </div>
                <div class="module-content">
                  <h3>Mi Perfil</h3>
                  <div class="module-description">Actualiza tu información personal y configuración de cuenta.</div>
                  <div class="module-features">
                    <span class="feature-tag">Perfil</span>
                    <span class="feature-tag">Seguridad</span>
                    <span class="feature-tag">Preferencias</span>
                  </div>
                </div>
                <div class="module-actions">
                  <a href="cuenta.php" class="btn-module">
                    <i class="fa-solid fa-arrow-right"></i> Acceder
                  </a>
                </div>
              </div>
            </div>
          </section>
        </div>
      </div>
    </section>
  </div>

  <script>
    // --- Navegación dinámica (botones + hash) ---
    const slider = document.getElementById('slider');
    const buttons = document.querySelectorAll('.navbtn');
    const views = Array.from(buttons).map(b => b.dataset.target);

    function hasView(id) { return !!document.getElementById(id); }
    function goTo(id) {
      const i = views.indexOf(id);
      if (i < 0) return;
      if (!hasView(id)) {
        const firstExisting = views.find(hasView) || 'dashboard';
        return goTo(firstExisting);
      }
      slider.style.transform = `translateX(-${i * 100}%)`;
      buttons.forEach(b => b.classList.toggle('active', b.dataset.target === id));
      history.replaceState(null, '', `#${id}`);
    }
    buttons.forEach(b => b.addEventListener('click', () => goTo(b.dataset.target)));
    window.addEventListener('load', () => {
      const target = location.hash.replace('#', '') || views[0] || 'dashboard';
      goTo(target);
    });

    // --- Mejora UX: Navegación con teclado (← →) ---
    window.addEventListener('keydown', (e) => {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return;
      const current = location.hash.replace('#', '') || views[0];
      const idx = views.indexOf(current);
      if (idx < 0) return;
      const next = e.key === 'ArrowRight' ? Math.min(idx + 1, views.length - 1) : Math.max(idx - 1, 0);
      goTo(views[next]);
    });

    // --- Reloj ---
    const clockTop = document.getElementById('clockTop'), dateTop = document.getElementById('dateTop');
    function tick() {
      const now = new Date();
      clockTop.textContent = now.toLocaleTimeString('es-MX', { hour12: true });
      dateTop.textContent = now.toLocaleDateString('es-MX', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' });
    }
    tick(); setInterval(tick, 1000);

    // --- FUNCIONES DE NOTIFICACIONES - VERSIÓN 100% FUNCIONAL ---
    function toggleNotifications(event) {
      if (event) event.stopPropagation();

      const panel = document.getElementById('notificationPanel');
      const btn = document.querySelector('.notification-btn');

      if (panel.style.display === 'block') {
        // Cerrar panel
        panel.style.display = 'none';
        btn.classList.remove('active');
        document.removeEventListener('click', closeNotificationPanel);
      } else {
        // Abrir panel
        panel.style.display = 'block';
        btn.classList.add('active');

        // Agregar event listener para cerrar al hacer clic fuera
        setTimeout(() => {
          document.addEventListener('click', closeNotificationPanel);
        }, 10);
      }
    }

    function closeNotificationPanel(event) {
      const panel = document.getElementById('notificationPanel');
      const btn = document.querySelector('.notification-btn');

      // Si el clic fue fuera del panel y fuera del botón
      if (panel && event.target !== btn && !btn.contains(event.target) && !panel.contains(event.target)) {
        panel.style.display = 'none';
        btn.classList.remove('active');
        document.removeEventListener('click', closeNotificationPanel);
      }
    }

    // Cerrar con ESC
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        const panel = document.getElementById('notificationPanel');
        const btn = document.querySelector('.notification-btn');
        if (panel && panel.style.display === 'block') {
          panel.style.display = 'none';
          btn.classList.remove('active');
          document.removeEventListener('click', closeNotificationPanel);
        }
      }
    });

    // --- Efectos de partículas en cards ---
    function createParticles() {
      const cards = document.querySelectorAll('.card-module');
      cards.forEach(card => {
        const particleCount = 8;
        for (let i = 0; i < particleCount; i++) {
          const particle = document.createElement('div');
          particle.className = 'particle';
          particle.style.width = Math.random() * 4 + 2 + 'px';
          particle.style.height = particle.style.width;
          particle.style.left = Math.random() * 100 + '%';
          particle.style.animationDelay = Math.random() * 6 + 's';
          particle.style.animationDuration = Math.random() * 3 + 4 + 's';
          card.appendChild(particle);
        }
      });
    }

    // Inicializar partículas cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', createParticles);
  </script>

  <!-- 🔔 Bienvenida -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <?php if (!empty($_SESSION['welcome_name'])): ?>
    <script>
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: '¡Bienvenid@, <?= htmlspecialchars($_SESSION['welcome_name']) ?>!',
        showConfirmButton: false,
        timer: 2200,
        timerProgressBar: true
      });
    </script>
    <?php unset($_SESSION['welcome_name']); endif; ?>
</body>

</html>