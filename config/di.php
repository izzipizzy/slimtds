<?php

declare(strict_types=1);

use App\Shared\Auth\PasswordHasher;
use App\Shared\Db\Partitions;
use DI\ContainerBuilder;

/**
 * Update-check configuration. Read in one place so the web process and the
 * cron process cannot disagree about whether checking is on or which
 * repository is being checked.
 */
if (!function_exists('updateCheckEnabled')) {
    function updateCheckEnabled(): bool
    {
        return filter_var($_ENV['UPDATE_CHECK_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOL);
    }
}
if (!function_exists('updateCheckRepo')) {
    function updateCheckRepo(): string
    {
        $r = $_ENV['UPDATE_CHECK_REPO'] ?? '';
        return is_string($r) && $r !== '' ? $r : 'izzipizzy/slimtds';
    }
}

return static function (): \DI\Container {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);
    $builder->addDefinitions([
        PDO::class => static function (): PDO {
            $dsn  = $_ENV['DB_DSN'] ?? sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $_ENV['DB_HOST'] ?? 'db',
                $_ENV['DB_PORT'] ?? '5432',
                $_ENV['DB_NAME'] ?? 'slimtds',
            );
            $pdo = new PDO(
                $dsn,
                $_ENV['DB_USER'] ?? 'slimtds',
                $_ENV['DB_PASSWORD'] ?? 'slimtds',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ],
            );
            // Per-session timezone: timestamptz still stored in UTC but
            // SELECT returns local-rendered strings, so /admin/clicks etc.
            // show times in the configured zone (default Europe/Moscow).
            $tz = $_ENV['APP_TZ'] ?? 'Europe/Moscow';
            $pdo->exec("SET TIME ZONE " . $pdo->quote($tz));
            return $pdo;
        },
        Partitions::class     => \DI\autowire(),
        PasswordHasher::class => \DI\autowire(),
        \App\Admin\Repository\AdminRepository::class => \DI\autowire(),
        \App\Shared\RateLimit\RateLimiter::class => \DI\autowire(),
        \App\Shared\Auth\AuthEventLogger::class => \DI\autowire(),
        \App\Shared\Db\Connection::class => \DI\autowire()
            ->constructorParameter('pdo', \DI\get(PDO::class)),
        \App\Shared\Session\PgSessionHandler::class => static function (\App\Shared\Db\Connection $db): \App\Shared\Session\PgSessionHandler {
            $lifetime = (int)($_ENV['SESSION_LIFETIME'] ?? 1_209_600);
            return new \App\Shared\Session\PgSessionHandler($db, $lifetime);
        },
        \App\Shared\Asset\Manifest::class => static function (): \App\Shared\Asset\Manifest {
            return new \App\Shared\Asset\Manifest(dirname(__DIR__) . '/public/assets/manifest.json');
        },
        \App\Admin\Middleware\SessionMiddleware::class => \DI\autowire(),
        \App\Admin\Middleware\LocaleMiddleware::class => \DI\autowire(),
        \App\Admin\Middleware\CsrfMiddleware::class => \DI\autowire(),
        \App\Admin\Middleware\RateLimitMiddleware::class => \DI\autowire(),
        \App\Admin\Middleware\AuthMiddleware::class => \DI\autowire(),
        \App\Admin\Controller\PasswordController::class => \DI\autowire(),
        \App\Admin\Repository\CampaignRepository::class => \DI\autowire(),
        \App\Admin\Form\CampaignForm::class => \DI\autowire(),
        \App\Admin\Controller\CampaignController::class => \DI\autowire(),
        \App\Admin\Repository\OfferRepository::class => \DI\autowire(),
        \App\Admin\Form\OfferForm::class => \DI\autowire(),
        \App\Admin\Controller\OfferController::class => \DI\autowire(),
        \App\Admin\Repository\FlowRepository::class => \DI\autowire(),
        \App\Admin\Form\FlowForm::class => \DI\autowire(),
        \App\Admin\Controller\FlowController::class => \DI\autowire(),
        \App\Admin\Repository\ClickRepository::class => \DI\autowire(),
        \App\Admin\Repository\ViewPreferenceRepository::class => \DI\autowire(),
        \App\Admin\Controller\ClickController::class => \DI\autowire(),
        \App\Admin\Repository\ConversionRepository::class => \DI\autowire(),
        \App\Admin\Controller\ConversionController::class => \DI\autowire(),
        \App\Admin\Controller\PixelController::class => \DI\autowire(),
        \App\Admin\Controller\SessionController::class => \DI\autowire(),
        \App\Admin\Repository\SettingsRepository::class => \DI\autowire(),
        \App\Admin\Repository\RrwebSessionRepository::class => \DI\autowire(),
        \App\Shared\Notification\NotificationRegistry::class => \DI\autowire(),
        \App\Admin\Controller\SettingsController::class => \DI\autowire(),
        \App\Admin\Controller\BackupController::class => \DI\autowire(),
        \App\Stats\StatsRepository::class => \DI\autowire(),
        \App\Admin\Controller\StatsController::class => \DI\autowire(),
        \App\Admin\Middleware\PasswordChangeRequiredMiddleware::class => \DI\autowire(),
        \App\Engine\VisitorResolver::class => \DI\autowire(),
        \App\Engine\DeviceDetector::class => \DI\autowire(),
        \App\Engine\GeoLookup::class => \DI\autowire(),
        \App\Engine\BotDetector::class => \DI\autowire(),
        \App\Engine\FilterCompiler::class => \DI\autowire(),
        \App\Engine\FlowMatcher::class => \DI\autowire(),
        \App\Engine\OfferPicker::class => \DI\autowire(),
        \App\Engine\MacroExpander::class => \DI\autowire(),
        \App\Engine\Schema\SchemaRegistry::class => \DI\autowire(),
        \App\Engine\ClickHandler::class => \DI\autowire(),
        \App\Pixel\ScriptController::class => static fn (\DI\Container $c): \App\Pixel\ScriptController =>
            new \App\Pixel\ScriptController(
                dirname(__DIR__) . '/public/p.js',
                $c->get(\App\Admin\Repository\SettingsRepository::class),
            ),
        \App\Pixel\EventController::class => \DI\autowire(),
        \App\Pixel\RecordController::class => \DI\autowire(),
        \App\Pixel\PixelEventRepository::class => \DI\autowire(),
        \App\Shared\Telegram\TelegramNotifier::class => static fn (): \App\Shared\Telegram\TelegramNotifier =>
            new \App\Shared\Telegram\TelegramNotifier(
                $_ENV['TELEGRAM_BOT_TOKEN'] ?? null,
                $_ENV['TELEGRAM_CHAT_ID']   ?? null,
            ),
        \App\Postback\PostbackOutbox::class => \DI\autowire(),
        \App\Postback\PostbackController::class => \DI\autowire(),
        \App\Cron\Command\PostbackDeliverCommand::class => \DI\autowire(),
        \App\Cron\Command\RrwebFlushCommand::class => \DI\autowire(),
        \App\Cron\Command\BotsUpdateExtraCommand::class => \DI\autowire(),
        \App\Shared\I18n\TranslatorFactory::class => static function (): \App\Shared\I18n\TranslatorFactory {
            return new \App\Shared\I18n\TranslatorFactory(dirname(__DIR__) . '/resources/translations');
        },
        \Symfony\Component\Translation\Translator::class => static function (\DI\Container $c): \Symfony\Component\Translation\Translator {
            return $c->get(\App\Shared\I18n\TranslatorFactory::class)->create();
        },
        \App\Shared\I18n\I18n::class => \DI\autowire(),
        \App\Shared\View\View::class => static function (\DI\Container $c): \App\Shared\View\View {
            return new \App\Shared\View\View(
                viewsDir: dirname(__DIR__) . '/resources/views',
                assets: $c->get(\App\Shared\Asset\Manifest::class),
                i18n:   $c->get(\App\Shared\I18n\I18n::class),
                updateStatus: $c->get(\App\Shared\Version\UpdateStatus::class),
            );
        },

        // ── Version ticker / update check ───────────────────────────────────
        \App\Shared\Version\BuildInfo::class => static fn (): \App\Shared\Version\BuildInfo =>
            \App\Shared\Version\BuildInfo::fromSuperglobals(),
        \App\Shared\Version\UpdateStatusRepository::class => \DI\autowire(),
        \App\Shared\Version\UpdateStateReader::class => \DI\get(\App\Shared\Version\UpdateStatusRepository::class),
        \App\Shared\Version\ReleaseFetcher::class => static fn (): \App\Shared\Version\ReleaseFetcher =>
            new \App\Shared\Version\GithubReleaseFetcher(
                token: (static function (): ?string {
                    $t = $_ENV['UPDATE_CHECK_TOKEN'] ?? null;
                    return is_string($t) && $t !== '' ? $t : null;
                })(),
            ),
        \App\Shared\Version\UpdateStatus::class => static function (\DI\Container $c): \App\Shared\Version\UpdateStatus {
            return new \App\Shared\Version\UpdateStatus(
                $c->get(\App\Shared\Version\BuildInfo::class),
                $c->get(\App\Shared\Version\UpdateStatusRepository::class),
                updateCheckEnabled(),
                updateCheckRepo(),
            );
        },
        \App\Cron\Command\VersionCheckCommand::class => static function (\DI\Container $c): \App\Cron\Command\VersionCheckCommand {
            return new \App\Cron\Command\VersionCheckCommand(
                $c->get(\App\Shared\Version\UpdateStatusRepository::class),
                $c->get(\App\Shared\Version\ReleaseFetcher::class),
                updateCheckEnabled(),
                updateCheckRepo(),
            );
        },
    ]);
    return $builder->build();
};
