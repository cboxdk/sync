<?php

declare(strict_types=1);

namespace Cbox\Sync;

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
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\Support\UuidV7Generator;
use Cbox\Sync\Validation\AcceptAll;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\FieldVersion;
use Cbox\Sync\ValueObjects\RecordVersion;

class Engine
{
    public function __construct(private Store $store, private ConflictResolver $resolver = new PreserveConflict, private IdGenerator $ids = new UuidV7Generator, private EntityValidator $validator = new AcceptAll) {}

    public function process(Mutation $mutation, AdapterContext $context = new AdapterContext): MutationResult
    {
        return $this->store->transaction($mutation->entity->space, function (Ledger $ledger) use ($mutation, $context): MutationResult {
            $receipt = $ledger->receipt($mutation->id);
            if ($receipt !== null) {
                if ($receipt->mutation->fingerprint() !== $mutation->fingerprint() || $receipt->provenance->actorId !== $context->actorId || $receipt->provenance->integrationId !== $context->integrationId) {
                    throw new ProtocolException('Mutation identity reused with different content');
                }

                return $receipt->result;
            }
            $ack = $ledger->acknowledged($mutation->replica);
            if ($mutation->sequence->value <= $ack) {
                throw new ProtocolException('Sequence reused with a different mutation identity');
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
                $outcome = $this->apply($ledger, $mutation, $record, $knowledge, $context);
                $proposed = $ledger->record($mutation->entity);
                if ($proposed !== null && in_array($outcome->status, [MutationStatus::Applied, MutationStatus::Partial, MutationStatus::Noop], true)) {
                    $validation = $this->validator->validate(new ValidationContext($record, $proposed, $mutation, $origin));
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
                $after = $ledger->record($mutation->entity);
                $changes[] = new Change(count($changes), $after?->deleted ? ChangeKind::Deleted : ChangeKind::Record, record: $after, previousRecord: $record, provenance: $origin);
            }
            foreach ($ledger->touchedGroups() as $group) {
                $changes[] = new Change(count($changes), ChangeKind::Conflict, group: $group, provenance: $origin);
            }
            $changes[] = new Change(count($changes), ChangeKind::Mutation, receipt: $receipt, provenance: $origin);
            $ledger->appendCommit($sequence, $changes);

            return $result;
        });
    }

    /** @return array<string, FieldVersion> */
    private function dependency(Ledger $ledger, Mutation $mutation): array
    {
        if ($mutation->dependsOn === null) {
            return [];
        }
        $previous = $ledger->receipt($mutation->dependsOn);
        if ($previous === null || $previous->mutation->replica->id !== $mutation->replica->id || $previous->mutation->entity->key() !== $mutation->entity->key() || $previous->mutation->sequence->value >= $mutation->sequence->value) {
            throw new InvalidRequest('Dependency must be a processed earlier mutation for the same replica, space and entity');
        }

        return $previous->result->acceptedVersions;
    }

    /** @param array<string, FieldVersion> $knowledge */
    private function apply(Ledger $ledger, Mutation $mutation, ?EntityRecord $record, array $knowledge, AdapterContext $context): MutationResult
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

        return $this->update($ledger, $mutation, $record, $knowledge, $context);
    }

    /** @param array<string, FieldVersion> $knowledge */
    private function update(Ledger $ledger, Mutation $mutation, EntityRecord $record, array $knowledge, AdapterContext $context): MutationResult
    {
        $accepted = $knowledge;
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
            if ($decision === ConflictDecision::Preserve) {
                $groups[] = $this->preserve($ledger, $mutation, $record, $operation, $context)->id;
            }
        }
        if (in_array(ConflictDecision::Reject, $decisions, true)) {
            return new MutationResult(MutationStatus::Rejected, $record->version, reason: 'conflict_rejected', decisions: $decisions, conflicts: $conflicts);
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
