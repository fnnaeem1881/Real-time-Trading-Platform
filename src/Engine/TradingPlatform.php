<?php

declare(strict_types=1);

namespace TradingPlatform\Engine;

use TradingPlatform\Analysis\Indicators;
use TradingPlatform\Analysis\Statistics;
use TradingPlatform\Analytics\Attribution;
use TradingPlatform\Analytics\Metrics;
use TradingPlatform\Analytics\PnL;
use TradingPlatform\Compliance\AuditLog;
use TradingPlatform\Compliance\MiFIDReporter;
use TradingPlatform\Compliance\Surveillance;
use TradingPlatform\Matching\MatchingAlgorithm;
use TradingPlatform\Matching\MatchingEngine;
use TradingPlatform\Matching\OrderBook;
use TradingPlatform\Order\Order;
use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Perf\LatencyMonitor;
use TradingPlatform\Perf\MarketEvent;
use TradingPlatform\Perf\ObjectPool;
use TradingPlatform\Perf\RingBuffer;
use TradingPlatform\Risk\AlertManager;
use TradingPlatform\Risk\Portfolio;
use TradingPlatform\Risk\RiskEngine;
use TradingPlatform\Risk\RiskLimits;
use TradingPlatform\Risk\StopLoss;
use TradingPlatform\Strategy\MarketMakerStrategy;
use TradingPlatform\Strategy\MomentumStrategy;
use TradingPlatform\Strategy\OrderIntent;
use TradingPlatform\Strategy\Strategy;
use TradingPlatform\Strategy\StrategyContext;
use TradingPlatform\Support\Decimal;
use TradingPlatform\Support\DeterministicRandom;
use TradingPlatform\Support\Ids;
use TradingPlatform\Support\VirtualClock;

/**
 * End-to-end orchestrator that wires every milestone together and drives a
 * deterministic market simulation:
 *
 *   market data (synthetic feed) → indicators (M1)
 *     → strategies (M2) → pre-trade risk gate (M3) → matching engine (M2)
 *       → post-trade: positions, P&L, portfolio risk, VaR (M3/M4)
 *         → compliance: audit chain, surveillance, MiFID (M3)
 *           → analytics + latency monitoring (M4)
 *
 * Determinism: the entire run is a pure function of (seed, steps, manualOrders),
 * so the web layer can persist just those three values and rebuild identical
 * state on any request. The book is rebuilt fresh each step, which bounds state
 * and models a discrete auction cleanly.
 */
final class TradingPlatform
{
    public const SYMBOL = 'BTC/USDT';
    public const HEDGE_SYMBOL = 'ETH/USDT';

    private VirtualClock $clock;
    private DeterministicRandom $rng;
    private Ids $ids;

    private Portfolio $house;
    private RiskEngine $risk;
    private AlertManager $alerts;
    private StopLoss $stops;
    private Indicators $indicators;
    private AuditLog $audit;
    private Surveillance $surveillance;
    private MiFIDReporter $mifid;
    private Attribution $attribution;
    private PnL $pnl;
    private LatencyMonitor $latency;
    private RingBuffer $eventBus;
    private ObjectPool $eventPool;

    /** @var array<string,Strategy> account/strategy name => strategy */
    private array $strategies;

    private int $step = 0;
    private float $fairValue;
    private float $hedgeFair;
    private float $lastPrice;

    // Rolling history for charts / stats.
    /** @var list<float> */
    private array $priceHistory = [];
    /** @var list<float> */
    private array $hedgeHistory = [];
    /** @var list<array<string,mixed>> */
    private array $tape = [];
    /** @var array{bids:list<array{price:float,qty:float,orders:int}>,asks:list<array{price:float,qty:float,orders:int}>} */
    private array $lastDepth = ['bids' => [], 'asks' => []];
    private ?Decimal $lastBid = null;
    private ?Decimal $lastAsk = null;
    /** @var array<string,mixed> */
    private array $lastRisk = [];
    private int $rejectedOrders = 0;
    private float $tradedVolume = 0.0;
    private int $tradeCount = 0;

