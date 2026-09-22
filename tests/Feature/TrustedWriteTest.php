<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\MutationSequence;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\ValueObjects\Replica;

/**
 * A host's own write decides create-or-update, its base and its stream
 * position inside the lock, so two of them never race for a position and a
 * stale read before the lock cannot refuse an ordinary save.
 */
it('creates, updates and deletes with everything decided inside the lock', function () {
    $store = $this->syncStore();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'n1');
    $server = new Replica('server');

    expect($engine->recordTrusted($key, $server, [Op::set('title', 'a')], false)?->status)->toBe(MutationStatus::Applied)
        ->and($engine->recordTrusted($key, $server, [Op::set('title', 'b')], false)?->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->version->value)->toBe(2)
        ->and($store->acknowledged('team', $server))->toBe(2)
        ->and($engine->recordTrusted($key, $server, [], true)?->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->deleted)->toBeTrue()
        // A delete of what the log does not hold is nothing to record.
        ->and($engine->recordTrusted(new EntityKey('team', 'notes', 'never'), $server, [], true))->toBeNull();
});

it('honours a whole-record precondition and a field-level base', function () {
    $store = $this->syncStore();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'n1');
    $server = new Replica('server');
    $engine->recordTrusted($key, $server, [Op::set('title', 'a'), Op::set('body', 'x')], false);
    $engine->recordTrusted($key, $server, [Op::set('title', 'b')], false);

    expect($engine->recordTrusted($key, $server, [Op::set('body', 'y')], false, acceptVersions: [1, 3])?->status)->toBe(MutationStatus::PreconditionFailed)
        ->and($engine->recordTrusted($key, $server, [Op::set('body', 'y')], false, acceptVersions: [1, 2])?->status)->toBe(MutationStatus::Applied)
        // Field-level: title moved since version 1, so an edit of it made then is refused ...
        ->and($engine->recordTrusted($key, $server, [Op::set('title', 'c')], false, claimedBase: 1)?->status)->toBe(MutationStatus::PullRequired)
        // ... and one of a field nobody touched since goes through.
        ->and($store->openGroups($key))->toBe([]);
});

/**
 * What the host's table made of a device's write - a column it defaulted, an
 * observer's trim - is logged as the server's own write one version later. The
 * device's next edit, built on its answer or depending on it, used to conflict
 * with that echo of its own write.
 */
it('counts an echo of a device\'s write as the device\'s own knowledge', function () {
    $store = $this->syncStore();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'n1');
    $device = new Replica('device');
    $create = new Mutation('c1', $key, $device, new MutationSequence(1), MutationKind::Create, new RecordVersion(0), [Op::set('title', 'x')]);
    $engine->process($create);

    $engine->recordTrusted($key, new Replica('server'), [Op::set('status', 'open')], false, echoOf: 'c1');
    $answer = $engine->process($create);
    $edit = $engine->process(new Mutation('c2', $key, $device, new MutationSequence(2), MutationKind::Update, new RecordVersion(0), [Op::set('status', 'done')], dependsOn: 'c1'));
    $onAnswer = $engine->process(new Mutation('c3', $key, $device, new MutationSequence(3), MutationKind::Update, $answer->recordVersion, [Op::set('title', 'y')]));

    expect($answer->recordVersion->value)->toBe(2)
        ->and($answer->acceptedVersions['status']->value)->toBe(2)
        ->and($edit->status)->toBe(MutationStatus::Applied)
        ->and($onAnswer->status)->toBe(MutationStatus::Applied)
        ->and($store->record($key)?->value('status')->value())->toBe('done');
});

/** A row that existed before it was synced is logged whole on its first save, not as the one field that changed. */
it('writes every field when the log has never held the record', function () {
    $store = $this->syncStore();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'legacy');

    $engine->recordTrusted($key, new Replica('server'), [Op::set('title', 'new')], false, asCreate: [Op::set('title', 'new'), Op::set('body', 'old')]);
    $engine->recordTrusted($key, new Replica('server'), [Op::set('title', 'newer')], false, asCreate: [Op::set('title', 'newer'), Op::set('body', 'old')]);

    expect($store->record($key)?->value('body')->value())->toBe('old')
        ->and($store->record($key)?->value('title')->value())->toBe('newer')
        ->and($store->record($key)?->version->value)->toBe(2);
});

/** If-Match on a record that does not exist fails, as HTTP says; it used to create the record. */
it('fails an If-Match on a record the log does not hold', function () {
    $store = $this->syncStore();
    $engine = new Engine($store);
    $key = new EntityKey('team', 'notes', 'missing');

    expect($engine->recordTrusted($key, new Replica('server'), [Op::set('title', 'x')], false, acceptVersions: [3])?->status)->toBe(MutationStatus::PreconditionFailed)
        ->and($engine->recordTrusted($key, new Replica('server'), [Op::set('title', 'x')], false, acceptVersions: [])?->status)->toBe(MutationStatus::PreconditionFailed)
        ->and($store->record($key))->toBeNull();
});
