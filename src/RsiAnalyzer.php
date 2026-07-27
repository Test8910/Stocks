<?php

declare(strict_types=1);

namespace Stocks;

/**
 * RSI(14) backtest on intraday bars + optional volume confirmation.
 */
final class RsiAnalyzer
{
    /**
     * Latest RSI + volume z-score snapshot for live checklist.
     *
     * @param list<array{price:float,volume?:?int,minute_of_day?:int,session_date?:string,ts_et?:string}> $bars1m
     * @return array{symbol:string,interval_minutes:int,rsi:?float,vol_z:?float,price:?float,volume:?int,ts:?string,date:?string,time:?string}
     */
    public function latestSnapshot(
        array $bars1m,
        string $symbol,
        int $intervalMinutes = 5,
        int $rsiPeriod = 14
    ): array {
        $series = $this->aggregate($bars1m, $intervalMinutes);
        $withRsi = $this->attachRsi($series, $rsiPeriod);
        $last = $withRsi === [] ? null : $withRsi[count($withRsi) - 1];
        return [
            'symbol' => $symbol,
            'interval_minutes' => $intervalMinutes,
            'rsi' => $last['rsi'] ?? null,
            'vol_z' => $last['vol_z'] ?? null,
            'price' => $last['price'] ?? null,
            'volume' => $last['volume'] ?? null,
            'ts' => $last['ts'] ?? null,
            'date' => $last['date'] ?? null,
            'time' => $last['time'] ?? null,
        ];
    }

