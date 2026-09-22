<?php

declare(strict_types=1);

namespace Cbox\Sync;

use Cbox\Sync\Contracts\CommitObserver;
use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Contracts\IdGenerator;
use Cbox\Sync\Contracts\Ledger;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\Candidate;
use Cbox\Sync\Data\Change;
use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Data\ConflictGroup;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldConflict;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\FieldState;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\MutationResult;
use Cbox\Sync\Data\PreconditionFailure;
use Cbox\Sync\Data\Provenance;
use Cbox\Sync\Data\Receipt;
use Cbox\Sync\Data\Submission;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Enums\OnConflict;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Observers\NullCommitObserver;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\Support\UuidV7Generator;
use Cbox\Sync\Validation\AcceptAll;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldVersion;
use Cbox\Sync\ValueObjects\Identifier;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

class Engine
{
    public function __construct(private Store $store, private ConflictResolver $resolver = new PreserveConflict, private IdGenerator $ids = new UuidV7Generator, private EntityValidator $validator = new AcceptAll, private CommitObserver $observer = new NullCommitObserver) {}

    /**
     * @param  OnConflict  $onConflict  how the writer wants a conflict the resolver would
     *                                  preserve to be handled. Not part of the mutation's
     *                                  identity: a refusal stores nothing, so the same
     *                                  mutation may come back rebased on newer knowledge.
     */
    public function process(Mutation $mutation, AdapterContext $context = new AdapterContext, OnConflict $onConflict = OnConflict::Resolve): MutationResult
    {
        return $this->submit($mutation, $context, $onConflict)->result;
    }

    /** As process(), also saying whether this call is the one that wrote it. */
    public function submit(Mutation $mutation, AdapterContext $context = new AdapterContext, OnConflict $onConflict = OnConflict::Resolve): Submission
    {
        Identifier::checkMutation($mutation);
        $committed = null;
        $result = $this->store->transaction($mutation->entity->space, function (Ledger $ledger) use ($mutation, $context, $onConflict, &$committed): MutationResult {
            return $this->within($ledger, $mutation, $context, $onConflict, $committed);
        });
        $this->announce($mutation->entity->space, $committed);

        return new Submission($result, $committed);
    }

    /**
     * A write the host makes itself - a model save, an admin screen - with
     * everything that depends on the current state decided INSIDE the space
     * lock: whether it is a create or an update, the version it is based on,
     * and the stream position it takes.
     *
     * Deciding those before the lock raced any other writer to the same space:
     * two saves read the same next position and one was refused, and under
     * MySQL's REPEATABLE READ a host transaction's snapshot kept every retry
     * reading the same stale number.
     *
     * $asCreate is what to write when the log turns out to hold no record -
     * every field, for a row that existed before it was synced - where
     * $operations carries only what this write changed.
     *
     * $echoOf names a mutation this write is the direct consequence of: what
     * the host's table made of a device's write. Its versions are added to
     * that mutation's answer, so the writer's next edit, based on that answer,
     * does not conflict with its own write's echo.
     *
     * @param  list<FieldOperation>  $operations
     * @param  list<int>|null  $acceptVersions  versions the caller accepts the record at - If-Match - or null for none
     * @param  int|null  $claimedBase  the version the caller says it was looking at, for field-level checks
     * @param  list<FieldOperation>|null  $asCreate
     * @return MutationResult|null null for a delete of a record the log never held
     */
    public function recordTrusted(EntityKey $entity, Replica $replica, array $operations, bool $deleting, AdapterContext $context = new AdapterContext, ?array $acceptVersions = null, ?int $claimedBase = null, OnConflict $onConflict = OnConflict::Pull, ?array $asCreate = null, ?string $echoOf = null): ?MutationResult
    {
        $committed = null;
        $mutationSpace = $entity->space;
        $result = $this->store->transaction($mutationSpace, function (Ledger $ledger) use ($entity, $replica, $operations, $deleting, $context, $acceptVersions, $claimedBase, $onConflict, $asCreate, $echoOf, &$committed): ?MutationResult {
            $current = $ledger->record($entity);
            if ($deleting && ($current === null || $current->deleted)) {
                return null;
            }
            $kind = match (true) {
                $deleting => MutationKind::Delete,
                $current === null => MutationKind::Create,
                default => MutationKind::Update,
            };
            $stored = $current?->version->value ?? 0;
            $expected = null;
            if ($acceptVersions !== null) {
                // A record the log does not hold is at version 0: If-Match
                // naming any version of it fails, as HTTP says it must.
                // An empty list can never match, so it names the next version.
                $expected = in_array($stored, $acceptVersions, true) ? $stored : ($acceptVersions[0] ?? $stored + 1);
            }
            if ($kind === MutationKind::Create && $asCreate !== null) {
                $operations = $asCreate;
            }
            $base = $kind === MutationKind::Create ? 0 : min($expected ?? $claimedBase ?? $stored, $stored);

            $mutation = new Mutation(
                'trusted-'.$this->ids->generate(),
                $entity,
                $replica,
                new MutationSequence($ledger->acknowledged($replica) + 1),
                $kind,
                new RecordVersion($base),
                $kind === MutationKind::Delete ? [] : $operations,
                expectedVersion: $expected === null ? null : new RecordVersion($expected),
            );
            Identifier::checkMutation($mutation);

            $result = $this->within($ledger, $mutation, $context, $onConflict, $committed);
            if ($echoOf !== null && in_array($result->status, [MutationStatus::Applied, MutationStatus::Partial], true)) {
                $this->amendWithEcho($ledger, $echoOf, $entity, $result);
            }

            return $result;
        });
        $this->announce($mutationSpace, $committed);

        return $result;
    }

