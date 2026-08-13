-- Real-time Trading Platform — persistence schema.
-- Postgres for ACID order/fill/position/audit; TimescaleDB for time-series.
-- The pure-PHP core runs without these; the PDO/Timescale adapters use them
-- when infrastructure is enabled (see docker-compose.yml).

-- ---- Accounts -------------------------------------------------------------
CREATE TABLE IF NOT EXISTS accounts (
    id            TEXT PRIMARY KEY,
    name          TEXT NOT NULL,
    base_ccy      TEXT NOT NULL DEFAULT 'USDT',
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- ---- Orders (lifecycle) ---------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id            TEXT PRIMARY KEY,
    account_id    TEXT NOT NULL REFERENCES accounts(id),
    symbol        TEXT NOT NULL,
    side          TEXT NOT NULL CHECK (side IN ('BUY','SELL')),
    type          TEXT NOT NULL CHECK (type IN ('LIMIT','MARKET')),
    tif           TEXT NOT NULL CHECK (tif IN ('GTC','IOC','FOK')),
    price         NUMERIC(38,8),
    qty           NUMERIC(38,8) NOT NULL,
    filled_qty    NUMERIC(38,8) NOT NULL DEFAULT 0,
    status        TEXT NOT NULL,
    strategy      TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_orders_account ON orders(account_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_orders_symbol  ON orders(symbol, status);

-- ---- Fills (append-only execution records) --------------------------------
CREATE TABLE IF NOT EXISTS fills (
    id            BIGSERIAL PRIMARY KEY,
    trade_id      TEXT NOT NULL,
    order_id      TEXT NOT NULL REFERENCES orders(id),
    account_id    TEXT NOT NULL,
    symbol        TEXT NOT NULL,
    side          TEXT NOT NULL,
    price         NUMERIC(38,8) NOT NULL,
    qty           NUMERIC(38,8) NOT NULL,
    liquidity     TEXT NOT NULL CHECK (liquidity IN ('MAKER','TAKER')),
    ts            TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS idx_fills_order ON fills(order_id);

-- ---- Positions (updated atomically with fills) ----------------------------
CREATE TABLE IF NOT EXISTS positions (
    account_id    TEXT NOT NULL,
    symbol        TEXT NOT NULL,
    qty           NUMERIC(38,8) NOT NULL DEFAULT 0,
    avg_price     NUMERIC(38,8) NOT NULL DEFAULT 0,
    realized_pnl  NUMERIC(38,8) NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, symbol)
);

-- ---- Immutable audit trail (hash chain) -----------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    seq           BIGINT PRIMARY KEY,
    ts            TIMESTAMPTZ NOT NULL,
    event_type    TEXT NOT NULL,
    payload       JSONB NOT NULL,
    prev_hash     TEXT NOT NULL,
    hash          TEXT NOT NULL
);

-- ---- Time-series (TimescaleDB hypertables) --------------------------------
-- Requires the timescaledb extension; safe to skip on plain Postgres.
CREATE TABLE IF NOT EXISTS ticks (
    ts            TIMESTAMPTZ NOT NULL,
    symbol        TEXT NOT NULL,
    venue         TEXT NOT NULL,
    bid           NUMERIC(38,8),
    ask           NUMERIC(38,8),
    last          NUMERIC(38,8),
    volume        NUMERIC(38,8)
);
-- SELECT create_hypertable('ticks', 'ts', if_not_exists => TRUE);
