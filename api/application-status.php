<?php
declare(strict_types=1);

require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_ratelimit.php';
require_once __DIR__ . '/_private.php';

hd_apply_cors('POST, OPTIONS');
api_rate_limit('application-status', 12);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$applicationId = strtoupper(trim((string) ($_POST['application_id'] ?? '')));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));

if (!preg_match('/^HD-\d{8}-[A-F0-9]{8}$/', $applicationId) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$store = hd_json_read('driver-applications.json', ['applications' => []]);
$record = $store['applications'][$applicationId] ?? null;
if (!is_array($record) || !hash_equals((string) ($record['email_hash'] ?? ''), hd_hash_email($email))) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

$status = (string) ($record['status'] ?? 'received');
$public = [
    'pending_delivery' => ['Solicitud en proceso', 'Estamos confirmando la recepción de tu solicitud.'],
    'delivery_failed' => ['Pendiente de confirmación', 'El equipo debe verificar manualmente la recepción. Contáctanos si no recibes respuesta.'],
    'received' => ['Solicitud recibida', 'El equipo Higo realizará la revisión inicial y te indicará el siguiente paso.'],
    'under_review' => ['En revisión', 'Estamos validando cobertura, datos y capacidad operativa.'],
    'documents_requested' => ['Documentos solicitados', 'Continúa el onboarding únicamente mediante el canal oficial indicado por Higo.'],
    'approved' => ['Solicitud aprobada', 'Sigue las instrucciones recibidas para activar tu cuenta y consultar Higo Pay.'],
    'waitlist' => ['En lista de espera', 'Tu zona o modalidad está temporalmente sin cupo. Conservaremos la solicitud para futuras aperturas.'],
    'rejected' => ['Solicitud no aprobada', 'La solicitud no cumple actualmente los criterios de incorporación.'],
];
if (!isset($public[$status])) $status = 'received';

hd_increment_funnel('status_lookup', ['result' => 'found', 'status' => $status]);
echo json_encode([
    'ok' => true,
    'application_id' => $applicationId,
    'status' => $status,
    'status_label' => $public[$status][0],
    'next_step' => $public[$status][1],
    'updated_at' => (string) ($record['updated_at'] ?? $record['created_at'] ?? ''),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
