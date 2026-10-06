<?php

declare(strict_types=1);

use App\Admin\Controller\ConversionController;
use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\ConversionRepository;
use App\Shared\Asset\Manifest;
use App\Shared\CampaignIdGenerator;
use App\Shared\Db\Connection;
use App\Shared\I18n\I18n;
use App\Shared\I18n\TranslatorFactory;
use App\Shared\View\View;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

const CONVRANGE_CAMPAIGN = '00000000-0000-7000-8000-0000000000e1';

/**
 * Day strings come from the database, not PHP, so the test cannot disagree
 * with the session time zone about where midnight is.
 */
beforeEach(function (): void {
    $_SESSION = [];
    $this->db = new Connection(pdo());
    $this->db->execute('DELETE FROM core.conversions');
    $this->db->execute(
        "INSERT INTO core.campaigns (id, slug, name, is_active)
         VALUES (:id, 'convrng', 'conversion range', true)
         ON CONFLICT (id) DO NOTHING",
        ['id' => CONVRANGE_CAMPAIGN],
    );
    $this->repo = new ConversionRepository($this->db);

    $day = fn (int $n): string => (string)$this->db->fetchScalar("SELECT (current_date - {$n})::text");
    [$this->d3, $this->d2, $this->d1] = [$day(3), $day(2), $day(1)];

    // d3 noon · d2 first instant · d2 last second · d1 first instant · 60 days back
    $stamps = [
        'd3_noon'  => ["{$this->d3} 12:00:00", 'approved', '5.00'],
        'd2_start' => ["{$this->d2} 00:00:00", 'approved', '2.50'],
        'd2_end'   => ["{$this->d2} 23:59:59", 'pending',  '1.00'],
        'd1_start' => ["{$this->d1} 00:00:00", 'approved', '9.00'],
        'old'      => [$day(60) . ' 12:00:00', 'approved', '100.00'],
    ];
    foreach ($stamps as $label => [$ts, $status, $payout]) {
        $this->db->execute(
            'INSERT INTO core.conversions (campaign_id, payout, status, external_id, created_at)
             VALUES (:c, :p, :s, :label, :ts::timestamptz)',
            ['c' => CONVRANGE_CAMPAIGN, 'p' => $payout, 's' => $status, 'label' => $label, 'ts' => $ts],
        );
    }
    $this->labels = function (array $filters): array {
        $labels = array_map(static fn (array $r): string => (string)$r['external_id'], $this->repo->page(1, 50, $filters));
        sort($labels);
        return $labels;
    };
});

test('from/to are inclusive days — the last second of `to` is in, the next midnight is out', function (): void {
    $f = ['from' => $this->d2, 'to' => $this->d2];
    expect(($this->labels)($f))->toBe(['d2_end', 'd2_start']);
    expect($this->repo->count($f))->toBe(2);
});

test('the status cards follow the period', function (): void {
    $b = $this->repo->statusBreakdown(['from' => $this->d3, 'to' => $this->d2]);
    expect($b['approved'])->toBe(['count' => 2, 'payout' => '7.50']);
    expect($b['pending'])->toBe(['count' => 1, 'payout' => '1.00']);
});

test('a picked day wins over the legacy since, and a malformed day is ignored', function (): void {
    expect(($this->labels)(['from' => $this->d1, 'since' => '2000-01-01']))->toBe(['d1_start']);
    // Garbage falls back to the default 30-day window: everything but `old`.
    expect(($this->labels)(['from' => "2026-13-45'; DROP TABLE x"]))->toBe(['d1_start', 'd2_end', 'd2_start', 'd3_noon']);
});

test('with no period the list keeps its 30-day window', function (): void {
    expect(($this->labels)([]))->not->toContain('old');
});

test('earliestDay is the day of the oldest conversion', function (): void {
    expect($this->repo->earliestDay())->toBe((string)$this->db->fetchScalar('SELECT (current_date - 60)::text'));
    $this->db->execute('DELETE FROM core.conversions');
    expect($this->repo->earliestDay())->toBeNull();
});

describe('page', function (): void {
    beforeEach(function (): void {
        $root = dirname(__DIR__, 3);
        $this->view = new View(
            $root . '/resources/views',
            new Manifest($root . '/public/assets/manifest.json'),
            new I18n((new TranslatorFactory($root . '/resources/translations'))->create()),
        );
        $this->controller = new ConversionController($this->repo, new CampaignRepository($this->db, new CampaignIdGenerator()));
        $this->get = function (array $query): string {
            $req = (new ServerRequestFactory())->createServerRequest('GET', '/admin/conversions')->withQueryParams($query);
            return (string)$this->controller->index($req, new Response(), $this->view)->getBody();
        };
    });

    test('a picked range filters the list and stays in the status cards and the inputs', function (): void {
        $html = ($this->get)(['from' => $this->d2, 'to' => $this->d2]);
        expect($html)->toContain('d2_start')->toContain('d2_end')->not->toContain('d3_noon');
        expect($html)->toContain('name="from" value="' . $this->d2 . '"');
        expect($html)->toContain('from=' . $this->d2 . '&amp;to=' . $this->d2 . '&amp;status=approved');
    });

    test('the all preset reaches back to the first conversion and travels as a key, not as dates', function (): void {
        $html = ($this->get)(['range' => 'all']);
        expect($html)->toContain('>old<');
        expect($html)->toContain('name="range" value="all"');
        expect($html)->toContain('range=all&amp;status=approved');
        expect($html)->not->toContain('name="from"');
    });

    test('with nothing picked the inputs show the 30-day window without naming it', function (): void {
        $html = ($this->get)([]);
        expect($html)->not->toContain('name="from"')->not->toContain('name="range"');
        expect($html)->toContain('range=90d');
        expect($html)->not->toContain('>old<');
    });
});
