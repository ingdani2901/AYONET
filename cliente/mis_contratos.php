<?php
/**
 * AYONET · Mis Contratos (Vista Cliente)
 * El cliente puede ver todos sus contratos pero NO modificarlos
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';

// Verificar que el usuario esté logueado como cliente
if (!isset($_SESSION['id_usuario']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: ../login.php");
    exit;
}

// Obtener el ID del cliente desde la sesión
$id_cliente = $_SESSION['id_cliente'];

/* ---------- Helpers ---------- */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function formatearFecha($fecha)
{
    if (!$fecha)
        return '—';
    return date('d/m/Y', strtotime($fecha));
}

function obtenerEstadoAmigable($estado)
{
    $estados = [
        'activo' => ['texto' => 'Activo y funcionando', 'icono' => 'fa-play-circle', 'color' => '#10b981'],
        'suspendido' => ['texto' => 'Temporalmente pausado', 'icono' => 'fa-pause-circle', 'color' => '#f59e0b'],
        'cancelado' => ['texto' => 'Finalizado', 'icono' => 'fa-stop-circle', 'color' => '#ef4444']
    ];
    return $estados[$estado] ?? ['texto' => 'Estado desconocido', 'icono' => 'fa-question-circle', 'color' => '#6b7280'];
}

/* ---------- Obtener datos del cliente ---------- */
$sql_cliente = "SELECT nombre_completo, email, telefono, codigo_cliente FROM clientes WHERE id_cliente = :id";
$stmt_cliente = $pdo->prepare($sql_cliente);
$stmt_cliente->execute([':id' => $id_cliente]);
$cliente = $stmt_cliente->fetch(PDO::FETCH_ASSOC);

if (!$cliente) {
    header("Location: menu_cliente.php");
    exit;
}

/* ---------- Obtener contratos del cliente ---------- */
$sql_contratos = "
SELECT 
    co.id_contrato,
    s.nombre_servicio,
    s.descripcion,
    s.precio_base,
    co.monto_mensual,
    co.fecha_inicio_contrato,
    co.fecha_fin_contrato,
    co.estado,
    co.observaciones,
    co.fecha_ultimo_pago,
    co.fecha_proximo_pago,
    co.eliminado
FROM contratos co
JOIN servicios s ON co.id_servicio = s.id_servicio
WHERE co.id_cliente = :id_cliente 
    AND co.eliminado IS NOT TRUE
ORDER BY 
    CASE co.estado 
        WHEN 'activo' THEN 1
        WHEN 'suspendido' THEN 2
        WHEN 'cancelado' THEN 3
        ELSE 4
    END,
    co.fecha_inicio_contrato DESC
";

$stmt_contratos = $pdo->prepare($sql_contratos);
$stmt_contratos->execute([':id_cliente' => $id_cliente]);
$contratos = $stmt_contratos->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Estadísticas ---------- */
$sql_estadisticas = "
SELECT 
    estado,
    COUNT(*) as cantidad,
    SUM(monto_mensual) as total_mensual
FROM contratos 
WHERE id_cliente = :id_cliente 
    AND eliminado IS NOT TRUE
GROUP BY estado
";
$stmt_estadisticas = $pdo->prepare($sql_estadisticas);
$stmt_estadisticas->execute([':id_cliente' => $id_cliente]);
$estadisticas_raw = $stmt_estadisticas->fetchAll(PDO::FETCH_ASSOC);

// Preparar estadísticas
$estadisticas = [
    'activo' => ['cantidad' => 0, 'total' => 0],
    'suspendido' => ['cantidad' => 0, 'total' => 0],
    'cancelado' => ['cantidad' => 0, 'total' => 0],
    'total' => ['cantidad' => 0, 'total' => 0]
];

foreach ($estadisticas_raw as $stat) {
    if (isset($estadisticas[$stat['estado']])) {
        $estadisticas[$stat['estado']] = [
            'cantidad' => (int) $stat['cantidad'],
            'total' => (float) $stat['total_mensual']
        ];
    }
}

// Calcular totales
$estadisticas['total']['cantidad'] = count($contratos);
$estadisticas['total']['total'] = array_sum(array_column($estadisticas, 'total'));

