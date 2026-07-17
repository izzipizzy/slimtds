<?php

declare(strict_types=1);

namespace App\Shared\Auth;

use App\Shared\Db\Connection;

final class AuthEventLogger
{
    public const EVENT_LOGIN_SUCCESS   = 'login_success';
    public const EVENT_LOGIN_FAIL      = 'login_fail';
    public const EVENT_PASSWORD_CHANGE = 'password_change';
    public const EVENT_LOGOUT          = 'logout';
    public const EVENT_RATE_LIMITED    = 'rate_limited';

    public function __construct(private readonly Connection $db) {}

    /**
     * @param array<string,mixed> $details  Arbitrary structured metadata (stored as jsonb)
     */
    public function log(
        string $eventType,
        ?string $adminLogin = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $details = [],
    ): void {
        $valid = [
            self::EVENT_LOGIN_SUCCESS,
            self::EVENT_LOGIN_FAIL,
            self::EVENT_PASSWORD_CHANGE,
            self::EVENT_LOGOUT,
            self::EVENT_RATE_LIMITED,
        ];
        if (!in_array($eventType, $valid, true)) {
            throw new \InvalidArgumentException("unknown event_type: {$eventType}");
        }

        $this->db->execute(
            <<<'SQL'
                INSERT INTO core.auth_events (event_type, admin_login, ip, user_agent, details)
                VALUES (:event_type, :admin_login, :ip, :user_agent, :details::jsonb)
            SQL,
            [
                'event_type'  => $eventType,
                'admin_login' => $adminLogin,
                'ip'          => $ip,
                'user_agent'  => $userAgent,
                'details'     => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * Fetch recent N events ordered by newest first.
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 100): array
    {
        return $this->db->fetchAll(
            'SELECT id, event_type, admin_login, host(ip) AS ip, user_agent, details, created_at FROM core.auth_events ORDER BY created_at DESC LIMIT :lim',
            ['lim' => $limit],
        );
    }
}