    /** @var array<string,string> orderId => accountId, for surveillance self-match checks */
    private array $orderAccounts = [];

    /** @var list<array{step:int,side:string,type:string,tif:string,price:?float,qty:float}> */
    private array $manualOrders;

    private MatchingAlgorithm $algorithm;

    /** Account id under which our (the trader's) orders are booked. */
    private string $houseAccount = 'HOUSE';
    private string $houseName = 'House Trading';
    /** When true, retain every house order/fill for persistence. */
    private bool $record = false;
    /** @var list<Order> */
    private array $recordedOrders = [];
    /** @var list<\TradingPlatform\Order\Fill> */
    private array $recordedFills = [];

    /**
     * @param array{startingCash?:float,sigma?:float,algorithm?:string,manualOrders?:list<array<string,mixed>>,houseAccount?:string,houseName?:string,record?:bool} $opts
     */
    public function __construct(
        private readonly int $seed = 42,
        array $opts = [],
    ) {
        $startingCash = (float) ($opts['startingCash'] ?? 250_000.0);
        $this->sigma = (float) ($opts['sigma'] ?? 0.0009);
        $this->algorithm = MatchingAlgorithm::tryFrom((string) ($opts['algorithm'] ?? 'FIFO')) ?? MatchingAlgorithm::Fifo;
        $this->houseAccount = (string) ($opts['houseAccount'] ?? 'HOUSE');
        $this->houseName = (string) ($opts['houseName'] ?? 'House Trading');
        $this->record = (bool) ($opts['record'] ?? false);
        /** @var list<array{step:int,side:string,type:string,tif:string,price:?float,qty:float}> $mo */
        $mo = $opts['manualOrders'] ?? [];
        $this->manualOrders = $mo;

        $this->clock = new VirtualClock();
        $this->rng = new DeterministicRandom($seed);
        $this->ids = new Ids();

        $this->house = Portfolio::open($this->houseAccount, Decimal::of($startingCash));
        $this->alerts = new AlertManager();
        $this->risk = new RiskEngine(new RiskLimits(), $this->alerts, $startingCash);
        $this->stops = new StopLoss();
        $this->indicators = new Indicators();
        $this->audit = new AuditLog();
        $this->surveillance = new Surveillance();
        $this->mifid = new MiFIDReporter($this->audit);
        $this->attribution = new Attribution();
        $this->pnl = new PnL($startingCash);
        $this->latency = new LatencyMonitor();
        $this->eventBus = new RingBuffer(1024);
        $this->eventPool = new ObjectPool(
            static fn (): MarketEvent => new MarketEvent(),
            static fn (MarketEvent $e): mixed => $e->reset(),
            2048,
        );

        $this->strategies = [
            'MARKET_MAKER' => new MarketMakerStrategy(),
            'MOMENTUM' => new MomentumStrategy(),
        ];

        $this->fairValue = 64_000.0;
        $this->hedgeFair = 3_200.0;
        $this->lastPrice = $this->fairValue;

        $this->audit->append('SYSTEM_START', ['seed' => $seed, 'symbol' => self::SYMBOL, 'startingCash' => $startingCash], $this->clock->nowMillis());
    }

    private float $sigma = 0.0009;

    /** Advance the simulation to an absolute target step. */
    public function runTo(int $targetStep): void
    {
        $targetStep = max(0, min($targetStep, 5000)); // hard cap for the demo
        while ($this->step < $targetStep) {
            $this->step();
        }
    }

