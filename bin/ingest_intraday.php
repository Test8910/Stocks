<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\Database;
use Stocks\PriceRepository;
use Stocks\SessionFilter;
use Stocks\YahooFinanceClient;

$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--symbol=')) {
        $only = strtoupper(substr($arg, 9));
    }
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

$days = (int) ($config['history_calendar_days'] ?? 14);
$symbols = $repo->activeSymbols();
if ($symbols === []) {
    fwrite(STDERR, "No symbols. Run: php bin/setup_db.php\n");
    exit(1);
}

foreach ($symbols as $row) {
    $symbol = $row['symbol'];
    if ($only !== null && $symbol !== $only) {
        continue;
    }

    echo "Fetching 1m history for {$symbol} ({$days}d chunks)...\n";
    $raw = $yahoo->fetchOneMinute($row['yahoo_symbol'], $days);
    $bars = $session->filter($raw);
    $n = $repo->upsertBars($symbol, $bars);
    echo "  raw=" . count($raw) . " rth=" . count($bars) . " upserted={$n} total=" . $repo->countBars($symbol) . "\n";

    usleep(((int) ($config['request_delay_ms'] ?? 350)) * 1000);
}

echo "Done.\n";
