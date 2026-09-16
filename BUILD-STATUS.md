# Build status

Framework-independent PHP foundation, released as 0.2.0 on 2026-09-16. One runtime requirement, `ext-pdo`, for the durable adapter. Source repository: [cboxdk/sync](https://github.com/cboxdk/sync).

Implemented:

- Atomic domain application by default, explicit partial opt-in, complete blocked mutation preservation, per-field conflict detection and multi-candidate resolution.
- RejectOnConflict and typed strict revision/validation failures as terminal acknowledged outcomes; exceptions roll back all effects.
- Separate trusted actor/integration context alongside replica/mutation provenance; no-op receipts without canonical version increments or data notifications.
- Whole-entity validation inside the storage transaction, covering partial results and explicit resolution.
- Context-bound view cursors, paginated bootstrap, whole-commit delta, explicit global deletion versus scope removal, full representations on view entry.
- Atomic client page/cursor application with multi-view ownership, cross-view canonical version protection and retained tombstone barriers.
- Keyed `Ledger` transaction contract scoped to one space, with a one-level draft over records and groups; no engine dependence on PHP object identity.
- Durable `PdoStore` for SQLite, MySQL 8+ and PostgreSQL: space-lock serialization, gapless commit sequences, savepoint drafts, indexed view scans, retention with a typed reset.
- Two bootstrap strategies: frozen in-process pages with byte-identical retry, and stateless authenticated keyset tokens that any process can serve.
- Ordered/idempotent streams, safe offline dependencies, tombstones, transactional rollback and seeded N-way simulator, now on every adapter.

Verification on 2026-09-16:

- Pest: 91 tests, ~1,506 assertions, run three times from the same fixtures — in memory, against a store that shares no objects across commits, and against SQLite. PHP 8.4 and 8.5.
- Full composer qa passed: Pint, PHPStan max (source, testing fixtures and scripts), all three test runs, 61 dependency licenses and full locked dependency audit.
- Strict Composer metadata validation; SBOM and generated requirements reproduce without drift.
- Simulator seeds 7, 42 and 2026 produce identical results in memory, on SQLite and over a DSN.
- `bin/concurrency.php`: 6 OS processes writing one space produce a gapless ascending commit log with every replica fully acknowledged.
- Executed quickstart, resolution, validator and bootstrap/delta documentation examples; relative links valid; Cbox documentation importer reports complete.

The contract moved substantially before this first tag; CHANGELOG.md records what changed. Being 0.x, a minor may still move it again: Composer's caret is narrow below 1.0, so `^0.1` will not resolve a future 0.2.

Limits: no transport or wire format, no webhooks, no framework integration. Host authentication/authorization and cross-entity locking/constraints remain required. A space accepts one concurrent writer, by design: it is the ordering boundary. Stored payloads use PHP serialization, which is a storage detail of the reference adapter and not a cross-language format; the same is true of `Mutation::fingerprint()`. Crash durability under power loss depends on host database settings and is not proven here, nor is behaviour at non-default isolation levels or under lock-timeout tuning. Retention is available but never automatic. Filtered deltas project canonical data only; conflict/receipt delivery requires a separately authorized adapter projection. Epoch/history reset must discard old local state and version/tombstone barriers. Client-side state (`MultiViewClient`) is still in-process only. Restore remains out of scope.

There are no third-party runtime packages to audit. Composer reports an empty-package error for audit --no-dev; composer security-audit checks the entire lock file, including development tooling, instead.
