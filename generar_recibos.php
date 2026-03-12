<?php
/* AYONET · Generar Recibos Automáticos */
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

// Helper functions
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
function formatoMoneda($monto)
{
    return '$' . number_format((float) $monto, 2);
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Generar Recibos</title>
    <style>
        :root {
            --neon1: #00d4ff;
            --neon2: #6a00ff;
            --neon3: #ff007a;
        }

        body {
            margin: 0;
            font-family: "Poppins", sans-serif;
            color: #fff;
            background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
        }

        .wrap {
            min-height: 100vh;
            padding: 20px;
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 16px;
            backdrop-filter: blur(12px);
            padding: 20px;
            margin: 0 auto;
            max-width: 1200px;
        }

        .card {
            background: rgba(255, 255, 255, .07);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 14px;
            padding: 20px;
            margin: 15px 0;
        }

        .btn {
            border: none;
            border-radius: 10px;
            padding: 12px 20px;
            cursor: pointer;
            font-weight: 700;
            font-size: 16px;
            margin: 5px;
            text-decoration: none;
            display: inline-block;
        }

        .primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
        }

        .success {
            background: #10b981;
            color: white;
        }

        .info {
            background: #3b82f6;
            color: white;
        }

        .stat-card {
            background: rgba(255, 255, 255, .08);
            border-radius: 12px;
            padding: 15px;
            margin: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="panel">
            <h1 style="text-align: center; margin-bottom: 30px;">🚀 GENERAR RECIBOS AUTOMÁTICOS</h1>

            <?php
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                try {
                    $pdo->beginTransaction();
                    $recibos_generados = 0;
                    $clientes_procesados = 0;

                    // 1. OBTENER CONTRATOS ACTIVOS
                    $sql_contratos = "
                        SELECT c.id_contrato, c.id_cliente, c.id_servicio, c.monto_mensual, 
                               c.fecha_inicio_contrato, cl.codigo_cliente, cl.nombre_completo, 
                               s.nombre_servicio
                        FROM contratos c
                        JOIN clientes cl ON c.id_cliente = cl.id_cliente
                        JOIN servicios s ON c.id_servicio = s.id_servicio
                        WHERE c.estado = 'activo' AND cl.eliminado = false
                        ORDER BY c.id_contrato
                    ";
                    $contratos = $pdo->query($sql_contratos)->fetchAll(PDO::FETCH_ASSOC);

                    echo "<div class='card'>";
                    echo "<h3>🔍 Procesando " . count($contratos) . " contratos activos</h3>";

                    foreach ($contratos as $contrato) {
                        $clientes_procesados++;
                        echo "<div style='border: 1px solid #333; padding: 15px; margin: 10px 0; border-radius: 10px; background: rgba(255,255,255,0.05);'>";
                        echo "<h4>📋 {$contrato['codigo_cliente']} - {$contrato['nombre_completo']}</h4>";
                        echo "<p>Servicio: {$contrato['nombre_servicio']} - " . formatoMoneda($contrato['monto_mensual']) . "</p>";
                        echo "<p>Inicio: " . date('d/m/Y', strtotime($contrato['fecha_inicio_contrato'])) . "</p>";

                        // CALCULAR MESES PENDIENTES
                        $fecha_inicio = new DateTime($contrato['fecha_inicio_contrato']);
                        $fecha_actual = new DateTime();
                        $meses_generados = 0;

                        // Generar para los próximos 3 meses también
                        $fecha_fin = (new DateTime())->modify('+3 months');

                        $periodo = new DatePeriod(
                            $fecha_inicio,
                            new DateInterval('P1M'),
                            $fecha_fin
                        );

                        foreach ($periodo as $mes) {
                            $periodo_mes = $mes->format('Y-m');
                            $fecha_emision = $mes->format('Y-m-01');
                            $fecha_vencimiento = $mes->format('Y-m-10');

                            // Solo generar si es mes actual o futuro
                            if ($mes->format('Y-m') <= $fecha_actual->format('Y-m')) {
                                // Verificar si ya existe
                                $sql_verificar = "SELECT COUNT(*) FROM facturas WHERE id_contrato = ? AND periodo_pagado LIKE ?";
                                $stmt_verificar = $pdo->prepare($sql_verificar);
                                $stmt_verificar->execute([$contrato['id_contrato'], $periodo_mes . '%']);
                                $existe = $stmt_verificar->fetchColumn();

                                if (!$existe) {
                                    // GENERAR RECIBO
                                    $sql_insert = "
                                        INSERT INTO facturas (id_contrato, monto, fecha_emision, fecha_vencimiento, estado, periodo_pagado, observaciones)
                                        VALUES (?, ?, ?, ?, 'pendiente', ?, ?)
                                    ";

                                    $observaciones = "Recibo automático - {$contrato['nombre_servicio']} - {$periodo_mes}";

                                    $stmt_insert = $pdo->prepare($sql_insert);
                                    $stmt_insert->execute([
                                        $contrato['id_contrato'],
                                        $contrato['monto_mensual'],
                                        $fecha_emision,
                                        $fecha_vencimiento,
                                        $periodo_mes,
                                        $observaciones
                                    ]);

                                    $meses_generados++;
                                    $recibos_generados++;
                                    echo "<p style='color: #00ff00; margin: 2px 0; padding: 5px; background: rgba(0,255,0,0.1); border-radius: 5px;'>";
                                    echo "✅ {$periodo_mes} - " . formatoMoneda($contrato['monto_mensual']) . "</p>";
                                }
                            }
                        }

                        if ($meses_generados === 0) {
                            echo "<p style='color: #ffff00;'>⚠️ Todos los recibos ya estaban generados</p>";
                        } else {
                            echo "<p><strong>Total: {$meses_generados} recibos generados</strong></p>";
                        }
                        echo "</div>";
                    }

                    $pdo->commit();

                    // ESTADÍSTICAS FINALES
                    echo "</div>";
                    echo "<div class='card' style='background: rgba(16, 185, 129, 0.2); border: 2px solid #10b981;'>";
                    echo "<h2 style='color: #10b981; text-align: center;'>🎉 GENERACIÓN COMPLETADA</h2>";
                    echo "<div class='stats-grid'>";
                    echo "<div class='stat-card'><h3>" . count($contratos) . "</h3><p>Contratos Procesados</p></div>";
                    echo "<div class='stat-card'><h3>{$recibos_generados}</h3><p>Recibos Generados</p></div>";
                    echo "<div class='stat-card'><h3>" . formatoMoneda($recibos_generados * $contratos[0]['monto_mensual'] ?? 0) . "</h3><p>Monto Total</p></div>";
                    echo "</div>";
                    echo "</div>";

                    echo '<div style="text-align: center; margin: 20px 0;">';
                    echo '<a href="recibos.php" class="btn success">📋 VER RECIBOS GENERADOS</a>';
                    echo '<a href="proximos_pagos.php" class="btn info">📅 VER PRÓXIMOS PAGOS</a>';
                    echo '</div>';

                } catch (Exception $e) {
                    $pdo->rollBack();
                    echo "<div class='card' style='background: rgba(239, 68, 68, 0.2); border: 2px solid #ef4444;'>";
                    echo "<h3 style='color: #ef4444;'>❌ ERROR EN LA GENERACIÓN</h3>";
                    echo "<p>" . h($e->getMessage()) . "</p>";
                    echo "</div>";
                }
            } else {
                // MOSTRAR ESTADÍSTICAS INICIALES
                try {
                    $sql_stats = "
                        SELECT COUNT(*) as total_contratos,
                               COALESCE(SUM(c.monto_mensual), 0) as ingreso_mensual,
                               (SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente') as recibos_pendientes
                        FROM contratos c
                        JOIN clientes cl ON c.id_cliente = cl.id_cliente
                        WHERE c.estado = 'activo' AND cl.eliminado = false
                    ";
                    $stats = $pdo->query($sql_stats)->fetch(PDO::FETCH_ASSOC);

                    echo "<div class='card'>";
                    echo "<h3>📊 ESTADO ACTUAL DEL SISTEMA</h3>";
                    echo "<div class='stats-grid'>";
                    echo "<div class='stat-card'><h3>{$stats['total_contratos']}</h3><p>Contratos Activos</p></div>";
                    echo "<div class='stat-card'><h3>" . formatoMoneda($stats['ingreso_mensual']) . "</h3><p>Ingreso Mensual</p></div>";
                    echo "<div class='stat-card'><h3>{$stats['recibos_pendientes']}</h3><p>Recibos Pendientes</p></div>";
                    echo "</div>";
                    echo "</div>";

                    echo "<div class='card' style='text-align: center; background: rgba(255,0,0,0.1); border: 2px solid #ff0000;'>";
                    echo "<h3>🚀 ACCIÓN: GENERAR RECIBOS AUTOMÁTICOS</h3>";
                    echo "<p>Este proceso generará recibos mensuales para todos los contratos activos</p>";
                    echo "<p>Se crearán recibos desde la fecha de inicio de cada contrato hasta el mes actual</p>";
                    echo "<form method='post'>";
                    echo "<button type='submit' class='btn primary' style='font-size: 18px; padding: 15px 30px;'>";
                    echo "🎯 GENERAR RECIBOS AUTOMÁTICAMENTE";
                    echo "</button>";
                    echo "</form>";
                    echo "</div>";

                } catch (Exception $e) {
                    echo "<p style='color: red;'>Error al cargar estadísticas: " . h($e->getMessage()) . "</p>";
                }
            }
            ?>
        </div>
    </div>
</body>

</html>