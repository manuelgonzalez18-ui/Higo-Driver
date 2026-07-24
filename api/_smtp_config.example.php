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

    // Integración servidor-a-servidor con el panel administrativo de Higo App.
    // El mismo valor debe existir como DRIVER_APPLICATION_INGEST_SECRET en
    // /private/higo-banesco.php del hosting de higoapp.com.
    'higo_app_base_url' => 'https://higoapp.com',
    'higo_app_ingest_secret' => 'REEMPLAZAR_CON_SECRETO_COMPARTIDO_LARGO',

    // Compatibilidad temporal con el endpoint administrativo anterior.
    // 'status_update_secret' => 'REEMPLAZAR_CON_SECRETO_LARGO_Y_ALEATORIO',
];
