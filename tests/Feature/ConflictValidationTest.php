<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\EntityValidator;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Data\ValidationContext;
use Cbox\Sync\Data\ValidationFailure;
use Cbox\Sync\Data\ValidationResult;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\Resolvers\RejectOnConflict;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/** Records every context it is asked about, and can be told to refuse. */
final class RecordingValidator implements EntityValidator
{
    /** @var list<string> */
    public array $sawMutations = [];

    public function __construct(public bool $refuse = false) {}

    public function validate(ValidationContext $context): ValidationResult
    {
        $this->sawMutations[] = $context->mutation->id;

        return $this->refuse
            ? new ValidationResult([new ValidationFailure('not_allowed', 'Refused by the host')])
            : new ValidationResult;
    }
}

function conflictingPair(Engine $engine, EntityKey $key): void
{
    $engine->process(new Mutation('seed', $key, new Replica('server'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [
        Op::set('title', 'original'),
    ]));
    $engine->process(new Mutation('device-a', $key, new Replica('a'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'from-a'),
    ]));
}

/**
 * A preserved candidate is stored state. Skipping the host's validator for it
 * means the one path this package exists for is also the one path that bypasses
 * the host's rules - including the authorization re-check that decorates the
 * validator in the Laravel transport.
 */
it('validates a mutation that conflicts', function () {
    $validator = new RecordingValidator;
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict, validator: $validator);
    $key = new EntityKey('test', 'notes', 'one');

    conflictingPair($engine, $key);

    $result = $engine->process(new Mutation('device-b', $key, new Replica('b'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'from-b'),
    ]));

    expect($result->status)->toBe(MutationStatus::Conflict);
    expect($validator->sawMutations)->toContain('device-b');
});

it('refuses to preserve a candidate the host will not accept', function () {
    $validator = new RecordingValidator;
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict, validator: $validator);
    $key = new EntityKey('test', 'notes', 'one');

    conflictingPair($engine, $key);
    $validator->refuse = true;

    $result = $engine->process(new Mutation('device-b', $key, new Replica('b'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'from-b'),
    ]));

    expect($result->status)->toBe(MutationStatus::ValidationFailed);

    // The draft is rolled back whole, so the refused proposal is not sitting in
    // a conflict group waiting for someone to resolve it.
    foreach ($store->openGroups($key) as $group) {
        foreach ($group->candidates as $candidate) {
            expect($candidate->value->value())->not->toBe('from-b');
        }
    }
});

/** A rejected mutation never reaches storage, so there is nothing to validate. */
it('does not validate a mutation the resolver rejected', function () {
    $validator = new RecordingValidator;
    $store = $this->syncStore();
    $engine = new Engine($store, new RejectOnConflict, validator: $validator);
    $key = new EntityKey('test', 'notes', 'one');

    conflictingPair($engine, $key);

    $result = $engine->process(new Mutation('device-b', $key, new Replica('b'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'from-b'),
    ]));

    expect($result->status)->toBe(MutationStatus::Rejected);
    expect($validator->sawMutations)->not->toContain('device-b');
});

/** Refuses any record whose title is "forbidden" - a rule about the value, not the caller. */
final class ForbiddenTitle implements EntityValidator
{
    public function validate(ValidationContext $context): ValidationResult
    {
        return $context->proposed->value('title')->value() === 'forbidden'
            ? new ValidationResult([new ValidationFailure('bad_title', 'No', 'title')])
            : new ValidationResult;
    }
}

/**
 * A conflict leaves the record unchanged, so validating only the record never
 * saw the value being preserved. That let a value the host forbids wait in a
 * group for someone to pick.
 */
it('validates the value a conflict would preserve, not only the record', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict, validator: new ForbiddenTitle);
    $key = new EntityKey('test', 'notes', 'one');
    conflictingPair($engine, $key);

    $result = $engine->process(new Mutation('device-b', $key, new Replica('b'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'forbidden'),
    ]));

    expect($result->status)->toBe(MutationStatus::ValidationFailed)
        ->and($store->openGroups($key))->toBe([]);
});

/** Refuses a record whose title equals its body. */
final class TitleIsNotBody implements EntityValidator
{
    public function validate(ValidationContext $context): ValidationResult
    {
        return $context->proposed->value('title')->equals($context->proposed->value('body'))
            ? new ValidationResult([new ValidationFailure('same', 'Title and body must differ', 'title')])
            : new ValidationResult;
    }
}

/**
 * An atomic proposal is chosen whole. Validating only its conflicted field
 * judged a combination nobody proposed, and refused a valid proposal.
 */
it('validates an atomic proposal whole, as it would be chosen', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict, validator: new TitleIsNotBody);
    $key = new EntityKey('test', 'notes', 'one');
    $engine->process(new Mutation('seed', $key, new Replica('server'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [
        Op::set('title', 'original'), Op::set('body', 'text'),
    ]));
    $engine->process(new Mutation('a', $key, new Replica('a'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [Op::set('title', 'from-a')]));

    // Conflicts on title only. Chosen whole it is title "text", body "new" - valid.
    $result = $engine->process(new Mutation('b', $key, new Replica('b'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'text'), Op::set('body', 'new'),
    ]));

    expect($result->status)->toBe(MutationStatus::Conflict);
});
