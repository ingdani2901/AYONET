<?php
/* AYONET · Módulo de Pagos (CRUD completo) */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../login.php');
    exit;
}

date_default_timezone_set('America/Mexico_City');

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_pagos'])) {
    $_SESSION['csrf_pagos'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_pagos'];

/* ---------- Helpers ---------- */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function formatoMoneda($monto)
{
    return '$' . number_format((float) $monto, 2, '.', ',');
}

$flash = null;
$edit = null;
$isEdit = false;

/* ---------- Estados y métodos de pago ---------- */
$estadosPago = [
    'completado' => 'Completado',
    'pendiente' => 'Pendiente',
    'fallido' => 'Fallido',
    'reembolsado' => 'Reembolsado'
];

/* ---------- SOLO EFECTIVO ---------- */
$metodosPago = [
    'efectivo' => 'Efectivo'
    // SOLO EFECTIVO - eliminamos todos los otros métodos
];

/* ---------- Cargar datos para formularios ---------- */
// Facturas pendientes de pago
$facturas = $pdo->query("
    SELECT f.id_factura, f.monto, f.fecha_emision, f.fecha_vencimiento, f.periodo_pagado,
           c.nombre_completo as cliente_nombre, co.id_contrato
    FROM public.facturas f
    JOIN public.contratos co ON co.id_contrato = f.id_contrato
    JOIN public.clientes c ON c.id_cliente = co.id_cliente
    WHERE f.estado = 'pendiente'
    ORDER BY f.fecha_vencimiento ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Preseleccionar factura si viene por URL ---------- */
$facturaPreseleccionada = (int) ($_GET['factura'] ?? 0);
$facturaPreseleccionadaData = null;

if ($facturaPreseleccionada) {
    // Verificar que la factura existe y está pendiente
    foreach ($facturas as $factura) {
        if ($factura['id_factura'] == $facturaPreseleccionada) {
            $facturaPreseleccionadaData = $factura;
            break;
        }
    }
}

/* ---------- POST: crear / actualizar / eliminar ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
    $accion = $_POST['accion'] ?? '';
    try {
        if ($accion === 'crear' || $accion === 'actualizar') {
            $idPago = isset($_POST['id_pago']) ? (int) $_POST['id_pago'] : 0;
            $idFactura = (int) ($_POST['id_factura'] ?? 0);
            $montoPagado = (float) ($_POST['monto_pagado'] ?? 0);
            $fechaPago = $_POST['fecha_pago'] ?? '';
            $metodoPago = $_POST['metodo_pago'] ?? '';
            $folioReferencia = trim($_POST['folio_referencia'] ?? '');

            // Validaciones
            if ($idFactura <= 0)
                throw new RuntimeException('Selecciona una factura válida');
            if ($montoPagado <= 0)
                throw new RuntimeException('El monto pagado debe ser mayor a 0');
            if (empty($fechaPago))
                throw new RuntimeException('La fecha de pago es requerida');

            // Validar fecha
            $fechaPagoObj = DateTime::createFromFormat('Y-m-d\TH:i', $fechaPago);
            if (!$fechaPagoObj)
                throw new RuntimeException('Fecha de pago inválida');

            // Verificar que la factura existe y obtener su monto
            $qFactura = $pdo->prepare("
                SELECT monto, estado, id_contrato 
                FROM public.facturas 
                WHERE id_factura = :id
            ");
            $qFactura->execute([':id' => $idFactura]);
            $factura = $qFactura->fetch(PDO::FETCH_ASSOC);

            if (!$factura)
                throw new RuntimeException('La factura seleccionada no existe');

            if ($accion === 'crear') {
                // Verificar si ya existe un pago para esta factura
                $qCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM public.pagos 
                    WHERE id_factura = :id_factura
                ");
                $qCheck->execute([':id_factura' => $idFactura]);
                if ((int) $qCheck->fetchColumn() > 0) {
                    throw new RuntimeException('Ya existe un pago registrado para esta factura');
                }

                // Crear pago
                $q = $pdo->prepare("
                    INSERT INTO public.pagos 
                    (id_factura, monto_pagado, fecha_pago, metodo_pago, folio_referencia) 
                    VALUES (:factura, :monto, :fecha, :metodo, :folio)
                ");
                $q->execute([
                    ':factura' => $idFactura,
                    ':monto' => $montoPagado,
                    ':fecha' => $fechaPago,
                    ':metodo' => $metodoPago,
                    ':folio' => empty($folioReferencia) ? null : $folioReferencia
                ]);

                // Actualizar estado de la factura a "pagada"
                $pdo->prepare("UPDATE public.facturas SET estado = 'pagada' WHERE id_factura = :id")
                    ->execute([':id' => $idFactura]);

                $flash = ['ok', 'Pago registrado', 'El pago ha sido registrado exitosamente'];

            } else {
                if ($idPago <= 0)
                    throw new RuntimeException('ID pago inválido');

                // Actualizar pago
                $q = $pdo->prepare("
                    UPDATE public.pagos SET 
                    id_factura = :factura, monto_pagado = :monto, fecha_pago = :fecha, 
                    metodo_pago = :metodo, folio_referencia = :folio
                    WHERE id_pago = :id
                ");
                $q->execute([
                    ':factura' => $idFactura,
                    ':monto' => $montoPagado,
                    ':fecha' => $fechaPago,
                    ':metodo' => $metodoPago,
                    ':folio' => empty($folioReferencia) ? null : $folioReferencia,
                    ':id' => $idPago
                ]);

                $flash = ['ok', 'Pago actualizado', 'Los datos del pago han sido actualizados'];
            }
        }

        if ($accion === 'eliminar') {
            $idPago = (int) ($_POST['id_pago'] ?? 0);
            if ($idPago <= 0)
                throw new RuntimeException('ID pago inválido');

            // Obtener la factura relacionada para revertir su estado
            $qFactura = $pdo->prepare("SELECT id_factura FROM public.pagos WHERE id_pago = :id");
            $qFactura->execute([':id' => $idPago]);
            $pago = $qFactura->fetch(PDO::FETCH_ASSOC);

            if ($pago) {
                // Revertir estado de la factura a pendiente
                $pdo->prepare("UPDATE public.facturas SET estado = 'pendiente' WHERE id_factura = :id")
                    ->execute([':id' => $pago['id_factura']]);
            }

            // Eliminar el pago
            $pdo->prepare("DELETE FROM public.pagos WHERE id_pago = :id")
                ->execute([':id' => $idPago]);

            $flash = ['ok', 'Pago eliminado', 'El pago ha sido eliminado y la factura marcada como pendiente'];
        }

    } catch (Throwable $e) {
        $flash = ['error', 'Error', $e->getMessage() ?: 'No se pudo guardar.'];
    }
}

/* ---------- GET: cargar para edición ---------- */
if (isset($_GET['editar'])) {
    $idPago = (int) $_GET['editar'];
    $q = $pdo->prepare("
        SELECT p.*, f.monto as monto_factura, c.nombre_completo as cliente_nombre
        FROM public.pagos p
        JOIN public.facturas f ON f.id_factura = p.id_factura
        JOIN public.contratos co ON co.id_contrato = f.id_contrato
        JOIN public.clientes c ON c.id_cliente = co.id_cliente
        WHERE p.id_pago = :id LIMIT 1
    ");
    $q->execute([':id' => $idPago]);
    $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;
    $isEdit = is_array($edit);
}

/* ---------- LISTADO ---------- */
$pagos = $pdo->query("
    SELECT 
        p.id_pago,
        p.monto_pagado,
        p.fecha_pago,
        p.metodo_pago,
        p.folio_referencia,
        f.monto as monto_factura,
        f.periodo_pagado,
        c.nombre_completo as cliente_nombre,
        c.codigo_cliente,
        co.id_contrato
    FROM public.pagos p
    JOIN public.facturas f ON f.id_factura = p.id_factura
    JOIN public.contratos co ON co.id_contrato = f.id_contrato
    JOIN public.clientes c ON c.id_cliente = co.id_cliente
    ORDER BY p.fecha_pago DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas rápidas
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_pagos,
        SUM(monto_pagado) as total_recaudado,
        AVG(monto_pagado) as promedio_pago
    FROM public.pagos
")->fetch(PDO::FETCH_ASSOC);

$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolPill = strtoupper($_SESSION['rol'] ?? 'USUARIO');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Gestión de Pagos</title>
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
            grid-template-columns: 400px 1fr;
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

        .stats-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }

        .stat-card {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 10px;
            padding: 12px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--neon1);
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: #cfe1ff;
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

        .form-container {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
            padding-right: 5px;
        }

        .form-container::-webkit-scrollbar {
            width: 6px;
        }

        .form-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 3px;
        }

        .form-container::-webkit-scrollbar-thumb {
            background: var(--neon1);
            border-radius: 3px;
        }

        .form-container::-webkit-scrollbar-thumb:hover {
            background: var(--neon3);
        }

        .table-container {
            overflow: hidden;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, .12);
        }

        .table-ayanet {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 255, 255, .04);
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

        tbody tr {
            background: rgba(255, 255, 255, .06);
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        tbody tr:hover {
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

        .hidden {
            display: none !important
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

        .factura-info {
            background: rgba(34, 197, 94, 0.1);
            border: 1px solid rgba(34, 197, 94, 0.3);
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 12px;
            font-size: 0.8rem;
        }

        .factura-info i {
            color: #22c55e;
            margin-right: 5px;
        }
    </style>
</head>

<body class="bg">
    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700;letter-spacing:.3px">AYONET · Gestión de Pagos</div>
                    <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
                <a class="btn ghost" href="recibos.php"><i class="fa-solid fa-receipt"></i> Ver Recibos</a>
                <span class="pill"><?= h($rolPill) ?></span>
            </div>
        </header>

        <section class="panel">
            <div class="grid">
                <!-- Formulario: CON SCROLL -->
                <div class="card">
                    <h3 id="formTitle">Registrar Nuevo Pago</h3>

                    <!-- Estadísticas rápidas -->
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-number"><?= (int) $stats['total_pagos'] ?></div>
                            <div class="stat-label">Total Pagos</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-number"><?= formatoMoneda($stats['total_recaudado'] ?? 0) ?></div>
                            <div class="stat-label">Total Recaudado</div>
                        </div>
                    </div>

                    <div class="form-container">
                        <form id="formPago" method="post">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="accion" id="accion" value="crear">
                            <input type="hidden" name="id_pago" id="id_pago" value="">

                            <div class="info-box">
                                <i class="fa-solid fa-circle-info"></i>
                                Complete todos los campos obligatorios para registrar un pago.
                            </div>

                            <label class="lab">Factura *</label>
                            <select class="ctrl" name="id_factura" id="id_factura" required>
                                <option value="">Selecciona una factura pendiente</option>
                                <?php foreach ($facturas as $factura): ?>
                                    <option value="<?= (int) $factura['id_factura'] ?>"
                                        data-monto="<?= h($factura['monto']) ?>"
                                        data-cliente="<?= h($factura['cliente_nombre']) ?>"
                                        <?= ($facturaPreseleccionadaData && $factura['id_factura'] == $facturaPreseleccionadaData['id_factura']) ? 'selected' : '' ?>>
                                        <?= h($factura['cliente_nombre']) ?> -
                                        <?= formatoMoneda($factura['monto']) ?> -
                                        Vence: <?= h($factura['fecha_vencimiento']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <!-- Información de la factura seleccionada -->
                            <div id="facturaInfo" class="factura-info" style="display:none;">
                                <i class="fa-solid fa-receipt"></i>
                                <strong>Cliente:</strong> <span id="infoCliente"></span> |
                                <strong>Monto Factura:</strong> <span id="infoMonto"></span> |
                                <strong>Periodo:</strong> <span id="infoPeriodo"></span>
                            </div>

                            <label class="lab">Monto Pagado *</label>
                            <input class="ctrl" type="number" name="monto_pagado" id="monto_pagado" step="0.01" min="0"
                                required>

                            <label class="lab">Fecha y Hora del Pago *</label>
                            <input class="ctrl" type="datetime-local" name="fecha_pago" id="fecha_pago" required>

                            <label class="lab">Método de Pago *</label>
                            <select class="ctrl" name="metodo_pago" id="metodo_pago" required>
                                <option value="efectivo">Efectivo</option>
                            </select>

                            <label class="lab">Folio/Referencia (Opcional)</label>
                            <input class="ctrl" type="text" name="folio_referencia" id="folio_referencia"
                                placeholder="Número de transacción, referencia, etc.">

                            <div class="form-actions">
                                <button class="btn primary" type="submit">
                                    <i class="fa-solid fa-credit-card"></i> <span id="btnText">Registrar Pago</span>
                                </button>
                                <a class="btn ghost btn-small" href="" id="btnCancelar" style="display:none;">
                                    <i class="fa-solid fa-times"></i> Cancelar
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Listado: muestra todos los pagos -->
                <div class="card">
                    <h3>Historial de Pagos</h3>
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
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pagos as $p): ?>
                                    <tr>
                                        <td>
                                            <div><strong><?= h($p['cliente_nombre']) ?></strong></div>
                                            <small style="color: #cfe1ff;"><?= h($p['codigo_cliente']) ?></small>
                                        </td>
                                        <td>
                                            <div><strong><?= formatoMoneda($p['monto_pagado']) ?></strong></div>
                                            <small style="color: #cfe1ff;">Factura:
                                                <?= formatoMoneda($p['monto_factura']) ?></small>
                                        </td>
                                        <td><?= h(date('d/m/Y H:i', strtotime($p['fecha_pago']))) ?></td>
                                        <td>
                                            <span class="badge badge-secondary">
                                                <?= h($metodosPago[$p['metodo_pago']] ?? $p['metodo_pago']) ?>
                                            </span>
                                        </td>
                                        <td><?= h($p['folio_referencia'] ?? 'N/A') ?></td>
                                        <td><?= h($p['periodo_pagado'] ?? 'N/A') ?></td>
                                        <td class="actions">
                                            <button class="btn ghost btn-small" onclick='editarPago(<?= json_encode([
                                                'id_pago' => $p['id_pago'],
                                                'id_factura' => $p['id_factura'],
                                                'monto_pagado' => $p['monto_pagado'],
                                                'fecha_pago' => date('Y-m-d\TH:i', strtotime($p['fecha_pago'])),
                                                'metodo_pago' => $p['metodo_pago'],
                                                'folio_referencia' => $p['folio_referencia'],
                                                'cliente_nombre' => $p['cliente_nombre']
                                            ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>
                                                <i class="fa-regular fa-pen-to-square"></i> Editar
                                            </button>
                                            <button class="btn btn-danger btn-small"
                                                onclick="eliminarPago(<?= (int) $p['id_pago'] ?>, '<?= h($p['cliente_nombre']) ?>', '<?= formatoMoneda($p['monto_pagado']) ?>')">
                                                <i class="fa-solid fa-trash"></i> Eliminar
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
                icon: '<?= $flash[0] === 'ok' ? 'success' : 'error' ?>',
                title: '<?= $flash[1] ?>',
                text: '<?= $flash[2] ?>',
                timer: 3000
            });
        </script>
    <?php endif; ?>

    <script>
        $(function () {
            $('#tablaPagos').DataTable({
                dom: '<"top"lf>rt<"bottom"ip><"clear">',
                searching: true,
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
                order: [[2, 'desc']],
                responsive: false,
                autoWidth: false,
                language: {
                    decimal: '',
                    emptyTable: 'No hay pagos registrados',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ pagos',
                    infoEmpty: 'Mostrando 0 a 0 de 0 pagos',
                    infoFiltered: '(filtrado de _MAX_ pagos totales)',
                    lengthMenu: 'Mostrar _MENU_ pagos',
                    loadingRecords: 'Cargando...',
                    processing: 'Procesando...',
                    search: 'Buscar:',
                    zeroRecords: 'No se encontraron pagos coincidentes',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            // Auto-completar monto cuando se selecciona una factura
            $('#id_factura').change(function () {
                const selectedOption = $(this).find('option:selected');
                const montoFactura = selectedOption.data('monto');
                const clienteNombre = selectedOption.data('cliente');

                if (montoFactura) {
                    $('#monto_pagado').val(montoFactura);

                    // Mostrar información de la factura
                    $('#infoCliente').text(clienteNombre);
                    $('#infoMonto').text('$' + parseFloat(montoFactura).toFixed(2));
                    $('#infoPeriodo').text(selectedOption.text().split('Periodo:')[1]?.trim() || 'N/A');
                    $('#facturaInfo').show();
                } else {
                    $('#facturaInfo').hide();
                }
            });

            // Establecer fecha y hora actual por defecto
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            $('#fecha_pago').val(now.toISOString().slice(0, 16));

            // Si hay una factura preseleccionada, activar el cambio automáticamente
            if ($('#id_factura').find('option:selected').val()) {
                $('#id_factura').trigger('change');
            }
        });

        // Editar pago
        function editarPago(p) {
            Swal.fire({
                title: '¿Editar pago?',
                text: `Vas a editar el pago de ${p.cliente_nombre}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, editar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#3085d6'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#accion').val('actualizar');
                    $('#id_pago').val(p.id_pago);
                    $('#id_factura').val(p.id_factura);
                    $('#monto_pagado').val(p.monto_pagado);
                    $('#fecha_pago').val(p.fecha_pago);
                    $('#metodo_pago').val(p.metodo_pago);
                    $('#folio_referencia').val(p.folio_referencia || '');

                    // Actualizar UI
                    $('#formTitle').text('Editar Pago');
                    $('#btnText').text('Actualizar Pago');
                    $('#btnCancelar').show();
                    $('#facturaInfo').hide(); // Ocultar info de factura en edición

                    window.scrollTo({ top: 0, behavior: 'smooth' });
                }
            });
        }

        // Eliminar pago
        function eliminarPago(id, nombre, monto) {
            Swal.fire({
                title: '¿Eliminar pago?',
                html: `¿Estás seguro de que quieres eliminar el pago de <strong>${nombre}</strong> por <strong>${monto}</strong>?<br><br>
                       <small>✓ El pago será eliminado permanentemente<br>
                       ✓ La factura se marcará como pendiente nuevamente<br>
                       ✓ Esta acción no se puede deshacer</small>`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Crear formulario para eliminar
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '';

                    const csrf = document.createElement('input');
                    csrf.name = 'csrf';
                    csrf.value = '<?= h($CSRF) ?>';
                    form.appendChild(csrf);

                    const accion = document.createElement('input');
                    accion.name = 'accion';
                    accion.value = 'eliminar';
                    form.appendChild(accion);

                    const idInput = document.createElement('input');
                    idInput.name = 'id_pago';
                    idInput.value = id;
                    form.appendChild(idInput);

                    document.body.appendChild(form);
                    form.submit();
                }
            });
        }

        // Validación del formulario
        document.getElementById('formPago').addEventListener('submit', function (e) {
            const idFactura = this.id_factura.value;
            const monto = parseFloat(this.monto_pagado.value);
            const fechaPago = this.fecha_pago.value;

            if (!idFactura) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Campo requerido',
                    text: 'Debes seleccionar una factura'
                });
                return false;
            }

            if (!monto || monto <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Monto inválido',
                    text: 'El monto pagado debe ser mayor a 0'
                });
                return false;
            }

            if (!fechaPago) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Campo requerido',
                    text: 'La fecha de pago es obligatoria'
                });
                return false;
            }
        });

        // Limpiar formulario al hacer clic en Cancelar
        document.getElementById('btnCancelar').addEventListener('click', function (e) {
            e.preventDefault();
            resetForm();
        });

        function resetForm() {
            $('#accion').val('crear');
            $('#id_pago').val('');
            $('#formPago')[0].reset();
            $('#formTitle').text('Registrar Nuevo Pago');
            $('#btnText').text('Registrar Pago');
            $('#btnCancelar').hide();
            $('#facturaInfo').hide();

            // Restablecer fecha y hora actual
            const now = new Date();
            now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
            $('#fecha_pago').val(now.toISOString().slice(0, 16));

            // Restablecer método de pago a efectivo
            $('#metodo_pago').val('efectivo');
        }
    </script>
</body>

</html>