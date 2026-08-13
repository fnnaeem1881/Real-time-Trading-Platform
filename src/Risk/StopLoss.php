<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

use TradingPlatform\Support\Decimal;

/**
 * Stop-loss / take-profit monitor. Registers protective levels per account+symbol
 * and reports which trigger at the current mark, so the engine can flatten.
 *
 * Supports trailing stops: the stop ratchets in the favourable direction as the
 * mark improves, locking in gains without giving back the move.
 */
final class StopLoss
{
    /** @var array<string,array{side:string,stop:Decimal,trail:?Decimal,best:Decimal}> */
    private array $stops = [];

    private function key(string $account, string $symbol): string
    {
        return $account.'|'.$symbol;
    }

    /**
     * @param string $side side of the *position* being protected: LONG or SHORT
     */
    public function set(string $account, string $symbol, string $side, Decimal $stop, ?Decimal $trailDistance = null): void
    {
        $this->stops[$this->key($account, $symbol)] = [
            'side' => $side,
            'stop' => $stop,
            'trail' => $trailDistance,
            'best' => $stop,
        ];
    }

    public function clear(string $account, string $symbol): void
    {
        unset($this->stops[$this->key($account, $symbol)]);
    }

    /**
     * Update trailing stops with the latest mark and return the list of triggered
     * protections (which the caller should act on by flattening).
     *
     * @param array<string,Decimal> $marks symbol => mark
     * @return list<array{account:string,symbol:string,side:string,stop:float,mark:float}>
     */
    public function evaluate(string $account, array $marks): array
    {
        $triggered = [];
        foreach ($this->stops as $key => &$s) {
            [$acct, $symbol] = explode('|', $key, 2);
            if ($acct !== $account || !isset($marks[$symbol])) {
                continue;
            }
            $mark = $marks[$symbol];

            // Trail the stop in the profitable direction.
            if ($s['trail'] !== null) {
                if ($s['side'] === 'LONG') {
                    $candidate = $mark->sub($s['trail']);
                    if ($candidate->gt($s['stop'])) {
                        $s['stop'] = $candidate;
                    }
                } else { // SHORT
                    $candidate = $mark->add($s['trail']);
                    if ($candidate->lt($s['stop'])) {
                        $s['stop'] = $candidate;
                    }
                }
            }

            $hit = $s['side'] === 'LONG' ? $mark->lte($s['stop']) : $mark->gte($s['stop']);
            if ($hit) {
                $triggered[] = [
                    'account' => $acct,
                    'symbol' => $symbol,
                    'side' => $s['side'],
                    'stop' => $s['stop']->toFloat(),
                    'mark' => $mark->toFloat(),
                ];
                unset($this->stops[$key]);
            }
        }
        unset($s);

        return $triggered;
    }

    public function active(): int
    {
        return count($this->stops);
    }
}
