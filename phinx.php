<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require __DIR__ . '/vendor/autoload.php';

if (file_exists(__DIR__ . '/.env')) {
    Dotenv::createImmutable(__DIR__)->load();
}

$env = $_ENV['APP_ENV'] ?? 'dev';

$connections = [
    'dev' => [
        'adapter' => 'pgsql',
        'host'    => $_ENV['DB_HOST'] ?? 'db',
        'port'    => $_ENV['DB_PORT'] ?? '5432',
        'name'    => $_ENV['DB_NAME'] ?? 'slimtds',
        'user'    => $_ENV['DB_USER'] ?? 'slimtds',
        'pass'    => $_ENV['DB_PASSWORD'] ?? 'slimtds',
        'charset' => 'utf8',
    ],
    'test' => [
        'adapter' => 'pgsql',
        'host'    => 'db-test',
        'port'    => '5432',
        'name'    => 'slimtds_test',
        'user'    => 'slimtds',
        'pass'    => 'slimtds',
        'charset' => 'utf8',
    ],
    'prod' => [
        'adapter' => 'pgsql',
        'host'    => $_ENV['DB_HOST'] ?? 'db',
        'port'    => $_ENV['DB_PORT'] ?? '5432',
        'name'    => $_ENV['DB_NAME'] ?? 'slimtds',
        'user'    => $_ENV['DB_USER'] ?? 'slimtds',
        'pass'    => $_ENV['DB_PASSWORD'] ?? 'slimtds',
        'charset' => 'utf8',
    ],
];

return [
    'paths' => [
        'migrations' => __DIR__ . '/migrations',
        'seeds'      => __DIR__ . '/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment'     => $env,
        'dev'  => $connections['dev'],
        'test' => $connections['test'],
        'prod' => $connections['prod'],
    ],
    'version_order' => 'creation',
];
