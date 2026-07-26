<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Aggregate 1m bars by weekday + minute; compute up/down probs and low/high profiles.
 */
final class StatsService
{
    public function __construct(
        private readonly PriceRepository $repo,
        private readonly SessionFilter $session
    ) {
    }

    /**
     * @return array{
     *   symbol:string,
     *   session_minutes:int,
     *   weekdays:array<int, array{
     *     weekday:int,
     *     label:string,
     *     minutes:list<array{
     *       minute_of_day:int,
     *       time:string,
     *       n:int,
     *       avg_ret:float,
     *       up_prob:float,
     *       down_prob:float,
     *       avg_rel_low:float,
     *       avg_rel_high:float
     *     }>
     *   }>,
     *   heatmap:list<list<?array{n:int,avg_ret:float,up_prob:float}>>,
     *   low_high:array<int, array{
     *     weekday:int,
     *     label:string,
     *     typical_low_time: ?string,
     *     typical_high_time: ?string,
     *     avg_low_minute: ?float,
     *     avg_high_minute: ?float
     *   }>
     * }
     */
    public function analyze(string $symbol): array
    {
        $bars = $this->repo->barsForSymbol($symbol);
        $sessionLen = $this->session->sessionLengthMinutes();
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];

        // Group by session date
        $bySession = [];
        foreach ($bars as $bar) {
            $bySession[$bar['session_date']][] = $bar;
        }

        // Per (weekday, minute): returns and relative position in day's range
        $bucket = [];
        $dayExtremes = []; // weekday => list of [low_minute, high_minute]

        foreach ($bySession as $date => $dayBars) {
            if ($dayBars === []) {
                continue;
            }
            usort($dayBars, static fn ($a, $b) => $a['minute_of_day'] <=> $b['minute_of_day']);

            $weekday = (int) $dayBars[0]['weekday'];
            $lowMinute = null;
            $highMinute = null;
            $dayLow = PHP_FLOAT_MAX;
            $dayHigh = -PHP_FLOAT_MAX;
            foreach ($dayBars as $b) {
                $p = (float) $b['price'];
                // First print of the session low / high
                if ($p < $dayLow) {
                    $dayLow = $p;
                    $lowMinute = (int) $b['minute_of_day'];
                }
                if ($p > $dayHigh) {
                    $dayHigh = $p;
                    $highMinute = (int) $b['minute_of_day'];
                }
            }
            $range = $dayHigh - $dayLow;
            $dayExtremes[$weekday][] = [
                'low' => $lowMinute,
                'high' => $highMinute,
            ];

            $prev = null;
            foreach ($dayBars as $b) {
                $m = (int) $b['minute_of_day'];
                $price = (float) $b['price'];
                $ret = null;
                if ($prev !== null && $prev > 0) {
                    $ret = ($price - $prev) / $prev;
                }
                $rel = $range > 0 ? ($price - $dayLow) / $range : 0.5;

                if (!isset($bucket[$weekday][$m])) {
                    $bucket[$weekday][$m] = [
                        'rets' => [],
                        'rels' => [],
                    ];
                }
                if ($ret !== null) {
                    $bucket[$weekday][$m]['rets'][] = $ret;
                }
                $bucket[$weekday][$m]['rels'][] = $rel;
                $prev = $price;
            }
        }

        $weekdays = [];
        $heatmap = [];

