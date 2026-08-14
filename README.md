# Real-time Trading Platform with Risk Management

A high-frequency cryptocurrency trading platform in **PHP 8.3**, covering the full
assignment across four milestones: real-time market data, a price-time matching
engine, real-time risk + regulatory compliance, and performance/analytics — with
a **live web dashboard**.

The trading core is pure PHP (only `ext-bcmath`) so it runs and tests anywhere.
Infrastructure (Swoole, Redis, Postgres/TimescaleDB, Kafka, ClickHouse) sits
behind interfaces and is optional — see [DESIGN.md](DESIGN.md).

📖 **New here? Read the [Complete Guide](docs/GUIDE.md)** — what the project is,
how it works, how to run it, and how to use every feature.

![milestones](https://img.shields.io/badge/milestones-M1..M4-blue) ·
36 passing correctness checks · deterministic & reproducible.

---

## Quick start (no infrastructure required)

```bash
composer install          # generates the autoloader (no external deps)
composer test             # 36 correctness checks
composer demo             # end-to-end CLI run touching every milestone
composer serve            # live dashboard → http://127.0.0.1:8080
```

Then open **http://127.0.0.1:8080** and press **▶ Play**.

### Live vs simulated data

The dashboard has a **SIM / LIVE** toggle:

- **SIM** — a deterministic, reproducible market simulation (same seed → same run).
- **LIVE** — **real market data** from Binance + Coinbase public APIs: live prices,
  real order book (Binance depth), real trade tape, real BTC/ETH correlation, and
  cross-venue basis. Your orders are matched at the **real best bid/offer** but are
  **not** sent to any exchange (no API keys, no real money). All five feeds are
  fetched in one parallel `curl_multi` batch (~0.5s/tick).

### Run on Swoole (the assignment's high-performance target)

Swoole is Linux-only; on Windows use WSL:

```bash
bash scripts/install-swoole-wsl.sh    # installs PHP 8.3 + Swoole in WSL
php bin/server.php                    # coroutine HTTP server, one worker/core
```

---

## What each milestone maps to

| Milestone | Where |
|---|---|
| **M1** Market data: normalization, order-book, aggregation, anomaly filter, RSI/MACD/VWAP, volatility & correlation | `src/MarketData/*`, `src/Analysis/*` |
| **M2** Matching engine: price-time priority, FIFO & pro-rata, FOK/IOC, lifecycle, atomic execution, routing & slicing, strategies | `src/Matching/*`, `src/Order/*`, `src/Routing/*`, `src/Strategy/*` |
| **M3** Risk: pre-trade gate, VaR/CVaR, exposure & concentration, margin, stop-loss, drawdown kill-switch, alerts; compliance: audit hash-chain, MiFID II, surveillance | `src/Risk/*`, `src/Compliance/*` |
| **M4** Analytics: real-time P&L, Sharpe/Sortino, attribution, backtesting; performance: object pool, lock-free ring buffer, latency monitor | `src/Analytics/*`, `src/Perf/*` |

Everything is wired together in `src/Engine/TradingPlatform.php`, which drives a
**deterministic** market simulation: the entire run is a pure function of
`(seed, steps, manualOrders)`, so the web layer persists only those and rebuilds
identical state on each request — no shared in-memory state, works the same under
`php -S` and Swoole.

## Dashboard features

Live price + VWAP chart, equity curve, depth-of-book ladder, trade tape, P&L with
Sharpe/Sortino/drawdown, the full risk panel (VaR/CVaR/exposure/margin/kill-switch),
technical indicators, BTC/ETH correlation & volatility, strategy attribution,
compliance (audit-chain validity, MiFID count, surveillance flags), latency
percentiles, a **manual order ticket** (Market/Limit · IOC/FOK/GTC), and a live
alert feed. Toggle **FIFO ↔ Pro-rata** matching and reseed on **Reset**.

## Design notes

- **Decimal money, never floats** on the order/fill path (`src/Support/Decimal.php`).
- **One book per symbol / actor model** → correctness without locks (see DESIGN.md §3).
- **Hot path is DB-free**; persistence & analytics are downstream consumers.
- **Audit as a hash chain** — tampering with any past entry is detectable
  (`composer test` proves it).

## Run the whole stack in Docker (app + TimescaleDB + Redis)

The app image is **PHP 8.3 + Swoole 6.2 + pdo_pgsql + redis**, so the containerized
server satisfies the "PHP 8.2+ with Swoole" requirement natively.

```bash
docker compose up -d --build                      # app (Swoole) + TimescaleDB + Redis
open http://127.0.0.1:8080                         # live dashboard, served by Swoole

# Data push — persist a full run into Postgres under a named account (ACID):
docker compose run --rm app php bin/persist.php 600

# Verify it landed:
docker compose exec postgres psql -U trading -d trading -c "SELECT * FROM positions;"
docker compose exec redis redis-cli XLEN "md.BTC/USDT.trades"
```

`bin/persist.php` runs the simulation in recording mode and writes **accounts,
orders, fills, positions and the immutable audit chain** to Postgres in a single
transaction (account defaults to `Mehedi Hasan` via `TRADER_NAME`), then publishes
trades to a Redis Stream.

See [DESIGN.md](DESIGN.md) for the full architecture, data model, and build plan.
