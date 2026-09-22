# Changelog

## 0.9.0 - Unreleased

### Added

- **A writer can decide its own conflicts.** `Engine::process()` takes an optional `OnConflict`. With `OnConflict::Pull`, a field the resolver would have preserved is refused instead: status `pull_required`, and nothing is stored - no record change, no conflict group, no receipt, no acknowledgement, no commit. `MutationResult::$conflicts` names the contested fields and `$recordVersion` the version that carries them, and the writer sends the same mutation again, rebased on that version. Decisions the resolver makes itself - client wins, server wins, reject - are untouched, so a writer cannot use this to get around them. `Mutation::rebased()`, `Outbox::rebase()`.
- **The outbox gives a device what it was missing:** `requeue()` and `dismiss()` for an abandoned write, `nameOf()` for what a created record was called, and a rewrite of the fields the application names as references when a created record is named, so a child created offline reaches the server pointing at its parent's real id.
- `Contracts\OutboxStore` gains `replace()`, `resetAcknowledged()`, `nameOf()`, `dismiss()` and `queued()`, and `head()` takes a space.

### Added

- **`Engine::recordTrusted()`** for a host's own writes - a model save - deciding create or update, the base version and the stream position inside the space lock. Deciding them before the lock made concurrent saves race for a position.

### Fixed

- **A duplicate delivery inside a host's transaction is answered from its receipt.** On MySQL at REPEATABLE READ the host's snapshot predates the space lock, so a retry whose first copy had just committed was answered `sequence_behind`; the device renumbered a write that had landed and then abandoned it. A position already used is now looked up again with a read that sees the latest commit, and so is a dependency.
- **An echo is the writer's own knowledge.** `recordTrusted(..., echoOf:)` records what the host's table made of a device's write and folds its versions into that write's answer, so the device's next edit does not conflict with its own write. `asCreate:` writes every field when the log has never held the record - a row that existed before it was synced. An `If-Match` on a record the log does not hold fails the precondition instead of creating it.
- **A restored device is not stuck behind the pruned range.** `settledUnknown()` takes the server's acknowledged sequence; when the server is further along than the device, every write still queued on that stream is settled together and new writes go on after the server. It used to burn one position per write, so the device could write nothing new until it had crawled through the whole range.
- A failed rollback no longer hides the failure that caused it (a deadlock ends the transaction on MySQL, and the rollback after it failed too); SQLite's busy and PostgreSQL's lock timeout count as contention; a space name is checked before its row is created, and a missing space row fails instead of locking nothing.
- Handing out a write and rewriting it for a parent's new name lock the row on MySQL and PostgreSQL device stores, so neither overwrites the other with the copy it read before.
- `Ledger` gains `amendReceipt()`, `receiptOf()` (a dependency read as the latest committed state, within the stream) and `receiptAt()`, the latter reading a stream position's answer as the latest committed state by `(space, replica, sequence)` - a new index - so a locking read inside a host transaction never touches another tenant's range.
- An echo moves the writer's answer to its own version only when nothing came between the write and its echo, and adds only the fields it actually wrote - otherwise the device's next edit, based on that answer, overwrote another writer's change unseen.
- **The outbox knows which writes may already be on the server**: one refused as `receipt_pruned` or `protocol_violation`, and any write with a sending that got no answer at all (`OutboxStore::countSend()`, `countAnswer()`, `clearSends()`, `unanswered()`; `Outbox::answered()` for an answer that leaves the write queued). An answered sending did not land, and a gap or `pull_required` proves none did. `requeue()` refuses a may-have-landed write without `evenIfItMayHaveLanded`; `dismiss()` of such a create abandons the writes that need it as `parent_unknown` rather than `parent_abandoned` - this device never learned the record's name either way. `dismiss()` looks up the one row (`OutboxStore::abandonedOne()`) and returns how many dependants it took along. `Outbox::relatedBy()` holds the application's references for calls not given them.
- A restored stream is settled across every type on it (`OutboxStore::queuedOn()`), and only by the answer that is still current - a late copy of it no longer settles writes queued since; `settledUnknown()` returns how many it settled. `mapNames()` re-checks after locking that no other process has sent the write, stream counters are created without locking an existing row (two hand-outs deadlocked on MySQL), and device-store contention is a `TransientFailure`.
- **`Outbox::refused()`** keeps a write the server processed and refused as abandoned under the server's status, so a refused create goes on holding back its children and the refusal outlives the push that received it. **`dismiss()`** takes a dismissed create's dependants along as `parent_abandoned`, and **`requeue()`** refuses a `receipt_pruned` or `protocol_violation` write - one that may already be on the server - unless told `evenIfItMayHaveLanded`.
- **`Engine::submit()`** answers like `process()` and says whether this call wrote the mutation, so a host that writes its own table for a landed write does not repeat that work for a replay that raced it.
- **`Views\CurrentStateView`**, for a rule that judges a record as it is now - a host permission check on its own row. A change such a rule does not show now is delivered as a removal, the id and nothing else, whenever the underlying window spans either side of it. Judging history by the current row sent the content of a row created and deleted since the cursor, and never told a previous owner that a row had left.
- **A handle is only unique within its space.** A queued write's own record is renamed by the name given in its space; a reference or a scope is not rewritten when two spaces named the same handle differently, nor when a create for that handle is still queued - a handle reused for a new record used to be pointed at the old one. Tenant B's update of its `local-1` used to be rewritten to tenant A's record.
- **A late copy of a gap answer no longer takes a newer attempt's number away**, which later made a write that had landed be refused as a reused identity. `OutboxStore::resetAcknowledged()` returns whether it applied.
- **A write an earlier release left in flight is numbered the way that release would have**, not resent under the placeholder number its payload carries (`numbered` column, set on migrate).
- The MySQL device store runs its transactions at READ COMMITTED and creates stream counters with one statement, so a process waiting behind another's hand-out sees what that one sent; a write moved to another scope while being handed out is looked up again rather than numbered on the old scope's counter.
- The first writes to a new space no longer race: the space row is created with a statement that cannot fail on a duplicate, which on PostgreSQL used to abort the host's surrounding transaction.

