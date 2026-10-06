<?php

declare(strict_types=1);

use App\Shared\Rrweb\Interaction;

test('recorded user interactions are recognized', function (array $data): void {
    expect(Interaction::exists([['type' => 3, 'data' => $data]]))->toBeTrue();
})->with([
    'mouse movement' => [['source' => 1, 'positions' => [['x' => 10, 'y' => 20]]]],
    'touch movement' => [['source' => 6, 'positions' => [['x' => 10, 'y' => 20]]]],
    'click' => [['source' => 2, 'type' => 2]],
    'mouse down' => [['source' => 2, 'type' => 1]],
    'touch start' => [['source' => 2, 'type' => 7]],
    'mouse up' => [['source' => 2, 'type' => 0]],
    'legacy touch movement' => [['source' => 2, 'type' => 8]],
]);

test('page loads, automatic changes and focus are not user interactions', function (): void {
    expect(Interaction::exists([
        ['type' => 4, 'data' => ['href' => 'https://example.test']],
        ['type' => 2, 'data' => []],
        ['type' => 3, 'data' => ['source' => 0]],
        ['type' => 3, 'data' => ['source' => 4]],
        ['type' => 3, 'data' => ['source' => 2, 'type' => 5]],
        ['type' => 3, 'data' => ['source' => 2, 'type' => 6]],
        ['type' => 3, 'data' => ['source' => 1, 'positions' => []]],
        ['type' => 5, 'data' => ['source' => 2, 'type' => 2]],
        null, 'invalid', ['type' => 3, 'data' => 'invalid'],
    ]))->toBeFalse();
});


test('scrolling or typing alone does not satisfy the pointer gate', function (array $data): void {
    expect(Interaction::exists([['type' => 3, 'data' => $data]]))->toBeFalse();
})->with([
    'scroll' => [['source' => 3, 'x' => 0, 'y' => 100]],
    'input' => [['source' => 5, 'text' => 'typed']],
    'drag alone' => [['source' => 12, 'positions' => [['x' => 10, 'y' => 20]]]],
    'touch end alone' => [['source' => 2, 'type' => 9]],
]);
