<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\Database;
use Stocks\PriceRepository;
use Stocks\SessionFilter;
use Stocks\YahooFinanceClient;

$tz = new DateTimeZone($config['timezone'] ?? 'America/New_York');
$now = new DateTimeImmutable('now', $tz);
$weekday = (int) $now->format('N');
$hm = ((int) $now->format('H')) * 60 + (int) $now->format('i');
$start = 9 * 60 + 25;
$end = 16 * 60 + 5;

// Allow --force outside market hours
$force = in_array('--force', $argv, true);
if (!$force && ($weekday > 5 || $hm < $start || $hm > $end)) {
    echo $now->format('Y-m-d H:i:s T') . " outside RTH window — skip\n";
    exit(0);
}

$pdo = Database::pdo($config);
$repo = new PriceRepository($pdo);
$session = new SessionFilter(
    $config['timezone'] ?? 'America/New_York',
    $config['session_start'] ?? '09:30',
    $config['session_end'] ?? '16:00'
);
$yahoo = new YahooFinanceClient(
    requestDelayMs: (int) ($config['request_delay_ms'] ?? 350)
);

foreach ($repo->activeSymbols() as $row) {
    $symbol = $row['symbol'];
    $raw = $yahoo->fetchRecent($row['yahoo_symbol'], '2d');
    $bars = $session->filter($raw);
    $n = $repo->upsertBars($symbol, $bars);
    echo "{$symbol}: upserted={$n} latest=" . ($repo->latestTs($symbol) ?? 'n/a') . "\n";
    usleep(((int) ($config['request_delay_ms'] ?? 350)) * 1000);
}

echo "Sync complete at " . $now->format('Y-m-d H:i:s T') . "\n";
