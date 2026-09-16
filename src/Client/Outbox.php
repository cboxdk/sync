<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/**
 * Ordering and retry semantics for one device, in one place.
 *
 * Every application that talks to this protocol has to get the same four
 * answers right - keep going, retry this exact mutation, resume from here,
 * give up on this one - and getting any of them wrong is silent data loss or a
 * permanently wedged queue. So it is implemented once, here, and the transport
 * only has to report which of the four happened.
 */
class Outbox
{
    /** @param \Closure(): string $identity */
    public function __construct(
        private readonly OutboxStore $store,
        private readonly Replica $replica,
        private readonly \Closure $identity,
    ) {}

    /** @param \Closure(): string|null $identity generates globally unique mutation ids */
    public static function for(OutboxStore $store, Replica $replica, ?\Closure $identity = null): self
    {
        return new self($store, $replica, $identity ?? static fn (): string => bin2hex(random_bytes(16)));
    }

    /**
     * Queue a write. The sequence is assigned here and never reused, because
     * the server rejects a repeated one outright.
     *
     * @param  list<FieldOperation>  $operations
     */
    public function queue(EntityKey $entity, MutationKind $kind, array $operations, int $baseVersion, bool $atomic = true, ?Resolution $resolution = null): Mutation
    {
        return $this->store->transaction(function () use ($entity, $kind, $operations, $baseVersion, $atomic, $resolution): Mutation {
            $mutation = new Mutation(
                ($this->identity)(),
                $entity,
                $this->replica,
                new MutationSequence($this->store->nextSequence($this->replica)),
                $kind,
                new RecordVersion($baseVersion),
                $operations,
                $atomic,
                null,
                $resolution,
            );
            $this->store->append($mutation);

            return $mutation;
        });
    }

    public function head(): ?Mutation
    {
        return $this->store->head();
    }

    public function pending(): int
    {
        return $this->store->pending();
    }

    /** The server processed it. Whether it applied, conflicted or was rejected, it is done. */
    public function acknowledged(Mutation $mutation): void
    {
        $this->store->acknowledge($mutation->id);
    }

    /**
     * The server has not seen everything before this one.
     *
     * Only the mutations it already has are dropped; the rest stay queued and
     * go out again in order. Clearing the whole queue here would discard writes
     * the server never received.
     */
    public function resumeAfter(int $acknowledgedSequence): void
    {
        $this->store->acknowledgeThrough($this->replica, $acknowledgedSequence);
    }

    /**
     * Terminal for this mutation: it can never be sent again under this
     * identity, so it leaves the queue rather than blocking everything behind
     * it forever. The application has to be told.
     */
    public function abandon(Mutation $mutation, string $reason): void
    {
        $this->store->abandon($mutation->id, $reason);
    }

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array
    {
        return $this->store->abandoned();
    }
}
