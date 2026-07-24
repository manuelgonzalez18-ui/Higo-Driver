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
$config = null;
foreach ($configCandidates as $candidate) {
    if (!is_file($candidate)) continue;
    $loaded = require $candidate;
    if (is_array($loaded)) $config = $loaded;
    break;
}
$baseUrl = rtrim((string) ($config['higo_app_base_url'] ?? 'https://higoapp.com'), '/');

$fields = ['identity','driver_license','vehicle_registration','rcv','vehicle_photo','health_certificate','payment_details','other'];
$postFields = ['token' => $token];
$totalSize = 0;
$fileCount = 0;
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
    $totalSize += $size;
    if ($totalSize > 31457280) ud_send(422, ['ok' => false, 'error' => 'total_upload_too_large']);
    $mime = (string) (@mime_content_type($tmp) ?: 'application/octet-stream');
    $postFields[$field] = new CURLFile($tmp, $mime, basename((string) ($file['name'] ?? $field)));
    $fileCount++;
}
if ($fileCount === 0) ud_send(422, ['ok' => false, 'error' => 'no_documents']);

$ch = curl_init($baseUrl . '/api/driver-application-documents.php');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Expect:'],
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
