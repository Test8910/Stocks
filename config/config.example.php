<?php

declare(strict_types=1);

/**
 * Copy to config.php and adjust if needed.
 */
return [
    // sqlite (default) or mysql
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/../storage/stocks.sqlite',
        // MySQL settings (used when driver = mysql)
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'stocks',
        'user' => 'stocks',
        'pass' => 'stocks',
        'charset' => 'utf8mb4',
    ],

    'timezone' => 'America/New_York',
    'session_start' => '09:30',
    'session_end' => '16:00',

    // Yahoo 1m history is capped (~7–8 days per request); we chunk to ~14 calendar days
    'history_calendar_days' => 14,
    'request_delay_ms' => 350,

    'pattern' => [
        'probability_threshold' => 0.60,
        // Yahoo only keeps ~7–8 days of 1m history (~2 of each weekday at first).
        // Raise toward 5 as cron accumulates more sessions.
        'min_samples' => 2,
        'min_window_minutes' => 3,
    ],

    // Significant dollar moves (up or down) — timings you care about
    'big_moves' => [
        'min_dollars' => 5.0,       // $5 and up
        'big_dollars' => 5.0,       // highlight $5+
        'reversal_dollars' => 1.5,  // pullback that ends a swing
        'max_window_minutes' => 90,
    ],

    'symbols' => [
        [
            'symbol' => 'SOXL',
            'name' => 'Direxion Daily Semiconductor Bull 3X',
            'yahoo_symbol' => 'SOXL',
        ],
        [
            'symbol' => 'QQQ',
            'name' => 'Invesco QQQ Trust',
            'yahoo_symbol' => 'QQQ',
        ],
    ],
];
