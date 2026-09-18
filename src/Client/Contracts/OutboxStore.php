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
    /**
     * Queue a mutation. Its sequence is a placeholder: the real one is assigned
     * when it is handed out, so a mutation that never reaches the server does
     * not consume a number the server will then wait for forever.
     */
    public function append(Mutation $mutation): void;

    /**
     * The oldest mutation still awaiting acknowledgement, with its placeholder
     * sequence. Narrowed to one entity type when given, because a push names
     * one type and must not send another type's queued work as that type.
     */
    public function head(?string $entityType = null): ?Mutation;

    /**
     * The highest sequence acknowledged for this replica IN THIS SPACE.
     *
     * The server keys an acknowledgement stream by space and replica together,
     * so a device writing to two spaces has two independent streams. A single
     * per-replica counter would send space B a number it has never seen.
     */
    public function acknowledged(Replica $replica, string $space): int;

    public function setAcknowledged(Replica $replica, string $space, int $sequence): void;

    public function acknowledge(string $mutationId): void;

    /** Moves a mutation out of the queue permanently, with why. */
    public function abandon(string $mutationId, string $reason): void;

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array;

    public function pending(?string $entityType = null): int;

    /**
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(\Closure $callback): mixed;
}
