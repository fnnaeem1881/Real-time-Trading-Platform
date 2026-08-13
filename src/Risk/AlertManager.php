<?php

declare(strict_types=1);

namespace TradingPlatform\Risk;

enum AlertSeverity: string
{
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Critical = 'CRITICAL';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }
}

/**
 * Tiered risk alerting with escalation. Repeated criticals for the same key
 * escalate (tracked count), which a pager/on-call integration would key off.
 */
final class AlertManager
{
    /** @var list<array{ts:int,severity:string,code:string,message:string,count:int}> */
    private array $alerts = [];
    /** @var array<string,int> escalation counters per code */
    private array $counts = [];

    public function raise(AlertSeverity $severity, string $code, string $message, int $tsMillis): void
    {
        $this->counts[$code] = ($this->counts[$code] ?? 0) + 1;
        $this->alerts[] = [
            'ts' => $tsMillis,
            'severity' => $severity->value,
            'code' => $code,
            'message' => $message,
            'count' => $this->counts[$code],
        ];
        // Keep the ring bounded.
        if (count($this->alerts) > 200) {
            array_shift($this->alerts);
        }
    }

    /** Should this alert escalate to on-call? Critical, or a warning seen repeatedly. */
    public function shouldEscalate(AlertSeverity $severity, string $code): bool
    {
        return $severity === AlertSeverity::Critical
            || ($severity === AlertSeverity::Warning && ($this->counts[$code] ?? 0) >= 5);
    }

    /** @return list<array{ts:int,severity:string,code:string,message:string,count:int}> */
    public function recent(int $limit = 20): array
    {
        return array_slice($this->alerts, -$limit);
    }

    public function count(): int
    {
        return count($this->alerts);
    }
}