    /**
     * Fold an echo's versions into the answer of the write that caused it:
     * the record version it reached, and each field it set as a version the
     * writer now knows. A replay of that write is answered the same way.
     */
    private function amendWithEcho(Ledger $ledger, string $cause, EntityKey $entity, MutationResult $echo): void
    {
        $receipt = $ledger->receipt($cause);
        if ($receipt === null || $receipt->mutation->entity->key() !== $entity->key()) {
            return;
        }
        $known = $receipt->result->acceptedVersions;
        foreach ($echo->acceptedVersions as $field => $version) {
            // Only what the echo itself wrote. A field it sent unchanged is
            // reported at the version that last wrote it - which can be
            // another writer's, whose edit the device has not seen.
            if ($version->value === $echo->recordVersion->value) {
                $known[$field] = $version;
            }
        }
        $was = $receipt->result;
        // The record version only when nothing came between the write and its
        // echo: a device basing its next edit on a version that includes
        // another writer's change would overwrite that change unseen.
        $version = $echo->recordVersion->value === $was->recordVersion->value + 1 ? $echo->recordVersion : $was->recordVersion;
        $ledger->amendReceipt(new Receipt($receipt->mutation, new MutationResult(
            $was->status, $version, $was->commitSequence, $was->reason, $was->decisions, $known,
            $was->conflictGroupIds, $was->acknowledgedSequence, $was->preconditionFailure, $was->validation, $was->conflicts,
        ), $receipt->provenance));
    }

    private function announce(string $space, ?CommitSequence $committed): void
    {
        // After the transaction, never inside it: a rollback must not announce a
        // write that did not happen. A replay, a mutation gap or a refusal
        // appends no commit and so signals nothing.
        if ($committed !== null) {
            try {
                $this->observer->committed($space, $committed);
            } catch (\Throwable) {
                // A notification cannot change the outcome of a write that has
                // already happened. Letting it through would fail the caller's
                // push for a mutation that is durably stored, so the client
                // would retry, meet its own receipt, and be told the same thing
                // again - noise for a write that was always fine.
                //
                // Delivery is at-most-once by contract and the reader's cursor
                // is the backstop, so a broken notifier costs promptness, not
                // correctness. Reporting it is the implementation's job: it is
                // the half that has a logger.
            }
        }
    }

