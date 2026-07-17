<?php

declare(strict_types=1);

use App\Admin\Form\OfferForm;

beforeEach(function (): void {
    $this->form = new OfferForm();
});

/** @param array<string,mixed> $over */
function offerData(array $over = []): array
{
    return array_merge(['name' => 'x', 'currency' => 'USD', 'url' => ''], $over);
}

test('accepts a plain url', function (): void {
    $err = $this->form->validate(offerData(['url' => 'https://example.com/land?p=1']));
    expect($err)->not->toHaveKey('url');
});

test('accepts a url with embedded macros', function (): void {
    $err = $this->form->validate(offerData(['url' => 'https://ex.com/?cid={click_id}&r={country}']));
    expect($err)->not->toHaveKey('url');
});

test('accepts a url with an embedded {spin} of path fragments', function (): void {
    $err = $this->form->validate(offerData(['url' => 'https://reffpa.com/L?click_id={click_id}&r={spin:slots|slots/game/52358/aviator|registration}']));
    expect($err)->not->toHaveKey('url');
});

test('accepts a {spin} of whole urls', function (): void {
    $url = '{spin:https://r1wjeon.life/?open=register&p=zal5|https://r1wjeon.life/v3/aggressive-casino?p=zal5|https://r1wjeon.life/?p=zal5}';
    $err = $this->form->validate(offerData(['url' => $url]));
    expect($err)->not->toHaveKey('url');
});

test('rejects genuinely invalid urls', function (): void {
    $err = $this->form->validate(offerData(['url' => 'not a url at all']));
    expect($err)->toHaveKey('url');
});

test('rejects an empty url', function (): void {
    $err = $this->form->validate(offerData(['url' => '']));
    expect($err['url'] ?? null)->toBe('validation.required');
});
