<?php
declare(strict_types=1);

require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_ratelimit.php';

hd_apply_cors('POST, OPTIONS');
api_rate_limit('upload-documents', 8, sys_get_temp_dir() . '/higodriver_document_upload.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ud_send(int $status, array $payload): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ud_send(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 41943040) {
    ud_send(413, ['ok' => false, 'error' => 'request_too_large']);
}
if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
    ud_send(413, ['ok' => false, 'error' => 'request_too_large']);
}

$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
    ud_send(401, ['ok' => false, 'error' => 'invalid_or_expired_token']);
}

$configCandidates = [
    __DIR__ . '/_smtp_config.php',
    dirname(__DIR__, 2) . '/Private/smtp-config.php',
    dirname(__DIR__, 3) . '/Private/smtp-config.php',
    dirname(__DIR__, 2) . '/private/smtp-config.php',
    dirname(__DIR__, 3) . '/private/smtp-config.php',
];
$config = [];
foreach ($configCandidates as $candidate) {
    if (!is_file($candidate)) continue;
    $loaded = require $candidate;
    if (is_array($loaded)) $config = $loaded;
    break;
}
$baseUrl = rtrim((string) ($config['higo_app_base_url'] ?? 'https://higoapp.com'), '/');

$fields = [
    'profile_photo',
    'identity',
    'driver_license',
    'vehicle_registration',
    'rcv',
    'vehicle_photo',
    'health_certificate',
    'payment_details',
    'other',
];
$requiredFields = ['profile_photo','identity','driver_license','vehicle_registration','rcv','vehicle_photo'];
$allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
$totalSize = 0;
$fileCount = 0;
$presentFields = [];
$filesPayload = [];
$finfo = new finfo(FILEINFO_MIME_TYPE);

foreach ($fields as $field) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) continue;
    $file = $_FILES[$field];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        ud_send(422, ['ok' => false, 'error' => 'upload_failed', 'detail' => $field]);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $size = (int) ($file['size'] ?? 0);
    if (!is_uploaded_file($tmp) || $size <= 0 || $size > 8388608) {
        ud_send(422, ['ok' => false, 'error' => 'invalid_file_size', 'detail' => $field]);
    }
    $mime = (string) $finfo->file($tmp);
    if (!in_array($mime, $allowedMime, true)) {
        ud_send(422, ['ok' => false, 'error' => 'invalid_file_type', 'detail' => $field]);
    }
    if (in_array($field, ['profile_photo', 'vehicle_photo'], true) && $mime === 'application/pdf') {
        ud_send(422, ['ok' => false, 'error' => 'invalid_file_type', 'detail' => $field]);
    }
    $totalSize += $size;
    if ($totalSize > 31457280) {
        ud_send(413, ['ok' => false, 'error' => 'total_upload_too_large']);
    }

    $binary = file_get_contents($tmp);
    if (!is_string($binary) || $binary === '') {
        ud_send(422, ['ok' => false, 'error' => 'upload_failed', 'detail' => $field]);
    }

    $filesPayload[$field] = [
        'name' => substr(basename((string) ($file['name'] ?? $field)), 0, 180),
        'mime' => $mime,
        'size' => $size,
        'data_base64' => base64_encode($binary),
    ];
    $presentFields[$field] = true;
    $fileCount++;
}

if ($fileCount === 0) ud_send(422, ['ok' => false, 'error' => 'no_documents']);
foreach ($requiredFields as $requiredField) {
    if (empty($presentFields[$requiredField])) {
        ud_send(422, ['ok' => false, 'error' => 'missing_required_document', 'detail' => $requiredField]);
    }
}

$payload = json_encode([
    'token' => $token,
    'files' => $filesPayload,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (!is_string($payload)) {
    ud_send(500, ['ok' => false, 'error' => 'payload_encoding_failed']);
}
if (strlen($payload) > 52428800) {
    ud_send(413, ['ok' => false, 'error' => 'request_too_large']);
}

$ch = curl_init($baseUrl . '/api/driver-application-documents.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Content-Type: application/json',
        'Content-Length: ' . strlen($payload),
        'Expect:',
    ],
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    error_log('upload-documents upstream failed: ' . $error);
    ud_send(502, ['ok' => false, 'error' => 'upstream_unavailable']);
}
http_response_code($status > 0 ? $status : 502);
echo $response;