    private function within(Ledger $ledger, Mutation $mutation, AdapterContext $context, OnConflict $onConflict, ?CommitSequence &$committed): MutationResult
    {
        $receipt = $ledger->receipt($mutation->id);
        $ack = $ledger->acknowledged($mutation->replica);
        if ($receipt === null && $mutation->sequence->value <= $ack && $mutation->sequence->value > $ledger->prunedThrough($mutation->replica)) {
            // A position already used and no receipt: most likely a replay
            // whose first delivery committed after this transaction's
            // snapshot was taken - a host transaction that read before the
            // space lock. The position's answer is asked for again, as the
            // latest committed state, before it is judged a writer that fell
            // behind - and only if it is this mutation's.
            $atPosition = $ledger->receiptAt($mutation->replica, $mutation->sequence->value);
            if ($atPosition !== null && $atPosition->mutation->id === $mutation->id) {
                $receipt = $atPosition;
            }
        }
        if ($receipt !== null) {
            if ($receipt->mutation->fingerprint() !== $mutation->fingerprint() || $receipt->provenance->actorId !== $context->actorId || $receipt->provenance->integrationId !== $context->integrationId) {
                throw new ProtocolException('Mutation identity reused with different content');
            }

            return $receipt->result;
        }
        if ($mutation->sequence->value <= $ack && $mutation->sequence->value <= $ledger->prunedThrough($mutation->replica)) {
            // A position whose receipt was pruned: this could be a replay
            // of a write that was applied, and renumbering it would apply
            // it twice. So this mutation is final, and the writer takes
            // THIS position as acknowledged (Outbox::settledUnknown) - not
            // the stream's position, which would renumber the replays
            // queued behind it above the pruned range.
            return new MutationResult(MutationStatus::ReceiptPruned, reason: 'receipt_pruned', acknowledgedSequence: $ack);
        }
        if ($mutation->sequence->value <= $ack) {
            // A number this stream already used, under an identity with no
            // receipt: the writer is BEHIND - its state restored from an
            // older backup. Answered like a gap, with where the stream really
            // is, so it renumbers upward and goes on. Refusing it as a
            // protocol violation abandoned every write after the restore,
            // permanently, one by one.
            return new MutationResult(MutationStatus::MutationGap, reason: 'sequence_behind', acknowledgedSequence: $ack);
        }
        if ($mutation->sequence->value !== $ack + 1) {
            return new MutationResult(MutationStatus::MutationGap, reason: 'expected_sequence_'.($ack + 1), acknowledgedSequence: $ack);
        }
        $record = $ledger->record($mutation->entity);
        $origin = Provenance::fromMutation($mutation, $context);
        $actualVersion = $record->version ?? new RecordVersion;
        if ($mutation->expectedVersion !== null && $mutation->expectedVersion->value !== $actualVersion->value) {
            $outcome = new MutationResult(
                MutationStatus::PreconditionFailed, $actualVersion,
                reason: 'revision_mismatch',
                preconditionFailure: new PreconditionFailure($mutation->expectedVersion, $actualVersion),
            );
        } else {
            if ($mutation->baseVersion->value > $actualVersion->value) {
                throw new InvalidRequest('Future base version');
            }
            $knowledge = $this->dependency($ledger, $mutation);
            $ledger->beginDraft();
            $outcome = $this->apply($ledger, $mutation, $record, $knowledge, $context, $onConflict);
            if ($outcome->status === MutationStatus::PullRequired) {
                // Like a gap: no receipt, no acknowledgement, no commit.
                // The writer is expected to send this same mutation again,
                // rebased, and a stored receipt would turn that into a
                // reused identity.
                $ledger->rollbackDraft();

                return new MutationResult(MutationStatus::PullRequired, $outcome->recordVersion, reason: $outcome->reason,
                    decisions: $outcome->decisions, acknowledgedSequence: $ack, conflicts: $outcome->conflicts);
            }
            $proposed = $ledger->record($mutation->entity);
            // Whatever is about to be committed is validated, rather than a
            // list of statuses someone has to remember to extend. Conflict
            // was missing from that list: a preserved candidate is stored
            // state, and it was reaching the database without the host's
            // validator - and so without the authorization re-check that
            // decorates it - on the one path this package exists for.
            //
            // Rejected is the only outcome here that never reaches storage,
            // so it is the only one worth skipping. Keeping the condition
            // the exact inverse of the rollback below is what stops the two
            // drifting apart again.
            if ($proposed !== null && $outcome->status !== MutationStatus::Rejected) {
                $validation = $this->validator->validate(new ValidationContext($record, $proposed, $mutation, $origin));
                // A preserved candidate is not in the record yet, so the
                // check above never saw its value. It is a value someone
                // may choose later, and one that could never be valid has
                // no business waiting in a group for them to choose it.
                $chosen = $this->asIfChosen($proposed, $mutation, $outcome);
                if ($validation->isValid() && $chosen !== null) {
                    $validation = $this->validator->validate(new ValidationContext($record, $chosen, $mutation, $origin));
                }
                if (! $validation->isValid()) {
                    $outcome = new MutationResult(MutationStatus::ValidationFailed, $actualVersion,
                        reason: 'invalid_entity_state', decisions: $outcome->decisions,
                        validation: $validation, conflicts: $outcome->conflicts);
                }
            }
            if (in_array($outcome->status, [MutationStatus::Rejected, MutationStatus::ValidationFailed], true)) {
                $ledger->rollbackDraft();
            } else {
                $ledger->commitDraft();
            }
        }
        $sequence = new CommitSequence($ledger->watermark()->value + 1);
        $result = new MutationResult($outcome->status, $outcome->recordVersion, $sequence, $outcome->reason, $outcome->decisions, $outcome->acceptedVersions, $outcome->conflictGroupIds, $mutation->sequence->value, $outcome->preconditionFailure, $outcome->validation, $outcome->conflicts);
        $receipt = new Receipt($mutation, $result, $origin);
        $ledger->putReceipt($receipt);
        $ledger->acknowledge($mutation->replica, $mutation->sequence->value);
        $changes = [];
        if ($ledger->recordChanged($mutation->entity)) {
            // A record reported changed is in the ledger; a Change of kind
            // Record carrying no record would be a feed entry a reader
            // cannot apply.
            $after = $ledger->record($mutation->entity) ?? throw new \LogicException('A changed record is missing from the ledger');
            $changes[] = new Change(count($changes), $after->deleted ? ChangeKind::Deleted : ChangeKind::Record, record: $after, previousRecord: $record, provenance: $origin);
        }
        foreach ($ledger->touchedGroups() as $group) {
            $changes[] = new Change(count($changes), ChangeKind::Conflict, group: $group, provenance: $origin);
        }
        $changes[] = new Change(count($changes), ChangeKind::Mutation, receipt: $receipt, provenance: $origin);
        $ledger->appendCommit($sequence, $changes);
        $committed = $sequence;

        return $result;
    }

