<?php
/** @var array<string,mixed> $session */
/** @var string $events_url */
/** @var string|null $pixel_referer */
$eng = \App\Shared\Referer\SearchEngine::classify((string)($session['referer'] ?? ''))
     ?? \App\Shared\Referer\SearchEngine::classify($pixel_referer ?? null);
$dms = $session['duration_ms'] ?? null;
$sfp = (string)($session['fp_js'] ?? '');
$purl = (string)($session['page_url'] ?? '');
$host = $purl !== '' ? (parse_url($purl, PHP_URL_HOST) ?: '') : '';
$title = t('sessions.replay_title');
$count = null;
$ctaLabel = $ctaHref = null;
$breadcrumb = [[t('sessions.title'), url('/admin/sessions')], [t('sessions.replay'), null]];
require __DIR__ . '/../../_partials/page-header.php';
?>
<link rel="stylesheet" href="<?= e(asset('session-player.css')) ?>">
<p class="session-intro"><?php $recording = $session; require __DIR__ . '/_activity.php'; ?><span class="meta-mono"><?= e(substr((string)$session['started_at'], 0, 19)) ?></span><?php if ($eng !== null): ?> <span class="badge badge-info"><?= e($eng) ?></span><?php endif; ?></p>

<div class="session-details">
  <div class="session-detail"><span class="filter-label"><?= e(t('sessions.col_domain')) ?></span><span class="session-detail-value" title="<?= e($purl) ?>"><?= $host !== '' ? e((string)$host) : '—' ?></span></div>
  <div class="session-detail"><span class="filter-label"><?= e(t('sessions.col_visitor')) ?></span><span class="session-detail-value meta-mono"><?= e((string)($session['ip'] ?? '—')) ?></span><?php if ($sfp !== ''): ?><span class="session-visitor-links"><a href="<?= e(url('/admin/clicks?fp_js=' . rawurlencode($sfp))) ?>"><?= e(t('sessions.in_clicks')) ?></a><a href="<?= e(url('/admin/pixel?fp_js=' . rawurlencode($sfp))) ?>"><?= e(t('sessions.in_pixel')) ?></a></span><?php endif; ?></div>
  <div class="session-detail"><span class="filter-label"><?= e(t('sessions.col_client')) ?></span><span class="session-detail-value"><?= e((string)(($session['browser'] ?? '') ?: '—')) ?></span><span class="session-subline"><?= e(implode(' · ', array_filter([(string)($session['os'] ?? ''), (string)($session['device'] ?? '')]))) ?></span></div>
  <div class="session-detail"><span class="filter-label"><?= e(t('sessions.col_duration')) ?></span><span class="session-detail-value"><?= $dms !== null ? (int)round((int)$dms / 1000) . ' ' . e(t('sessions.seconds')) : '—' ?></span><span class="session-subline"><?= number_format((int)$session['event_count']) ?> <?= e(t('sessions.col_events')) ?> · <?= number_format((int)$session['bytes'] / 1024, 1) ?> KB</span></div>
</div>
<div id="rrweb-player" class="session-player" role="region" aria-label="<?= e(t('sessions.replay_title')) ?>"
     data-events-url="<?= e($events_url) ?>"
     <?php foreach (['play', 'pause', 'timeline', 'speed', 'loading', 'load_error', 'empty_recording', 'recording'] as $label): ?>data-<?= e(str_replace('_', '-', $label)) ?>="<?= e(t('sessions.player_' . $label)) ?>" <?php endforeach; ?>>
  <div class="session-player-message" role="status"><?= e(t('sessions.player_loading')) ?></div>
</div>
<script src="<?= e(asset('session-player.js')) ?>" defer></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    var player = document.getElementById('rrweb-player');
    if (window.slimSessionPlayer && player) window.slimSessionPlayer(player, player.dataset.eventsUrl);
  });
</script>
