<?php

declare(strict_types=1);

use App\Engine\Context;

test('Context constructs with mandatory fields and sensible defaults', function (): void {
    $ctx = new Context(
        ip: '1.2.3.4',
        userAgent: 'curl/8',
        campaignSlug: 'demo01',
        timestamp: 1_714_000_000,
    );
    expect($ctx->ip)->toBe('1.2.3.4');
    expect($ctx->isUniqVisitor)->toBeTrue();
    expect($ctx->isBot)->toBeFalse();
    expect($ctx->utm)->toBe([]);
});
