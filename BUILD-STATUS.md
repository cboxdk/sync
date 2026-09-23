# Build status

Framework-independent PHP foundation for offline sync, released as 0.9.0 on 2026-09-22. Runtime requirements: `ext-pdo` and `ext-zlib`; a durable store needs SQLite 3.24+, MySQL 8.0.17+ or PostgreSQL 9.5+. Source repository: [cboxdk/sync](https://github.com/cboxdk/sync).

Implemented:

- Per-space gapless commit log with field-level conflict detection, whole-candidate preservation, pluggable resolvers, and receipts that answer a replayed mutation exactly as the first delivery was answered.
- `OnConflict::Pull`, so a writer can be refused a stale edit and rethink it, and `Engine::recordTrusted()` / `submit()` for a host's own writes, decided inside the space lock.
- Views: paginated bootstrap, whole-commit delta, membership transitions, and `CurrentStateView` for rules that can only judge a record as it is now.
- Durable PDO store for SQLite, MySQL and PostgreSQL, with retention (`prune()`), payload compression, and a schema that reconciles itself on install.
- A device-side outbox: durable queue, one write in flight per stream, parent-first sending of records created offline, renaming to server ids, and every recovery path a lost answer needs.

Verification: the suite runs against the in-memory store, SQLite, MySQL 8.4 and PostgreSQL, with a multi-process concurrency experiment proving the log stays gapless under contention, and a simulator over deterministic seeds. Pint, PHPStan max with the strict rules, dependency licenses, an audit and a docs-example parser run on every build; see `composer qa`.

Limits: no HTTP transport or framework integration here - `cboxdk/laravel-sync` serves it and `cboxdk/laravel-sync-client` consumes it. Conflict candidates are not filtered for a reader: a host that exposes them must decide what a caller may see.
