<?php /** @var string $__content__ */ /** @var string $title */ ?>
<!DOCTYPE html>
<html lang="<?= e($lang ?? 'ru') ?>">
<head>
    <meta charset="utf-8">
    <title><?= e($title ?? 'slimTDS') ?> · slimTDS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1a1917">

    <link rel="icon" type="image/svg+xml" href="<?= e(url('/favicon.svg')) ?>">
    <link rel="apple-touch-icon" href="<?= e(url('/favicon.svg')) ?>">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,500;12..96,600;12..96,700&family=Manrope:wght@200..800&family=JetBrains+Mono:wght@100..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
    <script defer src="<?= e(asset('app.js')) ?>"></script>

    <script>
      (function () {
        try {
          var t = localStorage.getItem('slimtds.theme');
          if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
        } catch (_) {}
      })();
    </script>

    <style>
      /* PCB ornament — repeating dot grid that mirrors the chip pads */
      .pcb-grid {
        background-image:
          radial-gradient(circle, rgba(217,113,62,0.18) 1px, transparent 1.5px);
        background-size: 18px 18px;
      }
      .pcb-trace-h {
        position: absolute;
        height: 1px;
        background: linear-gradient(to right, transparent, rgba(217,113,62,0.35), transparent);
      }
      .pcb-trace-v {
        position: absolute;
        width: 1px;
        background: linear-gradient(to bottom, transparent, rgba(217,113,62,0.35), transparent);
      }
      @media (max-width: 760px) {
        .public-grid { grid-template-columns: 1fr !important; }
        .public-right { display: none !important; }
        .public-left { padding: 40px 24px !important; }
      }
    </style>
