<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\Database;
use Stocks\PriceRepository;
use Stocks\SymbolSessions;
use Stocks\YahooFinanceClient;

$force = in_array('--force', $argv, true);
$anyOpen = false;
$nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));

foreach (SymbolSessions::all($config) as $row) {
    $tz = new DateTimeZone($row['timezone']);
    $local = $nowUtc->setTimezone($tz);
    $weekday = (int) $local->format('N');
    $hm = ((int) $local->format('H')) * 60 + (int) $local->format('i');
    [$sh, $sm] = array_map('intval', explode(':', $row['session_start']));
    [$eh, $em] = array_map('intval', explode(':', $row['session_end']));
    $start = $sh * 60 + $sm - 5;
    $end = $eh * 60 + $em + 5;
    if ($weekday <= 5 && $hm >= $start && $hm <= $end) {
        $anyOpen = true;
        break;
    }
}

if (!$force && !$anyOpen) {
    echo $nowUtc->format('Y-m-d H:i:s') . " UTC — no configured market session open — skip (use --force)\n";
    exit(0);
}

$pdo = Database::pdo($config);
$repo = new PriceRepository($pdo);
$repo->seedSymbols(SymbolSessions::all($config));
$yahoo = new YahooFinanceClient(
    requestDelayMs: (int) ($config['request_delay_ms'] ?? 350)
);

foreach (SymbolSessions::all($config) as $row) {
    $symbol = $row['symbol'];
    $session = SymbolSessions::filterFor($row);
    try {
        $raw = $yahoo->fetchRecent($row['yahoo_symbol'], '2d');
        $bars = $session->filter($raw);
        $n = $repo->upsertBars($symbol, $bars);
        echo "{$symbol} [{$row['region']}]: upserted={$n} latest=" . ($repo->latestTs($symbol) ?? 'n/a') . "\n";
    } catch (Throwable $e) {
        echo "{$symbol}: ERROR {$e->getMessage()}\n";
    }
    usleep(((int) ($config['request_delay_ms'] ?? 350)) * 1000);
}

echo "Sync complete at " . $nowUtc->format('Y-m-d H:i:s') . " UTC\n";
