<?php

declare(strict_types=1);

namespace TradingPlatform\Engine;

use TradingPlatform\Analysis\Indicators;
use TradingPlatform\Analysis\PatternRecognition;
use TradingPlatform\Analysis\Statistics;
use TradingPlatform\Analytics\ExecutionQuality;
use TradingPlatform\Compliance\AuditLog;
use TradingPlatform\Compliance\MiFIDReporter;
use TradingPlatform\MarketData\Aggregator;
use TradingPlatform\MarketData\AnomalyFilter;
use TradingPlatform\MarketData\ExchangeClient;
use TradingPlatform\MarketData\MarketTick;
use TradingPlatform\Matching\Trade;
use TradingPlatform\Order\Fill;
use TradingPlatform\Order\Liquidity;
use TradingPlatform\Order\Order;
use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Risk\AlertManager;
use TradingPlatform\Risk\AlertSeverity;
use TradingPlatform\Risk\Portfolio;
use TradingPlatform\Risk\RiskEngine;
use TradingPlatform\Risk\RiskLimits;
use TradingPlatform\Support\Decimal;
use TradingPlatform\Support\SystemClock;

/**
 * LIVE trading loop driven by REAL market data from Binance + Coinbase.
 *
 * Every tick pulls live prices, order book and trades, runs the same indicators,
 * risk and analytics as the simulator, and executes our own strategy against the
 * real market. Our orders are matched at the real best bid/offer but are NOT sent
 * to any exchange — no API keys, no real money. So the market is 100% real; only
 * our fills are simulated.
 *
 * Unlike the deterministic simulator, live state is genuinely stateful: it is
 * persisted as a plain array between requests (Indicators/Position/AuditLog each
 * provide toState/fromState) so the running position, P&L and audit chain carry
 * forward across polls under `php -S` or Swoole.
 */
final class LiveEngine
{
    private const SYMBOL = 'BTC/USDT';
    private const ACCOUNT = 'MEHEDI-HASAN';
    private const ANNUALIZE = 15_768_000.0; // ~2s cadence → periods/year, for vol display
    private const HIST = 240;

    public function __construct(
        private readonly ExchangeClient $client,
        private readonly float $startingCash = 250_000.0,
    ) {}

