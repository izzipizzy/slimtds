<?php
/** @var list<array<string,mixed>> $items */
/** @var int $total */
/** @var int $pages */
/** @var int $page */
/** @var array<string,mixed> $filters */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
/** @var array<string,array{label_key:string,sortable:?string,default:bool}> $columns_meta */
/** @var list<string> $visible_columns */
/** @var array{field:string,dir:string} $sort */
/** @var string $csrf_token */

// ISO-2 country code → flag emoji via Unicode regional indicator symbols.
// e.g. 'fr' → 🇫🇷, 'ru' → 🇷🇺. Empty string when input is invalid.
$flag = function (?string $cc): string {
    if (!is_string($cc)) return '';
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
};

// Build a /admin/clicks URL that preserves currently active filters but
// applies the given override(s). Used to make every cell value a one-click
// shortcut to filter the list to that value.
$filterFields = ['campaign_id', 'country', 'device', 'bot_view', 'is_uniq', 'is_trash', 'since', 'search', 'fp_js', 'fp_js_has'];
$filterUrl = function (array $overrides) use ($filters, $filterFields): string {
    $q = [];
    foreach ($filterFields as $k) {
        $v = $filters[$k] ?? null;
        if ($v === null || $v === '') continue;
        if (is_bool($v)) $v = $v ? '1' : '0';
        $q[$k] = (string)$v;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') { unset($q[$k]); continue; }
        if (is_bool($v)) $v = $v ? '1' : '0';
        $q[$k] = (string)$v;
    }
    return url('/admin/clicks' . ($q ? '?' . http_build_query($q) : ''));
};
// Wrap a value as a clickable filter chip
$chip = function (string $href, string $inner, string $title = ''): string {
    return '<a class="cell-filter" href="' . e($href) . '"'
        . ($title !== '' ? ' title="' . e($title) . '"' : '')
        . '>' . $inner . '</a>';
};

