(function () {
    'use strict';

    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    var activationToken = document.querySelector('meta[name="activation-token"]').content;
    var registrationId = document.querySelector('meta[name="registration-id"]').content;
    var notice = document.getElementById('notice');

    function uuid() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        return Array.prototype.map.call(bytes, function (value) {
            return value.toString(16).padStart(2, '0');
        }).join('');
    }

    function showNotice(message, kind) {
        notice.textContent = message;
        notice.className = 'notice ' + (kind || 'error');
        notice.hidden = false;
    }

    function clearNotice() {
        notice.hidden = true;
        notice.textContent = '';
    }

    function setBusy(form, busy) {
        var button = form.querySelector('button[type="submit"]');
        button.disabled = busy;
        button.dataset.label = button.dataset.label || button.textContent;
        button.textContent = busy ? 'Procesando…' : button.dataset.label;
    }

    function request(action, options) {
        options = options || {};
        var headers = {'Accept': 'application/json'};
        if (options.method === 'POST') {
            headers['Content-Type'] = 'application/json';
            headers['X-CSRF-Token'] = csrf;
        }
        if (options.idempotency) {
            headers['Idempotency-Key'] = options.idempotency;
        }
        return fetch('./api.php?action=' + encodeURIComponent(action), {
            method: options.method || 'GET',
            headers: headers,
            body: options.body ? JSON.stringify(options.body) : undefined,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().catch(function () {
                return {error: 'Respuesta no válida del servicio'};
            }).then(function (payload) {
                if (!response.ok) {
                    throw new Error(payload.error || 'No se pudo completar la solicitud');
                }
                return payload.data;
            });
        });
    }

    function loadLicenses() {
        var select = document.getElementById('license-select');
        if (!select) {
            return;
        }
        request('licenses').then(function (licenses) {
            select.innerHTML = '<option value="">Selecciona un producto</option>';
            licenses.forEach(function (license) {
                var option = document.createElement('option');
                option.value = license.id;
                option.textContent = license.name + (license.description ? ' — ' + license.description : '');
                select.appendChild(option);
            });
            if (!licenses.length) {
                showNotice('En este momento no hay productos DEMO disponibles.', 'warning');
            }
        }).catch(function (error) {
            select.innerHTML = '<option value="">No disponible</option>';
            showNotice(error.message);
        });
    }

    function prepareActivation() {
        if (!activationToken) {
            return;
        }
        if (!registrationId) {
            showNotice('El enlace de activación está incompleto.');
            return;
        }
        request('status', {
            method: 'POST',
            body: {public_id: registrationId}
        }).then(function (status) {
            if (status.status === 'completed' && status.login_url) {
                showSuccess(status);
                return;
            }
            document.getElementById('activation-username').value = status.suggested_username || '';
            if (status.status === 'expired' || status.status === 'released') {
                showNotice(status.error || 'Este enlace ya no está disponible.');
                document.querySelector('#activation-form button').disabled = true;
            }
        }).catch(function (error) {
            showNotice(error.message);
        });
    }

    function showSuccess(result) {
        document.getElementById('registration-view').hidden = true;
        document.getElementById('activation-view').hidden = true;
        document.getElementById('success-view').hidden = false;
        document.getElementById('success-copy').textContent =
            'Usuario ' + result.username + '. Tu prueba estará activa hasta ' + result.trial_ends_at + '.';
        document.getElementById('login-link').href = result.login_url;
        window.history.replaceState({}, document.title, './index.php');
        clearNotice();
    }

    var registrationForm = document.getElementById('registration-form');
    if (registrationForm) {
        registrationForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearNotice();
            if (!registrationForm.reportValidity()) {
                return;
            }
            var key = sessionStorage.getItem('demo-register-idempotency') || ('register-' + uuid());
            sessionStorage.setItem('demo-register-idempotency', key);
            var values = new FormData(registrationForm);
            var payload = {
                ruc: values.get('ruc'),
                email: values.get('email'),
                phone: values.get('phone'),
                license_id: values.get('license_id'),
                username: values.get('username')
            };
            setBusy(registrationForm, true);
            request('register', {method: 'POST', body: payload, idempotency: key})
                .then(function (result) {
                    sessionStorage.removeItem('demo-register-idempotency');
                    registrationForm.hidden = true;
                    sessionStorage.setItem('demo-pending-registration', result.public_id);
                    document.getElementById('pending-view').hidden = false;
                    showNotice(
                        'Enviamos el enlace de activación a ' + result.email
                        + '. Revisa también la carpeta de correo no deseado.',
                        'success'
                    );
                })
                .catch(function (error) {
                    showNotice(error.message);
                })
                .finally(function () {
                    setBusy(registrationForm, false);
                });
        });
    }

    var resendButton = document.getElementById('resend-button');
    if (resendButton) {
        resendButton.addEventListener('click', function () {
            var publicId = sessionStorage.getItem('demo-pending-registration');
            if (!publicId) {
                showNotice('Recarga el formulario para iniciar una solicitud nueva.');
                return;
            }
            resendButton.disabled = true;
            request('resend', {
                method: 'POST',
                body: {public_id: publicId}
            }).then(function (result) {
                showNotice('Enviamos un enlace nuevo a ' + result.email + '.', 'success');
            }).catch(function (error) {
                showNotice(error.message);
            }).finally(function () {
                resendButton.disabled = false;
            });
        });
    }

    var activationForm = document.getElementById('activation-form');
    if (activationForm) {
        activationForm.addEventListener('submit', function (event) {
            event.preventDefault();
            clearNotice();
            if (!activationForm.reportValidity()) {
                return;
            }
            var values = new FormData(activationForm);
            if (values.get('password') !== values.get('password_confirmation')) {
                showNotice('Las contraseñas no coinciden.');
                return;
            }
            var keyName = 'demo-activate-' + registrationId;
            var key = sessionStorage.getItem(keyName) || ('activate-' + uuid());
            sessionStorage.setItem(keyName, key);
            setBusy(activationForm, true);
            request('activate', {
                method: 'POST',
                idempotency: key,
                body: {
                    token: activationToken,
                    username: values.get('username'),
                    password: values.get('password')
                }
            }).then(function (result) {
                sessionStorage.removeItem(keyName);
                sessionStorage.removeItem('demo-pending-registration');
                activationForm.reset();
                showSuccess(result);
            }).catch(function (error) {
                showNotice(error.message);
            }).finally(function () {
                setBusy(activationForm, false);
            });
        });
    }

    loadLicenses();
    prepareActivation();
}());
