CREATE TABLE IF NOT EXISTS symbols (
  symbol TEXT PRIMARY KEY,
  name TEXT NOT NULL,
  yahoo_symbol TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS prices_1m (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  symbol TEXT NOT NULL,
  ts_utc TEXT NOT NULL,
  ts_et TEXT NOT NULL,
  weekday INTEGER NOT NULL,
  minute_of_day INTEGER NOT NULL,
  price REAL NOT NULL,
  volume INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (symbol, ts_utc)
);

CREATE INDEX IF NOT EXISTS idx_prices_symbol_weekday_minute
  ON prices_1m (symbol, weekday, minute_of_day);

CREATE INDEX IF NOT EXISTS idx_prices_symbol_ts_et
  ON prices_1m (symbol, ts_et);