// Cell renderer — output the right HTML for each column key.
$renderCell = function (string $key, array $r) use ($flag, $filterUrl, $chip): string {
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
        case 'flow':
            // No flow_id → click fell through to the campaign-level trash mode
            // (no flow matched). Mark it explicitly so it doesn't blend with normal flows.
            if (empty($r['flow_id'])) {
                return $chip($filterUrl(['is_trash' => 'only']),
                    '<span class="badge badge-danger">→ trash</span>',
                    'Filter to trash-fallthroughs only');
            }
            $name = (string)($r['flow_name'] ?? '');
            return $name !== ''
                ? '<span style="font-family:var(--font-sans);font-size:0.82rem">' . e($name) . '</span>'
                : '<span class="meta-mono">' . e(substr((string)$r['flow_id'], 0, 8)) . '…</span>';
        case 'offer':
            return e((string)($r['offer_name'] ?? '—'));
        case 'visitor':
            return '<span class="meta-mono">' . e(substr((string)($r['visitor_uuid'] ?? ''), 0, 8)) . '…</span>';
        case 'fp_js':
            $fp = (string)($r['fp_js'] ?? '');
            if ($fp === '') return '<span style="color:var(--color-faintest)">—</span>';
            return $chip($filterUrl(['fp_js' => $fp]),
                '<span class="meta-mono">' . e(substr($fp, 0, 10)) . '…</span>',
                'Filter by fingerprint ' . $fp)
                . ' <a href="/admin/sessions?fp=' . e(rawurlencode($fp)) . '" title="Session replays for this fingerprint" style="color:var(--accent);text-decoration:none">▶</a>';
        case 'ip':
            $ip = (string)$r['ip']; $country = (string)($r['country'] ?? '');
            $flagStr = $flag($country);
            $countryHtml = $country !== ''
                ? $chip($filterUrl(['country' => $country]),
                    '<span style="color:var(--color-faint)">' . ($flagStr !== '' ? $flagStr . ' ' : '') . '<span style="text-transform:uppercase">' . e($country) . '</span></span>',
                    'Filter by country ' . strtoupper($country))
                : '';
            $ipChip = $ip !== '' ? '<span class="meta-mono">' . e($ip) . '</span>' : '';
            return ($ipChip !== '' ? $ipChip : '') . ($countryHtml !== '' ? ' ' . $countryHtml : '');
        case 'country':
            $cc = (string)($r['country'] ?? '');
            if ($cc === '') return '<span style="color:var(--color-faintest)">—</span>';
            $flagStr = $flag($cc);
            return $chip($filterUrl(['country' => $cc]),
                ($flagStr !== '' ? $flagStr . ' ' : '') . '<span class="meta-mono" style="text-transform:uppercase">' . e($cc) . '</span>',
                'Filter by country ' . strtoupper($cc));
        case 'region':  return e((string)($r['region'] ?? '—'));
        case 'city':    return e((string)($r['city'] ?? '—'));
        case 'asn':     return '<span class="meta-mono">' . e((string)($r['asn'] ?? '—')) . '</span>';
        case 'isp':     return e((string)($r['isp'] ?? '—'));
        case 'device':
            $dev = (string)($r['device'] ?? '');
            return $dev !== ''
                ? $chip($filterUrl(['device' => $dev]), e($dev), 'Filter by device ' . $dev)
                : '—';
        case 'os':      return e((string)($r['os'] ?? '—'));
        case 'browser': return e((string)($r['browser'] ?? '—'));
        case 'lang':    return '<span class="meta-mono">' . e((string)($r['lang'] ?? '—')) . '</span>';
        case 'is_bot':
            return $r['is_bot']
                ? $chip($filterUrl(['bot_view' => 'only']),
                    '<span class="badge badge-danger">' . e((string)($r['bot_name'] ?? 'bot')) . '</span>',
                    'Filter to bots only')
                : '<span style="color:var(--color-faintest)">—</span>';
        case 'bot_name': return e((string)($r['bot_name'] ?? '—'));
        case 'is_uniq':
            return $r['is_uniq']
                ? $chip($filterUrl(['is_uniq' => '1']),
                    '<span class="badge badge-info">' . e(t('clicks.uniq_badge')) . '</span>',
                    'Filter to unique visitors')
                : $chip($filterUrl(['is_uniq' => '0']),
                    '<span style="color:var(--color-faint);text-decoration:none">' . e(t('clicks.repeat_badge')) . '</span>',
                    'Filter to repeat visitors');
        case 'ua':
            $ua = (string)($r['user_agent'] ?? '');
            return '<span class="meta-mono" title="' . e($ua) . '" style="display:inline-block;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">' . e($ua) . '</span>';
        case 'referer':
            $ref = (string)($r['referer'] ?? '');
            if ($ref === '') return '<span style="color:var(--color-faintest)">—</span>';
            $eng = \App\Shared\Referer\SearchEngine::classify($ref);
            $body = '<span class="meta-mono" title="' . e($ref) . '" style="display:inline-block;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">' . e($ref) . '</span>';
            if ($eng !== null) {
                $body = $chip($filterUrl(['search' => $eng]),
                    '<span class="badge badge-info">' . e($eng) . '</span>',
                    'Filter to ' . $eng . ' referers')
                    . ' ' . $body;
            }
            return $body;
        case 'entry_referer':
            // The visit's external entry source, as recorded by the pixel. Empty
            // whenever no pixel event covers this visitor — the raw Referer
            // column is deliberately left to speak for itself in that case.
            $eref = (string)($r['entry_referer'] ?? '');
            if ($eref === '') return '<span style="color:var(--color-faintest)">—</span>';
            $eng = \App\Shared\Referer\SearchEngine::classify($eref);
            $body = '<span class="meta-mono" title="' . e($eref) . '" style="display:inline-block;max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">' . e($eref) . '</span>';
            if ($eng !== null) {
                $body = $chip($filterUrl(['entry_ref' => $eng]),
                    '<span class="badge badge-info">' . e($eng) . '</span>',
                    'Filter to ' . $eng . ' entry sources')
                    . ' ' . $body;
            }
            return $body;
        case 'utm_source':   return e((string)($r['utm_source'] ?? '—'));
        case 'utm_medium':   return e((string)($r['utm_medium'] ?? '—'));
        case 'utm_campaign': return e((string)($r['utm_campaign'] ?? '—'));
        case 'lander_host':
            $h = (string)($r['lander_host'] ?? '');
            return $h !== ''
                ? '<span class="meta-mono" style="font-size:0.78rem">' . e($h) . '</span>'
                : '<span style="color:var(--color-faint)">—</span>';
        case 'lander_button':
            $b = (string)($r['lander_button'] ?? '');
            return $b !== ''
                ? '<span class="meta-mono">' . e($b) . '</span>'
                : '<span style="color:var(--color-faint)">—</span>';
        case 'schema':       return '<span class="meta-mono" style="font-variant-numeric:tabular-nums">' . (int)($r['schema_id'] ?? 0) . '</span>';
        case 'out_url':
            $u = (string)($r['out_url'] ?? '');
            return $u === ''
                ? '<span style="color:var(--color-faintest)">—</span>'
                : '<span class="meta-mono" title="' . e($u) . '" style="display:inline-block;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:bottom">' . e($u) . '</span>';
        case 'status':
            $st = (int)($r['http_status'] ?? 0);
            $cls = $st >= 400 ? 'badge-danger' : ($st >= 300 ? 'badge-info' : 'badge-success');
            return '<span class="badge ' . $cls . '">' . $st . '</span>';
    }
    return '—';
};

