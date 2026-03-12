<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';

// Verifica rol
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: ../login.php");
    exit;
}

// === SOLUCIÓN MEJORADA: Obtener id_cliente de múltiples formas ===
$id_cliente = null;

// Método 1: Desde la sesión
if (isset($_SESSION['id_cliente']) && !empty($_SESSION['id_cliente'])) {
    $id_cliente = (int) $_SESSION['id_cliente'];
}
// Método 2: Buscar por id_usuario
else if (isset($_SESSION['id_usuario'])) {
    try {
        $stmt = $pdo->prepare("SELECT id_cliente FROM public.clientes WHERE id_usuario = ? AND eliminado = false");
        $stmt->execute([$_SESSION['id_usuario']]);
        $cliente_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente_data && isset($cliente_data['id_cliente'])) {
            $id_cliente = (int) $cliente_data['id_cliente'];
            $_SESSION['id_cliente'] = $id_cliente;
        }
    } catch (Exception $e) {
        error_log("Error buscando cliente por id_usuario: " . $e->getMessage());
    }
}

// Método 3: Si todo falla, usar el ID conocido (para desarrollo)
if (!$id_cliente) {
    $id_cliente = 3009; // ID de José de Jesús Orozco Dueñas
    $_SESSION['id_cliente'] = $id_cliente;
}

