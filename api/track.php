<?php
declare(strict_types=1);

require_once __DIR__ . '/_cors.php';
require_once __DIR__ . '/_ratelimit.php';
require_once __DIR__ . '/_private.php';

hd_apply_cors('POST, OPTIONS');
api_rate_limit('higodriver-track', 60);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = is_string($raw) ? json_decode($raw, true) : null;
if (!is_array($data)) {
    http_response_code(204);
    exit;
}

$event = preg_replace('/[^a-z0-9_-]/i', '', substr((string) ($data['event'] ?? ''), 0, 48));
$context = isset($data['context']) && is_array($data['context']) ? $data['context'] : [];
$page = preg_replace('/[^a-z0-9_\/-]/i', '', substr((string) ($data['page'] ?? ''), 0, 48));
if ($page !== '') $context['page'] = $page;

hd_increment_funnel($event, $context);
http_response_code(204);
