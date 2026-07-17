<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class JsonSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $body = $config['body'] ?? '';
        if (is_string($body)) {
            // Allow operator to enter raw JSON string OR a php-encoded string
            $decoded = json_decode($body, true);
            $payload = $decoded === null ? $body : json_encode($decoded, JSON_UNESCAPED_UNICODE);
        } else {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $response->getBody()->write((string)$payload);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
