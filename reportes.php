<?php
/* AYONET · Módulo de Reportes Avanzados */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('America/Mexico_City');

/* ---------- Helpers ---------- */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function formatoMoneda($monto)
{
    return '$' . number_format((float) $monto, 2, '.', ',');
}

function calcularDiasVencimiento($fechaVencimiento)
{
    $hoy = new DateTime();
    $vencimiento = new DateTime($fechaVencimiento);
    $diferencia = $hoy->diff($vencimiento);
    return $vencimiento < $hoy ? -$diferencia->days : $diferencia->days;
}

/* ---------- Parámetros de filtros ---------- */
$filtro_fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$filtro_fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-t');
$filtro_tipo_reporte = $_GET['tipo_reporte'] ?? 'pagos';

/* ---------- Reporte: FACTURAS PENDIENTES ---------- */
if ($filtro_tipo_reporte === 'pendientes') {
    $facturasPendientes = $pdo->query("
        SELECT 
            f.id_factura,
            f.monto,
            f.fecha_emision,
            f.fecha_vencimiento,
            f.periodo_pagado,
            f.estado,
            c.nombre_completo as cliente_nombre,
            c.codigo_cliente,
            c.telefono,
            c.email,
            co.id_contrato,
            s.nombre_servicio
        FROM public.facturas f
        JOIN public.contratos co ON co.id_contrato = f.id_contrato
        JOIN public.clientes c ON c.id_cliente = co.id_cliente
        JOIN public.servicios s ON s.id_servicio = co.id_servicio
        WHERE f.estado = 'pendiente'
        ORDER BY f.fecha_vencimiento ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Calcular total pendiente
    $totalPendiente = 0;
    foreach ($facturasPendientes as $factura) {
        $totalPendiente += $factura['monto'];
    }
}

/* ---------- Reporte: PAGOS POR PERÍODO ---------- */
if ($filtro_tipo_reporte === 'pagos') {
    $pagosPeriodo = $pdo->prepare("
        SELECT 
            p.id_pago,
            p.monto_pagado,
            p.fecha_pago,
            p.metodo_pago,
            p.folio_referencia,
            f.periodo_pagado,
            c.nombre_completo as cliente_nombre,
            c.codigo_cliente,
            s.nombre_servicio
        FROM public.pagos p
        JOIN public.facturas f ON f.id_factura = p.id_factura
        JOIN public.contratos co ON co.id_contrato = f.id_contrato
        JOIN public.clientes c ON c.id_cliente = co.id_cliente
        JOIN public.servicios s ON s.id_servicio = co.id_servicio
        WHERE DATE(p.fecha_pago) BETWEEN :fecha_desde AND :fecha_hasta
        ORDER BY p.fecha_pago DESC
    ");
    $pagosPeriodo->execute([
        ':fecha_desde' => $filtro_fecha_desde,
        ':fecha_hasta' => $filtro_fecha_hasta
    ]);
    $pagosPeriodo = $pagosPeriodo->fetchAll(PDO::FETCH_ASSOC);

    // Calcular total de pagos en el período
    $totalPagos = 0;
    foreach ($pagosPeriodo as $pago) {
        $totalPagos += $pago['monto_pagado'];
    }
}

/* ---------- Reporte: MOROSIDAD ---------- */
if ($filtro_tipo_reporte === 'morosidad') {
    $clientesMorosos = $pdo->query("
        SELECT 
            c.id_cliente,
            c.nombre_completo,
            c.codigo_cliente,
            c.telefono,
            c.email,
            COUNT(f.id_factura) as facturas_pendientes,
            SUM(f.monto) as total_adeudado,
            MIN(f.fecha_vencimiento) as primera_vencida,
            MAX(f.fecha_vencimiento) as ultima_vencida
        FROM public.clientes c
        JOIN public.contratos co ON co.id_cliente = c.id_cliente
        JOIN public.facturas f ON f.id_contrato = co.id_contrato
        WHERE f.estado = 'pendiente' 
        AND f.fecha_vencimiento < CURRENT_DATE
        GROUP BY c.id_cliente, c.nombre_completo, c.codigo_cliente, c.telefono, c.email
        HAVING SUM(f.monto) > 0
        ORDER BY total_adeudado DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Calcular mora total
    $moraTotal = 0;
    foreach ($clientesMorosos as $cliente) {
        $moraTotal += $cliente['total_adeudado'];
    }
}

/* ---------- Reporte: ESTADÍSTICAS GENERALES ---------- */
if ($filtro_tipo_reporte === 'estadisticas') {
    // Estadísticas de pagos
    $statsPagos = $pdo->query("
        SELECT 
            COUNT(*) as total_pagos,
            SUM(monto_pagado) as total_recaudado,
            AVG(monto_pagado) as promedio_pago,
            MIN(fecha_pago) as primer_pago,
            MAX(fecha_pago) as ultimo_pago
        FROM public.pagos
    ")->fetch(PDO::FETCH_ASSOC);

    // Estadísticas de facturas
    $statsFacturas = $pdo->query("
        SELECT 
            COUNT(*) as total_facturas,
            SUM(monto) as total_facturado,
            COUNT(CASE WHEN estado = 'pendiente' THEN 1 END) as facturas_pendientes,
            COUNT(CASE WHEN estado = 'pagada' THEN 1 END) as facturas_pagadas,
            SUM(CASE WHEN estado = 'pendiente' THEN monto ELSE 0 END) as total_pendiente
        FROM public.facturas
    ")->fetch(PDO::FETCH_ASSOC);

    // Métodos de pago más usados
    $metodosPago = $pdo->query("
        SELECT 
            metodo_pago,
            COUNT(*) as cantidad,
            SUM(monto_pagado) as total
        FROM public.pagos
        GROUP BY metodo_pago
        ORDER BY total DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolPill = strtoupper($_SESSION['rol'] ?? 'USUARIO');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Reportes Avanzados</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
            transition: all 0.3s ease;
        }

        .ghost {
            background: rgba(255, 255, 255, .08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .16)
        }

        .ghost:hover,
        .ghost.active {
            background: rgba(255, 255, 255, .15);
            border-color: var(--neon1);
        }

        .primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022
        }

        .btn-success {
            background: rgba(34, 197, 94, 0.2);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.4);
        }

        .btn-success:hover {
            background: rgba(34, 197, 94, 0.3);
            border-color: #22c55e;
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

        .btn-warning {
            background: rgba(245, 158, 11, 0.2);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.4);
        }

        .btn-warning:hover {
            background: rgba(245, 158, 11, 0.3);
            border-color: #f59e0b;
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
            margin-bottom: 15px;
        }

        .card h3 {
            margin: 0 0 15px;
            font-size: 1.3rem;
            color: var(--neon1);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: transform 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            border-color: var(--neon1);
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--neon1);
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: 0.85rem;
            color: #cfe1ff;
        }

        .filtros {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 20px;
        }

        .filtro-group {
            display: flex;
            gap: 15px;
            align-items: end;
            flex-wrap: wrap;
        }

        .filtro-item {
            flex: 1;
            min-width: 150px;
        }

        .lab {
            font-size: .85rem;
            color: #d8e4ff;
            display: block;
            margin-bottom: 6px;
        }

        .ctrl {
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .16);
            outline: none;
            font-size: 0.9rem;
        }

        .table-container {
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .12);
            margin-top: 15px;
        }

        .table-ayanet {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, .04);
        }

        th,
        td {
            padding: 12px 10px;
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

        .badge-info {
            background: #dbeafe;
            color: #1e40af;
        }

        .seccion {
            display: none;
        }

        .seccion.active {
            display: block;
        }

        .chart-container {
            background: rgba(255, 255, 255, .06);
            border-radius: 12px;
            padding: 20px;
            margin: 20px 0;
            height: 400px;
        }

        .resumen-total {
            background: linear-gradient(135deg, var(--neon1), var(--neon2));
            color: #061022;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
            text-align: center;
            font-weight: 700;
            font-size: 1.2rem;
        }

        .nav-reportes {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            padding-bottom: 15px;
        }

        .nav-reportes .btn {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
    </style>
</head>

<body class="bg">
    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700;letter-spacing:.3px">AYONET · Reportes Avanzados</div>
                    <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
                <span class="pill"><?= h($rolPill) ?></span>
            </div>
        </header>

        <section class="panel">
            <!-- Navegación entre reportes -->
            <div class="nav-reportes">
                <button class="btn ghost <?= $filtro_tipo_reporte === 'pagos' ? 'active' : '' ?>"
                    onclick="cambiarReporte('pagos')">
                    <i class="fa-solid fa-chart-line"></i> Pagos
                </button>
                <button class="btn ghost <?= $filtro_tipo_reporte === 'pendientes' ? 'active' : '' ?>"
                    onclick="cambiarReporte('pendientes')">
                    <i class="fa-solid fa-clock"></i> Pendientes
                </button>
                <button class="btn ghost <?= $filtro_tipo_reporte === 'morosidad' ? 'active' : '' ?>"
                    onclick="cambiarReporte('morosidad')">
                    <i class="fa-solid fa-exclamation-triangle"></i> Morosidad
                </button>
                <button class="btn ghost <?= $filtro_tipo_reporte === 'estadisticas' ? 'active' : '' ?>"
                    onclick="cambiarReporte('estadisticas')">
                    <i class="fa-solid fa-chart-pie"></i> Estadísticas
                </button>
            </div>

            <!-- Filtros -->
            <div class="filtros">
                <form id="formFiltros" method="GET">
                    <input type="hidden" name="tipo_reporte" id="tipo_reporte" value="<?= h($filtro_tipo_reporte) ?>">

                    <div class="filtro-group">
                        <div class="filtro-item">
                            <label class="lab">Fecha Desde</label>
                            <input class="ctrl" type="date" name="fecha_desde" id="fecha_desde"
                                value="<?= h($filtro_fecha_desde) ?>">
                        </div>
                        <div class="filtro-item">
                            <label class="lab">Fecha Hasta</label>
                            <input class="ctrl" type="date" name="fecha_hasta" id="fecha_hasta"
                                value="<?= h($filtro_fecha_hasta) ?>">
                        </div>
                        <div class="filtro-item">
                            <button class="btn primary" type="submit" style="height: 42px;">
                                <i class="fa-solid fa-filter"></i> Aplicar Filtros
                            </button>
                        </div>
                        <div class="filtro-item">
                            <button class="btn ghost" type="button" onclick="exportarPDF()" style="height: 42px;">
                                <i class="fa-solid fa-file-pdf"></i> Exportar PDF
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- REPORTE: PAGOS POR PERÍODO -->
            <div id="seccion-pagos" class="seccion <?= $filtro_tipo_reporte === 'pagos' ? 'active' : '' ?>">
                <div class="card">
                    <h3><i class="fa-solid fa-chart-line"></i> Reporte de Pagos</h3>
                    <p>Período: <?= h($filtro_fecha_desde) ?> al <?= h($filtro_fecha_hasta) ?></p>

                    <div class="resumen-total">
                        Total Recaudado: <?= formatoMoneda($totalPagos ?? 0) ?> |
                        Cantidad de Pagos: <?= count($pagosPeriodo ?? []) ?>
                    </div>

                    <div class="table-container">
                        <table id="tablaPagos" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Monto</th>
                                    <th>Fecha Pago</th>
                                    <th>Método</th>
                                    <th>Referencia</th>
                                    <th>Periodo</th>
                                    <th>Servicio</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($pagosPeriodo)): ?>
                                    <?php foreach ($pagosPeriodo as $pago): ?>
                                        <tr>
                                            <td>
                                                <div><strong><?= h($pago['cliente_nombre']) ?></strong></div>
                                                <small style="color: #cfe1ff;"><?= h($pago['codigo_cliente']) ?></small>
                                            </td>
                                            <td><strong><?= formatoMoneda($pago['monto_pagado']) ?></strong></td>
                                            <td><?= h(date('d/m/Y H:i', strtotime($pago['fecha_pago']))) ?></td>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= h($pago['metodo_pago']) ?>
                                                </span>
                                            </td>
                                            <td><?= h($pago['folio_referencia'] ?? 'N/A') ?></td>
                                            <td><?= h($pago['periodo_pagado'] ?? 'N/A') ?></td>
                                            <td><?= h($pago['nombre_servicio']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- REPORTE: FACTURAS PENDIENTES -->
            <div id="seccion-pendientes" class="seccion <?= $filtro_tipo_reporte === 'pendientes' ? 'active' : '' ?>">
                <div class="card">
                    <h3><i class="fa-solid fa-clock"></i> Facturas Pendientes de Pago</h3>

                    <div class="resumen-total" style="background: linear-gradient(135deg, #f59e0b, #f97316);">
                        Total Pendiente: <?= formatoMoneda($totalPendiente ?? 0) ?> |
                        Facturas Pendientes: <?= count($facturasPendientes ?? []) ?>
                    </div>

                    <div class="table-container">
                        <table id="tablaPendientes" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Monto</th>
                                    <th>Emisión</th>
                                    <th>Vencimiento</th>
                                    <th>Días</th>
                                    <th>Periodo</th>
                                    <th>Servicio</th>
                                    <th>Contacto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($facturasPendientes)): ?>
                                    <?php foreach ($facturasPendientes as $factura): ?>
                                        <?php
                                        $diasVencimiento = calcularDiasVencimiento($factura['fecha_vencimiento']);
                                        $badgeClass = $diasVencimiento < 0 ? 'badge-danger' :
                                            ($diasVencimiento <= 5 ? 'badge-warning' : 'badge-secondary');
                                        $textoDias = $diasVencimiento < 0 ?
                                            "Vencida hace " . abs($diasVencimiento) . " días" :
                                            "Vence en $diasVencimiento días";
                                        ?>
                                        <tr>
                                            <td>
                                                <div><strong><?= h($factura['cliente_nombre']) ?></strong></div>
                                                <small style="color: #cfe1ff;"><?= h($factura['codigo_cliente']) ?></small>
                                            </td>
                                            <td><strong><?= formatoMoneda($factura['monto']) ?></strong></td>
                                            <td><?= h($factura['fecha_emision']) ?></td>
                                            <td><?= h($factura['fecha_vencimiento']) ?></td>
                                            <td>
                                                <span class="badge <?= $badgeClass ?>">
                                                    <?= $textoDias ?>
                                                </span>
                                            </td>
                                            <td><?= h($factura['periodo_pagado'] ?? 'N/A') ?></td>
                                            <td><?= h($factura['nombre_servicio']) ?></td>
                                            <td>
                                                <small><?= h($factura['telefono']) ?></small><br>
                                                <small><?= h($factura['email']) ?></small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- REPORTE: MOROSIDAD -->
            <div id="seccion-morosidad" class="seccion <?= $filtro_tipo_reporte === 'morosidad' ? 'active' : '' ?>">
                <div class="card">
                    <h3><i class="fa-solid fa-exclamation-triangle"></i> Reporte de Morosidad</h3>

                    <div class="resumen-total" style="background: linear-gradient(135deg, #ef4444, #dc2626);">
                        Mora Total: <?= formatoMoneda($moraTotal ?? 0) ?> |
                        Clientes Morosos: <?= count($clientesMorosos ?? []) ?>
                    </div>

                    <div class="table-container">
                        <table id="tablaMorosidad" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Cliente</th>
                                    <th>Facturas Pend.</th>
                                    <th>Total Adeudado</th>
                                    <th>Primera Vencida</th>
                                    <th>Última Vencida</th>
                                    <th>Contacto</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($clientesMorosos)): ?>
                                    <?php foreach ($clientesMorosos as $cliente): ?>
                                        <tr>
                                            <td>
                                                <div><strong><?= h($cliente['nombre_completo']) ?></strong></div>
                                                <small style="color: #cfe1ff;"><?= h($cliente['codigo_cliente']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge badge-danger">
                                                    <?= (int) $cliente['facturas_pendientes'] ?> facturas
                                                </span>
                                            </td>
                                            <td><strong><?= formatoMoneda($cliente['total_adeudado']) ?></strong></td>
                                            <td><?= h($cliente['primera_vencida']) ?></td>
                                            <td><?= h($cliente['ultima_vencida']) ?></td>
                                            <td>
                                                <small><?= h($cliente['telefono']) ?></small><br>
                                                <small><?= h($cliente['email']) ?></small>
                                            </td>
                                            <td>
                                                <button class="btn btn-warning btn-small"
                                                    onclick="contactarCliente('<?= h($cliente['nombre_completo']) ?>', '<?= h($cliente['telefono']) ?>', '<?= h($cliente['email']) ?>')">
                                                    <i class="fa-solid fa-phone"></i> Contactar
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- REPORTE: ESTADÍSTICAS GENERALES -->
            <div id="seccion-estadisticas"
                class="seccion <?= $filtro_tipo_reporte === 'estadisticas' ? 'active' : '' ?>">
                <div class="card">
                    <h3><i class="fa-solid fa-chart-pie"></i> Estadísticas Generales</h3>

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?= formatoMoneda($statsPagos['total_recaudado'] ?? 0) ?></div>
                            <div class="stat-label">Total Recaudado</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= (int) ($statsPagos['total_pagos'] ?? 0) ?></div>
                            <div class="stat-label">Total de Pagos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= formatoMoneda($statsPagos['promedio_pago'] ?? 0) ?></div>
                            <div class="stat-label">Promedio por Pago</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= formatoMoneda($statsFacturas['total_pendiente'] ?? 0) ?></div>
                            <div class="stat-label">Total Pendiente</div>
                        </div>
                    </div>

                    <!-- Gráfico de métodos de pago -->
                    <div class="chart-container">
                        <canvas id="chartMetodosPago"></canvas>
                    </div>

                    <div class="table-container">
                        <table id="tablaEstadisticas" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Método de Pago</th>
                                    <th>Cantidad</th>
                                    <th>Total Recaudado</th>
                                    <th>Porcentaje</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (isset($metodosPago)): ?>
                                    <?php foreach ($metodosPago as $metodo): ?>
                                        <tr>
                                            <td>
                                                <span class="badge badge-info">
                                                    <?= h($metodo['metodo_pago']) ?>
                                                </span>
                                            </td>
                                            <td><?= (int) $metodo['cantidad'] ?></td>
                                            <td><strong><?= formatoMoneda($metodo['total']) ?></strong></td>
                                            <td>
                                                <?php
                                                $porcentaje = $statsPagos['total_recaudado'] > 0 ?
                                                    ($metodo['total'] / $statsPagos['total_recaudado']) * 100 : 0;
                                                ?>
                                                <span class="badge badge-success">
                                                    <?= number_format($porcentaje, 1) ?>%
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <script>
        $(function () {
            // Inicializar DataTables para todas las tablas
            $('.table-ayanet').DataTable({
                dom: '<"top"lf>rt<"bottom"ip><"clear">',
                searching: true,
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
                order: [[0, 'asc']],
                responsive: false,
                autoWidth: false,
                language: {
                    decimal: '',
                    emptyTable: 'No hay datos disponibles',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ registros',
                    infoEmpty: 'Mostrando 0 a 0 de 0 registros',
                    infoFiltered: '(filtrado de _MAX_ registros totales)',
                    lengthMenu: 'Mostrar _MENU_ registros',
                    loadingRecords: 'Cargando...',
                    processing: 'Procesando...',
                    search: 'Buscar:',
                    zeroRecords: 'No se encontraron registros coincidentes',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            // Gráfico de métodos de pago
            <?php if (isset($metodosPago) && $filtro_tipo_reporte === 'estadisticas'): ?>
                const ctx = document.getElementById('chartMetodosPago').getContext('2d');
                new Chart(ctx, {
                    type: 'doughnut',
                    data: {
                        labels: [<?= implode(',', array_map(function ($m) {
                            return "'" . h($m['metodo_pago']) . "'";
                        }, $metodosPago)) ?>],
                        datasets: [{
                            data: [<?= implode(',', array_map(function ($m) {
                                return $m['total'];
                            }, $metodosPago)) ?>],
                            backgroundColor: [
                                '#00d4ff', '#6a00ff', '#ff007a', '#00ff88', '#ffd700', '#ff6b6b'
                            ],
                            borderWidth: 2,
                            borderColor: '#0f163b'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    color: '#cfe1ff',
                                    font: {
                                        family: 'Poppins'
                                    }
                                }
                            },
                            title: {
                                display: true,
                                text: 'Distribución por Método de Pago',
                                color: '#cfe1ff',
                                font: {
                                    family: 'Poppins',
                                    size: 16
                                }
                            }
                        }
                    }
                });
            <?php endif; ?>
        });

        function cambiarReporte(tipo) {
            document.getElementById('tipo_reporte').value = tipo;
            document.getElementById('formFiltros').submit();
        }

        function exportarPDF() {
            Swal.fire({
                title: 'Exportar a PDF',
                text: '¿Deseas generar un reporte en PDF?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, exportar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Aquí iría la lógica para generar PDF
                    Swal.fire({
                        icon: 'info',
                        title: 'Funcionalidad en desarrollo',
                        text: 'La exportación a PDF estará disponible próximamente'
                    });
                }
            });
        }

        function contactarCliente(nombre, telefono, email) {
            Swal.fire({
                title: `Contactar a ${nombre}`,
                html: `
                    <div style="text-align: left;">
                        <p><strong>Teléfono:</strong> ${telefono || 'No disponible'}</p>
                        <p><strong>Email:</strong> ${email || 'No disponible'}</p>
                        <hr>
                        <p>¿Qué acción deseas realizar?</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Llamar',
                cancelButtonText: 'Enviar Email',
                showDenyButton: true,
                denyButtonText: 'Copiar Datos'
            }).then((result) => {
                if (result.isConfirmed && telefono) {
                    window.open(`tel:${telefono}`);
                } else if (result.isDenied) {
                    navigator.clipboard.writeText(`Cliente: ${nombre}\nTel: ${telefono}\nEmail: ${email}`);
                    Swal.fire('Copiado!', 'Datos del cliente copiados al portapapeles', 'success');
                } else if (!result.dismiss) {
                    window.open(`mailto:${email}?subject=Recordatorio de pago pendiente&body=Estimado ${nombre},`);
                }
            });
        }

        // Establecer fechas por defecto (mes actual)
        const hoy = new Date();
        const primerDiaMes = new Date(hoy.getFullYear(), hoy.getMonth(), 1);
        const ultimoDiaMes = new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0);

        document.getElementById('fecha_desde').value = primerDiaMes.toISOString().split('T')[0];
        document.getElementById('fecha_hasta').value = ultimoDiaMes.toISOString().split('T')[0];
    </script>
</body>

</html>