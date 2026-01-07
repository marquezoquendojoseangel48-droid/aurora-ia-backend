<?php
declare(strict_types=1);

// 🔒 Blindaje total
ini_set('display_errors', 0);
error_reporting(0);
ob_start();

// 📡 Headers para API + CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: http://avaluosyperitajescosta.kesug.com');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 📥 Leer entrada
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$pregunta = trim($data['message'] ?? $data['pregunta'] ?? '');

if (!$pregunta) {
    ob_end_clean();
    echo json_encode(['reply' => 'Señor, no se recibió ninguna consulta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 🔍 Extraer placa / CC
$placa = preg_match('/\b[A-Z]{3}\d{3}\b/i', $pregunta, $m) ? strtoupper($m[0]) : null;
$cc = preg_match('/\b\d{8,10}\b/', $pregunta, $m) ? $m[0] : null;

// 🗄️ Conexión a tu BD en InfinityFree
function db() {
    return new PDO(
        'mysql:host=sql201.infinityfree.com;dbname=if0_40826626_avaluos_db;charset=utf8mb4',
        'if0_40826626',
        '0J77KK7GYcEAuV',
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
}

// 🔍 Buscar peritaje
$peritaje = null;
try {
    $pdo = db();
    if ($placa) {
        $stmt = $pdo->prepare("SELECT * FROM peritajes WHERE vehiculo_placa = ? ORDER BY fecha DESC LIMIT 1");
        $stmt->execute([$placa]);
        $peritaje = $stmt->fetch(PDO::FETCH_ASSOC);
    } elseif ($cc) {
        $stmt = $pdo->prepare("SELECT * FROM peritajes WHERE propietario_cc = ? ORDER BY fecha DESC LIMIT 1");
        $stmt->execute([$cc]);
        $peritaje = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    // Silently continue; IA works without BD
}

// 📝 Contexto para IA
$contexto = "SISTEMA DE AVALÚOS Y PERITAJES VEHICULARES\n";
$contexto .= "REGIÓN: COSTA CARIBE COLOMBIANA\n";
$contexto .= "AÑO ACTUAL: 2025\n\n";
$contexto .= "INSTRUCCIONES:\n";
$contexto .= "- Responda SOLO con la información existente\n";
$contexto .= "- No invente datos\n";
$contexto .= "- Use lenguaje técnico\n";
$contexto .= "- Diríjase al usuario como 'Señor'\n\n";

if ($peritaje) {
    foreach ($peritaje as $campo => $valor) {
        if ($valor !== null && $valor !== '') {
            $contexto .= strtoupper($campo) . ": " . $valor . "\n";
        }
    }
} else {
    $contexto .= "NO EXISTE PERITAJE REGISTRADO PARA LA CONSULTA.\n";
}

// 🚀 LLAMADA A CEREBRAS (¡URL CORREGIDA!)
$apiKey = 'csk-j4encynj35m52xk3x34cc2mhmk63t8pct2cnfx8j24rfyvm5';
$payload = [
    'model' => 'llama3.1-8b',
    'messages' => [
        [
            'role' => 'system',
            'content' => "Eres Aurora, una IA experta en avalúos y peritajes vehiculares. Analizas peritajes reales, explicas datos técnicos, conclusiones, riesgos, valores comerciales y estado general."
        ],
        [
            'role' => 'system',
            'content' => $contexto
        ],
        [
            'role' => 'user',
            'content' => $pregunta
        ]
    ],
    'temperature' => 0.2,
    'max_tokens' => 800
];

$ch = curl_init('https://api.cerebras.ai/v1/chat/completions'); // ✅ SIN ESPACIOS
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 25
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// ✅ Procesar respuesta
if ($httpCode === 200 && $response) {
    $json = json_decode($response, true);
    if (!empty($json['choices'][0]['message']['content'])) {
        ob_end_clean();
        echo json_encode([
            'reply' => trim($json['choices'][0]['message']['content'])
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 🔄 Manejo de errores comunes
$errorMsg = match($httpCode) {
    429 => 'Señor, se ha excedido el límite de uso de la IA. Por favor, espere 1 minuto e inténtelo nuevamente.',
    403 => 'Señor, la API de Cerebras está temporalmente restringida. Intente más tarde.',
    default => 'Señor, la IA no puede procesar su solicitud en este momento. Intente nuevamente.'
};

ob_end_clean();
echo json_encode(['reply' => $errorMsg], JSON_UNESCAPED_UNICODE);