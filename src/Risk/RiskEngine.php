<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

use TradingPlatform\Order\Order;
use TradingPlatform\Order\Side;
use TradingPlatform\Support\Decimal;

/**
 * The risk brain. Two responsibilities:
 *
 *  1. Pre-trade gate (synchronous, on the hot path): every order is validated
 *     against limits *before* it reaches the matching engine. A rejection here
 *     never touches the book.
 *  2. Continuous portfolio risk (exposure, concentration, VaR/CVaR, margin,
 *     drawdown kill-switch) recomputed as fills and marks arrive.
 */
final class RiskEngine
{
    private float $equityHighWater;
    private bool $killSwitch = false;

    public function __construct(
        public readonly RiskLimits $limits,
        public readonly AlertManager $alerts,
        float $startingEquity,
    ) {
        $this->equityHighWater = $startingEquity;
    }

    /**
     * Pre-trade validation. Returns an approve/reject decision listing every
     * breached limit.
     *
     * @param array<string,Decimal> $marks
     */
    public function preTradeCheck(Order $order, Portfolio $portfolio, array $marks, int $tsMillis): RiskDecision
    {
        $breaches = [];

        if ($this->killSwitch) {
            $breaches[] = 'kill-switch active (drawdown breach)';
        }

        $mark = $marks[$order->symbol] ?? $order->price ?? Decimal::zero();
        $orderNotional = $mark->mul($order->qty)->abs()->toFloat();

        // 1. Per-order notional cap.
        if ($orderNotional > $this->limits->maxOrderNotional) {
            $breaches[] = sprintf('order notional %.0f > max %.0f', $orderNotional, $this->limits->maxOrderNotional);
        }

        // 2. Projected symbol exposure after the order.
        $pos = $portfolio->position($order->symbol);
        $signed = $order->side === Side::Buy ? $order->qty : $order->qty->negate();
        $projectedQty = $pos->qty->add($signed);
        $projectedExposure = $projectedQty->abs()->mul($mark)->toFloat();
        if ($projectedExposure > $this->limits->maxSymbolExposure) {
            $breaches[] = sprintf('symbol exposure %.0f > max %.0f', $projectedExposure, $this->limits->maxSymbolExposure);
        }

        // 3. Projected gross portfolio exposure.
        $gross = $portfolio->grossExposure($marks)->toFloat();
        $projectedGross = $gross - $pos->exposure($mark)->toFloat() + $projectedExposure;
        if ($projectedGross > $this->limits->maxGrossExposure) {
            $breaches[] = sprintf('gross exposure %.0f > max %.0f', $projectedGross, $this->limits->maxGrossExposure);
        }

        // 4. Concentration: no single symbol above the configured share of gross.
        if ($projectedGross > 0) {
            $concentration = $projectedExposure / $projectedGross;
            if ($concentration > $this->limits->maxConcentration && $projectedExposure > $this->limits->maxOrderNotional) {
                $breaches[] = sprintf('concentration %.0f%% > max %.0f%%', $concentration * 100, $this->limits->maxConcentration * 100);
            }
        }

        // 5. Margin: does the account have enough equity to post initial margin?
        $equity = $portfolio->equity($marks)->toFloat();
        $requiredMargin = $projectedGross * $this->limits->initialMarginRate;
        if ($requiredMargin > $equity) {
            $breaches[] = sprintf('initial margin %.0f > equity %.0f', $requiredMargin, $equity);
        }

        if ($breaches !== []) {
            $this->alerts->raise(AlertSeverity::Warning, 'PRE_TRADE_REJECT', 'Order rejected: '.implode('; ', $breaches), $tsMillis);

            return RiskDecision::reject($breaches);
        }

        return RiskDecision::approve();
    }

    /**
     * Recompute portfolio-level risk after fills/marks change. Updates the
     * drawdown high-water mark and trips the kill-switch on breach.
     *
     * @param array<string,Decimal> $marks
     * @param list<float> $pnlHistory per-step P&L changes for VaR
     * @return array<string,mixed>
     */
    public function assess(Portfolio $portfolio, array $marks, array $pnlHistory, int $tsMillis): array
    {
        $equity = $portfolio->equity($marks)->toFloat();
        $this->equityHighWater = max($this->equityHighWater, $equity);
        $drawdown = $this->equityHighWater > 0 ? ($this->equityHighWater - $equity) / $this->equityHighWater : 0.0;

        if (!$this->killSwitch && $drawdown >= $this->limits->maxDrawdown) {
            $this->killSwitch = true;
            $this->alerts->raise(AlertSeverity::Critical, 'DRAWDOWN_KILL', sprintf('Drawdown %.1f%% >= %.1f%% — trading halted', $drawdown * 100, $this->limits->maxDrawdown * 100), $tsMillis);
        }

        $gross = $portfolio->grossExposure($marks)->toFloat();

        // Concentration warning (largest single-symbol share of gross).
        $topShare = 0.0;
        $topSymbol = null;
        foreach ($portfolio->positions() as $symbol => $pos) {
            $mark = $marks[$symbol] ?? $pos->avgPrice;
            $exp = $pos->exposure($mark)->toFloat();
            $share = $gross > 0 ? $exp / $gross : 0.0;
            if ($share > $topShare) {
                $topShare = $share;
                $topSymbol = $symbol;
            }
        }
        if ($topShare > $this->limits->maxConcentration && $gross > $this->limits->maxOrderNotional) {
            $this->alerts->raise(AlertSeverity::Warning, 'CONCENTRATION', sprintf('%s is %.0f%% of gross exposure', (string) $topSymbol, $topShare * 100), $tsMillis);
        }

        $var95 = RiskMetrics::historicalVaR($pnlHistory, 0.95);
        $cvar95 = RiskMetrics::conditionalVaR($pnlHistory, 0.95);
        $var99 = RiskMetrics::historicalVaR($pnlHistory, 0.99);

        return [
            'equity' => $equity,
            'highWater' => $this->equityHighWater,
            'drawdown' => $drawdown,
            'grossExposure' => $gross,
            'concentration' => ['symbol' => $topSymbol, 'share' => $topShare],
            'var95' => $var95,
            'cvar95' => $cvar95,
            'var99' => $var99,
            'initialMargin' => $gross * $this->limits->initialMarginRate,
            'maintenanceMargin' => $gross * $this->limits->maintenanceMarginRate,
            'killSwitch' => $this->killSwitch,
        ];
    }

    public function killSwitchActive(): bool
    {
        return $this->killSwitch;
    }

    public function toState(): array
    {
        return ['highWater' => $this->equityHighWater, 'killSwitch' => $this->killSwitch];
    }

    public function restoreState(float $highWater, bool $killSwitch): void
    {
        $this->equityHighWater = $highWater;
        $this->killSwitch = $killSwitch;
    }
}
