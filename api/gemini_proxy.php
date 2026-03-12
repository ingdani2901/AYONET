<?php
header("Content-Type: application/json");

$apiKey = "AIzaSyCR0pLwMnd2uGsr0lPvQvQ928UMYHRp1L4";

// Modelo válido
$modelName = "models/gemini-2.5-flash";

// Endpoint de generación de contenido
$url = "https://generativelanguage.googleapis.com/v1beta/{$modelName}:generateContent?key={$apiKey}";

// Lee el cuerpo del POST
$input = file_get_contents("php://input");

// Si no se recibió contenido, muestra error
if (!$input) {
    echo json_encode(["error" => "No se recibió contenido."]);
    exit;
}

// Inicializa cURL
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $input);
curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout de 15 segundos

$response = curl_exec($ch);

if ($response === false) {
    $errorMsg = curl_error($ch);
    // Detecta errores comunes de conexión
    if (strpos($errorMsg, 'Could not resolve host') !== false) {
        echo json_encode(["error" => "No se pudo resolver el host. Revisa tu conexión a internet o DNS."]);
    } else {
        echo json_encode(["error" => "Error cURL: " . $errorMsg]);
    }
} else {
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpCode >= 400) {
        echo json_encode(["error" => "HTTP {$httpCode}: " . $response]);
    } else {
        echo $response;
    }
}

curl_close($ch);
