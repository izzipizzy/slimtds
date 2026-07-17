<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists(dirname(__DIR__) . '/.env')) {
    Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

date_default_timezone_set($_ENV['APP_TZ'] ?? 'Europe/Moscow');

$app = (require dirname(__DIR__) . '/config/app.php')();
$app->run();
