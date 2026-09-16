---
title: "Contracts"
weight: 10
description: "Inject policies and identifiers without introducing framework coupling."
---

# Contracts

`Engine` constructor accepts:

```php
new Engine($store, resolver: new PreserveConflict, ids: new UuidV7Generator, validator: new \Cbox\Sync\Validation\AcceptAll);
```

- `Contracts\ConflictResolver::resolve(ConflictContext): ConflictDecision` chooses preserve, server, client or reject for a concurrent different field value. Context contains the immutable record, operation and mutation. Keep resolvers deterministic and side-effect free: external effects cannot roll back with storage.
- `Contracts\EntityValidator::validate(ValidationContext): ValidationResult` checks the complete post-merge entity within the storage transaction. See [validation](../core-concepts/validation.md) for failure/rollback semantics and cross-entity constraints.
- `Contracts\IdGenerator::generate(): string` supplies conflict group IDs. Hosts can use the same generator for entities, replicas and mutations. The engine rejects duplicate group IDs. Generator state is not part of the transaction, so a failed attempt may consume an unused ID.
- `Contracts\Store` provides `transaction(Closure): MutationResult`, `snapshot(): State` and `pull(...): PullPage`. The callback receives a transaction workspace; publication must be atomic, all stored objects immutable, and concurrent processing serialized consistently with per-space feed order.

Public classes are open for extension and capability interfaces can be faked or decorated. Value objects have cheap constructors but identity-bearing objects require explicit identity: empty invented entity/replica IDs are not useful defaults. Result defaults such as `new MutationResult(MutationStatus::Noop)` and `new PullPage` are useful in host tests. Readonly DTOs should be constructed directly rather than mocked.

The included whole-state `Store` interface is a reference contract for this spike. A scalable database adapter may need a narrower transaction repository API; settle that based on an actual adapter rather than adding unused abstractions now. The engine owns protocol processing, and the host owns transport, authorization and persistence selection.
