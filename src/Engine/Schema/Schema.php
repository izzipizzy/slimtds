<?php

declare(strict_types=1);

namespace App\Engine\Schema;

use App\Engine\Context;
use Psr\Http\Message\ResponseInterface;

interface Schema
{
    /**
     * Render the engine response.
     *
     * @param ?string $outUrl  expanded final URL of the chosen offer (null when target_type=none)
     * @param array<string,mixed> $config  raw schema_config jsonb decoded as array
     */
    public function respond(Context $ctx, ?string $outUrl, array $config, ResponseInterface $response): ResponseInterface;
}
