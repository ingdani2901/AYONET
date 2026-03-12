<?php
// functions/notificaciones.php

/**
 * Sistema de Notificaciones para AYONET
 * Maneja notificaciones para administradores y técnicos
 */

/**
 * Crea una nueva notificación en la base de datos
 */
function crearNotificacion(PDO $pdo, int $idUsuario, string $tipo, string $mensaje, ?int $idIncidencia = null, ?int $idTecnico = null, string $prioridad = 'normal')
{
    try {
        $stmt = $pdo->prepare("
            INSERT INTO notificaciones 
            (id_usuario, tipo_notificacion, mensaje, id_incidencia, id_tecnico_asignado, prioridad, fecha_envio) 
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $resultado = $stmt->execute([$idUsuario, $tipo, $mensaje, $idIncidencia, $idTecnico, $prioridad]);

        if (!$resultado) {
            error_log("Error: No se pudo insertar notificación para usuario $idUsuario");
            return false;
        }

        return true;
    } catch (Exception $e) {
        error_log("Error creando notificación: " . $e->getMessage());
        return false;
    }
}

/**
 * Notifica a todos los administradores del sistema
 */
function notificarAdministradores(PDO $pdo, string $mensaje, ?int $idIncidencia = null, string $prioridad = 'alta')
{
    try {
        // Obtener todos los usuarios admin
        $stmt = $pdo->prepare("
            SELECT u.id_usuario 
            FROM usuarios u 
            JOIN roles r ON u.id_rol = r.id_rol 
            WHERE r.nombre_rol = 'admin' AND u.eliminado = false
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $notificados = 0;
        foreach ($admins as $admin) {
            if (crearNotificacion($pdo, $admin['id_usuario'], 'sistema', $mensaje, $idIncidencia, null, $prioridad)) {
                $notificados++;
            }
        }

        error_log("Notificaciones enviadas: $notificados administradores notificados");
        return $notificados;

    } catch (Exception $e) {
        error_log("Error notificando administradores: " . $e->getMessage());
        return 0;
    }
}

/**
 * Notifica a un técnico específico
 */
function notificarTecnico(PDO $pdo, int $idTecnico, string $mensaje, ?int $idIncidencia = null, string $prioridad = 'alta')
{
    try {
        // Obtener id_usuario del técnico
        $stmt = $pdo->prepare("SELECT id_usuario FROM tecnicos WHERE id_tecnico = ? AND activo = true");
        $stmt->execute([$idTecnico]);
        $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($tecnico && isset($tecnico['id_usuario'])) {
            $resultado = crearNotificacion($pdo, $tecnico['id_usuario'], 'asignacion', $mensaje, $idIncidencia, $idTecnico, $prioridad);

            if ($resultado) {
                error_log("Notificación enviada al técnico $idTecnico");
                return true;
            } else {
                error_log("Error: No se pudo notificar al técnico $idTecnico");
                return false;
            }
        } else {
            error_log("Error: Técnico $idTecnico no encontrado o inactivo");
            return false;
        }

    } catch (Exception $e) {
        error_log("Error notificando técnico: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene las notificaciones pendientes de un usuario
 */
function obtenerNotificacionesPendientes(PDO $pdo, int $idUsuario)
{
    try {
        $stmt = $pdo->prepare("
            SELECT 
                n.*, 
                i.titulo as incidencia_titulo, 
                i.estado as incidencia_estado,
                t.nombre_completo as tecnico_nombre
            FROM notificaciones n 
            LEFT JOIN incidencias i ON n.id_incidencia = i.id_incidencia
            LEFT JOIN tecnicos t ON n.id_tecnico_asignado = t.id_tecnico
            WHERE n.id_usuario = ? AND n.leido = false 
            ORDER BY 
                CASE n.prioridad 
                    WHEN 'alta' THEN 1
                    WHEN 'media' THEN 2
                    WHEN 'normal' THEN 3
                    ELSE 4
                END,
                n.fecha_envio DESC
            LIMIT 20
        ");
        $stmt->execute([$idUsuario]);
        $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $notificaciones;

    } catch (Exception $e) {
        error_log("Error obteniendo notificaciones: " . $e->getMessage());
        return [];
    }
}

/**
 * Marca una notificación como leída
 */
function marcarNotificacionLeida(PDO $pdo, int $idNotificacion)
{
    try {
        $stmt = $pdo->prepare("UPDATE notificaciones SET leido = true WHERE id_notificacion = ?");
        return $stmt->execute([$idNotificacion]);
    } catch (Exception $e) {
        error_log("Error marcando notificación como leída: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene el número total de notificaciones pendientes
 */
function contarNotificacionesPendientes(PDO $pdo, int $idUsuario)
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM notificaciones 
            WHERE id_usuario = ? AND leido = false
        ");
        $stmt->execute([$idUsuario]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        return $resultado ? (int) $resultado['total'] : 0;

    } catch (Exception $e) {
        error_log("Error contando notificaciones: " . $e->getMessage());
        return 0;
    }
}

/**
 * Limpia notificaciones antiguas (más de 30 días)
 */
function limpiarNotificacionesAntiguas(PDO $pdo)
{
    try {
        $stmt = $pdo->prepare("
            DELETE FROM notificaciones 
            WHERE fecha_envio < NOW() - INTERVAL '30 days'
        ");
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Error limpiando notificaciones antiguas: " . $e->getMessage());
        return false;
    }
}
?>