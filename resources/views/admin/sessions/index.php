<?php
/** @var list<array<string,mixed>> $sessions */
/** @var int $total */
/** @var int $page */
/** @var int $pages */
/** @var int $per_page */
/** @var string $sort */
/** @var string $dir */
/** @var array<string,?string> $filters */
/** @var list<\App\Admin\Repository\Campaign> $campaigns */
/** @var list<string> $opt_domains */
/** @var list<string> $opt_country */
/** @var list<string> $opt_browser */
/** @var list<string> $opt_os */
/** @var list<string> $opt_device */
/** @var list<string> $opt_sources */
/** @var array<string,string> $pixel_src */

// ISO-2 country code → flag emoji via Unicode regional indicator symbols.
$flag = function (?string $cc): string {
    if (!is_string($cc)) return '';
    $cc = strtoupper(trim($cc));
    if (strlen($cc) !== 2 || !ctype_alpha($cc)) return '';
    return mb_chr(0x1F1E6 + ord($cc[0]) - 65, 'UTF-8') . mb_chr(0x1F1E6 + ord($cc[1]) - 65, 'UTF-8');
};

// Render a filter <select> that auto-submits; $render maps an option value to its label.
$sel = function (string $name, array $options, ?string $selected, string $allLabel, ?callable $render = null): void {
    echo '<select name="' . e($name) . '" onchange="this.form.submit()" class="input-sm" aria-label="' . e($allLabel) . '">';
    echo '<option value="">' . e($allLabel) . '</option>';
    foreach ($options as $opt) {
        $opt = (string)$opt;
        $label = $render ? $render($opt) : $opt;
        echo '<option value="' . e($opt) . '"' . ($selected === $opt ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
};

// Active filters as query params (reused for sort headers + pagination).
$activeQ = array_filter([
    'campaign' => $filters['campaign_id'] ?? null,
    'domain'   => $filters['domain'] ?? null,
    'country'  => $filters['country'] ?? null,
    'browser'  => $filters['browser'] ?? null,
    'os'       => $filters['os'] ?? null,
    'device'   => $filters['device'] ?? null,
    'source'   => $filters['source'] ?? null,
    'activity' => (($filters['activity'] ?? 'not_bot') !== 'not_bot') ? ($filters['activity'] ?? null) : null,
    'fp'       => $filters['fp'] ?? null,
    'from'     => $filters['from'] ?? null,
    'to'       => $filters['to'] ?? null,
    'range'    => $filters['range'] ?? null,
    // min_dur is preserved only when not the default (all durations).
    'min_dur'  => (($filters['min_dur'] ?? '0') !== '0') ? ($filters['min_dur'] ?? null) : null,
], fn ($v) => $v !== null && $v !== '');

// Sortable column header: toggles asc/desc, shows the active arrow, keeps filters.
$sortLink = function (string $key, string $label) use ($activeQ, $sort, $dir): string {
    $active = $sort === $key;
    $nextDir = ($active && $dir === 'desc') ? 'asc' : 'desc';
    $arrow = $active ? ($dir === 'desc' ? ' ▾' : ' ▴') : '';
    $q = array_merge($activeQ, ['sort' => $key, 'dir' => $nextDir]);
    return '<a href="/admin/sessions?' . e(http_build_query($q)) . '" style="color:inherit;text-decoration:none">'
         . e($label) . e($arrow) . '</a>';
};
?>
<?php
$title = t('sessions.title');
$count = $total;
$ctaLabel = $ctaHref = null;
require __DIR__ . '/../../_partials/page-header.php';
?>
<p class="session-intro"><?= e(t('sessions.subtitle')) ?></p>

<form method="get" class="filter-bar session-filters">
  <input type="hidden" name="sort" value="<?= e($sort) ?>">
  <input type="hidden" name="dir" value="<?= e($dir) ?>">
  <label class="filter-field">
    <span class="filter-label"><?= e(t('campaigns.title')) ?></span>
    <select name="campaign" onchange="this.form.submit()" class="input-sm">
      <option value=""><?= e(t('sessions.all_campaigns')) ?></option>
      <?php foreach ($campaigns as $c): ?>
        <option value="<?= e($c->id) ?>" <?= (($filters['campaign_id'] ?? null) === $c->id) ? 'selected' : '' ?>><?= e($c->name) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php foreach ([
      ['domain', $opt_domains, 'sessions.col_domain', 'sessions.all_domains'],
      ['country', $opt_country, 'sessions.col_country', 'sessions.all_countries'],
      ['browser', $opt_browser, 'sessions.filter_browser', 'sessions.all_browsers'],
      ['os', $opt_os, 'sessions.filter_os', 'sessions.all_os'],
      ['device', $opt_device, 'sessions.filter_device', 'sessions.all_devices'],
      ['source', array_merge(['any'], $opt_sources), 'sessions.filter_source', 'sessions.all_sources'],
  ] as [$name, $options, $label, $all]): ?>
    <label class="filter-field">
      <span class="filter-label"><?= e(t($label)) ?></span>
      <?php $sel($name, $options, $filters[$name] ?? null, t($all), match ($name) {
          'country' => fn ($cc) => trim($flag($cc) . ' ' . strtoupper($cc)),
          'source' => fn ($v) => $v === 'any' ? t('sessions.source_any') : $v,
          default => null,
      }); ?>
    </label>
  <?php endforeach; ?>
  <label class="filter-field">
    <span class="filter-label"><?= e(t('sessions.filter_activity')) ?></span>
    <select name="activity" onchange="this.form.submit()" class="input-sm">
      <?php foreach (['not_bot', 'all', 'inactive', 'active', 'unknown'] as $value): ?>
        <option value="<?= e($value) ?>" <?= ($filters['activity'] ?? 'not_bot') === $value ? 'selected' : '' ?>><?= e(t('sessions.activity_' . $value)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label class="filter-field">
    <span class="filter-label"><?= e(t('sessions.col_duration')) ?></span>
    <?php $minDur = (int)($filters['min_dur'] ?? 0); ?>
    <select name="min_dur" onchange="this.form.submit()" class="input-sm">
      <option value="0" <?= $minDur === 0 ? 'selected' : '' ?>><?= e(t('sessions.dur_all')) ?></option>
      <?php foreach ([3, 10, 30, 60] as $d): ?>
        <option value="<?= $d ?>" <?= $minDur === $d ? 'selected' : '' ?>>≥ <?= $d ?> <?= e(t('sessions.seconds')) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <div class="session-filter-period">
    <?php
    $rangeBare = false;
    $rangeDefault = 'all';
    $rangeUrl = static fn (string $preset): string => '/admin/sessions?' . http_build_query(array_merge(
        array_diff_key($activeQ, ['from' => 1, 'to' => 1, 'range' => 1]),
        $preset === 'all' ? [] : ['range' => $preset],
    ));
    $rangeAutoSubmit = true;
    $rangeInputClass = 'input-sm input-mono';
    require __DIR__ . '/../../_partials/date-range.php';
    ?>
  </div>
  <div class="session-filter-actions">
    <?php if ($activeQ !== []): ?>
      <a href="<?= e(url('/admin/sessions')) ?>" class="btn-ghost"><?= e(t('sessions.reset')) ?></a>
    <?php endif; ?>
    <?php if (!empty($filters['fp'])): ?>
      <input type="hidden" name="fp" value="<?= e((string)$filters['fp']) ?>">
      <span class="badge badge-ghost" title="<?= e(t('sessions.filtered_by_fp')) ?>"><code><?= e(substr((string)$filters['fp'], 0, 12)) ?></code></span>
    <?php endif; ?>
  </div>
</form>

<?php if ($sessions === []): ?>
  <?php
  $title = t('sessions.empty');
  $text = t('sessions.empty_hint');
  $ctaLabel = $ctaHref = null;
  $iconBody = '<rect x="3" y="4" width="18" height="14" rx="2"/><path d="m10 8 5 3-5 3zM8 21h8"/>';
  require __DIR__ . '/../../_partials/empty-state.php';
  ?>
<?php else: ?>
<div class="tbl-wrap"><div class="tbl-scroll">
<table class="tbl session-table">
  <thead>
    <tr>
      <th><?= $sortLink('started', t('sessions.col_started')) ?></th>
      <th><?= e(t('sessions.col_domain')) ?></th>
      <th><?= e(t('sessions.col_visitor')) ?></th>
      <th><?= e(t('sessions.col_client')) ?></th>
      <th class="session-number"><?= e(t('sessions.col_events')) ?></th>
      <th class="session-number"><?= $sortLink('duration', t('sessions.col_duration')) ?></th>
      <th><span class="sr-only"><?= e(t('sessions.replay')) ?></span></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($sessions as $s):
      $sfp = (string)($s['fp_js'] ?? '');
      $started = substr((string)$s['started_at'], 0, 19);
      $purl = (string)($s['page_url'] ?? '');
      $host = $purl !== '' ? (parse_url($purl, PHP_URL_HOST) ?: '') : '';
      $eng = \App\Shared\Referer\SearchEngine::classify((string)($s['referer'] ?? ''))
           ?? \App\Shared\Referer\SearchEngine::classify($pixel_src[$sfp] ?? null);
      $cc = (string)($s['country'] ?? '');
      $sip = (string)($s['ip'] ?? '');
      $dms = $s['duration_ms'] ?? null;
    ?>
      <tr>
        <td class="session-time"><span class="meta-mono"><?= e(substr($started, 11, 8)) ?></span><span class="session-subline"><?= e(substr($started, 0, 10)) ?></span></td>
        <td><span class="session-domain" title="<?= e($purl) ?>"><?= $host !== '' ? e((string)$host) : '—' ?></span><?php if ($eng !== null): ?><span class="session-subline"><span class="badge badge-info"><?= e($eng) ?></span></span><?php endif; ?></td>
        <td>
          <?php $recording = $s; require __DIR__ . '/_activity.php'; ?>
          <span class="meta-mono"><?= $cc !== '' ? e(trim($flag($cc) . ' ' . strtoupper($cc))) . ' · ' : '' ?><?= $sip !== '' ? e($sip) : '—' ?></span>
          <?php if ($sfp !== ''): ?>
            <span class="session-subline session-visitor-links"><code title="<?= e($sfp) ?>"><?= e(substr($sfp, 0, 10)) ?></code>
              <a href="<?= e(url('/admin/clicks?fp_js=' . rawurlencode($sfp))) ?>"><?= e(t('sessions.in_clicks')) ?></a>
              <a href="<?= e(url('/admin/pixel?fp_js=' . rawurlencode($sfp))) ?>"><?= e(t('sessions.in_pixel')) ?></a>
            </span>
          <?php endif; ?>
        </td>
        <td><span><?= e((string)(($s['browser'] ?? '') ?: '—')) ?></span><span class="session-subline"><?= e(implode(' · ', array_filter([(string)($s['os'] ?? ''), (string)($s['device'] ?? '')]))) ?></span></td>
        <td class="session-number"><span class="meta-mono"><?= number_format((int)$s['event_count']) ?></span><span class="session-subline"><?= number_format((int)$s['bytes'] / 1024, 1) ?> KB</span></td>
        <td class="session-number"><span class="session-duration"><?= $dms !== null ? (int)round((int)$dms / 1000) . ' ' . e(t('sessions.seconds')) : '—' ?></span></td>
        <td class="session-replay-action"><a class="btn-secondary" href="<?= e(url('/admin/sessions/' . (string)$s['session_id'])) ?>"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m8 5 11 7-11 7z"/></svg><?= e(t('sessions.replay')) ?></a></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div></div>
<?php endif; ?>
<?php
$baseUrl = '/admin/sessions';
$extraQuery = array_merge($activeQ, ['sort' => $sort, 'dir' => $dir]);
require __DIR__ . '/../../_partials/pagination.php';
?>
