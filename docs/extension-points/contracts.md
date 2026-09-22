---
title: "Contracts"
weight: 10
description: "Inject policies and identifiers without introducing framework coupling."
---

# Contracts

`Engine` constructor accepts:

```php
new Engine($store, resolver: new PreserveConflict, ids: new UuidV7Generator, validator: new \Cbox\Sync\Validation\AcceptAll, observer: new NullCommitObserver);
```

- `Contracts\ConflictResolver::resolve(ConflictContext): ConflictDecision` chooses preserve, server, client or reject for a concurrent different field value. Context contains the immutable record, operation and mutation. Keep resolvers deterministic and side-effect free: external effects cannot roll back with storage.
- `Contracts\EntityValidator::validate(ValidationContext): ValidationResult` checks the complete post-merge entity within the storage transaction. See [validation](../core-concepts/validation.md) for failure/rollback semantics and cross-entity constraints.
- `Contracts\CommitObserver::committed(string $space, CommitSequence $sequence)` is told after each commit, outside the transaction - a notification, never part of the write. A failing observer is swallowed: the write has happened.
- `Contracts\IdGenerator::generate(): string` supplies conflict group IDs and the ids of `recordTrusted()` writes. Hosts can use the same generator for entities, replicas and mutations. The engine rejects duplicate group IDs. Generator state is not part of the transaction, so a failed attempt may consume an unused ID.
- `Contracts\Store` provides `transaction(string $space, Closure(Ledger): T): T`, the keyed committed reads (`record`, `receipt`, `group`, `openGroups`, `acknowledged`, `watermark`, `retainedFrom`), the two paginated reads views need (`commitsAfter`, `scanRecords`), `pull(...): PullPage` and `prune()`. Publication must be atomic, all stored objects immutable, and writers in one space serialized consistently with its feed order.
- `Contracts\Ledger` is the transaction itself: keyed reads, keyed writes, a one-level draft, and the write tracking (`recordChanged`, `touchedGroups`) the engine uses instead of comparing objects. Reads reflect writes made through the same ledger.
- `Contracts\Inspectable` exposes `snapshot(): State` for tests and diagnostics. It is deliberately not part of `Store`: a durable adapter cannot materialize its whole state, and no production path may depend on it.

Beyond `process()`, the engine offers `submit()` - the same, returning a `Submission` that says whether this call wrote the mutation, for a host that does its own work for a write that landed - and `recordTrusted()` for the host's own writes, deciding create or update, base and stream position inside the space lock.

Public classes are open for extension and capability interfaces can be faked or decorated. Value objects have cheap constructors but identity-bearing objects require explicit identity: empty invented entity/replica IDs are not useful defaults. Result defaults such as `new MutationResult(MutationStatus::Noop)` and `new PullPage` are useful in host tests. Readonly DTOs should be constructed directly rather than mocked.

`Views\QueryableView` is an optional capability on a view: `criteria(): RecordCriteria` describes its membership as a conjunction of exact field equalities so a store can narrow which rows it reads. Narrowing is an optimization only — the caller always re-applies `includes()`, because canonical JSON equality is stricter than any database's native JSON comparison. A view that does not implement it still works; its bootstrap just reads the space rather than an index.

The engine owns protocol processing, and the host owns transport, authorization and persistence selection. See [persistence](persistence.md) for the two adapters that ship and what each one proves.