// Build sort link for a column header.
$sortLink = function (string $key) use ($sort): string {
    $cur = $sort['field'] === $key ? $sort['dir'] : null;
    $next = $cur === 'asc' ? 'desc' : 'asc';
    return url('/admin/clicks?sort=' . urlencode($key) . '&dir=' . $next);
};
?>
<?php
$title = t('clicks.title');
$count = (int)$total;
require __DIR__ . '/../../_partials/page-header.php';
?>
<?php $activePath = '/admin/clicks'; require __DIR__ . '/../../_partials/log-switcher.php'; ?>

<!-- 48h timeline — last 48 hours from current hour, reflects active filters -->
<div style="margin-bottom:18px;padding:14px 16px;border:1px solid var(--color-border);border-radius:6px;background:var(--color-surface)">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:6px">
        <span class="eyebrow"><?= e(t('clicks.chart.last_48h')) ?></span>
        <span style="font-family:var(--font-mono);font-size:0.7rem;color:var(--color-faint)"><?= e(t('clicks.chart.hourly_filtered')) ?></span>
    </div>
    <div x-data="clicksTimeline({ points: <?= e(json_encode($timeline, JSON_UNESCAPED_SLASHES)) ?> })" style="width:100%;height:180px"></div>
</div>

<form method="get" class="filter-bar">
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.campaign')) ?></label>
        <select name="campaign_id" class="input-sm" style="width:220px">
            <option value=""><?= e(t('clicks.filter.any_campaign')) ?></option>
            <?php foreach ($campaigns as $c): ?>
                <option value="<?= e($c->id) ?>" <?= ($filters['campaign_id'] ?? '') === $c->id ? 'selected' : '' ?>>
                    <?= e($c->slug) ?> · <?= e($c->name) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.country')) ?></label>
        <input type="text" name="country" value="<?= e((string)($filters['country'] ?? '')) ?>" placeholder="<?= e(t('clicks.filter.country_ph')) ?>" class="input-sm input-mono" style="width:110px">
    </div>
    <div class="filter-field">
        <label class="filter-label">IP</label>
        <input type="text" name="ip" value="<?= e((string)($filters['ip'] ?? '')) ?>" placeholder="1.2.3.4" class="input-sm input-mono" style="width:140px">
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.device')) ?></label>
        <select name="device" class="input-sm" style="width:130px">
            <option value=""><?= e(t('filter_opt.all')) ?></option>
            <option value="mobile"  <?= ($filters['device'] ?? '') === 'mobile'  ? 'selected' : '' ?>>mobile</option>
            <option value="tablet"  <?= ($filters['device'] ?? '') === 'tablet'  ? 'selected' : '' ?>>tablet</option>
            <option value="desktop" <?= ($filters['device'] ?? '') === 'desktop' ? 'selected' : '' ?>>desktop</option>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.bot')) ?></label>
        <select name="bot_view" class="input-sm" style="width:140px">
            <?php $bv = (string)($filters['bot_view'] ?? 'hide'); ?>
            <option value="hide" <?= $bv === 'hide' ? 'selected' : '' ?>><?= e(t('filter_opt.humans_only')) ?></option>
            <option value="all"  <?= $bv === 'all'  ? 'selected' : '' ?>><?= e(t('filter_opt.bots_incl')) ?></option>
            <option value="only" <?= $bv === 'only' ? 'selected' : '' ?>><?= e(t('filter_opt.bots_only')) ?></option>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.routing')) ?></label>
        <select name="is_trash" class="input-sm" style="width:140px">
            <option value="hide" <?= ($filters['is_trash'] ?? 'hide') === 'hide' ? 'selected' : '' ?>><?= e(t('filter_opt.routed_only')) ?></option>
            <option value="all"  <?= ($filters['is_trash'] ?? 'hide') === 'all'  ? 'selected' : '' ?>><?= e(t('filter_opt.trash_incl')) ?></option>
            <option value="only" <?= ($filters['is_trash'] ?? 'hide') === 'only' ? 'selected' : '' ?>><?= e(t('filter_opt.trash_only')) ?></option>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.uniq')) ?></label>
        <select name="is_uniq" class="input-sm" style="width:130px">
            <option value=""><?= e(t('filter_opt.any')) ?></option>
            <option value="1" <?= isset($filters['is_uniq']) && $filters['is_uniq'] === true  ? 'selected' : '' ?>><?= e(t('filter_opt.uniq_only')) ?></option>
            <option value="0" <?= isset($filters['is_uniq']) && $filters['is_uniq'] === false ? 'selected' : '' ?>><?= e(t('filter_opt.repeat')) ?></option>
        </select>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.fingerprint')) ?></label>
        <div style="display:flex;gap:4px;align-items:stretch">
            <select name="fp_js_has" class="input-sm" style="width:110px">
                <?php $fph = (string)($filters['fp_js_has'] ?? ''); ?>
                <option value=""  <?= $fph === ''  ? 'selected' : '' ?>><?= e(t('filter_opt.any')) ?></option>
                <option value="1" <?= $fph === '1' ? 'selected' : '' ?>><?= e(t('filter_opt.with_fp')) ?></option>
                <option value="0" <?= $fph === '0' ? 'selected' : '' ?>><?= e(t('filter_opt.no_fp')) ?></option>
            </select>
            <input type="text" name="fp_js" value="<?= e((string)($filters['fp_js'] ?? '')) ?>" placeholder="<?= e(t('clicks.filter.fp_ph')) ?>" class="input-sm input-mono" style="width:140px">
        </div>
    </div>
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.referer')) ?></label>
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
    <!-- Entry source — matches what the pixel saw when the visit started, not
         the click's own Referer. Left unset by default: it only narrows the
         list when the operator deliberately picks a value. -->
    <div class="filter-field">
        <label class="filter-label"><?= e(t('clicks.filter.entry_ref')) ?></label>
        <select name="entry_ref" class="input-sm" style="width:170px">
            <?php $curEr = (string)($filters['entry_ref'] ?? ''); ?>
            <option value=""    <?= $curEr === ''     ? 'selected' : '' ?>><?= e(t('filter_opt.any')) ?></option>
            <option value="any" <?= $curEr === 'any'  ? 'selected' : '' ?>><?= e(t('filter_opt.any_search')) ?></option>
            <option value="none"<?= $curEr === 'none' ? 'selected' : '' ?>><?= e(t('filter_opt.non_search')) ?></option>
            <?php foreach (\App\Shared\Referer\SearchEngine::keys() as $eng): ?>
                <option value="<?= e($eng) ?>" <?= $curEr === $eng ? 'selected' : '' ?>><?= e($eng) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn-secondary" style="font-size:0.8rem;height:32px;align-self:flex-end"><?= e(t('clicks.apply')) ?></button>
    <!-- Pin the filters currently applied as this operator's own default view.
         Posting with everything unset clears the preference. -->
    <?php $savedView = $saved_view ?? []; ?>
    <button type="submit" form="save-view-form" class="btn-ghost"
            title="<?= e(t('clicks.save_view_hint')) ?>"
            style="font-size:0.8rem;height:32px;align-self:flex-end;padding:0 12px;color:var(--color-muted);border:1px solid var(--color-border);border-radius:4px;background:none;cursor:pointer">
        <?= e($savedView !== [] ? t('clicks.save_view_update') : t('clicks.save_view')) ?>
    </button>
    <a href="<?= e(url('/admin/clicks')) ?>" class="btn-ghost" style="font-size:0.8rem;height:32px;align-self:flex-end;display:inline-flex;align-items:center;padding:0 12px;color:var(--color-muted);text-decoration:none;border:1px solid var(--color-border);border-radius:4px"><?= e(t('clicks.reset')) ?></a>

    <!-- Columns gear — opens drawer panel -->
    <div x-data="{ open: false }" style="margin-left:auto;align-self:flex-end">
        <button type="button" @click="open = true" class="btn-secondary" style="font-size:0.8rem;height:32px;display:inline-flex;align-items:center;gap:6px">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 6h18M3 12h12M3 18h6"/>
            </svg>
            <?= e(t('clicks.columns_btn')) ?>
        </button>

        <!-- Drawer overlay -->
        <div x-show="open" x-cloak @click="open = false" class="drawer-backdrop" :class="{ 'is-open': open }" style="display:none"></div>
        <aside x-show="open" x-cloak class="drawer" :class="{ 'is-open': open }" style="display:none"
               x-data="clickColumnsPanel(<?= htmlspecialchars(json_encode([
                    'all'     => array_map(fn ($k) => ['key' => $k, 'label' => t($columns_meta[$k]['label_key'])], array_keys($columns_meta)),
                    'visible' => $visible_columns,
                ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)">
            <header class="drawer-header">
                <div>
                    <div style="font-family:var(--font-display);font-size:1rem;font-weight:600"><?= e(t('clicks.columns_panel_title')) ?></div>
                    <div style="font-size:0.78rem;color:var(--color-muted);margin-top:2px"><?= e(t('clicks.columns_panel_hint')) ?></div>
                </div>
                <button type="button" class="drawer-close" @click="open = false" aria-label="<?= e(t('a11y.close')) ?>">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6 6l12 12M6 18L18 6"/></svg>
                </button>
            </header>

            <div class="drawer-body">
                <ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:4px">
                    <template x-for="(col, idx) in items" :key="col.key">
                        <li style="display:grid;grid-template-columns:24px 1fr 28px 28px;gap:8px;align-items:center;padding:6px 8px;border:1px solid var(--color-border-soft);border-radius:5px;background:var(--color-surface)">
                            <input type="checkbox" :checked="col.visible" @change="toggle(col.key)" :id="'col-' + col.key">
                            <label :for="'col-' + col.key" style="cursor:pointer;font-family:var(--font-sans);font-size:0.875rem;color:var(--color-text);user-select:none" x-text="col.label"></label>
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
                <form method="post" action="<?= e(url('/admin/clicks/columns/reset')) ?>" style="margin-right:auto">
                    <?= csrf_field($csrf_token) ?>
                    <button type="submit" class="btn-ghost" style="font-size:0.8rem"><?= e(t('clicks.columns_reset')) ?></button>
                </form>
                <button type="button" class="btn-secondary" @click="open = false" style="font-size:0.8rem"><?= e(t('clicks.cancel')) ?></button>
                <form method="post" action="<?= e(url('/admin/clicks/columns')) ?>">
                    <?= csrf_field($csrf_token) ?>
                    <input type="hidden" name="columns" :value="JSON.stringify(visibleKeys())">
                    <button type="submit" class="btn" style="font-size:0.8rem"><?= e(t('clicks.columns_save')) ?></button>
                </form>
            </footer>
        </aside>
    </div>
