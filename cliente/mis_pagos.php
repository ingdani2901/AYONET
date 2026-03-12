<?php
session_start();
require_once __DIR__ . '/../db.php';

// === SEGURIDAD ===
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: ../login.php");
    exit;
}

$id_usuario = (int) $_SESSION['id_usuario'];
$nombreCliente = htmlspecialchars($_SESSION['welcome_name'] ?? 'Cliente');

// Obtener id_cliente
try {
    $stmt = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_usuario = ? AND eliminado = FALSE");
    $stmt->execute([$id_usuario]);
    $cliente_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cliente_data) {
        throw new Exception("Cliente no encontrado");
    }
    $id_cliente = (int) $cliente_data['id_cliente'];

} catch (Exception $e) {
    session_destroy();
    header("Location: ../login.php");
    exit;
}

// === OBTENER DATOS DEL CLIENTE ===
$contrato_activo = null;
$facturas_pendientes = [];
$pagos_realizados = [];
$saldo_actual = 0;

try {
    // 1. Obtener contrato activo
    $stmt_contrato = $pdo->prepare("
        SELECT c.*, s.nombre_servicio, s.precio_base
        FROM contratos c
        JOIN servicios s ON c.id_servicio = s.id_servicio
        WHERE c.id_cliente = ? AND c.estado = 'activo'
        ORDER BY c.fecha_inicio_contrato DESC
        LIMIT 1
    ");
    $stmt_contrato->execute([$id_cliente]);
    $contrato_activo = $stmt_contrato->fetch(PDO::FETCH_ASSOC);

    // 2. Obtener facturas pendientes
    $stmt_facturas = $pdo->prepare("
        SELECT f.*, 
               CASE 
                 WHEN f.fecha_vencimiento < CURRENT_DATE AND f.estado = 'pendiente' THEN 'vencida'
                 ELSE f.estado
               END as estado_real
        FROM facturas f
        JOIN contratos c ON f.id_contrato = c.id_contrato
        WHERE c.id_cliente = ?
        ORDER BY f.fecha_emision DESC
    ");
    $stmt_facturas->execute([$id_cliente]);
    $facturas_pendientes = $stmt_facturas->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener pagos realizados
    $stmt_pagos = $pdo->prepare("
        SELECT p.*, f.id_factura, f.periodo_pagado
        FROM pagos p
        JOIN facturas f ON p.id_factura = f.id_factura
        JOIN contratos c ON f.id_contrato = c.id_contrato
        WHERE c.id_cliente = ?
        ORDER BY p.fecha_pago DESC
        LIMIT 10
    ");
    $stmt_pagos->execute([$id_cliente]);
    $pagos_realizados = $stmt_pagos->fetchAll(PDO::FETCH_ASSOC);

    // 4. Calcular saldo pendiente
    $stmt_saldo = $pdo->prepare("
        SELECT COALESCE(SUM(f.monto), 0) as total_pendiente
        FROM facturas f
        JOIN contratos c ON f.id_contrato = c.id_contrato
        WHERE c.id_cliente = ? 
        AND f.estado = 'pendiente'
    ");
    $stmt_saldo->execute([$id_cliente]);
    $saldo_actual = $stmt_saldo->fetchColumn();

} catch (PDOException $e) {
    // En caso de error, mantener arrays vacíos
    $facturas_pendientes = [];
    $pagos_realizados = [];
    $saldo_actual = 0;
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Pagos - AYONET</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
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

        .back-btn {
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

        .back-btn:hover {
            background: rgba(255, 255, 255, .12);
            border-color: var(--neon1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 16px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            border-color: var(--neon1);
        }

        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .stat-number.success {
            background: linear-gradient(90deg, var(--success), #00cc77);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-number.warning {
            background: linear-gradient(90deg, var(--warning), #ff9900);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-number.danger {
            background: linear-gradient(90deg, var(--danger), #ff3344);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .section {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .section h2 {
            margin-bottom: 20px;
            color: var(--neon1);
            font-size: 1.4rem;
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .1);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, .05);
        }

        th,
        td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
        }

        th {
            background: rgba(255, 255, 255, .08);
            color: var(--neon1);
            font-weight: 600;
        }

        tr:hover {
            background: rgba(255, 255, 255, .03);
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge.success {
            background: rgba(0, 255, 136, 0.2);
            color: var(--success);
            border: 1px solid var(--success);
        }

        .badge.warning {
            background: rgba(255, 170, 0, 0.2);
            color: var(--warning);
            border: 1px solid var(--warning);
        }

        .badge.danger {
            background: rgba(255, 71, 87, 0.2);
            color: var(--danger);
            border: 1px solid var(--danger);
        }

        .service-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }

        .info-item {
            background: rgba(255, 255, 255, .05);
            padding: 15px;
            border-radius: 10px;
            border-left: 4px solid var(--neon1);
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--neon1);
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            th,
            td {
                padding: 10px 8px;
                font-size: 0.9rem;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="welcome">
                <h1>Mis Pagos y Facturas</h1>
                <p>Gestiona tus pagos y consulta tu estado de cuenta</p>
            </div>
            <a href="menu_cliente.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Volver al Menú
            </a>
        </div>

        <!-- Estadísticas Rápidas -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number <?php echo $saldo_actual > 0 ? 'warning' : 'success'; ?>">
                    $<?php echo number_format($saldo_actual, 2); ?>
                </div>
                <div class="stat-label">Saldo Pendiente</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php echo count($facturas_pendientes); ?>
                </div>
                <div class="stat-label">Facturas Totales</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php
                    $vencidas = array_filter($facturas_pendientes, function ($f) {
                        return $f['estado_real'] === 'vencida';
                    });
                    echo count($vencidas);
                    ?>
                </div>
                <div class="stat-label">Facturas Vencidas</div>
            </div>
            <div class="stat-card">
                <div class="stat-number">
                    <?php echo count($pagos_realizados); ?>
                </div>
                <div class="stat-label">Pagos Realizados</div>
            </div>
        </div>

        <!-- Información del Servicio -->
        <?php if ($contrato_activo): ?>
            <div class="section">
                <h2><i class="fas fa-wifi"></i> Mi Servicio Activo</h2>
                <div class="service-info">
                    <div class="info-item">
                        <div class="info-label">Servicio</div>
                        <div class="info-value"><?php echo htmlspecialchars($contrato_activo['nombre_servicio']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Monto Mensual</div>
                        <div class="info-value">$<?php echo number_format($contrato_activo['monto_mensual'], 2); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Fecha Inicio</div>
                        <div class="info-value">
                            <?php echo date('d/m/Y', strtotime($contrato_activo['fecha_inicio_contrato'])); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Estado</div>
                        <div class="info-value">
                            <span class="badge success"><?php echo htmlspecialchars($contrato_activo['estado']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Facturas Pendientes -->
        <div class="section">
            <h2><i class="fas fa-file-invoice"></i> Mis Facturas</h2>
            <?php if (!empty($facturas_pendientes)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID Factura</th>
                                <th>Periodo</th>
                                <th>Monto</th>
                                <th>Emisión</th>
                                <th>Vencimiento</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($facturas_pendientes as $factura): ?>
                                <tr>
                                    <td>#<?php echo $factura['id_factura']; ?></td>
                                    <td><?php echo htmlspecialchars($factura['periodo_pagado'] ?? 'N/A'); ?></td>
                                    <td>$<?php echo number_format($factura['monto'], 2); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($factura['fecha_emision'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($factura['fecha_vencimiento'])); ?></td>
                                    <td>
                                        <?php
                                        $badge_class = 'success';
                                        $estado_text = $factura['estado_real'];

                                        if ($estado_text === 'vencida') {
                                            $badge_class = 'danger';
                                        } elseif ($estado_text === 'pendiente') {
                                            $badge_class = 'warning';
                                        }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo ucfirst($estado_text); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-check-circle"></i>
                    <h3>¡Todo al día!</h3>
                    <p>No tienes facturas pendientes</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Historial de Pagos -->
        <div class="section">
            <h2><i class="fas fa-history"></i> Historial de Pagos</h2>
            <?php if (!empty($pagos_realizados)): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>ID Pago</th>
                                <th>Factura</th>
                                <th>Periodo</th>
                                <th>Monto</th>
                                <th>Fecha Pago</th>
                                <th>Método</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pagos_realizados as $pago): ?>
                                <tr>
                                    <td>#<?php echo $pago['id_pago']; ?></td>
                                    <td>Factura #<?php echo $pago['id_factura']; ?></td>
                                    <td><?php echo htmlspecialchars($pago['periodo_pagado'] ?? 'N/A'); ?></td>
                                    <td>$<?php echo number_format($pago['monto_pagado'], 2); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($pago['fecha_pago'])); ?></td>
                                    <td><?php echo htmlspecialchars($pago['metodo_pago'] ?? 'No especificado'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-receipt"></i>
                    <h3>Sin pagos registrados</h3>
                    <p>No se han registrado pagos aún</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Aquí puedes agregar funcionalidades JavaScript si las necesitas
        document.addEventListener('DOMContentLoaded', function () {
            console.log('Página de pagos cargada correctamente');
        });
    </script>
</body>

</html>