    /** One discrete market step. */
    private function step(): void
    {
        $this->step++;
        $this->clock->advanceMillis(250);

        // --- M1: evolve the synthetic fair value (GBM) and a correlated hedge asset ---
        $z = $this->rng->gaussian();
        $this->fairValue *= exp($this->sigma * $z - 0.5 * $this->sigma ** 2);
        // ETH partially correlated to BTC (shared shock + idiosyncratic).
        $zh = 0.7 * $z + 0.7141 * $this->rng->gaussian();
        $this->hedgeFair *= exp($this->sigma * 1.15 * $zh - 0.5 * ($this->sigma * 1.15) ** 2);

        // Fresh book per step (bounded state, discrete auction).
        $book = new OrderBook(self::SYMBOL);
        $engine = new MatchingEngine($book, $this->ids, $this->clock, $this->algorithm);

        // --- External liquidity: a resting ladder around fair value ---
        $this->postLiquidityLadder($engine);

        // --- M2: our market maker quotes (through the OMS risk gate) ---
        $mid = $book->midPrice()?->toFloat() ?? $this->fairValue;
        $this->runStrategy($engine, 'MARKET_MAKER', $mid);

        // --- External order flow: takers that move the market ---
        $tradesThisStep = $this->generateOrderFlow($engine);

        // --- M2: momentum strategy reacts to indicators (marketable IOC) ---
        $tradesThisStep += $this->runStrategy($engine, 'MOMENTUM', $mid);

        // --- Manual orders scheduled for this step ---
        $this->processManualOrders($engine);

        // --- Update indicators from the last traded price + this step's volume ---
        $this->indicators->update($this->lastPrice, max(0.0001, $this->stepVolume));

        // --- Marks & P&L (M3/M4) ---
        $marks = [
            self::SYMBOL => Decimal::of($this->lastPrice),
            self::HEDGE_SYMBOL => Decimal::of($this->hedgeFair),
        ];
        $equity = $this->house->equity($marks)->toFloat();
        $this->pnl->record($equity);

        // --- Portfolio risk assessment + drawdown kill-switch (M3) ---
        $this->lastRisk = $this->risk->assess($this->house, $marks, $this->pnl->stepPnl(), $this->clock->nowMillis());

        // --- Stop-loss evaluation (M3) ---
        $triggered = $this->stops->evaluate('HOUSE', $marks);
        foreach ($triggered as $t) {
            $this->audit->append('STOP_LOSS_TRIGGER', $t, $this->clock->nowMillis());
        }

        // --- Snapshot book depth + BBO for display ---
        $this->lastDepth = $book->depth(8);
        $this->lastBid = $book->bestBid();
        $this->lastAsk = $book->bestAsk();

        // --- History bookkeeping ---
        $this->priceHistory[] = $this->lastPrice;
        $this->hedgeHistory[] = $this->hedgeFair;
        if (count($this->priceHistory) > 240) {
            array_shift($this->priceHistory);
            array_shift($this->hedgeHistory);
        }

        $this->tradeCount += $tradesThisStep;
        $this->stepVolume = 0.0;
    }

    private float $stepVolume = 0.0;

    /** Post a symmetric limit-order ladder from external liquidity providers. */
    private function postLiquidityLadder(MatchingEngine $engine): void
    {
        $levels = 6;
        $lpHalfSpreadBps = 16.0;
        $half = $this->fairValue * $lpHalfSpreadBps / 10_000.0;
        for ($i = 1; $i <= $levels; $i++) {
            $offset = $half * $i;
            $bidPx = $this->fairValue - $offset;
            $askPx = $this->fairValue + $offset;
            $size = 0.25 + $this->rng->uniform(0.0, 0.6);
            $this->submitExternal($engine, 'EXT_LP', Side::Buy, OrderType::Limit, TimeInForce::GTC, Decimal::of($bidPx), Decimal::of($size));
            $this->submitExternal($engine, 'EXT_LP', Side::Sell, OrderType::Limit, TimeInForce::GTC, Decimal::of($askPx), Decimal::of($size));
        }
    }

