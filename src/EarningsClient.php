<?php

declare(strict_types=1);

namespace Stocks;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * US earnings calendar via Nasdaq public API (EPS actual vs estimate).
 */
final class EarningsClient
{
    private const API = 'https://api.nasdaq.com/api/calendar/earnings';

    /** Mega / QQQ / semis names useful around SOXL & QQQ */
    private const WATCHLIST = [
        'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'GOOG', 'AVGO', 'TSLA', 'COST',
        'NFLX', 'AMD', 'ADBE', 'PEP', 'CSCO', 'TMUS', 'LIN', 'INTU', 'QCOM', 'AMAT',
        'TXN', 'ISRG', 'CMCSA', 'INTC', 'AMGN', 'HON', 'BKNG', 'VRTX', 'ADP', 'SBUX',
        'GILD', 'PANW', 'ADI', 'MU', 'LRCX', 'KLAC', 'SNPS', 'CDNS', 'MRVL', 'ASML',
        'TSM', 'ARM', 'SMCI', 'CRWD', 'PLTR', 'APP', 'MSTR', 'ORCL', 'IBM', 'NOW',
        'CRM', 'UBER', 'SHOP', 'SQ', 'COIN', 'SOFI', 'RIVN', 'NIO',
    ];

    public function __construct(
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; StocksEarnings/1.0)',
        private readonly int $requestDelayMs = 120,
        private readonly ?string $cacheDir = null
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function calendar(
        int $pastDays = 14,
        int $futureDays = 14,
        string $filter = 'major', // major|watchlist|all
        int $minMarketCapB = 10
    ): array {
        $tz = new DateTimeZone('America/New_York');
        $today = new DateTimeImmutable('today', $tz);
        $start = $today->sub(new DateInterval('P' . max(0, $pastDays) . 'D'));
        $end = $today->add(new DateInterval('P' . max(0, $futureDays) . 'D'));

        $byDate = [];
        $cursor = $start;
        while ($cursor <= $end) {
            $wd = (int) $cursor->format('N');
            if ($wd <= 5) {
                $date = $cursor->format('Y-m-d');
                try {
                    $rows = $this->fetchDay($date);
                } catch (\Throwable $e) {
                    $rows = [];
                    $byDate[$date] = ['date' => $date, 'error' => $e->getMessage(), 'rows' => []];
                    $cursor = $cursor->add(new DateInterval('P1D'));
                    continue;
                }
                $parsed = [];
                foreach ($rows as $r) {
                    $item = $this->normalizeRow($r, $date);
                    if ($item === null) {
                        continue;
                    }
                    if (!$this->passesFilter($item, $filter, $minMarketCapB)) {
                        continue;
                    }
                    $parsed[] = $item;
                }
                usort($parsed, static function ($a, $b) {
                    return ($b['market_cap'] ?? 0) <=> ($a['market_cap'] ?? 0);
                });
                $byDate[$date] = [
                    'date' => $date,
                    'label' => $cursor->format('D'),
                    'count' => count($parsed),
                    'rows' => $parsed,
                ];
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
            if ($this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        $past = [];
        $upcoming = [];
        $todayStr = $today->format('Y-m-d');
        foreach ($byDate as $date => $block) {
            if ($date < $todayStr) {
                $past[] = $block;
            } else {
                $upcoming[] = $block;
            }
        }
        // past newest first, upcoming soonest first
        usort($past, static fn ($a, $b) => strcmp($b['date'], $a['date']));
        usort($upcoming, static fn ($a, $b) => strcmp($a['date'], $b['date']));

        $flatPast = $this->flatten($past);
        $flatUp = $this->flatten($upcoming);
        $beats = 0;
        $misses = 0;
        $withActual = 0;
        foreach ($flatPast as $r) {
            if ($r['eps'] === null || $r['eps_estimate'] === null) {
                continue;
            }
            $withActual++;
            if ($r['eps'] > $r['eps_estimate']) {
                $beats++;
            } elseif ($r['eps'] < $r['eps_estimate']) {
                $misses++;
            }
        }

        return [
            'ok' => true,
            'as_of' => $todayStr,
            'timezone' => 'America/New_York',
            'filter' => $filter,
            'min_market_cap_b' => $minMarketCapB,
            'range' => [
                'from' => $start->format('Y-m-d'),
                'to' => $end->format('Y-m-d'),
                'past_days' => $pastDays,
                'future_days' => $futureDays,
            ],
            'stats' => [
                'past_reports' => count($flatPast),
                'upcoming_reports' => count($flatUp),
                'past_with_eps' => $withActual,
                'beats' => $beats,
                'misses' => $misses,
                'beat_pct' => $withActual > 0 ? round(100 * $beats / $withActual, 1) : null,
            ],
            'past' => $past,
            'upcoming' => $upcoming,
            'watchlist' => self::WATCHLIST,
            'summary_text' => $this->summary($flatPast, $flatUp, $withActual, $beats, $filter),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchDay(string $date): array
    {
        $cacheFile = null;
        if ($this->cacheDir !== null) {
            if (!is_dir($this->cacheDir)) {
                @mkdir($this->cacheDir, 0775, true);
            }
            $cacheFile = rtrim($this->cacheDir, '/') . '/earnings_' . $date . '.json';
            if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 6 * 3600) {
                $cached = json_decode((string) file_get_contents($cacheFile), true);
                if (is_array($cached)) {
                    return $cached;
                }
            }
        }

        $url = self::API . '?' . http_build_query(['date' => $date]);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to init cURL');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json, text/plain, */*',
                'User-Agent: ' . $this->userAgent,
                'Origin: https://www.nasdaq.com',
                'Referer: https://www.nasdaq.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new RuntimeException('Nasdaq earnings request failed: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Nasdaq earnings HTTP {$status}");
        }
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            throw new RuntimeException('Invalid Nasdaq earnings JSON');
        }
        $rows = $json['data']['rows'] ?? [];
        if (!is_array($rows)) {
            $rows = [];
        }
        if ($cacheFile !== null) {
            file_put_contents($cacheFile, json_encode($rows));
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $r
     * @return ?array<string,mixed>
     */
    private function normalizeRow(array $r, string $date): ?array
    {
        $symbol = strtoupper(trim((string) ($r['symbol'] ?? '')));
        if ($symbol === '') {
            return null;
        }
        $eps = $this->parseMoney($r['eps'] ?? null);
        $est = $this->parseMoney($r['epsForecast'] ?? null);
        $surprise = null;
        if (isset($r['surprise']) && $r['surprise'] !== '' && $r['surprise'] !== null) {
            $surprise = is_numeric($r['surprise']) ? (float) $r['surprise'] : null;
        }
        if ($surprise === null && $eps !== null && $est !== null && abs($est) > 1e-9) {
            $surprise = (($eps - $est) / abs($est)) * 100.0;
        }
        $mcap = $this->parseMarketCap($r['marketCap'] ?? null);
        $timeRaw = (string) ($r['time'] ?? '');
        $time = match (true) {
            str_contains($timeRaw, 'pre') => 'Pre-market',
            str_contains($timeRaw, 'after') => 'After hours',
            str_contains($timeRaw, 'not-supplied') => 'Time TBD',
            default => $timeRaw !== '' ? $timeRaw : 'Time TBD',
        };

        $result = 'pending';
        if ($eps !== null && $est !== null) {
            $result = $eps > $est ? 'beat' : ($eps < $est ? 'miss' : 'inline');
        } elseif ($eps !== null) {
            $result = 'reported';
        }

        return [
            'date' => $date,
            'symbol' => $symbol,
            'name' => trim((string) ($r['name'] ?? $symbol)),
            'time' => $time,
            'fiscal_quarter' => (string) ($r['fiscalQuarterEnding'] ?? ''),
            'eps' => $eps,
            'eps_estimate' => $est,
            'surprise_pct' => $surprise !== null ? round($surprise, 2) : null,
            'result' => $result,
            'num_estimates' => isset($r['noOfEsts']) && is_numeric($r['noOfEsts']) ? (int) $r['noOfEsts'] : null,
            'last_year_eps' => $this->parseMoney($r['lastYearEPS'] ?? null),
            'last_year_date' => isset($r['lastYearRptDt']) ? (string) $r['lastYearRptDt'] : null,
            'market_cap' => $mcap,
            'market_cap_label' => (string) ($r['marketCap'] ?? ''),
            'watchlist' => in_array($symbol, self::WATCHLIST, true),
        ];
    }

    /** @param array<string,mixed> $item */
    private function passesFilter(array $item, string $filter, int $minMarketCapB): bool
    {
        if ($filter === 'all') {
            return true;
        }
        if ($filter === 'watchlist') {
            return (bool) $item['watchlist'];
        }
        // major = large cap OR watchlist
        $cap = $item['market_cap'] ?? null;
        if ($item['watchlist']) {
            return true;
        }
        if ($cap === null) {
            return false;
        }
        return $cap >= ($minMarketCapB * 1_000_000_000);
    }

    private function parseMoney(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === 'N/A' || $v === '--') {
            return null;
        }
        if (is_numeric($v)) {
            return (float) $v;
        }
        $s = str_replace([',', '$', ' '], '', (string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    private function parseMarketCap(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === 'N/A') {
            return null;
        }
        $s = strtoupper(str_replace([',', '$', ' '], '', (string) $v));
        if ($s === '' || !is_numeric($s)) {
            return null;
        }
        return (float) $s;
    }

    /**
     * @param list<array{rows:list<array<string,mixed>>}> $blocks
     * @return list<array<string,mixed>>
     */
    private function flatten(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            foreach ($b['rows'] as $r) {
                $out[] = $r;
            }
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $past
     * @param list<array<string,mixed>> $up
     */
    private function summary(array $past, array $up, int $withActual, int $beats, string $filter): string
    {
        $beatPct = $withActual > 0 ? round(100 * $beats / $withActual) : null;
        $msg = 'US earnings (' . $filter . '): '
            . count($past) . ' reports in last ~2 weeks'
            . ($beatPct !== null ? ", beat rate {$beatPct}%" : '')
            . '; ' . count($up) . ' upcoming in the next ~2 weeks.';
        $mega = array_values(array_filter($up, static fn ($r) => ($r['market_cap'] ?? 0) >= 200_000_000_000));
        if ($mega !== []) {
            $syms = array_slice(array_column($mega, 'symbol'), 0, 8);
            $msg .= ' Notable upcoming: ' . implode(', ', $syms) . '.';
        }
        return $msg;
    }
}
