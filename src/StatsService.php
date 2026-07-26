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
        ];
    }
}
