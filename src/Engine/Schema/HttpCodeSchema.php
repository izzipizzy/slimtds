<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class HttpCodeSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $code = (int)($config['status_code'] ?? 200);
        if ($code < 100 || $code > 599) $code = 200;
        $body = (string)($config['body'] ?? '');
        if ($body !== '') $response->getBody()->write($body);
        return $response->withStatus($code);
    }
}
