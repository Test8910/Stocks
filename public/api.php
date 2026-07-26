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

    $action = $_GET['action'] ?? 'symbols';
    $symbol = strtoupper((string) ($_GET['symbol'] ?? 'SOXL'));

    $parseOptions = static function (array $config): array {
        $intervalAllowed = [1, 2, 5, 15];
        $interval = isset($_GET['interval']) ? (int) $_GET['interval'] : 1;
        if (!in_array($interval, $intervalAllowed, true)) {
            $interval = 1;
        }

        $bmCfg = $config['big_moves'] ?? [];
        $dollarAllowed = [5.0, 7.0, 9.0, 11.0];
        $requested = isset($_GET['min_dollars']) ? (float) $_GET['min_dollars'] : null;
        $minDollars = in_array($requested, $dollarAllowed, true)
            ? $requested
            : (float) ($bmCfg['min_dollars'] ?? 5.0);
        if (!in_array($minDollars, $dollarAllowed, true)) {
            $minDollars = 5.0;
        }

        return [$interval, $minDollars, $bmCfg];
    };

    $buildWeekdayAvgs = static function (array $paths, int $interval) use ($stats, $session, $symbol): array {
        $weekdayAvgs = [];
        if ($interval <= 1) {
            for ($wd = 1; $wd <= 5; $wd++) {
                $weekdayAvgs[$wd] = $stats->weekdayAvgPrice($symbol, $wd);
            }
            return $weekdayAvgs;
        }

        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        for ($wd = 1; $wd <= 5; $wd++) {
            $dayPaths = array_values(array_filter(
                $paths,
                static fn ($p) => (int) $p['weekday'] === $wd
            ));
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
        return $weekdayAvgs;
    };

    $buildSummary = static function () use (
        $config,
        $stats,
        $session,
        $symbol,
        $parseOptions,
        $buildWeekdayAvgs
    ): array {
        [$interval, $minDollars, $bmCfg] = $parseOptions($config);

        $paths1m = $stats->sessionPaths($symbol);
        $paths = $stats->aggregatePaths($paths1m, $interval);
        $analysis = $stats->analyzeFromPaths($paths, $symbol, $interval);

        $minWindow = (int) ($config['pattern']['min_window_minutes'] ?? 3);
        if ($interval > 1) {
            $minWindow = min($minWindow, $interval);
        }

        $engine = new PatternEngine(
            (float) ($config['pattern']['probability_threshold'] ?? 0.60),
            (int) ($config['pattern']['min_samples'] ?? 2),
            $session,
            $minWindow,
            $interval
        );

        $bigMoves = (new BigMoveDetector(
            $session,
            $minDollars,
            $minDollars,
            (float) ($bmCfg['reversal_dollars'] ?? 1.5),
            (int) ($bmCfg['max_window_minutes'] ?? 90)
        ))->analyze($paths);

        $detected = $engine->withDollarMoves(
            $engine->detect($analysis),
            $bigMoves,
            $minDollars
        );

        $step = max(1, $interval);
        $times = [];
        for ($m = 0; $m < $analysis['session_minutes']; $m += $step) {
            $times[] = $session->minuteLabel($m);
        }

        return [
            'ok' => true,
            'symbol' => $symbol,
            'interval_minutes' => $interval,
            'min_dollars' => $minDollars,
            'bar_count' => $analysis['bar_count'],
            'session_count' => $analysis['session_count'],
            'low_high' => array_values($analysis['low_high']),
            'patterns' => $detected,
            'heatmap' => $analysis['heatmap'],
            'times' => $times,
            'weekday_labels' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
            'weekdays' => $analysis['weekdays'],
            'sessions' => $paths,
            'weekday_avg_price' => $buildWeekdayAvgs($paths, $interval),
            'big_moves' => $bigMoves,
            'options' => [
                'interval_minutes' => $interval,
                'min_dollars' => $minDollars,
                'probability_threshold' => $detected['threshold'],
            ],
        ];
    };

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
            $summary = $buildSummary();
            $weekday = (int) ($_GET['weekday'] ?? 1);
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'weekday' => $weekday,
                'options' => $summary['options'],
                'day' => $summary['weekdays'][$weekday] ?? null,
                'low_high' => $summary['low_high'][$weekday - 1] ?? null,
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'heatmap': {
            $summary = $buildSummary();
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'options' => $summary['options'],
                'session_minutes' => $summary['session_count'],
                'bar_count' => $summary['bar_count'],
                'session_count' => $summary['session_count'],
                'weekday_labels' => $summary['weekday_labels'],
                'heatmap' => $summary['heatmap'],
                'low_high' => $summary['low_high'],
                'times' => $summary['times'],
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'patterns': {
            $summary = $buildSummary();
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'options' => $summary['options'],
                'bar_count' => $summary['bar_count'],
                'session_count' => $summary['session_count'],
                'patterns' => $summary['patterns'],
                'low_high' => $summary['low_high'],
            ], JSON_PRETTY_PRINT);
            break;
        }

        case 'session': {
            [$interval] = $parseOptions($config);
            $paths = $stats->aggregatePaths($stats->sessionPaths($symbol), $interval);
            $date = (string) ($_GET['date'] ?? '');
            $match = null;
            foreach ($paths as $p) {
                if ($date === '' || $p['date'] === $date) {
                    $match = $p;
                    break;
                }
            }
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
                'interval_minutes' => $interval,
                'session' => $match,
                'dates' => array_column($paths, 'date'),
            ]);
            break;
        }

        case 'summary':
            echo json_encode($buildSummary());
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
