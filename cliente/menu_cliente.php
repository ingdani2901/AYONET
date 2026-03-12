<?php
// Este archivo inicia la sesión y controla la seguridad
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';

// === GUARDIÁN DE SEGURIDAD ===
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: ../login.php");
    exit;
}

// Variables de sesión - CORREGIDO
$id_usuario = (int) $_SESSION['id_usuario'];
$nombreCliente = htmlspecialchars($_SESSION['welcome_name'] ?? 'Cliente');

// Obtener el id_cliente desde la base de datos
try {
    $stmt = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_usuario = ? AND eliminado = FALSE");
    $stmt->execute([$id_usuario]);
    $cliente_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente_data) {
        throw new Exception("Cliente no encontrado en la base de datos");
    }

    $id_cliente = (int) $cliente_data['id_cliente'];

} catch (Exception $e) {
    // Si hay error, redirigir al login
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// === OBTENER DATOS DEL CLIENTE Y NOTIFICACIONES ===
$total_incidencias_pendientes = 0;
$total_facturas_pendientes = 0;
$total_contratos_activos = 0;

try {
    // Contar incidencias pendientes del cliente
    $stmt_incidencias = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM incidencias 
        WHERE id_cliente = ? 
        AND estado IN ('abierta', 'asignada', 'en_proceso')
    ");
    $stmt_incidencias->execute([$id_cliente]);
    $total_incidencias_pendientes = $stmt_incidencias->fetchColumn();

    // Contar facturas pendientes del cliente
    $stmt_facturas = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM facturas f
        INNER JOIN contratos c ON f.id_contrato = c.id_contrato
        WHERE c.id_cliente = ? 
        AND f.estado = 'pendiente'
    ");
    $stmt_facturas->execute([$id_cliente]);
    $total_facturas_pendientes = $stmt_facturas->fetchColumn();

    // Contar contratos activos del cliente
    $stmt_contratos = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM contratos 
        WHERE id_cliente = ? 
        AND estado = 'activo'
        AND eliminado = FALSE
    ");
    $stmt_contratos->execute([$id_cliente]);
    $total_contratos_activos = $stmt_contratos->fetchColumn();

} catch (PDOException $e) {
    // Si hay error, mantener los contadores en 0
    $total_incidencias_pendientes = 0;
    $total_facturas_pendientes = 0;
    $total_contratos_activos = 0;
}

$total_notificaciones = $total_incidencias_pendientes + $total_facturas_pendientes;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AYONET - Panel Cliente</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --neon1: #00d4ff;
            --neon2: #6a00ff;
            --neon3: #ff007a;
            --muted: #cfe1ff;
            --glass: rgba(255, 255, 255, .07);
            --success: #00ff88;
            --warning: #ffaa00;
            --danger: #ff4757;
            --info: #3b82f6;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Poppins', sans-serif;
            color: #fff;
            background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border-radius: 16px;
            border: 1px solid rgba(255, 255, 255, .14);
            backdrop-filter: blur(12px);
            position: relative;
            z-index: 10;
        }

        .welcome h1 {
            font-size: 1.8rem;
            margin-bottom: 5px;
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .welcome p {
            color: var(--muted);
            font-size: 1rem;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
            position: relative;
        }

        .pill {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
            font-weight: 800;
            padding: 8px 16px;
            border-radius: 999px;
            font-size: 0.9rem;
        }

        .notifications {
            position: relative;
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
            z-index: 12;
        }

        .notification-btn {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            color: var(--muted);
            padding: 10px 15px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            z-index: 11;
        }

        .notification-btn:hover {
            background: rgba(255, 255, 255, .12);
            border-color: var(--neon1);
        }

        .notification-panel {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            width: 400px;
            background: rgba(15, 20, 45, 0.98);
            border: 1px solid rgba(255, 255, 255, .3);
            border-radius: 12px;
            padding: 20px;
            margin-top: 10px;
            z-index: 1000;
            backdrop-filter: blur(20px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }

        .notification-panel.show {
            display: block;
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

        .notification-item.info {
            border-left-color: var(--info);
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

        .menu-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
            position: relative;
            z-index: 1;
        }

        .menu-card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 16px;
            padding: 30px;
            text-decoration: none;
            color: #fff;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            position: relative;
            backdrop-filter: blur(10px);
            z-index: 1;
            min-height: 250px;
            justify-content: center;
        }

        .menu-card:hover {
            transform: translateY(-5px);
            border-color: var(--neon1);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.2);
        }

        .menu-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 20px;
            background: linear-gradient(135deg, var(--neon1), var(--neon3));
            color: white;
            box-shadow: 0 5px 15px rgba(0, 212, 255, 0.3);
        }

        .menu-card h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
            background: linear-gradient(90deg, #fff, var(--muted));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .menu-card p {
            color: var(--muted);
            font-size: 0.9rem;
            line-height: 1.4;
            margin-bottom: 15px;
        }

        .badge {
            background: var(--danger);
            color: white;
            border-radius: 12px;
            padding: 6px 12px;
            font-size: 0.8rem;
            font-weight: bold;
            margin-top: 8px;
        }

        .badge.warning {
            background: var(--warning);
        }

        .badge.success {
            background: var(--success);
        }

        .badge.info {
            background: var(--info);
        }

        .logout-btn {
            background: rgba(255, 255, 255, .08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .16);
            padding: 10px 20px;
            border-radius: 10px;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .logout-btn:hover {
            background: rgba(255, 0, 122, 0.2);
            border-color: var(--neon3);
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .stat-card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            backdrop-filter: blur(10px);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            border-color: var(--neon1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 5px;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.9rem;
        }

        /* Fondo con efecto burbuja */
        .bg-bubbles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            overflow: hidden;
        }

        .bubble {
            position: absolute;
            border-radius: 50%;
            background: radial-gradient(circle, var(--neon1) 0%, transparent 70%);
            opacity: 0.1;
            animation: float 15s infinite ease-in-out;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0) translateX(0);
            }

            25% {
                transform: translateY(-20px) translateX(10px);
            }

            50% {
                transform: translateY(-40px) translateX(-10px);
            }

            75% {
                transform: translateY(-20px) translateX(-5px);
            }
        }

        .menu-icon-colored {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin-bottom: 20px;
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .icon-pagos {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .icon-incidencias {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .icon-historial {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
        }

        .icon-contratos {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
        }

        .icon-asistente {
            background: linear-gradient(135deg, #ec4899, #be185d);
        }
    </style>
</head>

<body>
    <!-- Fondo animado -->
    <div class="bg-bubbles">
        <div class="bubble" style="width: 100px; height: 100px; top: 10%; left: 10%; animation-delay: 0s;"></div>
        <div class="bubble" style="width: 150px; height: 150px; top: 60%; left: 80%; animation-delay: -5s;"></div>
        <div class="bubble" style="width: 80px; height: 80px; top: 80%; left: 20%; animation-delay: -10s;"></div>
    </div>

    <div class="container">
        <div class="header">
            <div class="welcome">
                <h1>Panel Cliente - AYONET</h1>
                <p>Bienvenido, <?php echo $nombreCliente; ?></p>
            </div>
            <div class="user-info">
                <span class="pill">CLIENTE</span>
                <div class="notifications">
                    <button class="notification-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($total_notificaciones > 0): ?>
                            <span class="notification-badge"><?php echo $total_notificaciones; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-panel" id="notificationPanel">
                        <h4 style="margin-bottom: 15px; color: var(--neon1); text-align: center;">Mis Alertas</h4>
                        <?php if ($total_incidencias_pendientes > 0 || $total_facturas_pendientes > 0): ?>
                            <?php if ($total_incidencias_pendientes > 0): ?>
                                <div class="notification-item warning">
                                    <div class="notification-title">
                                        <span>Incidencias Pendientes</span>
                                        <span class="badge warning"><?php echo $total_incidencias_pendientes; ?></span>
                                    </div>
                                    <div class="notification-desc">
                                        Tienes incidencias que requieren tu atención. Revisa el historial para más detalles.
                                    </div>
                                    <div class="notification-meta">
                                        <span><i class="fas fa-clock"></i> Revisión pendiente</span>
                                        <a href="historial_incidencias.php"
                                            style="color: var(--warning); text-decoration: none;">
                                            <i class="fas fa-external-link-alt"></i> Ver
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($total_facturas_pendientes > 0): ?>
                                <div class="notification-item danger">
                                    <div class="notification-title">
                                        <span>Facturas Pendientes</span>
                                        <span class="badge"><?php echo $total_facturas_pendientes; ?></span>
                                    </div>
                                    <div class="notification-desc">
                                        Tienes facturas por pagar. Consulta la sección de pagos para regularizar tu situación.
                                    </div>
                                    <div class="notification-meta">
                                        <span><i class="fas fa-exclamation-triangle"></i> Pago pendiente</span>
                                        <a href="mis_pagos.php" style="color: var(--danger); text-decoration: none;">
                                            <i class="fas fa-external-link-alt"></i> Pagar
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($total_contratos_activos > 0): ?>
                                <div class="notification-item info">
                                    <div class="notification-title">
                                        <span>Contratos Activos</span>
                                        <span class="badge info"><?php echo $total_contratos_activos; ?></span>
                                    </div>
                                    <div class="notification-desc">
                                        Tienes <?php echo $total_contratos_activos; ?> contrato(s) activo(s). Consulta los
                                        detalles.
                                    </div>
                                    <div class="notification-meta">
                                        <span><i class="fas fa-check-circle"></i> Servicios activos</span>
                                        <a href="mis_contratos.php" style="color: var(--info); text-decoration: none;">
                                            <i class="fas fa-external-link-alt"></i> Ver contratos
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="no-notifications">
                                <i class="fas fa-check-circle"></i>
                                <p>¡Todo en orden!<br>No tienes alertas pendientes</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <a href="../logout.php" class="logout-btn">
                    <i class="fas fa-sign-out-alt"></i>
                    Cerrar Sesión
                </a>
            </div>
        </div>

        <!-- Estadísticas rápidas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_incidencias_pendientes; ?></div>
                <div class="stat-label">Incidencias Pendientes</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_facturas_pendientes; ?></div>
                <div class="stat-label">Facturas por Pagar</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $total_contratos_activos; ?></div>
                <div class="stat-label">Contratos Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">24/7</div>
                <div class="stat-label">Soporte Disponible</div>
            </div>
        </div>

        <div class="menu-grid">
            <!-- Mis Pagos y Facturas -->
            <a href="mis_pagos.php" class="menu-card">
                <div class="menu-icon-colored icon-pagos">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
                <h3>Mis Pagos y Facturas</h3>
                <p>Gestiona tus pagos y consulta facturas</p>
                <?php if ($total_facturas_pendientes > 0): ?>
                    <span class="badge warning"><?php echo $total_facturas_pendientes; ?> pendiente(s)</span>
                <?php endif; ?>
            </a>

            <!-- Reportar Incidencia -->
            <a href="asistente_incidencias.php" class="menu-card">
                <div class="menu-icon-colored icon-asistente">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <h3>Reportar Incidencia</h3>
                <p>Asistente inteligente para reportar problemas</p>
                <?php if ($total_incidencias_pendientes > 0): ?>
                    <span class="badge" style="margin-top: 5px;">
                        <i class="fas fa-exclamation-triangle"></i> Tienes incidencias pendientes
                    </span>
                <?php endif; ?>
            </a>

            <!-- Historial de Incidencias -->
            <a href="historial_incidencias.php" class="menu-card">
                <div class="menu-icon-colored icon-historial">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <h3>Historial de Incidencias</h3>
                <p>Consulta el estado de tus reportes</p>
                <?php if ($total_incidencias_pendientes > 0): ?>
                    <span class="badge"><?php echo $total_incidencias_pendientes; ?> activa(s)</span>
                <?php else: ?>
                    <span class="badge success" style="margin-top: 5px;">
                        <i class="fas fa-check"></i> Sin incidencias
                    </span>
                <?php endif; ?>
            </a>

            <!-- Mis Contratos (NUEVO) -->
            <a href="mis_contratos.php" class="menu-card">
                <div class="menu-icon-colored icon-contratos">
                    <i class="fa-solid fa-file-contract"></i>
                </div>
                <h3>Mis Contratos</h3>
                <p>Consulta tus servicios contratados y estados</p>
                <?php if ($total_contratos_activos > 0): ?>
                    <span class="badge info"><?php echo $total_contratos_activos; ?> activo(s)</span>
                <?php else: ?>
                    <span class="badge" style="margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> Sin contratos activos
                    </span>
                <?php endif; ?>
            </a>

            <!-- Perfil (Opcional - puedes agregarlo después) -->
            <!--
            <a href="mi_perfil.php" class="menu-card">
                <div class="menu-icon-colored" style="background: linear-gradient(135deg, #6b7280, #4b5563);">
                    <i class="fa-solid fa-user-gear"></i>
                </div>
                <h3>Mi Perfil</h3>
                <p>Administra tu información personal</p>
            </a>
            -->

            <!-- Soporte (Opcional) -->
            <!--
            <a href="soporte.php" class="menu-card">
                <div class="menu-icon-colored" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                    <i class="fa-solid fa-headset"></i>
                </div>
                <h3>Soporte Técnico</h3>
                <p>Contacta con nuestro equipo de soporte</p>
            </a>
            -->
        </div>

        <script>
            function toggleNotifications() {
                const panel = document.getElementById('notificationPanel');
                panel.classList.toggle('show');
            }

            // Cerrar panel al hacer clic fuera
            document.addEventListener('click', function (event) {
                const panel = document.getElementById('notificationPanel');
                const btn = document.querySelector('.notification-btn');

                if (panel && btn && !panel.contains(event.target) && !btn.contains(event.target)) {
                    panel.classList.remove('show');
                }
            });

            // Cerrar panel con ESC
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    const panel = document.getElementById('notificationPanel');
                    if (panel) {
                        panel.classList.remove('show');
                    }
                }
            });

            <?php if ($total_notificaciones > 0): ?>
                // Mostrar notificación de bienvenida si hay alertas
                document.addEventListener('DOMContentLoaded', function () {
                    Swal.fire({
                        icon: 'info',
                        title: 'Tienes <?php echo $total_notificaciones; ?> alerta(s)',
                        html: `<?php if ($total_facturas_pendientes > 0): ?>• <strong>Facturas pendientes:</strong> <?php echo $total_facturas_pendientes; ?><br><?php endif; ?>
                               <?php if ($total_incidencias_pendientes > 0): ?>• <strong>Incidencias activas:</strong> <?php echo $total_incidencias_pendientes; ?><br><?php endif; ?>
                               <?php if ($total_contratos_activos > 0): ?>• <strong>Contratos activos:</strong> <?php echo $total_contratos_activos; ?><?php endif; ?>`,
                        timer: 6000,
                        showConfirmButton: true,
                        background: '#0c1133',
                        color: '#fff',
                        confirmButtonColor: '#00d4ff',
                        confirmButtonText: 'Ver notificaciones',
                        showCancelButton: true,
                        cancelButtonText: 'Cerrar'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            toggleNotifications();
                        }
                    });
                });
            <?php else: ?>
                // Mensaje de bienvenida sin alertas
                document.addEventListener('DOMContentLoaded', function () {
                    Swal.fire({
                        icon: 'success',
                        title: '¡Bienvenido <?php echo $nombreCliente; ?>!',
                        text: 'No tienes alertas pendientes. Todo está en orden.',
                        timer: 3000,
                        showConfirmButton: false,
                        background: '#0c1133',
                        color: '#fff'
                    });
                });
            <?php endif; ?>

            // Agregar efecto de animación a las tarjetas al cargar
            document.addEventListener('DOMContentLoaded', function () {
                const cards = document.querySelectorAll('.menu-card');
                cards.forEach((card, index) => {
                    card.style.opacity = '0';
                    card.style.transform = 'translateY(20px)';

                    setTimeout(() => {
                        card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                        card.style.opacity = '1';
                        card.style.transform = 'translateY(0)';
                    }, index * 100);
                });
            });
        </script>
</body>

</html>