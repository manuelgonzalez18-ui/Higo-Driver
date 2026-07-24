<?php
declare(strict_types=1);

require_once __DIR__ . '/_cors.php';
hd_apply_cors('GET, OPTIONS');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=600');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$config = [
    'membership_note' => 'La membresía vigente se consulta y paga dentro de Higo Pay antes de confirmar.',
    'legal' => [
        'terms_version' => '2026-05-19',
        'privacy_version' => '2026-05-19',
    ],
    'plans' => [
        [
            'id' => 'moto',
            'name' => 'Higo Moto',
            'tag' => 'Movilidad ágil',
            'description' => 'Para conductores de moto que cumplen los requisitos de su zona.',
            'price_label' => 'Ver oferta vigente en Higo Pay',
            'features' => ['Viajes en moto', 'Envíos compatibles', 'Perfil verificado'],
            'featured' => false,
        ],
        [
            'id' => 'carro',
            'name' => 'Higo Carro',
            'tag' => 'Movilidad urbana',
            'description' => 'Para conductores de vehículos particulares habilitados.',
            'price_label' => 'Ver oferta vigente en Higo Pay',
            'features' => ['Viajes de pasajeros', 'Servicios habilitados por zona', 'Soporte Higo'],
            'featured' => true,
        ],
        [
            'id' => 'camioneta',
            'name' => 'Higo Camioneta',
            'tag' => 'Mayor capacidad',
            'description' => 'Para vehículos con capacidad ampliada y servicios compatibles.',
            'price_label' => 'Ver oferta vigente en Higo Pay',
            'features' => ['Pasajeros y carga compatible', 'Rutas habilitadas', 'Perfil verificado'],
            'featured' => false,
        ],
    ],
];

$candidates = [
    dirname(__DIR__, 2) . '/Private/higodriver-public-config.php',
    dirname(__DIR__, 3) . '/Private/higodriver-public-config.php',
    dirname(__DIR__, 2) . '/private/higodriver-public-config.php',
    dirname(__DIR__, 3) . '/private/higodriver-public-config.php',
];
foreach ($candidates as $path) {
    if (!is_file($path)) continue;
    $custom = require $path;
    if (!is_array($custom)) break;
    if (isset($custom['membership_note']) && is_string($custom['membership_note'])) {
        $config['membership_note'] = substr($custom['membership_note'], 0, 240);
    }
    if (isset($custom['plans']) && is_array($custom['plans'])) {
        $safePlans = [];
        foreach (array_slice($custom['plans'], 0, 6) as $plan) {
            if (!is_array($plan) || empty($plan['id']) || empty($plan['name'])) continue;
            $safePlans[] = [
                'id' => preg_replace('/[^a-z0-9_-]/i', '', substr((string) $plan['id'], 0, 32)),
                'name' => substr((string) $plan['name'], 0, 80),
                'tag' => substr((string) ($plan['tag'] ?? 'Modalidad'), 0, 60),
                'description' => substr((string) ($plan['description'] ?? ''), 0, 240),
                'price_label' => substr((string) ($plan['price_label'] ?? 'Ver oferta vigente en Higo Pay'), 0, 100),
                'features' => array_values(array_map(function ($item) { return substr((string) $item, 0, 100); }, array_slice((array) ($plan['features'] ?? []), 0, 6))),
                'featured' => !empty($plan['featured']),
            ];
        }
        if (!empty($safePlans)) $config['plans'] = $safePlans;
    }
    break;
}

echo json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
