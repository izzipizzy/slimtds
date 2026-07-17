<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class HtmlPageSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $body = (string)($config['body'] ?? '');
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
