<?php

declare(strict_types=1);

namespace App\Shared\Telegram;

/**
 * Fire-and-forget Telegram Bot API notifier.
 * Never throws — failures are silently logged via error_log().
 */
final class TelegramNotifier
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $chatId,
    ) {}

    public function isConfigured(): bool
    {
        return $this->token !== null
            && $this->token !== ''
            && $this->chatId !== null
            && $this->chatId !== '';
    }

    /**
     * Send a message to the configured chat.
     * Returns true on success, false on failure (never throws).
     */
    public function send(string $text, ?string $parseMode = 'HTML'): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $url = sprintf(
            'https://api.telegram.org/bot%s/sendMessage',
            rawurlencode((string)$this->token),
        );

        $payload = json_encode([
            'chat_id'    => $this->chatId,
            'text'       => $text,
            'parse_mode' => $parseMode,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            error_log('TelegramNotifier: curl_init failed');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
        ]);

        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            error_log(sprintf('TelegramNotifier: cURL error %d', $errno));
            return false;
        }

        $decoded = json_decode((string)$body, true);
        if (!is_array($decoded) || empty($decoded['ok'])) {
            error_log(sprintf('TelegramNotifier: API error: %s', (string)$body));
            return false;
        }

        return true;
    }
}
