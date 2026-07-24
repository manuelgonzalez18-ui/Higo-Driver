<?php
declare(strict_types=1);

require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_ratelimit.php';
require_once __DIR__ . '/_private.php';

hd_apply_cors('POST, OPTIONS');
api_rate_limit('register-driver', 4, sys_get_temp_dir() . '/higodriver_ratelimit.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rd_send(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    rd_send(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

if (!empty($_FILES)) {
    rd_send(400, ['ok' => false, 'error' => 'document_upload_not_allowed']);
}

if (!empty($_POST['website'] ?? '')) {
    rd_send(200, ['ok' => true, 'application_id' => 'HD-RECEIVED']);
}

$termsVersionExpected = '2026-05-19';
$privacyVersionExpected = '2026-05-19';

$fullName = trim((string) ($_POST['full_name'] ?? ''));
$cedula = strtoupper(trim((string) ($_POST['cedula'] ?? ''));
$phone = trim((string) ($_POST['phone'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? ''));
$city = trim((string) ($_POST['city'] ?? ''));
$vehicleType = trim((string) ($_POST['vehicle_type'] ?? ''));
$vehicleBrand = trim((string) ($_POST['vehicle_brand'] ?? ''));
$vehicleModel = trim((string) ($_POST['vehicle_model'] ?? ''));
$vehicleYear = trim((string) ($_POST['vehicle_year'] ?? ''));
$vehicleColor = trim((string) ($_POST['vehicle_color'] ?? ''));
$licensePlate = strtoupper(preg_replace('/\s+/', '', trim((string) ($_POST['license_plate'] ?? ''))));
$termsVersion = trim((string) ($_POST['terms_version'] ?? ''));
$privacyVersion = trim((string) ($_POST['privacy_version'] ?? ''));
$acceptTerms = (string) ($_POST['accept_terms'] ?? '') === '1';
$acceptPrivacy = (string) ($_POST['accept_privacy'] ?? '') === '1';
$acceptContact = (string) ($_POST['accept_contact'] ?? '') === '1';
$source = trim((string) ($_POST['source'] ?? 'higodriver.com'));
$idempotencyKey = trim((string) ($_POST['idempotency_key'] ?? ''));

$required = [
    'full_name' => $fullName,
    'cedula' => $cedula,
    'phone' => $phone,
    'email' => $email,
    'city' => $city,
    'vehicle_type' => $vehicleType,
    'vehicle_brand' => $vehicleBrand,
    'vehicle_model' => $vehicleModel,
    'vehicle_color' => $vehicleColor,
    'license_plate' => $licensePlate,
    'idempotency_key' => $idempotencyKey,
];
foreach ($required as $key => $value) {
    if ($value === '') rd_send(400, ['ok' => false, 'error' => 'missing_field', 'detail' => $key]);
}

if (strlen($fullName) < 3 || strlen($fullName) > 200) rd_send(400, ['ok' => false, 'error' => 'invalid_name']);
if (!preg_match('/^[VEJPG]-?\d{5,12}$/', $cedula)) rd_send(400, ['ok' => false, 'error' => 'invalid_cedula']);
$phoneDigits = preg_replace('/\D+/', '', $phone);
if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 15) rd_send(400, ['ok' => false, 'error' => 'invalid_phone']);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) rd_send(400, ['ok' => false, 'error' => 'invalid_email']);
if (strlen($city) < 2 || strlen($city) > 160) rd_send(400, ['ok' => false, 'error' => 'invalid_city']);
if (!in_array($vehicleType, ['moto', 'carro', 'camioneta'], true)) rd_send(400, ['ok' => false, 'error' => 'invalid_vehicle_type']);
if (strlen($vehicleBrand) > 120 || strlen($vehicleModel) > 120 || strlen($vehicleColor) > 80) rd_send(400, ['ok' => false, 'error' => 'invalid_vehicle']);
if (!preg_match('/^[A-Z0-9-]{3,12}$/', $licensePlate)) rd_send(400, ['ok' => false, 'error' => 'invalid_plate']);
if ($vehicleYear !== '') {
    $year = (int) $vehicleYear;
    if ($year < 1950 || $year > ((int) gmdate('Y') + 1)) rd_send(400, ['ok' => false, 'error' => 'invalid_vehicle_year']);
}
if (!$acceptTerms || !$acceptPrivacy) rd_send(400, ['ok' => false, 'error' => 'legal_acceptance_required']);
if ($termsVersion !== $termsVersionExpected || $privacyVersion !== $privacyVersionExpected) {
    rd_send(409, ['ok' => false, 'error' => 'legal_version_mismatch']);
}
if (!preg_match('/^[a-f0-9]{20,64}$/i', $idempotencyKey)) rd_send(400, ['ok' => false, 'error' => 'invalid_idempotency_key']);

$smtpConfigCandidates = [
    __DIR__ . '/_smtp_config.php',
    dirname(__DIR__, 2) . '/Private/smtp-config.php',
    dirname(__DIR__, 3) . '/Private/smtp-config.php',
    dirname(__DIR__, 2) . '/private/smtp-config.php',
    dirname(__DIR__, 3) . '/private/smtp-config.php',
];
$smtpCfg = null;
foreach ($smtpConfigCandidates as $candidate) {
    if (is_file($candidate)) {
        $loaded = require $candidate;
        if (is_array($loaded)) $smtpCfg = $loaded;
        break;
    }
}
if ($smtpCfg === null) rd_send(503, ['ok' => false, 'error' => 'mail_config_missing']);
foreach (['host', 'port', 'username', 'password', 'from_email'] as $key) {
    if (empty($smtpCfg[$key])) rd_send(503, ['ok' => false, 'error' => 'mail_config_invalid']);
}
if (empty($smtpCfg['higo_app_ingest_secret'])) {
    rd_send(503, ['ok' => false, 'error' => 'admin_integration_not_configured']);
}

$idempotencyHash = hash('sha256', strtolower($idempotencyKey));
$emailHash = hd_hash_email($email);
$plateHash = hash('sha256', $licensePlate . '|higodriver');
$now = gmdate('c');
$applicationId = 'HD-' . gmdate('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(6)), 0, 8));
$existingId = null;

$stored = hd_json_mutate('driver-applications.json', function (array $store) use (
    &$existingId, $applicationId, $idempotencyHash, $emailHash, $plateHash, $phoneDigits,
    $vehicleType, $city, $termsVersion, $privacyVersion, $acceptContact, $source, $now
): array {
    if (!isset($store['version'])) $store['version'] = 1;
    if (!isset($store['applications']) || !is_array($store['applications'])) $store['applications'] = [];

    foreach ($store['applications'] as $id => $record) {
        if (is_array($record) && ($record['idempotency_hash'] ?? '') === $idempotencyHash) {
            $existingId = (string) $id;
            return $store;
        }
    }

    $store['applications'][$applicationId] = [
        'application_id' => $applicationId,
        'email_hash' => $emailHash,
        'phone_last4' => substr($phoneDigits, -4),
        'plate_hash' => $plateHash,
        'vehicle_type' => $vehicleType,
        'city' => substr($city, 0, 80),
        'status' => 'pending_delivery',
        'created_at' => $now,
        'updated_at' => $now,
        'terms_version' => $termsVersion,
        'privacy_version' => $privacyVersion,
        'accept_contact' => $acceptContact,
        'source' => substr($source, 0, 80),
        'idempotency_hash' => $idempotencyHash,
        'ip_hash' => hd_hash_ip(),
    ];

    if (count($store['applications']) > 5000) {
        uasort($store['applications'], function ($a, $b) {
            return strcmp((string) ($a['created_at'] ?? ''), (string) ($b['created_at'] ?? ''));
        });
        while (count($store['applications']) > 5000) array_shift($store['applications']);
    }
    return $store;
});

if ($stored === null) rd_send(500, ['ok' => false, 'error' => 'storage_failed']);
if ($existingId !== null) {
    $applicationId = $existingId;
}

$syncPayload = [
    'application_code' => $applicationId,
    'idempotency_hash' => $idempotencyHash,
    'full_name' => $fullName,
    'cedula' => $cedula,
    'phone' => $phone,
    'phone_digits' => $phoneDigits,
    'email' => $email,
    'email_hash' => $emailHash,
    'city' => $city,
    'vehicle_type' => $vehicleType,
    'vehicle_brand' => $vehicleBrand,
    'vehicle_model' => $vehicleModel,
    'vehicle_year' => $vehicleYear,
    'vehicle_color' => $vehicleColor,
    'license_plate' => $licensePlate,
    'license_plate_hash' => $plateHash,
    'terms_version' => $termsVersion,
    'privacy_version' => $privacyVersion,
    'accept_terms' => $acceptTerms,
    'accept_privacy' => $acceptPrivacy,
    'accept_contact' => $acceptContact,
    'source' => $source,
    'submitted_ip_hash' => hd_hash_ip(),
];

if ($existingId !== null) {
    $existing = $stored['applications'][$applicationId] ?? [];
    if (($existing['status'] ?? '') === 'received') {
        rd_sync_higo_application($smtpCfg, $syncPayload + ['status' => 'received']);
        rd_send(200, [
            'ok' => true,
            'application_id' => $applicationId,
            'status_url' => '/status/?id=' . rawurlencode($applicationId),
            'duplicate' => true,
        ]);
    }
}

if (!rd_sync_higo_application($smtpCfg, $syncPayload + [
    'status' => 'pending_delivery',
    'confirmation_email_sent' => false,
])) {
    rd_send(503, ['ok' => false, 'error' => 'admin_sync_failed']);
}

$vehicleLabels = ['moto' => 'Moto', 'carro' => 'Carro', 'camioneta' => 'Camioneta'];
$safe = function (string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); };
$adminRows = [
    'Código' => $applicationId,
    'Nombre' => $fullName,
    'Cédula' => $cedula,
    'Teléfono' => $phone,
    'Correo' => $email,
    'Ciudad / zona' => $city,
    'Modalidad' => $vehicleLabels[$vehicleType],
    'Vehículo' => trim($vehicleBrand . ' ' . $vehicleModel),
    'Año' => $vehicleYear === '' ? 'No indicado' : $vehicleYear,
    'Color' => $vehicleColor,
    'Placa' => $licensePlate,
    'Acepta contacto' => $acceptContact ? 'Sí' : 'No',
    'Versión Términos' => $termsVersion,
    'Versión Privacidad' => $privacyVersion,
];
$rowsHtml = '';
$plain = "Nueva solicitud Higo Driver\n" . str_repeat('-', 48) . "\n";
foreach ($adminRows as $label => $value) {
    $rowsHtml .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#64748b;font-size:12px;font-weight:700;width:180px;">' . $safe($label) . '</td><td style="padding:8px 12px;border-bottom:1px solid #e5e7eb;color:#0f172a;font-size:14px;">' . $safe($value) . '</td></tr>';
    $plain .= $label . ': ' . $value . "\n";
}

$adminHtml = '<!doctype html><html lang="es"><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;padding:24px;">'
    . '<table role="presentation" width="100%"><tr><td align="center"><table role="presentation" width="640" style="max-width:640px;background:#fff;border-radius:14px;overflow:hidden;">'
    . '<tr><td style="padding:24px;background:#315ef4;color:#fff;"><h1 style="margin:0;font-size:22px;">Nueva solicitud Higo Driver</h1><p style="margin:6px 0 0;">' . $safe($applicationId) . '</p></td></tr>'
    . '<tr><td style="padding:22px;"><table role="presentation" width="100%" style="border:1px solid #e5e7eb;border-radius:10px;border-collapse:collapse;">' . $rowsHtml . '</table>'
    . '<p style="margin:18px 0 0;color:#475569;font-size:13px;">Esta solicitud también está disponible en el panel administrativo de Higo App.</p></td></tr>'
    . '</table></td></tr></table></body></html>';

$adminSubject = 'Nueva solicitud Higo Driver - ' . $applicationId;
$adminSent = rd_smtp_send($smtpCfg, 'admin@higodriver.com', $adminSubject, $adminHtml, $plain, $email);
if (!$adminSent) {
    rd_update_application_status($applicationId, 'delivery_failed');
    rd_sync_higo_application($smtpCfg, $syncPayload + [
        'status' => 'delivery_failed',
        'confirmation_email_sent' => false,
    ]);
    rd_send(502, ['ok' => false, 'error' => 'mail_failed']);
}

rd_update_application_status($applicationId, 'received');

$applicantSubject = 'Recibimos tu solicitud Higo Driver - ' . $applicationId;
$statusUrl = 'https://higodriver.com/status/?id=' . rawurlencode($applicationId);
$applicantPlain = "Hola {$fullName},\n\nRecibimos tu pre-registro como conductor Higo.\nCódigo: {$applicationId}\nConsulta el estado: {$statusUrl}\n\nNo envíes documentos por enlaces no oficiales. El equipo te indicará el siguiente paso.\n";
$applicantHtml = '<!doctype html><html lang="es"><body style="margin:0;background:#f1f5f9;font-family:Arial,sans-serif;padding:24px;">'
    . '<table role="presentation" width="100%"><tr><td align="center"><table role="presentation" width="560" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden;">'
    . '<tr><td style="padding:24px;background:#07132f;color:#fff;"><h1 style="margin:0;font-size:22px;">Recibimos tu pre-registro</h1></td></tr>'
    . '<tr><td style="padding:24px;color:#0f172a;"><p>Hola ' . $safe($fullName) . ',</p><p>Tu solicitud fue recibida. Guarda este código:</p>'
    . '<p style="font-family:monospace;font-size:20px;font-weight:700;padding:12px;background:#eff6ff;border-radius:8px;">' . $safe($applicationId) . '</p>'
    . '<p><a href="' . $safe($statusUrl) . '" style="color:#315ef4;font-weight:700;">Consultar estado</a></p>'
    . '<p style="color:#64748b;font-size:13px;">No envíes documentos por enlaces no oficiales. El equipo Higo te indicará el siguiente paso.</p></td></tr>'
    . '</table></td></tr></table></body></html>';
$confirmationSent = rd_smtp_send($smtpCfg, $email, $applicantSubject, $applicantHtml, $applicantPlain, (string) $smtpCfg['from_email']);

$centralSynced = rd_sync_higo_application($smtpCfg, $syncPayload + [
    'status' => 'received',
    'confirmation_email_sent' => $confirmationSent,
]);
if (!$centralSynced) {
    error_log('register-driver: final Higo administration sync failed for ' . $applicationId);
}

hd_increment_funnel('application_submitted', ['vehicle_type' => $vehicleType]);
rd_send(200, [
    'ok' => true,
    'application_id' => $applicationId,
    'status_url' => '/status/?id=' . rawurlencode($applicationId),
    'confirmation_email_sent' => $confirmationSent,
    'admin_sync_confirmed' => $centralSynced,
]);

function rd_update_application_status(string $applicationId, string $status): void {
    hd_json_mutate('driver-applications.json', function (array $store) use ($applicationId, $status): array {
        if (isset($store['applications'][$applicationId]) && is_array($store['applications'][$applicationId])) {
            $store['applications'][$applicationId]['status'] = $status;
            $store['applications'][$applicationId]['updated_at'] = gmdate('c');
        }
        return $store;
    });
}

function rd_sync_higo_application(array $cfg, array $payload): bool {
    $secret = trim((string) ($cfg['higo_app_ingest_secret'] ?? ''));
    if ($secret === '') return false;
    $baseUrl = rtrim((string) ($cfg['higo_app_base_url'] ?? 'https://higoapp.com'), '/');
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) return false;

    $ch = curl_init($baseUrl . '/api/driver-applications-ingest.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Higo-Driver-Secret: ' . $secret,
        ],
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) {
        error_log('rd_sync_higo_application failed HTTP ' . $status . ' ' . $error . ' ' . substr((string) $response, 0, 220));
        return false;
    }
    $decoded = json_decode((string) $response, true);
    return is_array($decoded) && ($decoded['ok'] ?? false) === true;
}

