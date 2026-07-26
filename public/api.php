<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$config = require dirname(__DIR__) . '/src/bootstrap.php';

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

        case 'summary': {
            $analysis = $stats->analyze($symbol);
            $detected = $patterns->detect($analysis);
            echo json_encode([
                'ok' => true,
                'symbol' => $symbol,
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
