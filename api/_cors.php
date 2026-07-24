<?php
/**
 * CORS para los endpoints públicos de higodriver.com.
 * Solo se permiten los orígenes de producción. Las llamadas internas sin
 * encabezado Origin continúan disponibles para tareas server-to-server.
 */

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('forbidden');
}

function hd_apply_cors(string $methods = 'POST, OPTIONS', array $extraHdrs = []): void {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $allowed = [
        'https://higodriver.com',
        'https://www.higodriver.com',
    ];
    $isAllowed = $origin !== '' && in_array($origin, $allowed, true);

    if ($isAllowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        $hdrList = array_merge(['Content-Type'], $extraHdrs);
        header('Access-Control-Allow-Headers: ' . implode(', ', $hdrList));
        header('Access-Control-Allow-Methods: ' . $methods);
        header('Access-Control-Max-Age: 600');
    } elseif ($origin !== '') {
        error_log(sprintf(
            '[higodriver CORS] Rejected origin "%s" on %s (UA: %s, IP: %s)',
            $origin,
            $_SERVER['REQUEST_URI'] ?? '?',
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 100),
            $_SERVER['REMOTE_ADDR'] ?? '-'
        ));
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code($isAllowed ? 204 : 403);
        exit;
    }

    if ($origin !== '' && !$isAllowed) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'origin_not_allowed']);
        exit;
    }
}
