<?php

declare(strict_types=1);

namespace TradingPlatform\Order;

enum Side: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';

    public function opposite(): self
    {
        return $this === self::Buy ? self::Sell : self::Buy;
    }

    public function sign(): int
    {
        return $this === self::Buy ? 1 : -1;
    }
}

enum OrderType: string
{
    case Limit = 'LIMIT';
    case Market = 'MARKET';
}

/** Time in force. */
enum TimeInForce: string
{
    case GTC = 'GTC';   // good till cancelled
    case IOC = 'IOC';   // immediate-or-cancel: fill what you can, cancel rest
    case FOK = 'FOK';   // fill-or-kill: fully fill immediately or cancel entirely
}

enum OrderStatus: string
{
    case New = 'NEW';
    case PartiallyFilled = 'PARTIALLY_FILLED';
    case Filled = 'FILLED';
    case Cancelled = 'CANCELLED';
    case Rejected = 'REJECTED';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Filled, self::Cancelled, self::Rejected => true,
            default => false,
        };
    }
}

/** Which side of a fill provided liquidity (maker) vs took it (taker). */
enum Liquidity: string
{
    case Maker = 'MAKER';
    case Taker = 'TAKER';
}
