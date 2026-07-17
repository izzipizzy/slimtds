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
