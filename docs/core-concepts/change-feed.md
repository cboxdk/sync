---
title: "Change feed"
weight: 40
description: "Pull whole ordered commits without skipping delayed transactions."
---

# Change feed

`Store::pull($space, $after, $limit)` is the low-level unfiltered space-log API for trusted infrastructure, not a client view cursor. It returns `PullPage`: complete `Commit` objects, `nextCursor` and `hasMore`. A commit has a per-space sequence and zero-based change ordinals. Changes contain immutable before/after record snapshots, changed conflict groups, and the mutation receipt with acknowledgement. Every change has structured provenance. A canonical tombstone change uses kind `deleted`; no-op receipts are not canonical data changes. A receipt is emitted for every processed mutation, including a permanent rejection or a no-op.

`limit` is a soft budget in **changes**, not commits. If the next commit exceeds the budget, it is returned whole. Once a page has a commit, adding another must fit the budget. The cursor is the last fully returned commit sequence, or the supplied cursor for an empty page. Negative/future cursors and limits below one are invalid. Always apply a whole commit and persist the client's cursor atomically.

```php
$cursor = 0;
do {
    $page = $store->pull('workspace-1', $cursor, 100);
    foreach ($page->commits as $commit) {
        // Apply every change in this commit in one client transaction.
    }
    $cursor = $page->nextCursor->value;
} while ($page->hasMore);
```

A sequence is consumed only by `Ledger::appendCommit()`. `Ledger::watermark()` is a pure read, so a replay and a mutation gap — neither of which produces a commit — consume nothing, and an exception discards the transaction without consuming anything either. Sequences therefore have no gaps.

An adapter must also preserve **committed visibility order**, including across records and replicas sharing a space. Ordinary auto-increment IDs do not prove it: transaction A can reserve 10, B can commit 11, a reader advances to 11, then A commits 10 and is skipped. The contract's answer is to take the space write lock when the transaction opens, before the first read, which serializes writers within a space and makes numbering and visibility agree. The honest cost is one concurrent writer per space; a space is the consistency boundary, so that is where the trade belongs. Crash, isolation and concurrency behavior still has to be integration-tested on the actual database.

The [view service](views.md) projects this log using both old and new membership, emits full representations on entry and `removed_from_scope` on exit, and supports consistent bootstrap. Client-facing view cursors bind context; do not expose raw integer space-log offsets as interchangeable view cursors. Authorization and durable cursor storage remain host responsibilities. Retention is not: a space reports `watermark()` and `retainedFrom()`, a cursor is valid only within that window, and a read below the horizon raises `HistoryUnavailable`, which the view service turns into `ResetRequired(HistoryPruned)`. Pruning does not renumber anything — sequences keep their values, the horizon simply moves. Raw receipts include all proposed values, so raw log access requires authorization independent of any view filter.
