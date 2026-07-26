<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Mon–Fri session open→close % for one or more symbols.
 */
final class WeekdayReturns
{
    public function __construct(private readonly StatsService $stats)
    {
    }

    /**
     * @param list<string> $symbols
     * @return array<string,mixed>
     */
    public function build(array $symbols): array
    {
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $bySymbol = [];
        $allDates = [];

        foreach ($symbols as $symbol) {
            $symbol = strtoupper($symbol);
            $paths = $this->stats->sessionPaths($symbol);
            $days = [];
            foreach ($paths as $path) {
                $points = $path['points'] ?? [];
                if (count($points) < 2) {
                    continue;
                }
                $open = (float) $points[0]['price'];
                $close = (float) $points[count($points) - 1]['price'];
                if ($open <= 0) {
                    continue;
                }
                $ret = (($close - $open) / $open) * 100.0;
                $date = (string) $path['date'];
                $wd = (int) $path['weekday'];
                $days[] = [
                    'date' => $date,
                    'weekday' => $wd,
                    'label' => $labels[$wd] ?? (string) $wd,
                    'open' => round($open, 4),
                    'close' => round($close, 4),
                    'ret' => round($ret, 3),
                    'up' => $ret > 0,
                ];
                $allDates[$date] = true;
            }

            usort($days, static fn ($a, $b) => strcmp($b['date'], $a['date']));

            $byWeekday = [];
            for ($wd = 1; $wd <= 5; $wd++) {
                $subset = array_values(array_filter($days, static fn ($d) => (int) $d['weekday'] === $wd));
                $n = count($subset);
                $up = 0;
                $sum = 0.0;
                foreach ($subset as $d) {
                    $sum += $d['ret'];
                    if ($d['up']) {
                        $up++;
                    }
                }
                $byWeekday[] = [
                    'weekday' => $wd,
                    'label' => $labels[$wd],
                    'n' => $n,
                    'up_n' => $up,
                    'down_n' => $n - $up,
                    'up_pct' => $n > 0 ? round(100 * $up / $n, 1) : null,
                    'avg_ret' => $n > 0 ? round($sum / $n, 3) : null,
                ];
            }

            $bySymbol[$symbol] = [
                'symbol' => $symbol,
                'days' => $days,
                'by_weekday' => $byWeekday,
                'n' => count($days),
            ];
        }

        $dates = array_keys($allDates);
        rsort($dates);
        $rows = [];
        foreach ($dates as $date) {
            $row = ['date' => $date, 'label' => null, 'weekday' => null, 'rets' => []];
            foreach ($symbols as $symbol) {
                $symbol = strtoupper($symbol);
                $hit = null;
                foreach ($bySymbol[$symbol]['days'] as $d) {
                    if ($d['date'] === $date) {
                        $hit = $d;
                        break;
                    }
                }
                if ($hit !== null) {
                    $row['label'] = $hit['label'];
                    $row['weekday'] = $hit['weekday'];
                    $row['rets'][$symbol] = $hit['ret'];
                } else {
                    $row['rets'][$symbol] = null;
                }
            }
            $rows[] = $row;
        }

        return [
            'ok' => true,
            'symbols' => array_map('strtoupper', $symbols),
            'by_symbol' => $bySymbol,
            'rows' => $rows,
            'summary_text' => $this->summary($bySymbol),
        ];
    }

    /** @param array<string,array<string,mixed>> $bySymbol */
    private function summary(array $bySymbol): string
    {
        $parts = [];
        foreach ($bySymbol as $sym => $block) {
            $best = null;
            $worst = null;
            foreach ($block['by_weekday'] as $w) {
                if ($w['n'] === 0 || $w['avg_ret'] === null) {
                    continue;
                }
                if ($best === null || $w['avg_ret'] > $best['avg_ret']) {
                    $best = $w;
                }
                if ($worst === null || $w['avg_ret'] < $worst['avg_ret']) {
                    $worst = $w;
                }
            }
            if ($best && $worst) {
                $parts[] = sprintf(
                    '%s: best %s avg %+0.2f%% (up %s%%) · worst %s avg %+0.2f%%',
                    $sym,
                    $best['label'],
                    $best['avg_ret'],
                    $best['up_pct'],
                    $worst['label'],
                    $worst['avg_ret']
                );
            }
        }
        return $parts === [] ? 'No session returns yet.' : implode(' · ', $parts);
    }
}
