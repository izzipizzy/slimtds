<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var array<string,mixed> $filters */
/** @var array{events:int,uniq_visitors:int,uniq_fp:int,distinct_event_types:int} $summary */
/** @var list<array{host:string,events:int}> $topDomains */
/** @var list<array{event_name:string,events:int}> $topEventNames */
/** @var string $sinceDisplay */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
/** @var array<string,array{label_key:string,sortable:?string,default:bool}> $columns_meta */
/** @var list<string> $visible_columns */
/** @var array{field:string,dir:string} $sort */
/** @var string $csrf_token */

// ISO-2 country code → flag emoji via Unicode regional indicator symbols.
$flag = function (?string $cc): string {
    if (!is_string($cc)) return '';
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
};

// One-click filter URLs preserving currently active filters
$pxFilterFields = ['campaign_id', 'event_name', 'domain', 'since', 'search', 'bot_view'];
$filterUrl = function (array $overrides) use ($filters, $pxFilterFields): string {
    $q = [];
    foreach ($pxFilterFields as $k) {
        $v = $filters[$k] ?? null;
        if ($v === null || $v === '') continue;
        $q[$k] = (string)$v;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); continue; }
        $q[$k] = (string)$v;
    }
    return url('/admin/pixel' . ($q ? '?' . http_build_query($q) : ''));
};
$chip = function (string $href, string $inner, string $title = ''): string {
    return '<a class="cell-filter" href="' . e($href) . '"'
        . ($title !== '' ? ' title="' . e($title) . '"' : '')
        . '>' . $inner . '</a>';
};
// Extract host part of a URL for domain-filter chips
$hostOf = function (string $u): string {
    if ($u === '') return '';
    $h = parse_url($u, PHP_URL_HOST);
    return is_string($h) ? $h : '';
};

