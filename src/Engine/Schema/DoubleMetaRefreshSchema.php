<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class DoubleMetaRefreshSchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $target = $outUrl !== null && $outUrl !== '' ? $outUrl : (string)($config['url'] ?? '');
        if ($target === '') return $response->withStatus(204);
        $url = htmlspecialchars($target, ENT_QUOTES);
        $body = '<!DOCTYPE html><html><head>'
              . '<meta name="referrer" content="no-referrer">'
              . "<meta http-equiv=\"refresh\" content=\"0;url={$url}\">"
              . '</head><body></body></html>';
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8')
                       ->withHeader('Referrer-Policy', 'no-referrer');
    }
}
