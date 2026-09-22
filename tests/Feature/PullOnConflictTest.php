<?php

declare(strict_types=1);

use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Enums\OnConflict;
use Cbox\Sync\Resolvers\ClientWins;
use Cbox\Sync\Resolvers\PreserveConflict;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/** A record with two fields, then another device's newer title. */
function staleSetup(Engine $engine, EntityKey $key): void
{
    $engine->process(new Mutation('seed', $key, new Replica('server'), new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [
        Op::set('title', 'original'),
        Op::set('body', 'text'),
    ]));
    $engine->process(new Mutation('other', $key, new Replica('other'), new MutationSequence(1), MutationKind::Update, new RecordVersion(1), [
        Op::set('title', 'theirs'),
    ]));
}

function staleEdit(string $id = 'mine', int $base = 1, array $operations = [], int $sequence = 1): Mutation
{
    return new Mutation($id, new EntityKey('test', 'notes', 'one'), new Replica('device'), new MutationSequence($sequence), MutationKind::Update, new RecordVersion($base),
        $operations === [] ? [Op::set('title', 'mine'), Op::set('body', 'edited')] : $operations);
}

it('refuses a stale edit instead of preserving it, and stores nothing', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);
    $before = $store->watermark('test')->value;

    $result = $engine->process(staleEdit(), onConflict: OnConflict::Pull);

    expect($result->status)->toBe(MutationStatus::PullRequired)
        ->and($result->reason)->toBe('pull_required')
        ->and($result->recordVersion->value)->toBe(2)
        ->and(array_keys($result->conflicts))->toBe(['title'])
        ->and($result->conflicts['title']->current->value())->toBe('theirs')
        ->and($result->acknowledgedSequence)->toBe(0)
        ->and($result->commitSequence)->toBeNull();

    // Not the fresh field either: half an edit the writer is about to
    // rethink is a state nobody chose.
    $record = $store->record($key);
    expect($record?->value('body')->value())->toBe('text')
        ->and($store->watermark('test')->value)->toBe($before)
        ->and($store->receipt('mine'))->toBeNull()
        ->and($store->openGroups($key))->toBe([])
        ->and($store->acknowledged('test', new Replica('device')))->toBe(0);
});

it('accepts the same mutation again once it is rebased, as an informed write', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);

    $engine->process(staleEdit(), onConflict: OnConflict::Pull);
    // Same identity, same sequence, new base: the refusal stored nothing, so
    // this is not a reused identity.
    $result = $engine->process(staleEdit(base: 2), onConflict: OnConflict::Pull);

    expect($result->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->value('title')->value())->toBe('mine')
        ->and($store->openGroups($key))->toBe([])
        ->and($store->acknowledged('test', new Replica('device')))->toBe(1);
});

it('still preserves when the writer did not ask to pull', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);

    $result = $engine->process(staleEdit());

    expect($result->status)->toBe(MutationStatus::Conflict)
        ->and($store->openGroups($key))->toHaveCount(1);
});

it('never overrides a resolver that settles the field itself', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new ClientWins);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);

    $result = $engine->process(staleEdit(), onConflict: OnConflict::Pull);

    expect($result->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->value('title')->value())->toBe('mine');
});

it('lets a fresh edit through untouched', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);

    $result = $engine->process(staleEdit(operations: [Op::set('body', 'edited')]), onConflict: OnConflict::Pull);

    expect($result->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->value('body')->value())->toBe('edited');
});

it('answers a replay from its receipt, whatever the mode', function () {
    $store = $this->syncStore();
    $engine = new Engine($store, new PreserveConflict);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);

    $first = $engine->process(staleEdit(base: 2), onConflict: OnConflict::Pull);
    $again = $engine->process(staleEdit(base: 2));

    expect($again)->toEqual($first);
});

/**
 * A field the resolver kept for the server is settled, not stale. The refusal
 * says so; without it, a writer that rebased onto the reported version sent
 * that field again and won it.
 */
it('tells the writer which fields the server kept, so a rebase cannot take them back', function () {
    $store = $this->syncStore();
    $resolver = new class implements ConflictResolver
    {
        public function resolve(ConflictContext $context): ConflictDecision
        {
            return $context->operation->field === 'body' ? ConflictDecision::Server : ConflictDecision::Preserve;
        }
    };
    $engine = new Engine($store, $resolver);
    $key = new EntityKey('test', 'notes', 'one');
    staleSetup($engine, $key);
    $engine->process(new Mutation('other-body', $key, new Replica('other'), new MutationSequence(2), MutationKind::Update, new RecordVersion(2), [Op::set('body', 'theirs')]));

    $result = $engine->process(staleEdit(), onConflict: OnConflict::Pull);

    expect($result->status)->toBe(MutationStatus::PullRequired)
        ->and(array_keys($result->conflicts))->toBe(['title'])
        ->and($result->decisions['body'] ?? null)->toBe(ConflictDecision::Server);
});
