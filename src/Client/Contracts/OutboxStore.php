<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Contracts;

use Cbox\Sync\Data\Mutation;
use Cbox\Sync\ValueObjects\EntityKey;
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
     * sequence. Narrowed to one entity type and one space when given, because a
     * push names both and must not send another type's or another tenant's
     * queued work under them.
     */
    public function head(?string $entityType = null, ?string $space = null): ?Mutation;

    /**
     * Rename the entity every queued mutation refers to.
     *
     * A create carries a handle the device made up, and the server answers with
     * the name it actually gave the record. Anything queued behind that create
     * still refers to the handle, and would be a write to a record that does
     * not exist.
     *
     * This renames the key, which the queue owns. A field VALUE that refers to
     * the handle - a child holding its parent's id - is the application's, and
     * no store can know which fields are references, so the rename is reported
     * rather than hidden. It is also remembered, for nameOf().
     */
    public function rekey(EntityKey $from, EntityKey $to): void;

    /**
     * Put a rethought version of a queued mutation in its place.
     *
     * Same id, same position in the queue: the server refused the first
     * attempt without storing anything, so the rebased write is still the one
     * it is waiting for. Nothing happens when the mutation is no longer queued.
     */
    public function replace(Mutation $mutation): void;

    /**
     * The highest sequence acknowledged for this replica IN THIS SPACE.
     *
     * The server keys an acknowledgement stream by space and replica together,
     * so a device writing to two spaces has two independent streams. A single
     * per-replica counter would send space B a number it has never seen.
     */
    public function acknowledged(Replica $replica, string $space): int;

    /**
     * Raise the acknowledged sequence; never lowers it.
     *
     * Monotonic because two deliveries can overlap - a queue worker and a
     * scheduler both draining - and a late acknowledgement of 1 arriving after
     * one of 2 must not wind the counter back and reuse a number.
     */
    public function setAcknowledged(Replica $replica, string $space, int $sequence): void;

    /**
     * Set the acknowledged sequence to exactly this, lower or higher.
     *
     * Only for a server that has said where it is. A gap is reported when this
     * device is AHEAD - a server restored from a backup - and a counter that
     * could only rise would resend the same number and get the same gap for
     * ever.
     */
    public function resetAcknowledged(Replica $replica, string $space, int $sequence): void;

    /**
     * The name the server gave a record this device created under a handle.
     *
     * Kept, because a device that crashes after the server answered but before
     * the application stored the new name would otherwise have nothing left
     * that says what its handle became.
     */
    public function nameOf(EntityKey $handle): ?EntityKey;

    public function acknowledge(string $mutationId): void;

    /** Moves a mutation out of the queue permanently, with why. */
    public function abandon(string $mutationId, string $reason): void;

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array;

    /**
     * Stop reporting an abandoned mutation: the application has dealt with it.
     *
     * Its identity is gone with it, exactly like an acknowledged one's. Never
     * queue under it again: the server may hold a receipt for it.
     */
    public function dismiss(string $mutationId): void;

    public function pending(?string $entityType = null): int;

    /**
     * Mutations still queued in one space, of the given entity types, oldest
     * first. Narrowed by the store, so a large backlog of unrelated writes is
     * never decoded.
     *
     * @param  list<string>  $entityTypes
     * @return list<Mutation>
     */
    public function queued(string $space, array $entityTypes): array;

    /**
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(\Closure $callback): mixed;
}
