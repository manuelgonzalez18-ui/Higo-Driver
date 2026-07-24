# Higo Driver

Sitio público de información, pre-registro y seguimiento para aspirantes a conductores Higo.

## Flujo implementado

1. El aspirante completa un pre-registro sin adjuntar documentos.
2. El servidor genera un código `HD-YYYYMMDD-XXXXXXXX`.
3. La solicitud se envía por SMTP al equipo y se confirma al aspirante.
4. El código y el correo permiten consultar el estado en `/status/`.
5. Los documentos se solicitan posteriormente mediante el onboarding seguro de Higo.
6. Los precios y períodos no están fijados en el HTML: `/api/public-config.php` publica el catálogo vigente.

## Archivos privados en Hostinger

La carpeta privada debe vivir fuera de `public_html`:

```text
/Private/smtp-config.php
/Private/driver-applications.json        # creado automáticamente
/Private/higodriver-funnel.json          # creado automáticamente
/Private/higodriver-public-config.php    # opcional
```

`/Private/smtp-config.php` parte de `api/_smtp_config.example.php` y nunca debe versionarse.

### Catálogo público opcional

`/Private/higodriver-public-config.php` puede devolver:

```php
<?php
return [
    'membership_note' => 'Consulta la oferta vigente en Higo Pay.',
    'plans' => [
        [
            'id' => 'moto',
            'name' => 'Higo Moto',
            'tag' => 'Movilidad ágil',
            'description' => 'Descripción pública.',
            'price_label' => 'Ver oferta vigente en Higo Pay',
            'features' => ['Viajes', 'Envíos'],
            'featured' => false,
        ],
    ],
];
```

Solo los campos permitidos por `public-config.php` llegan al navegador.

## Estado de solicitudes

Los estados públicos soportados son:

- `received`
- `under_review`
- `documents_requested`
- `approved`
- `waitlist`
- `rejected`

El endpoint privado `POST /api/admin-update-status.php` requiere el encabezado `X-Higo-Admin-Secret`. Para habilitarlo agrega `status_update_secret` en `smtp-config.php`.

Ejemplo:

```bash
curl -X POST https://higodriver.com/api/admin-update-status.php \
  -H 'Content-Type: application/json' \
  -H 'X-Higo-Admin-Secret: TU_SECRETO' \
  -d '{"application_id":"HD-20260724-ABCDEF12","status":"under_review"}'
```

## Analítica del embudo

`/api/track.php` registra únicamente conteos agregados por día y evento. No usa cookies, identificadores publicitarios ni almacena correo, cédula o teléfono.

Eventos:

- `page_view`
- `cta_click`
- `form_start`
- `form_step`
- `form_error`
- `application_submitted`
- `status_lookup`

## Seguridad

- CORS restringido a orígenes autorizados.
- Rate limiting por IP.
- Honeypot anti-bot.
- Idempotencia para evitar solicitudes duplicadas.
- No se aceptan archivos en el endpoint público.
- Datos de seguimiento almacenados fuera de `public_html`.
- Correo y placa guardados solo como hash en el archivo de seguimiento.
- Cabeceras de seguridad y CSP mediante `.htaccess`.
- Configuración SMTP fuera de GitHub y fuera de la raíz pública.

## Despliegue

Hostinger está conectado al repositorio privado mediante deploy key y webhook. La rama de producción es `main` y la ruta de instalación es `/` (`public_html`).

Proceso recomendado:

1. Crear rama `agent/...`.
2. Validar PHP y JavaScript.
3. Abrir Pull Request.
4. Fusionar a `main`.
5. Verificar el webhook y hacer prueba de humo en producción.

## Validación local

```bash
find api -name '*.php' -print0 | xargs -0 -n1 php -l
node --check assets/app.js
```
