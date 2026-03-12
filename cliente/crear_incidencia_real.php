<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';

// === SOLUCIÓN: Obtener el id_cliente desde la base de datos ===
if (!isset($_SESSION['id_cliente']) || empty($_SESSION['id_cliente'])) {
    try {
        // Obtener el id_cliente desde la tabla clientes usando el id_usuario
        $stmt = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_usuario = ? AND eliminado = false");
        $stmt->execute([$_SESSION['id_usuario']]);
        $cliente_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente_data && isset($cliente_data['id_cliente'])) {
            $_SESSION['id_cliente'] = (int) $cliente_data['id_cliente'];
            error_log("✅ id_cliente recuperado de BD: " . $_SESSION['id_cliente']);
        } else {
            error_log("❌ No se pudo encontrar id_cliente para el usuario: " . $_SESSION['id_usuario']);
            header("Location: login.php");
            exit;
        }
    } catch (Exception $e) {
        error_log("❌ Error obteniendo id_cliente: " . $e->getMessage());
        header("Location: login.php");
        exit;
    }
}

// === GUARDIÁN DE SEGURIDAD ===
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: login.php");
    exit;
}
// === FIN DEL GUARDIÁN ===

$id_cliente = (int) $_SESSION['id_cliente'];
$nombreCliente = htmlspecialchars($_SESSION['welcome_name'] ?? 'Cliente');
$page_title = 'Levantar Incidencia';
$flash = null;

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_incidencia'])) {
    $_SESSION['csrf_incidencia'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_incidencia'];

/* ---------- POST: Guardar la incidencia ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
        $id_contrato = (int) ($_POST['id_contrato'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');

        if ($id_contrato > 0 && !empty($descripcion)) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO public.incidencias
                      (id_cliente, id_contrato, fecha_registro, descripcion, estado, prioridad, titulo, necesita_tecnico)
                    VALUES
                      (:id_cli, :id_con, NOW(), :desc, 'abierta', 'alta', 'Incidencia desde Chatbot', true)
                    RETURNING id_incidencia
                ");
                $stmt->execute([
                    ':id_cli' => $id_cliente,
                    ':id_con' => $id_contrato,
                    ':desc' => "Falla reportada por cliente (Chatbot no resolvió): " . $descripcion
                ]);
                $id_generado = $stmt->fetchColumn();

                // 🔔 NUEVO: Notificar a administradores
                require_once __DIR__ . '/../functions/notificaciones.php';
                $mensajeAdmin = "🚨 NUEVA INCIDENCIA MANUAL\nCliente: $nombreCliente\nTicket: #$id_generado\nProblema: " . substr($descripcion, 0, 100) . "...";
                $notificados = notificarAdministradores($pdo, $mensajeAdmin, $id_generado, 'alta');

                $flash = ['ok', 'Incidencia Registrada', "Tu ticket N° {$id_generado} ha sido creado. $notificados administradores notificados."];

                unset($_SESSION['csrf_incidencia']);

            } catch (Throwable $e) {
                $flash = ['error', 'Error de base de datos', $e->getMessage()];
            }
        } else {
            $flash = ['error', 'Datos incompletos', 'Por favor, selecciona un servicio y describe tu problema.'];
        }
    } else {
        $flash = ['error', 'Error de seguridad', 'El formulario ha expirado. Por favor, recarga la página.'];
    }
}

/* ---------- GET: Cargar contratos activos del cliente - VERSIÓN CORREGIDA ---------- */
$contratos = [];
try {
    // CONSULTA MEJORADA con información completa para debug
    $stmt = $pdo->prepare("
        SELECT 
            ct.id_contrato, 
            s.nombre_servicio,
            ct.monto_mensual,
            ct.estado,
            cl.codigo_cliente,
            cl.nombre_completo
        FROM public.contratos ct
        JOIN public.servicios s ON s.id_servicio = ct.id_servicio
        JOIN public.clientes cl ON ct.id_cliente = cl.id_cliente
        WHERE ct.id_cliente = :id_cli 
        AND ct.estado = 'activo'
        ORDER BY s.nombre_servicio
    ");
    $stmt->execute([':id_cli' => $id_cliente]);
    $contratos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // DEBUG EN LOGS
    error_log("🔍 Buscando contratos para cliente ID: " . $id_cliente);
    error_log("📋 Contratos encontrados: " . count($contratos));

} catch (Throwable $e) {
    error_log("❌ Error al cargar servicios: " . $e->getMessage());
    $flash = ['error', 'Error al cargar servicios', $e->getMessage()];
}

?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · <?php echo htmlspecialchars($page_title); ?></title>

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
        }

        h1,
        h2,
        h3 {
            color: var(--neon3);
        }

        a {
            color: var(--neon1);
            text-decoration: none;
        }

        a:hover {
            text-decoration: underline;
        }

        .lab {
            font-size: .88rem;
            color: var(--muted);
            margin-top: 10px;
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
            border-color: var(--neon3);
        }

        textarea.ctrl {
            min-height: 120px;
            resize: vertical;
        }

        .btn {
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 1rem;
            margin-top: 20px;
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
            margin-top: 0;
        }

        .btn i {
            margin-right: 6px;
        }

        .debug-info {
            background: rgba(255, 200, 0, 0.1);
            border: 1px solid rgba(255, 200, 0, 0.3);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }

        .debug-info strong {
            color: #ffd93d;
        }
    </style>
</head>

<body>
    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700">AYONET · Portal de Cliente</div>
                    <small style="color:#cfe1ff">Sesión de: <?php echo $nombreCliente; ?></small>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <a class="btn ghost" href="menu_cliente.php"><i class="fa-solid fa-house"></i> Inicio</a>
                <a class="btn ghost" href="../logout.php"><i class="fa-solid fa-right-from-bracket"></i> Salir</a>
            </div>
        </header>

        <section class="panel">

            <div class="card">
                <h2 style="margin-top:0;"><i class="fa-solid fa-user-doctor"></i> Solicitar Visita de Técnico</h2>
                <p>Lamentamos que el asistente no haya podido ayudarte. Por favor, levanta un ticket para que un técnico
                    te visite.</p>

                <!-- DEBUG TEMPORAL: Mostrar información de diagnóstico -->
                <?php if (empty($contratos)): ?>
                    <div class="debug-info">
                        <strong>🔍 DIAGNÓSTICO DEL PROBLEMA</strong><br>
                        <strong>No se encontraron contratos activos para:</strong><br>
                        • ID Cliente en sesión: <?php echo $id_cliente; ?><br>
                        • Nombre en sesión: <?php echo $nombreCliente; ?><br><br>

                        <?php
                        // Verificar manualmente en la base de datos
                        try {
                            $stmt_check = $pdo->prepare("SELECT id_cliente, codigo_cliente, nombre_completo FROM clientes WHERE id_cliente = ?");
                            $stmt_check->execute([$id_cliente]);
                            $cliente_db = $stmt_check->fetch(PDO::FETCH_ASSOC);

                            if ($cliente_db) {
                                echo "✅ <strong>Cliente encontrado en BD:</strong> " . $cliente_db['codigo_cliente'] . " - " . $cliente_db['nombre_completo'] . "<br>";

                                // Ver todos los contratos sin filtro
                                $stmt_all = $pdo->prepare("SELECT id_contrato, estado, fecha_inicio_contrato FROM contratos WHERE id_cliente = ?");
                                $stmt_all->execute([$id_cliente]);
                                $all_contratos = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

                                echo "📊 <strong>Contratos en BD:</strong> " . count($all_contratos) . "<br>";
                                foreach ($all_contratos as $c) {
                                    echo "&nbsp;&nbsp;• Contrato ID: " . $c['id_contrato'] . " - Estado: " . $c['estado'] . " - Inicio: " . $c['fecha_inicio_contrato'] . "<br>";
                                }
                            } else {
                                echo "❌ <strong>Cliente NO encontrado en la base de datos</strong><br>";
                            }
                        } catch (Exception $e) {
                            echo "❌ Error en diagnóstico: " . $e->getMessage();
                        }
                        ?>
                    </div>

                    <div
                        style="background: rgba(255, 100, 100, .1); border: 1px solid rgba(255, 100, 100, .3); padding: 15px; border-radius: 8px;">
                        <strong>No tienes servicios activos.</strong>
                        <p style="margin: 5px 0 0;">No podemos levantar una incidencia si no tienes contratos de servicio
                            activos.</p>
                        <p style="margin: 5px 0 0; font-size: 0.9rem; color: #ff9999;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            Si crees que esto es un error, contacta al administrador.
                        </p>
                    </div>
                <?php else: ?>
                    <!-- MOSTRAR CONTRATOS ENCONTRADOS -->
                    <div
                        style="background: rgba(100, 255, 100, .1); border: 1px solid rgba(100, 255, 100, .3); padding: 15px; border-radius: 8px; margin-bottom: 15px;">
                        <strong>✅ Servicios activos encontrados:</strong> <?php echo count($contratos); ?> contrato(s)
                    </div>

                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?php echo $CSRF; ?>">

                        <label for="id_contrato" class="lab">Servicio Contratado Afectado</label>
                        <select name="id_contrato" id="id_contrato" class="ctrl" required>
                            <option value="">Selecciona un servicio...</option>
                            <?php foreach ($contratos as $c): ?>
                                <option value="<?php echo (int) $c['id_contrato']; ?>">
                                    <?php echo htmlspecialchars($c['nombre_servicio']); ?>
                                    ($<?php echo number_format($c['monto_mensual'], 2); ?>/mes)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <label for="descripcion" class="lab">Describe el problema (sé lo más detallado posible)</label>
                        <textarea name="descripcion" id="descripcion" class="ctrl"
                            placeholder="Ej. Mi internet está muy lento por las noches. Ya reinicié el módem y sigue igual."
                            required></textarea>

                        <button type="submit" class="btn primary"><i class="fa-solid fa-paper-plane"></i> Enviar
                            Reporte</button>
                    </form>
                <?php endif; ?>
            </div>

        </section>
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
                    location.href = 'historial_incidencias.php';
                }
            });
        </script>
    <?php endif; ?>
</body>

</html>