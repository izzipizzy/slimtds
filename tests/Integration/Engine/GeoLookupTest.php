<?php

declare(strict_types=1);

use App\Engine\Context;
use App\Engine\GeoLookup;

beforeEach(function (): void {
    $this->lookup = new GeoLookup();
    if (!$this->lookup->isAvailable()) {
        $this->markTestSkipped('GeoLite2 databases not present — run `docker compose --profile geo up geoipupdate` once');
    }
});

test('looks up known IP', function (): void {
    $ctx = new Context('8.8.8.8', 'curl/8', 'demo', time());
    $this->lookup->lookup($ctx);
    expect($ctx->country)->toBe('us');
    expect($ctx->asn)->toBe(15169); // Google
});

test('private IP falls through silently', function (): void {
    $ctx = new Context('192.168.1.1', 'curl/8', 'demo', time());
    $this->lookup->lookup($ctx);
    expect($ctx->country)->toBeNull();
});

test('invalid IP is no-op', function (): void {
    $ctx = new Context('not-an-ip', 'curl/8', 'demo', time());
    $this->lookup->lookup($ctx);
    expect($ctx->country)->toBeNull();
});
