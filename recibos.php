<?php
/* AYONET · proayonet — Recibos (Admin)
 * - Gestión completa de recibos (usa tabla facturas internamente)
 * - Listado con filtros y estadísticas
 * - Integración con contratos y clientes
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$ME_ID = (int) ($_SESSION['id_usuario'] ?? 0);
$ME_NAME = $_SESSION['nombre'] ?? 'ADMIN';

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_recibos']))
    $_SESSION['csrf_recibos'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf_recibos'];

/* ---------- Helpers ---------- */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function formatoMoneda($monto)
{
    return '$' . number_format((float) $monto, 2);
}

function estadoBadge($estado)
{
    switch ($estado) {
        case 'pagada':
            return '<span class="badge badge-success">✅ Pagado</span>';
        case 'pendiente':
            return '<span class="badge badge-warning">⏳ Pendiente</span>';
        case 'vencida':
            return '<span class="badge badge-danger">⚠️ Vencido</span>';
        case 'cancelada':
            return '<span class="badge badge-secondary">❌ Cancelado</span>';
        default:
            return '<span class="badge">' . h($estado) . '</span>';
    }
}

/* ---------- Estadísticas ---------- */
$stats = [
    'total' => 0,
    'pendientes' => 0,
    'vencidas' => 0,
    'pagadas' => 0,
    'total_monto' => 0
];

try {
    // Total recibos
    $q = $pdo->query("SELECT COUNT(*) FROM facturas");
    $stats['total'] = (int) $q->fetchColumn();

    // Pendientes
    $q = $pdo->query("SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente'");
    $stats['pendientes'] = (int) $q->fetchColumn();

    // Vencidos
    $q = $pdo->query("SELECT COUNT(*) FROM facturas WHERE estado = 'vencida'");
    $stats['vencidas'] = (int) $q->fetchColumn();

    // Pagados
    $q = $pdo->query("SELECT COUNT(*) FROM facturas WHERE estado = 'pagada'");
    $stats['pagadas'] = (int) $q->fetchColumn();

    // Total monto
    $q = $pdo->query("SELECT COALESCE(SUM(monto), 0) FROM facturas");
    $stats['total_monto'] = (float) $q->fetchColumn();

} catch (Exception $e) {
    // Si hay error en stats, continuamos igual
}

/* ---------- Filtros ---------- */
$filtros = [
    'estado' => $_GET['estado'] ?? '',
    'cliente' => $_GET['cliente'] ?? '',
    'mes' => $_GET['mes'] ?? ''
];

/* ---------- Consulta de recibos (usa tabla facturas) ---------- */
$sql = "
SELECT 
  f.id_factura as id_recibo,
  f.monto,
  f.fecha_emision,
  f.fecha_vencimiento,
  f.estado,
  f.periodo_pagado,
  c.id_contrato,
  cl.id_cliente,
  cl.nombre_completo as cliente_nombre,
  cl.codigo_cliente,
  cl.email as cliente_email,
  s.nombre_servicio,
  COALESCE(SUM(p.monto_pagado), 0) as total_pagado
FROM facturas f
JOIN contratos c ON f.id_contrato = c.id_contrato
JOIN clientes cl ON c.id_cliente = cl.id_cliente
JOIN servicios s ON c.id_servicio = s.id_servicio
LEFT JOIN pagos p ON f.id_factura = p.id_factura
WHERE 1=1
";

$params = [];

// Aplicar filtros
if (!empty($filtros['estado'])) {
    $sql .= " AND f.estado = ?";
    $params[] = $filtros['estado'];
}

if (!empty($filtros['cliente'])) {
    $sql .= " AND (cl.nombre_completo LIKE ? OR cl.codigo_cliente LIKE ?)";
    $params[] = '%' . $filtros['cliente'] . '%';
    $params[] = '%' . $filtros['cliente'] . '%';
}

if (!empty($filtros['mes'])) {
    $sql .= " AND f.periodo_pagado LIKE ?";
    $params[] = '%' . $filtros['mes'] . '%';
}