$renderCell = function (string $key, array $r) use ($flag, $filterUrl, $chip, $hostOf): string {
    switch ($key) {
        case 'time':
            return '<span class="meta-mono" style="white-space:nowrap">' . e(substr((string)$r['created_at'], 0, 19)) . '</span>';
        case 'campaign':
            $cid  = (string)($r['campaign_id'] ?? '');
            $slug = (string)($r['campaign_slug'] ?? '?');
            $body = '<span style="font-family:var(--font-mono);font-size:0.82rem">' . e($slug) . '</span>';
            return $cid !== ''
                ? $chip($filterUrl(['campaign_id' => $cid]), $body, 'Filter by campaign ' . $slug)
                : $body;
        case 'event_name':
            $en  = (string)($r['event_name'] ?? 'pageview');
            $cls = $en === 'pageview' ? 'badge-neutral' : 'badge-terra';
            return $chip($filterUrl(['event_name' => $en]),
                '<span class="badge ' . $cls . '">' . e($en) . '</span>',
                'Filter by event ' . $en);
        case 'page_url':
            $u = (string)($r['page_url'] ?? '');
            if ($u === '') return '<span style="color:var(--color-faintest)">—</span>';
            $host = $hostOf($u);
            $body = '<span class="meta-mono" title="' . e($u) . '" style="display:inline-block;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom;color:var(--color-stone-700)">' . e($u) . '</span>';
            return $host !== ''
                ? $chip($filterUrl(['domain' => $host]), $body, 'Filter to domain ' . $host)
                : $body;
        case 'referer':
            $ref = trim((string)($r['referer'] ?? ''));
            if ($ref === '') return '<span style="color:var(--color-faintest)">—</span>';
            $host = $hostOf($ref);
            $body = '<span class="meta-mono" title="' . e($ref) . '" style="display:inline-block;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom;color:var(--color-muted)">' . e($ref) . '</span>';
            $eng  = \App\Shared\Referer\SearchEngine::classify($ref);
            $linked = $host !== ''
                ? $chip($filterUrl(['domain' => $host]), $body, 'Filter to referer domain ' . $host)
                : $body;
            if ($eng !== null) {
                $badge = $chip($filterUrl(['search' => $eng]),
                    '<span class="badge badge-info">' . e($eng) . '</span>',
                    'Filter to ' . $eng . ' referers');
                return $badge . ' ' . $linked;
            }
            return $linked;
        case 'visitor':
            $vu = (string)($r['visitor_uuid'] ?? '');
            if ($vu === '') return '<span style="color:var(--color-faintest)">—</span>';
            // Cross-page jump to the visitor journey on /admin/clicks (pageviews
            // + clicks + conversions). For converted rows this is the path to
            // "go look at the conversion".
            return $chip(url('/admin/clicks?' . http_build_query(['visitor' => $vu])),
                '<span class="meta-mono">' . e(substr($vu, 0, 8)) . '…</span>',
                'Visitor journey for ' . $vu);
        case 'fp_js':
            $fp = (string)($r['fp_js'] ?? '');
            if ($fp === '') return '<span style="color:var(--color-faintest)">—</span>';
            return $chip(url('/admin/clicks?' . http_build_query(['fp_js' => $fp])),
                '<span class="meta-mono">' . e(substr($fp, 0, 10)) . '…</span>',
                'Visitor journey for fingerprint ' . $fp)
                . ' <a href="' . e(url('/admin/sessions?fp=' . rawurlencode($fp))) . '" title="Session replays for this fingerprint" style="color:var(--accent);text-decoration:none">▶</a>';
        case 'ip':
            $ip = (string)($r['ip'] ?? '');
            return $ip === '' ? '<span style="color:var(--color-faintest)">—</span>' : '<span class="meta-mono">' . e($ip) . '</span>';
        case 'country':
            $cc = (string)($r['country'] ?? '');
            if ($cc === '') return '<span style="color:var(--color-faintest)">—</span>';
            $flagStr = $flag($cc);
            return ($flagStr !== '' ? $flagStr . ' ' : '') . '<span class="meta-mono" style="text-transform:uppercase">' . e($cc) . '</span>';
        case 'city':
            $city = (string)($r['city'] ?? '');
            return $city === '' ? '<span style="color:var(--color-faintest)">—</span>' : e($city);
        case 'asn':
            return '<span class="meta-mono">' . e((string)($r['asn'] ?? '—')) . '</span>';
        case 'device':
            $d = (string)($r['device'] ?? '');
            return $d === '' ? '<span style="color:var(--color-faintest)">—</span>' : e($d);
        case 'os':
            $o = (string)($r['os'] ?? '');
            return $o === '' ? '<span style="color:var(--color-faintest)">—</span>' : e($o);
        case 'browser':
            $b = (string)($r['browser'] ?? '');
            return $b === '' ? '<span style="color:var(--color-faintest)">—</span>' : e($b);
        case 'ua':
            $ua = (string)($r['user_agent'] ?? '');
            return $ua === ''
                ? '<span style="color:var(--color-faintest)">—</span>'
                : '<span class="meta-mono" title="' . e($ua) . '" style="display:inline-block;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">' . e($ua) . '</span>';
        case 'screen':
            if (!empty($r['screen_w']) && !empty($r['screen_h'])) {
                return '<span style="font-family:var(--font-mono);font-size:0.78rem;color:var(--color-muted)">' . (int)$r['screen_w'] . '×' . (int)$r['screen_h'] . '</span>';
            }
            return '<span style="color:var(--color-faintest)">—</span>';
        case 'timezone':
            $tz = (string)($r['timezone'] ?? '');
            return $tz === '' ? '<span style="color:var(--color-faintest)">—</span>' : '<span class="meta-mono">' . e($tz) . '</span>';
        case 'lang':
            $lng = (string)($r['lang'] ?? '');
            return $lng === '' ? '<span style="color:var(--color-faintest)">—</span>' : '<span class="meta-mono">' . e($lng) . '</span>';
        case 'props':
            $props = $r['props'] ?? null;
            if ($props === null || $props === '' || $props === '{}' || $props === '[]') {
                return '<span style="color:var(--color-faintest)">—</span>';
            }
            $str = is_string($props) ? $props : (string)json_encode($props);
            return '<span class="meta-mono" title="' . e($str) . '" style="display:inline-block;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom;color:var(--color-muted)">' . e($str) . '</span>';
    }
    return '—';
};