// Si aún no tenemos id_cliente, mostrar error amigable
if (!$id_cliente) {
    die("<div style='text-align:center; padding:50px; color:white;'>
        <h2>Error de sesión</h2>
        <p>No se pudo identificar tu cuenta. Por favor, cierra sesión y vuelve a ingresar.</p>
        <a href='../logout.php' style='color:#00d4ff;'>Cerrar sesión</a>
    </div>");
}

// Obtener nombre del cliente
$nombreCliente = 'Cliente';
try {
    $stmt = $pdo->prepare("SELECT nombre_completo FROM public.clientes WHERE id_cliente = ?");
    $stmt->execute([$id_cliente]);
    $cliente_nombre = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($cliente_nombre) {
        $nombreCliente = $cliente_nombre['nombre_completo'];
    }
} catch (Exception $e) {
    error_log("Error obteniendo nombre cliente: " . $e->getMessage());
}

/* === OBTENER INCIDENCIAS === */
$incidencias = [];
try {
    $sql = "
        SELECT
            i.id_incidencia,
            i.titulo,
            i.descripcion,
            i.estado,
            i.prioridad,
            i.fecha_registro,
            i.fecha_cierre,
            i.solucion AS comentarios_cierre,
            s.nombre_servicio
        FROM public.incidencias i
        JOIN public.contratos c ON c.id_contrato = i.id_contrato
        LEFT JOIN public.servicios s ON s.id_servicio = c.id_servicio
        WHERE c.id_cliente = ?
        ORDER BY i.fecha_registro DESC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id_cliente]);
    $incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log("Error obteniendo incidencias: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Mis Reportes</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --neon1: #00d4ff;
            --neon2: #6a00ff;
            --neon3: #ff007a;
            --muted: #cfe1ff;
            --glass: rgba(255, 255, 255, .07);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            color: #fff;
            background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
            min-height: 100vh;
        }

        .wrap {
            padding: 12px;
            display: grid;
            grid-template-rows: 64px 1fr;
            gap: 12px;
            min-height: 100vh;
        }

        .topbar {
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border-radius: 12px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .logo {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%), radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%), #0c1133;
            border: 1px solid rgba(255, 255, 255, .12);
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, .06), rgba(255, 255, 255, .03));
            border-radius: 12px;
            padding: 12px;
        }

        .card {
            background: var(--glass);
            border-radius: 12px;
            padding: 12px;
            margin-bottom: 12px;
        }

        .btn {
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: .9rem;
            text-decoration: none;
            display: inline-block;
        }

        .btn.primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
        }

        .btn.ghost {
            background: rgba(255, 255, 255, .06);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .10);
        }

        .incidencia-card {
            background: rgba(255, 255, 255, .08);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border-left: 4px solid;
        }

        .incidencia-card.abierta {
            border-left-color: #f59e0b;
        }

        .incidencia-card.en_proceso {
            border-left-color: #3b82f6;
        }

        .incidencia-card.resuelta {
            border-left-color: #10b981;
        }

        .incidencia-card.cerrada {
            border-left-color: #6b7280;
        }

        .incidencia-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 10px;
        }

        .incidencia-title {
            font-weight: 600;
            margin: 0;
            font-size: 1rem;
        }

        .incidencia-id {
            color: var(--neon1);
            font-weight: bold;
        }

        .incidencia-estado {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: .75rem;
            font-weight: 600;
        }

        .estado-abierta {
            background: #f59e0b;
            color: #000;
        }

        .estado-en_proceso {
            background: #3b82f6;
            color: #fff;
        }

        .estado-resuelta {
            background: #10b981;
            color: #fff;
        }

        .estado-cerrada {
            background: #6b7280;
            color: #fff;
        }

        .incidencia-descripcion {
            background: rgba(0, 0, 0, .2);
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: .9rem;
            line-height: 1.4;
        }

        .descripcion-label {
            font-weight: 600;
            color: var(--neon1);
            margin-bottom: 5px;
            display: block;
        }

        .incidencia-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            font-size: .85rem;
            color: var(--muted);
        }

        .incidencia-detail {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .incidencia-detail i {
            color: var(--neon1);
            width: 16px;
        }

        .no-incidencias {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
        }

        .no-incidencias i {
            font-size: 4rem;
            margin-bottom: 15px;
            opacity: .5;
        }

        .solucion-tecnico {
            background: rgba(0, 255, 0, 0.1);
            border: 1px solid #10b981;
            padding: 10px;
            border-radius: 8px;
            margin: 10px 0;
            font-size: .9rem;
            line-height: 1.4;
        }

        .solucion-label {
            font-weight: 600;
            color: #10b981;
            margin-bottom: 5px;
            display: block;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700">AYONET · Mis Reportes</div>
                    <small style="color:#cfe1ff">Sesión de:
                        <?php echo htmlspecialchars($nombreCliente, ENT_QUOTES, 'UTF-8'); ?></small>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <a class="btn ghost" href="menu_cliente.php"><i class="fa-solid fa-house"></i> Inicio</a>
                <a class="btn ghost" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
            </div>
        </header>

        <section class="panel">
            <div class="card">
                <h2 style="margin-top:0;"><i class="fa-solid fa-list"></i> Mis Reportes de Servicio</h2>
                <p>Revisa el historial de todos los problemas que has reportado y su estado actual.</p>

                <a href="asistente_incidencias.php" class="btn primary">
                    <i class="fa-solid fa-plus"></i> Reportar Nuevo Problema
                </a>
            </div>

            <div class="card">
                <h3 style="margin-top:0;">📋 Tus Reportes</h3>

                <?php if (empty($incidencias)): ?>
                    <div class="no-incidencias">
                        <i class="fa-solid fa-inbox"></i>
                        <h4>No se encontraron reportes</h4>
                        <p>No hay incidencias registradas para tu cuenta.</p>
                        <a href="asistente_incidencias.php" class="btn primary" style="margin-top: 15px;">
                            <i class="fa-solid fa-plus"></i> Reportar un problema
                        </a>
                    </div>
                <?php else: ?>
                    <div id="lista-incidencias">
                        <?php foreach ($incidencias as $incidencia): ?>
                            <div
                                class="incidencia-card <?php echo htmlspecialchars($incidencia['estado'] ?? 'abierta', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="incidencia-header">
                                    <div>
                                        <div class="incidencia-title">
                                            <span class="incidencia-id">Reporte
                                                #<?php echo (int) $incidencia['id_incidencia']; ?></span>
                                            - <?php echo htmlspecialchars($incidencia['titulo'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                    </div>
                                    <span
                                        class="incidencia-estado estado-<?php echo htmlspecialchars($incidencia['estado'] ?? 'cerrada', ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php
                                        $estados = [
                                            'abierta' => '🟡 Abierta',
                                            'en_proceso' => '🔵 En Proceso',
                                            'resuelta' => '🟢 Resuelta',
                                            'cerrada' => '⚫ Cerrada'
                                        ];
                                        echo $estados[strtolower($incidencia['estado'] ?? '')] ?? '⚫ Cerrada';
                                        ?>
                                    </span>
                                </div>

                                <div class="incidencia-descripcion">
                                    <span class="descripcion-label">📝 Lo que reportaste:</span>
                                    <?php echo nl2br(htmlspecialchars($incidencia['descripcion'] ?? '', ENT_QUOTES, 'UTF-8')); ?>
                                </div>

                                <?php if (!empty($incidencia['comentarios_cierre'])): ?>
                                    <div class="solucion-tecnico">
                                        <span class="solucion-label">✅ Solución del técnico:</span>
                                        <?php echo nl2br(htmlspecialchars($incidencia['comentarios_cierre'], ENT_QUOTES, 'UTF-8')); ?>
                                    </div>
                                <?php endif; ?>

                                <div class="incidencia-details">
                                    <div class="incidencia-detail">
                                        <i class="fa-solid fa-wifi"></i>
                                        <span><?php echo htmlspecialchars($incidencia['nombre_servicio'] ?? 'Servicio general', ENT_QUOTES, 'UTF-8'); ?></span>
                                    </div>

                                    <div class="incidencia-detail">
                                        <i class="fa-solid fa-calendar"></i>
                                        <span>Reportado:
                                            <?php echo date('d/m/Y H:i', strtotime($incidencia['fecha_registro'])); ?></span>
                                    </div>

                                    <?php if (!empty($incidencia['fecha_cierre'])): ?>
                                        <div class="incidencia-detail">
                                            <i class="fa-solid fa-calendar-check"></i>
                                            <span>Resuelto:
                                                <?php echo date('d/m/Y H:i', strtotime($incidencia['fecha_cierre'])); ?></span>
                                        </div>
                                    <?php endif; ?>

                                    <div class="incidencia-detail">
                                        <i class="fa-solid fa-flag"></i>
                                        <span>
                                            <?php
                                            $prioridades = [
                                                '3' => '🔴 Urgente',
                                                '2' => '🟡 Normal',
                                                '1' => '🟢 Baja'
                                            ];
                                            $p = (string) ($incidencia['prioridad'] ?? '2');
                                            echo $prioridades[$p] ?? '🟡 Normal';
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</body>

</html>