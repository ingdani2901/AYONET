<?php
/* AYONET · Próximos Pagos */
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
    <title>AYONET · Próximos Pagos</title>
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
            max-width: 1400px;
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

        .pago-card {
            background: rgba(255, 255, 255, .08);
            border-radius: 12px;
            padding: 15px;
            margin: 10px;
            border-left: 4px solid;
        }

        .pagos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 15px;
        }

        .urgente {
            border-left-color: #ef4444;
            background: rgba(239, 68, 68, 0.1);
        }

        .proximo {
            border-left-color: #f59e0b;
            background: rgba(245, 158, 11, 0.1);
        }

        .normal {
            border-left-color: #10b981;
            background: rgba(16, 185, 129, 0.1);
        }

        .stat-card {
            background: rgba(255, 255, 255, .08);
            border-radius: 12px;
            padding: 15px;
            margin: 10px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <div class="panel">
            <h1 style="text-align: center; margin-bottom: 30px;">📅 PRÓXIMOS PAGOS</h1>

            <?php
            try {
                // ESTADÍSTICAS RÁPIDAS
                $sql_stats = "
                    SELECT 
                        COUNT(*) as total_clientes,
                        COALESCE(SUM(c.monto_mensual), 0) as ingreso_mensual,
                        (SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente' AND fecha_vencimiento < NOW()) as vencidos,
                        (SELECT COUNT(*) FROM facturas WHERE estado = 'pendiente' AND fecha_vencimiento >= NOW()) as por_vencer
                    FROM contratos c
                    JOIN clientes cl ON c.id_cliente = cl.id_cliente
                    WHERE c.estado = 'activo' AND cl.eliminado = false
                ";
                $stats = $pdo->query($sql_stats)->fetch(PDO::FETCH_ASSOC);

                echo "<div class='card'>";
                echo "<h3>📊 RESUMEN DE COBROS</h3>";
                echo "<div class='stats-grid'>";
                echo "<div class='stat-card'><h3>{$stats['total_clientes']}</h3><p>Clientes Activos</p></div>";
                echo "<div class='stat-card'><h3>" . formatoMoneda($stats['ingreso_mensual']) . "</h3><p>Ingreso Mensual</p></div>";
                echo "<div class='stat-card'><h3>{$stats['vencidos']}</h3><p>Recibos Vencidos</p></div>";
                echo "<div class='stat-card'><h3>{$stats['por_vencer']}</h3><p>Por Vencer</p></div>";
                echo "</div>";
                echo "</div>";

                // PRÓXIMOS PAGOS DETALLADOS
                $sql_proximos = "
                    SELECT 
                        cl.codigo_cliente,
                        cl.nombre_completo,
                        s.nombre_servicio,
                        c.monto_mensual,
                        c.fecha_inicio_contrato,
                        -- Calcular próximo pago (primer día del próximo mes)
                        (DATE_TRUNC('MONTH', NOW()) + INTERVAL '1 MONTH')::date as proximo_pago,
                        -- Calcular días restantes
                        EXTRACT(DAYS FROM (DATE_TRUNC('MONTH', NOW()) + INTERVAL '1 MONTH')::date - NOW()) as dias_restantes,
                        -- Verificar si ya tiene recibo generado
                        (SELECT COUNT(*) FROM facturas f 
                         WHERE f.id_contrato = c.id_contrato 
                         AND f.periodo_pagado = TO_CHAR(DATE_TRUNC('MONTH', NOW()) + INTERVAL '1 MONTH', 'YYYY-MM')
                        ) as recibo_generado
                    FROM contratos c
                    JOIN clientes cl ON c.id_cliente = cl.id_cliente
                    JOIN servicios s ON c.id_servicio = s.id_servicio
                    WHERE c.estado = 'activo'
                    AND cl.eliminado = false
                    ORDER BY dias_restantes ASC, cl.codigo_cliente
                ";

                $proximos_pagos = $pdo->query($sql_proximos)->fetchAll(PDO::FETCH_ASSOC);

                echo "<div class='card'>";
                echo "<h3>🔄 PRÓXIMOS PAGOS DEL PRÓXIMO MES</h3>";

                if (count($proximos_pagos) > 0) {
                    echo "<div class='pagos-grid'>";

                    foreach ($proximos_pagos as $pago) {
                        $clase = '';
                        if ($pago['dias_restantes'] <= 7) {
                            $clase = 'urgente';
                        } elseif ($pago['dias_restantes'] <= 15) {
                            $clase = 'proximo';
                        } else {
                            $clase = 'normal';
                        }

                        echo "<div class='pago-card {$clase}'>";
                        echo "<div style='display: flex; justify-content: space-between; align-items: start;'>";
                        echo "<div>";
                        echo "<h4 style='margin: 0 0 5px 0;'>{$pago['codigo_cliente']}</h4>";
                        echo "<p style='margin: 0; font-size: 0.9em;'>{$pago['nombre_completo']}</p>";
                        echo "</div>";
                        echo "<div style='text-align: right;'>";
                        echo "<div style='font-size: 1.2em; font-weight: bold;'>" . formatoMoneda($pago['monto_mensual']) . "</div>";
                        echo "</div>";
                        echo "</div>";

                        echo "<p style='margin: 8px 0;'><small>{$pago['nombre_servicio']}</small></p>";

                        $fecha_proximo = new DateTime($pago['proximo_pago']);
                        $color_fecha = $pago['dias_restantes'] <= 7 ? '#ef4444' :
                            ($pago['dias_restantes'] <= 15 ? '#f59e0b' : '#10b981');

                        echo "<div style='display: flex; justify-content: space-between; align-items: center; margin-top: 10px;'>";
                        echo "<div>";
                        echo "<div style='font-weight: bold; color: {$color_fecha};'>" . $fecha_proximo->format('d/m/Y') . "</div>";
                        echo "<div style='font-size: 0.8em; color: #cfe1ff;'>" . $pago['dias_restantes'] . " días</div>";
                        echo "</div>";

                        if ($pago['recibo_generado']) {
                            echo "<span style='background: #10b981; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;'>✅ LISTO</span>";
                        } else {
                            echo "<span style='background: #f59e0b; color: white; padding: 4px 8px; border-radius: 12px; font-size: 0.8em;'>⏳ PENDIENTE</span>";
                        }
                        echo "</div>";
                        echo "</div>";
                    }

                    echo "</div>";
                } else {
                    echo "<p style='text-align: center; color: #cfe1ff;'>No hay contratos activos</p>";
                }
                echo "</div>";

                // RECIBOS VENCIDOS (URGENTES)
                $sql_vencidos = "
                    SELECT f.id_factura, f.monto, f.fecha_vencimiento, f.periodo_pagado,
                           cl.codigo_cliente, cl.nombre_completo, s.nombre_servicio,
                           EXTRACT(DAYS FROM NOW() - f.fecha_vencimiento) as dias_vencido
                    FROM facturas f
                    JOIN contratos c ON f.id_contrato = c.id_contrato
                    JOIN clientes cl ON c.id_cliente = cl.id_cliente
                    JOIN servicios s ON c.id_servicio = s.id_servicio
                    WHERE f.estado = 'pendiente'
                    AND f.fecha_vencimiento < NOW()
                    ORDER BY f.fecha_vencimiento ASC
                    LIMIT 10
                ";

                $vencidos = $pdo->query($sql_vencidos)->fetchAll(PDO::FETCH_ASSOC);

                if (count($vencidos) > 0) {
                    echo "<div class='card' style='border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.1);'>";
                    echo "<h3 style='color: #ef4444;'>🚨 RECIBOS VENCIDOS - ATENCIÓN URGENTE</h3>";

                    foreach ($vencidos as $vencido) {
                        echo "<div style='background: rgba(239, 68, 68, 0.2); padding: 10px; margin: 8px 0; border-radius: 8px;'>";
                        echo "<div style='display: flex; justify-content: space-between; align-items: center;'>";
                        echo "<div>";
                        echo "<strong>{$vencido['codigo_cliente']} - {$vencido['nombre_completo']}</strong>";
                        echo "<br><small>{$vencido['nombre_servicio']} - {$vencido['periodo_pagado']}</small>";
                        echo "</div>";
                        echo "<div style='text-align: right;'>";
                        echo "<div style='color: #ef4444; font-weight: bold;'>" . formatoMoneda($vencido['monto']) . "</div>";
                        echo "<div style='font-size: 0.8em;'>Vencido hace " . (int) $vencido['dias_vencido'] . " días</div>";
                        echo "</div>";
                        echo "</div>";
                        echo "</div>";
                    }
                    echo "</div>";
                }

            } catch (Exception $e) {
                echo "<div class='card' style='background: rgba(239, 68, 68, 0.2); border: 2px solid #ef4444;'>";
                echo "<h3 style='color: #ef4444;'>❌ ERROR AL CARGAR DATOS</h3>";
                echo "<p>" . h($e->getMessage()) . "</p>";
                echo "</div>";
            }
            ?>

            <div style="text-align: center; margin-top: 30px;">
                <a href="generar_recibos.php" class="btn primary">🚀 GENERAR RECIBOS</a>
                <a href="recibos.php" class="btn success">📋 VER TODOS LOS RECIBOS</a>
                <a href="menu.php" class="btn" style="background: #6b7280; color: white;">🏠 MENÚ PRINCIPAL</a>
            </div>
        </div>
    </div>
</body>

</html>