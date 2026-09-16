---
title: "Quickstart"
weight: 2
description: "Create a record and preserve two competing edits."
---

# Quickstart

From this local checkout:

```sh
composer install
composer simulate
```

Save the following as `example.php` in the project root and run `php example.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Engine;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Persistence\InMemoryStore;
use Cbox\Sync\Support\UuidV7Generator;
use Cbox\Sync\ValueObjects\{EntityKey, MutationSequence, RecordVersion, Replica};

$ids = new UuidV7Generator;
$store = new InMemoryStore;
$engine = new Engine($store);
$entity = new EntityKey('workspace-1', 'notes', $ids->generate());

$engine->process(new Mutation(
    $ids->generate(), $entity, new Replica('server'), new MutationSequence(1),
    MutationKind::Create, new RecordVersion(0), [Op::set('title', 'Draft')],
));

foreach (['Alice', 'Bob'] as $replica) {
    $result = $engine->process(new Mutation(
        $ids->generate(), $entity, new Replica($replica), new MutationSequence(1),
        MutationKind::Update, new RecordVersion(1), [Op::set('title', $replica)],
    ));
    echo $replica.': '.$result->status->value.PHP_EOL;
}
// Alice: applied
// Bob: conflict

$record = $store->snapshot()->records[$entity->key()];
echo $record->value('title')->value(); // Alice
$groups = $store->snapshot()->groups; // Alice and Bob candidates, with provenance
```

Persist a replica ID for the lifetime of a local database installation. Persist mutation IDs and sequences before sending, and retry the same mutation after a lost response. Names in this example are deliberately readable; `UuidV7Generator` is the default generator available for host-created IDs.

[Resolve the conflict](cookbook/resolution.md) or read [the protocol](core-concepts/protocol.md).

Entity mutations default to atomic application. Opt in to partial application with `atomic: false`; add `expectedVersion` when the whole entity revision must match. See [validation](core-concepts/validation.md) and [views/bootstrap](cookbook/bootstrap.md) before building an adapter.
