<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Combined rule: UK session already down + US first N minutes dump.
 * Answers: when London is weak and SOXL/QQQ open hard red, what usually follows?
 */
final class UkOpenCrashAnalyzer
{
    public function __construct(
        private readonly StatsService $stats
    ) {
    }

    /**
     * @param list<array<string,mixed>> $symbolMeta
     * @return array<string,mixed>
     */
    public function analyze(
        array $symbolMeta,
        string $ukSymbol = 'EQQQ',
        string $usSymbol = 'SOXL',
        float $ukThresholdPct = 0.3,
        float $usOpenThresholdPct = 2.0,
        int $openWindowMinutes = 15,
        ?string $focusDate = null
    ): array {
        $ukSymbol = strtoupper($ukSymbol);
        $usSymbol = strtoupper($usSymbol);
        $ukThresholdPct = abs($ukThresholdPct);
        $usOpenThresholdPct = abs($usOpenThresholdPct);
        $openWindowMinutes = in_array($openWindowMinutes, [15, 30], true) ? $openWindowMinutes : 15;

        $bySymbol = [];
        foreach ($symbolMeta as $m) {
            $bySymbol[strtoupper((string) $m['symbol'])] = $m;
        }

        $ukDaily = $this->dailySessionReturns($ukSymbol);
        $usPaths = $this->stats->sessionPaths($usSymbol);
        $usFeatures = [];
        foreach ($usPaths as $path) {
            $f = $this->usOpenFeatures($path, $openWindowMinutes);
            if ($f !== null) {
                $usFeatures[$f['date']] = $f;
            }
        }

        $dates = array_values(array_intersect(array_keys($ukDaily), array_keys($usFeatures)));
        rsort($dates);

        $rows = [];
        foreach ($dates as $date) {
            $uk = $ukDaily[$date];
            $us = $usFeatures[$date];
            $ukDown = $uk['ret'] <= -$ukThresholdPct;
            $usCrash = $us['open_ret'] <= -$usOpenThresholdPct;
            $rows[] = [
                'date' => $date,
                'weekday' => $us['weekday'],
                'label' => $us['label'],
                'uk_ret' => $uk['ret'],
                'us_open_ret' => $us['open_ret'],
                'us_r30' => $us['r30'],
                'us_r60' => $us['r60'],
                'us_next_ret' => $us['next_ret'],
                'us_day_ret' => $us['day_ret'],
                'us_open' => $us['open'],
                'us_close' => $us['close'],
                'uk_down' => $ukDown,
                'us_open_crash' => $usCrash,
                'combined_hit' => $ukDown && $usCrash,
                'continued_to_1030' => $us['r60'] !== null && $us['r60'] < $us['open_ret'],
                'green_close' => $us['day_ret'] > 0,
            ];
        }

        $combined = array_values(array_filter($rows, static fn ($r) => $r['combined_hit']));
        $usOnly = array_values(array_filter(
            $rows,
            static fn ($r) => $r['us_open_crash'] && !$r['uk_down']
        ));
        $ukOnly = array_values(array_filter(
            $rows,
            static fn ($r) => $r['uk_down'] && !$r['us_open_crash']
        ));

        $focus = $focusDate;
        if ($focus === null || $focus === '') {
            $focus = $dates[0] ?? null;
        }
        $today = null;
        foreach ($rows as $r) {
            if ($r['date'] === $focus) {
                $today = $r;
                break;
            }
        }

        $combinedStats = $this->cohortStats($combined, 'UK down + US open crash');
        $usOnlyStats = $this->cohortStats($usOnly, 'US open crash but UK not down');
        $ukOnlyStats = $this->cohortStats($ukOnly, 'UK down but US open not crashed');

        $live = $this->liveSignal($today, $ukThresholdPct, $usOpenThresholdPct, $openWindowMinutes);

        return [
            'ok' => true,
            'uk_symbol' => $ukSymbol,
            'us_symbol' => $usSymbol,
            'uk_meta' => $bySymbol[$ukSymbol] ?? null,
            'us_meta' => $bySymbol[$usSymbol] ?? null,
            'uk_threshold_pct' => $ukThresholdPct,
            'us_open_threshold_pct' => $usOpenThresholdPct,
            'open_window_minutes' => $openWindowMinutes,
            'open_window_label' => $openWindowMinutes === 15 ? '09:30–09:45' : '09:30–10:00',
            'focus_date' => $focus,
            'today' => $today,
            'live' => $live,
            'cohort_combined' => $combinedStats,
            'cohort_us_only' => $usOnlyStats,
            'cohort_uk_only' => $ukOnlyStats,
            'matches' => array_slice($combined, 0, 20),
            'contrast_us_only' => array_slice($usOnly, 0, 12),
            'days' => $rows,
            'n_days' => count($rows),
            'strength' => count($combined) >= 8 ? 'usable' : (count($combined) >= 3 ? 'weak' : 'too_few'),
            'summary_text' => $this->summary(
                $ukSymbol,
                $usSymbol,
                $ukThresholdPct,
                $usOpenThresholdPct,
                $openWindowMinutes,
                $combinedStats,
                $usOnlyStats,
                $today,
                $live
            ),
            'presets' => [
                'uk_thresholds' => [0.3, 0.5, 1.0],
                'us_open_thresholds' => [2.0, 3.0, 4.0],
                'windows' => [15, 30],
            ],
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @return array<string,array{ret:float,open:float,close:float,weekday:int,label:string}>
     */
    private function dailySessionReturns(string $symbol): array
    {
        $out = [];
        foreach ($this->stats->sessionPaths($symbol) as $path) {
            $points = $path['points'] ?? [];
            if (count($points) < 2) {
                continue;
            }
            $open = (float) $points[0]['price'];
            $close = (float) $points[count($points) - 1]['price'];
            if ($open <= 0) {
                continue;
            }
            $out[(string) $path['date']] = [
                'ret' => round((($close - $open) / $open) * 100.0, 3),
                'open' => round($open, 4),
                'close' => round($close, 4),
                'weekday' => (int) $path['weekday'],
                'label' => (string) ($path['label'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $path
     * @return ?array<string,mixed>
     */
    private function usOpenFeatures(array $path, int $openWindowMinutes): ?array
    {
        $points = $path['points'] ?? [];
        if (count($points) < max(15, $openWindowMinutes)) {
            return null;
        }
        $by = [];
        foreach ($points as $pt) {
            $by[(int) $pt['minute_of_day']] = (float) $pt['price'];
        }
        $at = static function (int $m) use ($by): ?float {
            if (isset($by[$m])) {
                return $by[$m];
            }
            for ($d = 1; $d <= 8; $d++) {
                if (isset($by[$m - $d])) {
                    return $by[$m - $d];
                }
                if (isset($by[$m + $d])) {
                    return $by[$m + $d];
                }
            }
            return null;
        };

        $open = $at(0);
        $openEnd = $at($openWindowMinutes);
        $p30 = $at(30);
        $p60 = $at(60);
        $close = null;
        foreach ($by as $p) {
            $close = $p;
        }
        if ($open === null || $openEnd === null || $close === null || $open <= 0) {
            return null;
        }

        $openRet = (($openEnd - $open) / $open) * 100.0;
        $nextEnd = min(389, $openWindowMinutes + 60);
        $nextPx = $at($nextEnd);
        $nextRet = $nextPx !== null ? (($nextPx - $openEnd) / $openEnd) * 100.0 : null;

        return [
            'date' => (string) $path['date'],
            'weekday' => (int) $path['weekday'],
            'label' => (string) ($path['label'] ?? ''),
            'open' => round($open, 4),
            'close' => round($close, 4),
            'open_ret' => round($openRet, 3),
            'r30' => $p30 !== null ? round((($p30 - $open) / $open) * 100.0, 3) : null,
            'r60' => $p60 !== null ? round((($p60 - $open) / $open) * 100.0, 3) : null,
            'next_ret' => $nextRet !== null ? round($nextRet, 3) : null,
            'day_ret' => round((($close - $open) / $open) * 100.0, 3),
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function cohortStats(array $rows, string $label): array
    {
        $n = count($rows);
        if ($n === 0) {
            return [
                'label' => $label,
                'n' => 0,
                'strength' => 'too_few',
                'green_close_pct' => null,
                'avg_day_ret' => null,
                'avg_open_ret' => null,
                'avg_r60' => null,
                'avg_next_ret' => null,
                'continued_to_1030_pct' => null,
                'dates' => [],
            ];
        }

        $green = 0;
        $continued = 0;
        $sumDay = 0.0;
        $sumOpen = 0.0;
        $sum60 = 0.0;
        $n60 = 0;
        $sumNext = 0.0;
        $nNext = 0;
        foreach ($rows as $r) {
            $sumDay += (float) $r['us_day_ret'];
            $sumOpen += (float) $r['us_open_ret'];
            if ($r['green_close']) {
                $green++;
            }
            if (!empty($r['continued_to_1030'])) {
                $continued++;
            }
            if ($r['us_r60'] !== null) {
                $sum60 += (float) $r['us_r60'];
                $n60++;
            }
            if ($r['us_next_ret'] !== null) {
                $sumNext += (float) $r['us_next_ret'];
                $nNext++;
            }
        }

        return [
            'label' => $label,
            'n' => $n,
            'strength' => $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few'),
            'green_close_pct' => round(100 * $green / $n, 1),
            'avg_day_ret' => round($sumDay / $n, 3),
            'avg_open_ret' => round($sumOpen / $n, 3),
            'avg_r60' => $n60 > 0 ? round($sum60 / $n60, 3) : null,
            'avg_next_ret' => $nNext > 0 ? round($sumNext / $nNext, 3) : null,
            'continued_to_1030_pct' => round(100 * $continued / $n, 1),
            'dates' => array_column($rows, 'date'),
        ];
    }

    /**
     * @param ?array<string,mixed> $today
     * @return array<string,mixed>
     */
    private function liveSignal(?array $today, float $ukThresh, float $usThresh, int $window): array
    {
        if ($today === null) {
            return [
                'status' => 'no_data',
                'fired' => false,
                'level' => 'none',
                'note' => 'No overlapping UK/US session for the focus date.',
            ];
        }

        $ukDown = !empty($today['uk_down']);
        $usCrash = !empty($today['us_open_crash']);
        if ($ukDown && $usCrash) {
            $level = $today['uk_ret'] <= -1.0 && $today['us_open_ret'] <= -3.0 ? 'severe' : 'active';
            return [
                'status' => 'fired',
                'fired' => true,
                'level' => $level,
                'note' => sprintf(
                    'Rule fired: %s %+.2f%% and US first %dm %+.2f%% (thresholds UK ≤−%.1f%%, US ≤−%.1f%%).',
                    'UK',
                    $today['uk_ret'],
                    $window,
                    $today['us_open_ret'],
                    $ukThresh,
                    $usThresh
                ),
            ];
        }

        if ($usCrash && !$ukDown) {
            return [
                'status' => 'us_only',
                'fired' => false,
                'level' => 'watch',
                'note' => sprintf(
                    'US open crash (%+.2f%%) without UK confirmation (UK %+.2f%%). History shows more bounce risk.',
                    $today['us_open_ret'],
                    $today['uk_ret']
                ),
            ];
        }

        if ($ukDown && !$usCrash) {
            return [
                'status' => 'uk_only',
                'fired' => false,
                'level' => 'watch',
                'note' => sprintf(
                    'UK down (%+.2f%%) but US first %dm only %+.2f%% — below crash threshold.',
                    $today['uk_ret'],
                    $window,
                    $today['us_open_ret']
                ),
            ];
        }

        return [
            'status' => 'quiet',
            'fired' => false,
            'level' => 'none',
            'note' => 'Neither UK-down nor US open-crash thresholds hit for this day.',
        ];
    }

    /**
     * @param array<string,mixed> $combined
     * @param array<string,mixed> $usOnly
     * @param ?array<string,mixed> $today
     * @param array<string,mixed> $live
     */
    private function summary(
        string $uk,
        string $us,
        float $ukThresh,
        float $usThresh,
        int $window,
        array $combined,
        array $usOnly,
        ?array $today,
        array $live
    ): string {
        $msg = sprintf(
            'Rule: %s ≤ −%.1f%% and %s first %dm ≤ −%.1f%%. ',
            $uk,
            $ukThresh,
            $us,
            $window,
            $usThresh
        );
        if (($combined['n'] ?? 0) > 0) {
            $msg .= sprintf(
                'Combined hits N=%d: green close %s%%, avg day %+0.2f%%, continued lower into 10:30 on %s%%. ',
                $combined['n'],
                $combined['green_close_pct'],
                $combined['avg_day_ret'],
                $combined['continued_to_1030_pct']
            );
        } else {
            $msg .= 'No combined hits in loaded history. ';
        }
        if (($usOnly['n'] ?? 0) > 0) {
            $msg .= sprintf(
                'Contrast (US crash, UK not down) N=%d: green %s%%, avg day %+0.2f%%. ',
                $usOnly['n'],
                $usOnly['green_close_pct'],
                $usOnly['avg_day_ret']
            );
        }
        if ($today !== null) {
            $msg .= sprintf(
                'Focus %s: UK %+0.2f%%, US open %+0.2f%%, day %+0.2f%%. ',
                $today['date'],
                $today['uk_ret'],
                $today['us_open_ret'],
                $today['us_day_ret']
            );
        }
        $msg .= $live['note'] ?? '';
        return trim($msg);
    }
}
