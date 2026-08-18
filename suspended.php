<?php
$ruc = htmlspecialchars($_COOKIE['ruc'] ?? $_SERVER['HTTP_X_RUC'] ?? '', ENT_QUOTES, 'UTF-8');
$isTrialExpired = ($GLOBALS['sbSuspensionReason'] ?? '') === 'trial_expired';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cuenta suspendida</title>
<style>
:root {
    --login-celeste: #31D2DD;
    --login-azul: #09172A;
    --login-gris: #F8F8F9;
}
* { box-sizing: border-box; }
body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--login-gris);
    color: var(--login-azul);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2rem 1rem;
    position: relative;
    overflow: hidden;
}
.bg-pattern {
    position: absolute;
    inset: 0;
    background-image:
        linear-gradient(rgba(9, 23, 42, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(9, 23, 42, 0.03) 1px, transparent 1px);
    background-size: 40px 40px;
    pointer-events: none;
}
.bg-pattern::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -20%;
    width: 60%;
    height: 150%;
    background: radial-gradient(ellipse, rgba(49, 210, 221, 0.08) 0%, transparent 70%);
}
.card {
    max-width: 480px;
    width: 100%;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 8px 40px rgba(9, 23, 42, 0.08);
    border: 1px solid rgba(9, 23, 42, 0.06);
    overflow: hidden;
    position: relative;
    z-index: 1;
}
.card::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    background: linear-gradient(180deg, var(--login-celeste), var(--login-azul));
}
.card-body {
    padding: 2.5rem 2.25rem;
    text-align: center;
}
.logo {
    width: 72px;
    height: 72px;
    margin: 0 auto 1.5rem;
    background: var(--login-azul);
    border-radius: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 20px rgba(9, 23, 42, 0.15);
}
.logo img {
    width: 44px;
    height: 44px;
    object-fit: contain;
}
h1 {
    margin: 0 0 0.5rem;
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--login-azul);
    letter-spacing: -0.01em;
}
.subtitle {
    margin: 0 0 1.25rem;
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--login-celeste);
    letter-spacing: 0.05em;
    text-transform: uppercase;
}
.ruc-badge {
    display: inline-block;
    padding: 0.35rem 0.9rem;
    margin-bottom: 1.25rem;
    background: var(--login-gris);
    border-radius: 999px;
    font-size: 0.75rem;
    letter-spacing: 0.05em;
    color: rgba(9, 23, 42, 0.65);
    border: 1px solid rgba(9, 23, 42, 0.06);
}
.ruc-badge strong {
    color: var(--login-azul);
    font-weight: 700;
    margin-left: 0.35rem;
}
.lead {
    margin: 0 0 1.75rem;
    color: rgba(9, 23, 42, 0.72);
    line-height: 1.6;
    font-size: 0.9375rem;
}
.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    background: linear-gradient(135deg, var(--login-celeste) 0%, #28b8c2 100%);
    color: var(--login-azul);
    font-weight: 600;
    font-size: 0.9375rem;
    text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
}
.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(49, 210, 221, 0.35);
}
.btn svg {
    width: 18px;
    height: 18px;
    fill: currentColor;
}
.btn-outline {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    background: transparent;
    border: 1px solid rgba(9, 23, 42, 0.2);
    color: var(--login-azul);
    font-weight: 600;
    font-size: 0.9375rem;
    text-decoration: none;
    transition: border-color 0.2s, background 0.2s;
}
.btn-outline:hover {
    border-color: var(--login-celeste);
    background: rgba(49, 210, 221, 0.08);
}
.btn-outline svg {
    width: 18px;
    height: 18px;
    fill: currentColor;
}
.footer {
    margin-top: 1.75rem;
    padding-top: 1.25rem;
    border-top: 1px solid rgba(9, 23, 42, 0.08);
    font-size: 0.8125rem;
    color: rgba(9, 23, 42, 0.6);
}
.footer a {
    color: var(--login-celeste);
    text-decoration: none;
}
.footer a:hover {
    text-decoration: underline;
}
</style>
</head>
<body>
<div class="bg-pattern"></div>
<div class="card">
    <div class="card-body">
        <div class="logo">
            <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAV4AAAFeCAMAAAD69YcoAAAAAXNSR0IB2cksfwAAAAlwSFlzAAALEwAACxMBAJqcGAAAAwBQTFRFAAAASt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7dSt7do8K1BwAAAQB0Uk5TAAEFFzx8ssDT5vT/zBb60cKmhEQPISwyeg4RQIC/7PzBFfH99qlVKjrhYz7e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" alt="SpiderCode">
        </div>
        <p class="subtitle"><?= $isTrialExpired ? 'Prueba finalizada' : 'Acceso bloqueado' ?></p>
        <h1><?= $isTrialExpired ? 'Tu período DEMO terminó' : 'Cuenta suspendida' ?></h1>
        <?php if ($ruc !== ''): ?>
            <div class="ruc-badge">RUC<strong><?= $ruc ?></strong></div>
        <?php endif; ?>
        <?php if ($isTrialExpired): ?>
            <p class="lead">
                Finalizó el mes de prueba de SpiderCode. Conservamos tu configuración
                y tus datos para que puedas conversar con nuestro equipo sobre el plan adecuado.
            </p>
        <?php else: ?>
            <p class="lead">
                Tu cuenta está temporalmente suspendida. Por favor, ponte al corriente
                con los pagos para mantener el acceso al sistema.
            </p>
        <?php endif; ?>
        <a class="btn" href="https://wa.link/ubiy13" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12.04 2c-5.52 0-10 4.48-10 10 0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.52 0 10-4.48 10-10s-4.48-10-10-10zm5.85 14.1c-.25.7-1.43 1.33-1.99 1.42-.53.08-1.2.12-1.94-.12-.45-.14-1.02-.33-1.75-.65-3.08-1.33-5.09-4.43-5.24-4.63-.15-.2-1.24-1.65-1.24-3.15 0-1.5.79-2.24 1.07-2.54.28-.3.61-.38.81-.38.2 0 .41 0 .58.01.19.01.44-.07.69.53.25.6.86 2.08.93 2.23.07.15.12.33.02.53-.1.2-.15.32-.3.5-.15.18-.31.4-.45.54-.15.15-.3.31-.13.61.17.3.77 1.27 1.65 2.05 1.13 1 2.08 1.31 2.38 1.46.3.15.47.12.65-.08.17-.2.74-.87.94-1.16.2-.3.4-.25.67-.15.27.1 1.72.81 2.02.96.3.15.5.22.57.35.07.13.07.77-.18 1.47z"/></svg>
            Contactar por WhatsApp
        </a>
        <br>
        <a class="btn-outline" href="/?sb_retry=1">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M17.65 6.35A7.958 7.958 0 0 0 12 4c-4.42 0-7.99 3.58-7.99 8s3.57 8 7.99 8c3.73 0 6.84-2.55 7.73-6h-2.08A5.99 5.99 0 0 1 12 18c-3.31 0-6-2.69-6-6s2.69-6 6-6c1.66 0 3.14.69 4.22 1.78L13 11h7V4l-2.35 2.35z"/></svg>
            Reintentar acceso
        </a>
        <div class="footer">
            <a href="https://spidercode.dev" target="_blank" rel="noopener">www.spidercode.dev</a>
        </div>
    </div>
</div>
</body>
</html>
