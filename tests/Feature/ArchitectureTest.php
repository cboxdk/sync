<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\ChangeKind;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Exceptions\TransientFailure;
use Cbox\Sync\Resolvers\RejectOnConflict;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\RecordVersion;

it('defaults to atomic domain application and journals every proposed field', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $mutation = $this->mutation('b', 1, [Op::set('title', 'B'), Op::set('body', 'must not disappear')]);
    expect($mutation->atomic)->toBeTrue();
    $result = $this->engine->process($mutation);
    expect($result->status)->toBe(MutationStatus::Conflict);
    expect($this->record()->value('body')->value())->toBe('body');
    expect($result->recordVersion->value)->toBe(2);
    $receipt = $this->store->receipt('b-1');
    expect($receipt->mutation->operations)->toHaveCount(2);
    expect($receipt->mutation->operations[1]->value->value())->toBe('must not disappear');
    expect($receipt->result->acknowledgedSequence)->toBe(1);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
});

it('makes partial application an explicit opt-in', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $result = $this->write('b', 1, [Op::set('title', 'B'), Op::set('body', 'allowed')], atomic: false);
    expect($result->status)->toBe(MutationStatus::Partial);
    expect($this->record()->value('body')->value())->toBe('allowed');
});

it('rejects the whole mutation on conflict even when partial was requested', function () {
    $this->setUpSync(new RejectOnConflict);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $mutation = $this->mutation('b', 1, [Op::set('body', 'blocked'), Op::set('title', 'B')], atomic: false);
    $result = $this->engine->process($mutation);
    expect($result->status)->toBe(MutationStatus::Rejected);
    expect($result->reason)->toBe('conflict_rejected');
    expect($result->recordVersion->value)->toBe(2);
    expect($result->conflicts['title']->current->value())->toBe('A');
    expect($result->conflicts['title']->proposed->value())->toBe('B');
    expect($result->conflicts['title']->fieldVersion->value)->toBe(2);
    expect($this->record()->value('body')->value())->toBe('body');
    expect($this->openConflicts())->toBe([]);
    expect($this->engine->process($mutation))->toEqual($result);
    expect($this->write('b', 2, [Op::set('body', 'next')])->status)->toBe(MutationStatus::Applied);
});

it('checks strict entity revision before equal target detection and records a terminal failure', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $mutation = $this->mutation('b', 1, [Op::set('title', 'A')], expectedVersion: new RecordVersion(1));
    $result = $this->engine->process($mutation);
    expect($result->status)->toBe(MutationStatus::PreconditionFailed);
    expect($result->preconditionFailure->expectedVersion->value)->toBe(1);
    expect($result->preconditionFailure->actualVersion->value)->toBe(2);
    expect($result->acknowledgedSequence)->toBe(1);
    expect($this->record()->version->value)->toBe(2);
    expect($this->engine->process($mutation))->toEqual($result);
    expect(fn () => $this->engine->process($this->mutation('b', 1, [Op::set('title', 'A')], expectedVersion: new RecordVersion(2))))->toThrow(ProtocolException::class);
    expect($this->engine->process($this->mutation('b', 2, [Op::set('title', 'A')], expectedVersion: new RecordVersion(2)))->status)->toBe(MutationStatus::Noop);
});

it('retains field-level merging with a stale record base under atomic default', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    expect($this->write('b', 1, [Op::set('body', 'B')])->status)->toBe(MutationStatus::Applied);
    expect($this->record()->value('title')->value())->toBe('A');
    expect($this->record()->value('body')->value())->toBe('B');
});

it('passes trusted actor and integration separately through canonical conflict resolution and receipt origins', function () {
    $this->seedRecord();
    $first = new AdapterContext(actorId: 'actor-a', integrationId: 'integration-x');
    $second = new AdapterContext(actorId: 'actor-b', integrationId: 'integration-y');
    $this->engine->process($this->mutation('a', 1, [Op::set('title', 'A')]), $first);
    $this->engine->process($this->mutation('b', 1, [Op::set('title', 'B')]), $second);
    $group = $this->openConflicts()[0];
    $origins = array_values($group->candidates);
    expect($origins[0]->provenance->actorId)->toBe('actor-a');
    expect($origins[0]->provenance->integrationId)->toBe('integration-x');
    expect($origins[1]->provenance->actorId)->toBe('actor-b');
    expect($origins[1]->provenance->replica->id)->toBe('b');
    expect($origins[1]->provenance->mutationId)->toBe('b-1');
    $resolve = $this->mutation('r', 1, [Op::set('title', 'R')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates)));
    $this->engine->process($resolve, new AdapterContext(actorId: 'reviewer'));
    expect($this->record()->fields['title']->origin->provenance->actorId)->toBe('reviewer');
    $commit = $this->commit(4);
    foreach ($commit->changes as $change) {
        expect($change->provenance->actorId)->toBe('reviewer');
        expect($change->provenance->mutationId)->toBe('r-1');
    }
    expect($this->store->receipt('r-1')->provenance->actorId)->toBe('reviewer');
    expect(fn () => $this->engine->process($resolve, new AdapterContext(actorId: 'imposter')))->toThrow(ProtocolException::class);
});

it('records no-op acknowledgement without canonical changes or data notifications', function () {
    $this->seedRecord();
    $result = $this->write('a', 1, [Op::set('title', 'initial')]);
    expect($result->status)->toBe(MutationStatus::Noop);
    expect($result->recordVersion->value)->toBe(1);
    expect($this->record()->fields['title']->version->value)->toBe(1);
    $changes = $this->commit(2)->changes;
    expect($changes)->toHaveCount(1);
    expect($changes[0]->kind)->toBe(ChangeKind::Mutation);
    expect($changes[0]->isDataChange())->toBeFalse();
    expect($result->acknowledgedSequence)->toBe(1);
});