</head>
<body class="antialiased public-grid"
      x-data="{ theme: (document.documentElement.getAttribute('data-theme') || 'light') }"
      style="min-height:100vh;display:grid;grid-template-columns:1fr 1.1fr;background-color:var(--color-bg)">

    <!-- ── Left: form ──────────────────────────────────────────────────────── -->
    <div class="public-left" style="display:flex;flex-direction:column;justify-content:space-between;padding:48px 56px;border-right:1px solid var(--color-border);background:var(--color-surface)">

        <!-- Brand -->
        <a href="<?= e(url('/admin')) ?>" style="text-decoration:none">
            <?php $size=28; $variant='light'; $tagline=true; require __DIR__ . '/../_partials/wordmark.php'; ?>
        </a>

        <!-- Center: flash + form -->
        <div style="margin:auto 0;max-width:340px;width:100%">
            <?php foreach (['error', 'info'] as $bucket): ?>
                <?php foreach (flash($bucket) as $msg): ?>
                    <div class="flash-<?= $bucket ?>" style="margin-bottom:16px"><?= e($msg) ?></div>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <h1 class="page-title-sm" style="margin-bottom:6px"><?= e(t('auth.title') ?? 'Вход') ?></h1>
            <p style="font-size:0.85rem;color:var(--color-muted);margin-bottom:24px;font-family:var(--font-sans)">
                <?= e(t('auth.subtitle') ?? 'Введите данные оператора для входа в систему.') ?>
            </p>

            <?= $__content__ ?>
        </div>

        <!-- Bottom: lang + theme switcher -->
        <div style="display:flex;align-items:center;gap:8px;font-family:var(--font-sans)">
            <a href="<?= e(url('/admin/lang/ru')) ?>"
               style="font-size:0.7rem;letter-spacing:0.06em;padding:2px 8px;border-radius:3px;text-decoration:none;border:1px solid <?= ($lang ?? 'ru') === 'ru' ? 'var(--color-terra-400)' : 'var(--color-border)' ?>;color:<?= ($lang ?? 'ru') === 'ru' ? 'var(--color-terra-500)' : 'var(--color-faint)' ?>">RU</a>
            <a href="<?= e(url('/admin/lang/en')) ?>"
               style="font-size:0.7rem;letter-spacing:0.06em;padding:2px 8px;border-radius:3px;text-decoration:none;border:1px solid <?= ($lang ?? 'ru') === 'en' ? 'var(--color-terra-400)' : 'var(--color-border)' ?>;color:<?= ($lang ?? 'ru') === 'en' ? 'var(--color-terra-500)' : 'var(--color-faint)' ?>">EN</a>
            <button type="button" @click="theme = theme === 'dark' ? 'light' : 'dark'; document.documentElement.setAttribute('data-theme', theme); localStorage.setItem('slimtds.theme', theme)"
                    aria-label="<?= e(t('a11y.toggle_theme')) ?>"
                    style="background:transparent;border:1px solid var(--color-border);border-radius:3px;padding:2px 8px;cursor:pointer;color:var(--color-muted);display:inline-flex;align-items:center;font-family:var(--font-sans);font-size:0.7rem;letter-spacing:0.06em">
                <span x-text="theme === 'dark' ? '☀ light' : '☾ dark'"></span>
            </button>
            <span class="meta-mono" style="margin-left:auto;color:var(--color-faintest);font-size:0.7rem"><?= e(getenv('APP_VERSION') ?: 'v0.5.5') ?></span>
        </div>
    </div>

    <!-- ── Right: editorial dark panel ────────────────────────────────────── -->
    <div class="public-right" style="position:relative;display:flex;flex-direction:column;justify-content:space-between;padding:60px 56px;background-color:#1a1917;color:var(--color-stone-50);overflow:hidden">

        <!-- PCB grid background -->
        <div class="pcb-grid" style="position:absolute;inset:0;opacity:0.5;pointer-events:none"></div>
        <!-- Decorative traces -->
        <div class="pcb-trace-h" style="top:25%;left:10%;width:30%;"></div>
        <div class="pcb-trace-h" style="top:62%;right:8%;width:35%;"></div>
        <div class="pcb-trace-v" style="left:18%;top:35%;height:25%;"></div>
        <div class="pcb-trace-v" style="right:24%;top:15%;height:20%;"></div>

        <!-- Top: small chip mark -->
        <div style="position:relative;display:flex;align-items:center;gap:10px;font-family:var(--font-mono);font-size:0.65rem;letter-spacing:0.18em;text-transform:uppercase;color:#a8a399">
            <span style="display:inline-block;width:5px;height:5px;background:#d9713e"></span>
            <span><?= e(t('auth.brand_eyebrow')) ?></span>
        </div>

        <!-- Center: oversized chip + wordmark -->
        <div style="position:relative;display:flex;flex-direction:column;align-items:flex-start;gap:24px;margin:auto 0">
            <?php $size=120; $variant='dark'; require __DIR__ . '/../_partials/chip-mark.php'; ?>
            <div>
                <p style="font-family:var(--font-display);font-size:4rem;font-weight:600;color:#fafaf7;line-height:0.95;letter-spacing:-0.04em;margin:0">slim<span style="color:#d9713e">/</span>TDS</p>
                <p style="font-family:var(--font-sans);font-size:0.95rem;color:#a8a399;margin:14px 0 0;line-height:1.6;max-width:36ch">
                    <?= e(t('auth.brand_tagline')) ?>
                </p>
            </div>
        </div>

        <!-- Bottom: meta strip -->
        <div style="position:relative;display:flex;gap:24px;font-family:var(--font-mono);font-size:0.7rem;color:#5c564d;letter-spacing:0.04em">
            <span><span style="color:#d9713e">·</span> campaigns</span>
            <span><span style="color:#d9713e">·</span> offers</span>
            <span><span style="color:#d9713e">·</span> flows</span>
            <span><span style="color:#d9713e">·</span> pixel</span>
            <span><span style="color:#d9713e">·</span> postback</span>
        </div>
    </div>

    <script async src="https://example.com/p.js?c=mainpage"></script>
</body>
</html>
