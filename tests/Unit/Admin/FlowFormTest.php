<?php

declare(strict_types=1);

use App\Admin\Form\FlowForm;

test('accepts bounded named parameters and existing fields', function (string $field): void {
    $data = ['name' => 'Ember', 'schema_id' => 2, 'filters' => json_encode([[['field' => $field, 'op' => 'eq', 'value' => 'ember']]])];
    expect((new FlowForm())->validate($data))->toBe([]);
})->with(['param:brand', 'param:sub_id_30', 'param:_', 'param:A-b_0', 'param:' . str_repeat('a', 64), 'country', 'region', 'city', 'asn', 'device', 'os', 'browser', 'lang', 'is_bot', 'bot_name', 'is_uniq', 'referer', 'referer_domain', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'time_hour', 'day_of_week', 'ip_in_list', 'campaign']);

test('rejects invalid parameter names without changing submitted data', function (string $name): void {
    $data = ['name' => 'Ember', 'schema_id' => 2, 'filters' => json_encode([[['field' => 'param:' . $name, 'op' => 'neq', 'value' => 'ember']]])];
    $before = $data;
    expect((new FlowForm())->validate($data))->toHaveKey('filters', 'validation.pattern');
    expect($data)->toBe($before);
})->with(['', '1brand', 'bad.name', 'bad name', 'бренд', "brand\n", '<img src=x onerror=alert(1)>', str_repeat('a', 65)]);

test('retains existing empty filters and form validation', function (): void {
    $form = new FlowForm();
    expect($form->validate(['name' => 'Ember', 'schema_id' => 2, 'filters' => '[]']))->toBe([]);
    expect($form->validate(['name' => '', 'schema_id' => 17, 'filters' => '{', 'target_offers' => '{', 'weight' => 1001]))
        ->toHaveKeys(['name', 'schema_id', 'filters', 'target_offers', 'weight']);
});
