<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

$id_recibo = (int) ($_GET['id_recibo'] ?? 0);
$ME_ID = (int) ($_SESSION['id_usuario'] ?? 0);

if (!$id_recibo) {
    header("Location: recibos.php");
    exit;
}

// Obtener datos del recibo
$sql = "
SELECT 
    f.*,
    cl.nombre_completo as cliente_nombre,
    cl.codigo_cliente,
    s.nombre_servicio,
    COALESCE(SUM(p.monto_pagado), 0) as total_pagado
FROM facturas f
JOIN contratos c ON f.id_contrato = c.id_contrato
JOIN clientes cl ON c.id_cliente = cl.id_cliente
JOIN servicios s ON c.id_servicio = s.id_servicio
LEFT JOIN pagos p ON f.id_factura = p.id_factura
WHERE f.id_factura = ?
GROUP BY f.id_factura, cl.nombre_completo, cl.codigo_cliente, s.nombre_servicio
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

// Procesar pago
$mensaje = '';
$tipo_mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $monto_pagado = (float) ($_POST['monto_pagado'] ?? 0);
    $metodo_pago = trim($_POST['metodo_pago'] ?? '');
    $folio_referencia = trim($_POST['folio_referencia'] ?? '');
    $fecha_pago = trim($_POST['fecha_pago'] ?? date('Y-m-d H:i:s'));

    if ($monto_pagado > 0 && !empty($metodo_pago)) {
        try {
            $pdo->beginTransaction();

            // Registrar pago
            $sql_pago = "
            INSERT INTO pagos (id_factura, monto_pagado, fecha_pago, metodo_pago, folio_referencia, id_usuario_registro)
            VALUES (?, ?, ?, ?, ?, ?)
            ";

            $stmt = $pdo->prepare($sql_pago);
            $stmt->execute([
                $id_recibo,
                $monto_pagado,
                $fecha_pago,
                $metodo_pago,
                $folio_referencia,
                $ME_ID
            ]);

            // Calcular nuevo total pagado
            $sql_total = "SELECT COALESCE(SUM(monto_pagado), 0) FROM pagos WHERE id_factura = ?";
            $stmt = $pdo->prepare($sql_total);
            $stmt->execute([$id_recibo]);
            $nuevo_total_pagado = (float) $stmt->fetchColumn();

            // Actualizar estado del recibo si está completamente pagado
            if ($nuevo_total_pagado >= $recibo['monto']) {
                $sql_actualizar = "UPDATE facturas SET estado = 'pagada' WHERE id_factura = ?";
                $stmt = $pdo->prepare($sql_actualizar);
                $stmt->execute([$id_recibo]);
            }

            $pdo->commit();

            $mensaje = "Pago registrado exitosamente";
            $tipo_mensaje = 'success';

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensaje = "Error al registrar pago: " . $e->getMessage();
            $tipo_mensaje = 'error';
        }
    } else {
        $mensaje = "Complete todos los campos requeridos";
        $tipo_mensaje = 'error';
    }
}

// Calcular saldo pendiente
$saldo_pendiente = $recibo['monto'] - $recibo['total_pagado'];
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AYONET · Registrar Pago</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
            max-width: 600px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #1e293b, #334155);
            border-radius: 15px;
        }

        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--neon1), var(--neon3));
            color: white;
        }

        .btn-secondary {
            background: #475569;
            color: white;
        }

        .form-card {
            background: #1e293b;
            padding: 30px;
            border-radius: 15px;
            border-left: 4px solid var(--neon1);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #0f172a;
            color: #fff;
            font-size: 16px;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--neon1);
            box-shadow: 0 0 0 2px rgba(0, 212, 255, 0.2);
        }

        .info-box {
            background: #334155;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Registrar Pago</h1>
            <p>Recibo #<?= $recibo['id_factura'] ?> - <?= htmlspecialchars($recibo['cliente_nombre']) ?></p>
        </div>

        <?php if ($mensaje): ?>
            <script>
                Swal.fire({
                    icon: '<?= $tipo_mensaje ?>',
                    title: '<?= $tipo_mensaje === 'success' ? 'Éxito' : 'Error' ?>',
                    text: '<?= $mensaje ?>',
                    background: '#1e293b',
                    color: '#fff'
                }).then(() => {
                    <?php if ($tipo_mensaje === 'success'): ?>
                        window.location.href = 'detalle_recibo.php?id=<?= $id_recibo ?>';
                    <?php endif; ?>
                });
            </script>
        <?php endif; ?>

        <div class="form-card">
            <!-- Información del recibo -->
            <div class="info-box">
                <div class="info-row">
                    <span>Cliente:</span>
                    <span><strong><?= htmlspecialchars($recibo['cliente_nombre']) ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Servicio:</span>
                    <span><?= htmlspecialchars($recibo['nombre_servicio']) ?></span>
                </div>
                <div class="info-row">
                    <span>Monto Total:</span>
                    <span><strong>$<?= number_format($recibo['monto'], 2) ?></strong></span>
                </div>
                <div class="info-row">
                    <span>Pagado:</span>
                    <span style="color: #22c55e;">$<?= number_format($recibo['total_pagado'], 2) ?></span>
                </div>
                <div class="info-row">
                    <span>Saldo Pendiente:</span>
                    <span style="color: #ef4444;"><strong>$<?= number_format($saldo_pendiente, 2) ?></strong></span>
                </div>
            </div>

            <form method="POST">
                <div class="form-group">
                    <label for="monto_pagado">Monto a Pagar *</label>
                    <input type="number" step="0.01" min="0.01" max="<?= $saldo_pendiente ?>" id="monto_pagado"
                        name="monto_pagado" class="form-control" value="<?= $saldo_pendiente ?>" required>
                    <small style="color: var(--muted);">Máximo: $<?= number_format($saldo_pendiente, 2) ?></small>
                </div>

                <div class="form-group">
                    <label for="metodo_pago">Método de Pago *</label>
                    <select id="metodo_pago" name="metodo_pago" class="form-control" required>
                        <option value="">Seleccione método</option>
                        <option value="efectivo">Efectivo</option>
                        <option value="transferencia">Transferencia</option>
                        <option value="tarjeta_credito">Tarjeta de Crédito</option>
                        <option value="tarjeta_debito">Tarjeta de Débito</option>
                        <option value="cheque">Cheque</option>
                        <option value="deposito">Depósito</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="folio_referencia">Folio/Referencia</label>
                    <input type="text" id="folio_referencia" name="folio_referencia" class="form-control"
                        placeholder="Número de transacción, folio, etc.">
                </div>

                <div class="form-group">
                    <label for="fecha_pago">Fecha de Pago</label>
                    <input type="datetime-local" id="fecha_pago" name="fecha_pago" class="form-control"
                        value="<?= date('Y-m-d\TH:i') ?>">
                </div>

                <div style="display: flex; gap: 10px; margin-top: 30px;">
                    <a href="recibos.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Cancelar
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i> Registrar Pago
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Validar monto máximo
        document.getElementById('monto_pagado').addEventListener('input', function () {
            const max = <?= $saldo_pendiente ?>;
            const valor = parseFloat(this.value);

            if (valor > max) {
                this.value = max;
                Swal.fire({
                    icon: 'warning',
                    title: 'Monto excedido',
                    text: 'El monto no puede ser mayor al saldo pendiente',
                    background: '#1e293b',
                    color: '#fff'
                });
            }
        });
    </script>
</body>

</html>