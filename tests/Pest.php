<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Pest bootstrap
|--------------------------------------------------------------------------
| Applies to all Integration tests: gives them a PDO and Connection ready,
| cleans per-test state that would otherwise bleed between tests.
*/

uses()->in('Feature');

uses()->group('integration')->in('Integration');
uses()->group('unit')->in('Unit');
uses()->group('arch')->in('Arch');
uses()->group('browser')->in('Browser');

/*
|--------------------------------------------------------------------------
| Custom expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeUuid', function () {
    return $this->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
*/

function pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
            $_ENV['DB_USER'] ?? 'slimtds',
            $_ENV['DB_PASSWORD'] ?? 'slimtds',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }
    return $pdo;
}
