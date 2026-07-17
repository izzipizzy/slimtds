<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

/** Free-form template body (admin enters raw HTML/JS with macros already expanded by caller). */
final class FormulaSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $body = (string)($config['body'] ?? '');
        $type = (string)($config['content_type'] ?? 'text/html; charset=utf-8');
        $code = (int)($config['status_code'] ?? 200);
        $response->getBody()->write($body);
        return $response->withStatus($code)->withHeader('Content-Type', $type);
    }
}
