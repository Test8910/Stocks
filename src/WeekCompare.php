<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Compare two session days (e.g. this Friday vs last Friday).
 */
final class WeekCompare
{
    public function __construct(private readonly SessionFilter $session)
    {
    }

    /**
     * @param list<array<string,mixed>> $sessions Aggregated session paths
     * @param list<array<string,mixed>> $moves Big-move list for current options
     * @return array{
     *   pairs: list<array{weekday:int,label:string,recent:?string,prior:?string}>,
     *   comparison: ?array<string,mixed>
     * }
     */
    public function build(array $sessions, array $moves, ?string $dateA, ?string $dateB): array
    {
        $byDate = [];
        foreach ($sessions as $s) {
            $byDate[$s['date']] = $s;
        }

        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $byWeekday = [];
        foreach ($sessions as $s) {
            $byWeekday[(int) $s['weekday']][] = $s['date'];
        }
        foreach ($byWeekday as &$dates) {
            rsort($dates);
        }
        unset($dates);

        $pairs = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $dates = $byWeekday[$wd] ?? [];
            $pairs[] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'recent' => $dates[0] ?? null,
                'prior' => $dates[1] ?? null,
            ];
        }

        if (($dateA === null || $dateA === '') && ($dateB === null || $dateB === '')) {
            // Default: latest Friday vs prior Friday, else first available pair
            $friday = $pairs[4];
            if ($friday['recent'] && $friday['prior']) {
                $dateA = $friday['recent'];
                $dateB = $friday['prior'];
            } else {
                foreach ($pairs as $p) {
                    if ($p['recent'] && $p['prior']) {
                        $dateA = $p['recent'];
                        $dateB = $p['prior'];
                        break;
                    }
                }
            }
        }

        $comparison = null;
        if ($dateA && $dateB && isset($byDate[$dateA], $byDate[$dateB])) {
            $comparison = $this->compareDays($byDate[$dateA], $byDate[$dateB], $moves);
        }

        return [
            'pairs' => $pairs,
            'date_a' => $dateA,
            'date_b' => $dateB,
            'comparison' => $comparison,
        ];
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     * @param list<array<string,mixed>> $moves
     * @return array<string,mixed>
     */
    private function compareDays(array $a, array $b, array $moves): array
    {
        $mapA = [];
        foreach ($a['points'] as $pt) {
            $mapA[(int) $pt['minute_of_day']] = (float) $pt['price'];
        }
        $mapB = [];
        foreach ($b['points'] as $pt) {
            $mapB[(int) $pt['minute_of_day']] = (float) $pt['price'];
        }

        $minutes = array_values(array_unique(array_merge(array_keys($mapA), array_keys($mapB))));
        sort($minutes);

        $openA = $a['points'][0]['price'] ?? null;
        $openB = $b['points'][0]['price'] ?? null;
        $closeA = $a['points'][count($a['points']) - 1]['price'] ?? null;
        $closeB = $b['points'][count($b['points']) - 1]['price'] ?? null;

        $times = [];
        $priceA = [];
        $priceB = [];
        $normA = [];
        $normB = [];
        $diffNorm = [];

        foreach ($minutes as $m) {
            $times[] = $this->session->minuteLabel($m);
            $pa = $mapA[$m] ?? null;
            $pb = $mapB[$m] ?? null;
            $priceA[] = $pa;
            $priceB[] = $pb;
            $na = ($pa !== null && $openA) ? ($pa / $openA) * 100.0 : null;
            $nb = ($pb !== null && $openB) ? ($pb / $openB) * 100.0 : null;
            $normA[] = $na !== null ? round($na, 4) : null;
            $normB[] = $nb !== null ? round($nb, 4) : null;
            $diffNorm[] = ($na !== null && $nb !== null) ? round($na - $nb, 4) : null;
        }

        $movesA = array_values(array_filter($moves, static fn ($m) => $m['date'] === $a['date']));
        $movesB = array_values(array_filter($moves, static fn ($m) => $m['date'] === $b['date']));

        $dayChangeA = ($openA && $closeA) ? (($closeA - $openA) / $openA) * 100.0 : null;
        $dayChangeB = ($openB && $closeB) ? (($closeB - $openB) / $openB) * 100.0 : null;

        return [
            'a' => $this->daySummary($a, $openA, $closeA, $dayChangeA, $movesA),
            'b' => $this->daySummary($b, $openB, $closeB, $dayChangeB, $movesB),
            'same_weekday' => (int) $a['weekday'] === (int) $b['weekday'],
            'times' => $times,
            'price_a' => $priceA,
            'price_b' => $priceB,
            'norm_a' => $normA,
            'norm_b' => $normB,
            'diff_norm' => $diffNorm,
        ];
    }

    /**
     * @param array<string,mixed> $day
     * @param list<array<string,mixed>> $moves
     * @return array<string,mixed>
     */
    private function daySummary(array $day, ?float $open, ?float $close, ?float $dayChange, array $moves): array
    {
        return [
            'date' => $day['date'],
            'label' => $day['label'],
            'weekday' => $day['weekday'],
            'open' => $open !== null ? round($open, 4) : null,
            'close' => $close !== null ? round($close, 4) : null,
            'day_change_pct' => $dayChange !== null ? round($dayChange, 3) : null,
            'low_price' => $day['low_price'],
            'low_time' => $day['low_time'],
            'high_price' => $day['high_price'],
            'high_time' => $day['high_time'],
            'high_after_low' => $day['high_after_low'],
            'minutes_low_to_high' => $day['minutes_low_to_high'],
            'move_pct' => $day['move_pct'],
            'moves' => $moves,
            'move_count' => count($moves),
        ];
    }
}