function rd_smtp_send(array $cfg, string $to, string $subject, string $html, string $plain, string $replyTo): bool {
    $host = (string) $cfg['host'];
    $port = (int) $cfg['port'];
    $user = (string) $cfg['username'];
    $pass = (string) $cfg['password'];
    $from = (string) $cfg['from_email'];
    $fromName = str_replace(["\r", "\n"], '', (string) ($cfg['from_name'] ?? 'Higo Driver'));
    $safeTo = str_replace(["\r", "\n", "\0"], '', $to);
    $safeReplyTo = str_replace(["\r", "\n", "\0"], '', $replyTo);
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $boundary = '=_alt_' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$plain}\r\n";
    $body .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$html}\r\n--{$boundary}--\r\n";

    $url = ($port === 465 ? 'ssl://' : '') . $host;
    $context = stream_context_create(['ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'SNI_enabled' => true,
        'peer_name' => $host,
    ]]);
    $socket = @stream_socket_client($url . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log('rd_smtp_send connect failed: ' . $errno . ' ' . $errstr);
        return false;
    }
    stream_set_timeout($socket, 30);

    $read = function () use ($socket): string {
        $output = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $output .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $output;
    };
    $write = function (string $command) use ($socket): void { fwrite($socket, $command . "\r\n"); };
    $expect = function (string $response, string $code): bool {
        if (strpos($response, $code) !== 0) {
            error_log('rd_smtp_send expected ' . $code . ' got ' . trim($response));
            return false;
        }
        return true;
    };

    if (!$expect($read(), '220')) { fclose($socket); return false; }
    $write('EHLO ' . (string) ($cfg['ehlo'] ?? 'higodriver.com'));
    if (!$expect($read(), '250')) { fclose($socket); return false; }
    if ($port === 587) {
        $write('STARTTLS');
        if (!$expect($read(), '220')) { fclose($socket); return false; }
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); return false; }
        $write('EHLO ' . (string) ($cfg['ehlo'] ?? 'higodriver.com'));
        if (!$expect($read(), '250')) { fclose($socket); return false; }
    }
    $write('AUTH LOGIN');
    if (!$expect($read(), '334')) { fclose($socket); return false; }
    $write(base64_encode($user));
    if (!$expect($read(), '334')) { fclose($socket); return false; }
    $write(base64_encode($pass));
    if (!$expect($read(), '235')) { fclose($socket); return false; }
    $write('MAIL FROM:<' . $from . '>');
    if (!$expect($read(), '250')) { fclose($socket); return false; }
    $write('RCPT TO:<' . $safeTo . '>');
    if (!$expect($read(), '250')) { fclose($socket); return false; }
    $write('DATA');
    if (!$expect($read(), '354')) { fclose($socket); return false; }

    $message = 'Subject: ' . $encodedSubject . "\r\n";
    $message .= 'To: ' . $safeTo . "\r\n";
    $message .= 'From: ' . $fromName . ' <' . $from . ">\r\n";
    $message .= 'Reply-To: ' . $safeReplyTo . "\r\n";
    $message .= "MIME-Version: 1.0\r\n";
    $message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . "\"\r\n\r\n";
    $message .= $body;
    $message = preg_replace('/(^|\r\n)\./', '$1..', $message);
    fwrite($socket, $message . "\r\n.\r\n");
    if (!$expect($read(), '250')) { fclose($socket); return false; }
    $write('QUIT');
    fclose($socket);
    return true;
}
