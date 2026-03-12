<?php
/* AYONET · Impresión de Contratos (PDF) */
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../login.php');
    exit;
}

$id_contrato = (int) ($_GET['id'] ?? 0);

if ($id_contrato <= 0) {
    die('ID de contrato inválido');
}

// Obtener datos completos del contrato
$q = $pdo->prepare("
    SELECT 
        c.*,
        cl.nombre_completo as nombre_cliente,
        cl.email as email_cliente,
        cl.telefono as telefono_cliente,
        cl.codigo_cliente,
        s.nombre_servicio,
        s.descripcion as descripcion_servicio,
        s.precio_base,
        d.calle, d.numero_exterior, d.numero_interior, d.colonia, d.referencia,
        u.nombre_completo as nombre_usuario_creador
    FROM public.contratos c
    JOIN public.clientes cl ON cl.id_cliente = c.id_cliente
    JOIN public.servicios s ON s.id_servicio = c.id_servicio
    LEFT JOIN public.direcciones d ON d.id_cliente = cl.id_cliente AND d.es_principal = true
    LEFT JOIN public.usuarios u ON u.id_usuario = cl.id_usuario
    WHERE c.id_contrato = :id LIMIT 1
");
$q->execute([':id' => $id_contrato]);
$contrato = $q->fetch(PDO::FETCH_ASSOC);

if (!$contrato) {
    die('Contrato no encontrado');
}

// Para PDF necesitarías una librería como TCPDF o FPDF
// Por ahora mostramos una vista HTML que se puede imprimir
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>Contrato AYONET - <?= $contrato['codigo_cliente'] ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .section {
            margin-bottom: 20px;
        }

        .section-title {
            background: #f0f0f0;
            padding: 5px;
            font-weight: bold;
        }

        .info-row {
            display: flex;
            margin-bottom: 5px;
        }

        .info-label {
            width: 150px;
            font-weight: bold;
        }

        .signature-area {
            margin-top: 50px;
            border-top: 1px solid #333;
            padding-top: 20px;
        }

        .signature {
            display: inline-block;
            width: 45%;
            text-align: center;
        }

        @media print {
            .no-print {
                display: none;
            }

            body {
                margin: 0;
            }
        }
    </style>
</head>

<body>
    <button class="no-print" onclick="window.print()" style="padding: 10px; margin-bottom: 20px;">🖨️ Imprimir</button>

    <div class="header">
        <h1>CONTRATO DE SERVICIO AYONET</h1>
        <h2>No: AYO-<?= str_pad($contrato['id_contrato'], 6, '0', STR_PAD_LEFT) ?></h2>
    </div>

    <div class="section">
        <div class="section-title">INFORMACIÓN DEL CLIENTE</div>
        <div class="info-row">
            <div class="info-label">Nombre:</div>
            <div><?= htmlspecialchars($contrato['nombre_cliente']) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Código:</div>
            <div><?= htmlspecialchars($contrato['codigo_cliente']) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Email:</div>
            <div><?= htmlspecialchars($contrato['email_cliente']) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Teléfono:</div>
            <div><?= htmlspecialchars($contrato['telefono_cliente'] ?: 'No proporcionado') ?></div>
        </div>
        <?php if ($contrato['calle']): ?>
            <div class="info-row">
                <div class="info-label">Dirección:</div>
                <div>
                    <?= htmlspecialchars($contrato['calle']) ?>
                    <?= htmlspecialchars($contrato['numero_exterior']) ?>
                    <?= $contrato['numero_interior'] ? 'Int. ' . htmlspecialchars($contrato['numero_interior']) : '' ?>
                    , <?= htmlspecialchars($contrato['colonia']) ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="section">
        <div class="section-title">INFORMACIÓN DEL SERVICIO</div>
        <div class="info-row">
            <div class="info-label">Servicio:</div>
            <div><?= htmlspecialchars($contrato['nombre_servicio']) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Descripción:</div>
            <div><?= htmlspecialchars($contrato['descripcion_servicio'] ?: 'Servicio de internet') ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Monto Mensual:</div>
            <div>$<?= number_format($contrato['monto_mensual'], 2) ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Fecha Inicio:</div>
            <div><?= $contrato['fecha_inicio_contrato'] ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Fecha Fin:</div>
            <div><?= $contrato['fecha_fin_contrato'] ?: 'Indefinido' ?></div>
        </div>
        <div class="info-row">
            <div class="info-label">Estado:</div>
            <div><?= htmlspecialchars($contrato['estado']) ?></div>
        </div>
    </div>

    <div class="section">
        <div class="section-title">TÉRMINOS Y CONDICIONES</div>
        <ol>
            <li>El presente contrato tiene vigencia desde <?= $contrato['fecha_inicio_contrato'] ?> hasta
                <?= $contrato['fecha_fin_contrato'] ?: 'que sea cancelado por alguna de las partes' ?>.
            </li>
            <li>El cliente se compromete al pago puntual del servicio mensual.</li>
            <li>AYONET garantiza una disponibilidad del servicio del 99.5% mensual.</li>
            <li>Cualquier incidencia será atendida en un máximo de 24 horas hábiles.</li>
            <li>El cliente puede cancelar el servicio con 15 días de anticipación.</li>
        </ol>
    </div>

    <div class="signature-area">
        <div class="signature">
            <p>_________________________</p>
            <p><strong>FIRMA DEL CLIENTE</strong></p>
            <p><?= htmlspecialchars($contrato['nombre_cliente']) ?></p>
        </div>
        <div class="signature">
            <p>_________________________</p>
            <p><strong>FIRMA DE AYONET</strong></p>
            <p><?= htmlspecialchars($contrato['nombre_usuario_creador'] ?: 'Representante AYONET') ?></p>
        </div>
    </div>

    <div class="section" style="margin-top: 30px; font-size: 12px; color: #666;">
        <p>Contrato generado el: <?= date('d/m/Y H:i:s') ?></p>
        <p>ID Contrato: <?= $contrato['id_contrato'] ?></p>
    </div>
</body>

</html>