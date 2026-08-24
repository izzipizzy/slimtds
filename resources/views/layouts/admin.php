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
      // Restore theme + density before paint to avoid flash
      (function () {
        try {
          var t = localStorage.getItem('slimtds.theme');
          if (t === 'dark' || t === 'light') document.documentElement.setAttribute('data-theme', t);
          var d = localStorage.getItem('slimtds.density');
          if (d === 'compact' || d === 'comfortable') document.documentElement.setAttribute('data-density', d);
        } catch (_) {}
      })();
    </script>
</head>
<body class="app-shell" x-data="{ navOpen: false, theme: (document.documentElement.getAttribute('data-theme') || 'light'), density: (document.documentElement.getAttribute('data-density') || 'comfortable') }"
      style="display:grid;grid-template-columns:240px 1fr;grid-template-rows:1fr auto;grid-template-areas:'sidebar main' 'sidebar footer';min-height:100vh;background-color:var(--color-bg)">

    <!-- ── Mobile topbar ───────────────────────────────────────────────────── -->
    <header class="topbar-mobile" style="display:none;grid-area:main;position:sticky;top:0;z-index:30;background:var(--color-surface);border-bottom:1px solid var(--color-border);padding:10px 16px;align-items:center;justify-content:space-between;height:56px;margin-bottom:-56px">
        <a href="<?= e(url('/admin')) ?>" style="text-decoration:none">
            <?php $size=22; $variant='light'; $tagline=false; require __DIR__ . '/../_partials/wordmark.php'; ?>
        </a>
        <button @click="navOpen = true" aria-label="<?= e(t('a11y.open_nav')) ?>"
                style="background:transparent;border:1px solid var(--color-border);border-radius:4px;padding:6px 10px;cursor:pointer;color:var(--color-text);display:inline-flex;align-items:center;gap:6px;font-family:var(--font-sans);font-size:0.85rem">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            <span><?= e(t('a11y.menu')) ?></span>
        </button>
    </header>

    <!-- ── Sidebar ────────────────────────────────────────────────────────── -->
    <aside class="app-sidebar" :class="{ 'is-open': navOpen }"
           style="border-right:1px solid var(--color-border);background:var(--color-surface);display:flex;flex-direction:column">

        <!-- Brand -->
        <div style="padding:20px 16px 18px;border-bottom:1px solid var(--color-border-soft);display:flex;align-items:center;justify-content:space-between">
            <a href="<?= e(url('/admin')) ?>" style="text-decoration:none">
                <?php $size=22; $variant='light'; $tagline=true; require __DIR__ . '/../_partials/wordmark.php'; ?>
            </a>
            <button class="mobile-only" @click="navOpen = false" aria-label="<?= e(t('a11y.close_nav')) ?>"
                    style="background:transparent;border:0;cursor:pointer;color:var(--color-faint);padding:4px;display:none">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
            </button>
        </div>

        <!-- Nav -->
        <nav style="flex:1;padding:8px 0;overflow-y:auto" @click="navOpen = false">
            <?php
            $navItems = [
                ['/admin',              t('menu.dashboard'),   '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>'],
                ['/admin/campaigns',    t('menu.campaigns'),   '<path d="M5 4h11l3 4-3 4H5z"/><path d="M5 4v16"/>'],
                ['/admin/offers',       t('menu.offers'),      '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M3 11h18M9 7V4h6v3"/>'],
                ['/admin/clicks',       t('menu.clicks'),      '<path d="M9 4l3 13 2.5-5L20 9z"/><path d="M14 14l5 5"/>'],
                ['/admin/conversions',  t('menu.conversions'), '<path d="M3 17l5-5 4 4 8-8"/><path d="M14 8h6v6"/>'],
                ['/admin/pixel',        t('menu.pixel'),       '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>'],
                ['/admin/sessions',     t('nav.sessions'),     '<path d="M15 10l-4 4-4-4"/><rect x="3" y="3" width="18" height="18" rx="2"/>'],
                ['/admin/statistics',   t('menu.statistics'),  '<rect x="4" y="13" width="3" height="7"/><rect x="10.5" y="8" width="3" height="12"/><rect x="17" y="4" width="3" height="16"/>'],
                ['/admin/settings',     t('menu.settings') ?? 'Settings', '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/>'],
            ];
            foreach ($navItems as [$href, $label, $iconBody]):
            ?>
                <a href="<?= e(url($href)) ?>" class="nav-link <?= is_active($href) ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <?= $iconBody ?>
                    </svg>
                    <span><?= e($label) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <!-- Host stats (disk / RAM / load) — always visible under the menu -->
        <?php
        $stats = \App\Shared\System\SystemStats::snapshot();
        $gb = static fn (int $b): string => number_format($b / (1024 ** 3), 1);
        $bar = static function (int $pct): string {
            $c = $pct >= 90 ? 'var(--color-danger,#c0392b)' : ($pct >= 75 ? 'var(--color-terra-500,#b54f17)' : 'var(--color-terra-400,#cf7a4a)');
            return '<div style="height:4px;border-radius:2px;background:var(--color-stone-200,#e7e2da);overflow:hidden">'
                 . '<div style="height:100%;width:' . max(0, min(100, $pct)) . '%;background:' . $c . '"></div></div>';
        };
        ?>
        <div style="padding:12px 16px;border-top:1px solid var(--color-border-soft);display:flex;flex-direction:column;gap:8px;font-family:var(--font-mono);font-size:0.68rem;color:var(--color-faint)">
            <?php if ($stats['disk'] !== null): ?>
            <div title="Disk used / total">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px"><span>Disk</span><span><?= e($gb($stats['disk']['used'])) ?>/<?= e($gb($stats['disk']['total'])) ?>G · <?= (int)$stats['disk']['pct'] ?>%</span></div>
                <?= $bar((int)$stats['disk']['pct']) ?>
            </div>
            <?php endif; ?>
            <?php if ($stats['mem'] !== null): ?>
            <div title="RAM used / total">
                <div style="display:flex;justify-content:space-between;margin-bottom:3px"><span>RAM</span><span><?= e($gb($stats['mem']['used'])) ?>/<?= e($gb($stats['mem']['total'])) ?>G · <?= (int)$stats['mem']['pct'] ?>%</span></div>
                <?= $bar((int)$stats['mem']['pct']) ?>
            </div>
            <?php endif; ?>
            <?php if ($stats['load'] !== null): ?>
            <div style="display:flex;justify-content:space-between" title="Load average 1 / 5 / 15 min"><span>LA</span><span><?= e(number_format($stats['load'][0], 2)) ?> <?= e(number_format($stats['load'][1], 2)) ?> <?= e(number_format($stats['load'][2], 2)) ?></span></div>
            <?php endif; ?>
        </div>

        <!-- Sidebar bottom: lang + theme + logout -->
        <div style="padding:12px 16px;border-top:1px solid var(--color-border-soft);display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <a href="<?= e(url('/admin/lang/ru')) ?>"
               style="font-size:0.7rem;letter-spacing:0.06em;padding:2px 6px;border-radius:3px;text-decoration:none;border:1px solid <?= ($lang ?? 'ru') === 'ru' ? 'var(--color-terra-400)' : 'var(--color-border)' ?>;color:<?= ($lang ?? 'ru') === 'ru' ? 'var(--color-terra-500)' : 'var(--color-muted)' ?>;font-family:var(--font-sans)">RU</a>
            <a href="<?= e(url('/admin/lang/en')) ?>"
               style="font-size:0.7rem;letter-spacing:0.06em;padding:2px 6px;border-radius:3px;text-decoration:none;border:1px solid <?= ($lang ?? 'ru') === 'en' ? 'var(--color-terra-400)' : 'var(--color-border)' ?>;color:<?= ($lang ?? 'ru') === 'en' ? 'var(--color-terra-500)' : 'var(--color-muted)' ?>;font-family:var(--font-sans)">EN</a>

            <button @click="theme = theme === 'dark' ? 'light' : 'dark'; document.documentElement.setAttribute('data-theme', theme); localStorage.setItem('slimtds.theme', theme)"
                    aria-label="<?= e(t('a11y.toggle_theme')) ?>"
                    style="background:transparent;border:1px solid var(--color-border);border-radius:3px;padding:2px 6px;cursor:pointer;color:var(--color-muted);display:inline-flex;align-items:center;font-family:var(--font-sans);font-size:0.7rem;letter-spacing:0.06em">
                <span x-text="theme === 'dark' ? '☀ light' : '☾ dark'"></span>
            </button>

            <a href="<?= e(url('/admin/logout')) ?>"
               style="margin-left:auto;font-size:0.75rem;color:var(--color-faint);text-decoration:none;font-family:var(--font-sans)"><?= e(t('auth.logout')) ?></a>
        </div>
    </aside>

    <!-- Mobile backdrop -->
    <div x-show="navOpen" x-cloak @click="navOpen = false" class="nav-backdrop mobile-only" style="display:none"></div>

    <!-- ── Main ─────────────────────────────────────────────────────────── -->
    <div style="grid-area:main;min-width:0;display:flex;flex-direction:column">
        <main style="flex:1;padding:32px 32px 40px;min-width:0">
            <?= $__content__ ?>
        </main>
    </div>

    <!-- ── Footer ─────────────────────────────────────────────────────────── -->
    <footer style="grid-area:footer;border-top:1px solid var(--color-border-soft);padding:10px 32px;font-size:0.7rem;color:var(--color-faint);font-family:var(--font-sans);display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <span style="display:inline-flex;align-items:center;gap:6px">
            <span class="brand-tick"></span>
            slim<span style="color:var(--color-terra-500)">/</span>TDS
        </span>
        <?php require __DIR__ . '/../_partials/version-chip.php'; ?>
        <span style="font-family:var(--font-mono);color:var(--color-faintest);text-transform:uppercase;letter-spacing:0.08em"><?= e(strtolower((string)($_ENV['APP_ENV'] ?? 'dev'))) ?></span>

        <!-- Density toggle -->
        <button type="button"
                @click="density = density === 'compact' ? 'comfortable' : 'compact'; document.documentElement.setAttribute('data-density', density); localStorage.setItem('slimtds.density', density)"
                aria-label="<?= e(t('a11y.toggle_density')) ?>"
                style="background:transparent;border:1px solid var(--color-border);border-radius:3px;padding:2px 8px;cursor:pointer;color:var(--color-muted);font-family:var(--font-sans);font-size:0.7rem;letter-spacing:0.04em">
            <span x-text="density === 'compact' ? '⊟ compact' : '⊞ comfy'"></span>
        </button>

        <!-- Cmd+K hint -->
        <span style="display:inline-flex;align-items:center;gap:6px;color:var(--color-muted)">
            <span class="kbd">⌘ K</span>
            <span style="font-family:var(--font-sans);font-size:0.7rem"><?= e(t('cmdk.hint') ?? 'search') ?></span>
        </span>

        <!-- Author links -->
        <a href="https://t.me/izzypizzy_seo" target="_blank" rel="noopener"
           style="text-decoration:none;color:var(--color-faint);font-family:var(--font-sans);font-size:0.7rem;letter-spacing:0.04em">telegram</a>
        <a href="https://izzyypizzy.com/" target="_blank" rel="noopener"
           style="text-decoration:none;color:var(--color-faint);font-family:var(--font-sans);font-size:0.7rem;letter-spacing:0.04em">izzyypizzy.com</a>

        <span style="margin-left:auto;font-family:var(--font-mono);color:var(--color-faintest)" title="Page render time"><?= number_format((microtime(true) - (defined('APP_START') ? APP_START : microtime(true))) * 1000, 1) ?>ms</span>
    </footer>

    <!-- ── Toast notifications (auto-converts server flash) ──────────────── -->
    <?php require __DIR__ . '/../_partials/toast-host.php'; ?>

    <!-- ── Cmd+K palette ─────────────────────────────────────────────────── -->
    <?php
    $cmdkItems = [
        ['label' => t('menu.dashboard'),   'href' => url('/admin'),              'group' => 'Pages',   'meta' => ''],
        ['label' => t('menu.campaigns'),   'href' => url('/admin/campaigns'),    'group' => 'Pages',   'meta' => ''],
        ['label' => 'New campaign',        'href' => url('/admin/campaigns/new'),'group' => 'Actions', 'meta' => ''],
        ['label' => t('menu.offers'),      'href' => url('/admin/offers'),       'group' => 'Pages',   'meta' => ''],
        ['label' => 'New offer',           'href' => url('/admin/offers/new'),   'group' => 'Actions', 'meta' => ''],
        ['label' => t('menu.clicks'),      'href' => url('/admin/clicks'),       'group' => 'Pages',   'meta' => ''],
        ['label' => t('menu.conversions'), 'href' => url('/admin/conversions'),  'group' => 'Pages',   'meta' => ''],
        ['label' => 'Pixel events',        'href' => url('/admin/pixel'),        'group' => 'Pages',   'meta' => ''],
        ['label' => t('nav.sessions'),     'href' => url('/admin/sessions'),     'group' => 'Pages',   'meta' => ''],
        ['label' => t('menu.statistics'),  'href' => url('/admin/statistics'),   'group' => 'Pages',   'meta' => ''],
        ['label' => t('menu.settings'),    'href' => url('/admin/settings'),     'group' => 'Pages',   'meta' => ''],
        ['label' => t('auth.logout'),      'href' => url('/admin/logout'),       'group' => 'Account', 'meta' => ''],
    ];
    require __DIR__ . '/../_partials/cmdk.php';
    ?>

</body>
</html>
