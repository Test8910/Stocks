<?php

declare(strict_types=1);

namespace Stocks;

/**
 * If price drops (or rises) ≥ X% in a time window, what usually happens next?
 */
final class ScenarioAnalyzer
{
    public function __construct(private readonly SessionFilter $session)
    {
    }

    /**
     * @param list<array<string,mixed>> $paths
     * @return array<string,mixed>
     */
    public function analyzeDrop(
        array $paths,
        string $symbol,
        int $fromMinute,
        int $toMinute,
        float $thresholdPct,
        string $direction = 'down', // down|up
        string $measure = 'end', // end = window return, maxdd = max adverse excursion
        int $nextMinutes = 60,
        ?int $weekday = null
    ): array {
        $thresholdPct = abs($thresholdPct);
        $fromMinute = max(0, $fromMinute);
        $toMinute = max($fromMinute + 1, $toMinute);
        $nextMinutes = max(15, min(240, $nextMinutes));
        $sessionLen = $this->session->sessionLengthMinutes();
        $nextEnd = min($sessionLen - 1, $toMinute + $nextMinutes);

        $matches = [];
        foreach ($paths as $path) {
            if ($weekday !== null && (int) $path['weekday'] !== $weekday) {
                continue;
            }
            $win = $this->windowStats($path['points'], $fromMinute, $toMinute);
            if ($win === null) {
                continue;
            }

            $metric = $measure === 'maxdd' ? $win['max_dd'] : $win['ret'];
            $hit = $direction === 'up'
                ? ($measure === 'maxdd' ? $win['max_run'] >= $thresholdPct : $win['ret'] >= $thresholdPct)
                : ($metric <= -$thresholdPct);

            if (!$hit) {
                continue;
            }

            $next = $this->windowStats($path['points'], $toMinute, $nextEnd);
            $rest = $this->windowStats($path['points'], $toMinute, $sessionLen - 1);
            $day = $this->windowStats($path['points'], 0, $sessionLen - 1);

            $norm = $this->normalizedPath($path['points'], $toMinute);

            $matches[] = [
                'date' => $path['date'],
                'label' => $path['label'],
                'weekday' => (int) $path['weekday'],
                'setup_ret' => round($win['ret'], 3),
                'setup_max_dd' => round($win['max_dd'], 3),
                'setup_max_run' => round($win['max_run'], 3),
                'setup_start' => round($win['start'], 4),
                'setup_end' => round($win['end'], 4),
                'next_ret' => $next !== null ? round($next['ret'], 3) : null,
                'rest_ret' => $rest !== null ? round($rest['ret'], 3) : null,
                'day_ret' => $day !== null ? round($day['ret'], 3) : null,
                'norm_path' => $norm,
            ];
        }

        $n = count($matches);
        $bounce = 0;
        $continue = 0;
        $nextSum = 0.0;
        $nextN = 0;
        $restUp = 0;
        $restN = 0;
        $closeUp = 0;
        $closeN = 0;

        foreach ($matches as $m) {
            if ($m['next_ret'] !== null) {
                $nextN++;
                $nextSum += $m['next_ret'];
                if ($direction === 'down') {
                    if ($m['next_ret'] > 0) {
                        $bounce++;
                    }
                    if ($m['next_ret'] < 0) {
                        $continue++;
                    }
                } else {
                    if ($m['next_ret'] > 0) {
                        $continue++; // continuation up
                    }
                    if ($m['next_ret'] < 0) {
                        $bounce++; // fade
                    }
                }
            }
            if ($m['rest_ret'] !== null) {
                $restN++;
                if ($m['rest_ret'] > 0) {
                    $restUp++;
                }
            }
            if ($m['day_ret'] !== null) {
                $closeN++;
                if ($m['day_ret'] > 0) {
                    $closeUp++;
                }
            }
        }

        $strength = $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few');
        $medianPath = $this->medianNormPath($matches, $toMinute, $sessionLen - 1);

        $byWeekday = [];
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        for ($wd = 1; $wd <= 5; $wd++) {
            $subset = array_values(array_filter($matches, static fn ($m) => (int) $m['weekday'] === $wd));
            $byWeekday[] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'n' => count($subset),
            ];
        }

