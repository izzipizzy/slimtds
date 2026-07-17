<?php

declare(strict_types=1);

namespace App\Engine;

final class OfferPicker
{
    /**
     * @param list<array{offer_id:string,weight:int}> $candidates
     * @param bool $sticky true → consistent-hash by visitor_uuid (one visitor always
     *   lands on the same offer); false → weighted random per click (rotate by weight).
     * @return string|null  selected offer_id, null if list empty or sum=0
     */
    public function pick(array $candidates, Context $ctx, bool $sticky = true): ?string
    {
        $sum = 0;
        $valid = [];
        foreach ($candidates as $c) {
            $w = (int)($c['weight'] ?? 0);
            $oid = (string)($c['offer_id'] ?? '');
            if ($w <= 0 || $oid === '') continue;
            $valid[] = ['offer_id' => $oid, 'weight' => $w];
            $sum += $w;
        }
        if ($sum === 0) return null;

        if ($sticky) {
            // Stable: hash visitor_uuid into [0, $sum) — one visitor → one offer.
            $seed = $ctx->visitorUuid ?? bin2hex(random_bytes(8));
            $h = hexdec(substr(hash('xxh3', $seed), 0, 12));
            $point = $h % $sum;
        } else {
            // Rotate: independent weighted random each click.
            $point = random_int(0, $sum - 1);
        }

        $running = 0;
        foreach ($valid as $c) {
            $running += $c['weight'];
            if ($point < $running) {
                $ctx->matchedOfferId = $c['offer_id'];
                return $c['offer_id'];
            }
        }
        return $valid[count($valid) - 1]['offer_id'];
    }
}
