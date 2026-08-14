# Real-time Trading Platform — Complete Guide

Everything about this project in one place: what it is, how it works, how to run
it, and how to use every feature. If you read only one document, read this one.

> Companion docs: [README.md](../README.md) (quick start),
> [DESIGN.md](../DESIGN.md) (architecture rationale),
> [PROJECT_GUIDE.md](PROJECT_GUIDE.md) (file-by-file reference),
> [requirements.html](requirements.html) (per-requirement score matrix).

---

## 1. What is this project?

A **high-frequency cryptocurrency trading platform** that processes market data
in real time, runs trading strategies, enforces risk limits before every trade,
keeps a tamper-proof compliance trail, and shows it all on a live web dashboard.

It was built to the assignment *"Real-time Trading Platform with Risk
Management"* and covers four milestones:

| Milestone | What it delivers |
|---|---|
| **M1 — Market Data** | Ingest real prices, normalize them, rebuild the order book, aggregate across exchanges, filter anomalies, compute indicators (RSI, MACD, VWAP), volatility and correlation |
| **M2 — Trading Engine** | A price-time order matching engine (FIFO + pro-rata), full order lifecycle, FOK/IOC/GTC, trading strategies, smart routing and order slicing |
| **M3 — Risk & Compliance** | Pre-trade risk gate, VaR/CVaR, exposure & concentration limits, stop-loss, a drawdown kill-switch, an immutable audit hash-chain, MiFID II reports and trade surveillance |
| **M4 — Analytics & Performance** | Real-time P&L, Sharpe/Sortino, performance attribution, backtesting, memory pools, a lock-free ring buffer and latency monitoring |

**Two ways to run the market:**

- **SIM** — a deterministic simulated market (reproducible from a seed).
- **LIVE** — **real market data** from Binance + Coinbase public APIs. Your
  orders are matched at the real best bid/offer but are **never sent to an
  exchange** (no API keys, no real money).

**Tech stack:** PHP 8.3, Swoole (coroutine HTTP server), Docker,
PostgreSQL/TimescaleDB, Redis. The core logic depends only on `ext-bcmath`, so it
runs and tests anywhere; the infrastructure is optional and containerized.

---

## 2. How it works (the mental model)

### 2.1 One tick, end to end

Each "tick" of the platform flows through the same pipeline:

```
 market data ──► indicators ──► strategies ──► PRE-TRADE RISK GATE ──► matching
   (real or        (RSI,          (market-       (limits, VaR,          engine
    simulated)      MACD,          maker,          drawdown)             (FIFO /
                    VWAP)          momentum)          │                   pro-rata)
                                                      │ approved              │
                                          rejected ◄──┘                       ▼
                                          (audit + alert)              trades + fills
                                                                             │
                                                                             ▼
                                        positions · P&L · portfolio risk (VaR/CVaR)
                                                                             │
                                                                             ▼
                                        compliance: audit hash-chain · MiFID · surveillance
                                                                             │
                                                                             ▼
                                        analytics + latency ──► dashboard snapshot (JSON)
```

The **hot path** (order → risk gate → match → fill) never touches a database.
Persistence and analytics happen *after*, by consuming the results.

### 2.2 Two key design ideas

1. **Decimal money, never floats.** Every price and quantity on the order path is
   a `Decimal` (backed by bcmath), so `0.1 + 0.2` is exactly `0.3`. Rounding drift
   on money is a bug, not a rounding detail.

2. **Determinism (SIM) vs. real state (LIVE).**
   - In **SIM**, the entire run is a pure function of `(seed, steps, orders)`, so
     the server rebuilds identical state on every request — no shared memory
     needed. Same seed ⇒ identical run.
   - In **LIVE**, real data isn't reproducible, so the running state (position,
     P&L, indicators, audit chain) is serialized to disk between requests and
     resumed.

### 2.3 The matching engine (the heart)

- **Price-time priority:** best price matches first; ties broken by who arrived
  first.
- **Execution at the maker price:** the resting order's price wins (the taker
  gets price improvement) — standard exchange behaviour.
- **Time-in-force:** `GTC` rests the remainder, `IOC` cancels it, `FOK` fills the
  *whole* order immediately or nothing at all (checked before any state changes).
- **Self-trade prevention:** an account never matches against itself.

### 2.4 The risk gate

