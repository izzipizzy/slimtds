<?php

declare(strict_types=1);

use App\Engine\BotDetector;
use App\Engine\Context;
use App\Shared\Db\Connection;

beforeEach(function (): void {
    $pdo = pdo();
    $pdo->exec("DELETE FROM core.bot_ips WHERE ip <<= '203.0.113.0/24'::inet");
    $this->detector = new BotDetector(new Connection($pdo));
    $this->db = new Connection($pdo);
});

test('explicit ip in bot_ips wins', function (): void {
    $this->db->execute("INSERT INTO core.bot_ips (ip, bot_name) VALUES ('203.0.113.7', 'google')");
    $ctx = new Context('203.0.113.7', 'Mozilla/5.0', 'demo', time());
    $this->detector->detect($ctx);
    expect($ctx->isBot)->toBeTrue();
    expect($ctx->botName)->toBe('google');
});

test('asn isp match flags as bot', function (): void {
    $ctx = new Context('1.2.3.4', 'Mozilla/5.0', 'demo', time());
    $ctx->isp = 'Google LLC';
    $this->detector->detect($ctx);
    expect($ctx->isBot)->toBeTrue();
    expect($ctx->botName)->toBe('google');
});

test('UA googlebot detected', function (): void {
    $ctx = new Context('5.5.5.5', 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'demo', time());
    $this->detector->detect($ctx);
    expect($ctx->botName)->toBe('google');
});

test('UA YandexBot detected', function (): void {
    $ctx = new Context('5.5.5.5', 'Mozilla/5.0 (compatible; YandexBot/3.0)', 'demo', time());
    $this->detector->detect($ctx);
    expect($ctx->botName)->toBe('yandex');
});

test('curl flagged generic', function (): void {
    $ctx = new Context('5.5.5.5', 'curl/8.0', 'demo', time());
    $this->detector->detect($ctx);
    expect($ctx->botName)->toBe('others');
});

test('regular browser not flagged', function (): void {
    $ctx = new Context('5.5.5.5', 'Mozilla/5.0 (Macintosh; Intel Mac OS X) Chrome/120', 'demo', time());
    $this->detector->detect($ctx);
    expect($ctx->isBot)->toBeFalse();
});