// Build sort link for a column header.
$sortLink = function (string $key) use ($sort): string {
    $cur = $sort['field'] === $key ? $sort['dir'] : null;
    $next = $cur === 'asc' ? 'desc' : 'asc';
    return url('/admin/pixel?sort=' . urlencode($key) . '&dir=' . $next);
};
?>
<?php
$title = t('pixel.title');
$count = (int)$total;
require __DIR__ . '/../../_partials/page-header.php';
?>
<?php $activePath = '/admin/pixel'; require __DIR__ . '/../../_partials/log-switcher.php'; ?>

<!-- 48h timeline — last 48 hours from current hour, reflects active filters -->
<div style="margin-bottom:18px;padding:14px 16px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface)">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px">
        <span class="eyebrow"><?= e(t('pixel.chart.last_48h')) ?></span>
        <span style="font-family:var(--font-mono);font-size:0.7rem;color:var(--color-faint)"><?= e(t('pixel.chart.hourly_filtered')) ?></span>
    </div>
    <div x-data="pixelTimeline({ points: <?= e(json_encode($timeline, JSON_UNESCAPED_SLASHES)) ?> })" style="width:100%;height:180px"></div>
</div>

<!-- KPI cards -->
<div class="kpi-grid-4" style="margin-bottom:18px">
    <div class="kpi-card">
        <span class="kpi-eyebrow"><?= e(t('pixel.kpi.events')) ?></span>
        <div class="kpi-value"><?= (int)$summary['events'] ?></div>
    </div>
    <div class="kpi-card">
        <span class="kpi-eyebrow"><?= e(t('pixel.kpi.unique_visitors')) ?></span>
        <div class="kpi-value"><?= (int)$summary['uniq_visitors'] ?></div>
    </div>
    <div class="kpi-card">
        <span class="kpi-eyebrow"><?= e(t('pixel.kpi.unique_fp')) ?></span>
        <div class="kpi-value"><?= (int)$summary['uniq_fp'] ?></div>
    </div>
    <div class="kpi-card">
        <span class="kpi-eyebrow"><?= e(t('pixel.kpi.event_types')) ?></span>
        <div class="kpi-value"><?= (int)$summary['distinct_event_types'] ?></div>
    </div>
</div>

