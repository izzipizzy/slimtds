<?php

declare(strict_types=1);

namespace App\Admin\Form;

use App\Admin\Repository\CampaignRepository;
use App\Shared\CampaignIdGenerator;

final class CampaignForm
{
    public function __construct(
        private readonly CampaignRepository $repo,
        private readonly CampaignIdGenerator $idGen,
    ) {}

    /**
     * Validate form data. Returns list of errors keyed by field.
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    public function validate(array $data, ?string $updatingId = null): array
    {
        $errors = [];

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'validation.required';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'validation.max_length';
        }

        $slug = trim((string)($data['slug'] ?? ''));
        if ($slug !== '') {
            // custom slug — validate format
            if (!$this->idGen->validateCustom($slug)) {
                $errors['slug'] = 'validation.pattern';
            } elseif ($this->repo->slugExists($slug, $updatingId)) {
                $errors['slug'] = 'campaigns.slug_taken';
            }
        }

        $trashMode = (int)($data['trash_mode'] ?? 0);
        if ($trashMode < 0 || $trashMode > 7) {
            $errors['trash_mode'] = 'validation.pattern';
        }

        // Modes that need a URL: 1=302, 4=301, 5=307, 6=meta refresh, 7=JS redirect
        if (in_array($trashMode, [1, 4, 5, 6, 7], true)) {
            $url = trim((string)($data['trash_url'] ?? ''));
            // {offer:<name|id>} references an offer's URL — a valid reference, not a URL.
            $isOfferRef = (bool)preg_match('/^\{offer:[^}]+\}$/', $url);
            if ($url === '' || (!$isOfferRef && !filter_var(UrlMacroSampler::sample($url), FILTER_VALIDATE_URL))) {
                $errors['trash_url'] = 'validation.pattern';
            }
        }

        return $errors;
    }
}
