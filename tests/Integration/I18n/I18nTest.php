<?php

declare(strict_types=1);

use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;

beforeEach(function (): void {
    $factory = new TranslatorFactory(dirname(__DIR__, 3) . '/resources/translations');
    $this->i18n = new I18n($factory->create());
});

test('default locale is ru', function (): void {
    expect($this->i18n->locale())->toBe('ru');
    expect($this->i18n->t('auth.login'))->toBe('Вход');
});

test('switch to en', function (): void {
    $this->i18n->setLocale('en');
    expect($this->i18n->locale())->toBe('en');
    expect($this->i18n->t('auth.login'))->toBe('Sign in');
});

test('unsupported locale throws', function (): void {
    $this->i18n->setLocale('fr');
})->throws(InvalidArgumentException::class);

test('placeholder interpolation', function (): void {
    $this->i18n->setLocale('en');
    expect($this->i18n->t('validation.min_length', ['min' => 5]))->toBe('Minimum 5 characters');
});

test('missing key returns key itself (Symfony default)', function (): void {
    expect($this->i18n->t('no.such.key'))->toBe('no.such.key');
});

test('russian plural: one', function (): void {
    $this->i18n->setLocale('ru');
    expect($this->i18n->tn('campaigns.count', 1))->toBe('1 кампания');
});

test('russian plural: few', function (): void {
    $this->i18n->setLocale('ru');
    expect($this->i18n->tn('campaigns.count', 3))->toBe('3 кампании');
    expect($this->i18n->tn('campaigns.count', 22))->toBe('22 кампании');
});

test('russian plural: many', function (): void {
    $this->i18n->setLocale('ru');
    expect($this->i18n->tn('campaigns.count', 5))->toBe('5 кампаний');
    expect($this->i18n->tn('campaigns.count', 11))->toBe('11 кампаний');
    expect($this->i18n->tn('campaigns.count', 25))->toBe('25 кампаний');
});

test('russian plural: zero special', function (): void {
    $this->i18n->setLocale('ru');
    expect($this->i18n->tn('campaigns.count', 0))->toBe('нет кампаний');
});

test('english plural one/other', function (): void {
    $this->i18n->setLocale('en');
    expect($this->i18n->tn('campaigns.count', 1))->toBe('1 campaign');
    expect($this->i18n->tn('campaigns.count', 5))->toBe('5 campaigns');
    expect($this->i18n->tn('campaigns.count', 0))->toBe('no campaigns');
});
