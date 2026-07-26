<?php

declare(strict_types=1);

namespace Stocks;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Yahoo Finance chart API client for 1-minute bars.
 * Yahoo allows ~7–8 days of 1m data per request; we chunk longer windows.
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
    public function fetchOneMinute(string $yahooSymbol, int $calendarDays = 14): array
    {
        $end = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $start = $end->modify(sprintf('-%d days', max(1, $calendarDays)));

        // Chunk by 7 days to stay under Yahoo's 1m limit
        $chunkDays = 7;
        $cursor = $start;
        $all = [];

        while ($cursor < $end) {
            $chunkEnd = $cursor->modify(sprintf('+%d days', $chunkDays));
            if ($chunkEnd > $end) {
                $chunkEnd = $end;
            }

            $rows = $this->fetchRange(
                $yahooSymbol,
                (int) $cursor->format('U'),
                (int) $chunkEnd->format('U')
            );
            foreach ($rows as $row) {
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
