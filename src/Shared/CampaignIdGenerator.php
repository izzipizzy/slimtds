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
     * Words that are routes of their own. FastRoute prefers a static route, so
     * a campaign with one of these aliases would exist and never be reachable.
     */
    public const RESERVED = ['mcp', 'admin', 'postback'];

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
        $max = strlen(self::ALPHABET) - 1;
        // A random slug landing on a reserved word is astronomically
        // unlikely at the default length, but not impossible — loop rather
        // than hand out a slug that would never be reachable.
        do {
            $out = '';
            for ($i = 0; $i < $length; $i++) {
                $out .= self::ALPHABET[random_int(0, $max)];
            }
        } while (in_array(strtolower($out), self::RESERVED, true));
        return $out;
    }

    /**
     * Validate a user-supplied custom alias against the allowed pattern.
     *
     * $currentSlug is the campaign's own slug when validating an update —
     * an existing campaign already using a reserved word (grandfathered in
     * from before it was reserved, or created directly) must stay editable
     * under that same slug; the reserved check only blocks newly *claiming*
     * one of these words, not keeping one you already have.
     */
    public function validateCustom(string $alias, ?string $currentSlug = null): bool
    {
        if (!preg_match(self::CUSTOM_PATTERN, $alias)) {
            return false;
        }
        if ($currentSlug !== null && strtolower($alias) === strtolower($currentSlug)) {
            return true;
        }
        return !in_array(strtolower($alias), self::RESERVED, true);
    }
}
