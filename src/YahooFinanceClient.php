<?php

declare(strict_types=1);

namespace Stocks;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Yahoo Finance chart API client for 1-minute bars.
 *
 * Limits (Yahoo):
 * - 1m data only exists inside roughly the last ~30 days
 * - each request may cover at most ~7–8 days, so we chunk
 */
final class YahooFinanceClient
{
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/%s';

    public function __construct(
        private readonly string $userAgent = 'Mozilla/5.0 (compatible; StocksIntraday/1.0)',
        private readonly int $requestDelayMs = 350
    ) {
    }

    /**
     * @return list<array{ts:int, price:float, volume:?int}>
     */
    public function fetchOneMinute(string $yahooSymbol, int $calendarDays = 30): array
    {
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        // Yahoo rejects 1m windows older than ~30 days
        $calendarDays = max(1, min(30, $calendarDays));
        $start = $end->modify(sprintf('-%d days', $calendarDays));

        // Chunk by 7 days to stay under Yahoo's per-request 1m limit
        $chunkDays = 7;
        $cursor = $start;
        $all = [];

        while ($cursor < $end) {
            $chunkEnd = $cursor->modify(sprintf('+%d days', $chunkDays));
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $p1 = (int) $cursor->format('U');
            $p2 = (int) $chunkEnd->format('U');
            $rows = $this->fetchRange($yahooSymbol, $p1, $p2);
            foreach ($rows as $row) {
                // Keep only bars inside the requested chunk window
                if ($row['ts'] < $p1 || $row['ts'] > $p2) {
                    continue;
                }
                $all[$row['ts']] = $row;
            }

            $cursor = $chunkEnd;
            if ($this->requestDelayMs > 0) {
                usleep($this->requestDelayMs * 1000);
            }
        }

        ksort($all);
        return array_values($all);
    }

    /**
     * Recent bars for cron sync (last ~2 calendar days).
     *
     * @return list<array{ts:int, price:float, volume:?int}>
     */
    public function fetchRecent(string $yahooSymbol, string $range = '2d'): array
    {
        $url = sprintf(self::CHART_URL, rawurlencode($yahooSymbol));
        $url .= '?' . http_build_query([
            'interval' => '1m',
            'range' => $range,
            'includePrePost' => 'false',
        ]);

        return $this->parseChart($this->getJson($url), $yahooSymbol);
    }

    /**
     * Near-live quote from Yahoo chart meta (often delayed).
     *
     * @return array{
     *   yahoo_symbol:string,
     *   price:float,
     *   prev_close:float,
     *   change:float,
     *   change_pct:float,
     *   currency:string,
     *   exchange:string,
     *   market_state:string,
     *   as_of_utc:string,
     *   session_open:?float,
     *   session_change_pct:?float
     * }
     */
    public function fetchQuote(string $yahooSymbol, bool $includePrePost = true): array
    {
        $url = sprintf(self::CHART_URL, rawurlencode($yahooSymbol));
        $url .= '?' . http_build_query([
            'interval' => '1m',
            'range' => '1d',
            'includePrePost' => $includePrePost ? 'true' : 'false',
        ]);

        $payload = $this->getJson($url);
        $result = $payload['chart']['result'][0] ?? null;
        if ($result === null) {
            $error = $payload['chart']['error']['description'] ?? 'Unknown Yahoo Finance error';
            throw new RuntimeException("No quote for {$yahooSymbol}: {$error}");
        }

        $meta = $result['meta'] ?? [];
        $price = (float) ($meta['regularMarketPrice'] ?? $meta['previousClose'] ?? 0);
        $prev = (float) ($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? 0);
        if ($price <= 0 && $prev > 0) {
            $price = $prev;
        }
        $change = $prev > 0 ? $price - $prev : 0.0;
        $changePct = $prev > 0 ? ($change / $prev) * 100.0 : 0.0;

        $ts = (int) ($meta['regularMarketTime'] ?? time());
        $asOf = (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'));

        // First 1m print of the day as session open (better lead than prev close when session is live)
        $sessionOpen = null;
        $bars = $this->parseChart($payload, $yahooSymbol);
        if ($bars !== []) {
            $sessionOpen = (float) $bars[0]['price'];
        }
        $sessionChangePct = ($sessionOpen !== null && $sessionOpen > 0)
            ? (($price - $sessionOpen) / $sessionOpen) * 100.0
            : null;

        return [
            'yahoo_symbol' => $yahooSymbol,
            'price' => round($price, 4),
            'prev_close' => round($prev, 4),
            'change' => round($change, 4),
            'change_pct' => round($changePct, 3),
            'currency' => (string) ($meta['currency'] ?? ''),
            'exchange' => (string) ($meta['exchangeName'] ?? $meta['fullExchangeName'] ?? ''),
            'market_state' => (string) ($meta['marketState'] ?? 'UNKNOWN'),
            'as_of_utc' => $asOf->format('Y-m-d H:i:s'),
            'session_open' => $sessionOpen !== null ? round($sessionOpen, 4) : null,
            'session_change_pct' => $sessionChangePct !== null ? round($sessionChangePct, 3) : null,
        ];
    }

    /**
     * @return list<array{ts:int, price:float, volume:?int}>
     */
    private function fetchRange(string $yahooSymbol, int $period1, int $period2): array
    {
        if ($period2 <= $period1) {
            return [];
        }

        $url = sprintf(self::CHART_URL, rawurlencode($yahooSymbol));
        $url .= '?' . http_build_query([
            'interval' => '1m',
            'period1' => $period1,
            'period2' => $period2,
            'includePrePost' => 'false',
        ]);

        return $this->parseChart($this->getJson($url), $yahooSymbol);
    }

    /**
     * @return list<array{ts:int, price:float, volume:?int}>
     */
    private function parseChart(array $payload, string $yahooSymbol): array
    {
        $result = $payload['chart']['result'][0] ?? null;
        if ($result === null) {
            $error = $payload['chart']['error']['description'] ?? 'Unknown Yahoo Finance error';
            // Empty window is OK for chunked history
            if (str_contains(strtolower($error), 'not available')) {
                return [];
            }
            throw new RuntimeException("No chart data for {$yahooSymbol}: {$error}");
        }

        $timestamps = $result['timestamp'] ?? [];
        $quote = $result['indicators']['quote'][0] ?? [];
        $closes = $quote['close'] ?? [];
        $volumes = $quote['volume'] ?? [];

        $rows = [];
        foreach ($timestamps as $i => $ts) {
            $close = $closes[$i] ?? null;
            if ($close === null) {
                continue;
            }
            $rows[] = [
                'ts' => (int) $ts,
                'price' => round((float) $close, 4),
                'volume' => isset($volumes[$i]) && $volumes[$i] !== null ? (int) $volumes[$i] : null,
            ];
        }

        return $rows;
    }

    private function getJson(string $url): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize cURL');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Yahoo Finance request failed: ' . $error);
        }

        if ($status < 200 || $status >= 300) {
            // Soft-fail empty windows for chunked history
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
            throw new RuntimeException("Yahoo Finance HTTP {$status}: " . substr((string) $body, 0, 200));
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON from Yahoo Finance');
        }

        return $decoded;
    }
}
