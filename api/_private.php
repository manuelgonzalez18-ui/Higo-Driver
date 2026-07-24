<?php
declare(strict_types=1);

if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === basename(__FILE__)) {
    http_response_code(403);
    exit('forbidden');
}

function hd_private_dir(): ?string {
    $candidates = [
        dirname(__DIR__, 2) . '/Private',
        dirname(__DIR__, 3) . '/Private',
        dirname(__DIR__, 2) . '/private',
        dirname(__DIR__, 3) . '/private',
    ];

    foreach ($candidates as $candidate) {
        if (is_dir($candidate) && is_writable($candidate)) {
            return $candidate;
        }
    }

    foreach ($candidates as $candidate) {
        $parent = dirname($candidate);
        if (is_dir($parent) && is_writable($parent) && @mkdir($candidate, 0700, true)) {
            return $candidate;
        }
    }

    return null;
}

function hd_private_file(string $filename): ?string {
    $dir = hd_private_dir();
    if ($dir === null) return null;
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
    return $dir . '/' . $safe;
}

function hd_json_read(string $filename, array $fallback = []): array {
    $path = hd_private_file($filename);
    if ($path === null || !is_file($path)) return $fallback;
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') return $fallback;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function hd_json_mutate(string $filename, callable $callback): ?array {
    $path = hd_private_file($filename);
    if ($path === null) return null;

    $handle = @fopen($path, 'c+');
    if ($handle === false) return null;
    if (!flock($handle, LOCK_EX)) {
        fclose($handle);
        return null;
    }

    rewind($handle);
    $raw = stream_get_contents($handle);
    $current = [];
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) $current = $decoded;
    }

    $next = call_user_func($callback, $current);
    if (!is_array($next)) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return null;
    }

    $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        flock($handle, LOCK_UN);
        fclose($handle);
        return null;
    }

    rewind($handle);
    ftruncate($handle, 0);
    $written = fwrite($handle, $json . "\n");
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    return $written === false ? null : $next;
}

function hd_hash_email(string $email): string {
    return hash('sha256', strtolower(trim($email)));
}

function hd_hash_ip(): string {
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return hash('sha256', $ip . '|higodriver');
}

function hd_increment_funnel(string $event, array $context = []): bool {
    $allowed = [
        'page_view', 'cta_click', 'form_start', 'form_step', 'form_error',
        'application_submitted', 'status_lookup'
    ];
    if (!in_array($event, $allowed, true)) return false;

    $day = gmdate('Y-m-d');
    $contextKey = 'all';
    if (!empty($context)) {
        ksort($context);
        $parts = [];
        foreach ($context as $key => $value) {
            $cleanKey = preg_replace('/[^a-z0-9_-]/i', '', (string) $key);
            $cleanValue = preg_replace('/[^a-z0-9 _.-]/i', '', substr((string) $value, 0, 48));
            if ($cleanKey !== '' && $cleanValue !== '') $parts[] = $cleanKey . '=' . $cleanValue;
        }
        if (!empty($parts)) $contextKey = implode('&', $parts);
    }

    return hd_json_mutate('higodriver-funnel.json', function (array $data) use ($day, $event, $contextKey): array {
        if (!isset($data[$day]) || !is_array($data[$day])) $data[$day] = [];
        if (!isset($data[$day][$event]) || !is_array($data[$day][$event])) $data[$day][$event] = [];
        $current = isset($data[$day][$event][$contextKey]) ? (int) $data[$day][$event][$contextKey] : 0;
        $data[$day][$event][$contextKey] = $current + 1;

        if (count($data) > 120) {
            ksort($data);
            while (count($data) > 120) array_shift($data);
        }
        return $data;
    }) !== null;
}
