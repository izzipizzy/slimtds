<?php

declare(strict_types=1);

namespace App\Shared\Asset;

/**
 * Resolves asset paths via public/assets/manifest.json (produced by Bun build).
 * Format:
 *   { "app.js": "app.a1b2c3.js", "app.css": "app.x9y8.css" }
 * Falls back to passthrough if manifest is missing.
 */
final class Manifest
{
    /** @var array<string,string>|null */
    private ?array $manifest = null;

    public function __construct(private readonly string $manifestPath)
    {
    }

    public function url(string $name): string
    {
        $map = $this->load();
        $resolved = $map[$name] ?? $name;
        return '/assets/' . ltrim($resolved, '/');
    }

    /** @return array<string,string> */
    private function load(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }
        if (!is_file($this->manifestPath)) {
            return $this->manifest = [];
        }
        $raw = file_get_contents($this->manifestPath);
        if ($raw === false) {
            return $this->manifest = [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return $this->manifest = [];
        }
        /** @var array<string,string> $clean */
        $clean = array_filter($decoded, static fn ($v, $k) => is_string($k) && is_string($v), ARRAY_FILTER_USE_BOTH);
        return $this->manifest = $clean;
    }
}
