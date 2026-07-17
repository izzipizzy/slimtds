<?php

declare(strict_types=1);

use Dotenv\Dotenv;

ignore_user_abort(true);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__, 2) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__, 2))->safeLoad();
}

date_default_timezone_set($_ENV['APP_TZ'] ?? 'Europe/Moscow');

$app = (require dirname(__DIR__, 2) . '/config/app.php')();

$maxRequests = (int)($_ENV['FRANKENPHP_MAX_REQUESTS'] ?? 1000);

$handler = static function () use ($app): void {
    $app->run();
};

for ($i = 0; $i < $maxRequests; ++$i) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) break;
}
