<?php

declare(strict_types=1);

namespace App\Pixel;

use App\Admin\Repository\SettingsRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /p.js — serves public/p.js with ETag caching.
 *
 * Headers:
 *   Content-Type:  application/javascript; charset=utf-8
 *   Cache-Control: public, max-age=300, stale-while-revalidate=60
 *   ETag:          "<md5-of-body>"
 * 304 on If-None-Match match; 503 if file is missing.
 */
final class ScriptController
{
    public function __construct(
        private readonly string $filePath,
        private readonly SettingsRepository $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!is_file($this->filePath)) {
            $response->getBody()->write('Service Unavailable: pixel script not found');
            return $response
                ->withStatus(503)
                ->withHeader('Content-Type', 'text/plain; charset=utf-8');
        }

        $rate   = $this->settings->getInt('rrweb_sample_rate', 100);
        $config = 'window.__slim=' . json_encode(['rate' => $rate]) . ";\n";
        $body   = $config . (string) file_get_contents($this->filePath);
        $etag = '"' . md5($body) . '"';

        // 304 if client already has this version
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            return $response
                ->withStatus(304)
                ->withHeader('Access-Control-Allow-Origin', '*');
        }

        $response->getBody()->write($body);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300, stale-while-revalidate=60')
            ->withHeader('ETag', $etag)
            ->withHeader('Access-Control-Allow-Origin', '*');
    }
}
