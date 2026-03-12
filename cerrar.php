<?php
// cerrar.php — cierra sesión de forma segura y redirige a login
session_start();

// Validar token (si llega). Si no existe, igual cerramos (mejor UX).
$ok = true;
if (isset($_POST['token'], $_SESSION['logout_token'])) {
  $ok = hash_equals($_SESSION['logout_token'], $_POST['token']);
}

// Limpieza de sesión
$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $params = session_get_cookie_params();
  setcookie(
    session_name(),
    '',
    time() - 42000,
    $params["path"],
    $params["domain"],
    $params["secure"],
    $params["httponly"]
  );
}
session_destroy();

// Evita reuso de ID
session_start();
session_regenerate_id(true);

// Mensaje de salida (opcional para login.php)
$_SESSION['flash_bye'] = ($ok ? 'Sesión cerrada correctamente.' : 'Sesión cerrada.');

// Redirige al login
header('Location: login.php?bye=1');
exit;
