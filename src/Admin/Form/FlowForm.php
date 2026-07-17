<?php

declare(strict_types=1);

namespace App\Admin\Form;

final class FlowForm
{
    /** @param array<string,mixed> $data  @return array<string,string> */
    public function validate(array $data): array
    {
        $errors = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'validation.required';
        }

        $schemaId = (int)($data['schema_id'] ?? 0);
        if ($schemaId < 1 || $schemaId > 16) {
            $errors['schema_id'] = 'validation.pattern';
        }

        $targetType = (string)($data['target_type'] ?? 'offers');
        if (!in_array($targetType, ['offers', 'none'], true)) {
            $errors['target_type'] = 'validation.pattern';
        }

        // filters JSON must decode
        $filtersRaw = (string)($data['filters'] ?? '[]');
        $filters = json_decode($filtersRaw, true);
        if (!is_array($filters)) {
            $errors['filters'] = 'validation.pattern';
        }

        // target_offers JSON must decode
        $toRaw = (string)($data['target_offers'] ?? '[]');
        $to = json_decode($toRaw, true);
        if (!is_array($to)) {
            $errors['target_offers'] = 'validation.pattern';
        }

        $weight = (int)($data['weight'] ?? 100);
        if ($weight < 0 || $weight > 1000) {
            $errors['weight'] = 'validation.pattern';
        }

        return $errors;
    }
}
