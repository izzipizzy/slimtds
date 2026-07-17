<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class HttpRedirectSchema implements Schema
{
    public function __construct(private readonly int $statusCode = 302)
    {
        if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            throw new \InvalidArgumentException('unsupported redirect status: ' . $statusCode);
        }
    }

    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        // Prefer offer URL (from OfferPicker), fall back to flow-config 'url'
        // (operator-set static target — useful for non-offer flows like "bots → google.com")
        $url = $outUrl !== null && $outUrl !== '' ? $outUrl : (string)($config['url'] ?? '');
        if ($url === '') {
            return $response->withStatus(204);
        }
        // Referrer-Policy: no-referrer keeps the partner from learning our
        // lander URL — the same privacy property the JS-redirect schema
        // (which uses <meta name="referrer">) provided. Operator can opt
        // out by setting `referrer_policy: keep` in the flow config.
        $policy = (string)($config['referrer_policy'] ?? 'no-referrer');
        $response = $response->withStatus($this->statusCode)->withHeader('Location', $url);
        if ($policy !== 'keep') {
            $response = $response->withHeader('Referrer-Policy', $policy);
        }
        return $response;
    }
}
