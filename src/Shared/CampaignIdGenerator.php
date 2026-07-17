<?php

declare(strict_types=1);

namespace App\Shared;

final class CampaignIdGenerator
{
    /** Base58 (Bitcoin) alphabet — excludes confusable 0/O/I/l. */
    public const ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /** Regex for custom aliases: 3–16 chars, ASCII letters and digits only. */
    public const CUSTOM_PATTERN = '/^[a-zA-Z0-9]{3,16}$/';

    public const DEFAULT_LENGTH = 6;
    public const MIN_LENGTH = 4;
    public const MAX_LENGTH = 12;

    /**
     * Generate a random Base58 slug of the given length.
     *
     * Uses `random_int` (CSPRNG). Length must be between MIN_LENGTH and MAX_LENGTH.
     */
    public function generate(int $length = self::DEFAULT_LENGTH): string
    {
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'length must be between %d and %d, got %d',
                self::MIN_LENGTH, self::MAX_LENGTH, $length,
            ));
        }
        $out = '';
        $max = strlen(self::ALPHABET) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** Validate a user-supplied custom alias against the allowed pattern. */
    public function validateCustom(string $alias): bool
    {
        return (bool)preg_match(self::CUSTOM_PATTERN, $alias);
    }
}
