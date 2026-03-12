<?php
session_start();

// CSRF simple
if (empty($_SESSION['logout_token'])) {
  $_SESSION['logout_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['logout_token'];

$nombre = ($_SESSION['nombre'] ?? 'Usuario') . ' ' . ($_SESSION['apepat'] ?? '');
$rol = strtoupper($_SESSION['rol'] ?? 'USUARIO');

// Determinar a qué menú redirigir según el rol
$menu_redirect = "menu.php"; // Por defecto

if (isset($_SESSION['rol'])) {
  switch ($_SESSION['rol']) {
    case 'cliente':
      $menu_redirect = "cliente/menu_cliente.php";
      break;
    case 'tecnico':
      $menu_redirect = "tecnico/menu_tecnico.php";
      break;
    case 'administrador':
      $menu_redirect = "menu.php";
      break;
    default:
      $menu_redirect = "menu.php";
  }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1.0">
  <title>AYONET · Cerrar sesión</title>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;500;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    :root {
      --neon1: #00d4ff;
      --neon2: #6a00ff;
      --neon3: #ff007a;
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
      overflow: hidden;
      background: radial-gradient(1100px 600px at 15% 15%, #12183e 0%, #060915 55%) fixed;
    }

    /* glow fondo */
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

    /* topbar */
    .topbar {
      margin: 18px;
      padding: 10px 16px;
      border-radius: 18px;
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .14);
      backdrop-filter: blur(12px);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 10px
    }

    .logo {
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%),
        radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%), #0c1133;
      box-shadow: 0 0 18px rgba(106, 0, 255, .35) inset, 0 0 25px rgba(0, 212, 255, .18);
      border: 1px solid rgba(255, 255, 255, .18);
    }

    .pill {
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #051027;
      font-weight: 800;
      border-radius: 999px;
      padding: 6px 10px;
      font-size: .8rem
    }

    /* Fallback sin JS (oculto por defecto) */
    .fallback {
      display: none;
      position: fixed;
      inset: 0;
      display: grid;
      place-items: center;
      padding: 20px;
    }

    .fallback>form {
      width: min(560px, 92vw);
      background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
      border: 1px solid rgba(255, 255, 255, .16);
      border-radius: 20px;
      padding: 26px 22px;
      text-align: center;
      box-shadow: 0 16px 50px rgba(0, 0, 0, .45);
    }

    .fallback h2 {
      margin: 6px 0
    }

    .fallback p {
      color: #cfe1ff;
      opacity: .9
    }

    .fallback .row {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-top: 14px
    }

    .fallback .btn {
      padding: 12px 18px;
      border-radius: 12px;
      border: 1px solid transparent;
      cursor: pointer;
      font-weight: 600
    }

    .fallback .primary {
      background: linear-gradient(90deg, var(--neon1), var(--neon3));
      color: #061022
    }

    .fallback .ghost {
      background: rgba(255, 255, 255, .06);
      border-color: rgba(255, 255, 255, .18);
      color: #fff
    }

    /* Si no hay JS, mostramos el fallback */
    noscript+.fallback {
      display: grid;
    }
  </style>
</head>

<body class="bg">

  <header class="topbar">
    <div class="brand">
      <div class="logo"></div>
      <div>
        <div style="font-weight:700;letter-spacing:.3px">AYONET · Panel</div>
        <small style="color:#cfe1ff">Sesión de: <?= htmlspecialchars($nombre) ?></small>
      </div>
    </div>
    <span class="pill"><?= htmlspecialchars($rol) ?></span>
  </header>

  <!-- Fallback sin JS -->
  <noscript></noscript>
  <div class="fallback">
    <form action="cerrar.php" method="post">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <h2>¿Cerrar sesión?</h2>
      <p>Se cerrará tu sesión de forma segura.</p>
      <div class="row">
        <button class="btn primary" type="submit">Sí, cerrar sesión</button>
        <a class="btn ghost" href="<?php echo htmlspecialchars($menu_redirect); ?>">Regresar al panel</a>
      </div>
    </form>
  </div>
</body>

</html>