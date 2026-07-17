<?php

declare(strict_types=1);

use App\Cron\Command\BotsUpdateCommand;
use App\Shared\Db\Connection;

test('parses myip.ms format correctly', function (): void {
    $body = "# Googlebot\n66.249.66.1\n66.249.66.2\n# YandexBot\n5.255.253.1\n# Unknown\n198.51.100.1\n";
    $cmd = new BotsUpdateCommand(new Connection(pdo()));
    $entries = $cmd->parse($body);
    expect($entries)->toHaveCount(4);
    expect($entries[0])->toBe(['66.249.66.1', 'google']);
    expect($entries[2])->toBe(['5.255.253.1', 'yandex']);
    expect($entries[3])->toBe(['198.51.100.1', 'others']);
});
