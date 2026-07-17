<?php

declare(strict_types=1);

namespace App\Admin\Form;

final class UrlMacroSampler
{
    /**
     * Reduce macro placeholders to a concrete sample so a macro-bearing template
     * can be validated as a real URL. {spin:a|b|c} → its first value (spin always
     * emits one of them, so the first is representative); any other {macro} or
     * {macro:arg} → the literal "macro".
     */
    public static function sample(string $url): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]*))?\}/',
            static function (array $m): string {
                if ($m[1] === 'spin') {
                    $first = trim(explode('|', $m[2] ?? '')[0]);
                    return $first !== '' ? $first : 'macro';
                }
                return 'macro';
            },
            $url,
        ) ?? $url;
    }
}