    /**
     * @param list<array{price:float,volume?:?int,minute_of_day?:int,session_date?:string,ts_et?:string}> $bars1m
     * @return array<string,mixed>
     */
    public function analyze(
        array $bars1m,
        string $symbol,
        int $intervalMinutes = 5,
        int $rsiPeriod = 14,
        float $oversold = 30.0,
        float $overbought = 70.0,
        int $forwardBars = 6
    ): array {
        $intervalMinutes = max(1, $intervalMinutes);
        $rsiPeriod = max(2, $rsiPeriod);
        $forwardBars = max(1, min(30, $forwardBars));

        $series = $this->aggregate($bars1m, $intervalMinutes);
        $withRsi = $this->attachRsi($series, $rsiPeriod);

        $oversoldHits = [];
        $overboughtHits = [];
        $crossUp30 = []; // classic: RSI crosses up through oversold
        $crossDown70 = [];

        $prevRsi = null;
        foreach ($withRsi as $i => $bar) {
            $rsi = $bar['rsi'];
            if ($rsi === null) {
                $prevRsi = $rsi;
                continue;
            }

            $fwd = $this->forwardReturn($withRsi, $i, $forwardBars);
            if ($fwd === null) {
                $prevRsi = $rsi;
                continue;
            }

            $volZ = $bar['vol_z'];
            $row = [
                'ts' => $bar['ts'],
                'date' => $bar['date'],
                'time' => $bar['time'],
                'price' => $bar['price'],
                'rsi' => round($rsi, 2),
                'volume' => $bar['volume'],
                'vol_z' => $volZ !== null ? round($volZ, 2) : null,
                'fwd_ret' => round($fwd, 3),
                'fwd_up' => $fwd > 0,
            ];

            if ($rsi <= $oversold) {
                $oversoldHits[] = $row;
            }
            if ($rsi >= $overbought) {
                $overboughtHits[] = $row;
            }
            if ($prevRsi !== null && $prevRsi < $oversold && $rsi >= $oversold) {
                $crossUp30[] = $row;
            }
            if ($prevRsi !== null && $prevRsi > $overbought && $rsi <= $overbought) {
                $crossDown70[] = $row;
            }
            $prevRsi = $rsi;
        }

        $pack = function (array $hits, string $expect) use ($oversold, $overbought): array {
            $n = count($hits);
            if ($n === 0) {
                return [
                    'n' => 0,
                    'strength' => 'too_few',
                    'up_pct' => null,
                    'down_pct' => null,
                    'avg_fwd_ret' => null,
                    'high_vol_n' => 0,
                    'high_vol_up_pct' => null,
                    'low_vol_up_pct' => null,
                    'expect' => $expect,
                    'works' => null,
                ];
            }
            $up = 0;
            $sum = 0.0;
            $hiVol = [];
            $loVol = [];
            foreach ($hits as $h) {
                $sum += $h['fwd_ret'];
                if ($h['fwd_up']) {
                    $up++;
                }
                if ($h['vol_z'] !== null && $h['vol_z'] >= 1.0) {
                    $hiVol[] = $h;
                } elseif ($h['vol_z'] !== null && $h['vol_z'] <= 0.0) {
                    $loVol[] = $h;
                }
            }
            $upPct = round(100 * $up / $n, 1);
            $avg = round($sum / $n, 3);
            $strength = $n >= 20 ? 'usable' : ($n >= 8 ? 'weak' : 'too_few');

            // "works" if direction matches classic RSI expectation more often than not
            $works = null;
            if ($expect === 'bounce' && $n >= 5) {
                $works = $upPct >= 55;
            } elseif ($expect === 'fade' && $n >= 5) {
                $works = (100 - $upPct) >= 55; // want down
            }

            $hiUp = null;
            if (count($hiVol) > 0) {
                $hiUp = round(100 * count(array_filter($hiVol, static fn ($h) => $h['fwd_up'])) / count($hiVol), 1);
            }
            $loUp = null;
            if (count($loVol) > 0) {
                $loUp = round(100 * count(array_filter($loVol, static fn ($h) => $h['fwd_up'])) / count($loVol), 1);
            }

            return [
                'n' => $n,
                'strength' => $strength,
                'up_pct' => $upPct,
                'down_pct' => round(100 - $upPct, 1),
                'avg_fwd_ret' => $avg,
                'high_vol_n' => count($hiVol),
                'high_vol_up_pct' => $hiUp,
                'low_vol_n' => count($loVol),
                'low_vol_up_pct' => $loUp,
                'expect' => $expect,
                'works' => $works,
                'recent' => array_slice(array_reverse($hits), 0, 8),
            ];
        };

        $os = $pack($oversoldHits, 'bounce');
        $ob = $pack($overboughtHits, 'fade');
        $cu = $pack($crossUp30, 'bounce');
        $cd = $pack($crossDown70, 'fade');

        $volEdge = $this->volumeEdge($withRsi, $forwardBars);

        return [
            'symbol' => $symbol,
            'interval_minutes' => $intervalMinutes,
            'rsi_period' => $rsiPeriod,
            'oversold' => $oversold,
            'overbought' => $overbought,
            'forward_bars' => $forwardBars,
            'forward_minutes' => $forwardBars * $intervalMinutes,
            'bar_count' => count($withRsi),
            'signals' => [
                'rsi_oversold' => $os,
                'rsi_overbought' => $ob,
                'rsi_cross_up_oversold' => $cu,
                'rsi_cross_down_overbought' => $cd,
            ],
            'volume' => $volEdge,
            'summary_text' => $this->summary($symbol, $os, $ob, $cu, $volEdge, $forwardBars * $intervalMinutes),
        ];
    }

