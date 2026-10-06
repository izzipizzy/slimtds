<?php

declare(strict_types=1);

use App\Admin\Repository\ClickRepository;
use App\Shared\Db\Connection;

const SUM_CAMPAIGN = '00000000-0000-7000-8000-0000000000c1';
const SUM_OFFER_A  = '00000000-0000-7000-8000-0000000000c2';
const SUM_OFFER_B  = '00000000-0000-7000-8000-0000000000c3';
const SUM_VISITOR  = '00000000-0000-7000-8000-0000000000c4';

beforeEach(function (): void {
    $this->pdo = pdo();
    $this->pdo->exec('DELETE FROM core.conversions');
    $this->pdo->exec('DELETE FROM stats.clicks');
    $this->db = new Connection($this->pdo);
    $this->repo = new ClickRepository($this->db);

    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active) VALUES (:id, 'clksum', 'click summary', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => SUM_CAMPAIGN],
    );
    foreach ([SUM_OFFER_A => 'Offer A', SUM_OFFER_B => 'Offer B'] as $id => $name) {
        $this->db->execute(
            "INSERT INTO core.offers (id, name, url, is_active) VALUES (:id, :name, 'https://offer.test/', true)
             ON CONFLICT (id) DO NOTHING",
            ['id' => $id, 'name' => $name],
        );
    }

    // 5 clicks: one visitor clicks twice; two share a fingerprint; one is a bot;
    // one came straight to the TDS (no lander) and fell through to trash (no offer).
    $rows = [
        ['k1', SUM_VISITOR, 'fp-1', 'ar', 'lander-a.test', SUM_OFFER_A, false],
        ['k2', SUM_VISITOR, 'fp-1', 'ar', 'lander-a.test', SUM_OFFER_A, false],
        ['k3', null,        'fp-2', 'cl', 'lander-a.test', SUM_OFFER_B, false],
        ['k4', null,        null,   'cl', 'lander-b.test', SUM_OFFER_A, true],
        ['k5', null,        null,   'ar', null,            null,        false],
    ];
    $this->ids = [];
    foreach ($rows as [$key, $visitor, $fp, $cc, $lander, $offer, $bot]) {
        $this->ids[$key] = (string)$this->db->fetchScalar(
            "INSERT INTO stats.clicks (campaign_id, visitor_uuid, ip, fp_js, country, lander_host, offer_id, is_bot, created_at)
             VALUES (:c, COALESCE(:v::uuid, uuidv7()), '1.1.1.1', :fp, :cc, :lander, :offer::uuid, :bot, now())
             RETURNING id",
            ['c' => SUM_CAMPAIGN, 'v' => $visitor, 'fp' => $fp, 'cc' => $cc, 'lander' => $lander, 'offer' => $offer, 'bot' => $bot ? 'true' : 'false'],
        );
    }
    foreach ([['k1', 'approved', '10.50'], ['k3', 'pending', '7.00']] as [$key, $status, $payout]) {
        $this->db->execute(
            "INSERT INTO core.conversions (click_id, campaign_id, offer_id, payout, status)
             VALUES (:k, :c, :o, :p, :s)",
            ['k' => $this->ids[$key], 'c' => SUM_CAMPAIGN, 'o' => SUM_OFFER_A, 'p' => $payout, 's' => $status],
        );
    }
    $this->all = ['campaign_id' => SUM_CAMPAIGN, 'is_trash' => 'all', 'bot_view' => 'all'];
});

test('summary counts clicks, distinct visitors and fingerprints, and the conversions of those clicks', function (): void {
    expect($this->repo->summary($this->all))->toBe([
        'clicks'        => 5,
        'uniq_visitors' => 4,
        'uniq_fp'       => 2,
        'conversions'   => 2,
        'approved'      => 1,
        'payout'        => '10.50',
    ]);
});

test('summary follows the filters — and always equals the list total', function (): void {
    $cl = $this->all + ['country' => 'cl'];
    $s = $this->repo->summary($cl);

    // Chile: k3 (pending conversion) and the bot k4 — the approved one is Argentine.
    expect($s['clicks'])->toBe(2)->and($s['conversions'])->toBe(1)->and($s['approved'])->toBe(0)->and($s['payout'])->toBe('0');

    foreach ([$this->all, $cl, ['bot_view' => 'hide'] + $this->all, ['is_trash' => 'hide'] + $this->all, $this->all + ['fp_js_has' => '1']] as $f) {
        expect($this->repo->summary($f)['clicks'])->toBe($this->repo->count($f));
    }
});

test('top landers and offers rank by clicks and leave out clicks that have neither', function (): void {
    expect($this->repo->topLanders($this->all))->toBe([
        ['label' => 'lander-a.test', 'clicks' => 3],
        ['label' => 'lander-b.test', 'clicks' => 1],
    ])->and($this->repo->topOffers($this->all))->toBe([
        ['label' => 'Offer A', 'clicks' => 3],
        ['label' => 'Offer B', 'clicks' => 1],
    ])->and($this->repo->topOffers($this->all, 1))->toHaveCount(1)
      ->and($this->repo->topLanders(['bot_view' => 'hide'] + $this->all))->toBe([['label' => 'lander-a.test', 'clicks' => 3]]);
});
