<?php
declare(strict_types=1);

require_once __DIR__ . '/_private.php';
require_once __DIR__ . '/_ratelimit.php';

api_rate_limit('admin-update-status', 20, sys_get_temp_dir() . '/higodriver_admin_status.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
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
foreach ($configCandidates as $path) {
    if (!is_file($path)) continue;
    $loaded = require $path;
    if (is_array($loaded)) $config = $loaded;
    break;
}

$expected = is_array($config) ? (string) ($config['status_update_secret'] ?? '') : '';
$provided = (string) ($_SERVER['HTTP_X_HIGO_ADMIN_SECRET'] ?? '');
if ($expected === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'status_updates_not_configured']);
    exit;
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

$applicationId = strtoupper(trim((string) ($data['application_id'] ?? '')));
$status = trim((string) ($data['status'] ?? ''));
$allowed = ['received', 'under_review', 'documents_requested', 'approved', 'waitlist', 'rejected'];
if (!preg_match('/^HD-\d{8}-[A-F0-9]{8}$/', $applicationId) || !in_array($status, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_payload']);
    exit;
}

$found = false;
$updated = hd_json_mutate('driver-applications.json', function (array $store) use ($applicationId, $status, &$found): array {
    if (!isset($store['applications'][$applicationId]) || !is_array($store['applications'][$applicationId])) return $store;
    $found = true;
    $store['applications'][$applicationId]['status'] = $status;
    $store['applications'][$applicationId]['updated_at'] = gmdate('c');
    return $store;
});

if ($updated === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'storage_failed']);
    exit;
}
if (!$found) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'not_found']);
    exit;
}

echo json_encode(['ok' => true, 'application_id' => $applicationId, 'status' => $status]);
