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
    echo '<select name="' . e($name) . '" onchange="this.form.submit()" class="border rounded px-2 py-1">';
    echo '<option value="">' . e($allLabel) . '</option>';
    foreach ($options as $opt) {
        $opt = (string)$opt;
        $label = $render ? $render($opt) : $opt;
        echo '<option value="' . e($opt) . '"' . ($selected === $opt ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    echo '</select>';
};

// Traffic-source emoji (same convention as clicks): AI bots / search / direct.
$sourceEmoji = static fn (?string $src): string => match ($src) {
    'chatgpt', 'perplexity', 'claude', 'gemini', 'copilot' => '🤖',
    null, ''  => '🔗',
    default   => '🔍',
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
    'fp'       => $filters['fp'] ?? null,
    // min_dur is preserved only when not the default (3s).
    'min_dur'  => (($filters['min_dur'] ?? '3') !== '3') ? ($filters['min_dur'] ?? null) : null,
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
<h1 class="text-xl font-semibold mb-4"><?= e(t('sessions.title')) ?></h1>

<form method="get" class="mb-4 flex flex-wrap items-center gap-2">
  <select name="campaign" onchange="this.form.submit()" class="border rounded px-2 py-1">
    <option value=""><?= e(t('sessions.all_campaigns')) ?></option>
    <?php foreach ($campaigns as $c): ?>
      <option value="<?= e($c->id) ?>" <?= (($filters['campaign_id'] ?? null) === $c->id) ? 'selected' : '' ?>><?= e($c->name) ?></option>
    <?php endforeach; ?>
  </select>
  <?php
    $sel('domain', $opt_domains, $filters['domain'] ?? null, t('sessions.all_domains'));
    $sel('country', $opt_country, $filters['country'] ?? null, t('sessions.all_countries'), fn ($cc) => trim($flag($cc) . ' ' . strtoupper($cc)));
    $sel('browser', $opt_browser, $filters['browser'] ?? null, t('sessions.all_browsers'));
    $sel('os', $opt_os, $filters['os'] ?? null, t('sessions.all_os'));
    $sel('device', $opt_device, $filters['device'] ?? null, t('sessions.all_devices'));
    $sel('source', array_merge(['any'], $opt_sources), $filters['source'] ?? null, t('sessions.all_sources'),
        fn ($v) => $v === 'any' ? '🔁 ' . t('sessions.source_any') : $sourceEmoji($v) . ' ' . $v);
  ?>
  <?php $minDur = (int)($filters['min_dur'] ?? 3); ?>
  <select name="min_dur" onchange="this.form.submit()" class="border rounded px-2 py-1">
    <option value="0" <?= $minDur === 0 ? 'selected' : '' ?>><?= e(t('sessions.dur_all')) ?></option>
    <?php foreach ([3, 10, 30, 60] as $d): ?>
      <option value="<?= $d ?>" <?= $minDur === $d ? 'selected' : '' ?>>≥ <?= $d ?>s</option>
    <?php endforeach; ?>
  </select>
  <?php if ($activeQ !== []): ?>
    <a href="/admin/sessions" class="border rounded px-2 py-1 text-stone-600 no-underline hover:bg-stone-100">↺ <?= e(t('sessions.reset')) ?></a>
  <?php endif; ?>
  <?php if (!empty($filters['fp'])): ?>
    <input type="hidden" name="fp" value="<?= e((string)$filters['fp']) ?>">
    <span class="ml-1 text-sm text-stone-600"><?= e(t('sessions.filtered_by_fp')) ?>
      <code class="px-1"><?= e(substr((string)$filters['fp'], 0, 12)) ?></code>
      <a class="text-[var(--accent)] underline" href="/admin/sessions"><?= e(t('sessions.clear_filter')) ?></a>
    </span>
  <?php endif; ?>
</form>

<table class="w-full text-sm tabular-nums">
  <thead>
    <tr class="text-left border-b">
      <th class="py-1"><?= $sortLink('started', t('sessions.col_started')) ?></th>
      <th><?= e(t('sessions.col_domain')) ?></th>
      <th><?= e(t('sessions.col_ip')) ?></th>
      <th><?= e(t('sessions.col_country')) ?></th>
      <th><?= e(t('sessions.col_client')) ?></th>
      <th><?= e(t('sessions.col_fp')) ?></th>
      <th class="text-right"><?= e(t('sessions.col_events')) ?></th>
      <th class="text-right"><?= e(t('sessions.col_bytes')) ?></th>
      <th class="text-right"><?= $sortLink('duration', t('sessions.col_duration')) ?></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($sessions as $s): $sfp = (string)($s['fp_js'] ?? ''); ?>
      <tr class="border-b">
        <td class="py-1"><?= e(substr((string)$s['started_at'], 0, 19)) ?></td>
        <td><?php
          $purl = (string)($s['page_url'] ?? '');
          $host = $purl !== '' ? (parse_url($purl, PHP_URL_HOST) ?: '') : '';
          $eng = \App\Shared\Referer\SearchEngine::classify((string)($s['referer'] ?? ''))
               ?? \App\Shared\Referer\SearchEngine::classify($pixel_src[(string)($s['fp_js'] ?? '')] ?? null);
        ?><?= $host !== '' ? e((string)$host) : '<span class="text-stone-400 text-xs">—</span>' ?><?php if ($eng !== null): ?> <span class="badge badge-success"><?= e($eng) ?></span><?php endif; ?></td>
        <td><?php $sip = (string)($s['ip'] ?? ''); ?><?= $sip !== '' ? '<code class="text-xs">' . e($sip) . '</code>' : '<span class="text-stone-400 text-xs">—</span>' ?></td>
        <td><?php $cc = (string)($s['country'] ?? ''); $fl = $flag($cc); ?><?= $cc !== '' ? trim($fl . ' ' . e(strtoupper($cc))) : '<span class="text-stone-400 text-xs">—</span>' ?></td>
        <td><?php
          $client = array_values(array_filter([
            (string)($s['device'] ?? ''),
            (string)($s['os'] ?? ''),
            (string)($s['browser'] ?? ''),
          ], fn ($v) => $v !== ''));
        ?><?= $client !== [] ? e(implode(' · ', $client)) : '<span class="text-stone-400 text-xs">—</span>' ?></td>
        <td>
          <?php if ($sfp !== ''): ?>
            <code class="text-xs"><?= e(substr($sfp, 0, 10)) ?></code>
            <a class="ml-1 text-[var(--accent)] underline text-xs" href="/admin/clicks?fp_js=<?= e(rawurlencode($sfp)) ?>"><?= e(t('sessions.in_clicks')) ?></a>
            <a class="ml-1 text-[var(--accent)] underline text-xs" href="/admin/pixel?fp_js=<?= e(rawurlencode($sfp)) ?>"><?= e(t('sessions.in_pixel')) ?></a>
          <?php else: ?>
            <span class="text-stone-400 text-xs">—</span>
          <?php endif; ?>
        </td>
        <td class="text-right"><?= (int)$s['event_count'] ?></td>
        <td class="text-right"><?= number_format((int)$s['bytes'] / 1024, 1) ?> KB</td>
        <td class="text-right"><?php $dms = $s['duration_ms'] ?? null; ?><?= $dms !== null ? (int)round((int)$dms / 1000) . 's' : '<span class="text-stone-400 text-xs">—</span>' ?></td>
        <td class="text-right">
          <a class="text-[var(--accent)] underline" href="/admin/sessions/<?= e((string)$s['session_id']) ?>"><?= e(t('sessions.replay')) ?></a>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if ($sessions === []): ?>
      <tr><td colspan="10" class="py-6 text-center text-stone-500"><?= e(t('sessions.empty')) ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php
$baseUrl = '/admin/sessions';
$extraQuery = array_merge($activeQ, ['sort' => $sort, 'dir' => $dir]);
require __DIR__ . '/../../_partials/pagination.php';
?>
