<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Contracts;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\ViewCursor;

/**
 * Everything a client knows, keyed.
 *
 * A device that is killed mid-page must come back knowing exactly what it knew
 * before, or it re-bootstraps its whole dataset over whatever connection it has.
 * So this is storage, and every read is keyed for the same reason the server's
 * ledger is: an implementation should never have to materialize the lot.
 *
 * transaction() is the only commit point. A page updates records, watermarks,
 * memberships and a cursor together, and applying half of that is worse than
 * applying none: the cursor would claim progress the records do not reflect.
 */
interface ClientState
{
    public function record(EntityKey $entity): ?EntityRecord;

    public function putRecord(EntityRecord $record): void;

    public function forgetRecord(EntityKey $entity): void;

    /** Highest canonical version ever seen, retained after deletion. Zero when unknown. */
    public function version(EntityKey $entity): int;

    public function setVersion(EntityKey $entity, int $version): void;

    /** The delete watermark, or null when the entity was never deleted. */
    public function tombstone(EntityKey $entity): ?int;

    public function setTombstone(EntityKey $entity, int $version): void;

    /** @return list<string> context fingerprints that currently own this entity */
    public function memberships(EntityKey $entity): array;

    public function addMembership(EntityKey $entity, string $contextKey): void;

    public function removeMembership(EntityKey $entity, string $contextKey): void;

    public function forgetMemberships(EntityKey $entity): void;

    /** @return list<EntityKey> every entity this view owns, for a reset */
    public function members(string $contextKey): array;

    public function context(string $contextKey): ?CursorContext;

    public function putContext(CursorContext $context): void;

    public function cursor(string $contextKey): ?ViewCursor;

    public function putCursor(ViewCursor $cursor): void;

    public function nextBootstrapToken(string $contextKey): ?string;

    public function setNextBootstrapToken(string $contextKey, ?string $token): void;

    public function bootstrapTokenApplied(string $contextKey, string $token): bool;

    public function markBootstrapTokenApplied(string $contextKey, string $token): void;

    /** Drops one view's progress and ownership, keeping shared canonical knowledge. */
    public function forgetView(string $contextKey): void;

    /**
     * Runs one atomic unit. An exception discards everything written inside it.
     *
     * @template TResult
     *
     * @param  \Closure(): TResult  $callback
     * @return TResult
     */
    public function transaction(\Closure $callback): mixed;
}
