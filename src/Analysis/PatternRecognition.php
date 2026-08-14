<?php

declare(strict_types=1);

namespace TradingPlatform\Analysis;

/**
 * Streaming price-pattern recognition (M1).
 *
 * Ticks are aggregated into OHLC candles, and a set of classic patterns is
 * detected on the closed candles:
 *
 *  - Trend: higher-highs/higher-lows (up), lower-highs/lower-lows (down).
 *  - Breakout: a close that clears the recent range high/low.
 *  - Support / resistance from recent swing points.
 *  - Candlesticks: doji, bullish/bearish engulfing.
 *  - Double top / double bottom.
 *
 * These feed the market-analysis view and can gate strategy decisions.
 */
final class PatternRecognition
{
    /** @var array{o:float,h:float,l:float,c:float,n:int}|null current forming candle */
    private ?array $cur = null;
    /** @var list<array{o:float,h:float,l:float,c:float}> closed candles */
    private array $candles = [];

    public function __construct(
        private readonly int $bucket = 4,     // ticks per candle
        private readonly int $maxCandles = 60,
        private readonly float $breakoutLookback = 10,
    ) {}

    public function update(float $price): void
    {
        if ($this->cur === null) {
            $this->cur = ['o' => $price, 'h' => $price, 'l' => $price, 'c' => $price, 'n' => 1];

            return;
        }
        $this->cur['h'] = max($this->cur['h'], $price);
        $this->cur['l'] = min($this->cur['l'], $price);
        $this->cur['c'] = $price;
        $this->cur['n']++;

        if ($this->cur['n'] >= $this->bucket) {
            $this->candles[] = ['o' => $this->cur['o'], 'h' => $this->cur['h'], 'l' => $this->cur['l'], 'c' => $this->cur['c']];
            if (count($this->candles) > $this->maxCandles) {
                array_shift($this->candles);
            }
            $this->cur = null;
        }
    }

    /**
     * @return array{trend:string,patterns:list<string>,support:?float,resistance:?float,breakout:?string}
     */
    public function detect(): array
    {
        $n = count($this->candles);
        $patterns = [];
        if ($n < 4) {
            return ['trend' => 'forming', 'patterns' => [], 'support' => null, 'resistance' => null, 'breakout' => null];
        }

        $recent = array_slice($this->candles, -min($n, (int) $this->breakoutLookback));
        $highs = array_column($recent, 'h');
        $lows = array_column($recent, 'l');
        $resistance = max($highs);
        $support = min($lows);
        $last = $this->candles[$n - 1];
        $prev = $this->candles[$n - 2];

        // Trend from the last few closes.
        $closes = array_map(static fn (array $c): float => $c['c'], array_slice($this->candles, -5));
        $trend = $this->trend($closes);

        // Breakout: last close clears the prior range (exclude the last candle itself).
        $priorHigh = max(array_column(array_slice($recent, 0, -1) ?: $recent, 'h'));
        $priorLow = min(array_column(array_slice($recent, 0, -1) ?: $recent, 'l'));
        $breakout = null;
        if ($last['c'] > $priorHigh) {
            $breakout = 'bullish';
            $patterns[] = 'breakout ↑';
        } elseif ($last['c'] < $priorLow) {
            $breakout = 'bearish';
            $patterns[] = 'breakout ↓';
        }

        // Candlestick patterns.
        if ($this->isDoji($last)) {
            $patterns[] = 'doji';
        }
        if ($this->isBullishEngulfing($prev, $last)) {
            $patterns[] = 'bullish engulfing';
        } elseif ($this->isBearishEngulfing($prev, $last)) {
            $patterns[] = 'bearish engulfing';
        }

        // Double top / bottom over the recent window.
        if ($this->isDoubleTop($recent)) {
            $patterns[] = 'double top';
        }
        if ($this->isDoubleBottom($recent)) {
            $patterns[] = 'double bottom';
        }

        return [
            'trend' => $trend,
            'patterns' => $patterns,
            'support' => round($support, 2),
            'resistance' => round($resistance, 2),
            'breakout' => $breakout,
        ];
    }

    /** @param list<float> $closes */
    private function trend(array $closes): string
    {
        $c = count($closes);
        if ($c < 3) {
            return 'flat';
        }
        $up = $down = 0;
        for ($i = 1; $i < $c; $i++) {
            if ($closes[$i] > $closes[$i - 1]) {
                $up++;
            } elseif ($closes[$i] < $closes[$i - 1]) {
                $down++;
            }
        }
        if ($up >= $c - 1) {
            return 'strong up';
        }
        if ($down >= $c - 1) {
            return 'strong down';
        }
        if ($up > $down) {
            return 'up';
        }
        if ($down > $up) {
            return 'down';
        }

        return 'flat';
    }

    /** @param array{o:float,h:float,l:float,c:float} $c */
    private function isDoji(array $c): bool
    {
        $range = $c['h'] - $c['l'];

        return $range > 0 && abs($c['c'] - $c['o']) <= 0.1 * $range;
    }

    /**
     * @param array{o:float,h:float,l:float,c:float} $prev
     * @param array{o:float,h:float,l:float,c:float} $last
     */
    private function isBullishEngulfing(array $prev, array $last): bool
    {
        return $prev['c'] < $prev['o']          // prior red
            && $last['c'] > $last['o']           // current green
            && $last['c'] >= $prev['o']
            && $last['o'] <= $prev['c'];
    }

    /**
     * @param array{o:float,h:float,l:float,c:float} $prev
     * @param array{o:float,h:float,l:float,c:float} $last
     */
    private function isBearishEngulfing(array $prev, array $last): bool
    {
        return $prev['c'] > $prev['o']
            && $last['c'] < $last['o']
            && $last['o'] >= $prev['c']
            && $last['c'] <= $prev['o'];
    }

    /** @param list<array{o:float,h:float,l:float,c:float}> $w */
    private function isDoubleTop(array $w): bool
    {
        $highs = array_column($w, 'h');
        $peak = max($highs);
        $idx = array_keys($highs, $peak);
        // Two distinct highs within 0.15% of each other, separated by a dip.
        $near = array_filter($highs, static fn (float $h): bool => abs($h - $peak) / $peak < 0.0015);

        return count($near) >= 2 && (max(array_keys($near)) - min(array_keys($near))) >= 2;
    }

    /** @param list<array{o:float,h:float,l:float,c:float}> $w */
    private function isDoubleBottom(array $w): bool
    {
        $lows = array_column($w, 'l');
        $trough = min($lows);
        $near = array_filter($lows, static fn (float $l): bool => abs($l - $trough) / max($trough, 1e-9) < 0.0015);

        return count($near) >= 2 && (max(array_keys($near)) - min(array_keys($near))) >= 2;
    }

    /** @return array{candles:list<array{o:float,h:float,l:float,c:float}>,cur:?array} */
    public function toState(): array
    {
        return ['candles' => $this->candles, 'cur' => $this->cur];
    }

    /** @param array<string,mixed> $s */
    public static function fromState(array $s): self
    {
        $p = new self();
        $p->candles = $s['candles'] ?? [];
        $p->cur = $s['cur'] ?? null;

        return $p;
    }
}
