<?php

declare(strict_types=1);

namespace App\Shared\Rrweb;

/** Recorded interaction is a heuristic, never proof that a visitor is human. */
final class Interaction
{
    /** @param array<mixed> $events */
    public static function exists(array $events): bool
    {
        foreach ($events as $event) {
            if (!is_array($event) || ($event['type'] ?? null) !== 3 || !is_array($event['data'] ?? null)) {
                continue;
            }
            $data = $event['data'];
            // Match the former pixel gate: mousemove/touchmove carry pointer positions.
            if (in_array($data['source'] ?? null, [1, 6], true) && !empty($data['positions'])) {
                return true;
            }
            // rrweb MouseUp/MouseDown/Click/TouchStart and legacy TouchMove.
            // Scroll, input, focus and automatic DOM changes do not qualify.
            if (($data['source'] ?? null) === 2 && in_array($data['type'] ?? null, [0, 1, 2, 7, 8], true)) {
                return true;
            }
        }
        return false;
    }
}
