<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tool\ToolInterface;

final class ToolRegistry
{
    /** @var array<string,ToolInterface> */
    private array $tools = [];

    public function __construct(ToolInterface ...$tools)
    {
        foreach ($tools as $tool) {
            $this->tools[$tool->name()] = $tool;
        }
    }

    public function get(string $name): ?ToolInterface
    {
        return $this->tools[$name] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function describe(): array
    {
        return array_values(array_map(static fn (ToolInterface $t): array => [
            'name'        => $t->name(),
            'description' => $t->description(),
            'inputSchema' => $t->inputSchema(),
            'annotations' => ['readOnlyHint' => true, 'openWorldHint' => false],
        ], $this->tools));
    }
}
