<?php

declare(strict_types=1);

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Resolution;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Exceptions\InvalidRequest;
use Cbox\Sync\Exceptions\ProtocolException;
use Cbox\Sync\Resolvers\ClientWins;
use Cbox\Sync\Resolvers\ServerWins;
use Cbox\Sync\ValueObjects\RecordVersion;
use Random\Engine\Mt19937;
use Random\Randomizer;

it('merges independent fields from the same base', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $result = $this->write('b', 1, [Op::set('body', 'B')]);
    expect($result->status)->toBe(MutationStatus::Applied);
    expect($this->record()->value('title')->value())->toBe('A');
    expect($this->record()->value('body')->value())->toBe('B');
    expect($this->record()->version->value)->toBe(3);
    expect($this->record()->fields['title']->version->value)->toBe(2);
});

it('preserves all 100 competing proposals and their provenance in any delivery order', function (int $seed) {
    $this->seedRecord();
    $order = range(1, 100);
    $random = new Randomizer(new Mt19937($seed));
    $order = $random->shuffleArray($order);
    foreach ($order as $client) {
        $this->write('client-'.$client, 1, [Op::set('title', 'value-'.$client)]);
    }
    $group = $this->openConflicts()[0];
    expect($group->candidates)->toHaveCount(100);
    expect($this->record()->value('title')->value())->toBe('value-'.$order[0]);
    $values = [];
    foreach ($group->candidates as $candidate) {
        $values[] = $candidate->value->value();
        expect($candidate->provenance->baseVersion->value)->toBe(1);
        expect($candidate->provenance->mutationId)->toStartWith('client-');
    }
    sort($values);
    $expected = array_map(fn ($i) => 'value-'.$i, range(1, 100));
    sort($expected);
    expect($values)->toBe($expected);
})->with([7, 42, 2026]);

it('replays a lost response without any new effect', function () {
    $this->seedRecord();
    $mutation = $this->mutation('a', 1, [Op::set('title', 'A')]);
    $first = $this->engine->process($mutation);
    foreach (range(1, 10) as $_) {
        expect($this->engine->process($mutation))->toEqual($first);
    }
    expect($this->commits())->toHaveCount(2);
});

it('does not advance record versions for equal values and distinguishes null from unset', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', null)]);
    expect($this->write('b', 1, [Op::set('title', null)])->status)->toBe(MutationStatus::Noop);
    expect($this->record()->value('title')->exists)->toBeTrue();
    $this->write('a', 2, [Op::unset('title')], 2);
    expect($this->record()->value('title')->exists)->toBeFalse();
    expect($this->record()->fields['title']->version->value)->toBe(3);
    expect($this->write('b', 2, [Op::set('title', null)])->status)->toBe(MutationStatus::Conflict);
});

it('rejects future bases without acknowledgement and validates negative versions', function () {
    $this->seedRecord();
    expect(fn () => $this->write('a', 1, [Op::set('title', 'bad')], 100))->toThrow(InvalidRequest::class);
    expect(fn () => new RecordVersion(-1))->toThrow(InvalidRequest::class);
    expect($this->write('a', 1, [Op::set('title', 'valid')])->status)->toBe(MutationStatus::Applied);
});

it('detects gaps and identity reuse including a different payload at the same sequence', function () {
    $this->seedRecord();
    expect($this->write('a', 2, [Op::set('title', 'gap')])->status)->toBe(MutationStatus::MutationGap);
    $this->write('a', 1, [Op::set('title', 'A')]);
    expect(fn () => $this->write('a', 1, [Op::set('title', 'B')]))->toThrow(ProtocolException::class);
    $differentId = $this->mutation('a', 1, [Op::set('title', 'A')], id: 'different');
    expect(fn () => $this->engine->process($differentId))->toThrow(ProtocolException::class);
});

it('accepts dependent offline writes but still detects intervening remote changes', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    expect($this->write('a', 2, [Op::set('title', 'AA')], dependsOn: 'a-1')->status)->toBe(MutationStatus::Applied);
    $this->write('b', 1, [Op::set('title', 'B')], 3);
    expect($this->write('a', 3, [Op::set('title', 'AAA')], dependsOn: 'a-2')->status)->toBe(MutationStatus::Conflict);
    expect($this->record()->value('title')->value())->toBe('B');
});