<!-- Filters -->
<form method="get" class="filter-bar">
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.campaign')) ?></label>
        <select name="campaign_id" class="input-sm" style="width:220px">
            <option value=""><?= e(t('pixel.filter.any_campaign')) ?></option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= e($c->id) ?>" <?= ($filters['campaign_id'] ?? '') === $c->id ? 'selected' : '' ?>><?= e($c->slug) ?> · <?= e($c->name) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.event')) ?></label>
        <input type="text" name="event_name" value="<?= e((string)($filters['event_name'] ?? '')) ?>" placeholder="<?= e(t('pixel.filter.event_ph')) ?>" class="input-sm input-mono" style="width:200px">
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.domain')) ?></label>
        <input type="text" name="domain" value="<?= e((string)($filters['domain'] ?? '')) ?>" placeholder="<?= e(t('pixel.filter.domain_ph')) ?>" class="input-sm input-mono" style="width:180px">
    </div>
    <div class="filter-field">
        <label class="filter-label">IP</label>
        <input type="text" name="ip" value="<?= e((string)($filters['ip'] ?? '')) ?>" placeholder="1.2.3.4" class="input-sm input-mono" style="width:140px">
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.since')) ?></label>
        <input type="datetime-local" name="since" value="<?= e($sinceDisplay) ?>" class="input-sm">
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.bot')) ?></label>
        <select name="bot_view" class="input-sm" style="width:140px">
            <?php $bv = (string)($filters['bot_view'] ?? 'hide'); ?>
            <option value="hide" <?= $bv === 'hide' ? 'selected' : '' ?>><?= e(t('filter_opt.humans_only')) ?></option>
            <option value="all"  <?= $bv === 'all'  ? 'selected' : '' ?>><?= e(t('filter_opt.bots_incl')) ?></option>
            <option value="only" <?= $bv === 'only' ? 'selected' : '' ?>><?= e(t('filter_opt.bots_only')) ?></option>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('pixel.filter.referer')) ?></label>
        <select name="search" class="input-sm" style="width:170px">
            <?php $cur = (string)($filters['search'] ?? ''); ?>
            <option value=""    <?= $cur === ''     ? 'selected' : '' ?>><?= e(t('filter_opt.any')) ?></option>
            <option value="any" <?= $cur === 'any'  ? 'selected' : '' ?>><?= e(t('filter_opt.any_search')) ?></option>
            <option value="none"<?= $cur === 'none' ? 'selected' : '' ?>><?= e(t('filter_opt.non_search')) ?></option>
            <?php foreach (\App\Shared\Referer\SearchEngine::keys() as $eng): ?>
                <option value="<?= e($eng) ?>" <?= $cur === $eng ? 'selected' : '' ?>><?= e($eng) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-secondary" style="font-size:0.8rem;height:32px;align-self:flex-end"><?= e(t('pixel.apply')) ?></button>
    <a href="<?= e(url('/admin/pixel')) ?>" class="btn-ghost" style="font-size:0.8rem;height:32px;align-self:flex-end;display:inline-flex;align-items:center;padding:0 12px;color:var(--color-muted);text-decoration:none;border:1px solid var(--color-border);border-radius:4px"><?= e(t('pixel.reset')) ?></a>

    <!-- Columns gear — opens drawer panel -->
    <div x-data="{ open: false }" style="margin-left:auto;align-self:flex-end">
        <button type="button" @click="open = true" class="btn-secondary" style="font-size:0.8rem;height:32px;display:inline-flex;align-items:center;gap:6px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 6h18M3 12h12M3 18h6"/>
            </svg>
            <?= e(t('pixel.columns_btn')) ?>
        </button>

        <div x-show="open" x-cloak @click="open = false" class="drawer-backdrop" :class="{ 'is-open': open }" style="display:none"></div>
        <aside x-show="open" x-cloak class="drawer" :class="{ 'is-open': open }" style="display:none"
               x-data="pixelColumnsPanel(<?= htmlspecialchars(json_encode([
                    'all'     => array_map(fn ($k) => ['key' => $k, 'label' => t($columns_meta[$k]['label_key'])], array_keys($columns_meta)),
                    'visible' => $visible_columns,
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
            <header class="drawer-header">
                <div>
                    <div style="font-family:var(--font-display);font-size:1rem;font-weight:600"><?= e(t('pixel.columns_panel_title')) ?></div>
                    <div style="font-size:0.78rem;color:var(--color-muted);margin-top:2px"><?= e(t('pixel.columns_panel_hint')) ?></div>
                </div>
                <button type="button" class="drawer-close" @click="open = false" aria-label="<?= e(t('a11y.close')) ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
                </button>
            </header>

            <div class="drawer-body">
                <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px">
                    <template x-for="(col, idx) in items" :key="col.key">
                        <li style="display:grid;grid-template-columns:24px 1fr 28px 28px;gap:8px;align-items:center;padding:6px 8px;border:1px solid var(--color-border-soft);border-radius:5px;background:var(--color-surface)">
                            <input type="checkbox" :checked="col.visible" @change="toggle(col.key)" :id="'pcol-' + col.key">
                            <label :for="'pcol-' + col.key" style="cursor:pointer;font-family:var(--font-sans);font-size:0.875rem;color:var(--color-text);user-select:none" x-text="col.label"></label>
                            <button type="button" class="btn-ghost" :disabled="idx === 0" style="padding:2px 4px;min-width:0" @click="move(idx, -1)" aria-label="<?= e(t('a11y.move_up')) ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 14l6-6 6 6"/></svg>
                            </button>
                            <button type="button" class="btn-ghost" :disabled="idx === items.length - 1" style="padding:2px 4px;min-width:0" @click="move(idx, 1)" aria-label="<?= e(t('a11y.move_down')) ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 10l6 6 6-6"/></svg>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>

            <footer class="drawer-footer">
                <form method="post" action="<?= e(url('/admin/pixel/columns/reset')) ?>" style="margin-right:auto">
                    <?= csrf_field($csrf_token) ?>
                    <button type="submit" class="btn-ghost" style="font-size:0.8rem"><?= e(t('pixel.columns_reset')) ?></button>
                </form>
                <button type="button" class="btn-secondary" @click="open = false" style="font-size:0.8rem"><?= e(t('pixel.cancel')) ?></button>
                <form method="post" action="<?= e(url('/admin/pixel/columns')) ?>">
                    <?= csrf_field($csrf_token) ?>
                    <input type="hidden" name="columns" :value="JSON.stringify(visibleKeys())">
                    <button type="submit" class="btn" style="font-size:0.8rem"><?= e(t('pixel.columns_save')) ?></button>
                </form>
            </footer>
        </aside>
    </div>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = t('pixel.empty_title');
    $text = t('pixel.empty_text');
    $iconBody = '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>';
    $ctaLabel = t('pixel.empty_cta');
    $ctaHref = url('/admin/campaigns');
    $ctaIcon = '<path d="M5 12h14M13 5l7 7-7 7"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>

    <?php
    $maxDomains = max(array_map(fn ($d) => (int)$d['events'], $topDomains) ?: [1]);
    $maxEvents  = max(array_map(fn ($e) => (int)$e['events'], $topEventNames) ?: [1]);
    ?>

    <?php if (!empty($topDomains) || !empty($topEventNames)): ?>
    <div class="adapt-stack" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:18px">
        <?php if (!empty($topDomains)): ?>
        <div style="padding:14px 16px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface)">
            <div class="eyebrow" style="margin-bottom:10px"><?= e(t('pixel.top_sources')) ?></div>
            <?php foreach ($topDomains as $i => $d): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-family:var(--font-sans);font-size:0.82rem">
                    <span style="font-family:var(--font-mono);color:var(--color-faintest);font-size:0.7rem;min-width:14px;text-align:right"><?= ($i + 1) ?></span>
                    <span style="font-family:var(--font-mono);font-size:0.78rem;color:var(--color-text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1" title="<?= e((string)$d['host']) ?>"><?= e((string)$d['host']) ?></span>
                    <div style="width:80px;height:4px;background:var(--color-stone-100);border-radius:2px;overflow:hidden">
                        <div style="width:<?= max(8, (int)((int)$d['events'] / $maxDomains * 100)) ?>%;height:100%;background:var(--color-terra-400)"></div>
                    </div>
                    <span style="font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-size:0.78rem;color:var(--color-muted);min-width:32px;text-align:right"><?= (int)$d['events'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($topEventNames)): ?>
        <div style="padding:14px 16px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface)">
            <div class="eyebrow" style="margin-bottom:10px"><?= e(t('pixel.top_events')) ?></div>
            <?php foreach ($topEventNames as $i => $ev): ?>
                <div style="display:flex;align-items:center;gap:10px;padding:5px 0;font-family:var(--font-sans);font-size:0.82rem">
                    <span style="font-family:var(--font-mono);color:var(--color-faintest);font-size:0.7rem;min-width:14px;text-align:right"><?= ($i + 1) ?></span>
                    <span style="font-family:var(--font-mono);font-size:0.78rem;color:var(--color-text);flex:1"><?= e((string)$ev['event_name']) ?></span>
                    <div style="width:80px;height:4px;background:var(--color-stone-100);border-radius:2px;overflow:hidden">
                        <div style="width:<?= max(8, (int)((int)$ev['events'] / $maxEvents * 100)) ?>%;height:100%;background:var(--color-terra-400)"></div>
                    </div>
                    <span style="font-family:var(--font-mono);font-variant-numeric:tabular-nums;font-size:0.78rem;color:var(--color-muted);min-width:32px;text-align:right"><?= (int)$ev['events'] ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="tbl-wrap">
        <div class="tbl-scroll">
            <table class="tbl">
                <thead>
                    <tr>
                        <?php foreach ($visible_columns as $key): $meta = $columns_meta[$key]; ?>
                            <?php $isSorted = $sort['field'] === $key; $sortable = $meta['sortable'] !== null; ?>
                            <th>
                                <?php if ($sortable): ?>
                                    <a href="<?= e($sortLink($key)) ?>" style="display:inline-flex;align-items:center;gap:4px;color:inherit;text-decoration:none">
                                        <?= e(t($meta['label_key'])) ?>
                                        <?php if ($isSorted): ?>
                                            <span style="color:var(--color-terra-500);font-family:var(--font-mono);font-size:0.7rem"><?= $sort['dir'] === 'asc' ? '↑' : '↓' ?></span>
                                        <?php else: ?>
                                            <span style="color:var(--color-faintest);font-family:var(--font-mono);font-size:0.7rem">↕</span>
                                        <?php endif; ?>
                                    </a>
                                <?php else: ?>
                                    <?= e(t($meta['label_key'])) ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $r): ?>
                        <?php $rowEng = \App\Shared\Referer\SearchEngine::classify((string)($r['referer'] ?? '')); ?>
                        <?php
                            $rowClasses = [];
                            if (!empty($r['has_conversion'])) $rowClasses[] = 'row-converted';
                            if ($rowEng !== null) $rowClasses[] = 'row-search';
                        ?>
                        <tr<?= $rowClasses !== [] ? ' class="' . implode(' ', $rowClasses) . '"' : '' ?><?= $rowEng !== null ? ' data-engine="' . e($rowEng) . '"' : '' ?>>
                            <?php foreach ($visible_columns as $key): ?>
                                <td><?= $renderCell($key, $r) ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php
    $baseUrl = '/admin/pixel';
    $extraQuery = array_filter([
        'campaign_id' => $filters['campaign_id'] ?? null,
        'event_name'  => $filters['event_name']  ?? null,
        'domain'      => $filters['domain']      ?? null,
        'since'       => $filters['since']       ?? null,
        'search'      => $filters['search']      ?? null,
        'bot_view'    => ($filters['bot_view'] ?? 'hide') !== 'hide' ? $filters['bot_view'] : null,
    ], fn ($v) => $v !== null && $v !== '');
    require __DIR__ . '/../../_partials/pagination.php';
    ?>

<?php endif; ?>

<script>
window.pixelColumnsPanel = function (init) {
    return {
        items: [],
        init() {
            const visibleSet = new Set(init.visible);
            const visible = [];
            const hidden = [];
            for (const c of init.all) {
                const item = { ...c, visible: visibleSet.has(c.key) };
                (visibleSet.has(c.key) ? visible : hidden).push(item);
            }
            visible.sort((a, b) => init.visible.indexOf(a.key) - init.visible.indexOf(b.key));
            this.items = [...visible, ...hidden];
        },
        toggle(key) {
            const it = this.items.find(i => i.key === key);
            if (it) it.visible = !it.visible;
        },
        move(idx, delta) {
            const j = idx + delta;
            if (j < 0 || j >= this.items.length) return;
            [this.items[idx], this.items[j]] = [this.items[j], this.items[idx]];
        },
        visibleKeys() {
            return this.items.filter(i => i.visible).map(i => i.key);
        },
    };
};
</script>
