<?php
/**
 * LOGIN AYONET — proayonet (PostgreSQL)
 * Versión Mejorada - Búsqueda unificada
 * Tablas: public.usuarios, public.roles, public.clientes, public.accesos, public.tecnicos
 * Autentica usuarios (admin/tecnico) Y clientes Y técnicos en una sola consulta.
 * Redirige según el rol.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

/* ================== Seguridad de sesión ================== */
ini_set('session.use_strict_mode', 1);
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'httponly' => true,
  'samesite' => 'Lax',
  'secure' => !empty($_SERVER['HTTPS']),
]);
session_start();

/* ================== Conexión a BD ================== */
require_once __DIR__ . '/db.php';

/* ================== Util: normalizar rol ================== */
function normalizarRol($nombreRol)
{
  $map = [
    'administrador' => 'admin',
    'técnico' => 'tecnico',
    'tecnico' => 'tecnico',
    'cliente' => 'cliente',
  ];
  $k = mb_strtolower($nombreRol ?? '', 'UTF-8');
  return $map[$k] ?? 'cliente';
}

/* ================== Rate limit básico ================== */
$errorMsg = null;
$maxIntentos = 5;
$cooldown = 60;

if (!isset($_SESSION['login_attempts'])) {
  $_SESSION['login_attempts'] = 0;
  $_SESSION['login_last'] = 0;
}

