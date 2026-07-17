<?php

declare(strict_types=1);

namespace App\Admin\Form;

final class OfferForm
{
    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public function validate(array $data): array
    {
        $errors = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'validation.required';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'validation.max_length';
        }

        $url = trim((string)($data['url'] ?? ''));
        if ($url === '') {
            $errors['url'] = 'validation.required';
        } elseif (!filter_var(UrlMacroSampler::sample($url), FILTER_VALIDATE_URL)) {
            $errors['url'] = 'validation.pattern';
        }

        $payout = (string)($data['payout_default'] ?? '');
        if ($payout !== '' && !is_numeric($payout)) {
            $errors['payout_default'] = 'validation.pattern';
        }

        $currency = (string)($data['currency'] ?? 'USD');
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            $errors['currency'] = 'validation.pattern';
        }

        // Validate postback_urls_raw — one URL per line, macros allowed
        $raw = trim((string)($data['postback_urls_raw'] ?? ''));
        if ($raw !== '') {
            $lines = array_filter(
                array_map('trim', explode("\n", str_replace("\r", '', $raw))),
                static fn (string $l) => $l !== '',
            );
            $parsed = [];
            foreach ($lines as $line) {
                // Reduce macros to a concrete sample, then validate (same as offer URL)
                if (!filter_var(UrlMacroSampler::sample($line), FILTER_VALIDATE_URL)) {
                    $errors['postback_urls'] = 'validation.pattern';
                    break;
                }
                $parsed[] = $line;
            }
            if (!isset($errors['postback_urls'])) {
                $data['postback_urls'] = $parsed;
            }
        } else {
            $data['postback_urls'] = [];
        }

        return $errors;
    }

    /**
     * Extract the validated postback_urls array from submitted form data.
     * Must be called after validate() succeeds.
     *
     * @param  array<string,mixed> $data
     * @return list<string>
     */
    public function extractPostbackUrls(array $data): array
    {
        $raw = trim((string)($data['postback_urls_raw'] ?? ''));
        if ($raw === '') {
            return [];
        }
        $lines = array_filter(
            array_map('trim', explode("\n", str_replace("\r", '', $raw))),
            static fn (string $l) => $l !== '',
        );
        return array_values($lines);
    }
}
