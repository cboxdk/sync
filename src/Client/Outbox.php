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
     * Queue a write.
     *
     * $dependsOn names an earlier mutation of this device's, and is how two
     * offline edits to the same field say that the second knows about the
     * first. Without it the second conflicts with the device's own earlier
     * edit, which is never what the user meant.
     *
     * @param  list<FieldOperation>  $operations
     */
    public function queue(EntityKey $entity, MutationKind $kind, array $operations, int $baseVersion, bool $atomic = true, ?Resolution $resolution = null, ?string $dependsOn = null): Mutation
    {
        return $this->store->transaction(function () use ($entity, $kind, $operations, $baseVersion, $atomic, $resolution, $dependsOn): Mutation {
            $mutation = new Mutation(
                ($this->identity)(),
                $entity,
                $this->replica,
                // A placeholder. The real sequence is assigned when the
                // mutation is handed out, so one that is never accepted does
                // not consume a number the server will wait for forever.
                new MutationSequence(1),
                $kind,
                new RecordVersion($baseVersion),
                $operations,
                $atomic,
                $dependsOn,
                $resolution,
            );
            $this->store->append($mutation);

            return $mutation;
        });
    }

    /**
     * The next mutation to send, numbered for this attempt.
     *
     * The sequence is assigned here rather than at queue time. A mutation that
     * the transport refuses - a field the server will not accept, say - never
     * reaches the engine, so if it had already taken a number the server would
     * wait for that number forever and every later write would come back as a
     * gap. Numbering at send time makes that hole impossible.
     */
    public function head(?string $entityType = null): ?Mutation
    {
        $mutation = $this->store->head($entityType);
        if ($mutation === null) {
            return null;
        }

        return new Mutation(
            $mutation->id,
            $mutation->entity,
            $mutation->replica,
            new MutationSequence($this->store->acknowledged($this->replica, $mutation->entity->space) + 1),
            $mutation->kind,
            $mutation->baseVersion,
            $mutation->operations,
            $mutation->atomic,
            $mutation->dependsOn,
            $mutation->resolution,
            $mutation->expectedVersion,
        );
    }

    public function pending(?string $entityType = null): int
    {
        return $this->store->pending($entityType);
    }

    /** The server processed it. Whether it applied, conflicted or was rejected, it is done. */
    public function acknowledged(Mutation $mutation): void
    {
        $this->store->transaction(function () use ($mutation): void {
            $this->store->setAcknowledged($this->replica, $mutation->entity->space, $mutation->sequence->value);
            $this->store->acknowledge($mutation->id);
        });
    }

    /**
     * The server has not seen everything before this one.
     *
     * Nothing is dropped: the queue holds only what has not been acknowledged,
     * and sequences are assigned at send time, so the next attempt simply
     * numbers from where the server says it is. Clearing the queue here would
     * discard writes the server never received.
     */
    /**
     * The space comes from the mutation the server answered about, because an
     * acknowledgement stream belongs to one space and a device may be writing
     * to several.
     */
    public function resumeAfter(Mutation $mutation, int $acknowledgedSequence): void
    {
        $this->store->setAcknowledged($this->replica, $mutation->entity->space, $acknowledgedSequence);
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
