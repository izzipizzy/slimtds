<?php

declare(strict_types=1);

namespace App\Engine;

use GeoIp2\Database\Reader;
use GeoIp2\Exception\AddressNotFoundException;

final class GeoLookup
{
    private ?Reader $countryReader = null;
    private ?Reader $cityReader = null;
    private ?Reader $asnReader = null;

    public function __construct(private readonly string $databasesDir = '/usr/share/GeoIP') {}

    public function lookup(Context $ctx): void
    {
        $ip = $ctx->ip;
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return;
        }

        try {
            $city = $this->city()?->city($ip);
            if ($city !== null) {
                $ctx->country = $city->country->isoCode ? strtolower($city->country->isoCode) : null;
                $ctx->city = $city->city->name ? strtolower($city->city->name) : null;
                // Prefer human-readable subdivision name (e.g. "Paris", "Uusimaa") over the
                // numeric/letter ISO subdivision code (e.g. "75", "18"). MaxMind's `name`
                // is locale-aware via locales=['en']; isoCode kept as fallback.
                $sub = $city->mostSpecificSubdivision;
                $ctx->region = $sub->name
                    ? strtolower($sub->name)
                    : ($sub->isoCode ? strtolower($sub->isoCode) : null);
            }
        } catch (AddressNotFoundException) {
            // Ignore — fall through.
        }

        try {
            $asn = $this->asn()?->asn($ip);
            if ($asn !== null) {
                $ctx->asn = $asn->autonomousSystemNumber;
                $ctx->isp = $asn->autonomousSystemOrganization;
            }
        } catch (AddressNotFoundException) {
            // Ignore.
        }
    }

    public function isAvailable(): bool
    {
        return is_file($this->databasesDir . '/GeoLite2-City.mmdb')
            && is_file($this->databasesDir . '/GeoLite2-ASN.mmdb');
    }

    private function city(): ?Reader
    {
        if ($this->cityReader !== null) return $this->cityReader;
        $path = $this->databasesDir . '/GeoLite2-City.mmdb';
        return is_file($path) ? ($this->cityReader = new Reader($path)) : null;
    }

    private function asn(): ?Reader
    {
        if ($this->asnReader !== null) return $this->asnReader;
        $path = $this->databasesDir . '/GeoLite2-ASN.mmdb';
        return is_file($path) ? ($this->asnReader = new Reader($path)) : null;
    }
}
