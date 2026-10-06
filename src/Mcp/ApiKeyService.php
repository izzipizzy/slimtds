<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Admin\Repository\SettingsRepository;

/**
 * The single MCP key of the instance. Only its SHA-256 is kept: the secret is
 * 40 random base62 characters, so a salt or a slow hash would add nothing.
 */
final class ApiKeyService
{
    public const PREFIX = 'stds_';
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    private const SECRET_LENGTH = 40;
    private const VISIBLE_PREFIX = 9;

    public function __construct(private readonly SettingsRepository $settings) {}

    public function isEnabled(): bool
    {
        return $this->settings->get('mcp_key_hash') !== '';
    }

    /** Returns the plaintext key. This is the only moment it exists outside the client. */
    public function generate(): string
    {
        $secret = '';
        for ($i = 0; $i < self::SECRET_LENGTH; $i++) {
            $secret .= self::ALPHABET[random_int(0, 61)];
        }
        $key = self::PREFIX . $secret;
        $this->settings->set('mcp_key_hash', hash('sha256', $key));
        $this->settings->set('mcp_key_prefix', substr($key, 0, self::VISIBLE_PREFIX));
        $this->settings->set('mcp_key_created_at', gmdate('c'));
        $this->settings->set('mcp_key_last_used_at', '');
        return $key;
    }

    public function revoke(): void
    {
        foreach (['mcp_key_hash', 'mcp_key_prefix', 'mcp_key_created_at', 'mcp_key_last_used_at'] as $k) {
            $this->settings->set($k, '');
        }
    }

    public function verify(string $presented): bool
    {
        $hash = $this->settings->get('mcp_key_hash');
        return $hash !== '' && hash_equals($hash, hash('sha256', $presented));
    }

    public function touch(): void
    {
        $last = strtotime($this->settings->get('mcp_key_last_used_at')) ?: 0;
        if (time() - $last >= 60) {
            $this->settings->set('mcp_key_last_used_at', gmdate('c'));
        }
    }

    /** @return array{enabled:bool, prefix:string, created_at:string, last_used_at:string} */
    public function status(): array
    {
        return [
            'enabled'      => $this->isEnabled(),
            'prefix'       => $this->settings->get('mcp_key_prefix'),
            'created_at'   => $this->settings->get('mcp_key_created_at'),
            'last_used_at' => $this->settings->get('mcp_key_last_used_at'),
        ];
    }

    public function fullIp(): bool
    {
        return $this->settings->getBool('mcp_full_ip', false);
    }

    public function setFullIp(bool $on): void
    {
        $this->settings->set('mcp_full_ip', $on ? '1' : '0');
    }
}
