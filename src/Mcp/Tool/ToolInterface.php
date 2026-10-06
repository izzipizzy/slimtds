<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

interface ToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<string,mixed> JSON Schema of the arguments */
    public function inputSchema(): array;

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed> always a map — it becomes `structuredContent`
     * @throws ToolError for input the model can correct
     */
    public function call(array $args): array;
}
