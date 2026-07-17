<?php
/** @var array<string,string> $values */
/** @var array<string,string> $errors */
/** @var array<string,array{min:int,max:int,default:int}> $bounds */
/** @var list<array{name:string,size:int,mtime:int}> $backups */
/** @var int $backups_total */
/** @var string $backups_dir */
/** @var list<array<string,mixed>> $cron_latest */
/** @var list<array<string,mixed>> $cron_recent */
/** @var array<string,array{label:string,has_sources:bool,default:string,macros:array<string,string>}> $notif_defs */
/** @var list<string> $notif_engines */
/** @var bool $tg_configured */
/** @var string $csrf_token */

$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024)            return $bytes . ' B';
    if ($bytes < 1024 * 1024)     return number_format($bytes / 1024, 1) . ' KB';
    if ($bytes < 1024 * 1024 * 1024) return number_format($bytes / (1024 * 1024), 1) . ' MB';
    return number_format($bytes / (1024 * 1024 * 1024), 2) . ' GB';
};

$ageHuman = static function (string $iso): string {
    $ts = strtotime($iso);
    if ($ts === false) return '—';
    $d = max(0, time() - $ts);
    if ($d < 60)    return $d . 's';
    if ($d < 3600)  return floor($d / 60) . 'm';
    if ($d < 86400) return floor($d / 3600) . 'h';
    return floor($d / 86400) . 'd';
};
?>
<?php
$title = t('settings.title');
require __DIR__ . '/../../_partials/page-header.php';
?>

