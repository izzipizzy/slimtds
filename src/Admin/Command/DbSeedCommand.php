<?php

declare(strict_types=1);

namespace App\Admin\Command;

use App\Admin\Repository\CampaignRepository;
use App\Admin\Repository\FlowRepository;
use App\Admin\Repository\OfferRepository;
use App\Shared\Db\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:seed', description: 'Populate database with sample data for dev')]
final class DbSeedCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        private readonly CampaignRepository $campaigns,
        private readonly OfferRepository $offers,
        private readonly FlowRepository $flows,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('fresh', null, InputOption::VALUE_NONE, 'Drop existing campaigns/offers/flows before seeding');
    }

    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        if ((bool)$in->getOption('fresh')) {
            $out->writeln('<comment>--fresh: wiping campaigns/offers/flows</comment>');
            $this->db->execute('DELETE FROM core.flows');
            $this->db->execute('DELETE FROM core.offers');
            $this->db->execute('DELETE FROM core.campaigns');
        }

        if ($this->campaigns->count() > 0 && !$in->getOption('fresh')) {
            $out->writeln('<comment>campaigns already present; re-run with --fresh to overwrite</comment>');
            return self::SUCCESS;
        }

        $out->writeln('<info>creating campaigns…</info>');
        $casino = $this->campaigns->create([
            'name' => 'iGaming / Casino', 'slug' => 'casino', 'is_active' => '1',
            'trash_mode' => 2, // 403 for non-matching geo
            'notes' => 'Tier-1 + CIS casino. Geo-routed, high payout.',
        ]);
        $dating = $this->campaigns->create([
            'name' => 'Dating Smartlink', 'slug' => 'dating', 'is_active' => '1',
            'trash_mode' => 0,
            'notes' => 'Mobile-first dating smartlink. A/B 50/50, bots → 200 blank.',
        ]);
        $nutra = $this->campaigns->create([
            'name' => 'Nutra COD', 'slug' => 'nutra', 'is_active' => '1',
            'trash_mode' => 0,
            'notes' => 'Single-geo COD funnel. Reuses CIS Casino as fallback.',
        ]);
        $this->campaigns->create([
            'name' => 'slimTDS Site', 'slug' => 'site', 'is_active' => '1',
            'trash_mode' => 0,
            'notes' => 'Pixel-only: web analytics for the slimTDS site itself. No redirect traffic.',
        ]);

        $out->writeln('<info>creating offers (global)…</info>');
        $cisCasino = $this->offers->create(['name' => 'CIS Casino', 'url' => 'https://example.com/cis-casino?subid={click_id}&c={country}', 'payout_default' => '40.00', 'currency' => 'USD', 'is_active' => '1']);
        $euCasino  = $this->offers->create(['name' => 'EU Casino',  'url' => 'https://example.com/eu-casino?subid={click_id}&c={country}',  'payout_default' => '60.00', 'currency' => 'USD', 'is_active' => '1']);
        $datingA   = $this->offers->create(['name' => 'Dating A',   'url' => 'https://example.com/dating-a?subid={click_id}',           'payout_default' => '4.00',  'currency' => 'USD', 'is_active' => '1']);
        $datingB   = $this->offers->create(['name' => 'Dating B',   'url' => 'https://example.com/dating-b?subid={click_id}',           'payout_default' => '6.00',  'currency' => 'USD', 'is_active' => '1']);
        $nutraCod  = $this->offers->create(['name' => 'Nutra COD',  'url' => 'https://example.com/nutra-cod?subid={click_id}&geo={country}', 'payout_default' => '12.00', 'currency' => 'USD', 'is_active' => '1']);

        $out->writeln('<info>creating flows…</info>');
        // casino: CIS geo → CIS Casino, Tier-1 → EU Casino, else 403 (trash_mode=2)
        $this->flows->create($casino->id, [
            'name' => 'CIS → CIS Casino',
            'filters' => [[['field' => 'country', 'op' => 'in', 'value' => 'RU,UA,KZ,BY']]],
            'target_type' => 'offers',
            'target_offers' => [['offer_id' => $cisCasino->id, 'weight' => 100]],
            'schema_id' => 2, 'schema_config' => [], 'is_active' => '1',
        ]);
        $this->flows->create($casino->id, [
            'name' => 'Tier-1 → EU Casino',
            'filters' => [[['field' => 'country', 'op' => 'in', 'value' => 'DE,US,GB']]],
            'target_type' => 'offers',
            'target_offers' => [['offer_id' => $euCasino->id, 'weight' => 100]],
            'schema_id' => 2, 'schema_config' => [], 'is_active' => '1',
        ]);

        // dating: bots → 200 blank, then A/B 50/50, plus a Meta Refresh demo variant
        $this->flows->create($dating->id, [
            'name' => 'Bots → 200 blank',
            'filters' => [[['field' => 'is_bot', 'op' => 'eq', 'value' => '1']]],
            'target_type' => 'none', 'target_offers' => [],
            'schema_id' => 13, 'schema_config' => [], 'is_active' => '1',
        ]);
        $this->flows->create($dating->id, [
            'name' => 'Split 50/50 A/B',
            'filters' => [],
            'target_type' => 'offers',
            'target_offers' => [
                ['offer_id' => $datingA->id, 'weight' => 50],
                ['offer_id' => $datingB->id, 'weight' => 50],
            ],
            'schema_id' => 2, 'schema_config' => [], 'is_active' => '1',
        ]);
        // Intentionally inactive — demonstrates the Meta Refresh schema (id 6) only; do not activate (open catch-all).
        $this->flows->create($dating->id, [
            'name' => 'Meta refresh variant',
            'filters' => [],
            'target_type' => 'offers',
            'target_offers' => [['offer_id' => $datingA->id, 'weight' => 100]],
            'schema_id' => 6, 'schema_config' => [], 'is_active' => '0',
        ]);

        // nutra: MX + mobile → Nutra COD, fallback → CIS Casino (cross-campaign reuse)
        $this->flows->create($nutra->id, [
            'name' => 'MX + mobile → Nutra COD',
            'filters' => [[
                ['field' => 'country', 'op' => 'eq', 'value' => 'MX'],
                ['field' => 'device', 'op' => 'eq', 'value' => 'mobile'],
            ]],
            'target_type' => 'offers',
            'target_offers' => [['offer_id' => $nutraCod->id, 'weight' => 100]],
            'schema_id' => 2, 'schema_config' => [], 'is_active' => '1',
        ]);
        $this->flows->create($nutra->id, [
            'name' => 'Fallback → CIS Casino (reuse)',
            'filters' => [],
            'target_type' => 'offers',
            'target_offers' => [['offer_id' => $cisCasino->id, 'weight' => 100]],
            'schema_id' => 2, 'schema_config' => [], 'is_active' => '1',
        ]);

        // site: pixel-only, no flows.

        $campaignCount = $this->campaigns->count();
        $offerCount = $this->db->fetchScalar('SELECT count(*) FROM core.offers');
        $flowCount = $this->db->fetchScalar('SELECT count(*) FROM core.flows');
        $out->writeln("<info>done:</info> {$campaignCount} campaigns, {$offerCount} offers, {$flowCount} flows");
        return self::SUCCESS;
    }
}
