<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
require_once __DIR__ . '/db.php';

if (!isset($_SESSION['id_usuario'])) {
    header("Location: login.php");
    exit;
}

/* ---------- CSRF ---------- */
if (empty($_SESSION['csrf_clientes'])) {
    $_SESSION['csrf_clientes'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf_clientes'];

/* ---------- Helpers ---------- */
function h($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/* ---------- Generar siguiente código_cliente ---------- */
function siguienteCodigoCliente(PDO $pdo)
{
    $r = $pdo->query("SELECT MAX(codigo_cliente) AS maxc FROM clientes");
    $row = $r->fetch(PDO::FETCH_ASSOC);
    $max = $row['maxc'] ?? null;
    $n = 0;
    if ($max && preg_match('/CLI-(\d+)/', $max, $m))
        $n = (int) $m[1];
    $n++;
    return sprintf("CLI-%04d", $n);
}

/* ---------- Catálogo de servicios ---------- */
$servicios = [];
try {
    $stmt = $pdo->query("SELECT id_servicio, nombre_servicio, precio_base FROM servicios WHERE activo IS TRUE ORDER BY nombre_servicio");
    $servicios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    die("Error servicios: " . h($e->getMessage()));
}

/* ---------- CARGA PARA EDICIÓN ---------- */
$edit = null;
$editDir = ['calle' => '', 'numero_exterior' => '', 'numero_interior' => '', 'colonia' => '', 'referencia' => ''];
$editContrato = ['id_servicio' => '', 'monto_mensual' => '', 'fecha_inicio_contrato' => '', 'fecha_fin_contrato' => ''];
$mostrarPassword = false;

if (!empty($_GET['editar'])) {
    $idcli = (int) $_GET['editar'];
    $q = $pdo->prepare("SELECT c.*, u.id_usuario as id_usuario FROM clientes c LEFT JOIN usuarios u ON c.id_usuario = u.id_usuario WHERE c.id_cliente = :id LIMIT 1");
    $q->execute([':id' => $idcli]);
    $edit = $q->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($edit) {
        $qd = $pdo->prepare("SELECT calle, numero_exterior, numero_interior, colonia, referencia FROM direcciones WHERE id_cliente = :cli AND es_principal IS TRUE LIMIT 1");
        $qd->execute([':cli' => $idcli]);
        $d = $qd->fetch(PDO::FETCH_ASSOC);
        if ($d)
            $editDir = $d;

        // Cargar datos del contrato activo
        $qc = $pdo->prepare("SELECT id_servicio, monto_mensual, fecha_inicio_contrato, fecha_fin_contrato FROM contratos WHERE id_cliente = :cli AND estado = 'activo' ORDER BY id_contrato DESC LIMIT 1");
        $qc->execute([':cli' => $idcli]);
        $contrato = $qc->fetch(PDO::FETCH_ASSOC);
        if ($contrato)
            $editContrato = $contrato;

        // Determinar si mostrar campo de contraseña
        $mostrarPassword = (isset($_GET['tipo']) && $_GET['tipo'] === 'completo');
    }
}

/* ---------- ELIMINAR ---------- */
$flash = null;
if (!empty($_GET['eliminar']) && !empty($_GET['csrf']) && hash_equals($CSRF, $_GET['csrf'])) {
    try {
        $idcli = (int) $_GET['eliminar'];
        $stmt = $pdo->prepare("UPDATE clientes SET eliminado = TRUE WHERE id_cliente = :id");
        $stmt->execute([':id' => $idcli]);
        $flash = ['ok', 'Cliente eliminado', 'Se aplicó borrado lógico.'];
    } catch (Throwable $e) {
        $flash = ['error', 'Error al eliminar', $e->getMessage()];
    }
}

/* ---------- POST: crear / actualizar ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf']) && hash_equals($CSRF, $_POST['csrf'])) {
    $modo = $_POST['modo'] ?? '';
    $idcli = isset($_POST['id_cliente']) ? (int) $_POST['id_cliente'] : 0;

    // Datos del cliente
    $nombre = trim($_POST['nombre_completo'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $telefono = trim($_POST['telefono'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $new_password = (string) ($_POST['new_password'] ?? '');

    // Servicio/Contrato
    $servicio_id = isset($_POST['servicio_id']) ? (int) $_POST['servicio_id'] : 0;
    $precio_mensual = trim($_POST['precio_mensual'] ?? '');
    $fecha_inicio = trim($_POST['fecha_inicio'] ?? '');
    $fecha_fin = trim($_POST['fecha_fin'] ?? '');

    // Dirección
    $calle = trim($_POST['calle'] ?? '');
    $num_ext = trim($_POST['numero_exterior'] ?? '');
    $num_int = trim($_POST['numero_interior'] ?? '');
    $colonia = trim($_POST['colonia'] ?? '');
    $referencia = trim($_POST['referencia'] ?? '');

    // Validación básica
    $errores = [];
    if ($nombre === '')
        $errores[] = "Nombre requerido";
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        $errores[] = "Email inválido";
    if ($calle === '')
        $errores[] = "Calle requerida";
    if ($num_ext === '')
        $errores[] = "Número exterior requerido";
    if ($colonia === '')
        $errores[] = "Colonia requerida";

    // Validación de contraseña solo para creación
    if ($modo === 'crear' && $password !== '') {
        if (strlen($password) < 8) {
            $errores[] = "La contraseña debe tener al menos 8 caracteres";
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errores[] = "La contraseña debe tener al menos una mayúscula";
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errores[] = "La contraseña debe tener al menos un número";
        }
        if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $password)) {
            $errores[] = "La contraseña debe tener al menos un carácter especial";
        }
    }

    // Validación de nueva contraseña para edición
    if ($modo === 'actualizar' && $new_password !== '') {
        if (strlen($new_password) < 8) {
            $errores[] = "La nueva contraseña debe tener al menos 8 caracteres";
        }
        if (!preg_match('/[A-Z]/', $new_password)) {
            $errores[] = "La nueva contraseña debe tener al menos una mayúscula";
        }
        if (!preg_match('/[0-9]/', $new_password)) {
            $errores[] = "La nueva contraseña debe tener al menos un número";
        }
        if (!preg_match('/[!@#$%^&*()\-_=+{};:,<.>]/', $new_password)) {
            $errores[] = "La nueva contraseña debe tener al menos un carácter especial";
        }
    }

    if (!$errores) {
        try {
            $pdo->beginTransaction();

            if ($modo === 'crear') {
                // Validar duplicidad
                $qdu = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE LOWER(email)=LOWER(:e) AND COALESCE(eliminado,false)=false");
                $qdu->execute([':e' => $email]);
                if ((int) $qdu->fetchColumn() > 0)
                    throw new RuntimeException("Ya existe un cliente con ese email.");

                // Generar password si no proporcionó
                if ($password === '') {
                    // Generar contraseña que cumpla los requisitos
                    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
                    $lowercase = 'abcdefghjkmnpqrstuvwxyz';
                    $numbers = '23456789';
                    $special = '!@#$%&*';

                    $password = $uppercase[rand(0, strlen($uppercase) - 1)] .
                        $lowercase[rand(0, strlen($lowercase) - 1)] .
                        $numbers[rand(0, strlen($numbers) - 1)] .
                        $special[rand(0, strlen($special) - 1)] .
                        $lowercase[rand(0, strlen($lowercase) - 1)] .
                        $numbers[rand(0, strlen($numbers) - 1)] .
                        $lowercase[rand(0, strlen($lowercase) - 1)] .
                        $special[rand(0, strlen($special) - 1)];
                    $password = str_shuffle($password);
                }

                $codigo = siguienteCodigoCliente($pdo);

                // 1. Crear en TABLA USUARIOS
                $stmt_usuario = $pdo->prepare("
                    INSERT INTO usuarios (email, password_hash, nombre_completo, telefono, id_rol)
                    VALUES (:mail, :ph, :nom, :tel, (SELECT id_rol FROM roles WHERE nombre_rol = 'cliente'))
                    RETURNING id_usuario
                ");
                $stmt_usuario->execute([
                    ':mail' => $email,
                    ':ph' => password_hash($password, PASSWORD_DEFAULT),
                    ':nom' => $nombre,
                    ':tel' => $telefono ?: null
                ]);
                $id_usuario_nuevo = $stmt_usuario->fetchColumn();

                // 2. Crear en TABLA CLIENTES
                $ins = $pdo->prepare("
                    INSERT INTO clientes (codigo_cliente, fecha_alta_servicio, nombre_completo, email, telefono, password_hash, eliminado, id_usuario)
                    VALUES (:cod, NOW(), :nom, :mail, :tel, :ph, FALSE, :idu)
                    RETURNING id_cliente, codigo_cliente
                ");
                $ins->execute([
                    ':cod' => $codigo,
                    ':nom' => $nombre,
                    ':mail' => $email,
                    ':tel' => $telefono ?: null,
                    ':ph' => password_hash($password, PASSWORD_DEFAULT),
                    ':idu' => $id_usuario_nuevo
                ]);
                $row = $ins->fetch(PDO::FETCH_ASSOC);
                $id_cliente = (int) $row['id_cliente'];
                $codGen = $row['codigo_cliente'];

                // Dirección principal
                $insD = $pdo->prepare("
                    INSERT INTO direcciones (id_cliente, calle, numero_exterior, numero_interior, colonia, referencia, es_principal)
                    VALUES (:cli, :calle, :ext, :int, :col, :ref, TRUE)
                ");
                $insD->execute([
                    ':cli' => $id_cliente,
                    ':calle' => $calle,
                    ':ext' => $num_ext,
                    ':int' => $num_int ?: null,
                    ':col' => $colonia,
                    ':ref' => $referencia ?: null
                ]);

                // CONTRATO (solo si se seleccionó servicio)
                if ($servicio_id > 0) {
                    if ($precio_mensual === '') {
                        $stmtPrecio = $pdo->prepare("SELECT precio_base FROM servicios WHERE id_servicio=:s");
                        $stmtPrecio->execute([':s' => $servicio_id]);
                        $precio_mensual = (string) $stmtPrecio->fetchColumn();
                    }
                    $fecha_inicio = $fecha_inicio ?: date('Y-m-d');

                    // Si no se proporciona fecha fin, se establece como indefinido (NULL)
                    $fecha_fin_value = ($fecha_fin !== '') ? $fecha_fin : null;

                    $insCt = $pdo->prepare("
                        INSERT INTO contratos (id_cliente, id_servicio, monto_mensual, fecha_inicio_contrato, fecha_fin_contrato, estado, observaciones)
                        VALUES (:cli, :srv, :monto, :fi, :ff, 'activo', 'Contrato inicial creado automáticamente')
                    ");
                    $insCt->execute([
                        ':cli' => $id_cliente,
                        ':srv' => $servicio_id,
                        ':monto' => number_format((float) $precio_mensual, 2, '.', ''),
                        ':fi' => $fecha_inicio,
                        ':ff' => $fecha_fin_value
                    ]);
                }

                $pdo->commit();
                $txtPass = ($password ? " · Contraseña: $password" : "");
                $flash = ['ok', 'Cliente registrado', "Código: $codGen · Usuario: $email$txtPass" . ($servicio_id > 0 ? " · Contrato creado automáticamente" : "")];

                // Redirigir para limpiar el formulario después de crear
                header("Location: clientes.php?success=1");
                exit;

            } elseif ($modo === 'actualizar' && $idcli > 0) {
                // Validar duplicidad
                $qdu = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE LOWER(email)=LOWER(:e) AND id_cliente<>:id AND COALESCE(eliminado,false)=false");
                $qdu->execute([':e' => $email, ':id' => $idcli]);
                if ((int) $qdu->fetchColumn() > 0)
                    throw new RuntimeException("Ya existe otro cliente con ese email.");

                // 1. Actualizar en TABLA USUARIOS
                if ($edit && $edit['id_usuario']) {
                    $sql_usuario = "UPDATE usuarios SET nombre_completo = :nom, email = :mail, telefono = :tel";
                    if ($new_password !== '')
                        $sql_usuario .= ", password_hash = :ph";
                    $sql_usuario .= " WHERE id_usuario = :id_usuario";

                    $params_usuario = [
                        ':nom' => $nombre,
                        ':mail' => $email,
                        ':tel' => $telefono ?: null,
                        ':id_usuario' => $edit['id_usuario']
                    ];
                    if ($new_password !== '')
                        $params_usuario[':ph'] = password_hash($new_password, PASSWORD_DEFAULT);
                    $pdo->prepare($sql_usuario)->execute($params_usuario);
                }

                // 2. Actualizar en TABLA CLIENTES
                $sql = "UPDATE clientes SET nombre_completo = :nom, email = :mail, telefono = :tel";
                if ($new_password !== '')
                    $sql .= ", password_hash = :ph";
                $sql .= " WHERE id_cliente = :id";
                $params = [':nom' => $nombre, ':mail' => $email, ':tel' => $telefono ?: null, ':id' => $idcli];
                if ($new_password !== '')
                    $params[':ph'] = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->prepare($sql)->execute($params);

                // Upsert dirección principal
                $qSel = $pdo->prepare("SELECT id_direccion FROM direcciones WHERE id_cliente=:cli AND es_principal IS TRUE LIMIT 1");
                $qSel->execute([':cli' => $idcli]);
                $idDir = $qSel->fetchColumn();
                if ($idDir) {
                    $qU = $pdo->prepare("UPDATE direcciones SET calle = :calle, numero_exterior = :ext, numero_interior = :int, colonia = :col, referencia = :ref WHERE id_direccion = :id");
                    $qU->execute([
                        ':calle' => $calle,
                        ':ext' => $num_ext,
                        ':int' => $num_int ?: null,
                        ':col' => $colonia,
                        ':ref' => $referencia ?: null,
                        ':id' => $idDir
                    ]);
                } else {
                    $qI = $pdo->prepare("INSERT INTO direcciones (id_cliente, calle, numero_exterior, numero_interior, colonia, referencia, es_principal) VALUES (:cli, :calle, :ext, :int, :col, :ref, TRUE)");
                    $qI->execute([
                        ':cli' => $idcli,
                        ':calle' => $calle,
                        ':ext' => $num_ext,
                        ':int' => $num_int ?: null,
                        ':col' => $colonia,
                        ':ref' => $referencia ?: null
                    ]);
                }

                // Actualizar contrato existente si hay cambios
                if ($servicio_id > 0) {
                    $qc = $pdo->prepare("SELECT id_contrato FROM contratos WHERE id_cliente = :cli AND estado = 'activo' ORDER BY id_contrato DESC LIMIT 1");
                    $qc->execute([':cli' => $idcli]);
                    $id_contrato = $qc->fetchColumn();

                    if ($id_contrato) {
                        // Actualizar contrato existente
                        $qUpd = $pdo->prepare("UPDATE contratos SET id_servicio = :srv, monto_mensual = :monto, fecha_inicio_contrato = :fi, fecha_fin_contrato = :ff WHERE id_contrato = :id");
                        $fecha_fin_value = ($fecha_fin !== '') ? $fecha_fin : null;
                        $qUpd->execute([
                            ':srv' => $servicio_id,
                            ':monto' => number_format((float) $precio_mensual, 2, '.', ''),
                            ':fi' => $fecha_inicio,
                            ':ff' => $fecha_fin_value,
                            ':id' => $id_contrato
                        ]);
                    } else {
                        // Crear nuevo contrato si no existe
                        $fecha_fin_value = ($fecha_fin !== '') ? $fecha_fin : null;
                        $insCt = $pdo->prepare("
                            INSERT INTO contratos (id_cliente, id_servicio, monto_mensual, fecha_inicio_contrato, fecha_fin_contrato, estado, observaciones)
                            VALUES (:cli, :srv, :monto, :fi, :ff, 'activo', 'Contrato actualizado desde edición de cliente')
                        ");
                        $insCt->execute([
                            ':cli' => $idcli,
                            ':srv' => $servicio_id,
                            ':monto' => number_format((float) $precio_mensual, 2, '.', ''),
                            ':fi' => $fecha_inicio,
                            ':ff' => $fecha_fin_value
                        ]);
                    }
                }

                $pdo->commit();
                $flash = ['ok', 'Cliente actualizado', ($new_password !== '' ? 'Contraseña actualizada.' : 'Datos guardados.')];

                // Redirigir para limpiar el formulario después de actualizar
                header("Location: clientes.php?success=1");
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction())
                $pdo->rollBack();
            $flash = ['error', 'Error', $e->getMessage() ?: 'No se pudo guardar.'];
        }
    } else {
        $flash = ['error', 'Validación', implode("\n", $errores)];
    }
}

// Mostrar mensaje de éxito si viene de redirección
if (!empty($_GET['success'])) {
    $flash = ['ok', 'Operación exitosa', 'Los datos se guardaron correctamente.'];
}

// ========== CONSULTA SQL CORREGIDA ==========
// Consulta FIXED: Ahora muestra TODOS los clientes, con o sin contratos activos
$sql = "
SELECT 
    c.id_cliente, 
    c.codigo_cliente, 
    c.fecha_alta_servicio, 
    c.nombre_completo, 
    c.email, 
    c.telefono,
    (
        SELECT s.nombre_servicio 
        FROM contratos co2 
        LEFT JOIN servicios s ON co2.id_servicio = s.id_servicio 
        WHERE co2.id_cliente = c.id_cliente 
        AND co2.estado = 'activo'
        ORDER BY co2.fecha_inicio_contrato DESC 
        LIMIT 1
    ) AS nombre_servicio,
    CASE 
        WHEN EXISTS (
            SELECT 1 FROM contratos 
            WHERE id_cliente = c.id_cliente AND estado = 'activo'
        ) THEN 'activo'
        WHEN EXISTS (
            SELECT 1 FROM contratos 
            WHERE id_cliente = c.id_cliente
        ) THEN 'SIN CONTRATO ACTIVO'
        ELSE 'SIN CONTRATO'
    END as estado_contrato
FROM clientes c
WHERE COALESCE(c.eliminado, FALSE) = FALSE
ORDER BY c.id_cliente DESC
";

$clientes = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* ---------- PRESETS ---------- */
$isEdit = is_array($edit);
$nombreUser = ($_SESSION['nombre'] ?? 'Usuario');
$rolUI = strtoupper($_SESSION['rol'] ?? 'USUARIO');
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>AYONET · Clientes</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/i18n/es.js"></script>
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
            grid-template-columns: 350px 1fr;
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

        .table-wrap {
            overflow: auto;
            max-height: calc(100vh - 200px);
        }

        .table-ayanet {
            width: 100%;
            min-width: 800px;
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

        tr.row {
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .12)
        }

        tr.row:hover {
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

        .section-title {
            color: var(--neon1);
            font-size: 0.9rem;
            margin: 15px 0 8px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid rgba(255, 255, 255, .1);
        }

        .client-info {
            background: rgba(255, 255, 255, .05);
            padding: 10px 12px;
            border-radius: 8px;
            margin-top: 10px;
            font-size: 0.8rem;
        }

        .badge {
            background: rgba(111, 0, 255, .18);
            border: 1px solid rgba(111, 0, 255, .35);
            color: #dcd6ff;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 0.8rem;
        }

        .servicio-info {
            background: rgba(255, 255, 255, .05);
            padding: 10px;
            border-radius: 8px;
            margin: 8px 0;
            font-size: 0.8rem;
        }

        .servicio-nombre {
            font-weight: 500;
            color: var(--neon1);
        }

        .servicio-precio {
            color: var(--neon3);
            font-weight: 600;
        }

        .estado-activo {
            color: #10b981;
        }

        .estado-suspendido {
            color: #f59e0b;
        }

        .estado-cancelado {
            color: #ef4444;
        }

        /* Nuevos estilos para mejoras */
        .password-container {
            position: relative;
            margin-bottom: 12px;
        }

        .password-container .ctrl {
            margin-bottom: 0;
            padding-right: 40px;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 10px;
            background: none;
            border: none;
            color: var(--muted);
            cursor: pointer;
            z-index: 2;
            padding: 5px;
        }

        .password-toggle:hover {
            color: var(--neon1);
        }

        .password-requirements {
            margin-top: 5px;
            font-size: 0.75rem;
        }

        .requirement {
            display: flex;
            align-items: center;
            margin-bottom: 2px;
            color: var(--muted);
        }

        .requirement i {
            margin-right: 5px;
            font-size: 0.7rem;
        }

        .requirement.valid {
            color: #10b981;
        }

        .requirement.valid i {
            color: #10b981;
        }

        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(6, 9, 21, 0.8);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid rgba(255, 255, 255, 0.1);
            border-top: 5px solid var(--neon1);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 15px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .loading-text {
            color: var(--neon1);
            font-size: 1.1rem;
            font-weight: 500;
        }

        .select2-container--default .select2-selection--single {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 10px;
            color: #fff;
            height: 42px;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #fff;
            line-height: 42px;
            padding-left: 12px;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 42px;
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background: var(--neon1);
            color: #061022;
        }

        .select2-dropdown {
            background: var(--panel);
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 10px;
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            background: rgba(255, 255, 255, .08);
            border: 1px solid rgba(255, 255, 255, .16);
            color: #fff;
            border-radius: 8px;
        }

        .currency-input {
            position: relative;
        }

        .currency-input::before {
            content: '$';
            position: absolute;
            left: 10px;
            top: 10px;
            color: var(--muted);
            z-index: 1;
        }

        .currency-input input {
            padding-left: 25px;
        }
    </style>
</head>

<body class="bg">
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="spinner"></div>
        <div class="loading-text">Procesando...</div>
    </div>

    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700;letter-spacing:.3px">AYONET · Clientes</div>
                    <small style="color:#cfe1ff">Sesión de: <?= h($nombreUser) ?></small>
                </div>
            </div>
            <div class="top-actions">
                <a class="btn ghost" href="menu.php"><i class="fa-solid fa-arrow-left"></i> Menú</a>
                <a class="btn ghost" href="contratos/contratos.php"><i class="fa-solid fa-file-contract"></i>
                    Contratos</a>
                <span class="pill"><?= h($rolUI) ?></span>
            </div>
        </header>

        <section class="panel">
            <div class="grid">
                <!-- Tarjeta 1: Formulario -->
                <div class="card">
                    <h3><?= $isEdit ? 'Editar cliente' : 'Nuevo cliente' ?></h3>
                    <div class="form-container">
                        <form method="post" onsubmit="return validarFormulario()" id="clienteForm">
                            <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                            <input type="hidden" name="modo" value="<?= $isEdit ? 'actualizar' : 'crear' ?>">
                            <?php if ($isEdit): ?>
                                <input type="hidden" name="id_cliente" value="<?= (int) $edit['id_cliente'] ?>">
                            <?php endif; ?>

                            <label class="lab">Nombre completo *</label>
                            <input class="ctrl" type="text" name="nombre_completo" id="nombre_completo"
                                value="<?= h($isEdit ? $edit['nombre_completo'] : '') ?>" required>

                            <label class="lab">Email *</label>
                            <input class="ctrl" type="email" name="email" id="email"
                                value="<?= h($isEdit ? $edit['email'] : '') ?>" required>

                            <label class="lab">Teléfono</label>
                            <input class="ctrl" type="text" name="telefono" id="telefono"
                                value="<?= h($isEdit ? $edit['telefono'] : '') ?>" placeholder="Opcional">

                            <?php if (!$isEdit): ?>
                                <label class="lab">Contraseña</label>
                                <div class="password-container">
                                    <input class="ctrl" type="password" name="password" id="password"
                                        placeholder="Auto-generar si está vacío"
                                        oninput="validarRequisitosContrasena(this.value, 'password')">
                                    <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-requirements" id="password-requirements">
                                    <div class="requirement" id="req-length">
                                        <i class="fa-solid fa-circle"></i> 8 caracteres mínimo
                                    </div>
                                    <div class="requirement" id="req-uppercase">
                                        <i class="fa-solid fa-circle"></i> 1 mayúscula
                                    </div>
                                    <div class="requirement" id="req-number">
                                        <i class="fa-solid fa-circle"></i> 1 número
                                    </div>
                                    <div class="requirement" id="req-special">
                                        <i class="fa-solid fa-circle"></i> 1 carácter especial
                                    </div>
                                </div>
                            <?php else: ?>
                                <label class="lab">Nueva contraseña (opcional)</label>
                                <div class="password-container" id="password-container-edit"
                                    style="<?= $mostrarPassword ? 'display: block;' : 'display: none;' ?>">
                                    <input class="ctrl" type="password" name="new_password" id="new_password"
                                        placeholder="Ingrese nueva contraseña"
                                        oninput="validarRequisitosContrasena(this.value, 'new_password')">
                                    <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                                        <i class="fa-regular fa-eye"></i>
                                    </button>
                                </div>
                                <div class="password-requirements" id="new-password-requirements"
                                    style="<?= $mostrarPassword ? 'display: block;' : 'display: none;' ?>">
                                    <div class="requirement" id="new-req-length">
                                        <i class="fa-solid fa-circle"></i> 8 caracteres mínimo
                                    </div>
                                    <div class="requirement" id="new-req-uppercase">
                                        <i class="fa-solid fa-circle"></i> 1 mayúscula
                                    </div>
                                    <div class="requirement" id="new-req-number">
                                        <i class="fa-solid fa-circle"></i> 1 número
                                    </div>
                                    <div class="requirement" id="new-req-special">
                                        <i class="fa-solid fa-circle"></i> 1 carácter especial
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="section-title">📍 Dirección Principal</div>

                            <label class="lab">Calle *</label>
                            <input class="ctrl" type="text" name="calle" id="calle"
                                value="<?= h($editDir['calle'] ?? '') ?>" required>

                            <label class="lab">Número exterior *</label>
                            <input class="ctrl" type="text" name="numero_exterior" id="numero_exterior"
                                value="<?= h($editDir['numero_exterior'] ?? '') ?>" required>

                            <label class="lab">Número interior</label>
                            <input class="ctrl" type="text" name="numero_interior"
                                value="<?= h($editDir['numero_interior'] ?? '') ?>" placeholder="Opcional">

                            <label class="lab">Colonia *</label>
                            <input class="ctrl" type="text" name="colonia" id="colonia"
                                value="<?= h($editDir['colonia'] ?? '') ?>" required>

                            <label class="lab">Referencia</label>
                            <input class="ctrl" type="text" name="referencia"
                                value="<?= h($editDir['referencia'] ?? '') ?>" placeholder="Opcional">

                            <div class="section-title">📡 Servicio (Opcional)</div>

                            <label class="lab">Servicio</label>
                            <select class="ctrl" name="servicio_id" id="servicio_id" style="width: 100%">
                                <option value="">Seleccione un servicio (opcional)</option>
                                <?php foreach ($servicios as $s): ?>
                                    <option value="<?= (int) $s['id_servicio'] ?>" data-precio="<?= h($s['precio_base']) ?>"
                                        <?= ($isEdit && $editContrato['id_servicio'] == $s['id_servicio']) ? 'selected' : '' ?>>
                                        <?= h($s['nombre_servicio']) ?> - $<?= number_format($s['precio_base'], 2) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div id="info-servicio" class="servicio-info" style="display: none;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span id="nombre-servicio-seleccionado" class="servicio-nombre"></span>
                                    <span id="precio-servicio-seleccionado" class="servicio-precio"></span>
                                </div>
                                <div style="font-size: 0.75rem; color: var(--muted); margin-top: 5px;">
                                    Precio mensual - Puede personalizarlo abajo
                                </div>
                            </div>

                            <label class="lab">Precio mensual personalizado</label>
                            <div class="currency-input">
                                <input class="ctrl" type="text" name="precio_mensual" id="precio_mensual"
                                    placeholder="Precio base si está vacío"
                                    value="<?= $isEdit ? number_format($editContrato['monto_mensual'], 2) : '' ?>">
                            </div>

                            <label class="lab">Fecha inicio</label>
                            <input class="ctrl" type="date" name="fecha_inicio"
                                value="<?= $isEdit ? ($editContrato['fecha_inicio_contrato'] ?: date('Y-m-d')) : date('Y-m-d') ?>">

                            <label class="lab">Fecha fin (opcional)</label>
                            <input class="ctrl" type="date" name="fecha_fin"
                                value="<?= $isEdit ? $editContrato['fecha_fin_contrato'] : '' ?>"
                                placeholder="Dejar vacío para contrato indefinido">

                            <div class="form-actions">
                                <button class="btn primary" type="submit" id="submitBtn">
                                    <i class="fa-solid fa-floppy-disk"></i>
                                    <?= $isEdit ? 'Actualizar' : 'Guardar Cliente' ?>
                                </button>
                                <?php if ($isEdit): ?>
                                    <a class="btn ghost btn-small" href="clientes.php"><i class="fa-solid fa-times"></i>
                                        Cancelar</a>
                                <?php endif; ?>
                            </div>
                        </form>

                        <?php if ($isEdit): ?>
                            <div class="client-info">
                                <div><strong>Código:</strong> <span class="badge"><?= h($edit['codigo_cliente']) ?></span>
                                </div>
                                <div><strong>Alta:</strong>
                                    <?= h(date('d/m/Y H:i', strtotime($edit['fecha_alta_servicio']))) ?></div>
                                <?php if (isset($edit['estado_contrato'])): ?>
                                    <div><strong>Estado contrato:</strong>
                                        <span
                                            class="<?= $edit['estado_contrato'] === 'activo' ? 'estado-activo' : ($edit['estado_contrato'] === 'suspendido' ? 'estado-suspendido' : 'estado-cancelado') ?>">
                                            <?= h($edit['estado_contrato']) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tarjeta 2: Tabla -->
                <div class="card">
                    <h3>Listado de Clientes</h3>
                    <div class="table-wrap">
                        <table id="tablaClientes" class="display compact table-ayanet" style="width:100%">
                            <thead>
                                <tr>
                                    <th>Código</th>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Teléfono</th>
                                    <th>Servicio</th>
                                    <th>Estado</th>
                                    <th>Alta</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clientes as $c): ?>
                                    <tr class="row">
                                        <td><span class="badge"><?= h($c['codigo_cliente']) ?></span></td>
                                        <td><?= h($c['nombre_completo']) ?></td>
                                        <td><?= h($c['email']) ?></td>
                                        <td><?= h($c['telefono'] ?: '—') ?></td>
                                        <td><?= h($c['nombre_servicio'] ?: '—') ?></td>
                                        <td>
                                            <?php if ($c['estado_contrato']): ?>
                                                <span
                                                    class="
                                                    <?= $c['estado_contrato'] === 'activo' ? 'estado-activo' :
                                                        ($c['estado_contrato'] === 'SIN CONTRATO ACTIVO' ? 'estado-suspendido' :
                                                            ($c['estado_contrato'] === 'SIN CONTRATO' ? 'estado-cancelado' : 'estado-cancelado')) ?>">
                                                    <?= h($c['estado_contrato']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span style="color: #6b7280;">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= h(date('d/m/Y', strtotime($c['fecha_alta_servicio']))) ?></td>
                                        <td class="actions">
                                            <button class="btn ghost btn-small"
                                                onclick="editarCliente(<?= (int) $c['id_cliente'] ?>)">
                                                <i class="fa-regular fa-pen-to-square"></i> Editar
                                            </button>
                                            <button class="btn ghost btn-small"
                                                onclick="eliminarCliente(<?= (int) $c['id_cliente'] ?>)">
                                                <i class="fa-regular fa-trash-can"></i> Eliminar
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
                icon: <?= json_encode($flash[0] === 'ok' ? 'success' : 'error') ?>,
                title: <?= json_encode($flash[1]) ?>,
                text: <?= json_encode($flash[2]) ?>,
                timer: 3000
            });
        </script>
    <?php endif; ?>

    <script>
        function validarRequisitosContrasena(password, tipo) {
            const prefix = tipo === 'password' ? 'req' : 'new-req';

            // Validar longitud
            const lengthValid = password.length >= 8;
            document.getElementById(`${prefix}-length`).className = `requirement ${lengthValid ? 'valid' : ''}`;
            if (lengthValid) {
                document.getElementById(`${prefix}-length`).innerHTML = '<i class="fa-solid fa-check"></i> 8 caracteres mínimo';
            } else {
                document.getElementById(`${prefix}-length`).innerHTML = '<i class="fa-solid fa-circle"></i> 8 caracteres mínimo';
            }

            // Validar mayúscula
            const uppercaseValid = /[A-Z]/.test(password);
            document.getElementById(`${prefix}-uppercase`).className = `requirement ${uppercaseValid ? 'valid' : ''}`;
            if (uppercaseValid) {
                document.getElementById(`${prefix}-uppercase`).innerHTML = '<i class="fa-solid fa-check"></i> 1 mayúscula';
            } else {
                document.getElementById(`${prefix}-uppercase`).innerHTML = '<i class="fa-solid fa-circle"></i> 1 mayúscula';
            }

            // Validar número
            const numberValid = /[0-9]/.test(password);
            document.getElementById(`${prefix}-number`).className = `requirement ${numberValid ? 'valid' : ''}`;
            if (numberValid) {
                document.getElementById(`${prefix}-number`).innerHTML = '<i class="fa-solid fa-check"></i> 1 número';
            } else {
                document.getElementById(`${prefix}-number`).innerHTML = '<i class="fa-solid fa-circle"></i> 1 número';
            }

            // Validar carácter especial
            const specialValid = /[!@#$%^&*()\-_=+{};:,<.>]/.test(password);
            document.getElementById(`${prefix}-special`).className = `requirement ${specialValid ? 'valid' : ''}`;
            if (specialValid) {
                document.getElementById(`${prefix}-special`).innerHTML = '<i class="fa-solid fa-check"></i> 1 carácter especial';
            } else {
                document.getElementById(`${prefix}-special`).innerHTML = '<i class="fa-solid fa-circle"></i> 1 carácter especial';
            }
        }

        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const toggle = field.parentNode.querySelector('.password-toggle i');

            if (field.type === 'password') {
                field.type = 'text';
                toggle.classList.remove('fa-eye');
                toggle.classList.add('fa-eye-slash');
            } else {
                field.type = 'password';
                toggle.classList.remove('fa-eye-slash');
                toggle.classList.add('fa-eye');
            }
        }

        function validarFormulario() {
            const nombre = document.getElementById('nombre_completo').value.trim();
            const email = document.getElementById('email').value.trim();
            const calle = document.getElementById('calle').value.trim();
            const numeroExt = document.getElementById('numero_exterior').value.trim();
            const colonia = document.getElementById('colonia').value.trim();
            const password = document.getElementById('password') ? document.getElementById('password').value : '';
            const newPassword = document.getElementById('new_password') ? document.getElementById('new_password').value : '';
            const modo = document.querySelector('input[name="modo"]').value;

            if (!nombre) {
                Swal.fire('Error', 'El nombre completo es requerido', 'error');
                return false;
            }
            if (!email) {
                Swal.fire('Error', 'El email es requerido', 'error');
                return false;
            }
            if (!calle) {
                Swal.fire('Error', 'La calle es requerida', 'error');
                return false;
            }
            if (!numeroExt) {
                Swal.fire('Error', 'El número exterior es requerido', 'error');
                return false;
            }
            if (!colonia) {
                Swal.fire('Error', 'La colonia es requerida', 'error');
                return false;
            }

            // Validación de contraseña para creación
            if (modo === 'crear' && password !== '') {
                if (password.length < 8) {
                    Swal.fire('Error', 'La contraseña debe tener al menos 8 caracteres', 'error');
                    return false;
                }
                if (!/[A-Z]/.test(password)) {
                    Swal.fire('Error', 'La contraseña debe tener al menos una mayúscula', 'error');
                    return false;
                }
                if (!/[0-9]/.test(password)) {
                    Swal.fire('Error', 'La contraseña debe tener al menos un número', 'error');
                    return false;
                }
                if (!/[!@#$%^&*()\-_=+{};:,<.>]/.test(password)) {
                    Swal.fire('Error', 'La contraseña debe tener al menos un carácter especial', 'error');
                    return false;
                }
            }

            // Validación de nueva contraseña para edición
            if (modo === 'actualizar' && newPassword !== '') {
                if (newPassword.length < 8) {
                    Swal.fire('Error', 'La nueva contraseña debe tener al menos 8 caracteres', 'error');
                    return false;
                }
                if (!/[A-Z]/.test(newPassword)) {
                    Swal.fire('Error', 'La nueva contraseña debe tener al menos una mayúscula', 'error');
                    return false;
                }
                if (!/[0-9]/.test(newPassword)) {
                    Swal.fire('Error', 'La nueva contraseña debe tener al menos un número', 'error');
                    return false;
                }
                if (!/[!@#$%^&*()\-_=+{};:,<.>]/.test(newPassword)) {
                    Swal.fire('Error', 'La nueva contraseña debe tener al menos un carácter especial', 'error');
                    return false;
                }
            }

            // Mostrar loading
            document.getElementById('loadingOverlay').style.display = 'flex';
            document.getElementById('submitBtn').disabled = true;

            return true;
        }

        function mostrarServicioSeleccionado() {
            const select = document.getElementById('servicio_id');
            const infoDiv = document.getElementById('info-servicio');
            const nombreSpan = document.getElementById('nombre-servicio-seleccionado');
            const precioSpan = document.getElementById('precio-servicio-seleccionado');
            const precioInput = document.getElementById('precio_mensual');

            const selectedOption = select.options[select.selectedIndex];

            if (selectedOption.value !== '') {
                const nombre = selectedOption.text.split(' - $')[0];
                const precio = selectedOption.getAttribute('data-precio');

                nombreSpan.textContent = nombre;
                precioSpan.textContent = '$' + parseFloat(precio).toLocaleString('es-MX', { minimumFractionDigits: 2 });

                if (!precioInput.value) {
                    precioInput.value = parseFloat(precio).toFixed(2);
                }

                infoDiv.style.display = 'block';
            } else {
                infoDiv.style.display = 'none';
                if (!precioInput.value) {
                    precioInput.value = '';
                }
            }
        }

        function formatCurrency(input) {
            // Remover formato previo
            let value = input.value.replace(/[^\d.]/g, '');

            if (value) {
                // Formatear como moneda
                value = parseFloat(value).toFixed(2);
                input.value = value;
            }
        }

        function eliminarCliente(id) {
            Swal.fire({
                title: '¿Eliminar cliente?',
                text: 'Esta acción no se puede deshacer. También se cancelarán sus contratos.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    document.getElementById('loadingOverlay').style.display = 'flex';
                    window.location.href = 'clientes.php?eliminar=' + id + '&csrf=<?= $CSRF ?>';
                }
            });
        }

        function editarCliente(id) {
            Swal.fire({
                title: '¿Editar cliente?',
                text: '¿Qué tipo de edición deseas realizar?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Solo datos básicos',
                cancelButtonText: 'Datos + Contraseña',
                showDenyButton: true,
                denyButtonText: 'Cancelar',
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#6c757d',
                denyButtonColor: '#d33'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Solo datos básicos
                    document.getElementById('loadingOverlay').style.display = 'flex';
                    setTimeout(() => {
                        window.location.href = 'clientes.php?editar=' + id + '&tipo=basicos';
                    }, 100);
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    // Datos + contraseña
                    document.getElementById('loadingOverlay').style.display = 'flex';
                    setTimeout(() => {
                        window.location.href = 'clientes.php?editar=' + id + '&tipo=completo';
                    }, 100);
                }
                // Si es "Cancelar" (deny), no hace nada
            });
        }

        $(function () {
            // Inicializar Select2 para servicios
            $('#servicio_id').select2({
                placeholder: "Seleccione un servicio (opcional)",
                allowClear: true,
                language: "es",
                width: '100%'
            });

            // Mostrar servicio seleccionado al cambiar
            $('#servicio_id').on('change', function () {
                mostrarServicioSeleccionado();
            });

            // Formatear precio mensual como moneda
            $('#precio_mensual').on('blur', function () {
                formatCurrency(this);
            });

            $('#tablaClientes').DataTable({
                dom: '<"top"lf>rt<"bottom"ip><"clear">',
                searching: true,
                pageLength: 10,
                lengthMenu: [[5, 10, 25, 50, -1], [5, 10, 25, 50, 'Todos']],
                order: [[6, 'desc']],
                responsive: false,
                autoWidth: false,
                language: {
                    decimal: '',
                    emptyTable: 'No hay clientes registrados',
                    info: 'Mostrando _START_ a _END_ de _TOTAL_ clientes',
                    infoEmpty: 'Mostrando 0 a 0 de 0 clientes',
                    infoFiltered: '(filtrado de _MAX_ clientes totales)',
                    lengthMenu: 'Mostrar _MENU_ clientes',
                    loadingRecords: 'Cargando...',
                    processing: 'Procesando...',
                    search: 'Buscar:',
                    zeroRecords: 'No se encontraron clientes coincidentes',
                    paginate: {
                        first: 'Primero',
                        last: 'Último',
                        next: 'Siguiente',
                        previous: 'Anterior'
                    }
                }
            });

            // Mostrar servicio seleccionado al cargar la página
            mostrarServicioSeleccionado();
        });

        // Ocultar loading si la página se carga completamente
        window.addEventListener('load', function () {
            document.getElementById('loadingOverlay').style.display = 'none';
        });
    </script>
</body>

</html>