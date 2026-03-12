<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$id_recibo = (int) ($_GET['id'] ?? 0);

if (!$id_recibo) {
    header("Location: recibos.php");
    exit;
}

// Obtener datos del recibo
$sql = "
SELECT 
    f.*,
    c.id_contrato,
    cl.id_cliente,
    cl.nombre_completo as cliente_nombre,
    cl.codigo_cliente,
    cl.email as cliente_email,
    cl.telefono as cliente_telefono,
    s.nombre_servicio,
    s.descripcion as servicio_descripcion,
    s.precio_base,
    d.calle,
    d.colonia,
    d.numero_exterior,
    d.numero_interior,
    d.referencia,
    COALESCE(SUM(p.monto_pagado), 0) as total_pagado,
    COUNT(p.id_pago) as total_pagos
FROM facturas f
JOIN contratos c ON f.id_contrato = c.id_contrato
JOIN clientes cl ON c.id_cliente = cl.id_cliente
JOIN servicios s ON c.id_servicio = s.id_servicio
LEFT JOIN direcciones d ON cl.id_cliente = d.id_cliente AND d.es_principal = true
LEFT JOIN pagos p ON f.id_factura = p.id_factura
WHERE f.id_factura = ?
GROUP BY 
    f.id_factura, c.id_contrato, cl.id_cliente, cl.nombre_completo, cl.codigo_cliente, 
    cl.email, cl.telefono, s.nombre_servicio, s.descripcion, s.precio_base,
    d.calle, d.colonia, d.numero_exterior, d.numero_interior, d.referencia
";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_recibo]);
    $recibo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$recibo) {
        header("Location: recibos.php");
        exit;
    }
} catch (Exception $e) {
    die("Error al cargar el recibo: " . $e->getMessage());
}

// Obtener historial de pagos
$sql_pagos = "
SELECT 
    p.*,
    u.nombre_completo as registrado_por
FROM pagos p
LEFT JOIN usuarios u ON p.id_usuario_registro = u.id_usuario
WHERE p.id_factura = ?
ORDER BY p.fecha_pago DESC
";

