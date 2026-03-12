<?php
/**
 * config.php — Configuración base de AYONET
 * - Compatible con tu código existente.
 * - Permite sobrescribir valores con variables de entorno (Docker/.env/host).
 * - Define constantes de app, BD y zona horaria.
 */

function env($key, $default = null)
{
  $v = getenv($key);
  return ($v === false || $v === null || $v === '') ? $default : $v;
}

/* ==== Identidad de la app ==== */
define('APP_NAME', env('APP_NAME', 'AYONET'));
define('APP_LOGO', env('APP_LOGO', 'img/logo.png'));   // ruta pública al logo
define('APP_URL_BASE', env('APP_URL_BASE', '/'));      // si sirves en subcarpeta, ajústalo

/* ==== Zona horaria ==== */
date_default_timezone_set(env('APP_TZ', 'America/Mexico_City'));

/* ==== Base de datos (PostgreSQL) ==== */
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '5432'));
define('DB_NAME', env('DB_NAME', 'proayonet'));        // <<-- NUEVO nombre de base
define('DB_USER', env('DB_USER', 'postgres'));
define('DB_PASS', env('DB_PASS', 'daniela290104'));    // cámbialo en prod

// Opcional: conexión persistente (puedes controlar por ENV: DB_PERSISTENT=true/false)
define('DB_PERSISTENT', filter_var(env('DB_PERSISTENT', ''), FILTER_VALIDATE_BOOL) ?? false);

/* ==== Seguridad de sesión (por si lo centralizas) ==== */
define('SESSION_SAMESITE', env('SESSION_SAMESITE', 'Lax'));   // 'Lax' | 'Strict' | 'None'
define('SESSION_SECURE', filter_var(env('SESSION_SECURE', ''), FILTER_VALIDATE_BOOL) ?? (!empty($_SERVER['HTTPS'])));
