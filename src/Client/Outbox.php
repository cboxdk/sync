<?php

declare(strict_types=1);

namespace Cbox\Sync\Client;

use Cbox\Sync\Client\Contracts\OutboxStore;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Exceptions\InvalidRequest;
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
    /** @var array<string, array<string, string>> type => [field => the type it points at] */
    private array $references = [];

    /** @var array<string, string> type => the type whose id is its scope */
    private array $scopedBy = [];

    /** @param \Closure(): string $identity */
    public function __construct(
        private readonly OutboxStore $store,
        private readonly Replica $replica,
        private readonly \Closure $identity,
    ) {}

    /**
     * How the application's records point at each other, for the calls that
     * need it and are not given it - so dismissing a refused parent through
     * the outbox directly still takes its children with it.
     *
     * @param  array<string, array<string, string>>  $references
     * @param  array<string, string>  $scopedBy
     */
    public function relatedBy(array $references, array $scopedBy): static
    {
        $this->references = $references;
        $this->scopedBy = $scopedBy;

        return $this;
    }

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
        Identifier::checkMutation($mutation);
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
        // Once more if what was at the head was sent by another process in
        // the meantime.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $mutation = $this->store->head($entityType, $space);
            if ($mutation === null) {
                return null;
            }
            $handed = $this->handOut($mutation);
            if ($handed !== null) {
                return $handed;
            }
        }

        return null;
    }

    /**
     * The write head() would hand out, without handing it out - so a caller can
     * look at what it needs first, a parent's create, say, while the write
     * itself can still be rewritten when that parent is named.
     */
    public function peek(?string $entityType = null, ?string $space = null): ?Mutation
    {
        return $this->store->head($entityType, $space);
    }

    /**
     * One queued write by its identity, numbered for sending - out of queue
     * order. Used for a parent's create, which has to reach the server before
     * the child that points at it. Safe: a create is the first write for its
     * record, so nothing for the same record can be queued ahead of it.
     */
    public function take(string $mutationId): ?Mutation
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $mutation = $this->store->find($mutationId);
            if ($mutation === null) {
                return null;
            }
            $handed = $this->handOut($mutation);
            if ($handed !== null) {
                return $handed;
            }
        }

        return null;
    }

    /**
     * Number a write for sending - once.
     *
     * A write already handed out keeps its number on every resend, and while
     * one on a stream is waiting for its answer that is the write the stream
     * sends, whatever was asked for. Numbering afresh each time let a write
     * sent out of queue order claim a number another write had already used
     * on the server, and a lost response then made one of the two look like a
     * reused identity - abandoned, although it had landed.
     */
    private function handOut(Mutation $mutation): ?Mutation
    {
        // The stream it was queued on. A write queued before streams existed
        // carries the bare device id and goes out on that stream, numbered as
        // it always was - its retry and its depends_on still match.
        $stream = $mutation->replica;
        $space = $mutation->entity->space;

        return $this->store->transaction(function () use ($mutation, $stream, $space): ?Mutation {
            $this->store->lockStream($stream, $space);
            $waiting = $this->store->inFlight($stream, $space);
            if ($waiting !== null) {
                $this->store->countSend($waiting->id);

                return $waiting;
            }
            // Read again inside the transaction: another process may have
            // renamed it, rewritten a reference in it, or sent it and had it
            // acknowledged since it was looked up - and handing out the copy
            // read before would undo that, or send it twice.
            $mutation = $this->store->find($mutation->id);
            if ($mutation === null) {
                return null;
            }
            if ($mutation->replica->id !== $stream->id || $mutation->entity->space !== $space) {
                // Moved to another scope by another process since it was
                // looked up: the lock and the counter taken are the old
                // scope's. Asked again from the start.
                return null;
            }
            $numbered = new Mutation(
                $mutation->id,
                $mutation->entity,
                $stream,
                new MutationSequence($this->store->acknowledged($stream, $space) + 1),
                $mutation->kind,
                $mutation->baseVersion,
                $mutation->operations,
                $mutation->atomic,
                $mutation->dependsOn,
                $mutation->resolution,
                $mutation->expectedVersion,
            );
            $this->store->markSent($numbered);
            $this->store->countSend($numbered->id);

            return $numbered;
        });
    }

    /**
     * Put a write that has not been sent under every name the server has
     * given since it was queued - its record, the scope it lives in, the
     * records it points at. A write queued after its parent was named, by
     * another process say, still carries the handle.
     *
     * @param  array<string, array<string, string>>  $references
     * @param  array<string, string>  $scopedBy
     * @return bool whether anything changed
     */
    public function mapNames(Mutation $mutation, array $references, array $scopedBy): bool
    {
        return $this->store->transaction(function () use ($mutation, $references, $scopedBy): bool {
            if ($this->store->isSent($mutation->id)) {
                return false;
            }
            $type = $mutation->entity->type;
            $changed = false;

            $parentType = $scopedBy[$type] ?? null;
            $space = $parentType === null ? null : $this->nameOfOther($parentType, $mutation->entity->space);
            if ($space !== null && $space !== $mutation->entity->space) {
                $this->store->relabel($type, $mutation->entity->space, $space);
                $changed = true;
            }
            $current = $this->store->find($mutation->id) ?? $mutation;
            if ($this->store->isSent($mutation->id)) {
                // Handed out by another process while this one looked: it may
                // be on the server as it is, and a rewritten copy under the
                // same identity would be refused as reused.
                return $changed;
            }

            // Its own record by the name given in ITS space: a handle is only
            // unique within one, and another tenant's record of the same
            // handle is not this one.
            $id = $mutation->kind === MutationKind::Create || $this->queuedCreate($type, $current->entity->id) !== null
                ? null
                : $this->store->nameOf($current->entity)?->id;
            if ($id !== null && $id !== $current->entity->id) {
                $this->store->rekey($current->entity, new EntityKey($current->entity->space, $type, $id));
                $changed = true;
                $current = $this->store->find($mutation->id) ?? $current;
            }

            $operations = [];
            $rewritten = false;
            foreach ($current->operations as $operation) {
                $target = $references[$type][$operation->field] ?? null;
                $value = $operation->value->exists ? $operation->value->value() : null;
                $name = $target !== null && is_string($value) ? $this->nameOfOther($target, $value) : null;
                if ($name !== null && $name !== $value) {
                    $operation = FieldOperation::set($operation->field, $name);
                    $rewritten = true;
                }
                $operations[] = $operation;
            }
            if ($rewritten) {
                $this->store->replace($current->rebased($current->baseVersion, $operations));
                $changed = true;
            }

            return $changed;
        });
    }

    /**
     * The name a record this write points at became - unless a create for
     * that handle is still queued, which makes it a NEW record reusing the
     * handle, not the one named before.
     */
    private function nameOfOther(string $entityType, string $handle): ?string
    {
        return $this->queuedCreate($entityType, $handle) !== null ? null : $this->store->namedAs($entityType, $handle);
    }

    /** A create for this record still waiting to be sent, or null. */
    public function queuedCreate(string $entityType, string $entityId): ?Mutation
    {
        $first = $this->store->firstFor($entityType, $entityId);

        return $first !== null && $first->kind === MutationKind::Create ? $first : null;
    }

    /** Whether this record's create was abandoned - it will not exist unless the application requeues it. */
    public function createAbandoned(string $entityType, string $entityId): bool
    {
        foreach ($this->store->abandoned() as $entry) {
            $mutation = $entry['mutation'];
            if ($mutation->kind === MutationKind::Create && $mutation->entity->type === $entityType && $mutation->entity->id === $entityId) {
                return true;
            }
        }

        return false;
    }

    /** Move queued writes of a type from one scope label to another. */
    public function relabel(string $entityType, string $from, string $to): void
    {
        $this->store->transaction(fn () => $this->store->relabel($entityType, $from, $to));
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
        if ($from->space !== $to->space || $from->type !== $to->type) {
            // The server names a record; it never moves it. A rename that
            // changes the space or type is not one, and a durable name for it
            // would have nowhere to say so.
            throw new InvalidRequest('A rename keeps the space and type; only the id changes');
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
            // pull_required is answered only for an identity with no receipt.
            $this->store->clearSends($rebased->id);
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
     * @param  array<string, string>  $scopedBy  entity type => the type whose id is its scope:
     *                                           writes queued under the handle move to the name
     */
    public function acknowledged(Mutation $mutation, ?EntityKey $named = null, array $references = [], array $scopedBy = []): void
    {
        $this->store->transaction(function () use ($mutation, $named, $references, $scopedBy): void {
            $this->store->setAcknowledged($mutation->replica, $mutation->entity->space, $mutation->sequence->value);
            $this->store->acknowledge($mutation->id);
            if ($named !== null && ! $named->equals($mutation->entity)) {
                $this->store->rekey($mutation->entity, $named);
                $this->rewriteReferences($mutation->entity, $named->id, $references);
                foreach ($scopedBy as $type => $parentType) {
                    if ($parentType === $named->type) {
                        $this->store->relabel($type, $mutation->entity->id, $named->id);
                    }
                }
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
     * the created record's type, is touched - two types may well use the same
     * handle, so the type is what tells them apart.
     *
     * @param  array<string, array<string, string>>  $references
     */
    private function rewriteReferences(EntityKey $handle, string $name, array $references): void
    {
        if ($references === []) {
            return;
        }
        // Only the types that have a field pointing at the created record's
        // type, in its space - narrowed by the store, not by decoding the
        // whole backlog.
        $types = [];
        foreach ($references as $type => $fields) {
            if (in_array($handle->type, $fields, true)) {
                $types[] = $type;
            }
        }
        // Every scope: a handle is the device's own, and a child in one scope
        // may well point at a parent created in another.
        foreach ($this->store->queued($types) as $queued) {
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

    /**
     * Record the name a record created on this device turned out to have -
     * one whose create may have landed but whose answer never came, found on
     * the server by the application. Queued writes are moved to it and their
     * references rewritten, exactly as when the server names it, and writes
     * abandoned as parent_unknown can then be requeued under it.
     *
     * @param  array<string, array<string, string>>|null  $references  as for acknowledged(); null for what relatedBy() set
     * @param  array<string, string>|null  $scopedBy  as for acknowledged(); null for what relatedBy() set
     */
    public function found(EntityKey $handle, string $name, ?array $references = null, ?array $scopedBy = null): void
    {
        $references ??= $this->references;
        $scopedBy ??= $this->scopedBy;
        $named = new EntityKey($handle->space, $handle->type, $name);
        $this->store->transaction(function () use ($handle, $named, $references, $scopedBy): void {
            $this->store->rekey($handle, $named);
            $this->rewriteReferences($handle, $named->id, $references);
            foreach ($scopedBy as $type => $parentType) {
                if ($parentType === $handle->type) {
                    $this->store->relabel($type, $handle->id, $named->id);
                }
            }
        });
    }

    /** Where writes for this record are still queued, or null. */
    public function queuedKey(string $entityType, string $entityId): ?EntityKey
    {
        return $this->store->firstFor($entityType, $entityId)?->entity;
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
        // Exactly, down or up: the server that answered is the authority on
        // where its stream is, after either side restored a backup. And only
        // if the counter is still where this attempt numbered from - two
        // deliveries can get the same answer, and the late one must not wind
        // back what the other has since sent.
        $this->store->transaction(function () use ($mutation, $acknowledgedSequence): void {
            // The server has not got it at that number: it goes again under a
            // new one. Only if this answer is still the current one - a late
            // copy of it, arriving after the write went out again, must not
            // take the newer attempt's number away from it.
            if ($this->store->resetAcknowledged($mutation->replica, $mutation->entity->space, $acknowledgedSequence, $mutation->sequence->value - 1)) {
                $this->store->unmarkSent($mutation->id);
                // A gap is answered only for an identity with no receipt: no
                // sending of it landed.
                $this->store->clearSends($mutation->id);
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
     * The answer to receipt_pruned: this write may already have been applied
     * and nobody can say. It leaves the queue as abandoned, so the application
     * can tell the user, and its OWN position counts as acknowledged - the next
     * write on the stream goes out at the next position, where a replay of an
     * older one is again answered from its receipt or refused the same way.
     * Jumping to the server's position instead renumbered those replays past
     * the pruned range, and they were applied a second time.
     */
    public function settledUnknown(Mutation $mutation, ?int $serverAcknowledged = null): int
    {
        return $this->store->transaction(function () use ($mutation, $serverAcknowledged): int {
            $space = $mutation->entity->space;
            $this->store->abandon($mutation->id, 'receipt_pruned');
            if ($serverAcknowledged === null || $serverAcknowledged <= $mutation->sequence->value) {
                $this->store->setAcknowledged($mutation->replica, $space, $mutation->sequence->value);

                return 1;
            }
            // The server has seen MORE of this stream than this device knows:
            // the device's state was restored from a backup, or its id reused
            // by a new installation. Every write still queued on the stream may
            // be one it had sent before, applied at a position whose answer is
            // gone - so each is settled the same way, now, together. Burning
            // one position per write instead left a restored device unable to
            // write anything new until it had crawled through the whole pruned
            // range. New writes then go out after where the server really is.
            //
            // Only if this answer is still the current one: a late copy of it,
            // arriving after the stream moved on, must not settle writes queued
            // since - which never went anywhere.
            if (! $this->store->resetAcknowledged($mutation->replica, $space, $serverAcknowledged, $mutation->sequence->value - 1)) {
                return 1;
            }
            $settled = 1;
            // Every type: a stream from before streams were split by type
            // carries several, and a write of another type left behind would
            // go out past the server's position and could apply twice.
            foreach ($this->store->queuedOn($mutation->replica, $space) as $queued) {
                $this->store->abandon($queued->id, 'receipt_pruned');
                $settled++;
            }

            return $settled;
        });
    }

    /**
     * The server answered this sending of the write - whatever it said. Only
     * sendings that got no answer at all leave it possibly on the server; one
     * answered "busy" or "sign in again" did not land, and counting it as if
     * it might have blocked the requeue a user is entitled to.
     */
    public function answered(Mutation $mutation): void
    {
        $this->store->countAnswer($mutation->id);
    }

    /**
     * The server processed the write and refused it - rejected, invalid, a
     * precondition that failed. Its position is acknowledged like any answer,
     * and it is kept as abandoned under that reason rather than dropped: the
     * push's own report of it is lost the moment anything after it fails or
     * the process dies, and a refused create has to go on holding back the
     * writes that depend on it.
     */
    public function refused(Mutation $mutation, string $reason): void
    {
        $this->store->transaction(function () use ($mutation, $reason): void {
            $this->store->setAcknowledged($mutation->replica, $mutation->entity->space, $mutation->sequence->value);
            // The server keeps an answer for every write it processed, and a
            // resend of the same identity gets that answer back: this refusal
            // proves no sending of it applied.
            $this->store->clearSends($mutation->id);
            $this->store->abandon($mutation->id, $reason);
        });
    }

    /**
     * Terminal for this mutation: it can never be sent again under this
     * identity, so it leaves the queue rather than blocking everything behind
     * it forever. The application has to be told.
     */
    public function abandon(Mutation $mutation, string $reason, bool $answered = true): void
    {
        $this->store->transaction(function () use ($mutation, $reason, $answered): void {
            if ($answered && $this->store->isSent($mutation->id)) {
                // Refused on an answer: that sending is accounted for. One
                // given up on without an answer still may have landed.
                $this->store->countAnswer($mutation->id);
            }
            $this->store->abandon($mutation->id, $reason);
        });
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
    /**
     * @param  array<string, array<string, string>>|null  $references  as for acknowledged(); null for what relatedBy() set
     * @param  array<string, string>|null  $scopedBy  as for acknowledged(); null for what relatedBy() set
     */
    public function requeue(string $mutationId, ?array $references = null, ?array $scopedBy = null, bool $evenIfItMayHaveLanded = false): ?Mutation
    {
        $references ??= $this->references;
        $scopedBy ??= $this->scopedBy;

        return $this->store->transaction(function () use ($mutationId, $references, $scopedBy, $evenIfItMayHaveLanded): ?Mutation {
            foreach ($this->store->abandoned() as $entry) {
                $old = $entry['mutation'];
                if ($old->id !== $mutationId) {
                    continue;
                }
                if ((in_array($entry['reason'], self::MAY_HAVE_LANDED, true) || $this->store->unanswered($old->id) > 0) && ! $evenIfItMayHaveLanded) {
                    // Sent again under a new identity, a write the server may
                    // already hold is applied twice - a create becomes two
                    // records. Only someone who has checked may say so.
                    // So may a write sent more than once: an earlier attempt got
                    // no answer, and the refusal came on a resend - a create
                    // the server applied, then refused to answer again once
                    // the user lost access, is a second record if requeued.
                    throw new InvalidRequest(sprintf('Write %s may already be on the server (%s, %d sendings unanswered); requeue it only after checking, with $evenIfItMayHaveLanded.', $mutationId, $entry['reason'], $this->store->unanswered($old->id)));
                }
                $this->store->dismiss($old->id);

                // Written while handles were still handles. Anything named since
                // - the record, the scope it lives in, the records it points at -
                // goes back under the name the server gave it.
                $type = $old->entity->type;
                $parentType = $scopedBy[$type] ?? null;
                $space = $parentType === null ? $old->entity->space : ($this->nameOfOther($parentType, $old->entity->space) ?? $old->entity->space);
                $id = $this->store->nameOf($old->entity)->id ?? $old->entity->id;
                $operations = [];
                $mapped = false;
                foreach ($old->operations as $operation) {
                    $target = $references[$type][$operation->field] ?? null;
                    $value = $operation->value->exists ? $operation->value->value() : null;
                    $named = $target !== null && is_string($value) ? $this->nameOfOther($target, $value) : null;
                    $mapped = $mapped || ($named !== null && $named !== $value);
                    $operations[] = $named === null ? $operation : FieldOperation::set($operation->field, $named);
                }

                $key = new EntityKey($space, $type, $id);
                if ($entry['reason'] === 'parent_unknown' && ! $evenIfItMayHaveLanded && $key->equals($old->entity) && ! $mapped) {
                    // Its parent may exist, but no name for it has been given:
                    // sent now, it would carry the handle the server never
                    // heard of. Record the name with found() first.
                    throw new InvalidRequest(sprintf('Write %s needs a record whose name is not known yet; record it with found() and requeue again.', $mutationId));
                }

                return $this->append($key, $old->kind, $operations, $old->baseVersion->value, $old->atomic, $old->resolution, null);
            }

            return null;
        });
    }

    /** Refusals after which the write may nonetheless be on the server. */
    private const MAY_HAVE_LANDED = ['receipt_pruned', 'protocol_violation'];

    /**
     * The application has told the user; stop reporting it.
     *
     * A dismissed create takes the writes that depend on it along: the record
     * will never exist, and once its abandoned entry is gone nothing else says
     * so - its edits, and children pointing at it or living under it, would go
     * out carrying a handle the server never heard of. They are abandoned as
     * parent_abandoned (parent_unknown when the create may have landed), for
     * the application to report in turn; the count of them is returned.
     *
     * @param  array<string, array<string, string>>|null  $references  as for acknowledged(); null for what relatedBy() set
     * @param  array<string, string>|null  $scopedBy  as for acknowledged(); null for what relatedBy() set
     */
    public function dismiss(string $mutationId, ?array $references = null, ?array $scopedBy = null): int
    {
        $references ??= $this->references;
        $scopedBy ??= $this->scopedBy;

        return $this->store->transaction(function () use ($mutationId, $references, $scopedBy): int {
            // One row, not every abandoned write decoded: a restored stream
            // settles hundreds, and an application dismisses each in turn.
            $entry = $this->store->abandonedOne($mutationId);
            $cascaded = 0;
            if ($entry !== null) {
                $old = $entry['mutation'];
                if ($old->kind === MutationKind::Create && $this->queuedCreate($old->entity->type, $old->entity->id) === null) {
                    // Whether or not the record exists, this device never
                    // learned its name, so the writes that need it would go
                    // out carrying a handle the server never heard of. When it
                    // may exist they are parent_unknown: find it, then requeue
                    // them under its name.
                    $mayHaveLanded = in_array($entry['reason'], self::MAY_HAVE_LANDED, true) || $this->store->unanswered($old->id) > 0;
                    foreach ($this->dependents($old->entity, $references, $scopedBy) as $dependent) {
                        $this->store->abandon($dependent->id, $mayHaveLanded ? 'parent_unknown' : 'parent_abandoned');
                        $cascaded++;
                    }
                }
            }
            $this->store->dismiss($mutationId);

            return $cascaded;
        });
    }

    /**
     * Unsent writes that need this record to exist: its own edits, records
     * living under it, records whose declared references point at it.
     *
     * @param  array<string, array<string, string>>  $references
     * @param  array<string, string>  $scopedBy
     * @return list<Mutation>
     */
    private function dependents(EntityKey $record, array $references, array $scopedBy): array
    {
        $types = [$record->type];
        foreach ($scopedBy as $type => $parentType) {
            if ($parentType === $record->type) {
                $types[] = $type;
            }
        }
        foreach ($references as $type => $fields) {
            if (in_array($record->type, $fields, true)) {
                $types[] = $type;
            }
        }
        $found = [];
        foreach ($this->store->queued(array_values(array_unique($types))) as $queued) {
            $type = $queued->entity->type;
            $depends = ($type === $record->type && $queued->entity->id === $record->id && $queued->entity->space === $record->space)
                || (($scopedBy[$type] ?? null) === $record->type && $queued->entity->space === $record->id);
            foreach ($queued->operations as $operation) {
                if (($references[$type][$operation->field] ?? null) === $record->type && $operation->value->exists && $operation->value->value() === $record->id) {
                    $depends = true;
                }
            }
            if ($depends) {
                $found[] = $queued;
            }
        }

        return $found;
    }

    /** @return list<array{mutation: Mutation, reason: string}> */
    public function abandoned(): array
    {
        return $this->store->abandoned();
    }
}
