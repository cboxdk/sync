<?php

declare(strict_types=1);

namespace Cbox\Sync\Contracts;

use Cbox\Sync\Data\Change;
use Cbox\Sync\Data\Commit;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Replica;

/**
 * One storage transaction, scoped to exactly one space.
 *
 * Every read is keyed: an implementation never has to materialize the whole
 * store. Reads reflect writes previously made through the same instance, and a
 * repeated read of an unchanged key returns an equal value. The engine never
 * compares identity, so an implementation is free to rehydrate.
 *
 * The space write lock is taken when the transaction opens, before the first
 * read, so the whole callback is serialized against other writers in the space.
 */
interface Ledger
{
    public function space(): string;

    public function receipt(string $mutationId): ?Receipt;

    public function acknowledged(Replica $replica): int;

    public function record(EntityKey $entity): ?EntityRecord;

    public function group(string $id): ?ConflictGroup;

    /** The single open group for this field, if one exists. At most one may be open at a time. */
    public function openGroup(EntityKey $entity, string $field): ?ConflictGroup;

    /** Highest committed sequence in this space. A pure read: it consumes nothing. */
    public function watermark(): CommitSequence;

    public function putRecord(EntityRecord $record): void;

    public function putGroup(ConflictGroup $group): void;

    /** @throws TransientFailure when the mutation identity already exists */
    public function putReceipt(Receipt $receipt): void;

    public function acknowledge(Replica $replica, int $sequence): void;

    /**
     * Consumes the sequence, which must be the current watermark plus one.
     *
     * @param  list<Change>  $changes
     */
    public function appendCommit(CommitSequence $sequence, array $changes): Commit;

    /**
     * One level of nesting over records and groups only, so a blocked domain
     * mutation can be discarded while its receipt, acknowledgement and commit
     * are still published. A durable implementation uses a savepoint, which
     * keeps staged writes visible to a validator sharing the transaction.
     */
    public function beginDraft(): void;

    public function commitDraft(): void;

    public function rollbackDraft(): void;

    /** Whether putRecord() was called for this entity and survived the draft. */
    public function recordChanged(EntityKey $entity): bool;

    /**
     * Groups written during this transaction, in first-touch order.
     *
     * @return list<ConflictGroup>
     */
    public function touchedGroups(): array;
}