- **A device's numbering could disagree with the server's.** Each entity type and space a device writes to is now its own replica stream. The server numbers per replica per space and maps types and scopes to spaces by rules the device cannot see; one stream per (type, space) keeps both sides counting the same thing, where a single lost response used to make a write collide and be abandoned for good.
- **A device out of step with the server after a restore could never push again.** A server restored from a backup answered every write with the same gap; `resumeAfter()` now sets the acknowledgement exactly, downward too, and only if the counter is still where that attempt numbered from. A device restored from a backup reused numbers the server held and had every write refused as a protocol violation; a new identity on a used number is now answered as a gap with reason `sequence_behind`, and the writer renumbers upward - except at or below the highest position of that stream whose receipt was actually pruned, where it could be a replay whose answer is gone. That is answered `receipt_pruned`: final for that one write, never renumbered and applied twice, but carrying where the stream is so the writer goes on with the next. Ordinary acknowledgements still only rise.
- **A dependency pruned with the log, or on another of the writer's streams, refused the dependent write.** It is treated as no knowledge now: the write is judged on its own base.
- **Acknowledging a create and renaming what is queued behind it are one transaction.** A crash between them used to leave updates addressed to a handle nothing could resolve.
- **MySQL treated `a` and `a ` as the same identifier.** `utf8mb4_bin` pads with spaces, and a mutation id differing only by a trailing space was answered with another mutation's receipt. Identity columns use `utf8mb4_0900_bin`, and `migrate()` retypes older installations. MySQL 8.0.17 or later is required.
- **One oversized identifier could stop every bootstrap of its view.** Identifiers are capped at 150 characters - what the columns hold - and may not contain NUL, in `EntityKey`, `Replica` and `Mutation`, so every entry point inherits it.
- **A preserved candidate skipped the value checks.** A conflict leaves the record unchanged, so the validator never saw the value being kept. The record is now also validated as it would be if the candidate were chosen.
- **Receipts grew without bound.** `prune()` drops the receipts written in the commits it removes. A replay older than the horizon is answered as a gap and applies nothing.
- Identifiers must be valid UTF-8 - MySQL and PostgreSQL refused anything else with a driver error that SQLite stored.
- A write handed out for sending is never rewritten by a later rename, since it may already be on the server. Writes scoped by a record created offline move to its name (`scopedBy`), and `requeue()` maps an abandoned write through every name given since. `resetAcknowledged()` is one atomic compare-and-set.
- **On MySQL, concurrent writers mostly failed.** In one space, a writer inside a host transaction numbered its commit from a snapshot older than the space lock and hit the commits primary key; across spaces, REPEATABLE READ's gap locks on shared indexes made writers deadlock each other (807 deadlocks for 600 writes in six spaces). The store's own transactions run at READ COMMITTED on MySQL now; inside a host's transaction the ledger uses locking reads. A deadlock or lock-wait timeout is a `TransientFailure` on every driver. `bin/concurrency.php` retries only that, counts it, and has a `--spaces=separate` mode that CI runs.
- **Two device processes could hand out a stale copy of a write** - undoing another process's rename or reference rewrite - or give two writes one number on a MySQL or PostgreSQL device store. Handing out re-reads the write inside the transaction and holds the stream's counter.
- A truncated deflate payload is refused instead of decoding to a prefix, and a write that would store a row larger than the reader accepts fails instead of producing a commit nobody can pull.
- An atomic proposal that preserves a conflict is validated whole, as it would be chosen.
- **The PDO outbox could not be installed on MySQL at all** (`CREATE INDEX IF NOT EXISTS`), and client schemas created by earlier releases kept `utf8mb4_bin`. Both are fixed, and the outbox suite now runs on MySQL and PostgreSQL too.
- Identifiers are bounded where a write is made rather than wherever a key is built, so a record an earlier release stored with a longer id stays readable.
- A change could be recorded as a `Record` carrying no record; found by the strict analysis rules.

