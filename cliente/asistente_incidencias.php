<?php
// === CONFIGURACIÓN BASE Y SEGURIDAD ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../db.php';

// === SOLUCIÓN: Obtener el id_cliente si no existe ===
if (!isset($_SESSION['id_cliente']) || empty($_SESSION['id_cliente'])) {
    try {
        $stmt = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_usuario = ? AND eliminado = false");
        $stmt->execute([$_SESSION['id_usuario']]);
        $cliente_data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($cliente_data && isset($cliente_data['id_cliente'])) {
            $_SESSION['id_cliente'] = (int) $cliente_data['id_cliente'];
        }
    } catch (Exception $e) {
        // Si hay error, redirigir al login
        header("Location: ../login.php");
        exit;
    }
}

// Verifica rol
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'cliente') {
    header("Location: ../login.php");
    exit;
}

$id_cliente = (int) $_SESSION['id_cliente'];
$nombreCliente = htmlspecialchars($_SESSION['welcome_name'] ?? 'Cliente');
$page_title = 'Asistente de Incidencias';
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
            --neon4: #00ff88;
            --neon5: #ffaa00;
            --neon6: #9d4edd;
            --muted: #cfe1ff;
            --glass: rgba(255, 255, 255, .07);
            --dark-bg: #060915;
            --mid-bg: #12183e;
            --light-bg: #1a1f4e;
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            color: #fff;
            background: radial-gradient(1200px 700px at 10% 10%, var(--mid-bg) 0%, var(--dark-bg) 55%) fixed;
            min-height: 100vh;
        }

        /* Efecto de partículas en el fondo */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                radial-gradient(circle at 20% 30%, rgba(0, 212, 255, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(106, 0, 255, 0.05) 0%, transparent 50%),
                radial-gradient(circle at 40% 80%, rgba(255, 0, 122, 0.05) 0%, transparent 50%);
            pointer-events: none;
            z-index: -1;
        }

        .wrap {
            padding: 12px;
            display: grid;
            grid-template-rows: 64px 1fr;
            gap: 12px;
            height: 100vh;
            max-width: 1400px;
            margin: 0 auto;
        }

        .topbar {
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border-radius: 16px;
            padding: 8px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .brand {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .logo {
            width: 40px;
            height: 40px;
            border-radius: 12px;
            background:
                radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%),
                radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%),
                radial-gradient(circle at 50% 20%, var(--neon4), transparent 45%),
                var(--mid-bg);
            border: 1px solid rgba(255, 255, 255, .15);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            box-shadow: 0 0 20px rgba(0, 212, 255, 0.3);
            position: relative;
            overflow: hidden;
        }

        .logo::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: linear-gradient(45deg,
                    transparent 30%,
                    rgba(255, 255, 255, 0.1) 50%,
                    transparent 70%);
            animation: shine 3s infinite linear;
            transform: rotate(45deg);
        }

        @keyframes shine {
            0% {
                transform: translateX(-100%) rotate(45deg);
            }

            100% {
                transform: translateX(100%) rotate(45deg);
            }
        }

        .brand-text h1 {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            background: linear-gradient(90deg, var(--neon1), var(--neon4), var(--neon3));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .brand-text small {
            font-size: 12px;
            color: var(--muted);
            display: block;
            margin-top: 2px;
        }

        .topbar-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .btn {
            padding: 8px 16px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .btn-home {
            background: rgba(255, 255, 255, .08);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, .1);
        }

        .btn-home:hover {
            background: rgba(255, 255, 255, .12);
            border-color: var(--neon1);
        }

        .btn-logout {
            background: linear-gradient(90deg, var(--neon3), #ff5fa2);
            color: #061022;
        }

        .btn-logout:hover {
            background: linear-gradient(90deg, #ff5fa2, var(--neon3));
            box-shadow: 0 5px 20px rgba(255, 0, 122, 0.4);
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, .06), rgba(255, 255, 255, .03));
            border-radius: 16px;
            padding: 20px;
            display: grid;
            grid-template-rows: auto 1fr auto;
            gap: 15px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
            backdrop-filter: blur(10px);
        }

        .panel-header {
            display: flex;
            align-items: center;
            gap: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid rgba(255, 255, 255, .08);
        }

        .robot-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background:
                radial-gradient(circle at 30% 30%, var(--neon1), transparent 60%),
                radial-gradient(circle at 70% 70%, var(--neon6), transparent 60%),
                linear-gradient(135deg, var(--mid-bg), var(--dark-bg));
            border: 2px solid rgba(0, 212, 255, 0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: var(--neon1);
            position: relative;
            box-shadow: 0 0 25px rgba(0, 212, 255, 0.4);
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        .robot-icon::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 12px;
            background: linear-gradient(45deg,
                    transparent 40%,
                    rgba(255, 255, 255, 0.1) 50%,
                    transparent 60%);
            animation: scan 4s linear infinite;
        }

        @keyframes scan {
            0% {
                transform: translateX(-100%) rotate(45deg);
            }

            100% {
                transform: translateX(100%) rotate(45deg);
            }
        }

        .panel-title h2 {
            margin: 0;
            font-size: 24px;
            font-weight: 700;
            background: linear-gradient(90deg, var(--neon1), var(--neon4));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .panel-title p {
            margin: 5px 0 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        #chat-container {
            overflow-y: auto;
            padding: 15px;
            background: rgba(0, 0, 0, 0.15);
            border-radius: 12px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            min-height: 400px;
            max-height: 60vh;
            border: 1px solid rgba(255, 255, 255, .05);
        }

        /* Personalizar scrollbar */
        #chat-container::-webkit-scrollbar {
            width: 8px;
        }

        #chat-container::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, .05);
            border-radius: 4px;
        }

        #chat-container::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, var(--neon1), var(--neon2));
            border-radius: 4px;
        }

        #chat-container::-webkit-scrollbar-thumb:hover {
            background: linear-gradient(180deg, var(--neon4), var(--neon1));
        }

        .chat-message {
            padding: 12px 16px;
            border-radius: 16px;
            max-width: 85%;
            line-height: 1.5;
            font-size: 14px;
            position: relative;
            animation: messageAppear 0.3s ease-out;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }

        @keyframes messageAppear {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .user-message {
            background: linear-gradient(135deg, var(--neon1), var(--neon4));
            color: #061022;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
            border: 1px solid rgba(0, 212, 255, 0.3);
        }

        .user-message::after {
            content: '';
            position: absolute;
            right: -8px;
            top: 12px;
            border-width: 8px 0 8px 8px;
            border-style: solid;
            border-color: transparent transparent transparent var(--neon1);
        }

        .bot-message {
            background: linear-gradient(135deg, rgba(255, 255, 255, .1), rgba(255, 255, 255, .05));
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        .bot-message::before {
            content: '';
            position: absolute;
            left: -8px;
            top: 12px;
            border-width: 8px 8px 8px 0;
            border-style: solid;
            border-color: transparent rgba(255, 255, 255, 0.1) transparent transparent;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .message-header i {
            font-size: 12px;
        }

        .bot-name {
            font-weight: 600;
            color: var(--neon1);
            font-size: 12px;
        }

        .user-name {
            font-weight: 600;
            color: var(--dark-bg);
            font-size: 12px;
        }

        #input-area {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .ctrl {
            width: 100%;
            padding: 12px 16px;
            border-radius: 12px;
            background: rgba(255, 255, 255, .07);
            border: 1px solid rgba(255, 255, 255, .12);
            color: #fff;
            outline: none;
            font-family: 'Poppins', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .ctrl:focus {
            border-color: var(--neon1);
            box-shadow: 0 0 0 3px rgba(0, 212, 255, 0.1);
            background: rgba(255, 255, 255, .09);
        }

        .ctrl::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }

        .btn.primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
            padding: 12px 20px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn.primary:hover {
            background: linear-gradient(90deg, var(--neon3), var(--neon1));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 0, 122, 0.3);
        }

        .btn.primary:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .escape-hatch {
            display: none;
            flex-direction: column;
            gap: 15px;
            padding: 20px;
            background: linear-gradient(135deg, rgba(255, 255, 255, .07), rgba(255, 255, 255, .03));
            border-radius: 12px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, .08);
            margin-top: 15px;
            animation: fadeIn 0.5s ease;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .escape-hatch p {
            margin: 0;
            font-size: 16px;
            color: var(--muted);
        }

        .escape-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }

        #btn-yes {
            background: linear-gradient(90deg, var(--neon4), #00cc88);
            color: #061022;
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
        }

        #btn-yes:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 255, 136, 0.3);
        }

        #btn-no {
            background: linear-gradient(90deg, var(--neon3), #ff5fa2);
            color: #061022;
            padding: 10px 20px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        #btn-no:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 0, 122, 0.3);
        }

        .typing-indicator {
            font-style: italic;
            color: var(--muted);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .typing-dots {
            display: flex;
            gap: 4px;
        }

        .typing-dot {
            width: 6px;
            height: 6px;
            background: var(--neon1);
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(1) {
            animation-delay: -0.32s;
        }

        .typing-dot:nth-child(2) {
            animation-delay: -0.16s;
        }

        @keyframes typing {

            0%,
            80%,
            100% {
                transform: scale(0);
            }

            40% {
                transform: scale(1);
            }
        }

        /* Sugerencias rápidas */
        .quick-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 10px;
        }

        .suggestion-btn {
            padding: 6px 12px;
            background: rgba(255, 255, 255, .05);
            border: 1px solid rgba(255, 255, 255, .1);
            border-radius: 20px;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .suggestion-btn:hover {
            background: rgba(255, 255, 255, .1);
            border-color: var(--neon1);
            color: #fff;
            transform: translateY(-1px);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .wrap {
                padding: 8px;
                gap: 8px;
            }

            .topbar {
                padding: 6px 12px;
            }

            .brand-text h1 {
                font-size: 16px;
            }

            .btn {
                padding: 6px 12px;
                font-size: 12px;
            }

            .panel {
                padding: 15px;
            }

            .panel-header {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }

            #chat-container {
                max-height: 50vh;
                padding: 10px;
            }

            .chat-message {
                max-width: 90%;
                font-size: 13px;
                padding: 10px 14px;
            }

            .escape-buttons {
                flex-direction: column;
            }
        }

        @media (max-width: 480px) {
            .topbar {
                flex-direction: column;
                gap: 10px;
                padding: 10px;
            }

            .brand {
                flex-direction: column;
                text-align: center;
            }

            .topbar-actions {
                width: 100%;
                justify-content: center;
            }

            .wrap {
                grid-template-rows: auto 1fr;
            }
        }
    </style>
</head>

<body>

    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo">
                    <i class="fas fa-satellite-dish"></i>
                </div>
                <div class="brand-text">
                    <h1>AYONET · Portal de Cliente</h1>
                    <small>Sesión de: <?php echo $nombreCliente; ?></small>
                </div>
            </div>
            <div class="topbar-actions">
                <a class="btn btn-home" href="menu_cliente.php">
                    <i class="fa-solid fa-house"></i> Inicio
                </a>
                <a class="btn btn-logout" href="../logout.php">
                    <i class="fa-solid fa-right-from-bracket"></i> Salir
                </a>
            </div>
        </header>

        <section class="panel">
            <div class="panel-header">
                <div class="robot-icon">
                    <i class="fa-solid fa-robot"></i>
                </div>
                <div class="panel-title">
                    <h2><i class="fa-solid fa-robot"></i> Asistente de Incidencias</h2>
                    <p>¡Hola, <strong><?php echo $nombreCliente; ?></strong>! Soy Ayo-Bot, tu asistente virtual.</p>
                </div>
            </div>

            <div id="chat-container">
                <div class="chat-message bot-message">
                    <div class="message-header">
                        <i class="fa-solid fa-robot"></i>
                        <span class="bot-name">Ayo-Bot</span>
                    </div>
                    ¡Hola, <strong><?php echo $nombreCliente; ?></strong>! 👋<br><br>
                    Por favor descríbeme tu problema (ej. "no tengo internet", "la luz de mi módem está roja",
                    etc.).<br><br>
                    <small style="color: var(--neon4); font-style: italic;">
                        <i class="fa-solid fa-lightbulb"></i> Pro tip: Sé lo más específico posible para una mejor ayuda
                    </small>
                </div>
            </div>

            <div>
                <!-- Sugerencias rápidas -->
                <div class="quick-suggestions" id="quick-suggestions">
                    <button class="suggestion-btn" data-suggestion="No tengo conexión a internet">
                        <i class="fa-solid fa-wifi"></i> Sin conexión
                    </button>
                    <button class="suggestion-btn" data-suggestion="La luz de mi módem está roja">
                        <i class="fa-solid fa-lightbulb"></i> Luz roja
                    </button>
                    <button class="suggestion-btn" data-suggestion="Internet muy lento">
                        <i class="fa-solid fa-tachometer-alt"></i> Velocidad lenta
                    </button>
                    <button class="suggestion-btn" data-suggestion="Problemas con WiFi">
                        <i class="fa-solid fa-signal"></i> WiFi no funciona
                    </button>
                </div>

                <div class="escape-hatch" id="escape-hatch">
                    <p>¿Se solucionó tu problema?</p>
                    <div class="escape-buttons">
                        <button id="btn-yes" class="btn">
                            <i class="fa-solid fa-check-circle"></i> Sí, ¡gracias!
                        </button>
                        <a href="crear_incidencia_real.php" id="btn-no" class="btn">
                            <i class="fa-solid fa-user-cog"></i> No, necesito un técnico
                        </a>
                    </div>
                </div>

                <form id="input-area">
                    <input type="text" id="chat-input" class="ctrl" placeholder="Escribe tu problema aquí..."
                        autocomplete="off">
                    <button type="submit" id="btn-send" class="btn primary">
                        <i class="fa-solid fa-paper-plane"></i> Enviar
                    </button>
                </form>
            </div>
        </section>
    </div>

    <script>
        const chatContainer = document.getElementById('chat-container');
        const inputArea = document.getElementById('input-area');
        const chatInput = document.getElementById('chat-input');
        const btnSend = document.getElementById('btn-send');
        const escapeHatch = document.getElementById('escape-hatch');
        const btnYes = document.getElementById('btn-yes');
        const quickSuggestions = document.querySelectorAll('.suggestion-btn');

        const apiUrl = 'http://localhost/ayonetproyecto/api/gemini_proxy.php';
        let chatHistory = [];

        // === PROMPT DEL ASISTENTE ===
        const systemPrompt = `Eres "Ayo-Bot", un asistente técnico Nivel 1 de AYONET, un proveedor de servicios de internet.

INSTRUCCIONES:
- Solo ayudas con problemas técnicos simples de internet
- Usa lenguaje claro y amable
- No digas que eres IA ni modelo de lenguaje
- Si el problema es complejo, sugiere contactar a un técnico
- Después de cada respuesta, pregunta si el problema se solucionó

PROBLEMAS QUE PUEDES AYUDAR:
- Reiniciar módem/router
- Verificar luces del equipo  
- Problemas de conexión WiFi
- Velocidad lenta de internet

PROBLEMAS QUE DEBES DERIVAR A TÉCNICO:
- Cableado dañado
- Problemas eléctricos
- Equipo físico dañado
- Configuraciones avanzadas`;

        function addMessage(sender, message) {
            const div = document.createElement("div");
            div.classList.add("chat-message", sender === "user" ? "user-message" : "bot-message");

            if (sender === 'bot') {
                div.innerHTML = `
                    <div class="message-header">
                        <i class="fa-solid fa-robot"></i>
                        <span class="bot-name">Ayo-Bot</span>
                    </div>
                    ${message.replace(/\n/g, "<br>")}
                `;
            } else {
                div.innerHTML = `
                    <div class="message-header">
                        <i class="fa-solid fa-user"></i>
                        <span class="user-name">Tú</span>
                    </div>
                    ${message.replace(/\n/g, "<br>")}
                `;
            }

            chatContainer.appendChild(div);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function showTypingIndicator() {
            const typingDiv = document.createElement("div");
            typingDiv.classList.add("chat-message", "bot-message", "typing-indicator");
            typingDiv.innerHTML = `
                <div class="typing-dots">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
                Ayo-Bot está escribiendo...
            `;
            chatContainer.appendChild(typingDiv);
            chatContainer.scrollTop = chatContainer.scrollHeight;
            return typingDiv;
        }

        function showEscapeHatch() {
            inputArea.style.display = 'none';
            escapeHatch.style.display = 'flex';
            escapeHatch.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }

        async function callGeminiAPI(userMessage) {
            btnSend.disabled = true;
            chatInput.disabled = true;

            // Mostrar indicador de "escribiendo..."
            const typingIndicator = showTypingIndicator();

            // 🎯 FORMATO CORRECTO para la API de Gemini
            const payload = {
                contents: [
                    {
                        parts: [
                            {
                                text: systemPrompt + "\n\nConversación actual:\nCliente: " + userMessage + "\n\nAsistente:"
                            }
                        ]
                    }
                ]
            };

            try {
                const res = await fetch(apiUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/json"
                    },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    throw new Error(`Error HTTP: ${res.status}`);
                }

                const data = await res.json();

                // Remover el indicador de "escribiendo..."
                typingIndicator.remove();

                if (data.error) {
                    addMessage('bot', '⚠️ <strong>Error del servicio:</strong> ' + data.error);
                    showEscapeHatch();
                    return;
                }

                // Extraer la respuesta correctamente
                const botResponse = data.candidates?.[0]?.content?.parts?.[0]?.text ||
                    "No pude generar una respuesta. ¿Podrías describir tu problema de otra manera?";

                addMessage('bot', botResponse);

                // Guardar en historial
                chatHistory.push({ role: "user", text: userMessage });
                chatHistory.push({ role: "assistant", text: botResponse });

                // Mostrar opciones de escape
                setTimeout(() => {
                    showEscapeHatch();
                }, 500);

            } catch (error) {
                console.error("Error:", error);

                // Remover el indicador de "escribiendo..."
                typingIndicator.remove();

                addMessage('bot', '❌ <strong>Error de conexión:</strong> No pude conectarme con el servicio de asistencia. Por favor, intenta nuevamente o contacta a soporte técnico directamente.');
                showEscapeHatch();
            }

            btnSend.disabled = false;
            chatInput.disabled = false;
            chatInput.focus();
        }

        // Event Listeners
        inputArea.addEventListener("submit", e => {
            e.preventDefault();
            const userMessage = chatInput.value.trim();
            if (!userMessage) return;

            addMessage('user', userMessage);
            chatInput.value = '';
            callGeminiAPI(userMessage);
        });

        // Sugerencias rápidas
        quickSuggestions.forEach(button => {
            button.addEventListener('click', () => {
                const suggestion = button.getAttribute('data-suggestion');
                chatInput.value = suggestion;
                chatInput.focus();
            });
        });

        btnYes.addEventListener('click', () => {
            Swal.fire({
                title: '¡Excelente!',
                text: 'Nos alegra haberte ayudado. Que tengas un buen día.',
                icon: 'success',
                background: '#060915',
                color: '#fff',
                confirmButtonColor: '#00ff88',
                confirmButtonText: 'Volver al inicio'
            }).then(() => {
                window.location.href = 'menu_cliente.php';
            });
        });

        // Permitir enviar con Enter
        chatInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                inputArea.dispatchEvent(new Event('submit'));
            }
        });

        // Focus inicial en el input
        chatInput.focus();

        // Efecto de pulso en el robot
        setInterval(() => {
            const robotIcon = document.querySelector('.robot-icon');
            robotIcon.style.boxShadow = `0 0 ${15 + Math.random() * 10}px rgba(0, 212, 255, ${0.3 + Math.random() * 0.2})`;
        }, 2000);
    </script>

</body>

</html>