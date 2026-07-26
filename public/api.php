<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$config = require dirname(__DIR__) . '/src/bootstrap.php';

use Stocks\BigMoveDetector;
use Stocks\Database;
use Stocks\PatternEngine;
use Stocks\PriceRepository;
use Stocks\SessionFilter;
use Stocks\StatsService;

try {
    $pdo = Database::pdo($config);
    $repo = new PriceRepository($pdo);
    $session = new SessionFilter(
        $config['timezone'] ?? 'America/New_York',
        $config['session_start'] ?? '09:30',
        $config['session_end'] ?? '16:00'
    );
    $stats = new StatsService($repo, $session);
    $patterns = new PatternEngine(
        (float) ($config['pattern']['probability_threshold'] ?? 0.60),
        (int) ($config['pattern']['min_samples'] ?? 5),
        $session,
        (int) ($config['pattern']['min_window_minutes'] ?? 3)
    );

    $action = $_GET['action'] ?? 'symbols';
    $symbol = strtoupper((string) ($_GET['symbol'] ?? 'SOXL'));

    switch ($action) {
        case 'symbols':
            $rows = $repo->activeSymbols();
            $out = [];
            foreach ($rows as $r) {
                $out[] = [
                    'symbol' => $r['symbol'],
                    'name' => $r['name'],
                    'bars' => $repo->countBars($r['symbol']),
                    'latest' => $repo->latestTs($r['symbol']),
                ];
            }
            echo json_encode(['ok' => true, 'symbols' => $out], JSON_PRETTY_PRINT);
            break;

        case 'series': {
            $analysis = $stats->analyze($symbol);
            $weekday = (int) ($_GET['weekday'] ?? 1);
            $day = $analysis['weekdays'][$weekday] ?? null;
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'weekday' => $weekday,
                'day' => $day,
                'low_high' => $analysis['low_high'][$weekday] ?? null,
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'heatmap': {
            $analysis = $stats->analyze($symbol);
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'session_minutes' => $analysis['session_minutes'],
                'bar_count' => $analysis['bar_count'],
                'session_count' => $analysis['session_count'],
                'weekday_labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'heatmap' => $analysis['heatmap'],
                'low_high' => array_values($analysis['low_high']),
                'times' => array_map(
                    static fn ($m) => $session->minuteLabel($m),
                    range(0, $analysis['session_minutes'] - 1)
                ),
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'patterns': {
            $analysis = $stats->analyze($symbol);
            $detected = $patterns->detect($analysis);
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'bar_count' => $analysis['bar_count'],
                'session_count' => $analysis['session_count'],
                'patterns' => $detected,
                'low_high' => array_values($analysis['low_high']),
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'session': {
            $date = (string) ($_GET['date'] ?? '');
            $paths = $stats->sessionPaths($symbol);
            $match = null;
            foreach ($paths as $p) {
                if ($date === '' || $p['date'] === $date) {
                    $match = $p;
                    if ($date !== '') {
                        break;
                    }
                    // default: newest
                    break;
                }
            }
            echo json_encode(['ok' => true, 'symbol' => $symbol, 'session' => $match, 'dates' => array_column($paths, 'date')]);
            break;
        }

        case 'summary': {
            $analysis = $stats->analyze($symbol);
            $detected = $patterns->detect($analysis);

            $intervalAllowed = [1, 2, 5, 15];
            $interval = isset($_GET['interval']) ? (int) $_GET['interval'] : 1;
            if (!in_array($interval, $intervalAllowed, true)) {
                $interval = 1;
            }

            $paths1m = $stats->sessionPaths($symbol);
            $paths = $stats->aggregatePaths($paths1m, $interval);

            $weekdayAvgs = [];
            for ($wd = 1; $wd <= 5; $wd++) {
                $weekdayAvgs[$wd] = $stats->weekdayAvgPrice($symbol, $wd);
            }
            // Rebuild weekday avg from aggregated paths when interval > 1
            if ($interval > 1) {
                for ($wd = 1; $wd <= 5; $wd++) {
                    $dayPaths = array_values(array_filter(
                        $paths,
                        static fn ($p) => (int) $p['weekday'] === $wd
                    ));
                    $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
                    $bucketMap = [];
                    foreach ($dayPaths as $path) {
                        foreach ($path['points'] as $pt) {
                            $m = (int) $pt['minute_of_day'];
                            $bucketMap[$m]['sum'] = ($bucketMap[$m]['sum'] ?? 0) + (float) $pt['price'];
                            $bucketMap[$m]['n'] = ($bucketMap[$m]['n'] ?? 0) + 1;
                        }
                    }
                    ksort($bucketMap);
                    $times = [];
                    $avgPrice = [];
                    $bestLow = null;
                    $bestHigh = null;
                    $lowM = null;
                    $highM = null;
                    foreach ($bucketMap as $m => $agg) {
                        $p = $agg['sum'] / $agg['n'];
                        $times[] = $session->minuteLabel((int) $m);
                        $avgPrice[] = round($p, 4);
                        if ($bestLow === null || $p < $bestLow) {
                            $bestLow = $p;
                            $lowM = (int) $m;
                        }
                        if ($bestHigh === null || $p > $bestHigh) {
                            $bestHigh = $p;
                            $highM = (int) $m;
                        }
                    }
                    $weekdayAvgs[$wd] = [
                        'weekday' => $wd,
                        'label' => $labels[$wd],
                        'times' => $times,
                        'avg_price' => $avgPrice,
                        'avg_norm' => [],
                        'low_time' => $lowM !== null ? $session->minuteLabel($lowM) : null,
                        'high_time' => $highM !== null ? $session->minuteLabel($highM) : null,
                        'low_price' => $bestLow !== null ? round($bestLow, 4) : null,
                        'high_price' => $bestHigh !== null ? round($bestHigh, 4) : null,
                        'sessions' => count($dayPaths),
                    ];
                }
            }

            $bmCfg = $config['big_moves'] ?? [];
            $allowed = [5.0, 7.0, 9.0, 11.0];
            $requested = isset($_GET['min_dollars']) ? (float) $_GET['min_dollars'] : null;
            $minDollars = in_array($requested, $allowed, true)
                ? $requested
                : (float) ($bmCfg['min_dollars'] ?? 5.0);
            if (!in_array($minDollars, $allowed, true)) {
                $minDollars = 5.0;
            }
            $bigMoves = (new BigMoveDetector(
                $session,
                $minDollars,
                $minDollars,
                (float) ($bmCfg['reversal_dollars'] ?? 1.5),
                (int) ($bmCfg['max_window_minutes'] ?? 90)
            ))->analyze($paths);

            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'interval_minutes' => $interval,
                'bar_count' => $analysis['bar_count'],
                'session_count' => $analysis['session_count'],
                'low_high' => array_values($analysis['low_high']),
                'patterns' => $detected,
                'heatmap' => $analysis['heatmap'],
                'times' => array_map(
                    static fn ($m) => $session->minuteLabel($m),
                    range(0, $analysis['session_minutes'] - 1)
                ),
                'weekday_labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'weekdays' => $analysis['weekdays'],
                'sessions' => $paths,
                'weekday_avg_price' => $weekdayAvgs,
                'big_moves' => $bigMoves,
            ]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
