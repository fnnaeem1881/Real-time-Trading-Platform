<?php

declare(strict_types=1);

namespace TradingPlatform\Compliance;

/**
 * Tamper-evident, append-only audit trail — the regulatory "immutable log".
 *
 * Each entry is chained by hash: hash = sha256(prevHash || seq || ts || type ||
 * payload). Altering any past entry changes its hash and therefore every hash
 * after it, so {@see verify()} detects tampering. This is the same construction
 * a blockchain uses, applied to a compliance journal.
 */
final class AuditLog
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** @var list<array{seq:int,ts:int,type:string,payload:array<string,mixed>,prevHash:string,hash:string}> */
    private array $entries = [];
    private int $seq = 0;
    private string $head = self::GENESIS;

    /**
     * Append an event and return its hash.
     *
     * @param array<string,mixed> $payload
     */
    public function append(string $type, array $payload, int $tsMillis): string
    {
        $seq = ++$this->seq;
        $hash = $this->computeHash($this->head, $seq, $tsMillis, $type, $payload);
        $this->entries[] = [
            'seq' => $seq,
            'ts' => $tsMillis,
            'type' => $type,
            'payload' => $payload,
            'prevHash' => $this->head,
            'hash' => $hash,
        ];
        $this->head = $hash;

        return $hash;
    }

    /**
     * Recompute the chain and confirm no entry was altered or reordered.
     *
     * @return array{valid:bool,brokenAt:?int}
     */
    public function verify(): array
    {
        $prev = self::GENESIS;
        foreach ($this->entries as $e) {
            $expected = $this->computeHash($prev, $e['seq'], $e['ts'], $e['type'], $e['payload']);
            if ($expected !== $e['hash'] || $e['prevHash'] !== $prev) {
                return ['valid' => false, 'brokenAt' => $e['seq']];
            }
            $prev = $e['hash'];
        }

        return ['valid' => true, 'brokenAt' => null];
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function computeHash(string $prevHash, int $seq, int $ts, string $type, array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        return hash('sha256', $prevHash.'|'.$seq.'|'.$ts.'|'.$type.'|'.$canonical);
    }

    public function head(): string
    {
        return $this->head;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return list<array{seq:int,ts:int,type:string,payload:array<string,mixed>,prevHash:string,hash:string}> */
    public function recent(int $limit = 20): array
    {
        return array_slice($this->entries, -$limit);
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return $this->entries;
    }
}