    /** Generate external market orders biased toward the fair-value drift. */
    private function generateOrderFlow(MatchingEngine $engine): int
    {
        $trades = 0;
        $bursts = 1 + (int) floor($this->rng->uniform(0.0, 3.0));
        $mid = ($this->lastBid && $this->lastAsk) ? $this->lastBid->add($this->lastAsk)->div(Decimal::of(2))->toFloat() : $this->fairValue;
        $upBias = $this->fairValue >= $mid ? 0.58 : 0.42;

        for ($i = 0; $i < $bursts; $i++) {
            $side = $this->rng->nextFloat() < $upBias ? Side::Buy : Side::Sell;
            $size = 0.1 + $this->rng->uniform(0.0, 0.7);
            $res = $this->submitExternal($engine, 'EXT_FLOW', $side, OrderType::Market, TimeInForce::IOC, null, Decimal::of($size));
            $trades += count($res['trades']);
        }

        return $trades;
    }

    /**
     * Run one strategy: build context, get intents, push each through the OMS.
     *
     * @return int trades generated
     */
    private function runStrategy(MatchingEngine $engine, string $name, float $mid): int
    {
        $strategy = $this->strategies[$name];
        $ctx = new StrategyContext(
            symbol: self::SYMBOL,
            bestBid: $engine->book->bestBid(),
            bestAsk: $engine->book->bestAsk(),
            mid: $engine->book->midPrice() ?? Decimal::of($this->fairValue),
            indicators: $this->indicators->snapshot(),
            position: $this->house->position(self::SYMBOL)->qty,
            step: $this->step,
        );

        $trades = 0;
        foreach ($strategy->onTick($ctx) as $intent) {
            $res = $this->submitHouse($engine, $intent, $name);
            $trades += count($res['trades'] ?? []);
        }

        return $trades;
    }

    /** @return array<string,mixed> */
    private function processManualOrders(MatchingEngine $engine): array
    {
        $out = ['trades' => []];
        foreach ($this->manualOrders as $mo) {
            if ((int) $mo['step'] !== $this->step) {
                continue;
            }
            $intent = new OrderIntent(
                Side::from($mo['side']),
                OrderType::from($mo['type']),
                TimeInForce::from($mo['tif']),
                isset($mo['price']) && $mo['price'] !== null ? Decimal::of((float) $mo['price']) : null,
                Decimal::of((float) $mo['qty']),
            );
            $this->submitHouse($engine, $intent, 'MANUAL');
        }

        return $out;
    }

    /**
     * Order Management System entrypoint for HOUSE orders: pre-trade risk gate,
     * then matching, then post-trade processing. Rejections never reach the book.
     *
     * @return array<string,mixed>
     */
    private function submitHouse(MatchingEngine $engine, OrderIntent $intent, string $strategy): array
    {
        $order = new Order(
            id: $this->ids->nextOrderId(),
            accountId: $this->houseAccount,
            symbol: self::SYMBOL,
            side: $intent->side,
            type: $intent->type,
            tif: $intent->tif,
            price: $intent->price,
            qty: $intent->qty,
            createdAtNanos: $this->clock->nowNanos(),
            seq: $this->ids->next(),
            strategy: $strategy,
        );
        $this->orderAccounts[$order->id] = $this->houseAccount;
        $this->surveillance->onOrderPlaced($order);
        if ($this->record) {
            $this->recordedOrders[] = $order;
        }

        $marks = [self::SYMBOL => Decimal::of($this->lastPrice)];
        $decision = $this->risk->preTradeCheck($order, $this->house, $marks, $this->clock->nowMillis());
        if (!$decision->approved) {
            $order->reject();
            $this->rejectedOrders++;
            $this->audit->append('ORDER_REJECTED', ['order' => $order->id, 'strategy' => $strategy, 'reason' => $decision->reason()], $this->clock->nowMillis());

            return ['order' => $order, 'trades' => [], 'fills' => []];
        }

        $this->audit->append('ORDER_ACCEPTED', ['order' => $order->id, 'strategy' => $strategy, 'side' => $order->side->value, 'qty' => $order->qty->toFloat(), 'price' => $order->price?->toFloat()], $this->clock->nowMillis());

        // Time the matching hot path.
        $result = $this->latency->time(fn (): array => $engine->submit($order));
        $this->processResult($result);

        return $result;
    }

