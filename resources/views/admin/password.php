<?php
/** @var string $csrf_token */
/** @var bool   $forced */
?>
<div class="max-w-md mx-auto">
    <h1 class="text-2xl font-semibold mb-4"><?= e(t('password.title')) ?></h1>

    <?php if ($forced): ?>
        <div class="mb-4 rounded-md border border-yellow-200 bg-yellow-50 px-4 py-2 text-sm text-yellow-800">
            <?= e(t('password.forced_notice')) ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form method="post" action="<?= e(url('/admin/password')) ?>" class="space-y-4">
                <?= csrf_field($csrf_token) ?>

                <div>
                    <label class="block text-sm font-medium mb-1" for="current"><?= e(t('password.current')) ?></label>
                    <input id="current" type="password" name="current" class="input" autofocus required>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="new_password"><?= e(t('password.new')) ?></label>
                    <input id="new_password" type="password" name="new_password" class="input" minlength="10" required>
                </div>

                <div>
                    <label class="block text-sm font-medium mb-1" for="confirm"><?= e(t('password.confirm')) ?></label>
                    <input id="confirm" type="password" name="confirm" class="input" minlength="10" required>
                </div>

                <button type="submit" class="btn w-full justify-center"><?= e(t('password.submit')) ?></button>
            </form>
        </div>
    </div>
</div>
