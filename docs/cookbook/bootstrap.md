---
title: "Bootstrap a filtered view"
weight: 30
description: "Move from a frozen initial snapshot to complete incremental delta without a gap."
---

# Bootstrap a filtered view

`ViewSyncService` handles the handoff from an initial snapshot to delta. It takes the source high watermark when the bootstrap opens; writes after that moment are delivered by the following delta, including creates, updates, deletes, entries into the view and removals from it.

How the pages themselves keep their place is a strategy, because the guarantee and its cost differ:

- `Views\FrozenBootstrapSessions` (the default) materializes the whole view when the bootstrap opens, so retrying a token returns the identical page. Sessions live in one process: every page after the first must reach the same worker.
- `Views\KeysetBootstrapSessions` stores nothing. The token carries the view context, the watermark and the keyset position, authenticated with a secret you supply, so any process can serve any page — which is what a load-balanced or queued deployment needs. The sort key is entity identity, which never changes, so a record that stays in the view can be neither skipped nor duplicated. What it gives up is byte-identical retry: a page reflects the records as they are when it is served. Convergence still holds, because the client applies the delta from the watermark taken at open and its version and tombstone barriers discard anything older than the bootstrap already delivered.

Every page is presented with the view it belongs to, so the context binding is checked on each call rather than only at open.

```php
use Cbox\Sync\Client\InMemoryClientState;
use Cbox\Sync\Views\FieldEqualsView;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ViewSyncService;

$view = FieldEqualsView::matching(
    viewId: 'project-alpha',
    version: 'v1',
    field: 'project',
    expected: 'alpha',
);

$views = new ViewSyncService($store, schemaVersion: '1', epoch: '2026-09');
$client = new MultiViewClient(new InMemoryClientState);

// The context is held for the whole bootstrap, not rebuilt per page: every
// page is presented with the view it belongs to, so the binding is checked on
// each call rather than only at open.
$context = $views->context('tenant-1', $view);
$token = $views->openBootstrap($context, $view, pageSize: 100);

do {
    $page = $views->bootstrap($context, $view, $token);
    $client->applyBootstrap($page);
    $token = $page->nextToken;
} while ($token !== null);

// Present only on the final page. Save it after every snapshot record is durable.
$cursor = $client->cursor($page->context) ?? throw new \LogicException('Bootstrap did not finish');
```

A `BootstrapToken` names one immutable page in one retained frozen session. It is not a sync cursor. Retrying a token returns the same records and next token even when new writes have committed. Each `BootstrapPage` repeats the frozen `CursorContext`, request token and offset, so a client can bind even the first page to its authorization and local view state. The token is useful only while that bootstrap session is retained; this in-memory implementation retains it for the service object's lifetime. A durable adapter must persist or otherwise reconstruct frozen sessions for its promised retry window.

Only the final bootstrap page contains a `ViewCursor`, positioned at the frozen snapshot's source watermark. The client must not save it early. Apply all bootstrap records and make the final cursor durable as one recoverable local transition. If this cannot complete, retry the continuation token or restart bootstrap; do not invent a cursor from a token.

Then consume delta:

```php
do {
    $delta = $views->delta($cursor, $view, commitBudget: 100);
    $client->applyDelta($delta);
    $cursor = $client->cursor($delta->cursor->context) ?? throw new \LogicException('Delta cursor was not stored');
} while ($delta->hasMore);
```

The budget counts source commits. A source commit is read whole and never split between pages. Commits with no changes for this view are omitted from `DeltaPage::commits`, while the returned cursor still advances over them. That prevents an irrelevant stretch of the space log from trapping the client. Each delta page carries both its required previous cursor and resulting cursor. Retrying an already applied range is a no-op; a gap is rejected. Applying the changes, per-entity version/tombstone watermarks, membership updates and resulting cursor atomically prevents skipped changes and cross-view regressions.

Delta includes the client's own writes and their canonical server result. A future `excludeOwnChanges` option may suppress notifications, but it must not remove those writes from synchronization history.

Start a fresh bootstrap or perform an explicit compatible migration when `ResetRequired` reports changed context, a cursor ahead of retained history, or an unavailable bootstrap token. For a compatible filter migration, call `resetView($oldContext)` before bootstrapping the replacement context. That operation deliberately retains canonical revision and tombstone barriers shared with other views.

Epoch or history reset is broader: record versions may restart or refer to a different history, so retaining those barriers can reject valid new records. Discard all old canonical, membership, cursor, revision and tombstone state by creating a fresh `MultiViewClient`, or perform the equivalent per-space reset atomically in a real client. Epoch rotation is the adapter's explicit signal that existing cursors no longer address the same retained history. Cursor age alone does not prove validity; a durable adapter must compare it with its retention boundary.

The reference service uses the in-memory store's already ordered commits. A database implementation must establish the same committed visibility order. An auto-increment identifier by itself does not prevent a later transaction from becoming visible before an earlier reserved identifier.
