<?php

declare(strict_types=1);

namespace Stocks;

use PDO;

final class PriceRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function seedSymbols(array $symbols): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO symbols (symbol, name, yahoo_symbol, is_active)
             VALUES (:symbol, :name, :yahoo_symbol, 1)
             ON CONFLICT(symbol) DO UPDATE SET
               name = excluded.name,
               yahoo_symbol = excluded.yahoo_symbol,
               is_active = 1'
        );

        // MySQL uses ON DUPLICATE KEY UPDATE — detect driver
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $stmt = $this->pdo->prepare(
                'INSERT INTO symbols (symbol, name, yahoo_symbol, is_active)
                 VALUES (:symbol, :name, :yahoo_symbol, 1)
                 ON DUPLICATE KEY UPDATE
                   name = VALUES(name),
                   yahoo_symbol = VALUES(yahoo_symbol),
                   is_active = 1'
            );
        }

        foreach ($symbols as $row) {
            $stmt->execute([
                ':symbol' => $row['symbol'],
                ':name' => $row['name'],
                ':yahoo_symbol' => $row['yahoo_symbol'],
            ]);
        }
    }

    /** @return list<array{symbol:string,name:string,yahoo_symbol:string}> */
    public function activeSymbols(): array
    {
        $rows = $this->pdo->query(
            'SELECT symbol, name, yahoo_symbol FROM symbols WHERE is_active = 1 ORDER BY symbol'
        )->fetchAll();
        return $rows ?: [];
    }

    /**
     * @param list<array{
     *   ts_utc:string,ts_et:string,weekday:int,minute_of_day:int,price:float,volume:?int
     * }> $bars
     */
    public function upsertBars(string $symbol, array $bars): int
    {
        if ($bars === []) {
            return 0;
        }

        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $sql = 'INSERT INTO prices_1m
                      (symbol, ts_utc, ts_et, weekday, minute_of_day, price, volume)
                    VALUES
                      (:symbol, :ts_utc, :ts_et, :weekday, :minute_of_day, :price, :volume)
                    ON DUPLICATE KEY UPDATE
                      price = VALUES(price),
                      volume = VALUES(volume),
                      ts_et = VALUES(ts_et),
                      weekday = VALUES(weekday),
                      minute_of_day = VALUES(minute_of_day)';
        } else {
            $sql = 'INSERT INTO prices_1m
                      (symbol, ts_utc, ts_et, weekday, minute_of_day, price, volume)
                    VALUES
                      (:symbol, :ts_utc, :ts_et, :weekday, :minute_of_day, :price, :volume)
                    ON CONFLICT(symbol, ts_utc) DO UPDATE SET
                      price = excluded.price,
                      volume = excluded.volume,
                      ts_et = excluded.ts_et,
                      weekday = excluded.weekday,
                      minute_of_day = excluded.minute_of_day';
        }

        $stmt = $this->pdo->prepare($sql);
        $count = 0;
        $this->pdo->beginTransaction();
        try {
            foreach ($bars as $bar) {
                $stmt->execute([
                    ':symbol' => $symbol,
                    ':ts_utc' => $bar['ts_utc'],
                    ':ts_et' => $bar['ts_et'],
                    ':weekday' => $bar['weekday'],
                    ':minute_of_day' => $bar['minute_of_day'],
                    ':price' => $bar['price'],
                    ':volume' => $bar['volume'],
                ]);
                $count++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $count;
    }

    public function countBars(?string $symbol = null): int
    {
        if ($symbol === null) {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM prices_1m')->fetchColumn();
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM prices_1m WHERE symbol = :s');
        $stmt->execute([':s' => $symbol]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<array{weekday:int,minute_of_day:int,price:float,volume:?int,ts_et:string,session_date:string}> */
    public function barsForSymbol(string $symbol): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT weekday, minute_of_day, price, volume, ts_et,
                    substr(ts_et, 1, 10) AS session_date
             FROM prices_1m
             WHERE symbol = :s
             ORDER BY ts_et ASC"
        );
        $stmt->execute([':s' => $symbol]);
        return $stmt->fetchAll() ?: [];
    }

    public function latestTs(string $symbol): ?string
    {
        $stmt = $this->pdo->prepare('SELECT MAX(ts_et) FROM prices_1m WHERE symbol = :s');
        $stmt->execute([':s' => $symbol]);
        $v = $stmt->fetchColumn();
        return $v !== false && $v !== null ? (string) $v : null;
    }
}
