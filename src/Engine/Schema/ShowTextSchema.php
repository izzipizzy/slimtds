<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class ShowTextSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write((string)($config['body'] ?? ''));
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    }
}
