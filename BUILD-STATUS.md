# Build status

Unreleased framework-independent PHP foundation, including the architecture revision. No runtime dependencies. Source repository: [cboxdk/sync](https://github.com/cboxdk/sync). No tagged release or package publication yet.

Implemented:

- Atomic domain application by default, explicit partial opt-in, complete blocked mutation preservation, per-field conflict detection and multi-candidate resolution.
- RejectOnConflict and typed strict revision/validation failures as terminal acknowledged outcomes; exceptions roll back all effects.
- Separate trusted actor/integration context alongside replica/mutation provenance; no-op receipts without canonical version increments or data notifications.
- Whole-entity validation inside the storage transaction, covering partial results and explicit resolution.
- Context-bound view cursors, immutable snapshot bootstrap, whole-commit delta, explicit global deletion versus scope removal, full representations on view entry.
- Atomic client page/cursor application with multi-view ownership, cross-view canonical version protection and retained tombstone barriers.
- Existing ordered/idempotent streams, safe offline dependencies, tombstones, in-memory rollback and seeded N-way simulator retained.

Verification on 2026-09-16:

- Pest: 61 tests, 1,405 assertions on PHP 8.4 and 8.5.
- Full composer qa passed: Pint, PHPStan max (source, testing fixtures and scripts), Pest, 61 dependency licenses and full locked dependency audit.
- Strict Composer metadata validation; SBOM and generated requirements reproduce without drift.
- Simulator seeds 7, 42 and 2026 pass on PHP 8.4 and 8.5.
- Executed quickstart, resolution, validator and bootstrap/delta documentation examples; relative links valid; Cbox documentation importer reports complete.
- Independent core and views review completed. Corrected stale cross-view overwrites, tombstone resurrection by old pages, and loss of membership from older frozen snapshots. No outstanding review findings.

Breaking changes are documented in CHANGELOG.md: atomic default, same-value resolution no longer increasing canonical versions, deleted feed kind, trusted processing context, new typed results and client view contracts.

Limits: synchronous single-process in-memory server/client state and bootstrap sessions; no crash durability or SQL concurrency proof; no transport/wire format, webhooks or framework integration; host authentication/authorization and cross-entity locking/constraints remain required. Journal, feed, candidates and sessions are retained without compaction. Filtered deltas project canonical data only; conflict/receipt delivery requires a separately authorized adapter projection. Epoch/history reset must discard old local state and version/tombstone barriers; compatible view-filter migration can reset only the old view. Restore and durable adapters remain out of scope.

There are no runtime packages to audit. Composer reports an empty-package error for audit --no-dev; composer security-audit checks the entire lock file, including development tooling, instead.
