<?php
/** @var array<string,mixed> $recording */
$activity = $recording['has_interaction'] ?? null;
$state = $activity === null ? 'unknown' : (in_array($activity, [true, 1, '1', 't'], true) ? 'active' : 'inactive');
$badge = match ($state) { 'inactive' => 'badge-warn', 'active' => 'badge-success', default => 'badge-ghost' };
?>
<span class="session-subline"><span class="badge <?= e($badge) ?>" data-session-activity="<?= e($state) ?>" title="<?= e(t('sessions.activity_hint_' . $state)) ?>"><?= e(t('sessions.activity_' . ($state === 'inactive' ? 'badge_inactive' : $state))) ?></span></span>
