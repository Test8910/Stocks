CREATE DATABASE IF NOT EXISTS stocks
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE stocks;

CREATE TABLE IF NOT EXISTS symbols (
  symbol VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  yahoo_symbol VARCHAR(30) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS prices_1m (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  symbol VARCHAR(20) NOT NULL,
  ts_utc DATETIME NOT NULL,
  ts_et DATETIME NOT NULL,
  weekday TINYINT NOT NULL,
  minute_of_day SMALLINT NOT NULL,
  price DECIMAL(12, 4) NOT NULL,
  volume BIGINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_symbol_ts (symbol, ts_utc),
  KEY idx_symbol_weekday_minute (symbol, weekday, minute_of_day),
  KEY idx_symbol_ts_et (symbol, ts_et)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
