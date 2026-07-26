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

    // Request up to ~30 calendar days of 1m data (Yahoo max). Chunked in 7-day requests.
    // Actual available history is often ~2–3 weeks of 1-minute bars.
    'history_calendar_days' => 30,
    'request_delay_ms' => 350,

    'pattern' => [
        'probability_threshold' => 0.60,
        // Raise toward 5 as history accumulates more sessions per weekday.
        'min_samples' => 2,
        'min_window_minutes' => 3,
    ],

    // Significant dollar moves (up or down) — timings you care about
    'big_moves' => [
        'min_dollars' => 5.0,       // default; UI can switch 5/7/9/11
        'big_dollars' => 5.0,
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