$sql .= " GROUP BY 
  f.id_factura, f.monto, f.fecha_emision, f.fecha_vencimiento, f.estado, f.periodo_pagado,
  c.id_contrato, cl.id_cliente, cl.nombre_completo, cl.codigo_cliente, cl.email, s.nombre_servicio
  ORDER BY f.fecha_emision DESC, f.id_factura DESC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $recibos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $recibos = [];
    $error_consulta = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Recibos</title>

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

        .btn-info {
            background: rgba(59, 130, 246, 0.2);
            color: #3b82f6;
            border: 1px solid rgba(59, 130, 246, 0.4);
        }

        .btn:disabled {
            opacity: .55;
            cursor: not-allowed
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
            grid-template-columns: 1fr;
            gap: 12px;
            align-items: start;
        }

        .card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 14px;
            padding: 15px;
        }

        .card h3 {
            margin: 0 0 12px;
            font-size: 1.2rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }

        .stat-card {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 5px 0;
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .filtros-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
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

        .vencida {
            color: #ff6b6b;
            font-weight: bold;
        }

        .proxima {
            color: #ffd93d;
            font-weight: bold;
        }

        .proximo-pago {
            font-size: 0.8rem;
        }

        .proximo-cercano {
            color: #ff6b6b;
            font-weight: bold;
        }

        .proximo-normal {
            color: #51cf66;
        }

        .proximo-lejano {
            color: #ffd93d;
        }
    </style>
</head>

<body class="bg">
    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700;letter-spacing:.3px">AYONET · Recibos</div>
                    <small style="color:#cfe1ff">Sesión de: <?= h($ME_NAME) ?></small>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
                <a class="btn primary" href="generar_recibos.php"><i class="fa-solid fa-plus"></i> Generar Recibos</a>
                <a class="btn info" href="proximos_pagos.php"><i class="fa-solid fa-calendar-days"></i> Próximos
                    Pagos</a>
                <span class="pill">COBROS</span>
            </div>
        </header>

        <section class="panel">
            <div class="grid">
                <!-- Estadísticas -->
                <div class="card">
                    <h3>Estadísticas de Recibos</h3>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label">Total Recibos</div>
                            <div class="stat-number"><?= $stats['total'] ?></div>
                            <small style="color: var(--muted)">General</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Pendientes</div>
                            <div class="stat-number"><?= $stats['pendientes'] ?></div>
                            <small style="color: var(--muted)">Por cobrar</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Vencidos</div>
                            <div class="stat-number"><?= $stats['vencidas'] ?></div>
                            <small style="color: var(--muted)">Atención urgente</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Pagados</div>
                            <div class="stat-number"><?= $stats['pagadas'] ?></div>
                            <small style="color: var(--muted)">Completados</small>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Monto Total</div>
                            <div class="stat-number"><?= formatoMoneda($stats['total_monto']) ?></div>
                            <small style="color: var(--muted)">Valor en recibos</small>
                        </div>
                    </div>
                </div>

                <!-- Filtros -->
                <div class="card">
                    <h3>Filtros de Búsqueda</h3>
                    <form method="GET" class="filtros-grid">
                        <div>
                            <label class="lab">Estado</label>
                            <select name="estado" class="ctrl">
                                <option value="">Todos los estados</option>
                                <option value="pendiente" <?= $filtros['estado'] == 'pendiente' ? 'selected' : '' ?>>
                                    Pendiente</option>
                                <option value="pagada" <?= $filtros['estado'] == 'pagada' ? 'selected' : '' ?>>Pagado
                                </option>
                                <option value="vencida" <?= $filtros['estado'] == 'vencida' ? 'selected' : '' ?>>Vencido
                                </option>
                                <option value="cancelada" <?= $filtros['estado'] == 'cancelada' ? 'selected' : '' ?>>
                                    Cancelado</option>
                            </select>
                        </div>

                        <div>
                            <label class="lab">Cliente</label>
                            <input type="text" name="cliente" class="ctrl" value="<?= h($filtros['cliente']) ?>"
                                placeholder="Nombre o código">
                        </div>

                        <div>
                            <label class="lab">Mes y Año</label>
                            <input type="month" name="mes" class="ctrl" value="<?= h($filtros['mes']) ?>">
                        </div>

                        <div style="display: flex; align-items: end; gap: 8px;">
                            <button type="submit" class="btn primary">
                                <i class="fa-solid fa-search"></i> Buscar
                            </button>
                            <a href="recibos.php" class="btn ghost">
                                <i class="fa-solid fa-refresh"></i> Limpiar
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Listado de Recibos -->
                <div class="card">
                    <h3>Listado de Recibos</h3>
                    <div class="table-container">
                        <table id="tablaRecibos" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>ID Recibo</th>
                                    <th>Cliente</th>
                                    <th>Servicio</th>
                                    <th>Monto</th>
                                    <th>Emisión</th>
                                    <th>Vencimiento</th>
                                    <th>Periodo</th>
                                    <th>Estado</th>
                                    <th>Próximo Pago</th>
                                    <th>Pagado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recibos as $recibo):
                                    // Calcular días para vencimiento
                                    $vencimiento = strtotime($recibo['fecha_vencimiento']);
                                    $hoy = strtotime('today');
                                    $dias_restantes = ($vencimiento - $hoy) / (60 * 60 * 24);

                                    // Calcular próximo pago
                                    $proximo_pago = date('Y-m-d', strtotime($recibo['fecha_emision'] . ' +1 month'));
                                    $dias_proximo = (strtotime($proximo_pago) - strtotime('today')) / (60 * 60 * 24);
                                    ?>
                                    <tr>
                                        <td><strong>#<?= $recibo['id_recibo'] ?></strong></td>
                                        <td>
                                            <div><strong><?= h($recibo['codigo_cliente']) ?></strong></div>
                                            <small style="color: var(--muted)"><?= h($recibo['cliente_nombre']) ?></small>
                                        </td>
                                        <td><?= h($recibo['nombre_servicio']) ?></td>
                                        <td><strong><?= formatoMoneda($recibo['monto']) ?></strong></td>
                                        <td><?= date('d/m/Y', strtotime($recibo['fecha_emision'])) ?></td>
                                        <td>
                                            <div><?= date('d/m/Y', $vencimiento) ?></div>
                                            <?php if ($dias_restantes < 0 && $recibo['estado'] == 'pendiente'): ?>
                                                <small class="vencida">Vencido hace <?= abs($dias_restantes) ?> días</small>
                                            <?php elseif ($dias_restantes >= 0 && $dias_restantes <= 3 && $recibo['estado'] == 'pendiente'): ?>
                                                <small class="proxima">Vence en <?= $dias_restantes ?> días</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($recibo['periodo_pagado']): ?>
                                                <span class="badge"
                                                    style="background: rgba(255,255,255,0.1);"><?= h($recibo['periodo_pagado']) ?></span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= estadoBadge($recibo['estado']) ?></td>
                                        <td>
                                            <?php if (!empty($recibo['fecha_emision'])): ?>
                                                <div><?= date('d/m/Y', strtotime($proximo_pago)) ?></div>
                                                <?php if ($recibo['estado'] == 'pagada' && $dias_proximo > 0): ?>
                                                    <?php if ($dias_proximo <= 7): ?>
                                                        <small class="proximo-pago proximo-cercano">Próximo en <?= $dias_proximo ?>
                                                            días</small>
                                                    <?php elseif ($dias_proximo <= 15): ?>
                                                        <small class="proximo-pago proximo-lejano">En <?= $dias_proximo ?> días</small>
                                                    <?php else: ?>
                                                        <small class="proximo-pago proximo-normal">En <?= $dias_proximo ?> días</small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($recibo['total_pagado'] > 0): ?>
                                                <span style="color: #22c55e;">
                                                    <i class="fa-solid fa-check-circle"></i>
                                                    <?= formatoMoneda($recibo['total_pagado']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: var(--muted);">$0.00</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="actions">
                                            <button class="btn ghost btn-small"
                                                onclick="verDetalle(<?= $recibo['id_recibo'] ?>)">
                                                <i class="fa-regular fa-eye"></i> Ver
                                            </button>
                                            <?php if ($recibo['estado'] == 'pendiente'): ?>
                                                <button class="btn btn-success btn-small"
                                                    onclick="window.location.href='pagos.php?factura=<?= $recibo['id_recibo'] ?>'">
                                                    <i class="fa-solid fa-money-bill-wave"></i> Pagar
                                                </button>
                                            <?php endif; ?>
                                            <button class="btn btn-info btn-small"
                                                onclick="imprimirRecibo(<?= $recibo['id_recibo'] ?>)">
                                                <i class="fa-solid fa-print"></i> PDF
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

    <script>
        $(function () {
            $('#tablaRecibos').DataTable({
                dom: '<"top"lf>rt<"bottom"ip><"clear">',
                searching: true,
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
                order: [[4, 'desc']],
                responsive: false,
                autoWidth: false,
                language: {
                    decimal: '',
                    emptyTable: 'No hay recibos registrados',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ recibos',
                    infoEmpty: 'Mostrando 0 a 0 de 0 recibos',
                    infoFiltered: '(filtrado de _MAX_ recibos totales)',
                    lengthMenu: 'Mostrar _MENU_ recibos',
                    loadingRecords: 'Cargando...',
                    processing: 'Procesando...',
                    search: 'Buscar:',
                    zeroRecords: 'No se encontraron recibos coincidentes',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });
        });

        function verDetalle(idRecibo) {
            window.location.href = 'detalle_recibo.php?id=' + idRecibo;
        }

        function imprimirRecibo(idRecibo) {
            window.open('imprimir_recibo.php?id=' + idRecibo, '_blank');
        }
    </script>
</body>

</html>