Every order — strategy or manual — passes a **synchronous pre-trade check**
before it can reach the book. It validates order notional, per-symbol exposure,
gross exposure, concentration, and margin. A rejected order never touches the
book; it's logged to the audit trail and raised as an alert. Continuously, the
engine recomputes VaR/CVaR and trips a **kill-switch** if drawdown breaches its
limit.

### 2.5 The audit hash-chain

Every significant event is appended to an **immutable log** where each entry's
SHA-256 hash includes the previous entry's hash. Altering any past record breaks
the chain, and `verify()` detects exactly where. This is the "immutable log"
compliance requirement, done in a verifiable way (the test suite proves tampering
is caught).

---

## 3. Project structure

```
src/
  Support/       Decimal money, Clock, IDs, deterministic RNG
  Order/         Order, Fill, enums, execution reports
  Matching/      OrderBook, PriceLevel, MatchingEngine, Trade
  MarketData/    MarketTick, Normalizer, Aggregator, AnomalyFilter,
                 ExchangeClient (REAL Binance/Coinbase feeds)
  Analysis/      Indicators (RSI/MACD/VWAP), Statistics (vol/correlation)
  Strategy/      Strategy interface, MarketMaker, Momentum
  Routing/       SmartRouter, Slicer (TWAP/iceberg)
  Risk/          Position, Portfolio, RiskMetrics (VaR/CVaR), RiskEngine,
                 RiskLimits, StopLoss, AlertManager
  Compliance/    AuditLog (hash-chain), MiFIDReporter, Surveillance
  Analytics/     PnL, Metrics (Sharpe/Sortino), Attribution, Backtest
  Perf/          ObjectPool, RingBuffer, LatencyMonitor
  Engine/        TradingPlatform (SIM), LiveEngine (LIVE)
  Web/           Api (JSON endpoints)
  Persistence/   PostgresStore (ACID), RedisPublisher (Streams)
bin/             demo.php (CLI), server.php (Swoole), persist.php (data push)
public/          index.php (router), dashboard.html (the UI)
config/          config.php
db/migrations/   Postgres schema
tests/           run.php (36 checks)
Dockerfile, docker-compose.yml
```

A file-by-file explanation is in [PROJECT_GUIDE.md](PROJECT_GUIDE.md).

---

## 4. How to run it

### 4.1 Fastest — Docker (recommended)

Runs the whole stack: the Swoole app + TimescaleDB + Redis.

```bash
docker compose up -d --build
```

Then open **http://127.0.0.1:8080**.

Stop it with `docker compose down` (add `-v` to also wipe the database volumes).

### 4.2 Local, no infrastructure

Needs PHP 8.2+ with `ext-bcmath` and Composer.

```bash
composer install      # generates the autoloader (no third-party deps)
composer test         # 36 correctness checks
composer demo         # end-to-end CLI report across all milestones
composer serve        # dashboard at http://127.0.0.1:8080
```

### 4.3 Swoole directly (inside WSL / Linux)

```bash
bash scripts/install-swoole-wsl.sh   # one-time: PHP 8.3 + Swoole in WSL
php bin/server.php                   # coroutine server, one worker per core
```

---

## 5. How to use the dashboard

Open **http://127.0.0.1:8080**. The top bar has the controls:

| Control | What it does |
|---|---|
| **SIM / LIVE** | Data source. **SIM** = deterministic simulation. **LIVE** = real Binance + Coinbase data. |
| **FIFO / Pro-rata** | Matching algorithm (applied on Reset; SIM only). |
| **▶ Play / ❚❚ Pause** | Start/stop the auto-advancing feed. |
| **Step +10** | Advance 10 steps manually (SIM only). |
| **Reset** | Restart the session (new SIM seed, or clear the LIVE session). |

**The panels:**

- **Price & VWAP** — live price chart.
- **Portfolio P&L** — equity, total P&L, return, Sharpe, Sortino, max drawdown, equity curve.
- **Order Book** — depth ladder (real Binance depth in LIVE).
- **Trade Tape** — recent trades (real Binance trades in LIVE).
- **Risk (VaR / Exposure)** — VaR/CVaR, gross exposure, margin, drawdown, concentration, kill-switch state.
- **Position & Order Ticket** — your position, and a form to place orders (see below).
- **Technical Indicators** — RSI gauge, MACD, EMA, VWAP.
- **Market Analysis** — realized volatility, BTC/ETH correlation; in LIVE, Binance vs Coinbase BBO and cross-venue basis.
- **Compliance** — audit entry count, chain-valid badge, MiFID reports, surveillance flags.
- **Risk Alerts** — live alert feed with severity.