        return [
            'symbol' => $symbol,
            'direction' => $direction,
            'measure' => $measure,
            'threshold_pct' => $thresholdPct,
            'from_minute' => $fromMinute,
            'to_minute' => $toMinute,
            'from_time' => $this->session->minuteLabel($fromMinute),
            'to_time' => $this->session->minuteLabel($toMinute),
            'next_minutes' => $nextMinutes,
            'next_end_time' => $this->session->minuteLabel($nextEnd),
            'weekday_filter' => $weekday,
            'n' => $n,
            'strength' => $strength,
            'outcomes' => [
                'next_bounce_or_fade_n' => $bounce,
                'next_bounce_or_fade_pct' => $nextN > 0 ? round(100 * $bounce / $nextN, 1) : null,
                'next_continue_n' => $continue,
                'next_continue_pct' => $nextN > 0 ? round(100 * $continue / $nextN, 1) : null,
                'avg_next_ret' => $nextN > 0 ? round($nextSum / $nextN, 3) : null,
                'rest_up_pct' => $restN > 0 ? round(100 * $restUp / $restN, 1) : null,
                'close_up_pct' => $closeN > 0 ? round(100 * $closeUp / $closeN, 1) : null,
            ],
            'labels' => [
                'primary_good' => $direction === 'down' ? 'Bounce next period' : 'Continue up next period',
                'primary_bad' => $direction === 'down' ? 'Keep falling next period' : 'Fade next period',
            ],
            'summary_text' => $this->summaryText($direction, $thresholdPct, $n, $strength, $bounce, $continue, $nextN, $closeUp, $closeN, $nextSum),
            'matches' => $matches,
            'by_weekday' => $byWeekday,
            'median_path' => $medianPath,
            'path_times' => $medianPath['times'] ?? [],
        ];
    }

    /**
     * @param list<array{time:string,minute_of_day:int,price:float}> $points
     * @return ?array{ret:float,max_dd:float,max_run:float,start:float,end:float,low:float,high:float}
     */
    private function windowStats(array $points, int $from, int $to): ?array
    {
        $start = null;
        $end = null;
        $low = INF;
        $high = -INF;
        foreach ($points as $pt) {
            $m = (int) $pt['minute_of_day'];
            $p = (float) $pt['price'];
            if ($m < $from || $m > $to) {
                continue;
            }
            if ($start === null) {
                $start = $p;
            }
            $end = $p;
            if ($p < $low) {
                $low = $p;
            }
            if ($p > $high) {
                $high = $p;
            }
        }
        if ($start === null || $end === null || $start <= 0) {
            return null;
        }
        return [
            'ret' => (($end - $start) / $start) * 100.0,
            'max_dd' => (($low - $start) / $start) * 100.0,
            'max_run' => (($high - $start) / $start) * 100.0,
            'start' => $start,
            'end' => $end,
            'low' => $low,
            'high' => $high,
        ];
    }

    /**
     * Path after setup end, indexed so setup-end price = 100.
     *
     * @param list<array{minute_of_day:int,price:float}> $points
     * @return list<array{minute_of_day:int,time:string,norm:float}>
     */
    private function normalizedPath(array $points, int $anchorMinute): array
    {
        $anchor = null;
        foreach ($points as $pt) {
            if ((int) $pt['minute_of_day'] >= $anchorMinute) {
                $anchor = (float) $pt['price'];
                break;
            }
        }
        if ($anchor === null || $anchor <= 0) {
            return [];
        }
        $out = [];
        foreach ($points as $pt) {
            $m = (int) $pt['minute_of_day'];
            if ($m < $anchorMinute) {
                continue;
            }
            $out[] = [
                'minute_of_day' => $m,
                'time' => $this->session->minuteLabel($m),
                'norm' => round(((float) $pt['price'] / $anchor) * 100.0, 4),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @return array{times:list<string>,values:list<?float>}
     */
    private function medianNormPath(array $matches, int $fromMinute, int $toMinute): array
    {
        $bucket = [];
        foreach ($matches as $m) {
            foreach ($m['norm_path'] as $pt) {
                $mm = (int) $pt['minute_of_day'];
                if ($mm < $fromMinute || $mm > $toMinute) {
                    continue;
                }
                $bucket[$mm][] = (float) $pt['norm'];
            }
        }
        ksort($bucket);
        $times = [];
        $values = [];
        foreach ($bucket as $m => $vals) {
            sort($vals);
            $count = count($vals);
            $mid = intdiv($count, 2);
            $med = $count % 2 === 1 ? $vals[$mid] : (($vals[$mid - 1] + $vals[$mid]) / 2);
            $times[] = $this->session->minuteLabel((int) $m);
            $values[] = round($med, 4);
        }
        return ['times' => $times, 'values' => $values];
    }

    private function summaryText(
        string $direction,
        float $thresholdPct,
        int $n,
        string $strength,
        int $bounce,
        int $continue,
        int $nextN,
        int $closeUp,
        int $closeN,
        float $nextSum
    ): string {
        if ($n === 0) {
            return "No matching days for this setup in the loaded history.";
        }
        $avg = $nextN > 0 ? $nextSum / $nextN : 0.0;
        $bouncePct = $nextN > 0 ? round(100 * $bounce / $nextN) : 0;
        $contPct = $nextN > 0 ? round(100 * $continue / $nextN) : 0;
        $closePct = $closeN > 0 ? round(100 * $closeUp / $closeN) : 0;
        $tag = $strength === 'usable' ? 'usable' : ($strength === 'weak' ? 'weak sample' : 'too few samples');

        if ($direction === 'down') {
            return "On {$n} days ({$tag}) after a ≥{$thresholdPct}% drop in the window: "
                . "next period bounced {$bouncePct}% of the time, kept falling {$contPct}% "
                . "(avg next " . sprintf('%+.2f', $avg) . "%). Close finished green {$closePct}% of the time.";
        }
        return "On {$n} days ({$tag}) after a ≥{$thresholdPct}% rise in the window: "
            . "next period continued up {$contPct}% of the time, faded {$bouncePct}% "
            . "(avg next " . sprintf('%+.2f', $avg) . "%). Close finished green {$closePct}% of the time.";
    }

    /** @return list<array{id:string,label:string,from:int,to:int}> */
    public static function windowPresets(): array
    {
        return [
            ['id' => '0930_1000', 'label' => '09:30–10:00', 'from' => 0, 'to' => 30],
            ['id' => '0930_1030', 'label' => '09:30–10:30', 'from' => 0, 'to' => 60],
            ['id' => '1000_1100', 'label' => '10:00–11:00', 'from' => 30, 'to' => 90],
            ['id' => '1100_1200', 'label' => '11:00–12:00', 'from' => 90, 'to' => 150],
            ['id' => '1300_1400', 'label' => '13:00–14:00', 'from' => 210, 'to' => 270],
            ['id' => '1400_1500', 'label' => '14:00–15:00', 'from' => 270, 'to' => 330],
        ];
    }
}
