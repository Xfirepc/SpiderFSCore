<?php
$ruc = htmlspecialchars($_COOKIE['ruc'] ?? $_SERVER['HTTP_X_RUC'] ?? '', ENT_QUOTES, 'UTF-8');
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
            <img src="/Dinamic/Assets/Images/logo-sky.png" alt="SpiderCode">
        </div>
        <p class="subtitle">Acceso bloqueado</p>
        <h1>Cuenta suspendida</h1>
        <?php if ($ruc !== ''): ?>
            <div class="ruc-badge">RUC<strong><?= $ruc ?></strong></div>
        <?php endif; ?>
        <p class="lead">
            Tu cuenta está temporalmente suspendida. Por favor, ponte al corriente
            con los pagos para mantener el acceso al sistema.
        </p>
        <a class="btn" href="https://wa.link/ubiy13" target="_blank" rel="noopener">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path d="M12.04 2c-5.52 0-10 4.48-10 10 0 1.76.46 3.45 1.32 4.95L2 22l5.25-1.38c1.45.79 3.08 1.21 4.79 1.21 5.52 0 10-4.48 10-10s-4.48-10-10-10zm5.85 14.1c-.25.7-1.43 1.33-1.99 1.42-.53.08-1.2.12-1.94-.12-.45-.14-1.02-.33-1.75-.65-3.08-1.33-5.09-4.43-5.24-4.63-.15-.2-1.24-1.65-1.24-3.15 0-1.5.79-2.24 1.07-2.54.28-.3.61-.38.81-.38.2 0 .41 0 .58.01.19.01.44-.07.69.53.25.6.86 2.08.93 2.23.07.15.12.33.02.53-.1.2-.15.32-.3.5-.15.18-.31.4-.45.54-.15.15-.3.31-.13.61.17.3.77 1.27 1.65 2.05 1.13 1 2.08 1.31 2.38 1.46.3.15.47.12.65-.08.17-.2.74-.87.94-1.16.2-.3.4-.25.67-.15.27.1 1.72.81 2.02.96.3.15.5.22.57.35.07.13.07.77-.18 1.47z"/></svg>
            Contactar por WhatsApp
        </a>
        <div class="footer">
            <a href="https://spidercode.dev" target="_blank" rel="noopener">www.spidercode.dev</a>
        </div>
    </div>
</div>
</body>
</html>
