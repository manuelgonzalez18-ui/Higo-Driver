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

$configCandidates = [
    __DIR__ . '/_smtp_config.php',
    dirname(__DIR__, 2) . '/Private/smtp-config.php',
    dirname(__DIR__, 3) . '/Private/smtp-config.php',
    dirname(__DIR__, 2) . '/private/smtp-config.php',
    dirname(__DIR__, 3) . '/private/smtp-config.php',
];
$config = null;
foreach ($configCandidates as $candidate) {
    if (!is_file($candidate)) continue;
    $loaded = require $candidate;
    if (is_array($loaded)) $config = $loaded;
    break;
}

if (is_array($config)) {
    $baseUrl = rtrim((string) ($config['higo_app_base_url'] ?? 'https://higoapp.com'), '/');
    $body = json_encode(['application_id' => $applicationId, 'email' => $email]);
    $ch = curl_init($baseUrl . '/api/driver-application-status.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 18,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $central = is_string($response) ? json_decode($response, true) : null;
    if ($statusCode >= 200 && $statusCode < 300 && is_array($central) && ($central['ok'] ?? false)) {
        hd_json_mutate('driver-applications.json', function (array $store) use ($applicationId, $central): array {
            if (isset($store['applications'][$applicationId]) && is_array($store['applications'][$applicationId])) {
                $store['applications'][$applicationId]['status'] = (string) ($central['status'] ?? 'received');
                $store['applications'][$applicationId]['updated_at'] = (string) ($central['updated_at'] ?? gmdate('c'));
            }
            return $store;
        });
        hd_increment_funnel('status_lookup', ['result' => 'found', 'status' => (string) ($central['status'] ?? 'received')]);
        echo json_encode($central, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($statusCode >= 500 || $response === false) {
        error_log('application-status central lookup failed HTTP ' . $statusCode . ' ' . $error);
    }
}

// Fallback para solicitudes creadas antes de la integración central o durante una incidencia.
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
    'documents_requested' => ['Documentos solicitados', 'Revisa tu correo y completa la carga segura de requisitos.'],
    'documents_submitted' => ['Documentos recibidos', 'Tus documentos fueron recibidos y están pendientes de revisión.'],
    'correction_requested' => ['Corrección solicitada', 'Revisa el correo del equipo Higo y corrige los documentos indicados.'],
    'approved' => ['Solicitud aprobada', 'El equipo completará la creación de tu cuenta Higo Driver.'],
    'converted' => ['Registro completado', 'Tu cuenta Higo Driver fue creada. Revisa tu correo para ingresar.'],
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
