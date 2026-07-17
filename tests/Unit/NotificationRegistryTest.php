<?php

declare(strict_types=1);

use App\Shared\Notification\NotificationRegistry;

beforeEach(function (): void {
    $this->reg = new NotificationRegistry();
});

test('definitions expose all four notifications with required keys', function (): void {
    $defs = $this->reg->definitions();
    expect(array_keys($defs))->toBe([
        NotificationRegistry::AI_CLICK,
        NotificationRegistry::AI_PIXEL,
        NotificationRegistry::CONV_CLICK,
        NotificationRegistry::CONV_PING,
    ]);
    foreach ($defs as $d) {
        expect($d)->toHaveKeys(['label', 'has_sources', 'default', 'macros']);
        expect($d['default'])->toBeString()->not->toBe('');
        expect($d['macros'])->toBeArray()->not->toBeEmpty();
    }
    expect($defs[NotificationRegistry::AI_CLICK]['has_sources'])->toBeTrue();
    expect($defs[NotificationRegistry::AI_PIXEL]['has_sources'])->toBeTrue();
    expect($defs[NotificationRegistry::CONV_CLICK]['has_sources'])->toBeFalse();
    expect($defs[NotificationRegistry::CONV_PING]['has_sources'])->toBeFalse();
});

test('render substitutes placeholders', function (): void {
    $out = $this->reg->render(NotificationRegistry::AI_CLICK, '{source} -> {campaign}', [
        'source' => 'google', 'campaign' => 'abc123',
    ]);
    expect($out)->toBe('google -> abc123'); // template text is left as-is; only values are escaped
});

test('render escapes html in values but leaves template markup intact', function (): void {
    $out = $this->reg->render(NotificationRegistry::AI_CLICK, '<b>{campaign}</b>', [
        'campaign' => 'a&b<x>',
    ]);
    expect($out)->toBe('<b>a&amp;b&lt;x&gt;</b>');
});

test('render falls back to the default template when override is empty', function (): void {
    $def = $this->reg->definitions()[NotificationRegistry::AI_CLICK]['default'];
    $rendered = $this->reg->render(NotificationRegistry::AI_CLICK, '', [
        'emoji' => '', 'source' => '', 'campaign' => '', 'route' => '',
        'offer_url' => '', 'country' => '', 'device' => '', 'click_id' => '', 'app_url' => '',
    ]);
    expect($def)->toContain('AI click');
    expect($rendered)->toContain('AI click');
});

test('render leaves unknown placeholders untouched', function (): void {
    $out = $this->reg->render(NotificationRegistry::AI_CLICK, '{source} {unknown}', ['source' => 'g']);
    expect($out)->toBe('g {unknown}');
});
