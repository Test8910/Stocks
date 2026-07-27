<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\Database;
use Stocks\PriceRepository;
use Stocks\SymbolSessions;
use Stocks\YahooFinanceClient;

$only = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--symbol=')) {
        $only = strtoupper(substr($arg, 9));
    }
}

$pdo = Database::pdo($config);
$repo = new PriceRepository($pdo);
$yahoo = new YahooFinanceClient(
    requestDelayMs: (int) ($config['request_delay_ms'] ?? 350)
);

$days = (int) ($config['history_calendar_days'] ?? 14);
$symbols = SymbolSessions::all($config);
if ($symbols === []) {
    fwrite(STDERR, "No symbols in config. Check config.php\n");
    exit(1);
}

$repo->seedSymbols($symbols);

foreach ($symbols as $row) {
    $symbol = $row['symbol'];
    if ($only !== null && $symbol !== $only) {
        continue;
    }

    $session = SymbolSessions::filterFor($row);
    echo "Fetching 1m history for {$symbol} [{$row['region']}] ({$row['yahoo_symbol']}, {$days}d)...\n";
    try {
        $raw = $yahoo->fetchOneMinute($row['yahoo_symbol'], $days);
        $bars = $session->filter($raw);
        $n = $repo->upsertBars($symbol, $bars);
        echo "  raw=" . count($raw) . " session=" . count($bars) . " upserted={$n} total=" . $repo->countBars($symbol) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "  ERROR: {$e->getMessage()}\n");
    }

    usleep(((int) ($config['request_delay_ms'] ?? 350)) * 1000);
}

echo "Done.\n";
