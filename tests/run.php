<?php

declare(strict_types=1);

/**
 * Zero-dependency test runner (no PHPUnit needed). Exercises the correctness-
 * critical logic: decimal math, matching semantics, TIF handling, position
 * accounting, VaR, indicators, and the audit hash-chain tamper check.
 *
 * Run:  php tests/run.php
 */

require __DIR__.'/../vendor/autoload.php';

use TradingPlatform\Analysis\Indicators;
use TradingPlatform\Analytics\Metrics;
use TradingPlatform\Compliance\AuditLog;
use TradingPlatform\Engine\TradingPlatform;
use TradingPlatform\Matching\MatchingEngine;
use TradingPlatform\Matching\OrderBook;
use TradingPlatform\Order\Order;
use TradingPlatform\Order\OrderType;
use TradingPlatform\Order\Side;
use TradingPlatform\Order\TimeInForce;
use TradingPlatform\Risk\Position;
use TradingPlatform\Risk\RiskMetrics;
use TradingPlatform\Support\Clock;
use TradingPlatform\Support\Decimal;
use TradingPlatform\Support\Ids;
use TradingPlatform\Support\VirtualClock;

$passed = 0;
$failed = 0;

function check(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  \033[32m✓\033[0m {$name}\n";
    } else {
        $failed++;
        echo "  \033[31m✗ {$name}\033[0m\n";
    }
}

function section(string $s): void
{
    echo "\n\033[1m{$s}\033[0m\n";
}

/** Build a fresh engine for a symbol. */
function engine(): MatchingEngine
{
    return new MatchingEngine(new OrderBook('BTC/USDT'), new Ids(), new VirtualClock());
}

function order(MatchingEngine $e, string $acct, Side $side, OrderType $type, TimeInForce $tif, ?float $price, float $qty): Order
{
    static $seq = 0;
    static $ids = null;
    $ids ??= new Ids();

    return new Order($ids->nextOrderId(), $acct, 'BTC/USDT', $side, $type, $tif, $price !== null ? Decimal::of($price) : null, Decimal::of($qty), 0, ++$seq);
}

// ---------------------------------------------------------------------------
section('Decimal (exact money math)');
$a = Decimal::of('0.1');
$b = Decimal::of('0.2');
check('0.1 + 0.2 === 0.3 exactly', $a->add($b)->eq(Decimal::of('0.3')));
check('no float drift', (string) $a->add($b) === '0.30000000');
check('multiplication', Decimal::of('64000')->mul(Decimal::of('0.25'))->eq(Decimal::of('16000')));
check('division', Decimal::of('100')->div(Decimal::of('3'))->toFloat() > 33.33);
check('negative + abs', Decimal::of('-5')->abs()->eq(Decimal::of('5')));

// ---------------------------------------------------------------------------
section('Matching — price-time priority (FIFO)');
$e = engine();
$e->submit(order($e, 'MM', Side::Sell, OrderType::Limit, TimeInForce::GTC, 100.0, 1.0)); // ask 100 x1
$e->submit(order($e, 'MM', Side::Sell, OrderType::Limit, TimeInForce::GTC, 101.0, 1.0)); // ask 101 x1
$res = $e->submit(order($e, 'TAKER', Side::Buy, OrderType::Market, TimeInForce::IOC, null, 1.5));
check('market buy filled 1.5', $res['order']->filledQty->eq(Decimal::of('1.5')));
check('best price first (100 before 101)', $res['trades'][0]->price->eq(Decimal::of('100')));
check('two trades generated', count($res['trades']) === 2);
check('VWAP of fills = (100*1 + 101*0.5)/1.5', abs($res['order']->avgFillPrice->toFloat() - (100 * 1 + 101 * 0.5) / 1.5) < 1e-6);

section('Matching — resting & partial fills');
$e = engine();
$e->submit(order($e, 'MM', Side::Buy, OrderType::Limit, TimeInForce::GTC, 100.0, 2.0)); // bid 100 x2
$res = $e->submit(order($e, 'T', Side::Sell, OrderType::Limit, TimeInForce::GTC, 100.0, 0.5));
check('partial fill leaves 1.5 resting', $e->book->bestBid()->eq(Decimal::of('100')));
check('seller fully filled 0.5', $res['order']->status->value === 'FILLED');

section('Matching — FOK all-or-nothing');
$e = engine();
$e->submit(order($e, 'MM', Side::Sell, OrderType::Limit, TimeInForce::GTC, 100.0, 1.0)); // only 1 available
$res = $e->submit(order($e, 'T', Side::Buy, OrderType::Limit, TimeInForce::FOK, 100.0, 2.0)); // want 2
check('FOK with insufficient liquidity is cancelled', $res['order']->status->value === 'CANCELLED');
check('FOK produced zero fills (atomic)', count($res['trades']) === 0);
check('resting ask untouched after FOK kill', $e->book->bestAsk()->eq(Decimal::of('100')));

section('Matching — IOC cancels remainder');
$e = engine();
$e->submit(order($e, 'MM', Side::Sell, OrderType::Limit, TimeInForce::GTC, 100.0, 1.0));
$res = $e->submit(order($e, 'T', Side::Buy, OrderType::Limit, TimeInForce::IOC, 100.0, 3.0));
check('IOC filled available 1.0', $res['order']->filledQty->eq(Decimal::of('1')));
check('IOC did not rest remainder', $res['rested'] === false && $res['order']->status->value === 'CANCELLED');

section('Matching — self-trade prevention');
$e = engine();
$e->submit(order($e, 'SAME', Side::Sell, OrderType::Limit, TimeInForce::GTC, 100.0, 1.0));
$res = $e->submit(order($e, 'SAME', Side::Buy, OrderType::Market, TimeInForce::IOC, null, 1.0));
check('STP prevents account matching itself', count($res['trades']) === 0);

// ---------------------------------------------------------------------------
section('Position accounting (avg cost + realized P&L)');
$pos = new Position('BTC/USDT');
$pos->applyFill(new \TradingPlatform\Order\Fill('t1', 'o1', 'A', 'BTC/USDT', Side::Buy, Decimal::of('100'), Decimal::of('1'), \TradingPlatform\Order\Liquidity::Taker, 0));
$pos->applyFill(new \TradingPlatform\Order\Fill('t2', 'o2', 'A', 'BTC/USDT', Side::Buy, Decimal::of('200'), Decimal::of('1'), \TradingPlatform\Order\Liquidity::Taker, 0));
check('avg price of two buys = 150', $pos->avgPrice->eq(Decimal::of('150')));
$pos->applyFill(new \TradingPlatform\Order\Fill('t3', 'o3', 'A', 'BTC/USDT', Side::Sell, Decimal::of('160'), Decimal::of('1'), \TradingPlatform\Order\Liquidity::Taker, 0));
check('sell 1 @160 realizes +10', $pos->realizedPnl->eq(Decimal::of('10')));
check('remaining qty = 1', $pos->qty->eq(Decimal::of('1')));
check('unrealized @180 = +30', $pos->unrealizedPnl(Decimal::of('180'))->eq(Decimal::of('30')));

// ---------------------------------------------------------------------------
section('VaR / CVaR');
$pnls = [-100, -50, -20, -10, 0, 5, 10, 20, 30, 40];
check('historical VaR95 positive', RiskMetrics::historicalVaR($pnls, 0.95) > 0);
check('CVaR >= VaR (tail worse than threshold)', RiskMetrics::conditionalVaR($pnls, 0.95) >= RiskMetrics::historicalVaR($pnls, 0.95));
check('z-score(0.95) ~ 1.645', abs(RiskMetrics::zScore(0.95) - 1.645) < 0.01);

// ---------------------------------------------------------------------------
section('Indicators (RSI bounds, streaming)');
$ind = new Indicators();
for ($i = 0; $i < 40; $i++) {
    $ind->update(100 + sin($i / 3) * 5, 1.0);
}
$rsi = $ind->rsi();
check('RSI within [0,100]', $rsi !== null && $rsi >= 0 && $rsi <= 100);
check('MACD computed', $ind->macd() !== null);
check('VWAP computed', $ind->vwap() !== null);

section('Metrics (Sharpe / drawdown)');
check('Sharpe of positive drift > 0', Metrics::sharpe([0.01, 0.02, 0.015, 0.005, 0.02]) > 0);
check('max drawdown of monotonic rise = 0', Metrics::maxDrawdown([1, 2, 3, 4]) === 0.0);
check('max drawdown detects 50% fall', abs(Metrics::maxDrawdown([100, 50]) - 0.5) < 1e-9);

// ---------------------------------------------------------------------------
section('Audit hash-chain (tamper evidence)');
$log = new AuditLog();
$log->append('ORDER', ['id' => 1], 1000);
$log->append('FILL', ['id' => 1, 'qty' => 2], 1001);
$log->append('ORDER', ['id' => 2], 1002);
check('valid chain verifies', $log->verify()['valid'] === true);
// Tamper with a past entry via reflection.
$ref = new ReflectionProperty(AuditLog::class, 'entries');
$ref->setAccessible(true);
$entries = $ref->getValue($log);
$entries[1]['payload']['qty'] = 999; // forge a fill quantity
$ref->setValue($log, $entries);
$v = $log->verify();
check('tampered chain is detected', $v['valid'] === false);
check('tamper located at seq 2', $v['brokenAt'] === 2);

// ---------------------------------------------------------------------------
section('Determinism (same seed → identical run)');
$p1 = new TradingPlatform(7);
$p1->runTo(200);
$p2 = new TradingPlatform(7);
$p2->runTo(200);
check('reproducible last price', $p1->snapshot()['lastPrice'] === $p2->snapshot()['lastPrice']);
check('reproducible equity', $p1->snapshot()['pnl']['equity'] === $p2->snapshot()['pnl']['equity']);
check('audit chain valid after full run', $p1->snapshot()['compliance']['auditValid'] === true);

// ---------------------------------------------------------------------------
echo "\n".str_repeat('─', 46)."\n";
$total = $passed + $failed;
if ($failed === 0) {
    echo "\033[32m✓ ALL {$total} CHECKS PASSED\033[0m\n";
    exit(0);
}
echo "\033[31m✗ {$failed} of {$total} checks FAILED\033[0m\n";
exit(1);
