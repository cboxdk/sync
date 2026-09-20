# Changelog

## 0.6.0 - 2026-09-20

### Added (breaking)

- **`OutboxStore::rekey()`** renames the entity every queued mutation refers to. A create carries a handle the device made up for itself, and the server answers with the name it gave the record; everything queued behind that create still refers to the handle and would be a write to a record that does not exist. Mutation identities are untouched - renaming what a write targets is not a new write, and giving it a new id would let the server apply it twice. Covered by a parity test that runs against both shipped stores.

  Only the key is renamed. A field VALUE holding the handle - a child carrying its parent's id - belongs to the application, and no store can know which of its fields are references, so `Outbox::rekey()` leaves that to the caller rather than doing it half way.

  **Breaking:** an implementation of `OutboxStore` must provide `rekey()`. Both shipped stores do.

- `EntityKey::equals()` and `Mutation::withEntity()`, which the rename needs and which were awkward to express from outside.

## 0.5.0 - 2026-09-20

### Fixed (breaking)

- **A conflicting mutation skipped the host's validator.** The validator ran only for `Applied`, `Partial` and `Noop`, and a conflict still commits its draft - so a preserved candidate reached storage without the host ever seeing it. In the Laravel transport the validator is what carries the authorization re-check against the record as locked, so the one path this package exists for was also the one path where a principal whose permission changed between the outer check and the lock had their proposal preserved anyway. The condition is now the exact inverse of the rollback beside it: everything that reaches storage is validated, and `Rejected` - the only outcome that never does - is the only one skipped.
- **Breaking:** `Store::commitsAfter()` takes an optional `$entityType`. An implementation of the contract must accept it; the reference adapters and anything extending them already do.

### Security

- Stored payloads are decoded against a named list of the 28 classes the engine actually stores, instead of `unserialize()`'s default of allowing any class. Any row an attacker can write - a restored backup, the replica database on an end-user's device, SQL injection elsewhere in the host - was an object-injection chain against whatever that application had loaded. A payload naming anything outside the set is refused rather than tolerated, because an unlisted class decodes to an incomplete object and a nested one would otherwise pass a type check while being unusable.
- Payloads carry a format version, so changing the encoding later is detectable on read instead of arriving years afterwards as a corrupt-looking failure. Rows written before the tag are unambiguous and still read; base64 cannot contain a colon.

### Performance

- **A view bound to one entity type no longer pays for another type's writes.** `delta()` read every commit in the space and filtered in PHP, which is the dominant cost of a poll on a busy tenant, per device. The entity type is now a column - a commit is one mutation on one entity - and a queryable view passes it as a hint. Measured on a catch-up over 2,000 commits across ten types with the view matching one: **255ms over twenty round trips, down to 7ms over two**, because the commit budget is no longer spent on commits that project nothing.
- The hint is never a filter the reader relies on: a commit stored before the column existed has no type, which is read as "unknown" rather than "does not match". `includes()` still decides.

### Added

- `EntityTypeView` - every live record of one entity type in the space. Only `FieldEqualsView` existed, so a host with no filter to apply had nothing to pass. It is queryable, so the store pages it through an index and a delta skips other types without decoding them.
- `migrate()` reconciles an existing installation. `CREATE TABLE IF NOT EXISTS` does nothing to a table that is already there, so an installation from an earlier release would keep a schema the adapter can no longer write to and find out on its first write after the upgrade. Missing columns and indexes are added; nothing is dropped or retyped, and a `NOT NULL` column with no default is refused by name rather than attempted. Verified on SQLite, MySQL 8 and PostgreSQL, including that a second run changes nothing.

## 0.4.0 - 2026-09-18

### Fixed (breaking)

- **The outbox is keyed by entity type and space, not by replica alone.** `head()` takes an optional entity type, and acknowledgement counters are per (replica, space) because that is how the server keys a stream. Two defects fall out of the old shape: a push naming one type drained the whole queue and submitted other types' writes as that type, and a device writing to a second space offered it a sequence number that space had never seen, wedging it. **Breaking:** `OutboxStore::head()`, `acknowledged()`, `setAcknowledged()` and `pending()` changed signature, and `Outbox::resumeAfter()` now takes the mutation whose answer it is acting on.
- `PdoStore::scanRecords()` matches a field that was never set. A predicate expecting no value required a `sync_fields` row to exist, so SQLite silently omitted exactly those records while the in-memory store returned them - and a bootstrap that omits a record still advances its cursor, so the delta never repaired it.
- The in-memory watermark no longer derives from retained commits. Pruning all history rewound it to zero and the next mutation reused a sequence a client had already consumed. It is now tracked per space, as the durable adapter already did.

