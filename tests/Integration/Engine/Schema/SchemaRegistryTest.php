<?php

declare(strict_types=1);

use App\Engine\Schema\SchemaRegistry;

test('all 15 ids map to a Schema', function (): void {
    $r = new SchemaRegistry();
    for ($i = 1; $i <= 15; $i++) {
        expect($r->get($i))->toBeInstanceOf(\App\Engine\Schema\Schema::class);
    }
});

test('unknown id throws', function (): void {
    (new SchemaRegistry())->get(999);
})->throws(InvalidArgumentException::class);
