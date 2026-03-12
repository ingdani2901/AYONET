<?php
session_start();
require_once __DIR__ . '/../db.php';

// === GUARDIÁN DE SEGURIDAD ===
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'tecnico') {
    header("Location: ../login.php");
    exit;
}

$nombreTecnico = htmlspecialchars($_SESSION['welcome_name'] ?? 'Técnico');
$id_tecnico = $_SESSION['id_tecnico'] ?? null;
$id_usuario = $_SESSION['id_usuario'] ?? null;

// === OBTENER NOTIFICACIONES PENDIENTES ===
$notificaciones = [];
$total_pendientes = 0;

try {
    // Consultar incidencias asignadas - VERSIÓN CORREGIDA
    $stmt = $pdo->prepare("
        SELECT 
            i.id_incidencia,
            i.titulo,
            i.descripcion,
            i.fecha_registro,
            c.nombre_completo as cliente_nombre,
            i.estado,
            i.prioridad
        FROM incidencias i
        JOIN clientes c ON i.id_cliente = c.id_cliente
        WHERE i.id_tecnico = ?
        AND i.estado IN ('asignada', 'en_proceso')
        ORDER BY 
            CASE i.prioridad 
                WHEN '3' THEN 1
                WHEN '2' THEN 2
                ELSE 3
            END,
            i.fecha_registro DESC
        LIMIT 10
    ");
    $stmt->execute([$id_tecnico]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total_pendientes = count($notificaciones);

} catch (PDOException $e) {
    error_log("Error obteniendo incidencias del técnico: " . $e->getMessage());
    $notificaciones = [];
    $total_pendientes = 0;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AYONET - Panel Técnico</title>
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
        }

        .notification-btn {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            color: var(--muted);
            padding: 10px 15px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
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
            background: rgba(10, 15, 40, 0.95);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 12px;
            padding: 15px;
            margin-top: 10px;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }

        .notification-item {
            padding: 12px;
            margin-bottom: 10px;
            background: rgba(255, 255, 255, .05);
            border-radius: 8px;
            border-left: 4px solid var(--neon1);
        }

        .notification-item.urgent {
            border-left-color: var(--danger);
        }

        .notification-item.warning {
            border-left-color: var(--warning);
        }

        .notification-title {
            font-weight: 600;
            margin-bottom: 5px;
            color: var(--neon1);
        }

        .notification-desc {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 5px;
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
        }

        .menu-card:hover {
            transform: translateY(-5px);
            border-color: var(--neon1);
            box-shadow: 0 10px 30px rgba(0, 212, 255, 0.2);
        }

        .menu-icon {
            font-size: 3rem;
            margin-bottom: 15px;
            background: linear-gradient(135deg, var(--neon1), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .menu-card h3 {
            font-size: 1.3rem;
            margin-bottom: 10px;
        }

        .menu-card p {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .badge {
            background: var(--danger);
            color: white;
            border-radius: 12px;
            padding: 2px 8px;
            font-size: 0.7rem;
            margin-left: 5px;
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
            padding: 20px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="welcome">
                <h1>Panel Técnico - AYONET</h1>
                <p>Bienvenido, <?php echo $nombreTecnico; ?></p>
            </div>
            <div class="user-info">
                <span class="pill">TÉCNICO</span>
                <div class="notifications">
                    <button class="notification-btn" onclick="toggleNotifications()">
                        <i class="fas fa-bell"></i>
                        <?php if ($total_pendientes > 0): ?>
                            <span class="notification-badge"><?php echo $total_pendientes; ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notification-panel" id="notificationPanel">
                        <h4 style="margin-bottom: 15px; color: var(--neon1);">Incidencias Asignadas</h4>
                        <?php if (count($notificaciones) > 0): ?>
                            <?php foreach ($notificaciones as $notif): ?>
                                <div class="notification-item <?php echo $notif['prioridad'] === '3' ? 'urgent' : ''; ?>">
                                    <div class="notification-title">
                                        <?php echo htmlspecialchars($notif['titulo']); ?>
                                        <?php if ($notif['prioridad'] === '3'): ?>
                                            <span class="badge">URGENTE</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-desc">
                                        Cliente: <?php echo htmlspecialchars($notif['cliente_nombre']); ?>
                                    </div>
                                    <div class="notification-desc">
                                        <?php echo htmlspecialchars(substr($notif['descripcion'], 0, 100)); ?>...
                                    </div>
                                    <div class="notification-meta">
                                        <span>Estado: <?php echo htmlspecialchars($notif['estado']); ?></span>
                                        <span><?php echo date('d/m/Y', strtotime($notif['fecha_registro'])); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-notifications">
                                <i class="fas fa-check-circle"
                                    style="font-size: 2rem; color: var(--success); margin-bottom: 10px;"></i>
                                <p>No tienes incidencias pendientes</p>
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

        <div class="menu-grid">
            <a href="mis_incidencias.php" class="menu-card">
                <div class="menu-icon">📋</div>
                <h3>Mis Incidencias</h3>
                <p>Gestionar y resolver incidencias asignadas</p>
                <?php if ($total_pendientes > 0): ?>
                    <span class="badge"><?php echo $total_pendientes; ?> pendientes</span>
                <?php endif; ?>
            </a>

            <a href="reportes.php" class="menu-card">
                <div class="menu-icon">📊</div>
                <h3>Reportes</h3>
                <p>Ver mis reportes de trabajo</p>
            </a>
        </div>
    </div>

    <script>
        function toggleNotifications() {
            const panel = document.getElementById('notificationPanel');
            panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        }

        // Cerrar panel al hacer clic fuera
        document.addEventListener('click', function (event) {
            const panel = document.getElementById('notificationPanel');
            const btn = document.querySelector('.notification-btn');

            if (panel && btn && !panel.contains(event.target) && !btn.contains(event.target)) {
                panel.style.display = 'none';
            }
        });

        // Auto-refresh cada 30 segundos para nuevas notificaciones
        setInterval(() => {
            location.reload();
        }, 30000);

        <?php if ($total_pendientes > 0): ?>
            // Mostrar notificación de bienvenida si hay incidencias pendientes
            document.addEventListener('DOMContentLoaded', function () {
                Swal.fire({
                    icon: 'info',
                    title: 'Tienes <?php echo $total_pendientes; ?> incidencia(s) pendiente(s)',
                    text: 'Revisa el panel de notificaciones para más detalles',
                    timer: 5000,
                    showConfirmButton: true,
                    background: '#0c1133',
                    color: '#fff'
                });
            });
        <?php endif; ?>
    </script>
</body>

</html>