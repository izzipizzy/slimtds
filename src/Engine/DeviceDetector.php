<?php

declare(strict_types=1);

namespace App\Engine;

use Detection\MobileDetect;

final class DeviceDetector
{
    /** Mutates $ctx — sets device/os/browser/lang. */
    public function detect(Context $ctx, string $acceptLanguage = ''): void
    {
        $md = new MobileDetect();
        $md->setUserAgent($ctx->userAgent);

        if ($md->isTablet()) {
            $ctx->device = 'tablet';
        } elseif ($md->isMobile()) {
            $ctx->device = 'mobile';
        } else {
            $ctx->device = 'desktop';
        }

        $ctx->os = $this->detectOs($ctx->userAgent);
        $ctx->browser = $this->detectBrowser($ctx->userAgent);
        $ctx->lang = $acceptLanguage !== '' ? strtolower(substr($acceptLanguage, 0, 2)) : null;
    }

    private function detectOs(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')         => 'iOS',
            str_contains($ua, 'Android')                                     => 'Android',
            str_contains($ua, 'Windows')                                     => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux')                                       => 'Linux',
            default                                                           => null,
        };
    }

    private function detectBrowser(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Edg/')                                        => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')         => 'Opera',
            str_contains($ua, 'Firefox/')                                    => 'Firefox',
            str_contains($ua, 'Chrome/')                                     => 'Chrome',
            str_contains($ua, 'Safari/')                                     => 'Safari',
            default                                                           => null,
        };
    }
}
