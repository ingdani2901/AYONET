<?php
// === CONFIGURACIÓN BASE Y SEGURIDAD ===
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once __DIR__ . '/../db.php';

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
            --muted: #cfe1ff;
            --glass: rgba(255, 255, 255, .07);
        }

        body {
            margin: 0;
            font-family: 'Poppins', sans-serif;
            color: #fff;
            background: radial-gradient(1200px 700px at 10% 10%, #12183e 0%, #060915 55%) fixed;
        }

        .wrap {
            padding: 12px;
            display: grid;
            grid-template-rows: 64px 1fr;
            gap: 12px;
            height: 100vh;
        }

        .topbar {
            background: linear-gradient(180deg, rgba(255, 255, 255, .10), rgba(255, 255, 255, .04));
            border-radius: 12px;
            padding: 8px 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .brand {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .logo {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: radial-gradient(circle at 30% 30%, var(--neon1), transparent 55%),
                radial-gradient(circle at 70% 70%, var(--neon3), transparent 55%), #0c1133;
            border: 1px solid rgba(255, 255, 255, .12);
        }

        .panel {
            background: linear-gradient(180deg, rgba(255, 255, 255, .06), rgba(255, 255, 255, .03));
            border-radius: 12px;
            padding: 12px;
            display: grid;
            grid-template-rows: auto 1fr auto;
            gap: 10px;
            overflow: hidden;
        }

        #chat-container {
            overflow-y: auto;
            padding: 10px;
            background: rgba(0, 0, 0, 0.1);
            border-radius: 8px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .chat-message {
            padding: 10px 14px;
            border-radius: 16px;
            max-width: 80%;
            line-height: 1.5;
        }

        .user-message {
            background: var(--neon1);
            color: #061022;
            align-self: flex-end;
            border-bottom-right-radius: 4px;
        }

        .bot-message {
            background: var(--glass);
            color: #fff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            align-self: flex-start;
            border-bottom-left-radius: 4px;
        }

        #input-area {
            display: flex;
            gap: 10px;
        }

        .ctrl {
            width: 100%;
            padding: 10px;
            border-radius: 10px;
            background: rgba(255, 255, 255, .06);
            border: 1px solid rgba(255, 255, 255, .10);
            color: #fff;
            outline: none;
            font-family: 'Poppins', sans-serif;
            font-size: 1rem;
        }

        .btn {
            padding: 9px 12px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 700;
        }

        .btn.primary {
            background: linear-gradient(90deg, var(--neon1), var(--neon3));
            color: #061022;
        }

        .escape-hatch {
            display: none;
            flex-direction: column;
            gap: 10px;
            padding: 10px;
            background: var(--glass);
            border-radius: 8px;
            text-align: center;
        }
    </style>
</head>

<body>

    <div class="wrap">
        <header class="topbar">
            <div class="brand">
                <div class="logo"></div>
                <div>
                    <div style="font-weight:700">AYONET · Portal de Cliente</div>
                    <small style="color:#cfe1ff">Sesión de: <?php echo $nombreCliente; ?></small>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center">
                <a class="btn" style="background:rgba(255,255,255,.06);color:#fff" href="menu_cliente.php"><i
                        class="fa-solid fa-house"></i> Inicio</a>
                <a class="btn" style="background:rgba(255,255,255,.06);color:#fff" href="../logout.php"><i
                        class="fa-solid fa-right-from-bracket"></i> Salir</a>
            </div>
        </header>

        <section class="panel">
            <h2 style="margin-top:0;"><i class="fa-solid fa-robot"></i> Asistente de Incidencias</h2>

            <div id="chat-container">
                <div class="chat-message bot-message">
                    ¡Hola, <strong><?php echo $nombreCliente; ?></strong>! Soy Ayo-Bot, tu asistente virtual.<br><br>
                    Por favor descríbeme tu problema (ej. “no tengo internet”, “la luz de mi módem está roja”, etc.).
                </div>
            </div>

            <div>
                <div class="escape-hatch" id="escape-hatch">
                    <p>¿Se solucionó tu problema?</p>
                    <button id="btn-yes" class="btn" style="background:#2dd4bf;color:#061022;">Sí, ¡gracias!</button>
                    <a href="crear_incidencias_real.php" id="btn-no" class="btn"
                        style="background:#ff5fa2;color:#061022;">No, necesito un técnico</a>
                </div>

                <form id="input-area">
                    <input type="text" id="chat-input" class="ctrl" placeholder="Escribe tu problema aquí..."
                        autocomplete="off">
                    <button type="submit" id="btn-send" class="btn primary"><i
                            class="fa-solid fa-paper-plane"></i></button>
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

        const apiUrl = 'http://localhost/ayonetproyecto/api/gemini_proxy.php';
        let chatHistory = [];

        // === PROMPT DEL ASISTENTE ===
        const systemPrompt = `
Eres "Ayo-Bot", un asistente técnico Nivel 1 de AYONET.
Solo ayudas a usuarios con problemas simples: sin internet, luz roja en módem, internet lento, etc.
Usa lenguaje muy claro y amable. No menciones que eres IA ni modelo de lenguaje.
Si el problema es complejo, dile que lo atenderá un técnico humano.
Después de cada respuesta, pregunta si el problema se solucionó.
`;

        chatHistory.push({ role: "system", content: [{ type: "text", text: systemPrompt }] });

        function addMessage(sender, message) {
            const div = document.createElement("div");
            div.classList.add("chat-message", sender === "user" ? "user-message" : "bot-message");
            div.innerHTML = message.replace(/\n/g, "<br>");
            chatContainer.appendChild(div);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }

        function showEscapeHatch() {
            inputArea.style.display = 'none';
            escapeHatch.style.display = 'flex';
            escapeHatch.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }

        async function callGeminiAPI() {
            btnSend.disabled = true;
            chatInput.disabled = true;
            addMessage('bot', '...');

            // 🧠 Enviamos TODO el historial para que tenga memoria
            const payload = {
                contents: [
                    {
                        role: "user",
                        parts: [{
                            text: `
${systemPrompt}

Historial de conversación:

${chatHistory
                                    .map(m => `${m.role === 'user' ? 'Usuario' : 'Asistente'}: ${m.content[0].text}`)
                                    .join("\n")}
                    `
                        }]
                    }
                ]
            };

            try {
                const res = await fetch(apiUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify(payload)
                });

                if (!res.ok) {
                    const err = await res.text();
                    console.error("Error API:", res.status, err);
                    chatContainer.lastChild.remove();
                    addMessage('bot', 'Hubo un problema al conectar con el asistente.');
                    showEscapeHatch();
                    return;
                }

                const data = await res.json();
                console.log("Respuesta de Gemini:", data);

                const text = data.candidates?.[0]?.content?.parts?.[0]?.text || "No hay respuesta del asistente.";

                chatContainer.lastChild.remove();
                addMessage('bot', text);
                chatHistory.push({ role: "assistant", content: [{ type: "text", text }] });
                showEscapeHatch();

            } catch (e) {
                console.error(e);
                chatContainer.lastChild.remove();
                addMessage('bot', 'No pude conectarme. Inténtalo más tarde.');
                showEscapeHatch();
            }

            btnSend.disabled = false;
            chatInput.disabled = false;
        }

        inputArea.addEventListener("submit", e => {
            e.preventDefault();
            const msg = chatInput.value.trim();
            if (!msg) return;
            addMessage('user', msg);
            chatHistory.push({ role: "user", content: [{ type: "text", text: msg }] });
            chatInput.value = '';
            callGeminiAPI();
        });

        btnYes.addEventListener('click', () => {
            Swal.fire({
                title: '¡Excelente!',
                text: 'Nos alegra haberte ayudado. Que tengas un buen día.',
                icon: 'success',
                background: '#0c1133',
                color: '#fff'
            }).then(() => location.href = 'menu_cliente.php');
        });
    </script>

</body>

</html>