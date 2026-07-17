<?php

declare(strict_types=1);

use App\Shared\Auth\AuthEventLogger;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = new PDO(
        $_ENV['DB_DSN'] ?? 'pgsql:host=db;port=5432;dbname=slimtds',
        $_ENV['DB_USER'] ?? 'slimtds',
        $_ENV['DB_PASSWORD'] ?? 'slimtds',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('DELETE FROM core.auth_events');
    $this->db = new Connection($pdo);
    $this->logger = new AuthEventLogger($this->db);
});

test('logs login_success', function (): void {
    $this->logger->log(
        AuthEventLogger::EVENT_LOGIN_SUCCESS,
        adminLogin: 'alice',
        ip: '192.0.2.1',
        userAgent: 'curl/8',
    );
    $row = $this->db->fetchOne('SELECT * FROM core.auth_events ORDER BY id DESC LIMIT 1');
    expect($row)->not->toBeNull();
    expect($row['event_type'])->toBe('login_success');
    expect($row['admin_login'])->toBe('alice');
    expect($row['ip'])->toBe('192.0.2.1');
});

test('logs details as jsonb', function (): void {
    $this->logger->log(
        AuthEventLogger::EVENT_RATE_LIMITED,
        adminLogin: 'bob',
        ip: '198.51.100.7',
        details: ['attempts' => 6, 'limit' => 5, 'window_seconds' => 300],
    );
    $row = $this->db->fetchOne('SELECT details FROM core.auth_events ORDER BY id DESC LIMIT 1');
    $parsed = json_decode((string)$row['details'], true);
    expect($parsed['attempts'])->toBe(6);
    expect($parsed['limit'])->toBe(5);
    expect($parsed['window_seconds'])->toBe(300);
});

test('rejects unknown event_type', function (): void {
    $this->logger->log('ballooning');
})->throws(InvalidArgumentException::class);

test('recent returns events newest first', function (): void {
    $this->logger->log(AuthEventLogger::EVENT_LOGIN_FAIL, adminLogin: 'x');
    usleep(10_000);
    $this->logger->log(AuthEventLogger::EVENT_LOGIN_SUCCESS, adminLogin: 'y');

    $recent = $this->logger->recent(10);
    expect($recent)->toHaveCount(2);
    expect($recent[0]['event_type'])->toBe('login_success');
    expect($recent[0]['admin_login'])->toBe('y');
    expect($recent[1]['event_type'])->toBe('login_fail');
});

test('accepts nullable admin_login/ip/user_agent', function (): void {
    $this->logger->log(AuthEventLogger::EVENT_LOGIN_FAIL);
    $row = $this->db->fetchOne('SELECT * FROM core.auth_events ORDER BY id DESC LIMIT 1');
    expect($row['admin_login'])->toBeNull();
    expect($row['ip'])->toBeNull();
    expect($row['user_agent'])->toBeNull();
});

test('all 5 event_type values are accepted by CHECK constraint', function (): void {
    foreach ([
        AuthEventLogger::EVENT_LOGIN_SUCCESS,
        AuthEventLogger::EVENT_LOGIN_FAIL,
        AuthEventLogger::EVENT_PASSWORD_CHANGE,
        AuthEventLogger::EVENT_LOGOUT,
        AuthEventLogger::EVENT_RATE_LIMITED,
    ] as $type) {
        $this->logger->log($type, adminLogin: 'test');
    }
    $count = $this->db->fetchScalar('SELECT count(*) FROM core.auth_events');
    expect((int)$count)->toBe(5);
});
