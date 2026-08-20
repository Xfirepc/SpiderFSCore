<?php
declare(strict_types=1);

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['demo_csrf'])) {
    $_SESSION['demo_csrf'] = bin2hex(random_bytes(24));
}

$token = trim((string)($_GET['token'] ?? ''));
$registration = trim((string)($_GET['registration'] ?? ''));

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header(
    "Content-Security-Policy: default-src 'self'; "
    . "script-src 'self'; "
    . "style-src 'self'; img-src 'self' data:; connect-src 'self'; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['demo_csrf'], ENT_QUOTES, 'UTF-8') ?>">
    <meta name="activation-token" content="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="registration-id" content="<?= htmlspecialchars($registration, ENT_QUOTES, 'UTF-8') ?>">
    <title>Prueba SpiderCode por un mes</title>
    <link rel="stylesheet" href="./styles.css">
    <script src="./app.js" defer></script>
</head>
<body>
<div class="ambient ambient-one"></div>
<div class="ambient ambient-two"></div>
<main class="shell">
    <section class="story">
        <a class="brand" href="./index.php" aria-label="SpiderCode">
            <span class="brand-mark">S</span>
            <span>SpiderCode</span>
        </a>
        <p class="eyebrow">Tu operación, en orden</p>
        <h1>Prueba un sistema real con los datos de tu empresa.</h1>
        <p class="intro">
            Validamos tu RUC, protegemos tu acceso y preparamos un espacio privado
            para que explores SpiderCode durante un mes.
        </p>
        <div class="steps" aria-label="Pasos del registro">
            <div><span>01</span><p>Valida tu empresa</p></div>
            <div><span>02</span><p>Confirma tu correo</p></div>
            <div><span>03</span><p>Crea tu contraseña</p></div>
        </div>
        <p class="privacy">Tu contraseña no se envía por correo ni se guarda en este portal.</p>
    </section>

    <section class="panel" aria-live="polite">
        <div id="notice" class="notice" hidden></div>

        <div id="registration-view" <?= $token !== '' ? 'hidden' : '' ?>>
            <p class="panel-kicker">Prueba DEMO</p>
            <h2>Cuéntanos sobre tu empresa</h2>
            <p class="panel-copy">Recibirás un enlace válido durante 24 horas.</p>

            <form id="registration-form" novalidate>
                <label>
                    RUC
                    <input name="ruc" inputmode="numeric" pattern="[0-9]{13}" maxlength="13"
                           autocomplete="off" placeholder="1790012345001" required>
                </label>
                <div class="two-columns">
                    <label>
                        Correo
                        <input name="email" type="email" maxlength="100"
                               autocomplete="email" placeholder="tu@empresa.com" required>
                    </label>
                    <label>
                        Teléfono
                        <input name="phone" type="tel" maxlength="30"
                               autocomplete="tel" placeholder="+593 99 000 0000" required>
                    </label>
                </div>
                <label>
                    Producto DEMO
                    <select name="license_id" id="license-select" required>
                        <option value="">Cargando opciones…</option>
                    </select>
                </label>
                <label>
                    Usuario preferido <small>opcional</small>
                    <input name="username" maxlength="50" autocomplete="username"
                           placeholder="Se sugerirá uno desde tu correo">
                </label>

                <button class="primary" type="submit">
                    Enviar enlace de activación
                </button>
            </form>
            <div id="pending-view" hidden>
                <p class="panel-copy">¿No llegó? Espera un momento y revisa correo no deseado.</p>
                <button id="resend-button" class="secondary" type="button">Reenviar enlace</button>
            </div>
        </div>

        <div id="activation-view" <?= $token === '' ? 'hidden' : '' ?>>
            <p class="panel-kicker">Correo confirmado</p>
            <h2>Protege tu nuevo espacio</h2>
            <p class="panel-copy">Confirma tu usuario y crea una contraseña que solo tú conocerás.</p>

            <form id="activation-form" novalidate>
                <label>
                    Usuario
                    <input name="username" id="activation-username" maxlength="50"
                           autocomplete="username" required>
                </label>
                <label>
                    Contraseña
                    <input name="password" id="password" type="password" minlength="12" maxlength="72"
                           autocomplete="new-password" required>
                    <small>Entre 12 y 72 caracteres.</small>
                </label>
                <label>
                    Repite la contraseña
                    <input name="password_confirmation" type="password" minlength="12" maxlength="72"
                           autocomplete="new-password" required>
                </label>
                <button class="primary" type="submit">Crear mi espacio DEMO</button>
            </form>
        </div>

        <div id="success-view" hidden>
            <div class="success-icon">✓</div>
            <p class="panel-kicker">Todo listo</p>
            <h2>Tu espacio DEMO está activo</h2>
            <p id="success-copy" class="panel-copy"></p>
            <a id="login-link" class="primary link-button" href="#">Ingresar a SpiderCode</a>
        </div>
    </section>
</main>
</body>
</html>
