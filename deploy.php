<?php
// ─────────────────────────────────────────────────────────────
// deploy.php — Auto-deploy desde GitHub
// Colocá este archivo en: cliente.motiva.com.py/public_html/
// ─────────────────────────────────────────────────────────────

// 1. TU TOKEN SECRETO — cambialo por cualquier palabra que quieras
//    Tiene que coincidir exactamente con lo que ponés en GitHub Webhook
define('SECRET_TOKEN', 'motiva2026deploy');

// 2. TU REPOSITORIO DE GITHUB
define('GITHUB_REPO', 'walter2191/dashboard-clientes');

// 3. BRANCH A DESPLEGAR
define('BRANCH', 'main');

// 4. CARPETA DONDE ESTÁN LOS ARCHIVOS DEL DASHBOARD
//    (la carpeta public_html de tu subdominio)
define('DEPLOY_PATH', __DIR__);

// ─── NO TOCAR NADA DEBAJO DE ESTA LÍNEA ───────────────────────

header('Content-Type: application/json');

// Solo acepta POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Leer el payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

// Verificar el token secreto
$expected = 'sha256=' . hash_hmac('sha256', $payload, SECRET_TOKEN);
if (!hash_equals($expected, $signature)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

// Decodificar el payload
$data = json_decode($payload, true);

// Verificar que es el branch correcto
$pushedBranch = str_replace('refs/heads/', '', $data['ref'] ?? '');
if ($pushedBranch !== BRANCH) {
    echo json_encode(['status' => 'ignored', 'branch' => $pushedBranch]);
    exit;
}

// Descargar los archivos actualizados desde GitHub
$files_updated = [];
$files_failed = [];

// Archivos a sincronizar
$files = ['index.html', 'clientes.json'];

foreach ($files as $file) {
    $url = "https://raw.githubusercontent.com/" . GITHUB_REPO . "/" . BRANCH . "/" . $file;
    
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'header' => "User-Agent: SiteGround-Deploy\r\n"
        ]
    ]);
    
    $content = @file_get_contents($url, false, $context);
    
    if ($content !== false) {
        $dest = DEPLOY_PATH . '/' . $file;
        if (file_put_contents($dest, $content) !== false) {
            $files_updated[] = $file;
        } else {
            $files_failed[] = $file . ' (write error)';
        }
    } else {
        $files_failed[] = $file . ' (download error)';
    }
}

// Log del deploy
$log = date('Y-m-d H:i:s') . " | Branch: " . BRANCH . " | Updated: " . implode(', ', $files_updated);
if (!empty($files_failed)) {
    $log .= " | Failed: " . implode(', ', $files_failed);
}
file_put_contents(DEPLOY_PATH . '/deploy.log', $log . "\n", FILE_APPEND);

// Respuesta
$status = empty($files_failed) ? 'success' : 'partial';
http_response_code(200);
echo json_encode([
    'status'   => $status,
    'updated'  => $files_updated,
    'failed'   => $files_failed,
    'time'     => date('Y-m-d H:i:s')
]);