    /** @return array<string,mixed> */
    private function submitExternal(MatchingEngine $engine, string $account, Side $side, OrderType $type, TimeInForce $tif, ?Decimal $price, Decimal $qty): array
    {
        $order = new Order(
            id: $this->ids->nextOrderId(),
            accountId: $account,
            symbol: self::SYMBOL,
            side: $side,
            type: $type,
            tif: $tif,
            price: $price,
            qty: $qty,
            createdAtNanos: $this->clock->nowNanos(),
            seq: $this->ids->next(),
            strategy: null,
        );
        $this->orderAccounts[$order->id] = $account;

        $result = $engine->submit($order);
        $this->processResult($result);

        return $result;
    }

    /**
     * Post-trade processing shared by house + external orders: publish events,
     * update house positions/attribution, run compliance, update last price.
     *
     * @param array<string,mixed> $result
     */
    private function processResult(array $result): void
    {
        /** @var list<\TradingPlatform\Matching\Trade> $trades */
        $trades = $result['trades'] ?? [];
        /** @var list<\TradingPlatform\Order\Fill> $fills */
        $fills = $result['fills'] ?? [];

        foreach ($trades as $trade) {
            $this->lastPrice = $trade->price->toFloat();
            $this->stepVolume += $trade->qty->toFloat();
            $this->tradedVolume += $trade->qty->toFloat();

            // Publish through the pooled ring-buffer event bus (M4 hot-path demo).
            $ev = $this->eventPool->acquire();
            /** @var MarketEvent $ev */
            $ev->type = 'TRADE';
            $ev->symbol = $trade->symbol;
            $ev->price = $trade->price->toFloat();
            $ev->qty = $trade->qty->toFloat();
            $ev->side = $trade->takerSide->value;
            $this->eventBus->push($ev);

            // Surveillance: self-match / wash-trade detection.
            $this->surveillance->onTrade($trade, $this->orderAccounts, $this->clock->nowMillis());

            $this->tape[] = [
                'ts' => $trade->tsNanos,
                'price' => $trade->price->toFloat(),
                'qty' => $trade->qty->toFloat(),
                'side' => $trade->takerSide->value,
            ];
        }
        if (count($this->tape) > 40) {
            $this->tape = array_slice($this->tape, -40);
        }

        // Apply only our own fills to our book; attribute realized P&L per strategy.
        foreach ($fills as $fill) {
            if ($fill->accountId !== $this->houseAccount) {
                continue;
            }
            if ($this->record) {
                $this->recordedFills[] = $fill;
            }
            $pos = $this->house->position($fill->symbol);
            $realizedBefore = $pos->realizedPnl;
            $this->house->applyFill($fill);
            $realizedDelta = $pos->realizedPnl->sub($realizedBefore)->toFloat();
            $this->attribution->record($fill, $realizedDelta);

            // MiFID II transaction report for each HOUSE leg (also hits audit chain).
            $order = new Order($fill->orderId, 'HOUSE', $fill->symbol, $fill->side, OrderType::Limit, TimeInForce::GTC, $fill->price, $fill->qty, $fill->tsNanos, 0, $fill->strategy);
            $this->mifid->reportTrade(new \TradingPlatform\Matching\Trade($fill->tradeId, $fill->symbol, $fill->price, $fill->qty, $fill->orderId, $fill->orderId, $fill->side, $fill->tsNanos), $order, $this->clock->nowMillis());
        }

        // Drain the event bus (a downstream consumer would persist to Kafka/ClickHouse).
        while (($ev = $this->eventBus->pop()) !== null) {
            $this->eventPool->release($ev);
        }
    }

    /** Manually submit an order at the current step (used by the API / rebuild). */
    public function recordManualOrder(string $side, string $type, string $tif, ?float $price, float $qty): void
    {
        $this->manualOrders[] = [
            'step' => $this->step + 1, // takes effect on the next step
            'side' => $side,
            'type' => $type,
            'tif' => $tif,
            'price' => $price,
            'qty' => $qty,
        ];
    }

    public function currentStep(): int
    {
        return $this->step;
    }

