<?php

declare(strict_types=1);

namespace TradingPlatform\MarketData;

/**
 * Rejects bad ticks before they poison the book or indicators. Two guards:
 *
 *  1. Structural sanity — non-positive prices, crossed quotes (bid > ask), or an
 *     absurd spread relative to mid.
 *  2. Statistical outliers — a z-score on the return vs a rolling mean/stddev of
 *     recent mid-price returns. A print that jumps many sigma in one tick is far
 *     more likely a fat-finger / bad feed than a real move.
 */
final class AnomalyFilter
{
    /** @var list<float> recent log-ish returns for z-scoring */
    private array $returns = [];
    private ?float $lastMid = null;

    public function __construct(
        private readonly int $window = 50,
        private readonly float $zThreshold = 8.0,
        private readonly float $maxSpreadPct = 0.05, // 5% of mid
    ) {}

    /**
     * @return array{ok:bool,reason:?string}
     */
    public function inspect(MarketTick $tick): array
    {
        $bid = $tick->bid->toFloat();
        $ask = $tick->ask->toFloat();
        $mid = $tick->mid()->toFloat();

        if ($bid <= 0 || $ask <= 0 || $mid <= 0) {
            return ['ok' => false, 'reason' => 'non-positive price'];
        }
        if ($bid > $ask) {
            return ['ok' => false, 'reason' => 'crossed quote (bid>ask)'];
        }
        if (($ask - $bid) / $mid > $this->maxSpreadPct) {
            return ['ok' => false, 'reason' => 'spread too wide'];
        }

        $ok = true;
        $reason = null;
        if ($this->lastMid !== null && count($this->returns) >= 10) {
            $ret = ($mid - $this->lastMid) / $this->lastMid;
            [$mean, $std] = $this->stats();
            if ($std > 1e-12) {
                $z = abs($ret - $mean) / $std;
                if ($z > $this->zThreshold) {
                    $ok = false;
                    $reason = sprintf('price outlier z=%.1f', $z);
                }
            }
        }

        // Only learn from ticks we accept, so outliers don't inflate the baseline.
        if ($ok && $this->lastMid !== null) {
            $this->returns[] = ($mid - $this->lastMid) / $this->lastMid;
            if (count($this->returns) > $this->window) {
                array_shift($this->returns);
            }
        }
        $this->lastMid = $mid;

        return ['ok' => $ok, 'reason' => $reason];
    }

    /** @return array{0:float,1:float} mean, stddev */
    private function stats(): array
    {
        $n = count($this->returns);
        if ($n === 0) {
            return [0.0, 0.0];
        }
        $mean = array_sum($this->returns) / $n;
        $var = 0.0;
        foreach ($this->returns as $r) {
            $var += ($r - $mean) ** 2;
        }

        return [$mean, sqrt($var / $n)];
    }
}