    /**
     * @param list<array{price:float,volume?:?int,minute_of_day?:int,session_date?:string,ts_et?:string}> $bars1m
     * @return list<array{ts:string,date:string,time:string,price:float,volume:int}>
     */
    private function aggregate(array $bars1m, int $intervalMinutes): array
    {
        if ($intervalMinutes <= 1) {
            $out = [];
            foreach ($bars1m as $b) {
                $ts = (string) ($b['ts_et'] ?? '');
                $out[] = [
                    'ts' => $ts,
                    'date' => (string) ($b['session_date'] ?? substr($ts, 0, 10)),
                    'time' => strlen($ts) >= 16 ? substr($ts, 11, 5) : '',
                    'price' => (float) $b['price'],
                    'volume' => (int) ($b['volume'] ?? 0),
                ];
            }
            return $out;
        }

        $buckets = [];
        foreach ($bars1m as $b) {
            $m = (int) ($b['minute_of_day'] ?? 0);
            $date = (string) ($b['session_date'] ?? substr((string) ($b['ts_et'] ?? ''), 0, 10));
            $bucket = intdiv($m, $intervalMinutes) * $intervalMinutes;
            $key = $date . '|' . $bucket;
            if (!isset($buckets[$key])) {
                $buckets[$key] = [
                    'ts' => (string) ($b['ts_et'] ?? ''),
                    'date' => $date,
                    'minute' => $bucket,
                    'price' => (float) $b['price'],
                    'volume' => (int) ($b['volume'] ?? 0),
                ];
            } else {
                $buckets[$key]['price'] = (float) $b['price'];
                $buckets[$key]['volume'] += (int) ($b['volume'] ?? 0);
                $buckets[$key]['ts'] = (string) ($b['ts_et'] ?? $buckets[$key]['ts']);
            }
        }
        ksort($buckets);
        $out = [];
        foreach ($buckets as $b) {
            $h = 9 + intdiv(30 + $b['minute'], 60);
            $mi = (30 + $b['minute']) % 60;
            // session starts 09:30
            $total = 9 * 60 + 30 + $b['minute'];
            $h = intdiv($total, 60);
            $mi = $total % 60;
            $out[] = [
                'ts' => $b['ts'],
                'date' => $b['date'],
                'time' => sprintf('%02d:%02d', $h, $mi),
                'price' => $b['price'],
                'volume' => $b['volume'],
            ];
        }
        return $out;
    }

    /**
     * Wilder RSI + rolling volume z-score (20 bars).
     *
     * @param list<array{ts:string,date:string,time:string,price:float,volume:int}> $series
     * @return list<array{ts:string,date:string,time:string,price:float,volume:int,rsi:?float,vol_z:?float}>
     */
    private function attachRsi(array $series, int $period): array
    {
        $n = count($series);
        $gains = [];
        $losses = [];
        for ($i = 1; $i < $n; $i++) {
            $ch = $series[$i]['price'] - $series[$i - 1]['price'];
            $gains[$i] = max(0.0, $ch);
            $losses[$i] = max(0.0, -$ch);
        }

        $out = [];
        $avgGain = null;
        $avgLoss = null;
        $volWindow = [];

        for ($i = 0; $i < $n; $i++) {
            $rsi = null;
            if ($i === $period) {
                $sumG = 0.0;
                $sumL = 0.0;
                for ($j = 1; $j <= $period; $j++) {
                    $sumG += $gains[$j];
                    $sumL += $losses[$j];
                }
                $avgGain = $sumG / $period;
                $avgLoss = $sumL / $period;
                $rsi = $avgLoss <= 1e-12 ? 100.0 : 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss));
            } elseif ($i > $period && $avgGain !== null && $avgLoss !== null) {
                $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
                $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
                $rsi = $avgLoss <= 1e-12 ? 100.0 : 100.0 - (100.0 / (1.0 + $avgGain / $avgLoss));
            }

            $volWindow[] = (float) $series[$i]['volume'];
            if (count($volWindow) > 20) {
                array_shift($volWindow);
            }
            $volZ = null;
            if (count($volWindow) >= 10) {
                $mean = array_sum($volWindow) / count($volWindow);
                $var = 0.0;
                foreach ($volWindow as $v) {
                    $var += ($v - $mean) ** 2;
                }
                $sd = sqrt($var / count($volWindow));
                $volZ = $sd > 0 ? (($series[$i]['volume'] - $mean) / $sd) : 0.0;
            }

