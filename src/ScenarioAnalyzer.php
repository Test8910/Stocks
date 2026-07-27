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
            'mode' => 'drop',
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

    /**
     * Opening-drive: if first N minutes are up / down / flat, what happens next?
     *
     * @param list<array<string,mixed>> $paths
     * @return array<string,mixed>
     */
    public function analyzeOpenDrive(
        array $paths,
        string $symbol,
        int $toMinute,
        string $condition, // up|down|flat
        float $thresholdPct,
        int $nextMinutes = 60,
        ?int $weekday = null
    ): array {
        $thresholdPct = abs($thresholdPct);
        $toMinute = max(5, $toMinute);
        $nextMinutes = max(15, min(240, $nextMinutes));
        $sessionLen = $this->session->sessionLengthMinutes();
        $nextEnd = min($sessionLen - 1, $toMinute + $nextMinutes);
        $condition = in_array($condition, ['up', 'down', 'flat'], true) ? $condition : 'up';

        $matches = [];
        foreach ($paths as $path) {
            if ($weekday !== null && (int) $path['weekday'] !== $weekday) {
                continue;
            }
            $win = $this->windowStats($path['points'], 0, $toMinute);
            if ($win === null) {
                continue;
            }

            $ret = $win['ret'];
            $hit = match ($condition) {
                'up' => $ret >= $thresholdPct,
                'down' => $ret <= -$thresholdPct,
                'flat' => abs($ret) < $thresholdPct,
            };
            if (!$hit) {
                continue;
            }

            $next = $this->windowStats($path['points'], $toMinute, $nextEnd);
            $rest = $this->windowStats($path['points'], $toMinute, $sessionLen - 1);
            $day = $this->windowStats($path['points'], 0, $sessionLen - 1);
            $extremes = $this->extremesAfter($path['points'], $toMinute, $sessionLen - 1);

            $giveback50 = null;
            if ($condition === 'up' && $ret > 0 && $rest !== null) {
                // gave back ≥50% of morning gain by close of rest window end price vs setup end
                $giveback50 = ($win['end'] - $rest['end']) >= (0.5 * ($win['end'] - $win['start']));
            } elseif ($condition === 'down' && $ret < 0 && $rest !== null) {
                // recovered ≥50% of morning loss
                $giveback50 = ($rest['end'] - $win['end']) >= (0.5 * ($win['start'] - $win['end']));
            }

            $matches[] = [
                'date' => $path['date'],
                'label' => $path['label'],
                'weekday' => (int) $path['weekday'],
                'setup_ret' => round($ret, 3),
                'setup_start' => round($win['start'], 4),
                'setup_end' => round($win['end'], 4),
                'next_ret' => $next !== null ? round($next['ret'], 3) : null,
                'rest_ret' => $rest !== null ? round($rest['ret'], 3) : null,
                'day_ret' => $day !== null ? round($day['ret'], 3) : null,
                'high_after' => $extremes['high_after'],
                'low_after' => $extremes['low_after'],
                'giveback_50' => $giveback50,
                'norm_path' => $this->normalizedPath($path['points'], $toMinute),
            ];
        }

        return $this->packOpenOrShapeResult(
            $symbol,
            'open',
            $condition,
            $thresholdPct,
            0,
            $toMinute,
            $nextMinutes,
            $nextEnd,
            $weekday,
            $matches,
            $sessionLen
        );
    }

    /**
     * Rule-based morning shapes → afternoon outcomes.
     *
     * @param list<array<string,mixed>> $paths
     * @return array<string,mixed>
     */
    public function analyzeShape(
        array $paths,
        string $symbol,
        string $shape, // grind_up|spike_fade|v_reclaim|waterfall
        int $setupEnd = 60,
        float $thresholdPct = 0.5,
        int $nextMinutes = 120,
        ?int $weekday = null
    ): array {
        $thresholdPct = abs($thresholdPct);
        $setupEnd = max(15, min(120, $setupEnd));
        $nextMinutes = max(15, min(240, $nextMinutes));
        $sessionLen = $this->session->sessionLengthMinutes();
        $nextEnd = min($sessionLen - 1, $setupEnd + $nextMinutes);
        $shape = in_array($shape, ['grind_up', 'spike_fade', 'v_reclaim', 'waterfall'], true)
            ? $shape
            : 'v_reclaim';

        $matches = [];
        foreach ($paths as $path) {
            if ($weekday !== null && (int) $path['weekday'] !== $weekday) {
                continue;
            }
            $win = $this->windowStats($path['points'], 0, $setupEnd);
            if ($win === null) {
                continue;
            }

            $classified = $this->classifyShape($win, $thresholdPct);
            if ($classified !== $shape) {
                continue;
            }

            $next = $this->windowStats($path['points'], $setupEnd, $nextEnd);
            $rest = $this->windowStats($path['points'], $setupEnd, $sessionLen - 1);
            $day = $this->windowStats($path['points'], 0, $sessionLen - 1);
            $extremes = $this->extremesAfter($path['points'], $setupEnd, $sessionLen - 1);

            $matches[] = [
                'date' => $path['date'],
                'label' => $path['label'],
                'weekday' => (int) $path['weekday'],
                'setup_ret' => round($win['ret'], 3),
                'setup_max_dd' => round($win['max_dd'], 3),
                'setup_max_run' => round($win['max_run'], 3),
                'setup_start' => round($win['start'], 4),
                'setup_end' => round($win['end'], 4),
                'shape' => $shape,
                'next_ret' => $next !== null ? round($next['ret'], 3) : null,
                'rest_ret' => $rest !== null ? round($rest['ret'], 3) : null,
                'day_ret' => $day !== null ? round($day['ret'], 3) : null,
                'high_after' => $extremes['high_after'],
                'low_after' => $extremes['low_after'],
                'norm_path' => $this->normalizedPath($path['points'], $setupEnd),
            ];
        }

        $result = $this->packOpenOrShapeResult(
            $symbol,
            'shape',
            $shape,
            $thresholdPct,
            0,
            $setupEnd,
            $nextMinutes,
            $nextEnd,
            $weekday,
            $matches,
            $sessionLen
        );
        $result['shape'] = $shape;
        $result['shape_labels'] = self::shapePresets();
        return $result;
    }

    /**
     * If lead symbol hits a morning condition, what does follow symbol do next?
     *
     * @param list<array<string,mixed>> $leadPaths
     * @param list<array<string,mixed>> $followPaths
     * @return array<string,mixed>
     */
    public function analyzeCross(
        array $leadPaths,
        array $followPaths,
        string $leadSymbol,
        string $followSymbol,
        int $toMinute,
        string $direction, // down|up
        float $thresholdPct,
        int $nextMinutes = 60,
        ?int $weekday = null
    ): array {
        $thresholdPct = abs($thresholdPct);
        $toMinute = max(5, $toMinute);
        $nextMinutes = max(15, min(240, $nextMinutes));
        $sessionLen = $this->session->sessionLengthMinutes();
        $nextEnd = min($sessionLen - 1, $toMinute + $nextMinutes);
        $direction = $direction === 'up' ? 'up' : 'down';

        $followByDate = [];
        foreach ($followPaths as $p) {
            $followByDate[$p['date']] = $p;
        }

        $matches = [];
        foreach ($leadPaths as $lead) {
            if ($weekday !== null && (int) $lead['weekday'] !== $weekday) {
                continue;
            }
            if (!isset($followByDate[$lead['date']])) {
                continue;
            }
            $follow = $followByDate[$lead['date']];

            $leadWin = $this->windowStats($lead['points'], 0, $toMinute);
            if ($leadWin === null) {
                continue;
            }
            $hit = $direction === 'up'
                ? $leadWin['ret'] >= $thresholdPct
                : $leadWin['ret'] <= -$thresholdPct;
            if (!$hit) {
                continue;
            }

            $followSetup = $this->windowStats($follow['points'], 0, $toMinute);
            $followNext = $this->windowStats($follow['points'], $toMinute, $nextEnd);
            $followRest = $this->windowStats($follow['points'], $toMinute, $sessionLen - 1);
            $followDay = $this->windowStats($follow['points'], 0, $sessionLen - 1);

            $matches[] = [
                'date' => $lead['date'],
                'label' => $lead['label'],
                'weekday' => (int) $lead['weekday'],
                'lead_ret' => round($leadWin['ret'], 3),
                'setup_ret' => $followSetup !== null ? round($followSetup['ret'], 3) : null,
                'next_ret' => $followNext !== null ? round($followNext['ret'], 3) : null,
                'rest_ret' => $followRest !== null ? round($followRest['ret'], 3) : null,
                'day_ret' => $followDay !== null ? round($followDay['ret'], 3) : null,
                'same_direction_setup' => $followSetup !== null
                    ? (($direction === 'up' && $followSetup['ret'] > 0) || ($direction === 'down' && $followSetup['ret'] < 0))
                    : null,
                'norm_path' => $this->normalizedPath($follow['points'], $toMinute),
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
        $sameDir = 0;
        $sameN = 0;

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
                        $continue++;
                    }
                    if ($m['next_ret'] < 0) {
                        $bounce++;
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
            if ($m['same_direction_setup'] !== null) {
                $sameN++;
                if ($m['same_direction_setup']) {
                    $sameDir++;
                }
            }
        }

        $strength = $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few');
        $medianPath = $this->medianNormPath($matches, $toMinute, $sessionLen - 1);

        $dirWord = $direction === 'down' ? 'drop' : 'rise';
        $summary = $n === 0
            ? "No days where {$leadSymbol} {$dirWord} ≥{$thresholdPct}% by {$this->session->minuteLabel($toMinute)}."
            : "On {$n} days ({$strength}) when {$leadSymbol} {$dirWord} ≥{$thresholdPct}% by {$this->session->minuteLabel($toMinute)}: "
                . "{$followSymbol} next {$nextMinutes}m "
                . ($direction === 'down'
                    ? "bounced " . ($nextN > 0 ? round(100 * $bounce / $nextN) : 0) . "%, kept falling " . ($nextN > 0 ? round(100 * $continue / $nextN) : 0) . "%"
                    : "continued up " . ($nextN > 0 ? round(100 * $continue / $nextN) : 0) . "%, faded " . ($nextN > 0 ? round(100 * $bounce / $nextN) : 0) . "%")
                . ". Same-direction morning on {$followSymbol}: " . ($sameN > 0 ? round(100 * $sameDir / $sameN) : 0) . "%.";

        return [
            'mode' => 'cross',
            'symbol' => $followSymbol,
            'lead_symbol' => $leadSymbol,
            'follow_symbol' => $followSymbol,
            'direction' => $direction,
            'threshold_pct' => $thresholdPct,
            'from_minute' => 0,
            'to_minute' => $toMinute,
            'from_time' => $this->session->minuteLabel(0),
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
                'same_direction_setup_pct' => $sameN > 0 ? round(100 * $sameDir / $sameN, 1) : null,
            ],
            'labels' => [
                'primary_good' => $direction === 'down'
                    ? "{$followSymbol} bounce next period"
                    : "{$followSymbol} continue up next period",
                'primary_bad' => $direction === 'down'
                    ? "{$followSymbol} keep falling next period"
                    : "{$followSymbol} fade next period",
            ],
            'summary_text' => $summary,
            'matches' => $matches,
            'by_weekday' => $this->weekdayCounts($matches),
            'median_path' => $medianPath,
            'path_times' => $medianPath['times'] ?? [],
        ];
    }

    /**
     * @param array{ret:float,max_dd:float,max_run:float,start:float,end:float,low:float,high:float} $win
     */
    private function classifyShape(array $win, float $thresholdPct): ?string
    {
        $ret = $win['ret'];
        $dd = $win['max_dd'];
        $run = $win['max_run'];

        // Waterfall: deep dump and finish near the low
        if ($dd <= -$thresholdPct && $ret <= -$thresholdPct * 0.7 && ($ret - $dd) < $thresholdPct * 0.35) {
            return 'waterfall';
        }
        // V reclaim: dipped hard then recovered most of it
        if ($dd <= -$thresholdPct && $ret > $dd + $thresholdPct * 0.5 && $ret >= -$thresholdPct * 0.25) {
            return 'v_reclaim';
        }
        // Spike then fade: ran hard, gave back ≥ half
        if ($run >= $thresholdPct && $ret < $run * 0.5 && $ret < $thresholdPct * 0.6) {
            return 'spike_fade';
        }
        // Grind up: finished up with shallow drawdown
        if ($ret >= $thresholdPct && $dd > -$thresholdPct * 0.5) {
            return 'grind_up';
        }
        return null;
    }

    /**
     * @param list<array{minute_of_day:int,price:float}> $points
     * @return array{high_after:bool,low_after:bool}
     */
    private function extremesAfter(array $points, int $afterMinute, int $toMinute): array
    {
        $preHigh = -INF;
        $preLow = INF;
        $postHigh = -INF;
        $postLow = INF;
        $hasPre = false;
        $hasPost = false;
        foreach ($points as $pt) {
            $m = (int) $pt['minute_of_day'];
            $p = (float) $pt['price'];
            if ($m <= $afterMinute) {
                $hasPre = true;
                if ($p > $preHigh) {
                    $preHigh = $p;
                }
                if ($p < $preLow) {
                    $preLow = $p;
                }
            } elseif ($m <= $toMinute) {
                $hasPost = true;
                if ($p > $postHigh) {
                    $postHigh = $p;
                }
                if ($p < $postLow) {
                    $postLow = $p;
                }
            }
        }
        if (!$hasPre || !$hasPost) {
            return ['high_after' => false, 'low_after' => false];
        }
        return [
            'high_after' => $postHigh > $preHigh,
            'low_after' => $postLow < $preLow,
        ];
    }

    /**
     * @param list<array<string,mixed>> $matches
     * @return array<string,mixed>
     */
    private function packOpenOrShapeResult(
        string $symbol,
        string $mode,
        string $condition,
        float $thresholdPct,
        int $fromMinute,
        int $toMinute,
        int $nextMinutes,
        int $nextEnd,
        ?int $weekday,
        array $matches,
        int $sessionLen
    ): array {
        $n = count($matches);
        $bounce = 0;
        $continue = 0;
        $nextSum = 0.0;
        $nextN = 0;
        $restUp = 0;
        $restN = 0;
        $closeUp = 0;
        $closeN = 0;
        $highAfter = 0;
        $lowAfter = 0;
        $extN = 0;
        $giveback = 0;
        $giveN = 0;

        $isDownish = in_array($condition, ['down', 'waterfall'], true);
        $isUpish = in_array($condition, ['up', 'grind_up', 'spike_fade'], true);

        foreach ($matches as $m) {
            if ($m['next_ret'] !== null) {
                $nextN++;
                $nextSum += $m['next_ret'];
                if ($isDownish) {
                    if ($m['next_ret'] > 0) {
                        $bounce++;
                    }
                    if ($m['next_ret'] < 0) {
                        $continue++;
                    }
                } elseif ($isUpish) {
                    if ($m['next_ret'] > 0) {
                        $continue++;
                    }
                    if ($m['next_ret'] < 0) {
                        $bounce++;
                    }
                } else {
                    // flat / v_reclaim: treat bounce as next up
                    if ($m['next_ret'] > 0) {
                        $bounce++;
                    }
                    if ($m['next_ret'] < 0) {
                        $continue++;
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
            if (isset($m['high_after'], $m['low_after'])) {
                $extN++;
                if ($m['high_after']) {
                    $highAfter++;
                }
                if ($m['low_after']) {
                    $lowAfter++;
                }
            }
            if (array_key_exists('giveback_50', $m) && $m['giveback_50'] !== null) {
                $giveN++;
                if ($m['giveback_50']) {
                    $giveback++;
                }
            }
        }

        $strength = $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few');
        $medianPath = $this->medianNormPath($matches, $toMinute, $sessionLen - 1);

        if ($mode === 'shape') {
            $labels = [
                'primary_good' => $isDownish ? 'Bounce next period' : ($isUpish ? 'Continue up next period' : 'Next period up'),
                'primary_bad' => $isDownish ? 'Keep falling next period' : ($isUpish ? 'Fade next period' : 'Next period down'),
            ];
            $shapeNames = [
                'grind_up' => 'grind up',
                'spike_fade' => 'spike then fade',
                'v_reclaim' => 'V reclaim',
                'waterfall' => 'waterfall dump',
            ];
            $name = $shapeNames[$condition] ?? $condition;
            $summary = $n === 0
                ? "No {$name} mornings in the loaded history (threshold {$thresholdPct}%)."
                : "On {$n} {$name} mornings ({$strength}): next period "
                    . ($nextN > 0 ? round(100 * ($isDownish || !$isUpish ? $bounce : $continue) / $nextN) : 0)
                    . "% favorable, close green " . ($closeN > 0 ? round(100 * $closeUp / $closeN) : 0) . "%.";
        } else {
            $labels = [
                'primary_good' => $condition === 'down' ? 'Bounce next period' : ($condition === 'up' ? 'Continue up next period' : 'Next period up'),
                'primary_bad' => $condition === 'down' ? 'Keep falling next period' : ($condition === 'up' ? 'Fade next period' : 'Next period down'),
            ];
            $condWord = $condition === 'flat' ? 'flat' : ($condition === 'up' ? "up ≥{$thresholdPct}%" : "down ≥{$thresholdPct}%");
            $summary = $n === 0
                ? "No matching open-drive days ({$condWord} by {$this->session->minuteLabel($toMinute)})."
                : "On {$n} days ({$strength}) with open {$condWord}: "
                    . "next period " . ($condition === 'up'
                        ? "continued " . ($nextN > 0 ? round(100 * $continue / $nextN) : 0) . "%, faded " . ($nextN > 0 ? round(100 * $bounce / $nextN) : 0) . "%"
                        : ($condition === 'down'
                            ? "bounced " . ($nextN > 0 ? round(100 * $bounce / $nextN) : 0) . "%, kept falling " . ($nextN > 0 ? round(100 * $continue / $nextN) : 0) . "%"
                            : "went up " . ($nextN > 0 ? round(100 * $bounce / $nextN) : 0) . "%"))
                    . ". Day high after open window: " . ($extN > 0 ? round(100 * $highAfter / $extN) : 0) . "%."
                    . " Close green: " . ($closeN > 0 ? round(100 * $closeUp / $closeN) : 0) . "%.";
        }

        return [
            'mode' => $mode,
            'symbol' => $symbol,
            'direction' => $condition,
            'condition' => $condition,
            'measure' => 'end',
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
                'high_after_pct' => $extN > 0 ? round(100 * $highAfter / $extN, 1) : null,
                'low_after_pct' => $extN > 0 ? round(100 * $lowAfter / $extN, 1) : null,
                'giveback_50_pct' => $giveN > 0 ? round(100 * $giveback / $giveN, 1) : null,
            ],
            'labels' => $labels,
            'summary_text' => $summary,
            'matches' => $matches,
            'by_weekday' => $this->weekdayCounts($matches),
            'median_path' => $medianPath,
            'path_times' => $medianPath['times'] ?? [],
        ];
    }

    /** @param list<array<string,mixed>> $matches @return list<array{weekday:int,label:string,n:int}> */
    private function weekdayCounts(array $matches): array
    {
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $out = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $subset = array_values(array_filter($matches, static fn ($m) => (int) $m['weekday'] === $wd));
            $out[] = ['weekday' => $wd, 'label' => $labels[$wd], 'n' => count($subset)];
        }
        return $out;
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

    /** @return list<array{id:string,label:string,to:int}> */
    public static function openWindowPresets(): array
    {
        return [
            ['id' => 'first_15', 'label' => 'First 15m (→09:45)', 'to' => 15],
            ['id' => 'first_30', 'label' => 'First 30m (→10:00)', 'to' => 30],
            ['id' => 'first_60', 'label' => 'First 60m (→10:30)', 'to' => 60],
        ];
    }

    /** @return list<array{id:string,label:string}> */
    public static function shapePresets(): array
    {
        return [
            ['id' => 'grind_up', 'label' => 'Grind up'],
            ['id' => 'spike_fade', 'label' => 'Spike then fade'],
            ['id' => 'v_reclaim', 'label' => 'V reclaim'],
            ['id' => 'waterfall', 'label' => 'Waterfall dump'],
        ];
    }
}
