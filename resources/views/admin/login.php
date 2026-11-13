<?php
/** @var string $csrf_token */
/** @var string $lang */
$demo = ($_ENV['DEMO_MODE'] ?? '') === '1';
?>
<?php if ($demo): ?>
<div style="border:1px solid var(--border,#d8d2c8);border-radius:8px;padding:10px 12px;margin-bottom:16px;font-size:0.82rem;line-height:1.5;background:var(--surface-2,#f5f1ea)">
    <strong>Demo</strong> — sign in with <code>admin</code> / <code>demo</code>.<br>
    Open sandbox; everything <strong>resets every 4&nbsp;hours</strong>.
</div>
<?php endif; ?>
<form method="post" action="<?= e(url('/admin/login')) ?>" style="display:flex;flex-direction:column;gap:14px">
    <?= csrf_field($csrf_token) ?>

    <label style="display:block">
        <span class="label-uppercase" style="font-size:0.7rem;margin-bottom:4px"><?= e(t('auth.login_field')) ?></span>
        <input id="login" name="login" class="input" autofocus autocomplete="username" required value="<?= e(old('login') ?: ($demo ? 'admin' : '')) ?>" placeholder="admin">
    </label>

    <label style="display:block">
        <span class="label-uppercase" style="font-size:0.7rem;margin-bottom:4px"><?= e(t('auth.password_field')) ?></span>
        <input id="password" type="password" name="password" class="input" autocomplete="current-password" required placeholder="••••••••" value="<?= $demo ? 'demo' : '' ?>">
    </label>

    <button type="submit" class="btn" style="width:100%;justify-content:center;margin-top:6px"><?= e(t('auth.submit')) ?> →</button>
</form>

<div x-data="{ busy: false, err: '' }" style="margin-top:14px;display:flex;flex-direction:column;gap:8px">
    <div style="display:flex;align-items:center;gap:10px;color:var(--color-faint);font-size:0.7rem;letter-spacing:0.06em;text-transform:uppercase">
        <span style="flex:1;height:1px;background:var(--color-border)"></span><?= e(t('auth.or')) ?><span style="flex:1;height:1px;background:var(--color-border)"></span>
    </div>
    <button type="button" class="btn btn-ghost" style="width:100%;justify-content:center;gap:8px" :disabled="busy"
            @click="
                err = '';
                const login = document.getElementById('login').value.trim();
                if (!login) { err = <?= e(json_encode(t('auth.webauthn_need_login'))) ?>; document.getElementById('login').focus(); return; }
                if (!slimWebauthn.supported()) { err = <?= e(json_encode(t('auth.webauthn_unsupported'))) ?>; return; }
                busy = true;
                slimWebauthn.login(login)
                    .then(r => { location.href = r.redirect || '/admin'; })
                    .catch(e => { err = slimWebauthn.niceError(e); busy = false; });
            ">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="15" r="4"/><path d="M10.85 12.15 19 4M18 5l2 2M15 8l2 2"/></svg>
        <span x-text="busy ? <?= e(json_encode(t('auth.webauthn_touch'))) ?> : <?= e(json_encode(t('auth.webauthn_button'))) ?>"></span>
    </button>
    <div x-show="err" x-cloak x-text="err" role="alert" style="font-size:0.8rem;color:var(--color-danger, #b42318)"></div>
</div>

<?php require __DIR__ . '/../_partials/webauthn-js.php'; ?>
