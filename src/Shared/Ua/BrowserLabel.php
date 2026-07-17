<?php

declare(strict_types=1);

namespace App\Shared\Ua;

/**
 * Display-only browser label with major version, e.g. "Chrome 174".
 *
 * Distinct from Engine\DeviceDetector (which the money-path uses and which only
 * needs the browser *name*): this also extracts the version and recognises the
 * iOS browser variants (CriOS/FxiOS/EdgiOS/OPiOS), where the engine detector
 * would report "Safari". Used purely for the admin sessions list.
 */
final class BrowserLabel
{
    public static function make(string $ua): ?string
    {
        if ($ua === '') {
            return null;
        }
        // [name, regex] in priority order. iOS variants and Edge/Opera come
        // before Chrome because their UA strings also contain "Chrome/".
        $checks = [
            ['Edge',    '#\bEd(?:g|giOS|gA)/(\d+)#'],
            ['Opera',   '#\b(?:OPR|OPiOS|Opera)/(\d+)#'],
            ['Firefox', '#\b(?:Firefox|FxiOS)/(\d+)#'],
            ['Chrome',  '#\b(?:CriOS|Chrome)/(\d+)#'],
            ['Safari',  '#\bVersion/(\d+)#'], // Safari's real version is in Version/, not Safari/
        ];
        foreach ($checks as [$name, $re]) {
            if (preg_match($re, $ua, $m) === 1) {
                return $name . ' ' . $m[1];
            }
        }
        if (str_contains($ua, 'Safari/')) {
            return 'Safari';
        }
        return null;
    }
}