/* ================== POST: login ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  if ($_SESSION['login_attempts'] >= $maxIntentos && (time() - $_SESSION['login_last'] < $cooldown)) {
    $resta = $cooldown - (time() - $_SESSION['login_last']);
    $errorMsg = "Demasiados intentos. Intenta de nuevo en {$resta}s.";
  } else {
    $usuario = trim($_POST['usuario'] ?? ''); // Este campo es el EMAIL
    $password = trim($_POST['password'] ?? '');

    if ($usuario !== '' && $password !== '') {

      $userFound = false;

      /* === BÚSQUEDA UNIFICADA EN UNA SOLA CONSULTA === */
      $stmt = $pdo->prepare("
        (
          -- Buscar en USUARIOS (admins y técnicos)
          SELECT
            CAST(u.id_usuario AS INTEGER) as id_usuario,
            u.nombre_completo,
            u.email,
            u.password_hash,
            COALESCE(r.nombre_rol, 'cliente') AS rol,
            'usuario' as tipo_tabla,
            CAST(NULL AS INTEGER) as id_cliente,
            CAST(NULL AS INTEGER) as id_tecnico
          FROM public.usuarios u
          LEFT JOIN public.roles r
                ON r.id_rol = u.id_rol
               AND COALESCE(r.eliminado, false) = false
          WHERE LOWER(u.email) = LOWER(:u)
            AND COALESCE(u.eliminado, false) = false
        )
        UNION ALL
        (
          -- Buscar en CLIENTES
          SELECT
            CAST(COALESCE(c.id_usuario, c.id_cliente) AS INTEGER) as id_usuario,
            c.nombre_completo,
            c.email,
            c.password_hash,
            'cliente' AS rol,
            'cliente' as tipo_tabla,
            CAST(c.id_cliente AS INTEGER) as id_cliente,
            CAST(NULL AS INTEGER) as id_tecnico
          FROM public.clientes c
          WHERE LOWER(c.email) = LOWER(:u)
            AND COALESCE(c.eliminado, false) = false
        )
        UNION ALL
        (
          -- Buscar en TÉCNICOS (DIRECTAMENTE)
          SELECT
            CAST(t.id_tecnico AS INTEGER) as id_usuario,
            t.nombre_completo,
            t.email,
            t.password_hash,
            'tecnico' AS rol,
            'tecnico' as tipo_tabla,
            CAST(NULL AS INTEGER) as id_cliente,
            CAST(t.id_tecnico AS INTEGER) as id_tecnico
          FROM public.tecnicos t
          WHERE LOWER(t.email) = LOWER(:u)
            AND COALESCE(t.eliminado, false) = false
            AND COALESCE(t.activo, true) = true
        )
        LIMIT 1
      ");
      $stmt->execute([':u' => $usuario]);
      $user = $stmt->fetch();

      if ($user && password_verify($password, $user['password_hash'])) {
        $userFound = true;

        $_SESSION['login_attempts'] = 0;
        $_SESSION['login_last'] = 0;
        session_regenerate_id(true);

        // Configurar sesión según el tipo de usuario
        if ($user['tipo_tabla'] === 'usuario') {
          $_SESSION['id_usuario'] = (int) $user['id_usuario'];
          $_SESSION['rol'] = normalizarRol($user['rol']);

          // 🔥 OBTENER ID_TECNICO SI ES TÉCNICO
          if ($_SESSION['rol'] === 'tecnico') {
            try {
              $stmtTec = $pdo->prepare("SELECT id_tecnico FROM tecnicos WHERE id_usuario = ?");
              $stmtTec->execute([$_SESSION['id_usuario']]);
              $tecnico = $stmtTec->fetch(PDO::FETCH_ASSOC);

              if ($tecnico) {
                $_SESSION['id_tecnico'] = $tecnico['id_tecnico'];
              }
            } catch (Throwable $e) {
              // No romper el flujo si falla
              error_log("Error obteniendo id_tecnico: " . $e->getMessage());
            }
          }
        } else if ($user['tipo_tabla'] === 'tecnico') {
          // 🔥 NUEVO: USUARIO ES UN TÉCNICO DIRECTO
          $_SESSION['id_usuario'] = (int) $user['id_usuario'];
          $_SESSION['rol'] = 'tecnico';
          $_SESSION['id_tecnico'] = (int) $user['id_tecnico'];
        } else {
          // Cliente
          $_SESSION['id_cliente'] = (int) $user['id_cliente'];
          $_SESSION['id_usuario'] = $user['id_usuario'] ? (int) $user['id_usuario'] : null;
          $_SESSION['rol'] = 'cliente';
        }

        $_SESSION['nombre'] = $user['nombre_completo'];
        $_SESSION['welcome_name'] = $user['nombre_completo'];

        // Log de acceso (solo para usuarios del sistema, no clientes)
        if ($user['tipo_tabla'] === 'usuario' || $user['tipo_tabla'] === 'tecnico') {
          try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $ins = $pdo->prepare("
              INSERT INTO public.accesos (id_usuario, fecha_ingreso, direccion_ip)
              VALUES (:id_usuario, NOW(), :ip)
            ");
            $ins->execute([':id_usuario' => (int) $user['id_usuario'], ':ip' => $ip]);
          } catch (Throwable $e) {
            // No romper el flujo si falla el log
            error_log("Error en log de acceso: " . $e->getMessage());
          }
        }

        // Redirección según rol
        if ($_SESSION['rol'] === 'admin') {
          header('Location: menu.php');
        } else if ($_SESSION['rol'] === 'tecnico') {
          header('Location: tecnico/menu_tecnico.php');
        } else if ($_SESSION['rol'] === 'cliente') {
          header('Location: cliente/menu_cliente.php');
        } else {
          $errorMsg = 'Rol de usuario no configurado.';
          session_destroy();
        }
        exit;

      }

      /* === ERROR SI NO SE ENCONTRÓ EN NINGUNA TABLA === */
      if (!$userFound) {
        $_SESSION['login_attempts']++;
        $_SESSION['login_last'] = time();
        $errorMsg = 'Usuario o contraseña incorrectos.';
      }

    } else {
      $errorMsg = 'Completa usuario y contraseña.';
    }
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>AYONET | Login</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --bg1: #0b1020;
      --bg2: #0c0e2b;
      --neon1: #00d4ff;
      --neon2: #6a00ff;
      --neon3: #ff007a;
      --muted: #b9c1ff;
    }

    * {
      box-sizing: border-box
    }

    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      font-family: "Poppins", sans-serif;
      color: #fff;
      background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
      overflow: hidden;
    }

    .bg-glow::before,
    .bg-glow::after {
      content: "";
      position: fixed;
      width: 60vmax;
      height: 60vmax;
      border-radius: 50%;
      filter: blur(90px);
      opacity: .35;
      z-index: -2;
      animation: float 18s ease-in-out infinite;
    }

    .bg-glow::before {
      background: radial-gradient(closest-side, var(--neon2), transparent 65%);
      top: -20vmax;
      left: -10vmax;
    }

    .bg-glow::after {
      background: radial-gradient(closest-side, var(--neon3), transparent 65%);
      bottom: -25vmax;
      right: -15vmax;
      animation-delay: -6s;
    }

    @keyframes float {
      50% {
        transform: translateY(-30px)
      }
    }

    .card {
      width: min(980px, 94vw);
      height: min(560px, 88vh);
      background: linear-gradient(180deg, rgba(255, 255, 255, .06), rgba(255, 255, 255, .03));
      border: 1px solid rgba(255, 255, 255, .15);
      border-radius: 22px;
      overflow: hidden;
      position: relative;
      box-shadow: 0 20px 70px rgba(0, 0, 0, .55);
      display: grid;
      grid-template-columns: 460px 1fr;
      backdrop-filter: blur(10px);
    }

    .card::after {
      content: "";
      position: absolute;
      inset: -2px;
      border-radius: 24px;
      z-index: -1;
      background: conic-gradient(from 200deg, var(--neon1), var(--neon2), var(--neon3), var(--neon1));
      filter: blur(14px);
      opacity: .25;
    }

    .left {
      background: linear-gradient(160deg, rgba(10, 13, 35, .9), rgba(12, 18, 48, .92));
      padding: 44px 36px;
      display: flex;
      flex-direction: column;
    }

    .brand {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
      margin-bottom: 24px;
    }

    .brand .logo {
      width: 120px;
      height: auto;
      border-radius: 12px;
      box-shadow: 0 0 25px rgba(0, 212, 255, .25), 0 0 40px rgba(106, 0, 255, .2);
    }

    .brand h1 {
      display: none;
    }

    .subtitle {
      color: var(--muted);
      margin: -12px 0 22px;
      font-size: .93rem
    }

    .field {
      margin: 14px 0;
    }

    .control {
      position: relative;
    }

    .control>i {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      color: var(--neon1);
      opacity: .9;
      pointer-events: none;
    }

    .toggle {
      position: absolute;
      right: 10px;
      top: 50%;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      display: grid;
      place-items: center;
      border: none;
      border-radius: 10px;
      background: transparent;
      color: var(--neon1);
      opacity: 0.9;
      cursor: pointer;
      transition: color 0.25s, opacity 0.25s, transform 0.15s;
    }

    .toggle:hover {
      color: #00eaff;
      opacity: 1;
    }

    .toggle:active {
      transform: translateY(-50%) scale(.96);
    }

    .input {
      width: 100%;
      padding: 15px 52px 15px 46px;
      border-radius: 12px;
      outline: none;
      border: 1px solid rgba(255, 255, 255, .14);
      background: rgba(255, 255, 255, .06);
      color: #fff;
      transition: .25s;
      font-size: 1rem;
    }

    .input::placeholder {
      color: #c9d0ff7f
    }

    .input:focus {
      border-color: var(--neon1);
      box-shadow: 0 0 0 6px rgba(0, 212, 255, .17)
    }

    .inline-hints {
      display: flex;
      justify-content: space-between;
      margin: 6px 2px 2px;
      font-size: .86rem;
      color: #cfd2ff
    }

    .caps {
      display: none;
      color: #ffd166
    }

    .meter {
      height: 8px;
      background: rgba(255, 255, 255, .08);
      border-radius: 999px;
      overflow: hidden;
      margin-top: 8px;
      border: 1px solid rgba(255, 255, 255, .12)
    }

    .meter .bar {
      height: 100%;
      width: 0%;
      background: linear-gradient(90deg, #ff3b6e, #ffb400, #00d4ff, #38f3a0);
      box-shadow: 0 0 16px rgba(0, 212, 255, .4);
      transition: width .35s ease;
    }

    .actions {
      display: flex;
      justify-content: space-between;
      align-items: center;
      color: #cfd2ff;
      font-size: .9rem;
      margin: 10px 2px 12px
    }

    .actions a {
      color: #c7d1ff;
      text-decoration: none
    }

    .actions a:hover {
      text-decoration: underline
    }

    .btn {
      width: 100%;
      padding: 13px 14px;
      border-radius: 12px;
      border: none;
      cursor: pointer;
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #051027;
      font-weight: 800;
      letter-spacing: .3px;
      box-shadow: 0 12px 28px rgba(0, 212, 255, .25);
      transition: transform .15s, box-shadow .2s;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: 0 16px 40px rgba(0, 212, 255, .35)
    }

    .right {
      position: relative;
      background: radial-gradient(1200px 900px at 15% 10%, #0a0f2f 10%, #0b0e24 45%, #060916 100%);
      overflow: hidden;
    }

    #waves {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
    }

    .right::after {
      content: "";
      position: absolute;
      inset: 0;
      pointer-events: none;
      background:
        radial-gradient(600px 200px at 60% 40%, rgba(106, 0, 255, .55), transparent 70%),
        radial-gradient(400px 160px at 70% 65%, rgba(0, 212, 255, .45), transparent 70%),
        radial-gradient(550px 260px at 40% 80%, rgba(255, 0, 122, .35), transparent 70%);
      mix-blend-mode: screen;
      opacity: .7;
      animation: shimmer 12s ease-in-out infinite alternate;
    }

    @keyframes shimmer {
      0% {
        transform: translateX(-10px)
      }

      100% {
        transform: translateX(10px)
      }
    }

    @media (max-width:900px) {
      .card {
        grid-template-columns: 1fr;
        height: auto
      }

      .right {
        height: 280px
      }
    }
  </style>
</head>

<body class="bg-glow">
  <main class="card" role="main" aria-label="Login AYONET">
    <section class="left" aria-label="Formulario de inicio de sesión">

      <div class="brand">
        <img src="img/logo.jpg" alt="Logo de AYONET" class="logo">
      </div>

      <h2 style="margin:0 0 6px">El mundo en tus manos</h2>
      <p class="subtitle">Ingresa tus credenciales.</p>

      <form id="formLogin" method="post" autocomplete="off" novalidate>
        <div class="field">
          <div class="control">
            <i class="fa-solid fa-user"></i>
            <input class="input" type="text" name="usuario" placeholder="Correo electrónico" required>
          </div>
        </div>

        <div class="field">
          <div class="control">
            <i class="fa-solid fa-lock"></i>
            <input class="input" type="password" name="password" id="password" placeholder="Contraseña" required>
            <button class="toggle" type="button" id="peek"><i class="fa-regular fa-eye"></i></button>
          </div>
          <div class="inline-hints">
            <span class="caps" id="caps">Bloq Mayús activado</span>
            <span style="opacity:.85">Mantén presionado el ojo para ver</span>
          </div>
          <div class="meter">
            <div class="bar" id="strengthBar"></div>
          </div>
        </div>

        <div class="actions">
          <label><input type="checkbox"> Recordarme</label>
          <a href="#">¿Olvidaste tu contraseña?</a>
        </div>

        <button class="btn" type="submit">Ingresar</button>
      </form>
    </section>

    <section class="right" aria-hidden="true">
      <canvas id="waves"></canvas>
    </section>
  </main>

  <?php if ($errorMsg): ?>
    <script>
      Swal.fire({ icon: 'error', title: 'Oops', text: <?= json_encode($errorMsg) ?> });
    </script>
  <?php endif; ?>

  <script>
    // Ondas animadas
    const w = document.getElementById("waves"), ctx = w.getContext("2d"); let t = 0;
    function fit() { w.width = w.clientWidth; w.height = w.clientHeight } addEventListener("resize", fit); fit();
    function wave(y, a, s, l, c, b) {
      ctx.save(); ctx.shadowColor = c; ctx.shadowBlur = b; ctx.lineWidth = 2.2; ctx.strokeStyle = c; ctx.beginPath();
      for (let x = 0; x < w.width; x++) { const yv = y + Math.sin((x / l) + t * s) * a + Math.cos((x / (l * 1.8)) - t * s * .8) * (a * .45); x ? ctx.lineTo(x, yv) : ctx.moveTo(x, yv) } ctx.stroke(); ctx.restore();
    }
    (function anim() {
      ctx.clearRect(0, 0, w.width, w.height);
      ctx.fillStyle = "rgba(10,12,40,1)"; ctx.fillRect(0, 0, w.width, w.height);
      wave(w.height * .35, 26, .9, 120, "rgba(0,212,255,.9)", 24);
      wave(w.height * .52, 34, .7, 150, "rgba(106,0,255,.9)", 20);
      wave(w.height * .70, 28, .6, 170, "rgba(255,0,122,.85)", 22);
      wave(w.height * .58, 18, 1.2, 90, "rgba(0,212,255,.7)", 16);
      t += .02; requestAnimationFrame(anim);
    })();

    // Ojo presionado + caps + fuerza
    const pass = document.getElementById('password'),
      peek = document.getElementById('peek'),
      caps = document.getElementById('caps'),
      bar = document.getElementById('strengthBar');
    const show = () => pass.type = 'text', hide = () => pass.type = 'password';
    ['mousedown', 'touchstart'].forEach(ev => peek.addEventListener(ev, show));
    ['mouseup', 'mouseleave', 'touchend', 'touchcancel', 'blur'].forEach(ev => peek.addEventListener(ev, hide));
    pass.addEventListener('keydown', e => { if (typeof e.getModifierState === 'function') { caps.style.display = e.getModifierState('CapsLock') ? 'inline-block' : 'none'; } });
    pass.addEventListener('keyup', e => { if (typeof e.getModifierState === 'function') { caps.style.display = e.getModifierState('CapsLock') ? 'inline-block' : 'none'; } });
    function strength(p) { let s = 0; if (p.length >= 8) s++; if (/[A-Z]/.test(p)) s++; if (/[a-z]/.test(p)) s++; if (/\d/.test(p)) s++; if (/[^A-Za-z0-9]/.test(p)) s++; return Math.min(s, 4); }
    pass.addEventListener('input', () => { const pct = [0, 25, 50, 75, 100][strength(pass.value)]; bar.style.width = pct + '%'; });
  </script>
</body>

</html>