<?php

declare(strict_types=1);

namespace App\Engine;

final class MacroExpander
{
    public function expand(string $template, Context $ctx): string
    {
        return preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]*))?\}/',
            fn (array $m) => $this->resolve($m[1], $m[2] ?? '', $ctx) ?? $m[0],
            $template,
        ) ?? $template;
    }

    private function resolve(string $name, string $arg, Context $ctx): ?string
    {
        return match ($name) {
            'country'      => $ctx->country,
            'region'       => $ctx->region,
            'city'         => $ctx->city,
            'device'       => $ctx->device,
            'os'           => $ctx->os,
            'browser'      => $ctx->browser,
            'bot'          => $ctx->botName,
            'lang'         => $ctx->lang,
            'ip'           => $ctx->ip,
            'ua'           => $ctx->userAgent,
            'referer'      => $ctx->referer,
            'click_id'     => $ctx->clickId,
            'visitor_uuid' => $ctx->visitorUuid,
            'campaign_slug' => $ctx->campaignSlug,
            'lander_host'  => $ctx->landerHost,
            'lander_domain' => $ctx->landerDomain,
            'lander_button' => $ctx->landerButton,
            'timestamp'    => (string)$ctx->timestamp,
            'utm_source'   => $ctx->utm['source']   ?? null,
            'utm_medium'   => $ctx->utm['medium']   ?? null,
            'utm_campaign' => $ctx->utm['campaign'] ?? null,
            'utm_term'     => $ctx->utm['term']     ?? null,
            'utm_content'  => $ctx->utm['content']  ?? null,
            'rand'         => $this->rand($arg),
            'randstr'      => $this->randStr($arg),
            'spin'         => $this->spin($arg),
            default        => null,
        };
    }

    /**
     * Uniform random pick from a pipe-separated list: {spin:a|b|c}.
     * Whitespace is trimmed, empty segments dropped, values substituted raw.
     * Returns '' when no valid values so the literal never leaks into a URL.
     * A skew is expressed by repeating a value: {spin:a|a|b} → 2/3 a.
     */
    private function spin(string $arg): string
    {
        $values = [];
        foreach (explode('|', $arg) as $v) {
            $v = trim($v);
            if ($v !== '') $values[] = $v;
        }
        if ($values === []) return '';
        return $values[random_int(0, count($values) - 1)];
    }

    private function rand(string $arg): string
    {
        if (!preg_match('/^(-?\d+)-(-?\d+)$/', $arg, $m)) return '';
        return (string)random_int((int)$m[1], (int)$m[2]);
    }

    private function randStr(string $arg): string
    {
        $len = max(1, min(64, (int)$arg ?: 8));
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
