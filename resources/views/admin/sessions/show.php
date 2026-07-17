<?php
/** @var array<string,mixed> $session */
/** @var string $events_url */
/** @var string|null $pixel_referer */
?>
<link rel="stylesheet" href="<?= e(asset('session-player.css')) ?>">
<h1 class="text-xl font-semibold mb-4"><?= e(t('sessions.replay_title')) ?></h1>
<?php
$eng = \App\Shared\Referer\SearchEngine::classify((string)($session['referer'] ?? ''))
     ?? \App\Shared\Referer\SearchEngine::classify($pixel_referer ?? null);
$dms = $session['duration_ms'] ?? null;
?>
<div class="mb-3 text-sm text-stone-600">
  <?= e(substr((string)$session['started_at'], 0, 19)) ?>
  <?php if ($dms !== null): ?> · <?= (int)round((int)$dms / 1000) ?>s<?php endif; ?>
  · <?= (int)$session['event_count'] ?> <?= e(t('sessions.col_events')) ?>
  <?php if ($eng !== null): ?> · <span class="badge badge-success"><?= e($eng) ?></span><?php endif; ?>
  <?php $sfp = (string)($session['fp_js'] ?? ''); if ($sfp !== ''): ?>
    · <code class="text-xs"><?= e(substr($sfp, 0, 12)) ?></code>
    <a class="ml-2 text-[var(--accent)] underline" href="/admin/clicks?fp_js=<?= e(rawurlencode($sfp)) ?>"><?= e(t('sessions.in_clicks')) ?></a>
    <a class="ml-2 text-[var(--accent)] underline" href="/admin/pixel?fp_js=<?= e(rawurlencode($sfp)) ?>"><?= e(t('sessions.in_pixel')) ?></a>
  <?php endif; ?>
</div>
<div id="rrweb-player" class="border rounded overflow-hidden"></div>
<script src="<?= e(asset('session-player.js')) ?>" defer
        data-events-url="<?= e($events_url) ?>"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    if (window.slimSessionPlayer) {
      window.slimSessionPlayer(document.getElementById('rrweb-player'), <?= json_encode($events_url) ?>);
    }
  });
</script>