$nombreUser = $cliente['nombre_completo'];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Mis Servicios Contratados</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --neon1: #00d4ff;
            --neon2: #6a00ff;
            --neon3: #ff007a;
            --muted: #cfe1ff;
            --glass: rgba(255, 255, 255, .07);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #3b82f6;
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
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }

        .ghost {
            background: rgba(255, 255, 255, .08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .16)
        }

        .ghost:hover {
            background: rgba(255, 255, 255, .12);
            transform: translateY(-1px);
        }

        .primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
            font-weight: 700;
        }

        .primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 212, 255, 0.3);
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 16px;
            backdrop-filter: blur(12px);
            padding: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, .45);
        }

        .card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 14px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .card h3 {
            margin: 0 0 15px;
            font-size: 1.3rem;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .welcome-section {
            background: linear-gradient(135deg, rgba(0, 212, 255, 0.1), rgba(106, 0, 255, 0.1));
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            border: 1px solid rgba(0, 212, 255, 0.2);
            position: relative;
            overflow: hidden;
        }

        .welcome-section::before {
            content: "";
            position: absolute;
            top: 0;
            right: 0;
            width: 100px;
            height: 100px;
            background: radial-gradient(circle, rgba(0, 212, 255, 0.3) 0%, transparent 70%);
            border-radius: 50%;
            opacity: 0.3;
        }

        .welcome-title {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
            background: linear-gradient(90deg, var(--neon1), #fff);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome-subtitle {
            color: var(--muted);
            font-size: 1rem;
            margin-bottom: 15px;
        }

        .user-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.08);
            padding: 8px 16px;
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            font-size: 0.9rem;
        }

        .user-badge i {
            color: var(--neon1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin: 25px 0;
        }

        .stat-card {
            background: rgba(255, 255, 255, .05);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .1);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, .08);
            border-color: var(--neon1);
        }

        .stat-icon {
            font-size: 1.8rem;
            margin-bottom: 10px;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.85rem;
        }

        .servicios-container {
            margin-top: 20px;
        }

        .servicio-card {
            background: rgba(255, 255, 255, .04);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 14px;
            padding: 25px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .servicio-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, .06);
            border-color: var(--neon1);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.1);
        }

        .servicio-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        .servicio-info h4 {
            font-size: 1.4rem;
            margin: 0 0 8px 0;
            color: #fff;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .servicio-desc {
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.5;
        }

        .servicio-estado {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            white-space: nowrap;
        }

        .estado-activo {
            background: rgba(16, 185, 129, 0.15);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .estado-suspendido {
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .estado-cancelado {
            background: rgba(239, 68, 68, 0.15);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .servicio-detalles {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .detalle-item {
            background: rgba(255, 255, 255, .03);
            border-radius: 10px;
            padding: 15px;
            border: 1px solid rgba(255, 255, 255, .05);
        }

        .detalle-label {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detalle-valor {
            font-size: 1.1rem;
            font-weight: 600;
            color: #fff;
        }

        .detalle-monto {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--neon1);
        }

        .servicio-acciones {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn-imprimir {
            background: linear-gradient(90deg, #8b5cf6, #a855f7);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-imprimir:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(139, 92, 246, 0.3);
        }

        .btn-ayuda {
            background: rgba(255, 255, 255, .08);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 10px;
            padding: 10px 20px;
            font-size: 0.9rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-ayuda:hover {
            background: rgba(255, 255, 255, .12);
            color: #fff;
        }

        .sin-servicios {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
            background: rgba(255, 255, 255, .03);
            border-radius: 14px;
            border: 2px dashed rgba(255, 255, 255, .1);
            margin: 20px 0;
        }

        .sin-servicios i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: rgba(255, 255, 255, .2);
        }

        .sin-servicios h4 {
            font-size: 1.5rem;
            margin-bottom: 10px;
            color: #fff;
        }

        .sin-servicios p {
            font-size: 1rem;
            max-width: 500px;
            margin: 0 auto 25px;
            line-height: 1.6;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(6, 9, 21, 0.95);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .spinner {
            width: 60px;
            height: 60px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-top: 4px solid var(--neon1);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
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
            font-size: 1.2rem;
            font-weight: 500;
        }

        .info-box {
            background: rgba(255, 255, 255, .03);
            border-radius: 12px;
            padding: 20px;
            margin-top: 25px;
            border-left: 4px solid var(--neon1);
        }

        .info-box h5 {
            font-size: 1.1rem;
            margin: 0 0 10px 0;
            color: var(--neon1);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .info-box p {
            color: var(--muted);
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0 0 10px 0;
        }

        .info-list {
            list-style: none;
            padding: 0;
            margin: 15px 0 0 0;
        }

        .info-list li {
            color: var(--muted);
            font-size: 0.9rem;
            padding: 8px 0;
            padding-left: 25px;
            position: relative;
        }

        .info-list li:before {
            content: "✓";
            position: absolute;
            left: 0;
            color: var(--neon1);
            font-weight: bold;
        }

        .proximo-pago {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.1), rgba(5, 150, 105, 0.1));
            border: 1px solid rgba(16, 185, 129, 0.2);
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }

        .proximo-pago .detalle-label {
            color: #10b981;
        }

        .proximo-pago .detalle-valor {
            color: #10b981;
            font-weight: 700;
        }

        .observaciones-box {
            background: rgba(245, 158, 11, 0.05);
            border: 1px solid rgba(245, 158, 11, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            font-style: italic;
        }

        .observaciones-box .detalle-label {
            color: #f59e0b;
        }

        .badge-nuevo {
            background: linear-gradient(90deg, var(--neon3), #ff3b6e);
            color: white;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 700;
            margin-left: 10px;
            vertical-align: middle;
        }

        @media (max-width: 768px) {
            .servicio-header {
                flex-direction: column;
                gap: 15px;
            }

            .servicio-estado {
                align-self: flex-start;
            }

            .servicio-detalles {
                grid-template-columns: 1fr;
            }

            .servicio-acciones {
                flex-direction: column;
            }

            .btn-imprimir,
            .btn-ayuda {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>

<body class="bg">
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div class="loading-text">Cargando tus servicios...</div>
    </div>

    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700;letter-spacing:.3px">AYONET · Mis Servicios</div>
                    <small style="color:#cfe1ff">Hola, <?= h($cliente['codigo_cliente']) ?></small>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn ghost" href="menu_cliente.php">
                    <i class="fa-solid fa-arrow-left"></i> Volver al Menú
                </a>
                <span class="pill">TU PERFIL</span>
            </div>
        </header>

        <section class="panel">
            <!-- Bienvenida personal -->
            <div class="welcome-section">
                <h1 class="welcome-title">
                    <i class="fa-solid fa-hand-wave"></i> ¡Hola, <?= h($cliente['nombre_completo']) ?>!
                </h1>
                <p class="welcome-subtitle">
                    Aquí puedes ver todos los servicios que tienes contratados con nosotros.
                    Todo tu historial de conexiones en un solo lugar.
                </p>
                <div class="user-badge">
                    <i class="fa-solid fa-id-card"></i>
                    <span>Código de cliente: <strong><?= h($cliente['codigo_cliente']) ?></strong></span>
                </div>
            </div>

            <!-- Resumen de tu situación -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-wifi" style="color: var(--neon1);"></i>
                    </div>
                    <div class="stat-number"><?= $estadisticas['total']['cantidad'] ?></div>
                    <div class="stat-label">Servicios Contratados</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-bolt" style="color: var(--success);"></i>
                    </div>
                    <div class="stat-number"><?= $estadisticas['activo']['cantidad'] ?></div>
                    <div class="stat-label">Servicios Activos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-money-bill-wave" style="color: #8b5cf6;"></i>
                    </div>
                    <div class="stat-number">$<?= number_format($estadisticas['total']['total'], 2) ?></div>
                    <div class="stat-label">Total Mensual</div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fa-solid fa-headset" style="color: var(--warning);"></i>
                    </div>
                    <div class="stat-number">24/7</div>
                    <div class="stat-label">Soporte Disponible</div>
                </div>
            </div>

            <!-- Tus servicios contratados -->
            <div class="card">
                <h3>
                    <i class="fa-solid fa-list-check"></i>
                    Tus Servicios Contratados
                    <?php if (count($contratos) > 0): ?>
                        <span class="badge-nuevo"><?= count($contratos) ?> servicio(s)</span>
                    <?php endif; ?>
                </h3>

                <?php if (empty($contratos)): ?>
                    <div class="sin-servicios">
                        <i class="fa-solid fa-wifi-slash"></i>
                        <h4>¡Aún no tienes servicios!</h4>
                        <p>
                            Parece que aún no has contratado ningún servicio con nosotros.
                            Cuando lo hagas, aparecerán aquí todos los detalles de tu conexión.
                        </p>
                        <a href="../contacto.php" class="btn primary">
                            <i class="fa-solid fa-phone"></i> Contáctanos para más información
                        </a>
                    </div>
                <?php else: ?>
                    <div class="servicios-container">
                        <?php foreach ($contratos as $cont):
                            $estado_info = obtenerEstadoAmigable($cont['estado']);
                            ?>
                            <div class="servicio-card">
                                <div class="servicio-header">
                                    <div class="servicio-info">
                                        <h4>
                                            <i class="fa-solid fa-satellite-dish"></i>
                                            <?= h($cont['nombre_servicio']) ?>
                                        </h4>
                                        <p class="servicio-desc">
                                            <?= $cont['descripcion'] ? h($cont['descripcion']) : 'Servicio de conexión a internet' ?>
                                        </p>
                                    </div>
                                    <div class="servicio-estado estado-<?= $cont['estado'] ?>">
                                        <i class="fa-solid <?= $estado_info['icono'] ?>"></i>
                                        <span><?= $estado_info['texto'] ?></span>
                                    </div>
                                </div>

                                <div class="servicio-detalles">
                                    <div class="detalle-item">
                                        <div class="detalle-label">
                                            <i class="fa-solid fa-calendar-day"></i>
                                            Fecha de inicio
                                        </div>
                                        <div class="detalle-valor"><?= formatearFecha($cont['fecha_inicio_contrato']) ?></div>
                                    </div>

                                    <div class="detalle-item">
                                        <div class="detalle-label">
                                            <i class="fa-solid fa-calendar-check"></i>
                                            Fecha de finalización
                                        </div>
                                        <div class="detalle-valor">
                                            <?= $cont['fecha_fin_contrato'] ? formatearFecha($cont['fecha_fin_contrato']) : 'Sin fecha de fin' ?>
                                        </div>
                                    </div>

                                    <div class="detalle-item">
                                        <div class="detalle-label">
                                            <i class="fa-solid fa-money-bill"></i>
                                            Pago mensual
                                        </div>
                                        <div class="detalle-valor detalle-monto">
                                            $<?= number_format($cont['monto_mensual'], 2) ?>
                                        </div>
                                        <small style="color: var(--muted); font-size: 0.8rem;">
                                            Precio base: $<?= number_format($cont['precio_base'], 2) ?>
                                        </small>
                                    </div>

                                    <?php if ($cont['fecha_ultimo_pago']): ?>
                                        <div class="detalle-item">
                                            <div class="detalle-label">
                                                <i class="fa-solid fa-receipt"></i>
                                                Último pago
                                            </div>
                                            <div class="detalle-valor"><?= formatearFecha($cont['fecha_ultimo_pago']) ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <?php if ($cont['fecha_proximo_pago'] && $cont['estado'] === 'activo'): ?>
                                    <div class="proximo-pago">
                                        <div class="detalle-label">
                                            <i class="fa-solid fa-calendar-star"></i>
                                            Próximo pago programado
                                        </div>
                                        <div class="detalle-valor">
                                            <?= formatearFecha($cont['fecha_proximo_pago']) ?>
                                        </div>
                                        <small style="color: #10b981; font-size: 0.85rem;">
                                            <i class="fa-solid fa-lightbulb"></i>
                                            Te enviaremos un recordatorio antes de la fecha
                                        </small>
                                    </div>
                                <?php endif; ?>

                                <?php if ($cont['observaciones']): ?>
                                    <div class="observaciones-box">
                                        <div class="detalle-label">
                                            <i class="fa-solid fa-note-sticky"></i>
                                            Notas importantes sobre tu servicio
                                        </div>
                                        <div class="detalle-valor" style="font-size: 0.95rem; font-weight: normal;">
                                            <?= nl2br(h($cont['observaciones'])) ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="servicio-acciones">
                                    <button class="btn-imprimir" onclick="imprimirContrato(<?= $cont['id_contrato'] ?>)">
                                        <i class="fa-solid fa-print"></i>
                                        Descargar/Imprimir Contrato
                                    </button>
                                    <button class="btn-ayuda" onclick="solicitarAyuda(<?= $cont['id_contrato'] ?>)">
                                        <i class="fa-solid fa-question-circle"></i>
                                        ¿Necesitas ayuda con este servicio?
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Información útil -->
            <div class="info-box">
                <h5>
                    <i class="fa-solid fa-circle-info"></i>
                    ¿Necesitas ayuda con tus servicios?
                </h5>
                <p>
                    Tu satisfacción es nuestra prioridad. Si tienes alguna duda sobre tus servicios contratados,
                    aquí tienes algunas opciones rápidas:
                </p>
                <ul class="info-list">
                    <li><strong>Para reportar problemas técnicos:</strong> Ve a "Reportar Incidencia" en tu menú
                        principal</li>
                    <li><strong>Para consultas de facturación:</strong> Revisa la sección "Mis Pagos y Facturas"</li>
                    <li><strong>Para cambios en tu servicio:</strong> Contacta directamente a nuestro equipo de soporte
                    </li>
                    <li><strong>Emergencias 24/7:</strong> Llama al 01-800-AYONET (296638)</li>
                </ul>
                <p style="margin-top: 15px; font-size: 0.9rem; color: var(--neon1);">
                    <i class="fa-solid fa-shield-alt"></i>
                    <strong>Tu seguridad es importante:</strong> Nunca compartas tus datos de acceso con terceros.
                </p>
            </div>

            <!-- Estado actual simplificado -->
            <div class="card" style="background: rgba(255, 255, 255, 0.03);">
                <h3>
                    <i class="fa-solid fa-chart-line"></i>
                    Estado de tus servicios en resumen
                </h3>
                <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-top: 15px;">
                    <?php if ($estadisticas['activo']['cantidad'] > 0): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 12px; height: 12px; background: #10b981; border-radius: 50%;"></div>
                            <span style="color: var(--muted); font-size: 0.9rem;">
                                <strong><?= $estadisticas['activo']['cantidad'] ?></strong> servicio(s) activo(s)
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($estadisticas['suspendido']['cantidad'] > 0): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 12px; height: 12px; background: #f59e0b; border-radius: 50%;"></div>
                            <span style="color: var(--muted); font-size: 0.9rem;">
                                <strong><?= $estadisticas['suspendido']['cantidad'] ?></strong> servicio(s) pausado(s)
                            </span>
                        </div>
                    <?php endif; ?>

                    <?php if ($estadisticas['cancelado']['cantidad'] > 0): ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 12px; height: 12px; background: #ef4444; border-radius: 50%;"></div>
                            <span style="color: var(--muted); font-size: 0.9rem;">
                                <strong><?= $estadisticas['cancelado']['cantidad'] ?></strong> servicio(s) finalizado(s)
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>

    <script>
        // FUNCIÓN PARA MOSTRAR LOADING
        function mostrarLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }

        // FUNCIÓN PARA OCULTAR LOADING
        function ocultarLoading() {
            document.getElementById('loadingOverlay').style.display = 'none';
        }

        // FUNCIÓN PARA IMPRIMIR CONTRATO
        function imprimirContrato(id_contrato) {
            mostrarLoading();
            setTimeout(() => {
                // Usar el módulo de impresión que ya tienes en contratos/
                window.open('../contratos/imprimir_contrato.php?id=' + id_contrato, '_blank');
                // Ocultar loading después de un tiempo
                setTimeout(ocultarLoading, 1000);
            }, 500);
        }

        // FUNCIÓN PARA SOLICITAR AYUDA
        function solicitarAyuda(id_contrato) {
            Swal.fire({
                title: '¿En qué podemos ayudarte?',
                html: `
                    <div style="text-align: left; margin: 15px 0;">
                        <p style="color: #cfe1ff; margin-bottom: 15px;">Selecciona el tipo de ayuda que necesitas:</p>
                        
                        <div style="display: grid; gap: 10px;">
                            <button class="btn-ayuda-opcion" onclick="redirigirAIncidencias(${id_contrato}, 'tecnico')" 
                                    style="background: rgba(0, 212, 255, 0.1); border: 1px solid #00d4ff; padding: 12px; border-radius: 8px; color: #fff; cursor: pointer; text-align: left;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-tools" style="color: #00d4ff;"></i>
                                    <div>
                                        <strong>Problema técnico</strong><br>
                                        <small style="color: #cfe1ff;">Mi servicio no funciona correctamente</small>
                                    </div>
                                </div>
                            </button>
                            
                            <button class="btn-ayuda-opcion" onclick="redirigirAIncidencias(${id_contrato}, 'facturacion')" 
                                    style="background: rgba(139, 92, 246, 0.1); border: 1px solid #8b5cf6; padding: 12px; border-radius: 8px; color: #fff; cursor: pointer; text-align: left;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-money-bill" style="color: #8b5cf6;"></i>
                                    <div>
                                        <strong>Consulta de facturación</strong><br>
                                        <small style="color: #cfe1ff;">Duda sobre pagos o facturas</small>
                                    </div>
                                </div>
                            </button>
                            
                            <button class="btn-ayuda-opcion" onclick="redirigirAIncidencias(${id_contrato}, 'general')" 
                                    style="background: rgba(245, 158, 11, 0.1); border: 1px solid #f59e0b; padding: 12px; border-radius: 8px; color: #fff; cursor: pointer; text-align: left;">
                                <div style="display: flex; align-items: center; gap: 10px;">
                                    <i class="fa-solid fa-headset" style="color: #f59e0b;"></i>
                                    <div>
                                        <strong>Otra consulta</strong><br>
                                        <small style="color: #cfe1ff;">Información general o modificación</small>
                                    </div>
                                </div>
                            </button>
                        </div>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Contactar por teléfono',
                cancelButtonText: 'Cancelar',
                background: '#0c1133',
                color: '#fff',
                confirmButtonColor: '#00d4ff',
                cancelButtonColor: '#6b7280',
                width: '600px'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'tel:01800AYONET';
                }
            });
        }

        function redirigirAIncidencias(id_contrato, tipo) {
            // Redirigir al asistente de incidencias con parámetros
            window.location.href = `asistente_incidencias.php?contrato=${id_contrato}&tipo=${tipo}`;
        }

        // Mostrar loading al hacer clic en enlaces
        document.addEventListener('click', function (e) {
            if (e.target.closest('a.btn')) {
                mostrarLoading();
            }
        });

        // Ocultar loading cuando la página cargue completamente
        window.addEventListener('load', function () {
            setTimeout(ocultarLoading, 500);
        });

        // Si la página ya está cargada, ocultar loading inmediatamente
        document.addEventListener('DOMContentLoaded', function () {
            if (document.readyState === 'complete') {
                ocultarLoading();
            }
        });

        // Animación de entrada para las tarjetas
        document.addEventListener('DOMContentLoaded', function () {
            const cards = document.querySelectorAll('.servicio-card');
            cards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';

                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100 + 300);
            });
        });

        // Mostrar mensaje de bienvenida personalizado
        document.addEventListener('DOMContentLoaded', function () {
            setTimeout(() => {
                const totalServicios = <?= count($contratos) ?>;
                if (totalServicios > 0) {
                    const activos = <?= $estadisticas['activo']['cantidad'] ?>;

                    let mensaje = '';
                    if (activos === totalServicios) {
                        mensaje = '¡Excelente! Todos tus servicios están activos y funcionando correctamente.';
                    } else if (activos > 0) {
                        mensaje = `Tienes ${activos} servicio(s) activo(s). ¡Disfruta de tu conexión!`;
                    } else {
                        mensaje = 'Recuerda que puedes reactivar tus servicios en cualquier momento.';
                    }

                    Swal.fire({
                        icon: 'success',
                        title: 'Servicios cargados correctamente',
                        text: mensaje,
                        timer: 4000,
                        showConfirmButton: false,
                        background: '#0c1133',
                        color: '#fff',
                        toast: true,
                        position: 'top-end'
                    });
                }
            }, 1000);
        });
    </script>
</body>

</html>