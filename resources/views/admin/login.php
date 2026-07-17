<?php
/** @var string $csrf_token */
/** @var string $lang */
?>
<form method="post" action="<?= e(url('/admin/login')) ?>" style="display:flex;flex-direction:column;gap:14px">
    <?= csrf_field($csrf_token) ?>

    <label style="display:block">
        <span class="label-uppercase" style="font-size:0.7rem;margin-bottom:4px"><?= e(t('auth.login_field')) ?></span>
        <input id="login" name="login" class="input" autofocus autocomplete="username" required value="<?= e(old('login')) ?>" placeholder="admin">
    </label>

    <label style="display:block">
        <span class="label-uppercase" style="font-size:0.7rem;margin-bottom:4px"><?= e(t('auth.password_field')) ?></span>
        <input id="password" type="password" name="password" class="input" autocomplete="current-password" required placeholder="••••••••">
    </label>

    <button type="submit" class="btn" style="width:100%;justify-content:center;margin-top:6px"><?= e(t('auth.submit')) ?> →</button>
</form>
