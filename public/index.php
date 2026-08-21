<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

date_default_timezone_set($_ENV['APP_TZ'] ?? 'Europe/Moscow');

$app = (require dirname(__DIR__) . '/config/app.php')();

// Classic mode (no FrankenPHP worker runtime): handle a single request.
if (!function_exists('frankenphp_handle_request')) {
    $app->run();
    return;
}

// Worker mode: bootstrap once above, then serve many requests from the same
// process. The Caddyfile registers this file as the worker, so php_server
// routes requests here directly instead of re-bootstrapping per request.
ignore_user_abort(true);

$handler = static function () use ($app): void {
    $app->run();
};

$maxRequests = (int) ($_ENV['FRANKENPHP_MAX_REQUESTS'] ?? 1000);

for ($i = 0; $i < $maxRequests; ++$i) {
    $keepRunning = \frankenphp_handle_request($handler);
    gc_collect_cycles();
    if (!$keepRunning) {
        break;
    }
}
