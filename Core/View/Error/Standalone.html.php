<?php
// Esta vista solo se carga desde ErrorPage, nunca directamente desde una URL.
if (!isset($public, $escape, $admin, $homeUrl, $supportUrl, $logo)) {
    return;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $escape($public['title']) ?> · SpiderCode</title>
    <script>
        try {
            var theme = localStorage.getItem('spider-theme') || 'light';
            if (theme === 'system') theme = matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = theme === 'dark' ? 'dark' : 'light';
        } catch (error) {}
    </script>
    <style>
        :root { color-scheme: light; --bg: #f4f6f9; --card: #fff; --text: #102a43; --muted: #526579;
            --border: #dce5ed; --soft: #f0f7fa; --accent: #087e8b; --navy: #05142d; }
        [data-theme="dark"] { color-scheme: dark; --bg: #0b0f19; --card: #171d2b; --text: #e2eaf3;
            --muted: #a8b7ca; --border: #334155; --soft: #142e3a; --accent: #55dbe0; --navy: #050810; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: var(--bg); color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; line-height: 1.6; }
        .brand-bar { background: var(--navy); padding: 20px 32px; }
        .brand { display: inline-flex; align-items: center; gap: 12px; text-decoration: none; color: #fff;
            font-size: 21px; font-weight: 700; letter-spacing: -.6px; }
        .brand img { width: 34px; height: 37px; object-fit: contain; }
        .brand span span { color: #49d5dd; }
        main { width: min(100% - 40px, 680px); margin: 64px auto 32px; }
        .error-card { background: var(--card); border: 1px solid var(--border); border-radius: 24px;
            box-shadow: 0 16px 56px rgba(5, 20, 45, .07); overflow: hidden; }
        .content { padding: 40px; }
        .icon { display: grid; place-items: center; width: 64px; height: 64px; border-radius: 20px;
            color: var(--accent); background: var(--soft); margin-bottom: 24px; }
        .icon svg { width: 34px; height: 34px; }
        .eyebrow { color: var(--accent); font-size: 12px; font-weight: 700; letter-spacing: 1.5px; margin: 0 0 10px; }
        h1 { font-size: clamp(25px, 4.5vw, 32px); line-height: 1.25; letter-spacing: -.8px; margin: 0 0 16px; }
        .message { color: var(--muted); margin: 0; font-size: 16px; }
        .next-step { background: var(--soft); border: 1px solid var(--border); border-radius: 14px;
            padding: 18px 20px; margin: 28px 0; }
        .next-step strong { font-size: 14px; }
        .next-step p { margin: 5px 0 0; font-size: 14px; color: var(--muted); }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .button { display: inline-flex; align-items: center; justify-content: center; min-height: 46px;
            padding: 11px 20px; border: 1px solid var(--border); border-radius: 10px; font: inherit;
            font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; color: var(--text); background: var(--card); }
        .button-primary { background: #31d2dd; border-color: #31d2dd; color: #05142d; }
        .button:hover { filter: brightness(.94); }
        :focus-visible { outline: 3px solid var(--accent); outline-offset: 4px; }
        .support { border-top: 1px solid var(--border); padding: 24px 40px; font-size: 13px; color: var(--muted); }
        .support p { margin: 0 0 12px; }
        .support-row { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
        .reference { font-size: 12px; }
        .reference code { color: var(--text); font-size: 12px; user-select: all; }
        .support-link { color: var(--accent); font-weight: 600; text-underline-offset: 3px; }
        .technical { margin-top: 24px; border: 1px solid var(--border); border-radius: 14px; background: var(--card); }
        .technical summary { padding: 18px 22px; cursor: pointer; font-size: 14px; font-weight: 600; }
        .admin-label { display: inline-block; margin-left: 8px; color: var(--muted); font-size: 11px; font-weight: 400; }
        .technical-body { padding: 0 22px 22px; font-size: 13px; overflow-wrap: anywhere; }
        .technical-body h2 { font-size: 13px; margin-top: 20px; }
        .technical-body pre { white-space: pre-wrap; overflow-wrap: anywhere; background: var(--soft);
            border-radius: 8px; padding: 14px; font-size: 12px; line-height: 1.6; }
        .technical-body dl { display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
        .technical-body dt { color: var(--muted); }
        .technical-body dd { margin: 0; min-width: 0; }
        .footer { margin: 24px 0 0; text-align: center; font-size: 12px; color: var(--muted); }
        @media (max-width: 520px) {
            .brand-bar { padding: 16px 20px; }
            main { width: calc(100% - 28px); margin-top: 28px; }
            .content { padding: 26px 22px; }
            .support { padding: 22px; }
            .actions { flex-direction: column; }
            .button { width: 100%; }
            .technical-body dl { grid-template-columns: 1fr; gap: 4px; }
            .technical-body dd { margin-bottom: 10px; }
        }
    </style>
</head>
<body>
<header class="brand-bar">
    <a class="brand" href="<?= $escape($homeUrl) ?>" aria-label="SpiderCode, ir al inicio">
        <?php if ($logo !== ''): ?><img src="<?= $escape($logo) ?>" alt="" width="34" height="37"><?php endif; ?>
        <span>Spider<span>Code</span></span>
    </a>
</header>
<main>
    <section class="error-card" aria-labelledby="error-title">
        <div class="content">
            <div class="icon" aria-hidden="true">
                <svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <?php if ($public['icon'] === 'search'): ?>
                        <circle cx="13" cy="13" r="8"/>
                        <path d="m19 19 8 8M10 13h6"/>
                    <?php elseif ($public['icon'] === 'lock'): ?>
                        <rect x="7" y="14" width="18" height="14" rx="3"/>
                        <path d="M11 14V9a5 5 0 0 1 10 0v5m-5 6v3"/>
                    <?php elseif ($public['icon'] === 'check'): ?>
                        <circle cx="16" cy="16" r="12"/>
                        <path d="m10 16 4 4 8-8"/>
                    <?php else: ?>
                        <path d="M10 24H8a6 6 0 0 1-.6-12A9 9 0 0 1 25 13a5.5 5.5 0 0 1-1 11h-2"/>
                        <path d="M14 17v7m5-7v7m-7-10 10 13"/>
                    <?php endif; ?>
                </svg>
            </div>
            <p class="eyebrow"><?= $escape($public['eyebrow']) ?></p>
            <h1 id="error-title"><?= $escape($public['title']) ?></h1>
            <p class="message"><?= $escape($public['message']) ?></p>
            <div class="next-step">
                <strong>Qué puedes hacer ahora</strong>
                <p><?= $escape($public['hint']) ?></p>
            </div>
            <nav class="actions" aria-label="Opciones para continuar">
                <a class="button button-primary" id="back-link" href="<?= $escape($homeUrl) ?>">Volver al inicio</a>
                <a class="button" href="<?= $escape($supportUrl) ?>" target="_blank" rel="noopener noreferrer">Contactar a soporte</a>
            </nav>
        </div>
        <div class="support">
            <p>Si el problema continúa, comparte esta referencia con tu administrador o con soporte.</p>
            <div class="support-row">
                <span class="reference">Referencia: <code><?= $escape($public['reference']) ?></code></span>
                <a class="support-link" href="<?= $escape($homeUrl) ?>">Ir al inicio</a>
            </div>
        </div>
    </section>
    <?php if ($admin): ?>
        <details class="technical">
            <summary>Detalles técnicos <span class="admin-label">Solo administradores</span></summary>
            <div class="technical-body">
                <h2>Mensaje y traza de ejecución</h2>
                <pre><?= $escape(html_entity_decode($info['message'] ?? '', ENT_QUOTES, 'UTF-8')) ?></pre>
                <dl>
                    <?php foreach (['exception' => 'Excepción', 'code' => 'Código', 'file' => 'Archivo', 'line' => 'Línea',
                        'url' => 'Ruta', 'hash' => 'Referencia completa', 'core_version' => 'FacturaScripts',
                        'plugin_list' => 'Plugins', 'php_version' => 'PHP', 'os' => 'Sistema'] as $key => $label): ?>
                        <?php if (isset($info[$key])): ?>
                            <dt><?= $escape($label) ?></dt><dd><?= $escape($info[$key]) ?></dd>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </dl>
            </div>
        </details>
    <?php endif; ?>
    <p class="footer">SpiderCode · Estamos para ayudarte</p>
</main>
<script>
    (function () {
        // Volver no recarga ni reenvía el formulario que produjo el error.
        var back = document.getElementById('back-link');
        try {
            var previous = new URL(document.referrer);
            if (previous.origin === location.origin && history.length > 1) {
                back.textContent = 'Volver a la pantalla anterior';
                back.addEventListener('click', function (event) {
                    event.preventDefault();
                    history.back();
                });
            }
        } catch (error) {}
    }());
</script>
</body>
</html>
