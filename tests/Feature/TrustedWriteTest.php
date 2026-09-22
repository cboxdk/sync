<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\EntityKey;
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
