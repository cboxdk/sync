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
     * Set the acknowledged sequence to exactly this, lower or higher - but only
     * if it still is $expected, in one atomic step: two deliveries can get the
     * same answer, and the late one must not wind back the other's progress.
     *
     * Only for a server that has said where it is. A gap is reported when this
     * device is AHEAD - a server restored from a backup - and a counter that
     * could only rise would resend the same number and get the same gap for
     * ever.
     *
     * @return bool whether it was still $expected, and so was set
     */
    public function resetAcknowledged(Replica $replica, string $space, int $sequence, int $expected): bool;

    /**
     * The name the server gave a record this device created under a handle.
     *
     * Kept, because a device that crashes after the server answered but before
     * the application stored the new name would otherwise have nothing left
     * that says what its handle became.
     */
    public function nameOf(EntityKey $handle): ?EntityKey;

    /**
     * The name a handle of this type became, whatever space it was queued in -
     * for a reference, which may point into another scope. Null when two
     * spaces gave the same handle different names: guessing between them
     * would point a write at another tenant's record.
     */
    public function namedAs(string $entityType, string $handle): ?string;

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
     * Mutations still queued and not yet handed out, of the given entity
     * types, oldest first.
     * Narrowed by the store, so a large backlog of unrelated writes is never
     * decoded.
     *
     * @param  list<string>  $entityTypes
     * @return list<Mutation>
     */
    public function queued(array $entityTypes): array;

    /** The oldest write still queued for this record, or null. */
    public function firstFor(string $entityType, string $entityId): ?Mutation;

    /** A queued write by its identity, or null when it is no longer queued. */
    public function find(string $mutationId): ?Mutation;

    /**
     * Keep this write as handed out: numbered, and from then on possibly on
     * the server. Its number is kept for every resend, and it is never
     * rewritten - a changed write under the same identity would be refused as
     * reused.
     */
    public function markSent(Mutation $numbered): void;

    /** The server said it has not got this write: it may be renumbered and rewritten again. */
    public function unmarkSent(string $mutationId): void;

    public function isSent(string $mutationId): bool;

    /**
     * The write on this stream that was handed out and is still waiting for
     * its answer, if any. A stream never numbers a new write while one is:
     * two writes could otherwise claim the same number.
     */
    public function inFlight(Replica $replica, string $space): ?Mutation;

    /**
     * Hold this stream's numbering for the rest of the transaction, so two
     * processes cannot both see nothing in flight and number two writes the
     * same. SQLite's write lock already does it; a server database needs a
     * row lock.
     */
    public function lockStream(Replica $replica, string $space): void;

    /**
     * Move queued writes of one type from one space label to another - the
     * scope they were queued under turned out to have a different name.
     */
    public function relabel(string $entityType, string $from, string $to): void;

    /**
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(\Closure $callback): mixed;
}