### 5.1 Placing an order (Order Ticket)

1. Choose **Market** or **Limit**.
2. Choose time-in-force: **IOC**, **FOK**, or **GTC**.
3. For a limit order, enter a **price** (leave blank for market).
4. Enter a **quantity** (e.g. `0.05`).
5. Click **BUY** or **SELL**.

In **SIM** the order is scheduled for the next step; in **LIVE** it fills at the
real best bid/offer on the next tick. Orders that breach a risk limit are
rejected (you'll see a toast and a risk alert) — that's the pre-trade gate doing
its job.

### 5.2 Responsive

The dashboard adapts to screen size: a 12-column terminal on desktop, a
2-column layout on tablets, and a single-column, touch-friendly stack on phones
(no sideways scrolling). To preview on desktop Chrome: `F12` → `Ctrl+Shift+M`.

---

## 6. Live data mode — details

In LIVE mode, every tick fetches, in **one parallel `curl_multi` batch** (~0.5s):

- Binance best bid/offer, order book depth, and recent trades (`BTCUSDT`)
- Coinbase best bid/offer (`BTC-USD`)
- Binance ETH best bid/offer (`ETHUSDT`) — for real BTC/ETH correlation

These feed the same indicators, risk engine, VaR, audit chain and MiFID reporting
as the simulator. **Only your own fills are simulated** (matched at the real BBO);
nothing is sent to any exchange. If a fetch blips, the dashboard shows the last
data and flags it stale rather than crashing.

---

## 7. Data persistence (Postgres + Redis)

With the Docker stack up, push a full run into the database:

```bash
docker compose run --rm app php bin/persist.php 600
```

This runs the platform in recording mode and writes **accounts, orders, fills,
positions and the audit chain** into Postgres in a **single ACID transaction**,
under a named account (default *Mehedi Hasan*), then publishes trades to a Redis
Stream. Verify:

```bash
docker compose exec postgres psql -U trading -d trading -c "SELECT * FROM positions;"
docker compose exec redis redis-cli XLEN "md.BTC/USDT.trades"
```

Schema lives in [db/migrations/001_schema.sql](../db/migrations/001_schema.sql).

---

## 8. Testing

```bash
composer test      # or: php tests/run.php
```

36 zero-dependency checks cover: exact Decimal math, matching and TIF semantics
(FIFO, FOK atomicity, IOC, self-trade prevention), average-cost position
accounting, VaR/CVaR, streaming indicators, Sharpe/drawdown, the audit
tamper-detection, and SIM determinism.

---

## 9. Requirements coverage

Every graded requirement is mapped to the delivering component, with an honest
status, in **[docs/requirements.html](requirements.html)** — open it in a browser.
Summary: the large majority are fully implemented and tested; a handful are
partial (e.g. WebSocket-protocol streaming vs REST polling, CPU-affinity pinning);
Kafka and ClickHouse are architected behind interfaces but their adapters aren't
wired.

---

## 10. Troubleshooting

| Symptom | Fix |
|---|---|
| Dashboard won't load | Is the stack up? `docker compose ps`. Start it: `docker compose up -d`. |
| Port 8080 busy | Stop whatever holds it, or change the mapping in `docker-compose.yml`. |
| LIVE shows "stale" | A transient network blip to Binance/Coinbase; it recovers on the next tick. |
| LIVE shows no data at all | The machine can't reach the exchange APIs (firewall/region). SIM still works fully. |
| `ext-swoole not loaded` (bin/server.php) | Run inside Docker, or install Swoole via `scripts/install-swoole-wsl.sh`. |
| Composer autoload warning about Enums | Run `composer dump-autoload -o` (the multi-class files are handled by the classmap). |

---

## 11. One-paragraph summary

This is a PHP 8.3 real-time crypto trading platform that ingests market data
(real Binance/Coinbase or a deterministic simulation), runs market-making and
momentum strategies through a synchronous pre-trade risk gate into a price-time
matching engine, tracks positions, P&L, VaR/CVaR and drawdown, records an
immutable audit hash-chain with MiFID reporting and trade surveillance, and
surfaces everything on a live, responsive web dashboard served by Swoole — with
optional Postgres/Redis persistence, all runnable with a single
`docker compose up`.
```