    /** The record as it would be if every candidate this mutation preserved were chosen; null when it preserved none. */
    private function asIfChosen(EntityRecord $proposed, Mutation $mutation, MutationResult $outcome): ?EntityRecord
    {
        if (! in_array(ConflictDecision::Preserve, $outcome->decisions, true)) {
            return null;
        }
        // An atomic proposal is chosen whole - its other fields were held back
        // with it, not dropped - so it is validated whole. Substituting only
        // the conflicted field judged a combination nobody proposed.
        $fields = $proposed->fields;
        $preserved = false;
        foreach ($mutation->operations as $operation) {
            if ($mutation->atomic || ($outcome->decisions[$operation->field] ?? null) === ConflictDecision::Preserve) {
                $fields[$operation->field] = new FieldState($operation->value, $fields[$operation->field]->version ?? null, $fields[$operation->field]->origin ?? null);
                $preserved = true;
            }
        }

        return $preserved ? new EntityRecord($proposed->entity, $proposed->version, $fields, $proposed->deleted) : null;
    }

    /** @return array<string, FieldVersion> */
    private function dependency(Ledger $ledger, Mutation $mutation): array
    {
        if ($mutation->dependsOn === null) {
            return [];
        }
        $previous = $ledger->receipt($mutation->dependsOn);
        if ($previous === null) {
            // Unknown - never processed, or its receipt pruned with the log.
            // Either way there is no knowledge to inherit, and inheriting none
            // is the safe answer: the write is judged on its own base, and at
            // worst preserves a conflict with the device's own earlier edit.
            // Refusing it used to abandon a legitimate write after a prune.
            return [];
        }
        if ($previous->mutation->replica->id !== $mutation->replica->id) {
            // Another of the writer's streams - a write queued before an
            // upgrade split them. Inheriting nothing is safe; refusing lost it.
            return [];
        }
        if ($previous->mutation->entity->key() !== $mutation->entity->key() || $previous->mutation->sequence->value >= $mutation->sequence->value) {
            throw new InvalidRequest('Dependency must be a processed earlier mutation for the same replica, space and entity');
        }

        return $previous->result->acceptedVersions;
    }

