<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class MetaRefreshSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $target = $outUrl !== null && $outUrl !== '' ? $outUrl : (string)($config['url'] ?? '');
        if ($target === '') return $response->withStatus(204);
        $delay = (int)($config['delay'] ?? 0);
        $url = htmlspecialchars($target, ENT_QUOTES);
        $body = "<!DOCTYPE html><html><head><meta http-equiv=\"refresh\" content=\"{$delay};url={$url}\"></head><body></body></html>";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