- `Outbox::queue()` accepts `dependsOn`, and `MultiViewClient::pendingBootstrapToken()` exposes the token an interrupted bootstrap is waiting for. Both capabilities existed in the engine and were unreachable from a client: an offline write chain could not be expressed, and a bootstrap cut off part-way could only be restarted, which the replica correctly refuses as out of order.
- `PdoStore::prune()` is atomic, and a read re-checks the retention horizon after fetching. A prune landing between a reader's horizon check and its query removed commits the reader never saw, and it advanced its cursor past them as though they had been delivered.

### Tests

- Adds `NestedDataTest` and `RelationsTest`, pinning what field-level conflict detection actually means for nested documents and for entities that reference each other: nothing merges inside a field, a reordered object is the same value, an empty object and an empty array stay distinct at depth, a stale write to an untouched field still applies, a delete leaves a tombstone rather than a hole so following a reference reads a dead record, and a record survives leaving one view while another still owns it.

## 0.3.0 - 2026-09-16

### Durable client state

- `Views\MultiViewClient` keeps everything behind `Client\Contracts\ClientState` instead of eight private arrays, so a device that is killed mid-page comes back knowing what it knew rather than re-bootstrapping its whole dataset. Each page is applied in one state transaction: records, watermarks, memberships and the cursor move together, because a cursor that advanced without its records would claim progress the local data does not have.
- `Client\InMemoryClientState` is the default and behaves exactly as before; `Client\Pdo\PdoClientState` is the durable one, SQLite in practice. The whole view suite runs against both from the same fixtures via `SYNC_CLIENT=sqlite`.
- `MultiViewClient::__construct()` now takes an optional `ClientState`. **Breaking** only for anyone constructing it with arguments, which nothing did.
- Add `Client\Outbox` over `Client\Contracts\OutboxStore`, with in-memory and PDO implementations. It owns the four outcomes every client has to get right — done, retry the same identity, resume from here, give up on this one — because getting any of them wrong is silent data loss or a wedged queue.
- **A mutation's sequence is assigned when it is sent, not when it is queued.** Numbering at queue time meant a write the transport refused had already consumed a number the server would then wait for forever, and every later write came back as a gap for a number that would never arrive — a permanently wedged device. Found by running a real client against a real server; no test of either half alone would have shown it.

## 0.2.0 - 2026-09-16

### Security

- **A bootstrap token was a bearer capability for the space it named.** `ViewSyncService::bootstrap()` never validated the context, unlike `openBootstrap()` and `delta()`, so a token served pages on its own authority: two tenants sharing a view definition — the normal case, since the space is a separate axis and does not enter the filter signature — could read each other's data by forwarding the token, and an epoch rotation could not revoke an already-issued one. `bootstrap()` now takes the context the caller expects and refuses a page whose context does not match it. **Breaking:** the signature is now `bootstrap(CursorContext $context, ViewDefinition $view, BootstrapToken $token)`.
- Document what a transport must bind, in `docs/security/threat-model.md`: replica and mutation identity arrive from the client and are authority the engine honours without question, so an unbound replica id lets any caller claim another device's sequence numbers and wedge it permanently. Also states that a view filters rows and not columns, and that a record's field origins name the actor who wrote each one.

### Fixed

- `PdoStore` resolves its PDO handle per call through an overridable `connection()` rather than capturing it at construction. A host whose framework replaces the connection after a reconnect — what happens under a long-running worker — would otherwise open the transaction on the new connection while the writes went to the dead one, with the rollback rolling back nothing. Silent partial persistence, no error anywhere.

## 0.1.1 - 2026-09-16

### Fixed

- **MySQL installs now work at all.** The schema used `CREATE INDEX IF NOT EXISTS`, which SQLite and PostgreSQL accept and MySQL does not, so every MySQL migration failed on the first index. Indexes are now declared inside `CREATE TABLE IF NOT EXISTS` on MySQL, which is idempotent as a whole, and as separate statements elsewhere. Identity columns are bounded at 150 characters on MySQL so the widest composite index stays well inside InnoDB's key limit.
- **PostgreSQL installs now work at all.** Stored payloads are base64-encoded. PHP encodes private and protected property names with NUL bytes, and a PostgreSQL text column cannot hold those, so every read came back corrupt. It worked on SQLite and MySQL only because they are permissive about it. This changes the on-disk payload format; 0.1.0 wrote data only on SQLite, and any such database has to be rebuilt.
- Static analysis on PHP 8.5: `$argv` is read through `$GLOBALS` with a guard rather than assumed, and last-element access on a list uses `count() - 1` instead of `array_key_last()`, which is typed as possibly returning null.
- The shipped test trait migrates a shared database once and empties the tables per test instead of dropping and recreating seven tables each time, which cost minutes of DDL per MySQL run.

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
