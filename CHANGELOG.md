# Changelog

## Unreleased

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
