---
title: "Client state"
weight: 60
description: "What a device keeps locally, and why it has to survive being killed."
---

# Client state

`Views\MultiViewClient` applies bootstrap pages and deltas. Everything it knows
lives behind `Client\Contracts\ClientState`, so a device that is killed
mid-page comes back knowing what it knew.

```php
use Cbox\Sync\Client\Pdo\PdoClientState;
use Cbox\Sync\Views\MultiViewClient;

$state = new PdoClientState(new PDO('sqlite:'.$path));
$state->migrate();

$client = new MultiViewClient($state);
```

`Client\InMemoryClientState` is the default and needs no arguments; it is for
tests and for a process that can afford to bootstrap again.

Each page is applied inside one state transaction. Records, version watermarks,
tombstone watermarks, view memberships and the cursor move together, because a
cursor that advanced without its records would claim progress the local data
does not have.

## What survives a reset, and why

`resetView()` drops one view's cursor, bootstrap progress and ownership. It
deliberately keeps the **canonical knowledge**: the highest version seen for
each entity and the delete watermark.

That watermark is the barrier that stops a slow or stale page from resurrecting
something the client already saw deleted. Losing it on a reset would turn every
epoch rotation into a chance for deleted records to come back.

## The outbox

`Client\Outbox` owns the ordering and retry semantics for one device, over
`Client\Contracts\OutboxStore` — in memory, or `Client\Pdo\PdoOutboxStore`.

```php
// The queue and the replica share one connection, so what the device knows and
// what it still owes commit against the same database.
$queue = new PdoOutboxStore($pdo);
$queue->migrate();

$outbox = Outbox::for($queue, new Replica($deviceId));
$outbox->queue($entity, MutationKind::Update, [FieldOperation::set('title', $title)], $baseVersion);
```

A sequence is assigned when a mutation is **sent**, not when it is queued.
Numbering at queue time looks harmless and is not: a write the transport refuses
has already taken a number the server never receives, so the server waits for it
forever and every later write comes back as a gap for a number that will never
arrive. The device is wedged permanently. Numbering at send time makes that hole
impossible, and it is why `head()` returns a mutation numbered for this attempt
rather than the one that was stored.

Once handed out, a write keeps its number for every resend, and while one on a
stream is waiting for its answer, that stream sends it again before numbering
anything else. A write may also be sent out of queue order with `take()` - a
parent's create ahead of the child that points at it - and the same rule keeps
it from claiming a number another write already used.

A transport sends `head()` and reports back exactly one of these outcomes:

| Outcome | Call | Why |
|---|---|---|
| processed — applied, conflicted or rejected | `acknowledged()` | all three are answers; the mutation is done |
| server is busy | nothing; send the same mutation again | the engine returns the stored result for a repeated id, so only an unchanged id is safe |
| server has not seen everything before this | `resumeAfter($acknowledgedSequence)` | drops only what it already has; the rest go again in order |
| terminal for this identity | `abandon($mutation, $reason)` | it can never be sent again, so it leaves the queue instead of blocking everything behind it |
| `receipt_pruned` | `settledUnknown($mutation)` | it may have been applied and nobody can say; it takes its own position so the replays behind it keep theirs |

Getting any of those wrong is silent data loss or a permanently wedged
queue, which is why they are implemented once here rather than in each
application. Abandoned mutations are kept with their reason and must be
surfaced: nothing else will tell the user that a write is never going to land.
