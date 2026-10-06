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

/** Browser E2E targets; override both URL and DSN for a disposable stack. */
function browserUrl(string $path = ''): string
{
    $base = getenv('BROWSER_BASE_URL');
    if (!$base) {
        throw new RuntimeException('Set BROWSER_BASE_URL to an app connected to the disposable browser test database.');
    }
    return rtrim($base, '/') . $path;
}

function browserPdo(): PDO
{
    $dsn = getenv('TEST_PG_DSN');
    if (!$dsn || !getenv('BROWSER_BASE_URL')) {
        throw new RuntimeException('Browser tests require explicit TEST_PG_DSN and BROWSER_BASE_URL; they reset fixtures.');
    }
    return new PDO(
        $dsn,
        getenv('TEST_PG_USER') ?: 'slimtds',
        getenv('TEST_PG_PASSWORD') ?: 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
}

/** Seed the disposable browser administrator independently of other suites. */
function browserAdmin(string $password): void
{
    $pdo = browserPdo();
    $stmt = $pdo->prepare("INSERT INTO core.admins (login, password_hash, must_change_password)
        VALUES ('admin', :hash, false) ON CONFLICT (login) DO UPDATE
        SET password_hash = EXCLUDED.password_hash, must_change_password = false");
    $stmt->execute(['hash' => password_hash($password, PASSWORD_ARGON2ID)]);
    $pdo->exec('DELETE FROM core.rate_limits');
}