it('does not treat preserved or server-rejected proposals as accepted dependencies', function () {
    $this->seedRecord();
    $this->write('b', 1, [Op::set('title', 'B')]);
    $this->write('a', 1, [Op::set('title', 'A')]);
    expect($this->write('a', 2, [Op::set('title', 'AA')], dependsOn: 'a-1')->status)->toBe(MutationStatus::Conflict);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(3);
});

it('records permanent rejection so the next mutation can proceed', function () {
    expect($this->write('a', 1, [Op::set('title', 'absent')], 0)->status)->toBe(MutationStatus::Rejected);
    $create = $this->mutation('a', 2, [Op::set('title', 'created')], 0, kind: MutationKind::Create);
    expect($this->engine->process($create)->status)->toBe(MutationStatus::Applied);
});

it('keeps rejected stale updates after deletion and refuses implicit resurrection', function () {
    $this->seedRecord();
    $this->engine->process($this->mutation('a', 1, [], kind: MutationKind::Delete));
    expect($this->record()->deleted)->toBeTrue();
    $result = $this->write('b', 1, [Op::set('title', 'must survive')]);
    expect($result->reason)->toBe('entity_deleted');
    expect($this->store->receipt('b-1')->mutation->operations[0]->value->value())->toBe('must survive');
    expect($this->engine->process($this->mutation('c', 1, [], 0, kind: MutationKind::Create))->reason)->toBe('entity_exists');
});

it('supports partial and atomic domain application while preserving conflicts', function (bool $atomic) {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $result = $this->write('b', 1, [Op::set('title', 'B'), Op::set('body', 'new')], atomic: $atomic);
    expect($result->status)->toBe($atomic ? MutationStatus::Conflict : MutationStatus::Partial);
    expect($this->record()->value('body')->value())->toBe($atomic ? 'body' : 'new');
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
})->with([true, false]);

it('reports automatic resolver decisions', function (object $resolver, string $value, string $decision) {
    $this->setUpSync($resolver);
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $result = $this->write('b', 1, [Op::set('title', 'B')]);
    expect($this->record()->value('title')->value())->toBe($value);
    expect($result->decisions['title']->value)->toBe($decision);
})->with([[new ServerWins, 'A', 'server_wins'], [new ClientWins, 'B', 'client_wins']]);

it('requires explicit versioned resolution and preserves a late candidate', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $resolution = new Resolution($group->id, $group->revision, array_keys($group->candidates));
    $mutation = $this->mutation('r', 1, [Op::set('title', 'chosen')], 2, kind: MutationKind::Resolve, resolution: $resolution);
    expect($this->engine->process($mutation)->status)->toBe(MutationStatus::Applied);
    expect($this->openConflicts())->toHaveCount(0);
    $this->write('late', 1, [Op::set('title', 'late')]);
    expect($this->openConflicts())->toHaveCount(1);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
    expect($this->store->group($group->id)->resolved)->toHaveCount(2);
});

it('ordinary writes cannot erase open candidates and stale resolution cannot close them', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $group = $this->openConflicts()[0];
    $this->write('c', 1, [Op::set('title', 'C')], 2);
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
    $result = $this->engine->process($this->mutation('r', 1, [Op::set('title', 'resolved')], 2, kind: MutationKind::Resolve, resolution: new Resolution($group->id, $group->revision, array_keys($group->candidates))));
    expect($result->reason)->toBe('stale_resolution');
    expect($this->openConflicts()[0]->candidates)->toHaveCount(2);
});

it('paginates whole commits with immutable snapshots', function () {
    $this->seedRecord();
    $this->write('a', 1, [Op::set('title', 'A')]);
    $this->write('b', 1, [Op::set('title', 'B')]);
    $page = $this->store->pull('test', 0, 1);
    expect($page->commits)->toHaveCount(1);
    expect($page->commits[0]->changes)->toHaveCount(2);
    expect($page->nextCursor->value)->toBe(1);
    expect($page->commits[0]->changes[0]->record->value('title')->value())->toBe('initial');
    $next = $this->store->pull('test', $page->nextCursor->value, 100);
    expect($next->commits)->toHaveCount(2);
    expect($next->nextCursor->value)->toBe(3);
    expect(array_map(fn ($change) => $change->ordinal, $next->commits[1]->changes))->toBe([0, 1]);
});
