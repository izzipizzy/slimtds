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

test('validateCustom accepts alias boundaries and the expanded ASCII alphabet', function (string $alias): void {
    expect($this->gen->validateCustom($alias))->toBeTrue();
})->with(['a', 'qz', 'ravo-nex', 'demo_01', str_repeat('a', 17), str_repeat('Z', 50), '-', '_', '0']);

test('validateCustom rejects empty and invalid custom aliases', function (string $alias): void {
    expect($this->gen->validateCustom($alias))->toBeFalse();
})->with(['', str_repeat('a', 51), 'a/b', 'a%2Fb', 'a b', ' a', 'a ', 'промо', "a\n", "a\0b", 'abc!', 'p.js']);

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

test('reserved words are not valid custom aliases', function (string $alias): void {
    expect((new \App\Shared\CampaignIdGenerator())->validateCustom($alias))->toBeFalse();
})->with(['mcp', 'MCP', 'admin', 'Postback', '__health', '__HEALTH']);

test('an alias that merely contains a reserved word is still valid', function (): void {
    expect((new \App\Shared\CampaignIdGenerator())->validateCustom('mcp2'))->toBeTrue();
});

test('existing reserved aliases remain editable without allowing new claims', function (string $alias): void {
    expect($this->gen->validateCustom(strtoupper($alias), $alias))->toBeTrue();
    expect($this->gen->validateCustom($alias, 'other'))->toBeFalse();
})->with(['admin', 'mcp', 'postback', '__health']);