try {
    $stmt = $pdo->prepare($sql_pagos);
    $stmt->execute([$id_recibo]);
    $pagos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $pagos = [];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AYONET · Detalle Recibo #<?= $recibo['id_factura'] ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --neon1: #00d4ff;
            --neon2: #6a00ff;
            --neon3: #ff007a;
            --muted: #cfe1ff;
        }

        body {
            margin: 0;
            font-family: "Poppins", sans-serif;
            background: #0f172a;
            color: #fff;
            padding: 20px;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e293b, #334155);
            border-radius: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--neon1), var(--neon3));
            color: white;
        }

        .btn-secondary {
            background: #475569;
            color: white;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
        }

        .info-card {
            background: #1e293b;
            padding: 20px;
            border-radius: 10px;
            border-left: 4px solid var(--neon1);
        }

        .info-card h3 {
            margin-top: 0;
            color: var(--neon1);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            padding-bottom: 8px;
            border-bottom: 1px solid #334155;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }

        .badge-success {
            background: #dcfce7;
            color: #166534;
        }

        .badge-warning {
            background: #fef9c3;
            color: #854d0e;
        }

        .badge-danger {
            background: #fef2f2;
            color: #dc2626;
        }

        .badge-secondary {
            background: #f3f4f6;
            color: #374151;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            background: #1e293b;
            border-radius: 10px;
            overflow: hidden;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #334155;
        }

        .table th {
            background: #334155;
            color: var(--neon1);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div>
                <h1>Recibo #<?= $recibo['id_factura'] ?></h1>
                <p>Detalle completo del recibo</p>
            </div>
            <div>
                <a href="recibos.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Volver
                </a>
                <a href="imprimir_recibo.php?id=<?= $recibo['id_factura'] ?>" class="btn btn-primary" target="_blank">
                    <i class="fas fa-print"></i> Imprimir PDF
                </a>
            </div>
        </div>

        <div class="info-grid">
            <!-- Información del Cliente -->
            <div class="info-card">
                <h3><i class="fas fa-user"></i> Información del Cliente</h3>
                <div class="info-row">
                    <span>Cliente:</span>
                    <span><strong><?= htmlspecialchars($recibo['cliente_nombre'] ?? '') ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Código:</span>
                    <span><?= htmlspecialchars($recibo['codigo_cliente'] ?? '') ?></span>
                </div>
                <div class="info-row">
                    <span>Email:</span>
                    <span><?= htmlspecialchars($recibo['cliente_email'] ?? 'No disponible') ?></span>
                </div>
                <div class="info-row">
                    <span>Teléfono:</span>
                    <span><?= htmlspecialchars($recibo['cliente_telefono'] ?? 'No disponible') ?></span>
                </div>
                <div class="info-row">
                    <span>Dirección:</span>
                    <span>
                        <?php
                        $direccion = [];
                        if (!empty($recibo['calle']))
                            $direccion[] = $recibo['calle'];
                        if (!empty($recibo['colonia']))
                            $direccion[] = $recibo['colonia'];
                        if (!empty($recibo['numero_exterior']))
                            $direccion[] = '#' . $recibo['numero_exterior'];
                        echo $direccion ? htmlspecialchars(implode(', ', $direccion)) : 'No disponible';
                        ?>
                    </span>
                </div>
            </div>

            <!-- Información del Servicio -->
            <div class="info-card">
                <h3><i class="fas fa-wifi"></i> Información del Servicio</h3>
                <div class="info-row">
                    <span>Servicio:</span>
                    <span><strong><?= htmlspecialchars($recibo['nombre_servicio'] ?? '') ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Descripción:</span>
                    <span><?= htmlspecialchars($recibo['servicio_descripcion'] ?? 'Sin descripción') ?></span>
                </div>
                <div class="info-row">
                    <span>Contrato:</span>
                    <span>#<?= $recibo['id_contrato'] ?></span>
                </div>
            </div>

            <!-- Información del Pago -->
            <div class="info-card">
                <h3><i class="fas fa-money-bill-wave"></i> Información de Pago</h3>
                <div class="info-row">
                    <span>Monto Total:</span>
                    <span><strong>$<?= number_format($recibo['monto'], 2) ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Pagado:</span>
                    <span
                        style="color: #22c55e;"><strong>$<?= number_format($recibo['total_pagado'], 2) ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Saldo Pendiente:</span>
                    <span
                        style="color: #ef4444;"><strong>$<?= number_format($recibo['monto'] - $recibo['total_pagado'], 2) ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Estado:</span>
                    <span>
                        <?php
                        $badge_class = '';
                        switch ($recibo['estado']) {
                            case 'pagada':
                                $badge_class = 'badge-success';
                                break;
                            case 'pendiente':
                                $badge_class = 'badge-warning';
                                break;
                            case 'vencida':
                                $badge_class = 'badge-danger';
                                break;
                            default:
                                $badge_class = 'badge-secondary';
                        }
                        ?>
                        <span class="badge <?= $badge_class ?>">
                            <?= strtoupper($recibo['estado']) ?>
                        </span>
                    </span>
                </div>
            </div>

            <!-- Fechas -->
            <div class="info-card">
                <h3><i class="fas fa-calendar"></i> Fechas</h3>
                <div class="info-row">
                    <span>Emisión:</span>
                    <span><?= date('d/m/Y', strtotime($recibo['fecha_emision'])) ?></span>
                </div>
                <div class="info-row">
                    <span>Vencimiento:</span>
                    <span><?= date('d/m/Y', strtotime($recibo['fecha_vencimiento'])) ?></span>
                </div>
                <div class="info-row">
                    <span>Periodo:</span>
                    <span><?= htmlspecialchars($recibo['periodo_pagado'] ?? 'No especificado') ?></span>
                </div>
                <?php
                $vencimiento = strtotime($recibo['fecha_vencimiento']);
                $hoy = strtotime('today');
                $dias_restantes = ($vencimiento - $hoy) / (60 * 60 * 24);
                ?>
                <div class="info-row">
                    <span>Días restantes:</span>
                    <span
                        style="<?= $dias_restantes < 0 ? 'color: #ef4444;' : ($dias_restantes <= 3 ? 'color: #f59e0b;' : 'color: #22c55e;') ?>">
                        <?= $dias_restantes < 0 ? abs($dias_restantes) . ' días vencidos' : $dias_restantes . ' días' ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Historial de Pagos -->
        <div class="info-card">
            <h3><i class="fas fa-history"></i> Historial de Pagos</h3>
            <?php if ($pagos): ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th># Pago</th>
                            <th>Monto</th>
                            <th>Fecha</th>
                            <th>Método</th>
                            <th>Folio</th>
                            <th>Registrado por</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pagos as $pago): ?>
                            <tr>
                                <td>#<?= $pago['id_pago'] ?></td>
                                <td>$<?= number_format($pago['monto_pagado'], 2) ?></td>
                                <td><?= date('d/m/Y H:i', strtotime($pago['fecha_pago'])) ?></td>
                                <td><?= htmlspecialchars($pago['metodo_pago'] ?? 'No especificado') ?></td>
                                <td><?= htmlspecialchars($pago['folio_referencia'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($pago['registrado_por'] ?? 'Sistema') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p style="text-align: center; color: var(--muted); padding: 20px;">
                    No se han registrado pagos para este recibo.
                </p>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>