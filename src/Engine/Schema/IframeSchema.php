<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class IframeSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $target = $outUrl !== null && $outUrl !== '' ? $outUrl : (string)($config['url'] ?? '');
        if ($target === '') return $response->withStatus(204);
        $url = htmlspecialchars($target, ENT_QUOTES);
        $body = "<!DOCTYPE html><html><body style=\"margin:0\"><iframe src=\"{$url}\" style=\"border:0;width:100vw;height:100vh\"></iframe></body></html>";
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
