<?php
// Copia esta plantilla fuera de public_html, por ejemplo:
// /Private/smtp-config.php
// Nunca publiques este archivo con valores reales.

return [
    'host' => 'smtp.hostinger.com',
    'port' => 465,
    'username' => 'admin@higodriver.com',
    'password' => 'REEMPLAZAR_CON_PASSWORD_REAL',
    'from_email' => 'admin@higodriver.com',
    'from_name' => 'Higo Driver',
    // 'ehlo' => 'higodriver.com',

    // Opcional: permite que un sistema administrativo actualice el estado
    // de una solicitud mediante POST /api/admin-update-status.php.
    // Genera un valor largo y aleatorio; envíalo en X-Higo-Admin-Secret.
    // 'status_update_secret' => 'REEMPLAZAR_CON_SECRETO_LARGO_Y_ALEATORIO',
];
