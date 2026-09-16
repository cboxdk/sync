<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Contracts;

use Cbox\Sync\Data\Mutation;
use Cbox\Sync\ValueObjects\Replica;

/**
 * The local queue of writes a device has made but the server has not
 * acknowledged.
 *
 * It has to be durable for the same reason the sequence has to be gapless: the
 * server refuses a mutation that arrives out of order, so a device that forgets
 * what it had queued cannot produce a stream the server will accept again
 * without being told where to resume.
 */
interface OutboxStore
{
    public function append(Mutation $mutation): void;

    /** The oldest mutation still awaiting acknowledgement. */
    public function head(): ?Mutation;

    /** One past the highest sequence ever assigned to this replica, never reused. */
    public function nextSequence(Replica $replica): int;

    public function acknowledge(string $mutationId): void;

    /** Drops everything at or below a sequence the server says it already has. */
    public function acknowledgeThrough(Replica $replica, int $sequence): void;

    /** Moves a mutation out of the queue permanently, with why. */
    public function abandon(string $mutationId, string $reason): void;

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array;

    public function pending(): int;

    /**
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(\Closure $callback): mixed;
}
