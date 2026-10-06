<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

final class Schema
{
    /**
     * @param array<string,array<string,mixed>> $properties
     * @param list<string> $required
     * @return array<string,mixed>
     */
    public static function object(array $properties, array $required = []): array
    {
        return [
            'type'                 => 'object',
            // An empty PHP array would encode as [], which is not a valid schema.
            'properties'           => $properties === [] ? new \stdClass() : $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }
}