        for ($wd = 1; $wd <= 5; $wd++) {
            $minutes = [];
            $heatRow = [];
            for ($m = 0; $m < $sessionLen; $m++) {
                $rets = $bucket[$wd][$m]['rets'] ?? [];
                $rels = $bucket[$wd][$m]['rels'] ?? [];
                $n = count($rets);
                $up = 0;
                $down = 0;
                $sum = 0.0;
                foreach ($rets as $r) {
                    $sum += $r;
                    if ($r > 0) {
                        $up++;
                    } elseif ($r < 0) {
                        $down++;
                    }
                }
                $avgRet = $n > 0 ? $sum / $n : 0.0;
                $upProb = $n > 0 ? $up / $n : 0.0;
                $downProb = $n > 0 ? $down / $n : 0.0;
                $avgRel = $rels !== [] ? array_sum($rels) / count($rels) : 0.5;

                $cell = [
                    'minute_of_day' => $m,
                    'time' => $this->session->minuteLabel($m),
                    'n' => $n,
                    'avg_ret' => round($avgRet, 6),
                    'up_prob' => round($upProb, 4),
                    'down_prob' => round($downProb, 4),
                    'avg_rel_low' => round(1 - $avgRel, 4), // closeness to low
                    'avg_rel_high' => round($avgRel, 4),     // closeness to high
                ];
                $minutes[] = $cell;
                $heatRow[] = $n > 0 ? [
                    'n' => $n,
                    'avg_ret' => round($avgRet, 6),
                    'up_prob' => round($upProb, 4),
                    'avg_rel_high' => round($avgRel, 4),
                ] : null;
            }

            $weekdays[$wd] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'minutes' => $minutes,
            ];
            $heatmap[] = $heatRow;
        }

        $lowHigh = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $ext = $dayExtremes[$wd] ?? [];
            $avgLow = null;
            $avgHigh = null;
            if ($ext !== []) {
                $avgLow = array_sum(array_column($ext, 'low')) / count($ext);
                $avgHigh = array_sum(array_column($ext, 'high')) / count($ext);
            }
            $lowHigh[$wd] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'sessions' => count($ext),
                'avg_low_minute' => $avgLow !== null ? round($avgLow, 1) : null,
                'avg_high_minute' => $avgHigh !== null ? round($avgHigh, 1) : null,
                'typical_low_time' => $avgLow !== null ? $this->session->minuteLabel((int) round($avgLow)) : null,
                'typical_high_time' => $avgHigh !== null ? $this->session->minuteLabel((int) round($avgHigh)) : null,
            ];
        }

        return [
            'symbol' => $symbol,
            'session_minutes' => $sessionLen,
            'bar_count' => count($bars),
            'session_count' => count($bySession),
            'weekdays' => $weekdays,
            'heatmap' => $heatmap,
            'low_high' => $lowHigh,
            'interval_minutes' => 1,
        ];
    }

    /**
     * Build pattern/heatmap stats from (possibly aggregated) session paths.
     *
     * @param list<array<string,mixed>> $paths
     */
    public function analyzeFromPaths(array $paths, string $symbol, int $intervalMinutes = 1): array
    {
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $sessionLen = $this->session->sessionLengthMinutes();
        $bucket = [];
        $dayExtremes = [];
        $barCount = 0;

        foreach ($paths as $path) {
            $points = $path['points'] ?? [];
            if ($points === []) {
                continue;
            }
            $weekday = (int) $path['weekday'];
            $barCount += count($points);

            $lowMinute = null;
            $highMinute = null;
            $dayLow = PHP_FLOAT_MAX;
            $dayHigh = -PHP_FLOAT_MAX;
            foreach ($points as $pt) {
                $p = (float) $pt['price'];
                $m = (int) $pt['minute_of_day'];
                if ($p < $dayLow) {
                    $dayLow = $p;
                    $lowMinute = $m;
                }
                if ($p > $dayHigh) {
                    $dayHigh = $p;
                    $highMinute = $m;
                }
            }
            $range = $dayHigh - $dayLow;
            $dayExtremes[$weekday][] = ['low' => $lowMinute, 'high' => $highMinute];

            $prev = null;
            foreach ($points as $pt) {
                $m = (int) $pt['minute_of_day'];
                $price = (float) $pt['price'];
                $ret = null;
                if ($prev !== null && $prev > 0) {
                    $ret = ($price - $prev) / $prev;
                }
                $rel = $range > 0 ? ($price - $dayLow) / $range : 0.5;
                if (!isset($bucket[$weekday][$m])) {
                    $bucket[$weekday][$m] = ['rets' => [], 'rels' => []];
                }
                if ($ret !== null) {
                    $bucket[$weekday][$m]['rets'][] = $ret;
                }
                $bucket[$weekday][$m]['rels'][] = $rel;
                $prev = $price;
            }
        }

        $step = max(1, $intervalMinutes);
        $weekdays = [];
        $heatmap = [];

        for ($wd = 1; $wd <= 5; $wd++) {
            $minutes = [];
            $heatRow = [];
            for ($m = 0; $m < $sessionLen; $m += $step) {
                $rets = $bucket[$wd][$m]['rets'] ?? [];
                $rels = $bucket[$wd][$m]['rels'] ?? [];
                $n = count($rets);
                $up = 0;
                $down = 0;
                $sum = 0.0;
                foreach ($rets as $r) {
                    $sum += $r;
                    if ($r > 0) {
                        $up++;
                    } elseif ($r < 0) {
                        $down++;
                    }
                }
                $avgRet = $n > 0 ? $sum / $n : 0.0;
                $upProb = $n > 0 ? $up / $n : 0.0;
                $downProb = $n > 0 ? $down / $n : 0.0;
                $avgRel = $rels !== [] ? array_sum($rels) / count($rels) : 0.5;
                $cell = [
                    'minute_of_day' => $m,
                    'time' => $this->session->minuteLabel($m),
                    'n' => $n,
                    'avg_ret' => round($avgRet, 6),
                    'up_prob' => round($upProb, 4),
                    'down_prob' => round($downProb, 4),
                    'avg_rel_low' => round(1 - $avgRel, 4),
                    'avg_rel_high' => round($avgRel, 4),
                ];
                $minutes[] = $cell;
                $heatRow[] = $n > 0 ? [
                    'n' => $n,
                    'avg_ret' => round($avgRet, 6),
                    'up_prob' => round($upProb, 4),
                    'avg_rel_high' => round($avgRel, 4),
                ] : null;
            }
            $weekdays[$wd] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'minutes' => $minutes,
            ];
            $heatmap[] = $heatRow;
        }

        $lowHigh = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $ext = $dayExtremes[$wd] ?? [];
            $avgLow = null;
            $avgHigh = null;
            if ($ext !== []) {
                $avgLow = array_sum(array_column($ext, 'low')) / count($ext);
                $avgHigh = array_sum(array_column($ext, 'high')) / count($ext);
            }
            $lowHigh[$wd] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'sessions' => count($ext),
                'avg_low_minute' => $avgLow !== null ? round($avgLow, 1) : null,
                'avg_high_minute' => $avgHigh !== null ? round($avgHigh, 1) : null,
                'typical_low_time' => $avgLow !== null ? $this->session->minuteLabel((int) round($avgLow)) : null,
                'typical_high_time' => $avgHigh !== null ? $this->session->minuteLabel((int) round($avgHigh)) : null,
            ];
        }

        return [
            'symbol' => $symbol,
            'session_minutes' => $sessionLen,
            'bar_count' => $barCount,
            'session_count' => count($paths),
            'weekdays' => $weekdays,
            'heatmap' => $heatmap,
            'low_high' => $lowHigh,
            'interval_minutes' => $intervalMinutes,
        ];
    }

    /**
     * Price-vs-time for each session, with low → high path details.
     *
     * @return list<array{
     *   date:string,
     *   weekday:int,
     *   label:string,
     *   points:list<array{time:string,minute_of_day:int,price:float}>,
     *   low_price:float,
     *   low_time:string,
     *   high_price:float,
     *   high_time:string,
     *   high_after_low:bool,
     *   minutes_low_to_high:?int,
     *   move_pct:?float
     * }>
     */
    public function sessionPaths(string $symbol): array
    {
        $bars = $this->repo->barsForSymbol($symbol);
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $bySession = [];
        foreach ($bars as $bar) {
            $bySession[$bar['session_date']][] = $bar;
        }

        $paths = [];
        foreach ($bySession as $date => $dayBars) {
            usort($dayBars, static fn ($a, $b) => $a['minute_of_day'] <=> $b['minute_of_day']);
            $weekday = (int) $dayBars[0]['weekday'];

            $lowMinute = null;
            $highMinute = null;
            $lowPrice = PHP_FLOAT_MAX;
            $highPrice = -PHP_FLOAT_MAX;
            $points = [];

            foreach ($dayBars as $b) {
                $m = (int) $b['minute_of_day'];
                $p = (float) $b['price'];
                $points[] = [
                    'time' => $this->session->minuteLabel($m),
                    'minute_of_day' => $m,
                    'price' => $p,
                ];
                if ($p < $lowPrice) {
                    $lowPrice = $p;
                    $lowMinute = $m;
                }
                if ($p > $highPrice) {
                    $highPrice = $p;
                    $highMinute = $m;
                }
            }

            $highAfterLow = $lowMinute !== null && $highMinute !== null && $highMinute > $lowMinute;
            $minutesLh = ($lowMinute !== null && $highMinute !== null)
                ? abs($highMinute - $lowMinute)
                : null;
            $movePct = ($lowPrice > 0 && $highAfterLow)
                ? (($highPrice - $lowPrice) / $lowPrice) * 100
                : (($lowPrice > 0 && $highMinute !== null && $lowMinute !== null)
                    ? (($highPrice - $lowPrice) / $lowPrice) * 100
                    : null);

            $paths[] = [
                'date' => $date,
                'weekday' => $weekday,
                'label' => $labels[$weekday] ?? (string) $weekday,
                'points' => $points,
                'low_price' => round($lowPrice, 4),
                'low_time' => $lowMinute !== null ? $this->session->minuteLabel($lowMinute) : null,
                'high_price' => round($highPrice, 4),
                'high_time' => $highMinute !== null ? $this->session->minuteLabel($highMinute) : null,
                'high_after_low' => $highAfterLow,
                'minutes_low_to_high' => $highAfterLow ? $minutesLh : null,
                'move_pct' => $movePct !== null ? round($movePct, 3) : null,
            ];
        }

        usort($paths, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        return $paths;
    }

    /**
     * Resample 1-minute session paths into N-minute bars (close of each bucket).
     *
     * @param list<array<string,mixed>> $paths
     * @return list<array<string,mixed>>
     */
    public function aggregatePaths(array $paths, int $intervalMinutes): array
    {
        if ($intervalMinutes <= 1) {
            return $paths;
        }

        $out = [];
        foreach ($paths as $path) {
            $buckets = [];
            foreach ($path['points'] as $pt) {
                $m = (int) $pt['minute_of_day'];
                $bucket = intdiv($m, $intervalMinutes) * $intervalMinutes;
                $buckets[$bucket] = $pt; // keep last price in bucket
            }
            ksort($buckets);

            $points = [];
            $lowMinute = null;
            $highMinute = null;
            $lowPrice = PHP_FLOAT_MAX;
            $highPrice = -PHP_FLOAT_MAX;

            foreach ($buckets as $bucketMinute => $pt) {
                $price = (float) $pt['price'];
                $points[] = [
                    'time' => $this->session->minuteLabel((int) $bucketMinute),
                    'minute_of_day' => (int) $bucketMinute,
                    'price' => $price,
                ];
                if ($price < $lowPrice) {
                    $lowPrice = $price;
                    $lowMinute = (int) $bucketMinute;
                }
                if ($price > $highPrice) {
                    $highPrice = $price;
                    $highMinute = (int) $bucketMinute;
                }
            }

            $highAfterLow = $lowMinute !== null && $highMinute !== null && $highMinute > $lowMinute;
            $minutesLh = ($lowMinute !== null && $highMinute !== null)
                ? abs($highMinute - $lowMinute)
                : null;
            $movePct = ($lowPrice > 0)
                ? (($highPrice - $lowPrice) / $lowPrice) * 100
                : null;

            $path['points'] = $points;
            $path['interval_minutes'] = $intervalMinutes;
            $path['low_price'] = round($lowPrice, 4);
            $path['high_price'] = round($highPrice, 4);
            $path['low_time'] = $lowMinute !== null ? $this->session->minuteLabel($lowMinute) : null;
            $path['high_time'] = $highMinute !== null ? $this->session->minuteLabel($highMinute) : null;
            $path['high_after_low'] = $highAfterLow;
            $path['minutes_low_to_high'] = $highAfterLow ? $minutesLh : null;
            $path['move_pct'] = $movePct !== null ? round($movePct, 3) : null;
            $out[] = $path;
        }

        return $out;
    }

    /**
     * Average price by minute for a weekday (aligned to open = 100).
     *
     * @return array{
     *   weekday:int,
     *   label:string,
     *   times:list<string>,
     *   avg_price:list<?float>,
     *   avg_norm:list<?float>,
     *   low_time:?string,
     *   high_time:?string,
     *   low_price:?float,
     *   high_price:?float
     * }
     */
    public function weekdayAvgPrice(string $symbol, int $weekday): array
    {
        $paths = array_values(array_filter(
            $this->sessionPaths($symbol),
            static fn ($p) => (int) $p['weekday'] === $weekday
        ));
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $sessionLen = $this->session->sessionLengthMinutes();
        $sums = array_fill(0, $sessionLen, 0.0);
        $normSums = array_fill(0, $sessionLen, 0.0);
        $counts = array_fill(0, $sessionLen, 0);

        foreach ($paths as $path) {
            $open = $path['points'][0]['price'] ?? null;
            if ($open === null || $open <= 0) {
                continue;
            }
            foreach ($path['points'] as $pt) {
                $m = (int) $pt['minute_of_day'];
                if ($m < 0 || $m >= $sessionLen) {
                    continue;
                }
                $sums[$m] += (float) $pt['price'];
                $normSums[$m] += ((float) $pt['price'] / $open) * 100.0;
                $counts[$m]++;
            }
        }

        $avgPrice = [];
        $avgNorm = [];
        $times = [];
        $bestLow = null;
        $bestHigh = null;
        $lowM = null;
        $highM = null;

        for ($m = 0; $m < $sessionLen; $m++) {
            $times[] = $this->session->minuteLabel($m);
            if ($counts[$m] === 0) {
                $avgPrice[] = null;
                $avgNorm[] = null;
                continue;
            }
            $p = $sums[$m] / $counts[$m];
            $n = $normSums[$m] / $counts[$m];
            $avgPrice[] = round($p, 4);
            $avgNorm[] = round($n, 4);
            if ($bestLow === null || $p < $bestLow) {
                $bestLow = $p;
                $lowM = $m;
            }
            if ($bestHigh === null || $p > $bestHigh) {
                $bestHigh = $p;
                $highM = $m;
            }
        }

        return [
            'weekday' => $weekday,
            'label' => $labels[$weekday] ?? (string) $weekday,
            'times' => $times,
            'avg_price' => $avgPrice,
            'avg_norm' => $avgNorm,
            'low_time' => $lowM !== null ? $this->session->minuteLabel($lowM) : null,
            'high_time' => $highM !== null ? $this->session->minuteLabel($highM) : null,
            'low_price' => $bestLow !== null ? round($bestLow, 4) : null,
            'high_price' => $bestHigh !== null ? round($bestHigh, 4) : null,
            'sessions' => count($paths),
        ];
    }
}
