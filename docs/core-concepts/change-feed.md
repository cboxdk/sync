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

Within one in-memory store, synchronous transactions assign sequences while staging the same snapshot that contains changes and acknowledgement. No writer can expose a later commit before an earlier one becomes visible. Exceptions discard the workspace, so no sequence is consumed.

A future database adapter must preserve this **committed visibility order**, including across records and replicas sharing a space. Ordinary auto-increment IDs do not prove it: transaction A can reserve 10, B can commit 11, a reader advances to 11, then A commits 10 and is skipped. Use a demonstrated serialization/locking or visibility-watermark design, and integration-test crash, isolation and concurrency behavior on the actual database. This spike proves none of those MySQL/PostgreSQL properties.

The [view service](views.md) projects this log using both old and new membership, emits full representations on entry and `removed_from_scope` on exit, and supports consistent bootstrap. Client-facing view cursors bind context; do not expose raw integer space-log offsets as interchangeable view cursors. Authorization, retention and durable cursor storage remain host responsibilities. Raw receipts include all proposed values, so raw log access requires authorization independent of any view filter.