    /**
     * Advance one real-time tick and return the dashboard snapshot.
     *
     * @param array<string,mixed> $state prior persisted state ([] on first tick)
     * @param list<array<string,mixed>> $pending manual orders to execute this tick
     * @return array{state:array<string,mixed>,snapshot:array<string,mixed>}
     */
    public function tick(array $state, array $pending = []): array
    {
        $clock = new SystemClock();
        $t0 = hrtime(true);

        // ---- 1. Pull REAL market data (one parallel batch) ----------------
        $market = $this->client->fetchMarket('BTCUSDT', 'BTC-USD', 'ETHUSDT');
        $bbo = $market['binance'];
        $depth = $market['depth'];
        $trades = $market['trades'];
        $cb = $market['coinbase'];
        $ethBbo = $market['eth'];
        $fetchMs = (hrtime(true) - $t0) / 1e6;

        $state = $this->hydrate($state);

        if ($bbo === null) {
            // Network hiccup: report last known state, flag stale, do not advance.
            $snap = $this->snapshot($state, $depth, $trades, $cb, $ethBbo, $fetchMs, true);

            return ['state' => $state, 'snapshot' => $snap];
        }

        $bid = $bbo['bid'];
        $ask = $bbo['ask'];
        // Prefer the WebSocket-fed BBO (bin/ws_ingest.php → Redis) when it is
        // running and fresh — true streaming ingestion instead of REST polling.
        $source = 'REST';
        $ws = $this->wsBbo();
        if ($ws !== null) {
            $bid = $ws['bid'];
            $ask = $ws['ask'];
            $source = 'WEBSOCKET';
        }
        $state['dataSource'] = $source;
        $mid = ($bid + $ask) / 2;
        $last = isset($trades[0]) ? (float) $trades[0]['price'] : $mid;
        $vol = 0.0;
        foreach ($trades as $t) {
            $vol += (float) $t['qty'];
        }

        // ---- 2. Anomaly filter (structural + jump vs last) ----------------
        $tick = new MarketTick(self::SYMBOL, 'BINANCE', Decimal::of($bid), Decimal::of($ask), Decimal::of($last), Decimal::of($vol), $clock->nowMillis(), $clock->nowMillis());
        $filter = new AnomalyFilter();
        $inspect = $filter->inspect($tick);

        // ---- 3. Multi-exchange aggregation (Binance + Coinbase) -----------
        $agg = new Aggregator();
        $agg->update($tick);
        if ($cb !== null) {
            $agg->update(new MarketTick(self::SYMBOL, 'COINBASE', Decimal::of($cb['bid']), Decimal::of($cb['ask']), Decimal::of(($cb['bid'] + $cb['ask']) / 2), Decimal::zero(), $clock->nowMillis(), $clock->nowMillis()));
        }
        $arb = $agg->arbitrage();

        // ---- 4. Indicators on the real price ------------------------------
        $ind = Indicators::fromState($state['indicators']);
        if ($inspect['ok']) {
            $ind->update($last, max(0.0001, $vol));
        }
        $state['indicators'] = $ind->toState();

        // Price-pattern recognition on the real price.
        $pr = PatternRecognition::fromState($state['pattern'] ?? []);
        if ($inspect['ok']) {
            $pr->update($last);
        }
        $state['pattern'] = $pr->toState();
        $execq = ExecutionQuality::fromState($state['execq'] ?? []);

        $state['ticks']++;
        $state['priceHistory'][] = round($last, 2);
        $ethMid = $ethBbo !== null ? ($ethBbo['bid'] + $ethBbo['ask']) / 2 : ($state['ethHistory'] ? end($state['ethHistory']) : 0.0);
        $state['ethHistory'][] = round($ethMid, 2);
        $state['priceHistory'] = array_slice($state['priceHistory'], -self::HIST);
        $state['ethHistory'] = array_slice($state['ethHistory'], -self::HIST);

        // ---- 5. Rebuild portfolio + risk from persisted state -------------
        $portfolio = new Portfolio(self::ACCOUNT, Decimal::of((string) $state['cash']), Decimal::of($this->startingCash));
        $pos = $portfolio->position(self::SYMBOL);
        $pos->qty = Decimal::of((string) $state['position']['qty']);
        $pos->avgPrice = Decimal::of((string) $state['position']['avgPrice']);
        $pos->realizedPnl = Decimal::of((string) $state['position']['realizedPnl']);

        $alerts = new AlertManager();
        $risk = new RiskEngine(new RiskLimits(), $alerts, $this->startingCash);
        $risk->restoreState((float) $state['highWater'], (bool) $state['killSwitch']);

        $audit = AuditLog::fromState($state['audit']);
        $mifid = new MiFIDReporter($audit);

        $marks = [self::SYMBOL => Decimal::of($last)];

        // ---- 6. Strategy decision + manual orders → simulated fills -------
        $intents = $pending;
        $auto = $this->strategyIntent($ind->snapshot(), $pos->qty->toFloat());
        if ($auto !== null) {
            $intents[] = $auto;
        }

        foreach ($intents as $intent) {
            $this->executeIntent($intent, $portfolio, $risk, $audit, $mifid, $alerts, $marks, $bid, $ask, $last, $clock->nowMillis(), $state, $execq);
        }
        $state['execq'] = $execq->toState();

        // ---- 7. Mark-to-market + P&L --------------------------------------
        $equity = $portfolio->equity($marks)->toFloat();
        $prevEquity = $state['equityCurve'] ? (float) end($state['equityCurve']) : $this->startingCash;
        $state['stepPnl'][] = $equity - $prevEquity;
        $state['equityCurve'][] = round($equity, 2);
        $state['stepPnl'] = array_slice($state['stepPnl'], -self::HIST);
        $state['equityCurve'] = array_slice($state['equityCurve'], -self::HIST);

        // ---- 8. Portfolio risk + drawdown kill-switch ---------------------
        $assess = $risk->assess($portfolio, $marks, $state['stepPnl'], $clock->nowMillis());
        $state['lastRisk'] = $assess;

        // Persist mutated objects back into state.
        $state['cash'] = (string) $portfolio->cash;
        $state['position'] = $portfolio->position(self::SYMBOL)->toState();
        $state['audit'] = $audit->toState();
        $rs = $risk->toState();
        $state['highWater'] = $rs['highWater'];
        $state['killSwitch'] = $rs['killSwitch'];
        foreach ($alerts->recent(10) as $a) {
            $state['alerts'][] = $a;
        }
        $state['alerts'] = array_slice($state['alerts'], -20);

        // Real trade tape from Binance.
        $state['tape'] = array_map(static fn (array $t): array => ['price' => $t['price'], 'qty' => $t['qty'], 'side' => $t['side'], 'ts' => $t['ts']], array_slice($trades, 0, 15));
        $state['anomaly'] = $inspect;

        $snap = $this->snapshot($state, $depth, $trades, $cb, $ethBbo, $fetchMs, false, $arb, $bbo);

        return ['state' => $state, 'snapshot' => $snap];
    }

