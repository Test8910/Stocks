<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Classify uptrend/downtrend minutes and low→high windows per weekday.
 */
final class PatternEngine
{
    public function __construct(
        private readonly float $probabilityThreshold = 0.60,
        private readonly int $minSamples = 5,
        private readonly SessionFilter $session = new SessionFilter(),
        private readonly int $minWindowMinutes = 3
    ) {
    }

    /**
     * @param array $analysis Output of StatsService::analyze()
     * @return array{
     *   threshold:float,
     *   min_samples:int,
     *   weekdays:array<int, array{
     *     label:string,
     *     uptrend_windows:list<array{start:string,end:string,avg_up_prob:float}>,
     *     downtrend_windows:list<array{start:string,end:string,avg_down_prob:float}>,
     *     buy_zone: ?array{start:string,end:string,reason:string},
     *     sell_zone: ?array{start:string,end:string,reason:string},
     *     typical_low_time: ?string,
     *     typical_high_time: ?string
     *   }>
     * }
     */
    public function detect(array $analysis): array
    {
        $out = [
            'threshold' => $this->probabilityThreshold,
            'min_samples' => $this->minSamples,
            'weekdays' => [],
        ];

        foreach ($analysis['weekdays'] as $wd => $day) {
            $upFlags = [];
            $downFlags = [];
            $upProbs = [];
            $downProbs = [];
            $relHigh = [];

            foreach ($day['minutes'] as $cell) {
                $m = (int) $cell['minute_of_day'];
                $n = (int) $cell['n'];
                $isUp = $n >= $this->minSamples
                    && $cell['up_prob'] >= $this->probabilityThreshold
                    && $cell['avg_ret'] > 0;
                $isDown = $n >= $this->minSamples
                    && $cell['down_prob'] >= $this->probabilityThreshold
                    && $cell['avg_ret'] < 0;
                $upFlags[$m] = $isUp;
                $downFlags[$m] = $isDown;
                $upProbs[$m] = (float) $cell['up_prob'];
                $downProbs[$m] = (float) $cell['down_prob'];
                $relHigh[$m] = (float) $cell['avg_rel_high'];
            }

            $lh = $analysis['low_high'][$wd] ?? [];
            $buyZone = $this->zoneAround($lh['avg_low_minute'] ?? null, $relHigh, 'near session low');
            $sellZone = $this->zoneAround($lh['avg_high_minute'] ?? null, $relHigh, 'near session high', true);

            $out['weekdays'][$wd] = [
                'label' => $day['label'],
                'uptrend_windows' => $this->mergeWindows($upFlags, $upProbs, 'up'),
                'downtrend_windows' => $this->mergeWindows($downFlags, $downProbs, 'down'),
                'buy_zone' => $buyZone,
                'sell_zone' => $sellZone,
                'typical_low_time' => $lh['typical_low_time'] ?? null,
                'typical_high_time' => $lh['typical_high_time'] ?? null,
                'sessions' => $lh['sessions'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * @param array<int,bool> $flags
     * @param array<int,float> $probs
     * @return list<array{start:string,end:string,avg_up_prob?:float,avg_down_prob?:float}>
     */
    private function mergeWindows(array $flags, array $probs, string $kind): array
    {
        $windows = [];
        $start = null;
        $sum = 0.0;
        $count = 0;
        $keys = array_keys($flags);
        sort($keys);
        $last = null;

        foreach ($keys as $m) {
            if ($flags[$m]) {
                if ($start === null) {
                    $start = $m;
                    $sum = 0.0;
                    $count = 0;
                }
                $sum += $probs[$m];
                $count++;
                $last = $m;
            } elseif ($start !== null) {
                $windows[] = $this->windowPayload($start, (int) $last, $sum / max(1, $count), $kind);
                $start = null;
            }
        }
        if ($start !== null && $last !== null) {
            $windows[] = $this->windowPayload($start, (int) $last, $sum / max(1, $count), $kind);
        }

        // Drop tiny noisy blips (common when history is only ~2 sessions/weekday)
        return array_values(array_filter(
            $windows,
            function (array $w): bool {
                $start = $this->labelToMinute($w['start']);
                $end = $this->labelToMinute($w['end']);
                return ($end - $start + 1) >= $this->minWindowMinutes;
            }
        ));
    }

    private function labelToMinute(string $label): int
    {
        [$h, $m] = array_map('intval', explode(':', $label));
        return ($h * 60 + $m) - (9 * 60 + 30);
    }

    private function windowPayload(int $start, int $end, float $avgProb, string $kind): array
    {
        $row = [
            'start' => $this->session->minuteLabel($start),
            'end' => $this->session->minuteLabel($end),
        ];
        if ($kind === 'up') {
            $row['avg_up_prob'] = round($avgProb, 4);
        } else {
            $row['avg_down_prob'] = round($avgProb, 4);
        }
        return $row;
    }

    /**
     * Build a ±15 minute zone around typical low/high minute.
     *
     * @param array<int,float> $relHigh
     */
    private function zoneAround(?float $center, array $relHigh, string $reason, bool $preferHigh = false): ?array
    {
        if ($center === null) {
            return null;
        }
        $c = (int) round($center);
        $start = max(0, $c - 15);
        $end = min($this->session->sessionLengthMinutes() - 1, $c + 15);

        // Refine: within window, pick stretch closest to low or high
        $best = $c;
        $bestScore = $preferHigh ? -1.0 : 2.0;
        for ($m = $start; $m <= $end; $m++) {
            if (!isset($relHigh[$m])) {
                continue;
            }
            $score = $relHigh[$m];
            if ($preferHigh) {
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $m;
                }
            } elseif ($score < $bestScore) {
                $bestScore = $score;
                $best = $m;
            }
        }

        $zStart = max(0, $best - 10);
        $zEnd = min($this->session->sessionLengthMinutes() - 1, $best + 10);

        return [
            'start' => $this->session->minuteLabel($zStart),
            'end' => $this->session->minuteLabel($zEnd),
            'center' => $this->session->minuteLabel($best),
            'reason' => $reason,
        ];
    }
}