it('resolves conflict metadata with an equal canonical target without a false data change', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $result = $this->engine->process($this->mutation('r', 1, [Op::set('title', 'A')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates))));
    expect($result->status)->toBe(MutationStatus::Noop);
    expect($this->record()->version->value)->toBe(2);
    expect($this->record()->fields['title']->version->value)->toBe(2);
    expect($this->openConflicts())->toBe([]);
    $changes = $this->commit(4)->changes;
    expect(array_map(fn ($change) => $change->kind, $changes))->toBe([ChangeKind::Conflict, ChangeKind::Mutation]);
    expect(array_filter($changes, fn ($change) => $change->isDataChange()))->toBe([]);
});

it('validates the complete merged state and retains a typed terminal rejection', function () {
    $this->seedRecord();
    $validator = new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return $context->proposed->value('title')->value() === $context->proposed->value('body')->value()
                ? new ValidationResult([new ValidationFailure('distinct_fields', 'Title and body must differ', 'body')])
                : new ValidationResult;
        }
    };
    $this->engine = new Engine($this->store, validator: $validator);
    $this->write('a', 1, [Op::set('title', 'shared')]);
    $mutation = $this->mutation('b', 1, [Op::set('body', 'shared')]);
    $result = $this->engine->process($mutation);
    expect($result->status)->toBe(MutationStatus::ValidationFailed);
    expect($result->validation->failures[0]->code)->toBe('distinct_fields');
    expect($this->record()->value('body')->value())->toBe('body');
    expect($this->record()->version->value)->toBe(2);
    expect($result->acknowledgedSequence)->toBe(1);
    expect($this->engine->process($mutation))->toEqual($result);
    expect($this->write('b', 2, [Op::set('body', 'valid')])->status)->toBe(MutationStatus::Applied);
});

it('rejects invalid partial results and rolls back tentative conflict metadata', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->engine = new Engine($this->store, validator: new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return new ValidationResult([new ValidationFailure('domain_rule', 'Rejected proposed combined state')]);
        }
    });
    $result = $this->write('b', 1, [Op::set('title', 'B'), Op::set('body', 'invalid')], atomic: false);
    expect($result->status)->toBe(MutationStatus::ValidationFailed);
    expect($this->record()->value('body')->value())->toBe('body');
    expect($this->openConflicts())->toBe([]);
    expect($this->store->receipt('b-1')->mutation->operations)->toHaveCount(2);
    expect($this->commit(3)->changes)->toHaveCount(1);
});

it('rolls back all effects when a transactional validator throws', function () {
    $this->seedRecord();
    $this->engine = new Engine($this->store, validator: new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            throw new TransientFailure('Adapter check unavailable');
        }
    });
    $before = $this->storeDigest();
    expect(fn () => $this->write('a', 1, [Op::set('title', 'A')]))->toThrow(TransientFailure::class);
    expect($this->storeDigest())->toBe($before);
    $this->engine = new Engine($this->store);
    expect($this->write('a', 1, [Op::set('title', 'A')])->status)->toBe(MutationStatus::Applied);
});

it('validates resolution before closing candidates and validates create and delete', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $this->engine = new Engine($this->store, validator: new class implements EntityValidator
    {
        public function validate(ValidationContext $context): ValidationResult
        {
            return new ValidationResult([new ValidationFailure('protected', 'This state transition is forbidden')]);
        }
    });
    $result = $this->engine->process($this->mutation('r', 1, [Op::set('title', 'R')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates))));
    expect($result->status)->toBe(MutationStatus::ValidationFailed);
    expect($this->openConflicts()[0])->toEqual($group);
    expect($this->record()->value('title')->value())->toBe('A');
    $delete = $this->engine->process($this->mutation('d', 1, [], 2, kind: MutationKind::Delete));
    expect($delete->status)->toBe(MutationStatus::ValidationFailed);
    expect($this->record()->deleted)->toBeFalse();
    $this->key = new EntityKey('test', 'notes', 'new');
    $create = $this->engine->process($this->mutation('c', 1, [Op::set('title', 'new')], 0, kind: MutationKind::Create));
    expect($create->status)->toBe(MutationStatus::ValidationFailed);
    expect($this->store->record($this->key) !== null)->toBeFalse();
});

it('does not retain earlier preserved candidates when a later field resolver rejects', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A'), Op::set('body', 'A body')]);
    $this->engine = new Engine($this->store, resolver: new class implements ConflictResolver
    {
        public function resolve(ConflictContext $context): ConflictDecision
        {
            return $context->operation->field === 'title' ? ConflictDecision::Preserve : ConflictDecision::Reject;
        }
    });
    $result = $this->write('b', 1, [Op::set('title', 'B'), Op::set('body', 'B body')]);
    expect($result->status)->toBe(MutationStatus::Rejected);
    expect($result->conflicts)->toHaveCount(2);
    expect($result->conflictGroupIds)->toBe([]);
    expect($this->openConflicts())->toBe([]);
    expect($this->commit(3)->changes)->toHaveCount(1);
});

it('represents global deletion explicitly and preserves before membership state and origin', function () {
    $this->seedRecord();
    $this->engine->process($this->mutation('d', 1, [], kind: MutationKind::Delete), new AdapterContext(actorId: 'deleter'));
    $change = $this->commit(2)->changes[0];
    expect($change->kind)->toBe(ChangeKind::Deleted);
    expect($change->isDataChange())->toBeTrue();
    expect($change->previousRecord->deleted)->toBeFalse();
    expect($change->record->deleted)->toBeTrue();
    expect($change->record->deletion->actorId)->toBe('deleter');
    expect($change->provenance->actorId)->toBe('deleter');
});