    /**
     * Read the WebSocket-fed BBO from Redis if the ingest process is running and
     * the quote is fresh (< 5s). Returns null otherwise (fall back to REST).
     *
     * @return array{bid:float,ask:float}|null
     */
    private function wsBbo(): ?array
    {
        if (!extension_loaded('redis')) {
            return null;
        }
        $host = getenv('REDIS_HOST') ?: null;
        if ($host === null) {
            return null;
        }
        try {
            $r = new \Redis();
            if (!@$r->connect($host, 6379, 0.3)) {
                return null;
            }
            $h = $r->hGetAll('ws:bbo:BTCUSDT');
            $r->close();
            if (!is_array($h) || !isset($h['bid'], $h['ask'], $h['ts'])) {
                return null;
            }
            if ((int) (microtime(true) * 1000) - (int) $h['ts'] > 5000) {
                return null; // stale — the ingest process isn't keeping up
            }

            return ['bid' => (float) $h['bid'], 'ask' => (float) $h['ask']];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Decide an auto-strategy intent from indicators + current position. */
    private function strategyIntent(array $ind, float $pos): ?array
    {
        $rsi = $ind['rsi'] ?? null;
        $hist = $ind['macdHist'] ?? null;
        if ($rsi === null || $hist === null) {
            return null; // still warming up on real data
        }
        $clip = 0.02;       // 0.02 BTC per signal (~$1.2k notional)
        $maxPos = 0.12;
        if ($hist > 0 && $rsi < 68 && $pos < $maxPos) {
            return ['side' => 'BUY', 'type' => 'MARKET', 'tif' => 'IOC', 'price' => null, 'qty' => $clip, 'strategy' => 'MOMENTUM'];
        }
        if ($hist < 0 && $rsi > 32 && $pos > -$maxPos) {
            return ['side' => 'SELL', 'type' => 'MARKET', 'tif' => 'IOC', 'price' => null, 'qty' => $clip, 'strategy' => 'MOMENTUM'];
        }
        if (abs($hist) < 1e-9 && abs($pos) > 1e-9) {
            return ['side' => $pos > 0 ? 'SELL' : 'BUY', 'type' => 'MARKET', 'tif' => 'IOC', 'price' => null, 'qty' => min(abs($pos), $clip), 'strategy' => 'MOMENTUM'];
        }

        return null;
    }

    /**
     * Run one order through the pre-trade risk gate, then simulate a fill at the
     * REAL best bid/offer. Nothing is sent to any exchange.
     *
     * @param array<string,mixed> $intent
     * @param array<string,Decimal> $marks
     * @param array<string,mixed> $state
     */
    private function executeIntent(array $intent, Portfolio $portfolio, RiskEngine $risk, AuditLog $audit, MiFIDReporter $mifid, AlertManager $alerts, array $marks, float $bid, float $ask, float $last, int $tsMs, array &$state, ExecutionQuality $execq): void
    {
        $side = Side::from((string) $intent['side']);
        $type = OrderType::from((string) ($intent['type'] ?? 'MARKET'));
        $tif = TimeInForce::from((string) ($intent['tif'] ?? 'IOC'));
        $qty = Decimal::of((float) $intent['qty']);
        $limit = isset($intent['price']) && $intent['price'] !== null ? Decimal::of((float) $intent['price']) : null;
        $strategy = (string) ($intent['strategy'] ?? 'MANUAL');

        $order = new Order('LIVE-'.$state['ticks'].'-'.mt_rand(1000, 9999), self::ACCOUNT, self::SYMBOL, $side, $type, $tif, $limit, $qty, $tsMs * 1_000_000, 0, $strategy);

        $decision = $risk->preTradeCheck($order, $portfolio, $marks, $tsMs);
        if (!$decision->approved) {
            $state['rejected']++;
            $audit->append('ORDER_REJECTED', ['order' => $order->id, 'strategy' => $strategy, 'reason' => $decision->reason()], $tsMs);

            return;
        }
        $audit->append('ORDER_ACCEPTED', ['order' => $order->id, 'strategy' => $strategy, 'side' => $side->value, 'qty' => $qty->toFloat()], $tsMs);

        // Determine the executable price at the real book.
        $execPrice = $side === Side::Buy ? $ask : $bid;
        if ($type === OrderType::Limit && $limit !== null) {
            $crosses = $side === Side::Buy ? $limit->toFloat() >= $ask : $limit->toFloat() <= $bid;
            if (!$crosses) {
                // Resting limit that doesn't cross — for the live demo we treat
                // non-marketable manual limits as not-yet-filled.
                $audit->append('ORDER_RESTING', ['order' => $order->id, 'price' => $limit->toFloat()], $tsMs);

                return;
            }
            $execPrice = $limit->toFloat();
        }

        $fill = new Fill('T-'.$order->id, $order->id, self::ACCOUNT, self::SYMBOL, $side, Decimal::of($execPrice), $qty, Liquidity::Taker, $tsMs * 1_000_000, $strategy);
        $pos = $portfolio->position(self::SYMBOL);
        $realizedBefore = $pos->realizedPnl;
        $portfolio->applyFill($fill);
        $realizedDelta = $pos->realizedPnl->sub($realizedBefore)->toFloat();

        // Execution-quality: slippage vs the arrival mid, fill rate, maker/taker.
        $refMid = ($bid + $ask) / 2;
        $execq->record($side->value, $refMid, $execPrice, $qty->toFloat(), $qty->toFloat(), Liquidity::Taker->value);

        // Attribution (kept in state).
        $b = $state['attribution'][$strategy] ?? ['realized' => 0.0, 'notional' => 0.0, 'fills' => 0, 'volume' => 0.0];
        $b['realized'] += $realizedDelta;
        $b['notional'] += $fill->notional()->toFloat();
        $b['volume'] += $qty->toFloat();
        $b['fills']++;
        $state['attribution'][$strategy] = $b;
        $state['tradeCount']++;

        // MiFID II transaction report → audit chain.
        $mifid->reportTrade(new Trade($fill->tradeId, self::SYMBOL, $fill->price, $qty, $order->id, $order->id, $side, $tsMs * 1_000_000), $order, $tsMs);
    }

    /** Initialise a fresh live state on the first tick. */
    private function hydrate(array $s): array
    {
        if (isset($s['ticks'])) {
            $s['priceHistory'] ??= [];
            $s['ethHistory'] ??= [];
            $s['equityCurve'] ??= [];
            $s['stepPnl'] ??= [];
            $s['alerts'] ??= [];
            $s['tape'] ??= [];
            $s['attribution'] ??= [];
            $s['pattern'] ??= (new PatternRecognition())->toState();
            $s['execq'] ??= (new ExecutionQuality())->toState();

            return $s;
        }

        return [
            'ticks' => 0,
            'startedMs' => (new SystemClock())->nowMillis(),
            'cash' => (string) $this->startingCash,
            'position' => ['qty' => '0', 'avgPrice' => '0', 'realizedPnl' => '0'],
            'indicators' => (new Indicators())->toState(),
            'priceHistory' => [], 'ethHistory' => [], 'equityCurve' => [], 'stepPnl' => [],
            'audit' => (new AuditLog())->toState(),
            'highWater' => $this->startingCash, 'killSwitch' => false,
            'alerts' => [], 'tape' => [], 'attribution' => [],
            'pattern' => (new PatternRecognition())->toState(),
            'execq' => (new ExecutionQuality())->toState(),
            'tradeCount' => 0, 'rejected' => 0,
            'lastRisk' => [],
        ];
    }

    /**
     * Build the dashboard snapshot (same shape as the simulator, plus real venue
     * data).
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function snapshot(array $state, ?array $depth, array $trades, ?array $cb, ?array $ethBbo, float $fetchMs, bool $stale, ?array $arb = null, ?array $bbo = null): array
    {
        $prices = $state['priceHistory'];
        $eth = $state['ethHistory'];
        $last = $prices ? (float) end($prices) : 0.0;
        $btcRet = Statistics::returns($prices);
        $ethRet = Statistics::returns($eth);

        // Real order book from Binance depth.
        $book = ['bids' => [], 'asks' => []];
        if ($depth !== null) {
            $book['bids'] = array_map(static fn (array $l): array => ['price' => $l['price'], 'qty' => $l['qty'], 'orders' => 1], array_slice($depth['bids'], 0, 8));
            $book['asks'] = array_map(static fn (array $l): array => ['price' => $l['price'], 'qty' => $l['qty'], 'orders' => 1], array_slice($depth['asks'], 0, 8));
        }
        $bid = $bbo['bid'] ?? ($book['bids'][0]['price'] ?? null);
        $ask = $bbo['ask'] ?? ($book['asks'][0]['price'] ?? null);

        $equity = $state['equityCurve'] ? (float) end($state['equityCurve']) : $this->startingCash;
        $returns = Statistics::returns($state['equityCurve']);
        $sharpe = \TradingPlatform\Analytics\Metrics::sharpe($returns);
        $sortino = \TradingPlatform\Analytics\Metrics::sortino($returns);
        $maxdd = \TradingPlatform\Analytics\Metrics::maxDrawdown($state['equityCurve'] ?: [$this->startingCash]);

        $posQty = (float) $state['position']['qty'];
        $posAvg = (float) $state['position']['avgPrice'];
        $unreal = $posQty * ($last - $posAvg);

        $risk = $state['lastRisk'] ?: ['var95' => 0, 'cvar95' => 0, 'var99' => 0, 'grossExposure' => abs($posQty) * $last, 'drawdown' => 0, 'concentration' => ['symbol' => self::SYMBOL, 'share' => 1.0], 'initialMargin' => 0, 'maintenanceMargin' => 0, 'killSwitch' => false];
        $auditEntries = $state['audit']['entries'] ?? [];
        $auditHead = (string) ($state['audit']['head'] ?? '');

        $ethLast = $eth ? (float) end($eth) : 0.0;
        $cbBid = $cb['bid'] ?? null;
        $cbAsk = $cb['ask'] ?? null;

        return [
            'mode' => 'LIVE',
            'stale' => $stale,
            'step' => $state['ticks'],
            'symbol' => self::SYMBOL,
            'clockMs' => (new SystemClock())->nowMillis(),
            'fairValue' => round($cb && $bid ? ($bid + ($cbBid ?? $bid)) / 2 : (float) $last, 2),
            'lastPrice' => round($last, 2),
            'bbo' => ['bid' => $bid, 'ask' => $ask, 'spread' => ($bid && $ask) ? round($ask - $bid, 2) : null],
            'book' => $book,
            'priceHistory' => $prices,
            'equityCurve' => $state['equityCurve'],
            'pnl' => [
                'equity' => $equity,
                'totalPnl' => $equity - $this->startingCash,
                'totalReturnPct' => ($equity / $this->startingCash - 1) * 100,
                'sharpe' => $sharpe, 'sortino' => $sortino, 'maxDrawdown' => $maxdd,
            ],
            'position' => [
                'symbol' => self::SYMBOL, 'qty' => $posQty, 'avgPrice' => $posAvg, 'mark' => $last,
                'realizedPnl' => (float) $state['position']['realizedPnl'], 'unrealizedPnl' => $unreal,
                'exposure' => abs($posQty) * $last,
            ],
            'indicators' => Indicators::fromState($state['indicators'])->snapshot(),
            'stats' => [
                'correlation' => round(Statistics::correlation($btcRet, $ethRet), 3),
                'volBtcPct' => round(Statistics::realizedVolatility($prices, self::ANNUALIZE) * 100, 2),
                'volEthPct' => round(Statistics::realizedVolatility($eth, self::ANNUALIZE) * 100, 2),
                'ethPrice' => round($ethLast, 2),
            ],
            'venues' => [
                'binance' => ['bid' => $bid, 'ask' => $ask],
                'coinbase' => ['bid' => $cbBid, 'ask' => $cbAsk],
                'basis' => ($bid && $cbBid) ? round($bid - $cbBid, 2) : null,
                'arbitrage' => $arb,
            ],
            'patterns' => PatternRecognition::fromState($state['pattern'] ?? [])->detect(),
            'risk' => array_merge($risk, ['limits' => (new RiskLimits())->toArray()]),
            'compliance' => [
                'auditCount' => count($auditEntries),
                'auditHead' => substr($auditHead, 0, 16),
                'auditValid' => AuditLog::fromState($state['audit'])->verify()['valid'],
                'mifidReports' => $this->countAudit($auditEntries, 'MIFID_TXN_REPORT'),
                'surveillanceFlags' => 0,
                'flags' => [],
            ],
            'perf' => [
                'latency' => ['count' => $state['ticks'], 'meanUs' => round($fetchMs * 1000, 1), 'p50Us' => round($fetchMs * 1000, 1), 'p95Us' => round($fetchMs * 1000, 1), 'p99Us' => round($fetchMs * 1000, 1), 'maxUs' => round($fetchMs * 1000, 1), 'budgetUs' => 500000, 'overBudget' => false],
                'objectPool' => ['created' => 0, 'reused' => 0, 'free' => 0, 'reuseRate' => 1.0],
                'ringDropped' => 0,
                'dataLatencyMs' => round($fetchMs, 1),
                'execQuality' => ExecutionQuality::fromState($state['execq'] ?? [])->summary(),
            ],
            'attribution' => $state['attribution'],
            'tape' => array_map(static fn (array $t): array => ['price' => $t['price'], 'qty' => $t['qty'], 'side' => $t['side'], 'ts' => $t['ts']], array_slice($state['tape'], 0, 15)),
            'alerts' => array_reverse(array_slice($state['alerts'], -8)),
            'totals' => ['trades' => $state['tradeCount'], 'volume' => round(array_sum(array_map(static fn (array $t): float => (float) $t['qty'], $trades)), 3), 'rejected' => $state['rejected'], 'algorithm' => 'LIVE · '.($state['dataSource'] ?? 'REST').' · Binance+Coinbase'],
            'audit' => array_map(static fn (array $e): array => ['seq' => $e['seq'], 'type' => $e['type'], 'hash' => substr($e['hash'], 0, 12)], array_slice($auditEntries, -8)),
        ];
    }

    /** @param list<array<string,mixed>> $entries */
    private function countAudit(array $entries, string $type): int
    {
        $n = 0;
        foreach ($entries as $e) {
            if (($e['type'] ?? '') === $type) {
                $n++;
            }
        }

        return $n;
    }
}
