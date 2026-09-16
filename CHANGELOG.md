# Changelog

## 0.1.0 - 2026-09-16

### Durable storage

- Add `Persistence\Pdo\PdoStore`, a durable adapter for SQLite, MySQL 8+ and PostgreSQL, with no runtime dependency beyond `ext-pdo`. `PdoSchema` holds the DDL for hosts that manage their own migrations.
- Every mutation takes the space row's write lock as its first statement, which is what makes commit sequences gapless and their numbering agree with visibility order. `bin/concurrency.php` proves it with real concurrent processes.
- The full test suite runs against the in-memory store, a store that shares no objects across commits, and SQLite, from the same fixtures. CI adds PostgreSQL and MySQL.
- `bin/simulate.php` accepts `--store=sqlite` or `--dsn=…` and produces identical results on every adapter.

### Durable storage contract (breaking changes)

- Replace the whole-state transaction workspace with `Contracts\Ledger`: one transaction scoped to one space, every read keyed, so an adapter never materializes the store. `Store::transaction()` now takes the space and is generic over the callback's return type.
- The engine no longer compares object identity to decide what changed. `Ledger::recordChanged()` and `Ledger::touchedGroups()` track writes instead, so a store that rehydrates values no longer emits a phantom record change on every no-op, rejection and precondition failure. Conflict changes now follow first-touch order, which matches `MutationResult::conflictGroupIds`; they previously followed group creation order.
- Commit sequences are consumed only by `appendCommit()`; `watermark()` is a pure read. A replay and a mutation gap consume nothing. Adapters take the space write lock when the transaction opens.
- `Store` gains keyed committed reads (`record`, `receipt`, `group`, `openGroups`, `acknowledged`, `watermark`, `retainedFrom`) and the paginated reads views need (`commitsAfter`, `scanRecords`). `snapshot(): State` moves to `Contracts\Inspectable`, for tests and diagnostics only.
- Add retention: `retainedFrom()`, `InMemoryStore::prune()`, `Exceptions\HistoryUnavailable` and `ResetReason::HistoryPruned`. Pruning moves the horizon without renumbering sequences.
- Bootstrap pagination becomes a strategy. `Views\FrozenBootstrapSessions` keeps the previous byte-identical retry in one process; `Views\KeysetBootstrapSessions` stores nothing and lets any process serve any page, using an authenticated token. `ViewSyncService::bootstrap()` now takes the view alongside the token, so context binding is checked on every page. Adds `ResetReason::BootstrapSessionExpired`.
- Add `Views\QueryableView` with `Data\RecordCriteria` and `Data\FieldPredicate` so a store can narrow a bootstrap scan. Narrowing never decides membership; `includes()` still does.

### Architecture revision (breaking changes)

- Entity mutations default to `atomic: true`; partial apply requires `atomic: false`. Complete blocked proposals remain in mutation receipts.
- Add `RejectOnConflict`, typed strict `expectedVersion` precondition results and typed post-merge validation failures. These are terminal acknowledged outcomes; changed retries require new identities/sequences.
- Add separate trusted actor/integration context on processing and structured receipt/change/candidate provenance. Replays must retain that context.
- Equal-value resolution changes conflict metadata only, without canonical version increments or data notifications. The earlier equal-value resolution revision barrier is removed.
- Global tombstone log entries now use `deleted`, distinct from view `removed_from_scope`. Record changes include previous state for membership transitions.
- Add view-context cursors, frozen bootstrap pagination, incremental view deltas and a multi-view client reference. Raw `Store::pull` integer offsets remain infrastructure-only and must not be used as view cursors.

### Initial foundation

- Initial framework-independent `Cbox\Sync` core with immutable typed values, field operations, create/update and tombstones.
- Explicit multi-candidate conflicts, provenance, preserve/server/client policies, partial or atomic domain mutation and versioned resolution.
- Ordered mutation streams, global mutation identity checking, dependencies for offline write chains and atomic receipt/acknowledgement persistence.
- In-memory snapshot transactions and whole-commit change-feed pagination.
- Seeded simulator, adversarial Pest tests, developer fakes, PHPStan max, Pint, dependency license checks and deterministic CycloneDX SBOM.
