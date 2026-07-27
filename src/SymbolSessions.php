<?php

declare(strict_types=1);

namespace Stocks;

/**
 * Resolve per-symbol trading sessions (US / UK / Asia).
 */
final class SymbolSessions
{
    /**
     * @param array<string,mixed> $config
     * @return array{
     *   symbol:string,
     *   name:string,
     *   yahoo_symbol:string,
     *   region:string,
     *   timezone:string,
     *   session_start:string,
     *   session_end:string,
     *   role:string
     * }
     */
    public static function resolve(array $config, string $symbol): array
    {
        $symbol = strtoupper($symbol);
        foreach ($config['symbols'] ?? [] as $row) {
            if (strtoupper((string) ($row['symbol'] ?? '')) !== $symbol) {
                continue;
            }
            return self::normalize($row, $config);
        }

        return self::normalize([
            'symbol' => $symbol,
            'name' => $symbol,
            'yahoo_symbol' => $symbol,
        ], $config);
    }

    /**
     * @param array<string,mixed> $config
     * @return list<array{
     *   symbol:string,
     *   name:string,
     *   yahoo_symbol:string,
     *   region:string,
     *   timezone:string,
     *   session_start:string,
     *   session_end:string,
     *   role:string
     * }>
     */
    public static function all(array $config): array
    {
        $out = [];
        foreach ($config['symbols'] ?? [] as $row) {
            $out[] = self::normalize($row, $config);
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $config
     * @return array{
     *   symbol:string,
     *   name:string,
     *   yahoo_symbol:string,
     *   region:string,
     *   timezone:string,
     *   session_start:string,
     *   session_end:string,
     *   role:string
     * }
     */
    public static function normalize(array $row, array $config): array
    {
        $region = strtolower((string) ($row['region'] ?? 'us'));
        $defaults = match ($region) {
            'asia' => [
                'timezone' => 'Asia/Hong_Kong',
                'session_start' => '09:30',
                'session_end' => '16:00',
            ],
            'uk', 'europe' => [
                'timezone' => 'Europe/London',
                'session_start' => '08:00',
                'session_end' => '16:30',
            ],
            default => [
                'timezone' => (string) ($config['timezone'] ?? 'America/New_York'),
                'session_start' => (string) ($config['session_start'] ?? '09:30'),
                'session_end' => (string) ($config['session_end'] ?? '16:00'),
            ],
        };

        return [
            'symbol' => strtoupper((string) $row['symbol']),
            'name' => (string) ($row['name'] ?? $row['symbol']),
            'yahoo_symbol' => (string) ($row['yahoo_symbol'] ?? $row['symbol']),
            'region' => $region === 'europe' ? 'uk' : $region,
            'timezone' => (string) ($row['timezone'] ?? $defaults['timezone']),
            'session_start' => (string) ($row['session_start'] ?? $defaults['session_start']),
            'session_end' => (string) ($row['session_end'] ?? $defaults['session_end']),
            'role' => (string) ($row['role'] ?? 'other'),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function filterFor(array $row): SessionFilter
    {
        return new SessionFilter(
            (string) $row['timezone'],
            (string) $row['session_start'],
            (string) $row['session_end']
        );
    }
}