    // --- Persistence accessors (populated when opts['record'] === true) ------

    public function houseAccountId(): string
    {
        return $this->houseAccount;
    }

    public function houseName(): string
    {
        return $this->houseName;
    }

    public function lastMarkPrice(): float
    {
        return $this->lastPrice;
    }

    /** @return list<Order> every order we submitted this run */
    public function recordedOrders(): array
    {
        return $this->recordedOrders;
    }

    /** @return list<\TradingPlatform\Order\Fill> every fill against our account */
    public function recordedFills(): array
    {
        return $this->recordedFills;
    }

    public function housePortfolio(): Portfolio
    {
        return $this->house;
    }

    public function auditLog(): AuditLog
    {
        return $this->audit;
    }

    /**
     * Full dashboard snapshot.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $marks = [
            self::SYMBOL => Decimal::of($this->lastPrice),
            self::HEDGE_SYMBOL => Decimal::of($this->hedgeFair),
        ];
        $pos = $this->house->position(self::SYMBOL);

        $btcReturns = Statistics::returns($this->priceHistory);
        $ethReturns = Statistics::returns($this->hedgeHistory);
        $correlation = Statistics::correlation($btcReturns, $ethReturns);
        $volBtc = Statistics::realizedVolatility($this->priceHistory, 34_560) * 100; // annualized-ish %
        $volEth = Statistics::realizedVolatility($this->hedgeHistory, 34_560) * 100;

        $auditVerify = $this->audit->verify();

        return [
            'step' => $this->step,
            'symbol' => self::SYMBOL,
            'clockMs' => $this->clock->nowMillis(),
            'fairValue' => round($this->fairValue, 2),
            'lastPrice' => round($this->lastPrice, 2),
            'bbo' => [
                'bid' => $this->lastBid?->toFloat(),
                'ask' => $this->lastAsk?->toFloat(),
                'spread' => ($this->lastBid && $this->lastAsk) ? round($this->lastAsk->sub($this->lastBid)->toFloat(), 2) : null,
            ],
            'book' => $this->lastDepth,
            'priceHistory' => array_map(static fn (float $p): float => round($p, 2), $this->priceHistory),
            'equityCurve' => array_map(static fn (float $e): float => round($e, 2), array_slice($this->pnl->equityCurve(), -240)),
            'pnl' => $this->pnl->summary(),
            'position' => $pos->toArray(Decimal::of($this->lastPrice)),
            'indicators' => $this->indicators->snapshot(),
            'stats' => [
                'correlation' => round($correlation, 3),
                'volBtcPct' => round($volBtc, 2),
                'volEthPct' => round($volEth, 2),
                'ethPrice' => round($this->hedgeFair, 2),
            ],
            'risk' => array_merge($this->lastRisk, ['limits' => $this->risk->limits->toArray()]),
            'compliance' => [
                'auditCount' => $this->audit->count(),
                'auditHead' => substr($this->audit->head(), 0, 16),
                'auditValid' => $auditVerify['valid'],
                'mifidReports' => $this->mifid->count(),
                'surveillanceFlags' => $this->surveillance->count(),
                'flags' => $this->surveillance->flags(6),
            ],
            'perf' => [
                'latency' => $this->latency->snapshot(),
                'objectPool' => $this->eventPool->stats(),
                'ringDropped' => $this->eventBus->dropped(),
            ],
            'attribution' => $this->attribution->byStrategy(),
            'tape' => array_slice(array_reverse($this->tape), 0, 15),
            'alerts' => array_reverse($this->alerts->recent(8)),
            'totals' => [
                'trades' => $this->tradeCount,
                'volume' => round($this->tradedVolume, 3),
                'rejected' => $this->rejectedOrders,
                'algorithm' => $this->algorithm->value,
            ],
            'audit' => array_map(static function (array $e): array {
                return ['seq' => $e['seq'], 'type' => $e['type'], 'hash' => substr($e['hash'], 0, 12)];
            }, $this->audit->recent(8)),
        ];
    }
}