</form>

<?php
// Sibling form (HTML forms cannot nest) carrying the filters that are actually
// in effect right now. Only the keys the controller accepts as saveable are
// sent; posting them all empty is how the operator clears their default.
$saveableView = ['is_trash', 'bot_view', 'search', 'entry_ref', 'fp_js_has'];
?>
<form id="save-view-form" method="post" action="<?= e(url('/admin/clicks/view')) ?>" style="display:none">
    <?= csrf_field($csrf_token) ?>
    <?php foreach ($saveableView as $k): ?>
        <input type="hidden" name="<?= e($k) ?>" value="<?= e((string)($filters[$k] ?? '')) ?>">
    <?php endforeach; ?>
</form>

<?php if (empty($items)): ?>
    <?php
    $title = t('clicks.empty_title');
    $text = t('clicks.empty_text');
    $iconBody = '<path d="M9 4l3 13 2.5-5L20 9z"/><path d="M14 14l5 5"/>';
    $ctaLabel = t('clicks.empty_cta'); $ctaHref = url('/admin/campaigns');
    $ctaIcon = '<path d="M5 12h14M13 5l7 7-7 7"/>';
    require __DIR__ . '/../../_partials/empty-state.php';
    ?>
<?php else: ?>
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

    <?php if (!empty($visitor)): ?>
        <?php
        $sourceEmoji = static fn (?string $src): string => match ($src) {
            'chatgpt', 'perplexity', 'claude', 'gemini', 'copilot' => '🤖',
            null, ''  => '🔗',
            default   => '🔍',
        };
        $kindEmoji = ['pageview' => '📄', 'click' => '🖱', 'conversion' => '💵'];
        $entrySrc = $visitor['entry']['source'] ?? null;
        $entryRef = (string)($visitor['entry']['ref'] ?? '');
        $totals = $visitor['totals'];
        ?>
        <section style="margin-top:32px">
            <div class="visitor-card" style="background:var(--color-stone-50);border:1px solid var(--color-stone-200);border-radius:8px;padding:16px 20px;margin-bottom:16px">
                <?php $vkind = (string)($visitor['kind'] ?? 'visitor'); $vlabel = $vkind === 'fp_js' ? 'Fingerprint' : 'Visitor'; ?>
                <div class="visitor-summary" style="display:flex;flex-wrap:wrap;gap:18px;align-items:baseline;font-size:0.85rem">
                    <div>
                        <span style="color:var(--color-stone-500);font-size:0.72rem;letter-spacing:0.04em;text-transform:uppercase"><?= $vlabel ?></span>
                        <span class="meta-mono" style="margin-left:6px"><?= e(substr($visitor['uuid'], 0, 10)) ?>…<?= e(substr($visitor['uuid'], -4)) ?></span>
                    </div>
                    <div><?= $sourceEmoji($entrySrc) ?> <strong><?= e($entrySrc ?? 'direct') ?></strong>
                        <?php if ($entryRef !== ''): ?>
                            <span class="meta-mono" style="color:var(--color-stone-500);font-size:0.74rem;margin-left:4px">· <?= e(strlen($entryRef) > 60 ? substr($entryRef, 0, 60) . '…' : $entryRef) ?></span>
                        <?php endif; ?>
                    </div>
                    <div>👁 <?= t('clicks.journey.pageviews', ['count' => '<strong>' . (int)$totals['pageviews'] . '</strong>']) ?></div>
                    <div>🖱 <?= t('clicks.journey.clicks', ['count' => '<strong>' . (int)$totals['clicks'] . '</strong>']) ?></div>
                    <div>💵 <?= t('clicks.journey.conversions', ['count' => '<strong>' . (int)$totals['conversions'] . '</strong>']) ?></div>
                    <?php if (!empty($totals['first_seen'])): ?>
                        <div style="color:var(--color-stone-500);font-size:0.78rem"><?= e(t('clicks.journey.first_seen', ['at' => substr((string)$totals['first_seen'], 0, 19)])) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($visitor['journey'])): ?>
                <div class="tbl-wrap">
                    <div class="tbl-scroll">
                        <table class="tbl">
                            <thead>
                                <tr>
                                    <th style="width:1%;white-space:nowrap"><?= e(t('clicks.journey.time')) ?></th>
                                    <th style="width:1%"><?= e(t('clicks.journey.type')) ?></th>
                                    <th><?= e(t('clicks.journey.detail')) ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($visitor['journey'] as $ev): ?>
                                    <?php
                                    $kind = (string)$ev['kind'];
                                    $isCurrentClick = $kind === 'click' && (string)($ev['click_id'] ?? '') === (string)($filters['click_id'] ?? '');
                                    ?>
                                    <tr<?= $isCurrentClick ? ' style="background:var(--color-stone-100)"' : '' ?>>
                                        <td class="meta-mono" style="white-space:nowrap;font-size:0.78rem"><?= e(substr((string)$ev['at'], 0, 19)) ?></td>
                                        <td style="white-space:nowrap">
                                            <span title="<?= e($kind) ?>"><?= $kindEmoji[$kind] ?? '·' ?></span>
                                            <span style="font-size:0.78rem;color:var(--color-stone-500);margin-left:4px"><?= e($kind) ?></span>
                                        </td>
                                        <td style="font-size:0.83rem">
                                            <?php if ($kind === 'pageview'): ?>
                                                <span><?= e((string)($ev['detail'] ?? '')) ?></span>
                                                <?php if (!empty($ev['ref'])): ?>
                                                    <?php $refSrc = \App\Shared\Referer\SearchEngine::classify((string)$ev['ref']); ?>
                                                    <span style="color:var(--color-stone-500);font-size:0.74rem;margin-left:6px">
                                                        ← <?= $sourceEmoji($refSrc) ?> <?= e($refSrc ?? (parse_url((string)$ev['ref'], PHP_URL_HOST) ?: 'ref')) ?>
                                                    </span>
                                                <?php endif; ?>
                                            <?php elseif ($kind === 'click'): ?>
                                                <span class="meta-mono" style="font-size:0.78rem"><?= e((string)($ev['slug'] ?? '?')) ?></span>
                                                <?php if (!empty($ev['lander_host'])): ?>
                                                    <span style="margin-left:6px"><?= e((string)$ev['lander_host']) ?>
                                                        <?php if (!empty($ev['lander_button'])): ?>
                                                            → <strong><?= e((string)$ev['lander_button']) ?></strong>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($ev['offer'])): ?>
                                                    <span style="color:var(--color-stone-500);margin-left:8px">→ <?= e((string)$ev['offer']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($isCurrentClick): ?>
                                                    <span style="margin-left:8px;font-size:0.7rem;background:var(--color-amber-100,#fef3c7);padding:1px 6px;border-radius:3px"><?= e(t('clicks.journey.this_click')) ?></span>
                                                <?php endif; ?>
                                            <?php elseif ($kind === 'conversion'): ?>
                                                <span class="badge <?= match ((string)$ev['status']) { 'approved' => 'badge-success', 'pending' => 'badge-warn', 'hold' => 'badge-info', 'rejected' => 'badge-danger', default => 'badge-neutral' } ?>"><?= e((string)$ev['status']) ?></span>
                                                <span style="margin-left:6px"><?= e((string)($ev['offer'] ?? '?')) ?> · $<?= e((string)$ev['payout']) ?></span>
                                                <?php if (!empty($ev['detail'])): ?>
                                                    <span style="color:var(--color-stone-500);font-size:0.78rem;margin-left:6px"><?= e(t('clicks.journey.player', ['id' => (string)$ev['detail']])) ?></span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php
    $baseUrl = '/admin/clicks';
    $extraQuery = array_filter([
        'campaign_id' => $filters['campaign_id'] ?? null,
        'country'     => $filters['country']     ?? null,
        'device'      => $filters['device']      ?? null,
        'bot_view'    => ($filters['bot_view'] ?? 'hide') !== 'hide' ? $filters['bot_view'] : null,
        'is_uniq'     => isset($filters['is_uniq']) && $filters['is_uniq'] !== null ? ($filters['is_uniq'] ? '1' : '0') : null,
        'is_trash'    => ($filters['is_trash'] ?? 'hide') !== 'hide' ? $filters['is_trash'] : null,
        'search'      => $filters['search'] ?? null,
        'fp_js'       => $filters['fp_js'] ?? null,
        'fp_js_has'   => $filters['fp_js_has'] ?? null,
    ], fn ($v) => $v !== null && $v !== '');
    require __DIR__ . '/../../_partials/pagination.php';
    ?>
<?php endif; ?>

<script>
window.clickColumnsPanel = function (init) {
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
            // Visible items in user's saved order, then hidden (alphabetical-ish from COLUMNS keys order).
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