<div x-data="{ tab: (location.hash ? location.hash.slice(1) : 'general') }"
     x-init="$watch('tab', v => history.replaceState(null, '', '#' + v))">

    <nav class="tabs" role="tablist">
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'general' }" @click="tab = 'general'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9 1.7 1.7 0 0 0 4.3 7.2l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></svg>
            <?= e(t('settings.tab.general')) ?>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'backups' }" @click="tab = 'backups'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"/><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2"/><path d="M9 12h6"/></svg>
            <?= e(t('settings.tab.backups')) ?> <span class="tab-count"><?= count($backups) ?></span>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'cron' }" @click="tab = 'cron'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            <?= e(t('settings.tab.cron')) ?> <span class="tab-count"><?= count($cron_latest) ?></span>
        </button>
        <button class="tab" role="tab" :class="{ 'is-active': tab === 'notifications' }" @click="tab = 'notifications'">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <?= e(t('settings.tab.notifications')) ?>
        </button>
    </nav>

    <!-- ===================== General tab ===================== -->
    <section x-show="tab === 'general'" class="anim-fade-in" role="tabpanel" style="max-width:600px">

        <form method="post" action="<?= e(url('/admin/settings')) ?>">
            <?= csrf_field($csrf_token) ?>

            <div class="form-section">
                <span class="form-section-label"><?= e(t('settings.retention.heading')) ?></span>
                <p style="font-size:0.82rem;color:var(--color-muted);font-family:var(--font-sans);margin:0 0 18px;line-height:1.5;max-width:60ch">
                    <?= t('settings.retention.hint', ['cmd' => '<code style="font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-size:0.78rem">partitions:rotate</code>']) ?>
                </p>

                <?php foreach ($bounds as $key => $b): $label = match ($key) {
                    'retention_clicks_days'       => t('settings.retention.clicks'),
                    'retention_pixel_events_days' => t('settings.retention.pixel_events'),
                    'retention_fingerprints_days' => t('settings.retention.fingerprints'),
                    'retention_auth_events_days'  => t('settings.retention.auth_events'),
                    'retention_rrweb_days'        => t('settings.retention.rrweb'),
                    default => $key,
                }; ?>
                    <div class="form-row">
                        <label class="label-uppercase" for="<?= $key ?>"><?= e($label) ?></label>
                        <div style="display:flex;align-items:baseline;gap:14px">
                            <input id="<?= $key ?>" name="<?= $key ?>" type="number" class="input"
                                   min="<?= $b['min'] ?>" max="<?= $b['max'] ?>" required
                                   value="<?= e($values[$key] ?? (string)$b['default']) ?>"
                                   style="max-width:180px;font-variant-numeric:tabular-nums">
                            <span style="font-size:0.72rem;color:var(--color-faint);font-family:var(--font-mono)"><?= e(t('settings.retention.bounds', ['min' => $b['min'], 'max' => $b['max'], 'default' => $b['default']])) ?></span>
                        </div>
                        <?php if (isset($errors[$key])): ?>
                            <p class="form-error"><?= e($errors[$key]) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="form-section">
                <span class="form-section-label"><?= e(t('settings.rrweb_sample_rate')) ?></span>
                <div class="form-row">
                    <label class="label-uppercase" for="rrweb_sample_rate"><?= e(t('settings.rrweb_sample_rate')) ?></label>
                    <div style="display:flex;align-items:baseline;gap:14px">
                        <input id="rrweb_sample_rate" name="rrweb_sample_rate" type="number" class="input"
                               min="0" max="100" required
                               value="<?= e($values['rrweb_sample_rate'] ?? '100') ?>"
                               style="max-width:180px;font-variant-numeric:tabular-nums">
                        <span style="font-size:0.72rem;color:var(--color-faint);font-family:var(--font-mono)">range 0–100 · default 100 · %</span>
                    </div>
                    <?php if (isset($errors['rrweb_sample_rate'])): ?>
                        <p class="form-error"><?= e($errors['rrweb_sample_rate']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div style="margin-top:24px;padding-top:18px;border-top:1px solid var(--color-border-soft)">
                <button type="submit" class="btn"><?= e(t('settings.retention.save')) ?></button>
            </div>
        </form>
    </section>

    <!-- ===================== Backups tab ===================== -->
    <section x-show="tab === 'backups'" x-cloak class="anim-fade-in" role="tabpanel">

        <!-- Summary strip — NOT cards; hairline-divided inline KPIs to feel utilitarian -->
        <div style="display:flex;gap:32px;flex-wrap:wrap;padding:14px 0 18px;border-bottom:1px solid var(--color-border-soft);margin-bottom:20px">
            <div>
                <div class="eyebrow"><?= e(t('settings.backups.eyebrow')) ?></div>
                <div style="font-family:var(--font-display);font-size:1.6rem;font-weight:600;line-height:1.1;letter-spacing:-0.02em;font-variant-numeric:tabular-nums;margin-top:4px"><?= count($backups) ?></div>
            </div>
            <div>
                <div class="eyebrow"><?= e(t('settings.backups.total_size')) ?></div>
                <div style="font-family:var(--font-display);font-size:1.6rem;font-weight:600;line-height:1.1;letter-spacing:-0.02em;font-variant-numeric:tabular-nums;margin-top:4px"><?= e($fmtSize((int)$backups_total)) ?></div>
            </div>
            <div>
                <div class="eyebrow"><?= e(t('settings.backups.latest')) ?></div>
                <div style="font-family:var(--font-mono);font-size:0.95rem;color:var(--color-text);margin-top:8px">
                    <?= $backups ? e(date('Y-m-d H:i', (int)$backups[0]['mtime'])) : '<span style="color:var(--color-faintest)">—</span>' ?>
                </div>
            </div>
            <div style="margin-left:auto">
                <form method="post" action="<?= e(url('/admin/settings/backups')) ?>" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent=<?= htmlspecialchars(json_encode(t('settings.backups.working')), ENT_QUOTES) ?>">
                    <?= csrf_field($csrf_token) ?>
                    <button type="submit" class="btn">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        <?= e(t('settings.backups.new')) ?>
                    </button>
                </form>
            </div>
        </div>

        <p style="font-size:0.8rem;color:var(--color-muted);font-family:var(--font-sans);margin:0 0 14px;line-height:1.5;max-width:70ch">
            <?= t('settings.backups.hint', [
                'cmd'  => '<code style="font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-size:0.78rem">pg_dump --format=custom</code>',
                'time' => '<span class="meta-mono">01:00 UTC</span>',
                'dir'  => '<span class="meta-mono">' . e($backups_dir) . '</span>',
            ]) ?>
        </p>

        <?php if ($backups === []): ?>
            <?php
            $title    = t('settings.backups.empty_title');
            $text     = t('settings.backups.empty_text');
            $iconBody = '<path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7"/><path d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2"/><path d="M9 12h6"/>';
            $ctaLabel = null; $ctaHref = null;
            require __DIR__ . '/../../_partials/empty-state.php';
            ?>
        <?php else: ?>
            <div class="tbl-wrap">
                <div class="tbl-scroll">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th><?= e(t('settings.backups.col.file')) ?></th>
                                <th style="text-align:right"><?= e(t('settings.backups.col.size')) ?></th>
                                <th><?= e(t('settings.backups.col.created')) ?></th>
                                <th><?= e(t('settings.backups.col.age')) ?></th>
                                <th style="width:1%"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($backups as $b): ?>
                            <tr>
                                <td class="tbl-primary"><span class="meta-mono" style="font-size:0.88rem;color:var(--color-text)"><?= e($b['name']) ?></span></td>
                                <td style="text-align:right;font-variant-numeric:tabular-nums"><?= e($fmtSize((int)$b['size'])) ?></td>
                                <td><span class="meta-mono"><?= e(date('Y-m-d H:i:s', (int)$b['mtime'])) ?></span></td>
                                <td><span class="meta-mono" style="color:var(--color-muted)"><?= e($ageHuman(date('c', (int)$b['mtime']))) ?> <?= e(t('settings.backups.ago')) ?></span></td>
                                <td style="white-space:nowrap;text-align:right">
                                    <a class="action-link" href="<?= e(url('/admin/settings/backups/' . rawurlencode($b['name']) . '/download')) ?>"><?= e(t('settings.backups.download')) ?></a>
                                    <form method="post"
                                          action="<?= e(url('/admin/settings/backups/' . rawurlencode($b['name']) . '/delete')) ?>"
                                          style="display:inline-block;margin-left:14px"
                                          onsubmit="return confirm(<?= htmlspecialchars(json_encode(t('settings.backups.confirm_delete', ['name' => $b['name']])), ENT_QUOTES) ?>)">
                                        <?= csrf_field($csrf_token) ?>
                                        <button type="submit" class="danger-link" style="background:transparent;border:0;cursor:pointer"><?= e(t('settings.backups.delete')) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===================== Cron log tab ===================== -->
    <section x-show="tab === 'cron'" x-cloak class="anim-fade-in" role="tabpanel">

        <p style="font-size:0.82rem;color:var(--color-muted);font-family:var(--font-sans);margin:0 0 18px;line-height:1.5;max-width:70ch">
            <?= t('settings.cron.hint', [
                'cmd' => '<code style="font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-size:0.78rem">bin/console</code>',
                'app' => '<span class="meta-mono">JournaledApplication</span>',
            ]) ?>
        </p>

        <?php if ($cron_latest === []): ?>
            <?php
            $title    = t('settings.cron.empty_title');
            $text     = t('settings.cron.empty_text');
            $iconBody = '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>';
            require __DIR__ . '/../../_partials/empty-state.php';
            ?>
        <?php else: ?>

            <h3 class="section-title" style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--color-muted);font-family:var(--font-sans);font-weight:500;margin-bottom:10px"><?= e(t('settings.cron.latest_title')) ?></h3>

            <div class="tbl-wrap" style="margin-bottom:28px">
                <div class="tbl-scroll">
                    <table class="tbl">
                        <thead>
                            <tr>
                                <th><?= e(t('settings.cron.col.command')) ?></th>
                                <th><?= e(t('settings.cron.col.last_run')) ?></th>
                                <th><?= e(t('settings.cron.col.age')) ?></th>
                                <th style="text-align:right"><?= e(t('settings.cron.col.duration')) ?></th>
                                <th><?= e(t('settings.cron.col.exit')) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cron_latest as $r): ?>
                            <?php $ec = $r['exit_code']; $ok = $ec === 0 || $ec === '0'; ?>
                            <tr>
                                <td class="tbl-primary"><span class="meta-mono" style="font-size:0.88rem;color:var(--color-text)"><?= e((string)$r['name']) ?></span></td>
                                <td><span class="meta-mono"><?= e(substr((string)$r['started_at'], 0, 19)) ?></span></td>
                                <td><span class="meta-mono" style="color:var(--color-muted)"><?= e($ageHuman((string)$r['started_at'])) ?> ago</span></td>
                                <td style="text-align:right;font-variant-numeric:tabular-nums;color:var(--color-muted);font-family:var(--font-mono);font-size:0.82rem">
                                    <?= $r['duration_ms'] !== null ? number_format((int)$r['duration_ms']) . ' ' . e(t('settings.cron.ms')) : '—' ?>
                                </td>
                                <td>
                                    <?php if ($ec === null): ?>
                                        <span class="badge badge-warn"><?= e(t('settings.cron.running')) ?></span>
                                    <?php elseif ($ok): ?>
                                        <span class="badge badge-success"><?= e(t('settings.cron.ok')) ?></span>
                                    <?php else: ?>
                                        <span class="badge badge-danger">exit <?= (int)$ec ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <h3 class="section-title" style="font-size:0.85rem;text-transform:uppercase;letter-spacing:0.1em;color:var(--color-muted);font-family:var(--font-sans);font-weight:500;margin-bottom:10px"><?= e(t('settings.cron.recent_title')) ?></h3>

            <div style="display:flex;flex-direction:column;gap:6px">
                <?php foreach ($cron_recent as $r): ?>
                    <?php
                        $ec  = $r['exit_code'];
                        $ok  = $ec === 0 || $ec === '0';
                        $tip = $ec === null ? 'warn' : ($ok ? 'success' : 'danger');
                        $preview = trim((string)($r['preview'] ?? ''));
                    ?>
                    <details style="border:1px solid var(--color-border-soft);border-radius:5px;background:var(--color-surface);padding:0 12px">
                        <summary style="display:flex;align-items:center;gap:12px;padding:8px 0;cursor:pointer;font-family:var(--font-sans);font-size:0.82rem;list-style:none">
                            <span style="font-family:var(--font-mono);color:var(--color-muted);font-size:0.75rem;min-width:140px"><?= e(substr((string)$r['started_at'], 0, 19)) ?></span>
                            <span class="meta-mono" style="color:var(--color-text);min-width:200px"><?= e((string)$r['name']) ?></span>
                            <span style="font-family:var(--font-mono);color:var(--color-faint);font-size:0.75rem;font-variant-numeric:tabular-nums;margin-left:auto">
                                <?= $r['duration_ms'] !== null ? number_format((int)$r['duration_ms']) . ' ' . e(t('settings.cron.ms')) : '—' ?>
                            </span>
                            <?php if ($ec === null): ?>
                                <span class="badge badge-warn"><?= e(t('settings.cron.running')) ?></span>
                            <?php elseif ($ok): ?>
                                <span class="badge badge-success"><?= e(t('settings.cron.ok')) ?></span>
                            <?php else: ?>
                                <span class="badge badge-danger">exit <?= (int)$ec ?></span>
                            <?php endif; ?>
                        </summary>
                        <?php if ($preview !== ''): ?>
                            <pre style="margin:0 0 12px;padding:10px 12px;border-top:1px dashed var(--color-border-soft);background:var(--color-surface-2);font-family:var(--font-mono);font-size:0.74rem;line-height:1.45;color:var(--color-stone-700);white-space:pre-wrap;word-break:break-word;border-radius:0 0 4px 4px"><?= e($preview) ?></pre>
                        <?php else: ?>
                            <p style="margin:0 0 10px;padding:8px 0;border-top:1px dashed var(--color-border-soft);font-family:var(--font-mono);font-size:0.74rem;color:var(--color-faint);font-style:italic"><?= e(t('settings.cron.no_output')) ?></p>
                        <?php endif; ?>
                    </details>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===================== Notifications tab ===================== -->
    <section x-show="tab === 'notifications'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:760px">

        <!-- Telegram status strip + test button -->
        <div style="display:flex;align-items:center;gap:16px;padding:14px 0 18px;border-bottom:1px solid var(--color-border-soft);margin-bottom:22px">
            <div>
                <div class="eyebrow"><?= e(t('settings.notif.eyebrow')) ?></div>
                <div style="margin-top:6px">
                    <?php if ($tg_configured): ?>
                        <span class="badge badge-success"><?= e(t('settings.notif.configured')) ?></span>
                    <?php else: ?>
                        <span class="badge badge-danger"><?= e(t('settings.notif.not_configured')) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <p style="font-size:0.8rem;color:var(--color-muted);font-family:var(--font-sans);margin:0;line-height:1.5;max-width:46ch">
                <?= t('settings.notif.env_hint', [
                    'token' => '<span class="meta-mono">TELEGRAM_BOT_TOKEN</span>',
                    'chat'  => '<span class="meta-mono">TELEGRAM_CHAT_ID</span>',
                ]) ?>
            </p>
            <div style="margin-left:auto">
                <form method="post" action="<?= e(url('/admin/settings/notifications/test')) ?>">
                    <?= csrf_field($csrf_token) ?>
                    <button type="submit" class="btn"<?= $tg_configured ? '' : ' disabled' ?>><?= e(t('settings.notif.send_test')) ?></button>
                </form>
            </div>
        </div>

        <form method="post" action="<?= e(url('/admin/settings')) ?>">
            <?= csrf_field($csrf_token) ?>
            <input type="hidden" name="section" value="notifications">

            <?php foreach ($notif_defs as $key => $def):
                $enKey  = "notif_{$key}_enabled";
                $tplKey = "notif_{$key}_template";
                $srcKey = "notif_{$key}_sources";
                $enabled = ($values[$enKey] ?? '1') === '1';
                $tpl = $values[$tplKey] ?? '';
                $selected = isset($values[$srcKey]) ? (json_decode((string)$values[$srcKey], true) ?: []) : ['chatgpt', 'google'];
            ?>
                <div class="form-section" style="margin-bottom:26px;padding-bottom:22px;border-bottom:1px solid var(--color-border-soft)">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer">
                        <input type="checkbox" name="<?= e($enKey) ?>" value="1" <?= $enabled ? 'checked' : '' ?>>
                        <span class="form-section-label" style="margin:0"><?= e($def['label']) ?></span>
                    </label>

                    <?php if ($def['has_sources']): ?>
                        <div style="margin:14px 0 4px">
                            <span class="label-uppercase"><?= e(t('settings.notif.sources')) ?></span>
                            <div style="display:flex;flex-wrap:wrap;gap:6px 16px;margin-top:8px">
                                <?php foreach ($notif_engines as $eng): ?>
                                    <label style="display:flex;align-items:center;gap:6px;font-family:var(--font-mono);font-size:0.8rem;cursor:pointer">
                                        <input type="checkbox" name="<?= e($srcKey) ?>[]" value="<?= e($eng) ?>"
                                               <?= in_array($eng, $selected, true) ? 'checked' : '' ?>>
                                        <?= e($eng) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="form-row" style="margin-top:14px">
                        <label class="label-uppercase" for="<?= e($tplKey) ?>"><?= e(t('settings.notif.template')) ?></label>
                        <textarea id="<?= e($tplKey) ?>" name="<?= e($tplKey) ?>" class="input" rows="5"
                                  placeholder="<?= e($def['default']) ?>"
                                  style="width:100%;font-family:var(--font-mono);font-size:0.8rem;line-height:1.5"><?= e($tpl !== '' ? $tpl : $def['default']) ?></textarea>
                        <p style="font-size:0.72rem;color:var(--color-faint);font-family:var(--font-sans);margin:8px 0 0;line-height:1.6">
                            <?= e(t('settings.notif.template_hint')) ?>
                            <?php foreach ($def['macros'] as $name => $desc): ?>
                                <code class="meta-mono" title="<?= e($desc) ?>" style="background:var(--color-stone-100);padding:1px 5px;border-radius:2px;margin:2px 3px 0 0;display:inline-block">{<?= e($name) ?>}</code>
                            <?php endforeach; ?>
                        </p>
                    </div>
                </div>
            <?php endforeach; ?>

            <div style="margin-top:20px">
                <button type="submit" class="btn"><?= e(t('settings.notif.save')) ?></button>
            </div>
        </form>
    </section>
</div>
