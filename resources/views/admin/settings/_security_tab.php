<?php
declare(strict_types=1);
/** @var list<array{id:int,name:string,created_at:string,last_used_at:?string}> $security_keys */
/** @var string $csrf_token */
$hasKeys = $security_keys !== [];
$fmt = static fn (?string $ts): string => $ts === null ? '—' : date('Y-m-d H:i', (int)strtotime($ts));
$code = 'font-family:var(--font-mono);background:var(--color-stone-100);padding:1px 5px;border-radius:2px;font-size:0.78rem';
?>
<section x-show="tab === 'security'" x-cloak class="anim-fade-in" role="tabpanel" style="max-width:760px">
    <h2 style="font-family:var(--font-display);font-size:1.05rem;font-weight:600;margin:0 0 6px"><?= e(t('settings.security.heading')) ?></h2>
    <p style="font-size:0.85rem;color:var(--color-muted);margin:0 0 16px;line-height:1.5;max-width:70ch"><?= e(t('settings.security.intro')) ?></p>

    <div style="border:1px solid <?= $hasKeys ? 'var(--color-border)' : 'var(--color-border-soft)' ?>;border-radius:4px;padding:10px 14px;margin-bottom:18px;font-size:0.85rem;line-height:1.5;display:flex;gap:10px;align-items:flex-start">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;margin-top:6px;flex:none;background:<?= $hasKeys ? 'var(--color-success, #16a34a)' : 'var(--color-faint)' ?>"></span>
        <span><?= e(t($hasKeys ? 'settings.security.status_on' : 'settings.security.status_off')) ?></span>
    </div>

    <?php if ($hasKeys): ?>
        <div class="tbl-wrap" style="margin-bottom:18px">
            <div class="tbl-scroll">
                <table class="tbl">
                    <thead>
                        <tr>
                            <th><?= e(t('settings.security.col.name')) ?></th>
                            <th><?= e(t('settings.security.col.added')) ?></th>
                            <th><?= e(t('settings.security.col.last_used')) ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($security_keys as $k): ?>
                            <tr>
                                <td><?= e($k['name']) ?></td>
                                <td class="meta-mono"><?= e($fmt($k['created_at'])) ?></td>
                                <td class="meta-mono"><?= e($fmt($k['last_used_at'])) ?></td>
                                <td style="text-align:right">
                                    <form method="post" action="<?= e(url('/admin/settings/security/webauthn/' . $k['id'] . '/delete')) ?>"
                                          onsubmit="return confirm(<?= e(json_encode(t(count($security_keys) === 1 ? 'settings.security.confirm_remove_last' : 'settings.security.confirm_remove'))) ?>)">
                                        <?= csrf_field($csrf_token) ?>
                                        <button type="submit" class="btn btn-ghost" style="font-size:0.75rem;padding:2px 10px;color:var(--color-danger)"><?= e(t('settings.security.remove')) ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div x-data="{ busy: false, err: '', name: 'YubiKey' }" style="display:flex;flex-direction:column;gap:8px;max-width:460px">
        <span class="label-uppercase" style="font-size:0.7rem"><?= e(t('settings.security.name_label')) ?></span>
        <div style="display:flex;gap:8px">
            <input class="input" x-model="name" maxlength="64" style="flex:1">
            <button type="button" class="btn" :disabled="busy"
                    @click="
                        err = '';
                        if (!slimWebauthn.supported()) { err = <?= e(json_encode(t('auth.webauthn_unsupported'))) ?>; return; }
                        <?php if (!$hasKeys): ?>if (!confirm(<?= e(json_encode(t('settings.security.confirm_first'))) ?>)) return;<?php endif; ?>
                        busy = true;
                        slimWebauthn.register(name)
                            .then(() => { location.hash = 'security'; location.reload(); })
                            .catch(e => { err = slimWebauthn.niceError(e); busy = false; });
                    ">
                <span x-text="busy ? <?= e(json_encode(t('auth.webauthn_touch'))) ?> : <?= e(json_encode(t('settings.security.add'))) ?>"></span>
            </button>
        </div>
        <div x-show="err" x-cloak x-text="err" role="alert" style="font-size:0.8rem;color:var(--color-danger)"></div>
    </div>

    <p style="font-size:0.8rem;color:var(--color-muted);margin:22px 0 0;line-height:1.6;max-width:70ch">
        <?= e(t('settings.security.recovery')) ?><br>
        <code style="<?= $code ?>">docker compose exec app php bin/console admin:webauthn-reset admin</code>
    </p>
</section>

<?php require __DIR__ . '/../../_partials/webauthn-js.php'; ?>
