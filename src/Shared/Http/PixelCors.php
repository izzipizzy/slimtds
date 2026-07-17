<?php

declare(strict_types=1);

namespace App\Shared\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * CORS helper shared by pixel endpoints (/p/event, /p/rec).
 * Echoes the request Origin so credentials work; falls back to '*'.
 */
final class PixelCors
{
    public static function apply(ServerRequestInterface $request, ResponseInterface $r): ResponseInterface
    {
        $origin = trim($request->getHeaderLine('Origin'));
        $allowOrigin = $origin !== '' ? $origin : '*';
        $r = $r
            ->withHeader('Access-Control-Allow-Origin', $allowOrigin)
            ->withHeader('Access-Control-Allow-Methods', 'POST, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
            ->withHeader('Access-Control-Max-Age', '86400')
            ->withHeader('Vary', 'Origin');
        if ($origin !== '') {
            $r = $r->withHeader('Access-Control-Allow-Credentials', 'true');
        }
        return $r;
    }
}
