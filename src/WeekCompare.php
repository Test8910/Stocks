<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Compare up to 4 session days (same weekday across weeks, or last 4 trading days).
 */
final class WeekCompare
{
    private const COLORS = ['#3dbb8b', '#7ec8ff', '#f0c674', '#e06c75'];

    public function __construct(private readonly SessionFilter $session)
    {
    }

    /**
     * @param list<array<string,mixed>> $sessions
     * @param list<array<string,mixed>> $moves
     * @param list<string> $selectedDates
     * @return array<string,mixed>
     */
    public function build(
        array $sessions,
        array $moves,
        array $selectedDates = [],
        string $mode = 'last4_weekday',
        int $weekday = 5,
        int $limit = 4
    ): array {
        $limit = max(2, min(4, $limit));
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

        $allDates = array_keys($byDate);
        rsort($allDates);

        $presets = [];
        $presets[] = [
            'id' => 'last4_days',
            'label' => 'Last 4 trading days',
            'dates' => array_slice($allDates, 0, $limit),
        ];
        for ($wd = 1; $wd <= 5; $wd++) {
            $dates = array_slice($byWeekday[$wd] ?? [], 0, $limit);
            if (count($dates) >= 2) {
                $presets[] = [
                    'id' => 'weekday_' . $wd,
                    'label' => 'Last ' . count($dates) . ' ' . $labels[$wd] . 's',
                    'dates' => $dates,
                    'weekday' => $wd,
                ];
            }
        }

        // Legacy 2-day pairs for older UI bits
        $pairs = [];
        for ($wd = 1; $wd <= 5; $wd++) {
            $dates = $byWeekday[$wd] ?? [];
            $pairs[] = [
                'weekday' => $wd,
                'label' => $labels[$wd],
                'recent' => $dates[0] ?? null,
                'prior' => $dates[1] ?? null,
                'dates' => array_slice($dates, 0, $limit),
            ];
        }

        $selectedDates = array_values(array_filter($selectedDates, static fn ($d) => $d !== '' && isset($byDate[$d])));
        if ($selectedDates === []) {
            if ($mode === 'last4_days') {
                $selectedDates = array_slice($allDates, 0, $limit);
            } else {
                $wd = max(1, min(5, $weekday));
                $selectedDates = array_slice($byWeekday[$wd] ?? [], 0, $limit);
                if (count($selectedDates) < 2) {
                    $selectedDates = array_slice($allDates, 0, $limit);
                    $mode = 'last4_days';
                } else {
                    $mode = 'last4_weekday';
                }
            }
        }

        $selectedDates = array_slice($selectedDates, 0, $limit);
        $comparison = count($selectedDates) >= 2
            ? $this->compareMany($selectedDates, $byDate, $moves)
            : null;

        return [
            'mode' => $mode,
            'weekday' => $weekday,
            'limit' => $limit,
            'presets' => $presets,
            'pairs' => $pairs,
            'dates' => $selectedDates,
            'date_a' => $selectedDates[0] ?? null,
            'date_b' => $selectedDates[1] ?? null,
            'comparison' => $comparison,
        ];
    }

    /**
     * @param list<string> $dates
     * @param array<string,array<string,mixed>> $byDate
     * @param list<array<string,mixed>> $moves
     * @return array<string,mixed>
     */
    private function compareMany(array $dates, array $byDate, array $moves): array
    {
        $days = [];
        $minuteSets = [];
        foreach ($dates as $i => $date) {
            $session = $byDate[$date];
            $map = [];
            foreach ($session['points'] as $pt) {
                $map[(int) $pt['minute_of_day']] = (float) $pt['price'];
            }
            $minuteSets[] = array_keys($map);
            $open = $session['points'][0]['price'] ?? null;
            $close = $session['points'][count($session['points']) - 1]['price'] ?? null;
            $dayChange = ($open && $close) ? (($close - $open) / $open) * 100.0 : null;
            $dayMoves = array_values(array_filter($moves, static fn ($m) => $m['date'] === $date));
            $summary = $this->daySummary($session, $open, $close, $dayChange, $dayMoves);
            $summary['color'] = self::COLORS[$i % count(self::COLORS)];
            $summary['price_map'] = $map;
            $days[] = $summary;
        }

        $minutes = [];
        foreach ($minuteSets as $set) {
            foreach ($set as $m) {
                $minutes[$m] = true;
            }
        }
        $minutes = array_keys($minutes);
        sort($minutes);

        $times = array_map(fn ($m) => $this->session->minuteLabel($m), $minutes);
        $seriesPrice = [];
        $seriesNorm = [];
        foreach ($days as $idx => $day) {
            $prices = [];
            $norms = [];
            $open = $day['open'];
            foreach ($minutes as $m) {
                $p = $day['price_map'][$m] ?? null;
                $prices[] = $p;
                $norms[] = ($p !== null && $open) ? round(($p / $open) * 100.0, 4) : null;
            }
            $seriesPrice[] = [
                'date' => $day['date'],
                'label' => $day['label'],
                'color' => $day['color'],
                'data' => $prices,
            ];
            $seriesNorm[] = [
                'date' => $day['date'],
                'label' => $day['label'],
                'color' => $day['color'],
                'data' => $norms,
            ];
            unset($days[$idx]['price_map']);
        }

        $weekdays = array_unique(array_column($days, 'weekday'));
        // Keep backward-compatible a/b fields for first two days
        $a = $days[0] ?? null;
        $b = $days[1] ?? null;

        return [
            'days' => array_values($days),
            'count' => count($days),
            'same_weekday' => count($weekdays) === 1,
            'times' => $times,
            'series_price' => $seriesPrice,
            'series_norm' => $seriesNorm,
            'a' => $a,
            'b' => $b,
            'price_a' => $seriesPrice[0]['data'] ?? [],
            'price_b' => $seriesPrice[1]['data'] ?? [],
            'norm_a' => $seriesNorm[0]['data'] ?? [],
            'norm_b' => $seriesNorm[1]['data'] ?? [],
            'diff_norm' => $this->diffSeries($seriesNorm[0]['data'] ?? [], $seriesNorm[1]['data'] ?? []),
        ];
    }

    /**
     * @param list<?float> $a
     * @param list<?float> $b
     * @return list<?float>
     */
    private function diffSeries(array $a, array $b): array
    {
        $out = [];
        $n = max(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $va = $a[$i] ?? null;
            $vb = $b[$i] ?? null;
            $out[] = ($va !== null && $vb !== null) ? round($va - $vb, 4) : null;
        }
        return $out;
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
