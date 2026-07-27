<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Classify a session day shape and find similar historical days.
 */
final class DayPatternMatcher
{
    public function __construct(private readonly SessionFilter $session)
    {
    }

    /**
     * @param list<array<string,mixed>> $paths
     * @return array<string,mixed>
     */
    public function analyze(array $paths, string $symbol, ?string $focusDate = null): array
    {
        $features = [];
        foreach ($paths as $path) {
            $f = $this->extract($path);
            if ($f !== null) {
                $features[] = $f;
            }
        }
        if ($features === []) {
            return [
                'ok' => false,
                'error' => 'No session paths to analyze',
            ];
        }

        usort($features, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        $focusDate = $focusDate ?: $features[0]['date'];
        $today = null;
        foreach ($features as $f) {
            if ($f['date'] === $focusDate) {
                $today = $f;
                break;
            }
        }
        if ($today === null) {
            $today = $features[0];
            $focusDate = $today['date'];
        }

        $matches = [];
        foreach ($features as $f) {
            if ($f['date'] === $today['date']) {
                continue;
            }
            if (empty($f['complete'])) {
                continue;
            }
            $score = $this->similarity($today, $f);
            if ($score >= 4) {
                $matches[] = $f + ['score' => $score];
            }
        }
        usort($matches, static fn ($a, $b) => $b['score'] <=> $a['score']);

        $cohort = array_values(array_filter(
            $features,
            static fn ($f) => $f['date'] !== $today['date']
                && !empty($f['complete'])
                && $f['r60'] <= -5.0
        ));

        $sameShape = array_values(array_filter(
            $features,
            static fn ($f) => $f['date'] !== $today['date']
                && !empty($f['complete'])
                && $f['pattern_id'] === $today['pattern_id']
        ));

        $story = $this->story($today);
        $cohortStats = $this->cohortStats($cohort, 'AM crash (≤−5% by 10:30)');
        $shapeStats = $this->cohortStats($sameShape, 'Same pattern: ' . $today['pattern_label']);
        $topMatches = array_slice($matches, 0, 12);
        $projected = $this->projectClose($today, $topMatches, $cohortStats, $shapeStats);

        // Keep match payloads light for the UI (timeline only on focus day).
        $matchRows = array_map(static function (array $m): array {
            unset($m['timeline']);
            return $m;
        }, $topMatches);

        return [
            'ok' => true,
            'symbol' => $symbol,
            'focus_date' => $focusDate,
            'today' => $today,
            'story' => $story,
            'matches' => $matchRows,
            'cohort_am_crash' => $cohortStats,
            'cohort_same_pattern' => $shapeStats,
            'projected_close' => $projected,
            'pattern_catalog' => $this->catalogCounts($features),
            'summary_text' => $this->summary($today, $topMatches, $cohortStats, $shapeStats, $projected),
            'timeline' => $today['timeline'],
        ];
    }

    /**
     * @param array<string,mixed> $path
     * @return ?array<string,mixed>
     */
    private function extract(array $path): ?array
    {
        $points = $path['points'] ?? [];
        if (count($points) < 30) {
            return null;
        }
        $by = [];
        foreach ($points as $pt) {
            $by[(int) $pt['minute_of_day']] = (float) $pt['price'];
        }
        $at = function (int $m) use ($by): ?float {
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
        $close = null;
        $lastMinute = null;
        foreach ($by as $m => $p) {
            $close = $p;
            $lastMinute = (int) $m;
        }
        if ($open === null || $close === null || $open <= 0) {
            return null;
        }
        // Regular cash session is ~390 minutes (09:30–16:00). Treat <360 as incomplete/live.
        $complete = $lastMinute !== null && $lastMinute >= 360;

        $marks = [15, 30, 45, 60, 90, 120, 180, 240, 300, 330];
        $rets = [];
        foreach ($marks as $m) {
            $px = $at($m);
            $rets['r' . $m] = $px !== null ? (($px - $open) / $open) * 100.0 : null;
        }

        $low = INF;
        $lowM = null;
        $high = -INF;
        $highM = null;
        $amLow = INF;
        $amLowM = null;
        foreach ($by as $m => $p) {
            if ($p < $low) {
                $low = $p;
                $lowM = $m;
            }
            if ($p > $high) {
                $high = $p;
                $highM = $m;
            }
            if ($m <= 90 && $p < $amLow) {
                $amLow = $p;
                $amLowM = $m;
            }
        }

        $dayRet = (($close - $open) / $open) * 100.0;
        $r60 = $rets['r60'];
        $closeFromLow = $low > 0 ? (($close - $low) / $low) * 100.0 : 0.0;
        $closeFromAm = ($r60 !== null) ? ($dayRet - $r60) : null;

        [$patternId, $patternLabel] = $this->classify($dayRet, $r60, $closeFromLow, $closeFromAm, $amLowM);

        $timeline = [];
        foreach ([0, 15, 30, 45, 60, 90, 120, 180, 240, 300, 330, 389] as $m) {
            $px = $at($m);
            if ($px === null) {
                continue;
            }
            $timeline[] = [
                'minute_of_day' => $m,
                'time' => $this->session->minuteLabel($m),
                'price' => round($px, 4),
                'ret' => round((($px - $open) / $open) * 100.0, 3),
            ];
        }

        return [
            'date' => (string) $path['date'],
            'label' => (string) ($path['label'] ?? ''),
            'weekday' => (int) $path['weekday'],
            'complete' => $complete,
            'last_minute' => $lastMinute,
            'last_time' => $lastMinute !== null ? $this->session->minuteLabel($lastMinute) : null,
            'open' => round($open, 4),
            'close' => round($close, 4),
            'day_ret' => round($dayRet, 3),
            'r15' => $rets['r15'] !== null ? round($rets['r15'], 3) : null,
            'r30' => $rets['r30'] !== null ? round($rets['r30'], 3) : null,
            'r45' => $rets['r45'] !== null ? round($rets['r45'], 3) : null,
            'r60' => $r60 !== null ? round($r60, 3) : null,
            'r90' => $rets['r90'] !== null ? round($rets['r90'], 3) : null,
            'r120' => $rets['r120'] !== null ? round($rets['r120'], 3) : null,
            'low' => round($low, 4),
            'high' => round($high, 4),
            'low_minute' => $lowM,
            'high_minute' => $highM,
            'low_time' => $lowM !== null ? $this->session->minuteLabel($lowM) : null,
            'high_time' => $highM !== null ? $this->session->minuteLabel($highM) : null,
            'am_low_minute' => $amLowM,
            'am_low_time' => $amLowM !== null ? $this->session->minuteLabel($amLowM) : null,
            'close_from_low' => round($closeFromLow, 3),
            'close_vs_1030' => $closeFromAm !== null ? round($closeFromAm, 3) : null,
            'pattern_id' => $patternId,
            'pattern_label' => $patternLabel,
            'timeline' => $timeline,
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function classify(
        float $dayRet,
        ?float $r60,
        float $closeFromLow,
        ?float $closeFromAm,
        ?int $amLowM
    ): array {
        if ($r60 !== null && $r60 <= -8.0) {
            if ($closeFromLow >= 5.0) {
                return ['severe_crash_partial_reclaim', 'Severe AM crash + partial reclaim'];
            }
            return ['severe_am_crash', 'Severe AM crash (weak reclaim)'];
        }
        if ($r60 !== null && $r60 <= -5.0) {
            if ($closeFromAm !== null && $closeFromAm >= 3.0) {
                return ['am_crash_bounce', 'AM crash then bounce'];
            }
            return ['waterfall_weak_close', 'Waterfall / weak close'];
        }
        if ($r60 !== null && $r60 >= 3.0 && $dayRet >= 1.0) {
            return ['strong_open_hold', 'Strong open and hold'];
        }
        if ($r60 !== null && $r60 >= 2.0 && $dayRet < 0) {
            return ['open_up_fade', 'Open up then fade'];
        }
        if ($dayRet >= 1.0 && $amLowM !== null && $amLowM <= 60) {
            return ['early_dip_green', 'Early dip then green close'];
        }
        if ($dayRet <= -2.0) {
            return ['down_day', 'Down day'];
        }
        if ($dayRet >= 2.0) {
            return ['up_day', 'Up day'];
        }
        return ['chop', 'Chop / mixed'];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function similarity(array $a, array $b): int
    {
        $score = 0;
        if ($a['r60'] !== null && $b['r60'] !== null && abs($a['r60'] - $b['r60']) <= 4.0) {
            $score += 2;
        }
        if ($a['r30'] !== null && $b['r30'] !== null && abs($a['r30'] - $b['r30']) <= 3.0) {
            $score += 1;
        }
        if (($a['r60'] ?? 0) <= -5.0 && ($b['r60'] ?? 0) <= -5.0) {
            $score += 2;
        }
        if (($a['am_low_minute'] ?? 999) <= 120 && ($b['am_low_minute'] ?? 999) <= 120) {
            $score += 1;
        }
        if (($a['day_ret'] < 0) === ($b['day_ret'] < 0)) {
            $score += 1;
        }
        if ($a['pattern_id'] === $b['pattern_id']) {
            $score += 2;
        }
        if ($a['weekday'] === $b['weekday']) {
            $score += 1;
        }
        return $score;
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
                'closed_better_than_1030_pct' => null,
                'close_green_pct' => null,
                'avg_day_ret' => null,
                'avg_reclaim_from_low' => null,
                'avg_close_vs_1030' => null,
                'dates' => [],
            ];
        }
        $better = 0;
        $green = 0;
        $sumDay = 0.0;
        $sumReclaim = 0.0;
        $sumVs = 0.0;
        $vsN = 0;
        foreach ($rows as $r) {
            $sumDay += $r['day_ret'];
            $sumReclaim += $r['close_from_low'];
            if ($r['day_ret'] > 0) {
                $green++;
            }
            if ($r['r60'] !== null && $r['day_ret'] > $r['r60']) {
                $better++;
            }
            if ($r['close_vs_1030'] !== null) {
                $sumVs += $r['close_vs_1030'];
                $vsN++;
            }
        }
        return [
            'label' => $label,
            'n' => $n,
            'strength' => $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few'),
            'closed_better_than_1030_pct' => round(100 * $better / $n, 1),
            'close_green_pct' => round(100 * $green / $n, 1),
            'avg_day_ret' => round($sumDay / $n, 3),
            'avg_reclaim_from_low' => round($sumReclaim / $n, 3),
            'avg_close_vs_1030' => $vsN > 0 ? round($sumVs / $vsN, 3) : null,
            'dates' => array_column($rows, 'date'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $features
     * @return list<array{id:string,label:string,n:int}>
     */
    private function catalogCounts(array $features): array
    {
        $counts = [];
        $labels = [];
        foreach ($features as $f) {
            $id = $f['pattern_id'];
            $counts[$id] = ($counts[$id] ?? 0) + 1;
            $labels[$id] = $f['pattern_label'];
        }
        $out = [];
        foreach ($counts as $id => $n) {
            $out[] = ['id' => $id, 'label' => $labels[$id], 'n' => $n];
        }
        usort($out, static fn ($a, $b) => $b['n'] <=> $a['n']);
        return $out;
    }

    /** @param array<string,mixed> $t */
    private function story(array $t): string
    {
        $parts = [];
        $verb = !empty($t['complete']) ? 'open→close' : 'open→last';
        $parts[] = sprintf(
            '%s %s: %s %+0.2f%% (open %.2f → %.2f%s).',
            $t['date'],
            $t['label'],
            $verb,
            $t['day_ret'],
            $t['open'],
            $t['close'],
            !empty($t['complete']) ? '' : ' live'
        );
        if ($t['r60'] !== null) {
            $parts[] = sprintf('By 10:30: %+0.2f%%.', $t['r60']);
        }
        if ($t['low_time'] !== null) {
            $parts[] = sprintf('Day low %.2f at %s.', $t['low'], $t['low_time']);
        }
        $parts[] = sprintf('Pattern: %s. Reclaim from low %+0.2f%%.', $t['pattern_label'], $t['close_from_low']);
        if ($t['close_vs_1030'] !== null) {
            $parts[] = sprintf('Close vs 10:30 mark: %+0.2f%%.', $t['close_vs_1030']);
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $today
     * @param list<array<string,mixed>> $matches
     * @param array<string,mixed> $cohort
     * @param array<string,mixed> $shape
     * @return ?array<string,mixed>
     */
    private function projectClose(array $today, array $matches, array $cohort, array $shape): ?array
    {
        if (!empty($today['complete'])) {
            return [
                'status' => 'complete',
                'actual_day_pct' => $today['day_ret'],
                'actual_close' => $today['close'],
                'note' => 'Session complete — projection not needed.',
            ];
        }

        $source = null;
        $avg = null;
        if (($shape['n'] ?? 0) >= 2 && $shape['avg_day_ret'] !== null) {
            $source = 'same_pattern';
            $avg = (float) $shape['avg_day_ret'];
        } elseif ($matches !== []) {
            $source = 'top_matches';
            $slice = array_slice($matches, 0, min(5, count($matches)));
            $sum = 0.0;
            foreach ($slice as $m) {
                $sum += (float) $m['day_ret'];
            }
            $avg = $sum / count($slice);
        } elseif (($cohort['n'] ?? 0) >= 2 && $cohort['avg_day_ret'] !== null) {
            $source = 'am_crash_cohort';
            $avg = (float) $cohort['avg_day_ret'];
        }

        if ($avg === null) {
            return [
                'status' => 'insufficient',
                'note' => 'Not enough similar days to project a close.',
            ];
        }

        $open = (float) $today['open'];
        $expectedClose = $open * (1.0 + $avg / 100.0);
        return [
            'status' => 'projected',
            'method' => $source,
            'expected_day_pct' => round($avg, 3),
            'expected_close' => round($expectedClose, 4),
            'current_day_pct' => $today['day_ret'],
            'current_price' => $today['close'],
            'note' => sprintf(
                'Based on %s history, similar days closed around %+0.2f%% (~%.2f).',
                str_replace('_', ' ', (string) $source),
                $avg,
                $expectedClose
            ),
        ];
    }

    /**
     * @param array<string,mixed> $today
     * @param list<array<string,mixed>> $matches
     * @param array<string,mixed> $cohort
     * @param array<string,mixed> $shape
     * @param ?array<string,mixed> $projected
     */
    private function summary(array $today, array $matches, array $cohort, array $shape, ?array $projected = null): string
    {
        $msg = $today['pattern_label'] . ' on ' . $today['date'] . '. ';
        if ($matches !== []) {
            $top = array_slice(array_column($matches, 'date'), 0, 3);
            $msg .= 'Closest history: ' . implode(', ', $top) . '. ';
        } else {
            $msg .= 'No close path matches in loaded history. ';
        }
        if (($cohort['n'] ?? 0) > 0) {
            $msg .= sprintf(
                'After AM crash ≤−5%% by 10:30 (N=%d): closed better than 10:30 on %s%% of days, green close %s%%, avg day %+0.2f%%. ',
                $cohort['n'],
                $cohort['closed_better_than_1030_pct'],
                $cohort['close_green_pct'],
                $cohort['avg_day_ret']
            );
        }
        if (($shape['n'] ?? 0) > 0) {
            $msg .= sprintf(
                'Same pattern cohort (N=%d): green close %s%%, avg day %+0.2f%%. ',
                $shape['n'],
                $shape['close_green_pct'],
                $shape['avg_day_ret']
            );
        }
        if ($projected !== null && ($projected['status'] ?? '') === 'projected') {
            $msg .= $projected['note'] ?? '';
        }
        return trim($msg);
    }
}