            $out[] = [
                'ts' => $series[$i]['ts'],
                'date' => $series[$i]['date'],
                'time' => $series[$i]['time'],
                'price' => $series[$i]['price'],
                'volume' => $series[$i]['volume'],
                'rsi' => $rsi,
                'vol_z' => $volZ,
            ];
        }
        return $out;
    }

    /**
     * @param list<array{price:float}> $series
     */
    private function forwardReturn(array $series, int $i, int $forwardBars): ?float
    {
        $j = $i + $forwardBars;
        if (!isset($series[$j])) {
            return null;
        }
        // stay inside same session when possible
        if (($series[$j]['date'] ?? '') !== ($series[$i]['date'] ?? '')) {
            // allow cross-session but prefer same-day: find last bar same day
            $last = $i;
            for ($k = $i + 1; $k < count($series) && $k <= $j; $k++) {
                if (($series[$k]['date'] ?? '') === ($series[$i]['date'] ?? '')) {
                    $last = $k;
                } else {
                    break;
                }
            }
            if ($last <= $i) {
                return null;
            }
            $j = $last;
        }
        $p0 = (float) $series[$i]['price'];
        $p1 = (float) $series[$j]['price'];
        if ($p0 <= 0) {
            return null;
        }
        return (($p1 - $p0) / $p0) * 100.0;
    }

    /**
     * Does high volume alone predict next move?
     *
     * @param list<array{vol_z:?float,price:float,date:string}> $series
     * @return array<string,mixed>
     */
    private function volumeEdge(array $series, int $forwardBars): array
    {
        $hi = [];
        $lo = [];
        foreach ($series as $i => $bar) {
            if ($bar['vol_z'] === null) {
                continue;
            }
            $fwd = $this->forwardReturn($series, $i, $forwardBars);
            if ($fwd === null) {
                continue;
            }
            $row = ['fwd_ret' => $fwd, 'fwd_up' => $fwd > 0];
            if ($bar['vol_z'] >= 1.5) {
                $hi[] = $row;
            } elseif ($bar['vol_z'] <= -0.5) {
                $lo[] = $row;
            }
        }
        $stat = static function (array $rows): array {
            $n = count($rows);
            if ($n === 0) {
                return ['n' => 0, 'up_pct' => null, 'avg_fwd_ret' => null];
            }
            $up = count(array_filter($rows, static fn ($r) => $r['fwd_up']));
            $sum = array_sum(array_column($rows, 'fwd_ret'));
            return [
                'n' => $n,
                'up_pct' => round(100 * $up / $n, 1),
                'avg_fwd_ret' => round($sum / $n, 3),
            ];
        };
        $hiS = $stat($hi);
        $loS = $stat($lo);
        $useful = null;
        if ($hiS['n'] >= 10 && $loS['n'] >= 10 && $hiS['up_pct'] !== null && $loS['up_pct'] !== null) {
            $useful = abs($hiS['up_pct'] - $loS['up_pct']) >= 8;
        }
        return [
            'high_volume' => $hiS,
            'low_volume' => $loS,
            'useful_alone' => $useful,
            'note' => $useful
                ? 'Volume alone shows a noticeable up-rate gap between high vs low volume bars.'
                : 'Volume alone does not show a clear directional edge in this sample.',
        ];
    }

    /**
     * @param array<string,mixed> $os
     * @param array<string,mixed> $ob
     * @param array<string,mixed> $cu
     * @param array<string,mixed> $vol
     */
    private function summary(string $symbol, array $os, array $ob, array $cu, array $vol, int $fwdMin): string
    {
        $parts = [];
        if ($os['n'] > 0) {
            $parts[] = "RSI≤30 (N={$os['n']}): next ~{$fwdMin}m up {$os['up_pct']}% (avg {$os['avg_fwd_ret']}%)"
                . ($os['works'] === true ? ' — bounce idea OK' : ($os['works'] === false ? ' — bounce idea weak' : ''));
        } else {
            $parts[] = 'No RSI≤30 samples in this history.';
        }
        if ($ob['n'] > 0) {
            $parts[] = "RSI≥70 (N={$ob['n']}): next up {$ob['up_pct']}% / down {$ob['down_pct']}% (avg {$ob['avg_fwd_ret']}%)"
                . ($ob['works'] === true ? ' — fade idea OK' : ($ob['works'] === false ? ' — fade idea weak' : ''));
        }
        if ($cu['n'] > 0) {
            $parts[] = "Cross up from oversold (N={$cu['n']}): next up {$cu['up_pct']}%";
        }
        $parts[] = $vol['note'];
        return "{$symbol} RSI study: " . implode(' · ', $parts);
    }
}
