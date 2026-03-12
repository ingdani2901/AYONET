<?php
session_start();
require_once __DIR__ . '/db.php';

echo "<h3>🔍 DEBUG TÉCNICO - INFORMACIÓN CRÍTICA</h3>";
echo "<div style='background:#1a1a1a; color:#fff; padding:20px; border-radius:10px;'>";

// 1. INFO DE SESIÓN
echo "<h4>📋 INFORMACIÓN DE SESIÓN:</h4>";
echo "ID Usuario: <strong>" . ($_SESSION['id_usuario'] ?? 'NO DEFINIDO') . "</strong><br>";
echo "ID Técnico: <strong>" . ($_SESSION['id_tecnico'] ?? 'NO DEFINIDO') . "</strong><br>";
echo "Rol: <strong>" . ($_SESSION['rol'] ?? 'NO DEFINIDO') . "</strong><br>";
echo "Nombre: <strong>" . ($_SESSION['nombre'] ?? 'NO DEFINIDO') . "</strong><br>";

// 2. VERIFICAR TÉCNICO EN BD
echo "<h4>👤 VERIFICACIÓN DE TÉCNICO EN BD:</h4>";
try {
    $stmt = $pdo->prepare("SELECT id_tecnico, nombre_completo FROM tecnicos WHERE id_usuario = ?");
    $stmt->execute([$_SESSION['id_usuario'] ?? 0]);
    $tecnico = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($tecnico) {
        echo "✅ <strong>TÉCNICO ENCONTRADO:</strong> " . $tecnico['nombre_completo'] . " (ID: " . $tecnico['id_tecnico'] . ")<br>";

        // 3. VERIFICAR INCIDENCIAS ASIGNADAS
        echo "<h4>📋 INCIDENCIAS ASIGNADAS A ESTE TÉCNICO:</h4>";
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM incidencias WHERE id_tecnico = ?");
        $stmt->execute([$tecnico['id_tecnico']]);
        $count = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "Total de incidencias asignadas: <strong>" . $count['total'] . "</strong><br>";

        // Mostrar detalles
        $stmt = $pdo->prepare("SELECT id_incidencia, titulo, estado, prioridad FROM incidencias WHERE id_tecnico = ? ORDER BY id_incidencia DESC");
        $stmt->execute([$tecnico['id_tecnico']]);
        $incidencias = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($incidencias) > 0) {
            foreach ($incidencias as $inc) {
                echo "• Incidencia #" . $inc['id_incidencia'] . " - " . $inc['titulo'] . " - Estado: " . $inc['estado'] . "<br>";
            }
        } else {
            echo "❌ <strong>NO HAY INCIDENCIAS ASIGNADAS</strong><br>";
        }

    } else {
        echo "❌ <strong>NO SE ENCONTRÓ TÉCNICO PARA ESTE USUARIO</strong><br>";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "<br>";
}

// 4. VERIFICAR ÚLTIMAS INCIDENCIAS CREADAS
echo "<h4>🆕 ÚLTIMAS 5 INCIDENCIAS CREADAS:</h4>";
try {
    $stmt = $pdo->prepare("
        SELECT i.id_incidencia, i.titulo, i.estado, i.id_tecnico, 
               t.nombre_completo as tecnico_nombre, c.nombre_completo as cliente_nombre
        FROM incidencias i
        LEFT JOIN tecnicos t ON i.id_tecnico = t.id_tecnico
        JOIN clientes c ON i.id_cliente = c.id_cliente
        ORDER BY i.id_incidencia DESC 
        LIMIT 5
    ");
    $stmt->execute();
    $ultimas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ultimas as $inc) {
        echo "• #" . $inc['id_incidencia'] . " - " . $inc['titulo'] .
            " - Cliente: " . $inc['cliente_nombre'] .
            " - Técnico: " . ($inc['tecnico_nombre'] ?? 'SIN ASIGNAR') .
            " - Estado: " . $inc['estado'] . "<br>";
    }
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "<br>";
}

echo "</div>";
echo "<p><strong>🎯 INSTRUCCIONES:</strong> Copia y pega TODO lo que aparece arriba y me lo compartes.</p>";
?>