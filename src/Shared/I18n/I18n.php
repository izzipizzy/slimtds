<?php

declare(strict_types=1);

namespace App\Shared\I18n;

use Symfony\Component\Translation\Translator;

final class I18n
{
    private string $locale;

    public function __construct(
        private readonly Translator $translator,
    ) {
        $this->locale = TranslatorFactory::DEFAULT;
    }

    public function setLocale(string $locale): void
    {
        if (!in_array($locale, TranslatorFactory::SUPPORTED, true)) {
            throw new \InvalidArgumentException("unsupported locale: {$locale}");
        }
        $this->locale = $locale;
        $this->translator->setLocale($locale);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string,int|string|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        $prefixedParams = [];
        foreach ($params as $k => $v) {
            $prefixedParams['{' . $k . '}'] = (string)$v;
        }
        return $this->translator->trans($key, $prefixedParams, null, $this->locale);
    }

    /**
     * Plural choice (ICU-free, uses Symfony's transChoice fallback via trans with %count%).
     * @param array<string,int|string|float> $params
     */
    public function tn(string $key, int $count, array $params = []): string
    {
        $params['count'] = $count;
        $prefixedParams = [];
        foreach ($params as $k => $v) {
            $prefixedParams['{' . $k . '}'] = (string)$v;
        }
        // Symfony 7 uses MessageSelector/translator-style pluralization with the 'intl-icu' format;
        // for simple yaml with keys 'one'/'other', call trans with explicit sub-key chosen by plural rule.
        $plural = $this->plural($count, $this->locale);
        $fullKey = "{$key}.{$plural}";
        $result = $this->translator->trans($fullKey, $prefixedParams, null, $this->locale);
        // fallback to 'other' if the chosen plural key doesn't exist (trans returns the key as-is on miss)
        if ($result === $fullKey && $plural !== 'other') {
            $result = $this->translator->trans("{$key}.other", $prefixedParams, null, $this->locale);
        }
        return $result;
    }

    private function plural(int $count, string $locale): string
    {
        if ($locale === 'ru') {
            // Russian plural rules (CLDR)
            $mod10 = $count % 10;
            $mod100 = $count % 100;
            if ($count === 0)                     return 'zero';
            if ($mod10 === 1 && $mod100 !== 11)   return 'one';
            if ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) return 'few';
            return 'many';
        }
        // English-like
        return match (true) {
            $count === 0 => 'zero',
            $count === 1 => 'one',
            default      => 'other',
        };
    }
}