### Performance

- **The log stores about a tenth of what it did.** Payloads are deflated (format 2): a one-field edit on a ten-field record went from ~22KB of commit to ~2.6KB, for ~20µs more per write. Inflating is bounded at 16MB. Formats 0 and 1 are still read. Requires `ext-zlib`.
- Delta narrowing reads two index ranges instead of an `OR` the SQLite planner could not index.

### Upgrading

- Run `migrate()`: it adds `sync_receipts.commit_sequence` and retypes MySQL identity columns. **On a large MySQL installation, run it in a maintenance window**: the collation change copies each table with writes blocked, once per table.
- A device keeps sending writes queued before the upgrade on the stream they were queued on, so their retries and `depends_on` still match. `OutboxStore` implementations outside this package need the new methods, and the PDO outbox gains an `entity_id` column in place.
- Format-2 payloads cannot be read by 0.8.x; a downgrade after writing is refused by name.

## 0.8.0 - 2026-09-21

### Added

- **`Contracts\CommitObserver`** — the engine tells a listener that a space advanced, so devices no longer have to ask to find out. Polling alone makes the interval a straight trade between how stale the data may be and how much load every idle device puts on the server.

  It carries the watermark and NOTHING else, on purpose. The log is per space, but authorization is per principal and per view: putting the changes in the signal would hand every listener everything written in that space, including the rows and fields a given reader may not see. The signal says there is something new, up to here; the reader then asks through the endpoint that knows who it is.

  Called after the transaction commits, never inside it — a rollback must not announce a write that did not happen, and a replay or a refusal appends no commit so it signals nothing. A throw cannot unmake a commit that already happened, so the engine does not let one reach the caller either: failing a push for a durably stored mutation would only make the client retry, meet its own receipt, and be told the same thing again.

  Delivery is at-most-once and unordered by contract, which is why a reader still polls on a slow timer. The signal makes sync prompt; the cursor is what makes it correct.

