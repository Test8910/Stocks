<?php

declare(strict_types=1);

namespace Stocks;

/**
 * UK → US session lead-lag: does US cash follow London hours?
 */
final class GlobalLeadLag
{
    public function __construct(private readonly StatsService $stats)
    {
    }

    /**
     * @param list<array{
     *   symbol:string,name:string,region:string,role:string,
     *   timezone?:string,session_start?:string,session_end?:string
     * }> $symbolMeta
     * @return array<string,mixed>
     */
    public function analyze(
        array $symbolMeta,
        string $ukSymbol = 'EQQQ',
        string $usSymbol = 'QQQ',
        float $thresholdPct = 0.3
    ): array {
        $thresholdPct = abs($thresholdPct);
        $bySymbol = [];
        foreach ($symbolMeta as $m) {
            $bySymbol[strtoupper($m['symbol'])] = $m;
        }

        $ukSymbol = strtoupper($ukSymbol);
        $usSymbol = strtoupper($usSymbol);

        $daily = [];
        foreach ([$ukSymbol, $usSymbol] as $sym) {
            if (!isset($bySymbol[$sym])) {
                continue;
            }
            $daily[$sym] = $this->dailySessionReturns($sym);
        }

        $dates = array_values(array_intersect(
            array_keys($daily[$ukSymbol] ?? []),
            array_keys($daily[$usSymbol] ?? [])
        ));
        rsort($dates);

        $rows = [];
        foreach ($dates as $date) {
            $u = $daily[$ukSymbol][$date];
            $s = $daily[$usSymbol][$date];
            $rows[] = [
                'date' => $date,
                'weekday' => $s['weekday'],
                'label' => $s['label'],
                'uk_ret' => $u['ret'],
                'us_ret' => $s['ret'],
                'uk_open' => $u['open'],
                'uk_close' => $u['close'],
                'us_open' => $s['open'],
                'us_close' => $s['close'],
                'same_uk_us' => ($u['ret'] == 0.0 || $s['ret'] == 0.0)
                    ? null
                    : (($u['ret'] > 0) === ($s['ret'] > 0)),
            ];
        }

        $scenarios = [
            $this->conditional($rows, 'uk_up', $ukSymbol, $usSymbol, $thresholdPct, static fn ($r) => $r['uk_ret'] >= $thresholdPct),
            $this->conditional($rows, 'uk_down', $ukSymbol, $usSymbol, $thresholdPct, static fn ($r) => $r['uk_ret'] <= -$thresholdPct),
        ];

        $agreeUk = 0;
        $nAgree = 0;
        foreach ($rows as $r) {
            if ($r['same_uk_us'] === null) {
                continue;
            }
            $nAgree++;
            if ($r['same_uk_us']) {
                $agreeUk++;
            }
        }

        $corrUk = $this->corr(array_column($rows, 'uk_ret'), array_column($rows, 'us_ret'));
        $strength = count($rows) >= 8 ? 'usable' : (count($rows) >= 3 ? 'weak' : 'too_few');

        return [
            'ok' => true,
            'uk_symbol' => $ukSymbol,
            'us_symbol' => $usSymbol,
            'uk_meta' => $bySymbol[$ukSymbol] ?? null,
            'us_meta' => $bySymbol[$usSymbol] ?? null,
            'threshold_pct' => $thresholdPct,
            'n_days' => count($rows),
            'strength' => $strength,
            'agreement' => [
                'uk_us_same_dir_pct' => $nAgree > 0 ? round(100 * $agreeUk / $nAgree, 1) : null,
                'n' => $nAgree,
                'corr_uk_us' => $corrUk,
            ],
            'scenarios' => $scenarios,
            'days' => $rows,
            'summary_text' => $this->summary(
                count($rows),
                $strength,
                $ukSymbol,
                $usSymbol,
                $nAgree,
                $agreeUk,
                $corrUk
            ),
            'presets' => [
                'uk' => $this->regionPresets($symbolMeta, 'uk'),
                'us' => $this->regionPresets($symbolMeta, 'us'),
            ],
        ];
    }

