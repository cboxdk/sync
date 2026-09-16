---
title: "Resolve candidates"
weight: 10
description: "Choose a value and explicitly name the candidates it resolves."
---

# Resolve candidates

After the quickstart, choose an open group and its current canonical version:

```php
use Cbox\Sync\Data\Resolution;

$state = $store->snapshot();
$group = array_values($state->groups)[0];
$record = $state->records[$entity->key()];
$openIds = array_keys(array_diff_key($group->candidates, $group->resolved));

$result = $engine->process(new Mutation(
    $ids->generate(), $entity, new Replica('moderator'), new MutationSequence(1),
    MutationKind::Resolve, $record->version,
    [Op::set($group->field, 'Reviewed title')],
    resolution: new Resolution($group->id, $group->revision, $openIds),
));
```

The imports and variables other than `Resolution` come from the [quickstart](../quickstart.md). The selected value may be an existing candidate, a new value, null or unset. Supply a subset of open candidate IDs to leave others unresolved. In production, inspect `isOpen()` and select the intended group rather than assuming the first stored group is open.

If new candidates or canonical changes arrived, the result is a permanent `stale_resolution` rejection. Fetch fresh state and submit a **new** mutation with the next sequence. Retrying the old ID returns its original rejection. Resolved candidates record the resolving mutation ID, whose receipt contains the chosen value.

Atomic application is now the default. If the original mutation also proposed a conflict-free field that was blocked, retrieve that full mutation from its receipt and submit the approved remaining edit as a **new** mutation. A field resolution only handles the field and candidate IDs it names. Selecting the current canonical value changes group metadata without increasing canonical record/field versions.