    /** @param array<string, FieldVersion> $knowledge */
    private function apply(Ledger $ledger, Mutation $mutation, ?EntityRecord $record, array $knowledge, AdapterContext $context, OnConflict $onConflict): MutationResult
    {
        if ($mutation->kind === MutationKind::Create) {
            if ($record !== null) {
                return $this->reject($record, 'entity_exists');
            }
            $fields = [];
            foreach ($mutation->operations as $operation) {
                $fields[$operation->field] = $this->field($mutation, $operation, 1, $context);
                $knowledge[$operation->field] = new FieldVersion(1);
            }
            $record = new EntityRecord($mutation->entity, new RecordVersion(1), $fields);
            $ledger->putRecord($record);

            return new MutationResult(MutationStatus::Applied, $record->version, acceptedVersions: $knowledge);
        }
        if ($record === null) {
            return $this->reject(null, 'entity_not_found');
        }
        if ($record->deleted) {
            return $this->reject($record, 'entity_deleted');
        }
        if ($mutation->kind === MutationKind::Delete) {
            $record = new EntityRecord($record->entity, new RecordVersion($record->version->value + 1), $record->fields, true, Provenance::fromMutation($mutation, $context));
            $ledger->putRecord($record);

            return new MutationResult(MutationStatus::Applied, $record->version);
        }
        if ($mutation->kind === MutationKind::Resolve) {
            return $this->resolve($ledger, $mutation, $record, $knowledge, $context);
        }

        return $this->update($ledger, $mutation, $record, $knowledge, $context, $onConflict);
    }

    /** @param array<string, FieldVersion> $knowledge */
    private function update(Ledger $ledger, Mutation $mutation, EntityRecord $record, array $knowledge, AdapterContext $context, OnConflict $onConflict): MutationResult
    {
        $accepted = $knowledge;
        $stale = [];
        $pending = [];
        $decisions = [];
        $groups = [];
        $conflicts = [];
        foreach ($mutation->operations as $operation) {
            $field = $record->fields[$operation->field] ?? null;
            if ($record->value($operation->field)->equals($operation->value)) {
                $accepted[$operation->field] = $field->version ?? new FieldVersion;

                continue;
            }
            $base = max($mutation->baseVersion->value, ($knowledge[$operation->field] ?? null)->value ?? 0);
            if (($field->version->value ?? 0) <= $base) {
                $pending[] = $operation;

                continue;
            }
            $decision = $this->resolver->resolve(new ConflictContext($record, $operation, $mutation));
            $decisions[$operation->field] = $decision;
            $conflicts[$operation->field] = new FieldConflict($operation->field, $record->value($operation->field), $operation->value, $field->version ?? new FieldVersion, new RecordVersion($base));
            if ($decision === ConflictDecision::Client) {
                $pending[] = $operation;
            }
            if ($decision === ConflictDecision::Preserve && $onConflict === OnConflict::Pull) {
                // Checked before anything is preserved, so a refusal leaves
                // no group behind for a write that never happened.
                $stale[$operation->field] = $conflicts[$operation->field];

                continue;
            }
            if ($decision === ConflictDecision::Preserve) {
                $groups[] = $this->preserve($ledger, $mutation, $record, $operation, $context)->id;
            }
        }
        if (in_array(ConflictDecision::Reject, $decisions, true)) {
            return new MutationResult(MutationStatus::Rejected, $record->version, reason: 'conflict_rejected', decisions: $decisions, conflicts: $conflicts);
        }
        if ($stale !== []) {
            // The whole mutation, not the fields that happened to be fresh:
            // applying half of an edit the writer is about to rethink would
            // leave the record in a state nobody chose.
            // With every decision, not only the refused fields: a field the
            // resolver kept for the server is not stale - it is settled - and a
            // writer that rebased onto this version without knowing would send
            // it again and win it.
            return new MutationResult(MutationStatus::PullRequired, $record->version, reason: 'pull_required', decisions: $decisions, conflicts: $stale);
        }
        if ($mutation->atomic && $groups !== []) {
            $pending = [];
            $accepted = $knowledge;
        }
        if ($pending !== []) {
            $version = $record->version->value + 1;
            $fields = $record->fields;
            foreach ($pending as $operation) {
                $fields[$operation->field] = $this->field($mutation, $operation, $version, $context);
                $accepted[$operation->field] = new FieldVersion($version);
            }
            $record = new EntityRecord($record->entity, new RecordVersion($version), $fields);
            $ledger->putRecord($record);
        }
        $status = $groups !== []
            ? ($pending !== [] ? MutationStatus::Partial : MutationStatus::Conflict)
            : ($pending !== [] ? MutationStatus::Applied : MutationStatus::Noop);

        return new MutationResult($status, $record->version, decisions: $decisions, acceptedVersions: $accepted, conflictGroupIds: $groups, conflicts: $conflicts);
    }

