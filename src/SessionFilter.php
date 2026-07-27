<?php

declare(strict_types=1);

namespace Stocks;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Keep only weekday regular-trading-hours bars (default 09:30–16:00 ET).
 */
final class SessionFilter
{
    private DateTimeZone $tz;
    private int $startMinute;
    private int $endMinute;

    public function __construct(
        string $timezone = 'America/New_York',
        string $sessionStart = '09:30',
        string $sessionEnd = '16:00'
    ) {
        $this->tz = new DateTimeZone($timezone);
        $this->startMinute = self::parseHm($sessionStart);
        $this->endMinute = self::parseHm($sessionEnd);
    }

    /**
     * @param list<array{ts:int, price:float, volume:?int}> $bars
     * @return list<array{
     *   ts_utc:string,
     *   ts_et:string,
     *   weekday:int,
     *   minute_of_day:int,
     *   price:float,
     *   volume:?int
     * }>
     */
    public function filter(array $bars): array
    {
        $out = [];
        foreach ($bars as $bar) {
            $utc = (new DateTimeImmutable('@' . (int) $bar['ts']))->setTimezone(new DateTimeZone('UTC'));
            $et = $utc->setTimezone($this->tz);

            $weekday = (int) $et->format('N'); // 1=Mon … 7=Sun
            if ($weekday > 5) {
                continue;
            }

            $minute = ((int) $et->format('H')) * 60 + (int) $et->format('i');
            if ($minute < $this->startMinute || $minute >= $this->endMinute) {
                continue;
            }

            $out[] = [
                'ts_utc' => $utc->format('Y-m-d H:i:s'),
                'ts_et' => $et->format('Y-m-d H:i:s'),
                'weekday' => $weekday,
                'minute_of_day' => $minute - $this->startMinute,
                'price' => (float) $bar['price'],
                'volume' => $bar['volume'] ?? null,
            ];
        }

        return $out;
    }

    public function minuteLabel(int $minuteOfDay): string
    {
        $total = $this->startMinute + $minuteOfDay;
        $h = intdiv($total, 60);
        $m = $total % 60;
        return sprintf('%02d:%02d', $h, $m);
    }

    public function sessionLengthMinutes(): int
    {
        return $this->endMinute - $this->startMinute;
    }

    private static function parseHm(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm));
        return $h * 60 + $m;
    }
}
