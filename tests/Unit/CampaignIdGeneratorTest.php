<?php

declare(strict_types=1);

use App\Shared\CampaignIdGenerator;

beforeEach(function (): void {
    $this->gen = new CampaignIdGenerator();
});

test('generate returns a 6-char string by default', function (): void {
    $slug = $this->gen->generate();
    expect($slug)->toHaveLength(6);
});

test('generate honours custom length', function (): void {
    expect($this->gen->generate(4))->toHaveLength(4);
    expect($this->gen->generate(8))->toHaveLength(8);
    expect($this->gen->generate(12))->toHaveLength(12);
});

test('generate rejects too-short length', function (): void {
    $this->gen->generate(3);
})->throws(InvalidArgumentException::class);

test('generate rejects too-long length', function (): void {
    $this->gen->generate(13);
})->throws(InvalidArgumentException::class);

test('generate only uses alphabet characters', function (): void {
    $allowed = str_split(CampaignIdGenerator::ALPHABET);
    $forbidden = ['0', 'O', 'I', 'l'];
    for ($i = 0; $i < 1000; $i++) {
        $slug = $this->gen->generate();
        foreach (str_split($slug) as $ch) {
            expect($allowed, "unexpected char '$ch' in slug '$slug'")->toContain($ch);
            expect($forbidden, "forbidden char '$ch' in slug '$slug'")->not->toContain($ch);
        }
    }
});

test('generate produces diverse output (no duplicates in 10k runs)', function (): void {
    $seen = [];
    for ($i = 0; $i < 10_000; $i++) {
        $s = $this->gen->generate();
        expect(isset($seen[$s]), "collision at iter=$i slug=$s")->toBeFalse();
        $seen[$s] = true;
    }
})->group('slow');

test('validateCustom accepts valid aliases', function (): void {
    expect($this->gen->validateCustom('demo01'))->toBeTrue();
    expect($this->gen->validateCustom('abc'))->toBeTrue();
    expect($this->gen->validateCustom('BlackFriday2026'))->toBeTrue();
    expect($this->gen->validateCustom('ABC123XYZ'))->toBeTrue();
});

test('validateCustom rejects too short', function (): void {
    expect($this->gen->validateCustom(''))->toBeFalse();
    expect($this->gen->validateCustom('ab'))->toBeFalse();
});

test('validateCustom rejects too long', function (): void {
    expect($this->gen->validateCustom(str_repeat('a', 17)))->toBeFalse();
});

test('validateCustom rejects non-ASCII and special chars', function (): void {
    expect($this->gen->validateCustom('demo-01'))->toBeFalse();
    expect($this->gen->validateCustom('demo_01'))->toBeFalse();
    expect($this->gen->validateCustom('demo 01'))->toBeFalse();
    expect($this->gen->validateCustom('промо'))->toBeFalse();
    expect($this->gen->validateCustom('abc!'))->toBeFalse();
});

test('alphabet has exactly 58 chars', function (): void {
    expect(strlen(CampaignIdGenerator::ALPHABET))->toBe(58);
});

test('alphabet contains no confusable chars', function (): void {
    expect(CampaignIdGenerator::ALPHABET)
        ->not->toContain('0')
        ->not->toContain('O')
        ->not->toContain('I')
        ->not->toContain('l');
});