## 0.7.0 - 2026-09-21

### Fixed (breaking)

- **A record could be dropped from a bootstrap page.** PHP compares two numeric strings NUMERICALLY, so the in-memory store put `'9'` after `'10'` where a database puts it before - and compared `'1e2'` and `'100'` EQUAL, which made the keyset filter treat one of two distinct records as already passed and drop it while the page still reported itself finished. Entity ids are client input, so this was reachable on purpose. Identifiers are compared by bytes now, which is what the contract promises and what every driver's binary collation gives.
- **`putReceipt()` reported every database error as "mutation identity already recorded."** A deadlock, a dropped connection, an over-long identifier and a missing table all arrived as "already processed", and a caller that trusts that answer drops the write and reports success. Only a real integrity violation says it now.
- **Breaking:** `Store::prune()` is on the contract. The commit log is the only thing here that grows without bound, and a host given the contract had no way to reach the pruning both adapters already implemented - so there was no supported way to stop a busy tenant filling the disk. Both shipped adapters already provided it.
- **Breaking:** `beforeCommit()` takes the space on both adapters, and the in-memory workspace gets its own `beforePublish()` hook. They differed before, so a fault-injecting store could only extend the in-memory one - and the rollback test, which pins that a failure at the storage boundary takes back the domain state, the conflicts, the receipt, the acknowledgement and the commit together, had never run against a database on any driver. It now runs on whichever adapter the suite is running against, green on SQLite, MySQL 8 and PostgreSQL.
- **Breaking:** `commitDraft()` without a draft is refused on both adapters rather than being a silent no-op on one and a raw `PDOException` on the other - which on PostgreSQL also poisons the whole transaction. `OutboxStore::append()` refuses a duplicate identity on both, as `InvalidRequest` rather than a driver exception escaping the package's hierarchy; abandoning does not free the identity, because the durable store keeps the row and its key.

### Performance

- **A selective bootstrap page costs the page, not the space.** `scanRecords()` emitted a correlated `EXISTS` anchored on `sync_records`, so the planner drove from the record scan and probed `sync_fields` once for every record in the space - the field index was unreachable. The query now drives from `sync_fields`, whose index carries the keyset columns as well as the predicate, so one index both finds the records and returns them in order with no sort. Measured on a 32,000-record space: **331ms to 0.2ms**. The widened index is named apart from the one it replaces, so an existing installation gains it by reconciliation rather than by rebuilding an index on a live table; `sync_fields_lookup` is a strict prefix of it and can be dropped whenever the host chooses.
- **The outbox is indexed.** `head()` is called once per mutation while a device drains its queue, and the table had no index at all, so every call scanned it and sorted in a temp b-tree; `append()` took its position with `MAX(queued_at)`, which is O(n) per write. Measured on a 5,000-deep backlog: queueing **4,955ms to 583ms**, draining **~32,000ms to 957ms**. That cost lands on the device, on the day the user most needs it to work.
- `FrozenBootstrapSessions` retains a bounded number of sessions and drops the oldest. Each holds a fully materialized view and none was ever removed, so a long-lived process accumulated one per bootstrap until it ran out of memory. It also makes `BootstrapSessionExpired` an outcome a client can meet rather than a branch that could never be taken.

### Tests

- The parity suite pages a hostile identifier set - `'9'`, `'10'`, `'100'`, `'1e2'`, `'2'`, `'01'` and a trailing space - through both adapters one record at a time, and asserts each is seen exactly once. It used a friendly alphabet before, which is why the ordering defect above could hide in it.

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
