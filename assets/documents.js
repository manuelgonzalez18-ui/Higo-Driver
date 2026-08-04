(function () {
    'use strict';

    var UPLOAD_ENDPOINT = 'https://higoapp.com/api/driver-application-documents.php';
    var REQUIRED_FIELDS = [
        'profile_photo',
        'identity',
        'driver_license',
        'vehicle_registration',
        'rcv',
        'vehicle_photo'
    ];
    var MAX_FILE_SIZE = 8 * 1024 * 1024;
    var MAX_TOTAL_SIZE = 30 * 1024 * 1024;

    var labels = {
        profile_photo: 'foto de perfil del conductor',
        identity: 'cédula de identidad',
        driver_license: 'licencia de conducir',
        vehicle_registration: 'certificado de circulación',
        rcv: 'RCV vigente',
        vehicle_photo: 'fotografía del vehículo',
        health_certificate: 'certificado de salud',
        payment_details: 'datos para cobro directo',
        other: 'otro requisito'
    };

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
            invalid_or_expired_token: 'Este enlace no es válido, está siendo utilizado o ya venció. Solicita uno nuevo al equipo Higo.',
            documents_not_expected: 'La solicitud no está habilitada actualmente para recibir documentos.',
            no_documents: 'El servidor no recibió los archivos seleccionados. Recarga la página, vuelve a seleccionarlos e intenta nuevamente.',
            missing_required_document: 'Falta un documento obligatorio: ' + (labels[detail] || detail || 'requisito sin identificar') + '.',
            invalid_file_size: 'El archivo de ' + (labels[detail] || detail || 'uno de los requisitos') + ' supera el máximo permitido de 8 MB o está vacío.',
            total_upload_too_large: 'El conjunto de archivos supera el máximo permitido de 30 MB.',
            request_too_large: 'La carga supera el límite permitido por el servidor. Reduce el tamaño de las imágenes o archivos e intenta nuevamente.',
            invalid_file_type: 'El archivo de ' + (labels[detail] || detail || 'uno de los requisitos') + ' no tiene un formato permitido.',
            upload_failed: 'No se pudo leer el archivo de ' + (labels[detail] || detail || 'uno de los requisitos') + '. Vuelve a seleccionarlo.',
            document_upload_not_completed: 'La carga no pudo finalizar de forma segura. Ningún envío incompleto será procesado; intenta nuevamente.',
            upstream_unavailable: 'El servicio de verificación está temporalmente no disponible.',
            rate_limited: 'Has realizado varios intentos. Espera un minuto antes de volver a enviar.',
            invalid_response: 'El servidor no pudo procesar la carga. Recarga la página e intenta nuevamente.'
        };
        return messages[code] || ('No pudimos completar el envío.' + (detail ? ' Archivo: ' + detail : ''));
    }

    function selectedFile(input) {
        return input && input.files && input.files.length ? input.files[0] : null;
    }

    function fileToPayload(file) {
        return new Promise(function (resolve, reject) {
            var reader = new FileReader();
            reader.onerror = function () {
                reject(new Error('No se pudo leer el archivo ' + String(file.name || '') + '.'));
            };
            reader.onload = function () {
                var result = String(reader.result || '');
                var separator = result.indexOf(',');
                if (separator < 0) {
                    reject(new Error('No se pudo preparar el archivo ' + String(file.name || '') + '.'));
                    return;
                }
                resolve({
                    name: String(file.name || 'documento').slice(0, 180),
                    mime: String(file.type || 'application/octet-stream'),
                    size: file.size,
                    data_base64: result.slice(separator + 1)
                });
            };
            reader.readAsDataURL(file);
        });
    }

    function prepareFiles(form, button) {
        var inputs = Array.prototype.slice.call(form.querySelectorAll('input[type="file"]'));
        var files = {};
        var sequence = Promise.resolve();

        inputs.forEach(function (input) {
            var file = selectedFile(input);
            if (!file) return;
            sequence = sequence.then(function () {
                button.textContent = 'Preparando ' + (labels[input.name] || 'documento') + '…';
                return fileToPayload(file).then(function (payload) {
                    files[input.name] = payload;
                });
            });
        });

        return sequence.then(function () { return files; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = document.getElementById('documentsForm');
        var button = document.getElementById('submitDocuments');
        var confirmAccuracy = document.getElementById('confirmAccuracy');
        if (!form || !button) return;

        var token = new URLSearchParams(window.location.search).get('token') || '';
        if (!/^[A-Za-z0-9_-]{40,64}$/.test(token)) {
            button.disabled = true;
            showMessage('error', '<strong>Enlace no válido.</strong><br>Solicita un nuevo enlace seguro al equipo Higo.');
            return;
        }

        form.querySelectorAll('input[type="file"]').forEach(function (input) {
            input.addEventListener('change', function () {
                var file = selectedFile(input);
                if (!file) return;
                if (file.size <= 0 || file.size > MAX_FILE_SIZE) {
                    var fileName = String(file.name || '').replace(/[&<>"']/g, '');
                    input.value = '';
                    showMessage('error', 'El archivo <strong>' + fileName + '</strong> está vacío o supera 8 MB.');
                }
            });
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var missing = REQUIRED_FIELDS.filter(function (field) {
                return !selectedFile(form.elements[field]);
            });
            if (missing.length) {
                showMessage('error', '<strong>Faltan documentos obligatorios.</strong><br>Selecciona: ' + missing.map(function (field) {
                    return labels[field];
                }).join(', ') + '.');
                var firstMissing = form.elements[missing[0]];
                if (firstMissing && typeof firstMissing.focus === 'function') firstMissing.focus();
                return;
            }

            if (!confirmAccuracy || !confirmAccuracy.checked) {
                showMessage('error', '<strong>Falta la confirmación.</strong><br>Marca la casilla de autenticidad antes de enviar.');
                if (confirmAccuracy) confirmAccuracy.focus();
                return;
            }

            var totalSize = 0;
            var invalidFile = null;
            form.querySelectorAll('input[type="file"]').forEach(function (input) {
                var file = selectedFile(input);
                if (!file) return;
                totalSize += file.size;
                if (!invalidFile && (file.size <= 0 || file.size > MAX_FILE_SIZE)) invalidFile = input.name;
            });
            if (invalidFile) {
                showMessage('error', '<strong>Archivo no válido.</strong><br>' + formatError('invalid_file_size', invalidFile));
                return;
            }
            if (totalSize > MAX_TOTAL_SIZE) {
                showMessage('error', '<strong>La carga es demasiado grande.</strong><br>' + formatError('total_upload_too_large'));
                return;
            }

            var original = button.textContent;
            button.disabled = true;
            button.textContent = 'Preparando documentos…';
            showMessage('success', 'Estamos preparando y enviando los documentos de forma segura. No cierres esta ventana.');

            prepareFiles(form, button)
                .then(function (files) {
                    button.textContent = 'Enviando documentos para revisión…';
                    return fetch(UPLOAD_ENDPOINT, {
                        method: 'POST',
                        mode: 'cors',
                        credentials: 'omit',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({
                            token: token,
                            files: files
                        })
                    });
                })
                .then(function (response) {
                    return safeJson(response).then(function (body) {
                        if (response.status === 413 && !body.error) body.error = 'request_too_large';
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
                    var rawMessage = String(error && error.message ? error.message : '');
                    var message = /failed to fetch|networkerror|load failed/i.test(rawMessage)
                        ? 'No fue posible conectar con el servidor. Verifica tu conexión a internet, mantén esta página abierta y vuelve a intentarlo. Los documentos no se marcaron como enviados.'
                        : (rawMessage || 'Intenta nuevamente.');
                    showMessage('error', '<strong>No se pudo completar el envío.</strong><br>' + message);
                    button.disabled = false;
                    button.textContent = original;
                });
        });
    });
})();
