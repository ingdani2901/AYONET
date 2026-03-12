<?php
require_once __DIR__ . '/config.php';

/**
 * db.php — Crea $pdo (PDO PostgreSQL) para toda la app.
 * Compatible con tu login actual.
 * Mejoras:
 * - connect_timeout en el DSN
 * - ATTR_EMULATE_PREPARES = false
 * - SET client_encoding, TIME ZONE y search_path
 * - Conexión persistente opcional por ENV (DB_PERSISTENT=true)
 */

$dsn = sprintf(
    "pgsql:host=%s;port=%s;dbname=%s;options='-c client_encoding=UTF8' ;connect_timeout=5",
    DB_HOST,
    DB_PORT,
    DB_NAME
);

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => defined('DB_PERSISTENT') ? (bool) DB_PERSISTENT : false,
    ]);

    // Ajustes de sesión de BD útiles y seguros
    $pdo->exec("SET TIME ZONE 'America/Mexico_City'");
    $pdo->exec("SET search_path TO public"); // por claridad; ajusta si usas otros schemas

    // (Opcional) Identificar la app en PG (útil para monitoreo/pg_stat_activity)
    $app = defined('APP_NAME') ? APP_NAME : 'AYONET';
    $pdo->exec("SET application_name TO " . $pdo->quote($app));

} catch (PDOException $e) {
    // En producción: loguea en vez de mostrar el detalle
    die("Error de conexión a PostgreSQL: " . htmlspecialchars($e->getMessage()));
}
