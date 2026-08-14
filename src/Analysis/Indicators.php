<?php

declare(strict_types=1);

namespace TradingPlatform\Analysis;

/**
 * Streaming technical indicators. Each update is O(1) — we keep running state
 * rather than recomputing over a window, which is what makes tick-by-tick
 * indicator calculation viable at high message rates.
 *
 * Values are floats: these are statistical signals, not money, so IEEE-754 is
 * fine here (unlike the order/fill path, which uses Decimal).
 */
final class Indicators
{
    // --- EMA (also the building block for MACD) ---
    private ?float $emaFast = null;
    private ?float $emaSlow = null;
    private ?float $macdSignal = null;
    private readonly float $kFast;
    private readonly float $kSlow;
    private readonly float $kSignal;

    // --- RSI (Wilder's smoothing) ---
    private ?float $prevClose = null;
    private ?float $avgGain = null;
    private ?float $avgLoss = null;
    private int $rsiCount = 0;
    private readonly float $rsiPeriod;

    // --- VWAP (session cumulative) ---
    private float $cumPV = 0.0;
    private float $cumV = 0.0;

    public function __construct(
        int $fast = 12,
        int $slow = 26,
        int $signal = 9,
        int $rsiPeriod = 14,
    ) {
        $this->kFast = 2 / ($fast + 1);
        $this->kSlow = 2 / ($slow + 1);
        $this->kSignal = 2 / ($signal + 1);
        $this->rsiPeriod = $rsiPeriod;
    }

    /** Feed one price (and optional volume for VWAP). */
    public function update(float $price, float $volume = 0.0): void
    {
        // EMAs.
        $this->emaFast = $this->emaFast === null ? $price : $price * $this->kFast + $this->emaFast * (1 - $this->kFast);
        $this->emaSlow = $this->emaSlow === null ? $price : $price * $this->kSlow + $this->emaSlow * (1 - $this->kSlow);
        $macd = $this->emaFast - $this->emaSlow;
        $this->macdSignal = $this->macdSignal === null ? $macd : $macd * $this->kSignal + $this->macdSignal * (1 - $this->kSignal);

        // RSI via Wilder's smoothing.
        if ($this->prevClose !== null) {
            $change = $price - $this->prevClose;
            $gain = max(0.0, $change);
            $loss = max(0.0, -$change);
            if ($this->avgGain === null) {
                $this->avgGain = $gain;
                $this->avgLoss = $loss;
            } else {
                $this->avgGain = ($this->avgGain * ($this->rsiPeriod - 1) + $gain) / $this->rsiPeriod;
                $this->avgLoss = ($this->avgLoss * ($this->rsiPeriod - 1) + $loss) / $this->rsiPeriod;
            }
            $this->rsiCount++;
        }
        $this->prevClose = $price;

        // VWAP.
        if ($volume > 0.0) {
            $this->cumPV += $price * $volume;
            $this->cumV += $volume;
        }
    }

    public function emaFast(): ?float
    {
        return $this->emaFast;
    }

    public function emaSlow(): ?float
    {
        return $this->emaSlow;
    }

    public function macd(): ?float
    {
        if ($this->emaFast === null || $this->emaSlow === null) {
            return null;
        }

        return $this->emaFast - $this->emaSlow;
    }

    public function macdSignal(): ?float
    {
        return $this->macdSignal;
    }

    public function macdHistogram(): ?float
    {
        $macd = $this->macd();

        return ($macd === null || $this->macdSignal === null) ? null : $macd - $this->macdSignal;
    }

    public function rsi(): ?float
    {
        if ($this->rsiCount < (int) $this->rsiPeriod || $this->avgGain === null || $this->avgLoss === null) {
            return null;
        }
        if ($this->avgLoss <= 1e-12) {
            return 100.0;
        }
        $rs = $this->avgGain / $this->avgLoss;

        return 100 - (100 / (1 + $rs));
    }

    public function vwap(): ?float
    {
        return $this->cumV > 0.0 ? $this->cumPV / $this->cumV : null;
    }

    /** Reset VWAP at session boundaries. */
    public function resetVwap(): void
    {
        $this->cumPV = 0.0;
        $this->cumV = 0.0;
    }

    /** @return array<string,?float> */
    public function snapshot(): array
    {
        return [
            'rsi' => $this->rsi(),
            'macd' => $this->macd(),
            'macdSignal' => $this->macdSignal(),
            'macdHist' => $this->macdHistogram(),
            'emaFast' => $this->emaFast,
            'emaSlow' => $this->emaSlow,
            'vwap' => $this->vwap(),
        ];
    }

    /**
     * Serialize the mutable running state so a live session can persist and
     * resume indicators across requests.
     *
     * @return array<string,mixed>
     */
    public function toState(): array
    {
        return [
            'emaFast' => $this->emaFast, 'emaSlow' => $this->emaSlow, 'macdSignal' => $this->macdSignal,
            'prevClose' => $this->prevClose, 'avgGain' => $this->avgGain, 'avgLoss' => $this->avgLoss,
            'rsiCount' => $this->rsiCount, 'cumPV' => $this->cumPV, 'cumV' => $this->cumV,
        ];
    }

    /** @param array<string,mixed> $s */
    public static function fromState(array $s): self
    {
        $i = new self();
        $i->emaFast = $s['emaFast'] ?? null;
        $i->emaSlow = $s['emaSlow'] ?? null;
        $i->macdSignal = $s['macdSignal'] ?? null;
        $i->prevClose = $s['prevClose'] ?? null;
        $i->avgGain = $s['avgGain'] ?? null;
        $i->avgLoss = $s['avgLoss'] ?? null;
        $i->rsiCount = (int) ($s['rsiCount'] ?? 0);
        $i->cumPV = (float) ($s['cumPV'] ?? 0.0);
        $i->cumV = (float) ($s['cumV'] ?? 0.0);

        return $i;
    }
}
