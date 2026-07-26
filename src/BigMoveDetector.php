<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Find stretches where price moves at least $2 (and flag moves over $3), up or down.
 */
final class BigMoveDetector
{
    public function __construct(
        private readonly SessionFilter $session,
        private readonly float $minDollars = 2.0,
        private readonly float $bigDollars = 3.0,
        private readonly float $reversalDollars = 1.0,
        private readonly int $maxWindowMinutes = 90
    ) {
    }

    /**
     * @param list<array{
     *   date:string,weekday:int,label:string,
     *   points:list<array{time:string,minute_of_day:int,price:float}>
     * }> $sessions
     * @return array{
     *   thresholds: array{min:float,big:float},
     *   moves: list<array<string,mixed>>,
     *   by_weekday: array<int, array<string,mixed>>,
     *   timing_summary: list<array<string,mixed>>
     * }
     */
    public function analyze(array $sessions): array
    {
        $moves = [];
        foreach ($sessions as $session) {
            foreach ($this->detectSession($session) as $move) {
                $moves[] = $move;
            }
        }

        // Newest first
        usort($moves, static function (array $a, array $b): int {
            $c = strcmp($b['date'], $a['date']);
            return $c !== 0 ? $c : ($a['start_minute'] <=> $b['start_minute']);
        });

        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $byWeekday = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $dayMoves = array_values(array_filter($moves, static fn ($m) => (int) $m['weekday'] === $wd));
            $ups = array_values(array_filter($dayMoves, static fn ($m) => $m['direction'] === 'up'));
            $downs = array_values(array_filter($dayMoves, static fn ($m) => $m['direction'] === 'down'));
            $over3 = array_values(array_filter($dayMoves, static fn ($m) => $m['bucket'] === 'over_3'));

            $byWeekday[$wd] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'count' => count($dayMoves),
                'up_count' => count($ups),
                'down_count' => count($downs),
                'over_3_count' => count($over3),
                'typical_up_start' => $this->typicalTime($ups, 'start_minute'),
                'typical_down_start' => $this->typicalTime($downs, 'start_minute'),
                'typical_over_3_start' => $this->typicalTime($over3, 'start_minute'),
                'moves' => $dayMoves,
            ];
        }

        return [
            'thresholds' => [
                'min' => $this->minDollars,
                'big' => $this->bigDollars,
                'reversal' => $this->reversalDollars,
                'max_window_minutes' => $this->maxWindowMinutes,
            ],
            'moves' => $moves,
            'by_weekday' => $byWeekday,
            'timing_summary' => array_values($byWeekday),
        ];
    }

    /**
     * @param array{
     *   date:string,weekday:int,label:string,
     *   points:list<array{time:string,minute_of_day:int,price:float}>
     * } $session
     * @return list<array<string,mixed>>
     */
    private function detectSession(array $session): array
    {
        $points = $session['points'] ?? [];
        if (count($points) < 2) {
            return [];
        }

        $moves = [];
        $n = count($points);
        $i = 0;

        while ($i < $n - 1) {
            $startIdx = $i;
            $startPrice = (float) $points[$i]['price'];
            $startMinute = (int) $points[$i]['minute_of_day'];

            $extremeIdx = $i;
            $extremePrice = $startPrice;
            $direction = 0; // 1 up, -1 down, 0 unknown
            $found = null;

            for ($j = $i + 1; $j < $n; $j++) {
                $price = (float) $points[$j]['price'];
                $minute = (int) $points[$j]['minute_of_day'];

                if ($minute - $startMinute > $this->maxWindowMinutes) {
                    break;
                }

                $moveFromStart = $price - $startPrice;

                if ($direction === 0) {
                    if ($moveFromStart >= $this->minDollars) {
                        $direction = 1;
                        $extremeIdx = $j;
                        $extremePrice = $price;
                    } elseif ($moveFromStart <= -$this->minDollars) {
                        $direction = -1;
                        $extremeIdx = $j;
                        $extremePrice = $price;
                    } else {
                        // Drift anchor slightly with price to avoid stale starts
                        if (abs($moveFromStart) < 0.25) {
                            $startIdx = $j;
                            $startPrice = $price;
                            $startMinute = $minute;
                            $extremeIdx = $j;
                            $extremePrice = $price;
                        }
                        continue;
                    }
                }

                if ($direction === 1) {
                    if ($price >= $extremePrice) {
                        $extremePrice = $price;
                        $extremeIdx = $j;
                    }
                    $pullback = $extremePrice - $price;
                    $dollars = $extremePrice - $startPrice;
                    if ($dollars >= $this->minDollars && $pullback >= $this->reversalDollars) {
                        $found = [$startIdx, $extremeIdx, $dollars, 'up'];
                        break;
                    }
                } else { // down
                    if ($price <= $extremePrice) {
                        $extremePrice = $price;
                        $extremeIdx = $j;
                    }
                    $pullback = $price - $extremePrice;
                    $dollars = $startPrice - $extremePrice;
                    if ($dollars >= $this->minDollars && $pullback >= $this->reversalDollars) {
                        $found = [$startIdx, $extremeIdx, $dollars, 'down'];
                        break;
                    }
                }
            }

            // End-of-window / end-of-day: still count if threshold hit
            if ($found === null && $direction !== 0) {
                $dollars = abs($extremePrice - $startPrice);
                if ($dollars >= $this->minDollars) {
                    $found = [$startIdx, $extremeIdx, $dollars, $direction === 1 ? 'up' : 'down'];
                }
            }

            if ($found === null) {
                $i++;
                continue;
            }

            [$from, $to, $dollars, $dir] = $found;
            $start = $points[$from];
            $end = $points[$to];
            $dollars = round((float) $dollars, 4);
            $bucket = $dollars >= $this->bigDollars ? 'over_3' : '2_to_3';

            $moves[] = [
                'date' => $session['date'],
                'weekday' => (int) $session['weekday'],
                'label' => $session['label'],
                'direction' => $dir,
                'bucket' => $bucket,
                'dollars' => $dollars,
                'start_time' => $start['time'],
                'end_time' => $end['time'],
                'start_minute' => (int) $start['minute_of_day'],
                'end_minute' => (int) $end['minute_of_day'],
                'start_price' => round((float) $start['price'], 4),
                'end_price' => round((float) $end['price'], 4),
                'duration_minutes' => (int) $end['minute_of_day'] - (int) $start['minute_of_day'],
            ];

            // Continue after the extreme so we don't double-count the same swing
            $i = max($to, $from + 1);
        }

        return $moves;
    }

    /**
     * @param list<array<string,mixed>> $moves
     */
    private function typicalTime(array $moves, string $field): ?string
    {
        if ($moves === []) {
            return null;
        }
        $vals = array_map(static fn ($m) => (int) $m[$field], $moves);
        $avg = array_sum($vals) / count($vals);
        return $this->session->minuteLabel((int) round($avg));
    }
}
