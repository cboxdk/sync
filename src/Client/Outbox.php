<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\Identifier;
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
        return $this->store->transaction(fn (): Mutation => $this->append($entity, $kind, $operations, $baseVersion, $atomic, $resolution, $dependsOn));
    }

    /** @param list<FieldOperation> $operations */
    private function append(EntityKey $entity, MutationKind $kind, array $operations, int $baseVersion, bool $atomic, ?Resolution $resolution, ?string $dependsOn): Mutation
    {
        $mutation = new Mutation(
            ($this->identity)(),
            $entity,
            // Stamped now and kept: the stream a write travels on is part of
            // its identity on the server, so it must not change between a send
            // and its retry - not even across an upgrade that changes how new
            // writes are routed.
            $this->stream($entity),
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
    public function head(?string $entityType = null, ?string $space = null): ?Mutation
    {
        $mutation = $this->store->head($entityType, $space);
        if ($mutation === null) {
            return null;
        }

        // The stream it was queued on. A write queued before streams existed
        // carries the bare device id and goes out on that stream, numbered as
        // it always was - its retry and its depends_on still match.
        $stream = $mutation->replica;

        return new Mutation(
            $mutation->id,
            $mutation->entity,
            $stream,
            new MutationSequence($this->store->acknowledged($stream, $mutation->entity->space) + 1),
            $mutation->kind,
            $mutation->baseVersion,
            $mutation->operations,
            $mutation->atomic,
            $mutation->dependsOn,
            $mutation->resolution,
            $mutation->expectedVersion,
        );
    }

    /**
     * The record this queue was writing to turned out to be called something
     * else.
     *
     * A create carries a handle the device made up, and the server answers with
     * the name it gave the record. Everything queued behind that create still
     * refers to the handle and would be a write to a record that does not
     * exist.
     *
     * Only the key is renamed. A field VALUE holding the handle - a child
     * carrying its parent's id - belongs to the application, and no queue can
     * know which fields are references, so the rename is reported to the
     * application rather than quietly half-done.
     */
    public function rekey(EntityKey $from, EntityKey $to): void
    {
        if ($from->equals($to)) {
            return;
        }

        $this->store->transaction(function () use ($from, $to): void {
            $this->store->rekey($from, $to);
        });
    }

    /**
     * Send a queued write again, rethought against what the device now knows.
     *
     * The answer to pull_required. $baseVersion is the version the server
     * reported with the refusal - not whatever the device pulled afterwards.
     * A newer pull can include changes to fields the refusal never mentioned,
     * and basing on it would overwrite them without anyone having looked.
     *
     * An empty list of operations is allowed: the device decided the other
     * edit wins everywhere, and the server records that as a no-op.
     *
     * @param  list<FieldOperation>  $operations
     */
    public function rebase(Mutation $mutation, RecordVersion $baseVersion, array $operations): Mutation
    {
        $rebased = $mutation->rebased($baseVersion, $operations);
        $this->store->transaction(function () use ($rebased): void {
            $this->store->replace($rebased);
        });

        return $rebased;
    }

    public function pending(?string $entityType = null): int
    {
        return $this->store->pending($entityType);
    }

    /**
     * The server processed it. Whether it applied, conflicted or was rejected, it is done.
     *
     * $named is the name the server gave a record this mutation created. The
     * rename of everything queued behind it happens in the SAME transaction as
     * the acknowledgement: in two, a crash between them removed the create and
     * left updates addressed to a handle nothing could resolve any more.
     */
    /**
     * @param  array<string, array<string, string>>  $references  entity type => [field => the type
     *                                                            it points at], rewritten too
     */
    public function acknowledged(Mutation $mutation, ?EntityKey $named = null, array $references = []): void
    {
        $this->store->transaction(function () use ($mutation, $named, $references): void {
            $this->store->setAcknowledged($mutation->replica, $mutation->entity->space, $mutation->sequence->value);
            $this->store->acknowledge($mutation->id);
            if ($named !== null && ! $named->equals($mutation->entity)) {
                $this->store->rekey($mutation->entity, $named);
                $this->rewriteReferences($mutation->entity, $named->id, $references);
            }
        });
    }

    /**
     * Point queued writes that refer to a handle at the name it became.
     *
     * A child created offline under a parent that was also created offline
     * carries the parent's HANDLE in a field. The key rename cannot reach it -
     * no queue can know which fields are references - so the application says
     * which ones are, and they are rewritten in the same step, before the child
     * is sent. Without it the child reaches the server pointing at an id that
     * never existed.
     *
     * Only a value that is exactly the handle, in a field declared to point at
     * the created record's type, in the same space, is touched - handles are
     * the device's own and two types may well use the same one.
     *
     * @param  array<string, array<string, string>>  $references
     */
    private function rewriteReferences(EntityKey $handle, string $name, array $references): void
    {
        if ($references === []) {
            return;
        }
        foreach ($this->store->queued() as $queued) {
            if ($queued->entity->space !== $handle->space) {
                continue;
            }
            $fields = array_keys(array_filter($references[$queued->entity->type] ?? [], fn (string $target): bool => $target === $handle->type));
            if ($fields === []) {
                continue;
            }
            $changed = false;
            $operations = [];
            foreach ($queued->operations as $operation) {
                if (in_array($operation->field, $fields, true) && $operation->value->exists && $operation->value->value() === $handle->id) {
                    $operation = FieldOperation::set($operation->field, $name);
                    $changed = true;
                }
                $operations[] = $operation;
            }
            if ($changed) {
                $this->store->replace($queued->rebased($queued->baseVersion, $operations));
            }
        }
    }

    /** What a record this device created under a handle is actually called; null until the server has said. */
    public function nameOf(EntityKey $handle): ?EntityKey
    {
        return $this->store->nameOf($handle);
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
        // Exactly, and downward too. A gap is only ever reported when this
        // device is AHEAD of the server - a server restored from a backup, a
        // device database restored from a newer one - so a counter that could
        // only rise would resend the same number and get the same gap forever.
        // Compare-and-set: only if the counter is still where this attempt
        // numbered from. Two deliveries can get the same gap; the one that
        // resends and succeeds must not have the other wind it back after.
        $this->store->transaction(function () use ($mutation, $acknowledgedSequence): void {
            if ($this->store->acknowledged($mutation->replica, $mutation->entity->space) === $mutation->sequence->value - 1) {
                $this->store->resetAcknowledged($mutation->replica, $mutation->entity->space, $acknowledgedSequence);
            }
        });
    }

    /**
     * The stream a queued write travels on: one per entity type and space.
     *
     * The server numbers writes per replica per SPACE, where the space is its
     * own mapping of type and scope - which this device cannot see. Two of
     * this device's streams landing in one server space would then share a
     * counter on one side and not the other, and the first lost response makes
     * a write collide with a number already used and be refused for good.
     * Giving each (type, space) its own replica identity makes the two sides
     * agree whatever the server's mapping is.
     *
     * Bounded, because the server stores replica identities in fixed-width
     * columns, and stable, because changing it would restart the numbering.
     */
    public function stream(EntityKey $entity): Replica
    {
        // The device id is kept readable where it fits; one too long to leave
        // room for the suffix is replaced by its hash, so every valid device
        // id still yields a valid stream id.
        $device = strlen($this->replica->id) <= Identifier::MAX_LENGTH - 17 ? $this->replica->id : hash('sha256', $this->replica->id);

        return new Replica($device.'#'.substr(hash('sha256', $entity->type."\0".$entity->space), 0, 16));
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

    /**
     * Send an abandoned write again, as a new write.
     *
     * For a refusal that was about the moment rather than the write - the
     * session had expired, the user lacked a permission they have since been
     * given. A new identity, because the server may hold a receipt for the old
     * one, and at the back of the queue, because it is being made now.
     *
     * Null when no abandoned write has that id.
     */
    public function requeue(string $mutationId): ?Mutation
    {
        return $this->store->transaction(function () use ($mutationId): ?Mutation {
            foreach ($this->store->abandoned() as $entry) {
                $old = $entry['mutation'];
                if ($old->id !== $mutationId) {
                    continue;
                }
                $this->store->dismiss($old->id);

                return $this->append($old->entity, $old->kind, $old->operations, $old->baseVersion->value, $old->atomic, $old->resolution, null);
            }

            return null;
        });
    }

    /** The application has told the user; stop reporting it. */
    public function dismiss(string $mutationId): void
    {
        $this->store->dismiss($mutationId);
    }

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array
    {
        return $this->store->abandoned();
    }
}
