<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

final class CurlProxySchema implements Schema
{
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface
    {
        $url = (string)($config['url'] ?? $outUrl ?? '');
        if ($url === '') return $response->withStatus(502);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => (int)($config['timeout'] ?? 10),
            CURLOPT_USERAGENT      => $ctx->userAgent,
            CURLOPT_HTTPHEADER     => ['Accept: */*'],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'text/html');
        curl_close($ch);

        if ($body === false) return $response->withStatus(502);
        $response->getBody()->write((string)$body);
        return $response->withStatus($code ?: 200)->withHeader('Content-Type', $type);
    }
}