    /**
     * @return array<string, array{date:string,weekday:int,label:string,open:float,close:float,ret:float}>
     */
    private function dailySessionReturns(string $symbol): array
    {
        $paths = $this->stats->sessionPaths($symbol);
        $labels = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri'];
        $out = [];
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
            $date = (string) $path['date'];
            $wd = (int) $path['weekday'];
            $out[$date] = [
                'date' => $date,
                'weekday' => $wd,
                'label' => $labels[$wd] ?? (string) $wd,
                'open' => round($open, 4),
                'close' => round($close, 4),
                'ret' => round((($close - $open) / $open) * 100.0, 3),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param callable(array<string,mixed>):bool $pred
     * @return array<string,mixed>
     */
    private function conditional(
        array $rows,
        string $id,
        string $leadLabel,
        string $usSymbol,
        float $thresholdPct,
        callable $pred
    ): array {
        $hits = array_values(array_filter($rows, $pred));
        $n = count($hits);
        $usUp = 0;
        $usDown = 0;
        $sum = 0.0;
        foreach ($hits as $h) {
            $sum += (float) $h['us_ret'];
            if ($h['us_ret'] > 0) {
                $usUp++;
            } elseif ($h['us_ret'] < 0) {
                $usDown++;
            }
        }
        $strength = $n >= 8 ? 'usable' : ($n >= 3 ? 'weak' : 'too_few');
        return [
            'id' => $id,
            'lead' => $leadLabel,
            'follow' => $usSymbol,
            'threshold_pct' => $thresholdPct,
            'n' => $n,
            'strength' => $strength,
            'us_up_pct' => $n > 0 ? round(100 * $usUp / $n, 1) : null,
            'us_down_pct' => $n > 0 ? round(100 * $usDown / $n, 1) : null,
            'avg_us_ret' => $n > 0 ? round($sum / $n, 3) : null,
            'dates' => array_column($hits, 'date'),
        ];
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private function corr(array $a, array $b): ?float
    {
        $n = min(count($a), count($b));
        if ($n < 3) {
            return null;
        }
        $a = array_slice($a, 0, $n);
        $b = array_slice($b, 0, $n);
        $meanA = array_sum($a) / $n;
        $meanB = array_sum($b) / $n;
        $num = 0.0;
        $denA = 0.0;
        $denB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $da = $a[$i] - $meanA;
            $db = $b[$i] - $meanB;
            $num += $da * $db;
            $denA += $da * $da;
            $denB += $db * $db;
        }
        if ($denA <= 0.0 || $denB <= 0.0) {
            return null;
        }
        return round($num / sqrt($denA * $denB), 3);
    }

    /**
     * @param list<array<string,mixed>> $symbolMeta
     * @return list<array{symbol:string,name:string,role:string}>
     */
    private function regionPresets(array $symbolMeta, string $region): array
    {
        $out = [];
        foreach ($symbolMeta as $m) {
            if (($m['region'] ?? '') !== $region) {
                continue;
            }
            $out[] = [
                'symbol' => $m['symbol'],
                'name' => $m['name'],
                'role' => $m['role'] ?? 'other',
            ];
        }
        return $out;
    }

    private function summary(
        int $n,
        string $strength,
        string $uk,
        string $us,
        int $nAgree,
        int $agreeUk,
        ?float $corrUk
    ): string {
        if ($n === 0) {
            return "No overlapping UK / US sessions yet. Ingest EQQQ (and US symbols) first.";
        }
        $ukPct = $nAgree > 0 ? round(100 * $agreeUk / $nAgree) : 0;
        $cu = $corrUk !== null ? sprintf('%+.2f', $corrUk) : 'n/a';
        return "On {$n} overlapping days ({$strength}): {$us} same direction as {$uk} {$ukPct}% of the time "
            . "(corr UK/US {$cu}). London hours print first — use as a lead check before the US cash open.";
    }
}