    private function preserve(Ledger $ledger, Mutation $mutation, EntityRecord $record, FieldOperation $operation, AdapterContext $context): ConflictGroup
    {
        $group = $ledger->openGroup($record->entity, $operation->field);
        if ($group === null) {
            $id = $this->ids->generate();
            if ($ledger->group($id) !== null) {
                throw new ProtocolException('ID generator returned a duplicate group ID');
            }
            $group = new ConflictGroup($id, $record->entity, $operation->field);
        }
        $origin = ($record->fields[$operation->field] ?? null)?->origin;
        if ($origin !== null) {
            $group = $group->add($origin);
        }
        $group = $group->add(Candidate::fromOperation($mutation, $operation, $context));
        $ledger->putGroup($group);

        return $group;
    }

    /** @param array<string, FieldVersion> $knowledge */
    private function resolve(Ledger $ledger, Mutation $mutation, EntityRecord $record, array $knowledge, AdapterContext $context): MutationResult
    {
        $resolution = $mutation->resolution;
        $operation = $mutation->operations[0] ?? null;
        if ($resolution === null || $operation === null) {
            throw new InvalidRequest('Missing resolution');
        }
        $group = $ledger->group($resolution->groupId);
        if ($group === null || $group->entity->key() !== $record->entity->key() || $group->field !== $operation->field) {
            return $this->reject($record, 'invalid_conflict_group');
        }
        if ($record->version->value !== $mutation->baseVersion->value || $group->revision !== $resolution->groupRevision || ! $group->isOpen()) {
            return $this->reject($record, 'stale_resolution');
        }
        foreach ($resolution->candidateIds as $id) {
            if (! isset($group->candidates[$id]) || isset($group->resolved[$id])) {
                return $this->reject($record, 'invalid_candidate');
            }
        }
        $sameValue = $record->value($operation->field)->equals($operation->value);
        if (! $sameValue) {
            $version = $record->version->value + 1;
            $fields = $record->fields;
            $fields[$operation->field] = $this->field($mutation, $operation, $version, $context);
            $record = new EntityRecord($record->entity, new RecordVersion($version), $fields);
            $ledger->putRecord($record);
        }
        $ledger->putGroup($group->resolve($resolution->candidateIds, $mutation->id));
        $knowledge[$operation->field] = ($record->fields[$operation->field] ?? null)->version ?? new FieldVersion;

        return new MutationResult($sameValue ? MutationStatus::Noop : MutationStatus::Applied, $record->version, acceptedVersions: $knowledge, conflictGroupIds: [$group->id]);
    }

    private function field(Mutation $mutation, FieldOperation $operation, int $version, AdapterContext $context): FieldState
    {
        return new FieldState($operation->value, new FieldVersion($version), Candidate::fromOperation($mutation, $operation, $context));
    }

    private function reject(?EntityRecord $record, string $reason): MutationResult
    {
        return new MutationResult(MutationStatus::Rejected, $record->version ?? new RecordVersion, reason: $reason);
    }
}
