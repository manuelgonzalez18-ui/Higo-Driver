(function () {
    'use strict';

    function safeJson(response) {
        return response.text().then(function (text) {
            if (!text) return {};
            try { return JSON.parse(text); } catch (error) { return { ok: false, error: 'invalid_response' }; }
        });
    }

    function showMessage(type, html) {
        var message = document.getElementById('pageMessage');
        if (!message) return;
        message.className = 'message show ' + type;
        message.innerHTML = html;
        message.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function formatError(code, detail) {
        var messages = {
            invalid_or_expired_token: 'Este enlace no es válido o ya venció. Solicita uno nuevo al equipo Higo.',
            documents_not_expected: 'La solicitud no está habilitada actualmente para recibir documentos.',
            no_documents: 'Selecciona los documentos requeridos antes de continuar.',
            invalid_file_size: 'Uno de los archivos supera el máximo permitido de 8 MB.',
            total_upload_too_large: 'El conjunto de archivos supera el máximo permitido de 30 MB.',
            invalid_file_type: 'Uno de los archivos no tiene un formato permitido.',
            storage_upload_failed: 'No pudimos almacenar uno de los archivos. Intenta nuevamente.',
            document_metadata_failed: 'Los archivos se recibieron parcialmente. Contacta al equipo Higo antes de repetir el envío.',
            upstream_unavailable: 'El servicio de verificación está temporalmente no disponible.',
            rate_limited: 'Has realizado varios intentos. Espera un minuto antes de volver a enviar.'
        };
        return messages[code] || ('No pudimos completar el envío.' + (detail ? ' Archivo: ' + detail : ''));
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('documentsForm');
        var button = document.getElementById('submitDocuments');
        if (!form || !button) return;

        var token = new URLSearchParams(window.location.search).get('token') || '';
        if (!/^[A-Za-z0-9_-]{40,64}$/.test(token)) {
            button.disabled = true;
            showMessage('error', '<strong>Enlace no válido.</strong><br>Solicita un nuevo enlace seguro al equipo Higo.');
            return;
        }

        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file) return;
                if (file.size > 8 * 1024 * 1024) {
                    input.value = '';
                    showMessage('error', 'El archivo <strong>' + file.name.replace(/[&<>"']/g, '') + '</strong> supera 8 MB.');
                }
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }

            var data = new FormData(form);
            data.append('token', token);
            var original = button.textContent;
            button.disabled = true;
            button.textContent = 'Cargando y protegiendo documentos…';
            showMessage('success', 'La carga puede tardar algunos segundos. No cierres esta ventana.');

            fetch('/api/upload-documents.php', {
                method: 'POST',
                body: data
            })
                .then(function (response) {
                    return safeJson(response).then(function (body) {
                        return { response: response, body: body };
                    });
                })
                .then(function (result) {
                    if (!result.response.ok || !result.body.ok) {
                        throw new Error(formatError(result.body.error, result.body.detail));
                    }
                    form.hidden = true;
                    var code = String(result.body.application_id || '');
                    var statusUrl = '/status/?id=' + encodeURIComponent(code);
                    showMessage('success', '<strong>Documentos recibidos correctamente.</strong><br>El equipo Higo los revisará y te notificará por correo.<br><br><a href="' + statusUrl + '">Consultar el estado de la solicitud →</a>');
                })
                .catch(function (error) {
                    showMessage('error', '<strong>No se pudo completar el envío.</strong><br>' + String(error.message || 'Intenta nuevamente.'));
                    button.disabled = false;
                    button.textContent = original;
                });
        });
    });
})();
