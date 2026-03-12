<?php
session_start();
require_once __DIR__ . '/../db.php';

// === GUARDIÁN DE SEGURIDAD ===
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'tecnico') {
    header("Location: ../login.php");
    exit;
}

$nombreTecnico = htmlspecialchars($_SESSION['welcome_name'] ?? 'Técnico');
$id_tecnico = $_SESSION['id_tecnico'] ?? null;
$id_usuario = $_SESSION['id_usuario'] ?? null;

// === CSRF ===
if (empty($_SESSION['csrf_tecnico'])) {
    $_SESSION['csrf_tecnico'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_tecnico'];

// === PROCESAR ACTUALIZACIÓN DE INCIDENCIA ===
$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
    $id_incidencia = (int) ($_POST['id_incidencia'] ?? 0);
    $estado = trim($_POST['estado'] ?? '');
    $solucion = trim($_POST['solucion'] ?? '');
    $observaciones = trim($_POST['observaciones'] ?? '');

    if ($id_incidencia > 0 && in_array($estado, ['en_proceso', 'resuelta', 'cerrada'])) {
        try {
            // Verificar que la incidencia pertenece a este técnico
            $stmt = $pdo->prepare("SELECT id_tecnico FROM incidencias WHERE id_incidencia = ?");
            $stmt->execute([$id_incidencia]);
            $incidencia = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($incidencia && $incidencia['id_tecnico'] == $id_tecnico) {
                // Actualizar la incidencia
                $stmt = $pdo->prepare("
                    UPDATE incidencias 
                    SET estado = ?, solucion = ?, observaciones_tecnico = ?, updated_at = NOW()
                    WHERE id_incidencia = ?
                ");
                $stmt->execute([$estado, $solucion, $observaciones, $id_incidencia]);

                // 🔔 NOTIFICAR AL ADMINISTRADOR
                require_once __DIR__ . '/../functions/notificaciones.php';

                $estado_texto = [
                    'en_proceso' => 'EN PROCESO',
                    'resuelta' => 'RESUELTA',
                    'cerrada' => 'CERRADA'
                ];

                $mensajeAdmin = "🛠️ INCIDENCIA ACTUALIZADA\n";
                $mensajeAdmin .= "Técnico: $nombreTecnico\n";
                $mensajeAdmin .= "Incidencia: #$id_incidencia\n";
                $mensajeAdmin .= "Nuevo Estado: " . $estado_texto[$estado] . "\n";
                if (!empty($solucion)) {
                    $mensajeAdmin .= "Solución: " . substr($solucion, 0, 80) . "...";
                }

                $notificados = notificarAdministradores($pdo, $mensajeAdmin, $id_incidencia, 'media');

                $flash = ['ok', 'Incidencia Actualizada', "Se actualizó el estado de la incidencia. $notificados administradores notificados."];

                // Limpiar CSRF después de uso exitoso
                unset($_SESSION['csrf_tecnico']);
            } else {
                $flash = ['error', 'Error', 'No tienes permiso para modificar esta incidencia.'];
            }
        } catch (Throwable $e) {
            $flash = ['error', 'Error de base de datos', $e->getMessage()];
        }
    } else {
        $flash = ['error', 'Datos inválidos', 'Por favor, completa todos los campos correctamente.'];
    }
}

// === OBTENER INCIDENCIAS DEL TÉCNICO ===
$incidencias = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            i.id_incidencia,
            i.titulo,
            i.descripcion,
            i.fecha_registro,
            i.estado,
            i.prioridad,
            i.solucion,
            c.nombre_completo as cliente_nombre,
            c.telefono as cliente_telefono,
            s.nombre_servicio,
            d.calle,
            d.colonia,
            d.referencia
        FROM incidencias i
        JOIN clientes c ON i.id_cliente = c.id_cliente
        JOIN contratos ct ON i.id_contrato = ct.id_contrato
        JOIN servicios s ON ct.id_servicio = s.id_servicio
        LEFT JOIN direcciones d ON c.id_cliente = d.id_cliente AND d.es_principal = true
        WHERE i.id_tecnico = ?
        ORDER BY 
            CASE i.prioridad 
                WHEN '3' THEN 1
                WHEN '2' THEN 2
                ELSE 3
            END,
            i.fecha_registro DESC
    ");
    $stmt->execute([$id_tecnico]);
    $incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error obteniendo incidencias: " . $e->getMessage());
    $incidencias = [];
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AYONET - Mis Incidencias</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        }

        .incidencias-grid {
            display: grid;
            gap: 20px;
        }

        .incidencia-card {
            background: var(--glass);
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 16px;
            padding: 20px;
            transition: all 0.3s ease;
        }

        .incidencia-card:hover {
            border-color: var(--neon1);
            box-shadow: 0 5px 20px rgba(0, 212, 255, 0.1);
        }

        .incidencia-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }

        .incidencia-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--neon1);
        }

        .incidencia-meta {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .badge {
            padding: 4px 8px;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .badge.urgente {
            background: rgba(255, 71, 87, 0.2);
            color: #ff4757;
            border: 1px solid rgba(255, 71, 87, 0.3);
        }

        .badge.alta {
            background: rgba(255, 165, 0, 0.2);
            color: #ffa500;
            border: 1px solid rgba(255, 165, 0, 0.3);
        }

        .badge.media {
            background: rgba(0, 212, 255, 0.2);
            color: #00d4ff;
            border: 1px solid rgba(0, 212, 255, 0.3);
        }

        .badge.baja {
            background: rgba(0, 255, 136, 0.2);
            color: #00ff88;
            border: 1px solid rgba(0, 255, 136, 0.3);
        }

        .badge.estado-abierta {
            background: rgba(0, 212, 255, 0.2);
            color: #00d4ff;
        }

        .badge.estado-asignada {
            background: rgba(255, 165, 0, 0.2);
            color: #ffa500;
        }

        .badge.estado-en_proceso {
            background: rgba(255, 196, 0, 0.2);
            color: #ffc400;
        }

        .badge.estado-resuelta {
            background: rgba(0, 255, 136, 0.2);
            color: #00ff88;
        }

        .badge.estado-cerrada {
            background: rgba(160, 160, 160, 0.2);
            color: #a0a0a0;
        }

        .incidencia-info {
            margin-bottom: 15px;
        }

        .info-row {
            display: flex;
            gap: 20px;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .info-label {
            color: var(--muted);
            min-width: 120px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-top: 20px;
        }

        .form-full {
            grid-column: 1 / -1;
        }

        .lab {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 5px;
            display: block;
        }

        .ctrl {
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .10);
            color: #fff;
            outline: none;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
        }

        .ctrl:focus {
            border-color: var(--neon1);
        }

        textarea.ctrl {
            min-height: 100px;
            resize: vertical;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        .btn.primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
        }

        .btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 212, 255, 0.3);
        }

        .no-incidencias {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .no-incidencias i {
            font-size: 4rem;
            margin-bottom: 20px;
            color: var(--neon1);
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <div class="welcome">
                <h1>Mis Incidencias</h1>
                <p>Gestiona y resuelve las incidencias asignadas</p>
            </div>
            <a href="menu_tecnico.php" class="back-btn">
                <i class="fas fa-arrow-left"></i>
                Volver al Menú
            </a>
        </div>

        <?php if (empty($incidencias)): ?>
            <div class="no-incidencias">
                <i class="fas fa-clipboard-check"></i>
                <h3>No tienes incidencias asignadas</h3>
                <p>Cuando te asignen incidencias, aparecerán aquí.</p>
            </div>
        <?php else: ?>
            <div class="incidencias-grid">
                <?php foreach ($incidencias as $inc): ?>
                    <div class="incidencia-card">
                        <div class="incidencia-header">
                            <div class="incidencia-title">
                                #<?php echo $inc['id_incidencia']; ?> - <?php echo htmlspecialchars($inc['titulo']); ?>
                            </div>
                            <div class="incidencia-meta">
                                <span class="badge <?php
                                echo $inc['prioridad'] == '3' ? 'urgente' :
                                    ($inc['prioridad'] == '2' ? 'alta' : 'media');
                                ?>">
                                    <?php
                                    echo $inc['prioridad'] == '3' ? 'URGENTE' :
                                        ($inc['prioridad'] == '2' ? 'ALTA' : 'MEDIA');
                                    ?>
                                </span>
                                <span class="badge estado-<?php echo $inc['estado']; ?>">
                                    <?php echo strtoupper(str_replace('_', ' ', $inc['estado'])); ?>
                                </span>
                            </div>
                        </div>

                        <div class="incidencia-info">
                            <div class="info-row">
                                <span class="info-label">Cliente:</span>
                                <span><?php echo htmlspecialchars($inc['cliente_nombre']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Teléfono:</span>
                                <span><?php echo htmlspecialchars($inc['cliente_telefono'] ?? 'No disponible'); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Servicio:</span>
                                <span><?php echo htmlspecialchars($inc['nombre_servicio']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Dirección:</span>
                                <span>
                                    <?php
                                    if ($inc['calle'] && $inc['colonia']) {
                                        echo htmlspecialchars($inc['calle'] . ', ' . $inc['colonia']);
                                    } else {
                                        echo 'No disponible';
                                    }
                                    ?>
                                </span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Descripción:</span>
                                <span><?php echo htmlspecialchars($inc['descripcion']); ?></span>
                            </div>
                            <?php if ($inc['solucion']): ?>
                                <div class="info-row">
                                    <span class="info-label">Solución:</span>
                                    <span><?php echo htmlspecialchars($inc['solucion']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <form method="POST">
                            <input type="hidden" name="csrf" value="<?php echo $CSRF; ?>">
                            <input type="hidden" name="id_incidencia" value="<?php echo $inc['id_incidencia']; ?>">

                            <div class="form-grid">
                                <div>
                                    <label class="lab">Estado</label>
                                    <select name="estado" class="ctrl" required>
                                        <option value="en_proceso" <?php echo $inc['estado'] === 'en_proceso' ? 'selected' : ''; ?>>En Proceso</option>
                                        <option value="resuelta" <?php echo $inc['estado'] === 'resuelta' ? 'selected' : ''; ?>>
                                            Resuelta</option>
                                        <option value="cerrada" <?php echo $inc['estado'] === 'cerrada' ? 'selected' : ''; ?>>
                                            Cerrada</option>
                                    </select>
                                </div>
                                <div class="form-full">
                                    <label class="lab">Solución Aplicada</label>
                                    <textarea name="solucion" class="ctrl" placeholder="Describe la solución que aplicaste..."
                                        <?php echo $inc['estado'] === 'resuelta' || $inc['estado'] === 'cerrada' ? '' : 'required'; ?>><?php echo htmlspecialchars($inc['solucion'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-full">
                                    <label class="lab">Observaciones (Opcional)</label>
                                    <textarea name="observaciones" class="ctrl"
                                        placeholder="Observaciones adicionales..."><?php echo htmlspecialchars($inc['observaciones_tecnico'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-full">
                                    <button type="submit" class="btn primary">
                                        <i class="fas fa-save"></i>
                                        Actualizar Incidencia
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (isset($flash) && $flash): ?>
        <script>
            Swal.fire({
                icon: <?php echo json_encode($flash[0] === 'ok' ? 'success' : 'error'); ?>,
                title: <?php echo json_encode($flash[1]); ?>,
                text: <?php echo json_encode($flash[2] ?? ''); ?>,
                background: '#0c1133',
                color: '#fff'
            }).then(() => {
                if (<?php echo json_encode($flash[0] === 'ok'); ?>) {
                    // Recargar para ver los cambios
                    location.reload();
                }
            });
        </script>
    <?php endif; ?>
</body>

</html>