---
title: "Architecture"
weight: 10
description: "Keep canonical revisions separate from feed and client order."
---

# Architecture

The core uses immutable value objects and DTOs. `Engine` depends on `Store`, `ConflictResolver`, `EntityValidator` and `IdGenerator`. The included store executes a synchronous callback against a cloned `State`; records, groups, mutations, receipts and feed entries inside that state are immutable. Success publishes a detached snapshot. An exception discards it. Nested or concurrent callbacks on the same store are refused.

| Concept | Scope | Advances when |
| --- | --- | --- |
| `RecordVersion` | Entity (`space`, public `type`, stable `id`) | Create, canonical field changes (including resolution to a different value), or delete |
| `FieldVersion` | Entity field | That field changes |
| `CommitSequence` | Sync space | Any mutation is permanently processed, even no-op or rejection |
| `MutationSequence` | Replica and space | Next expected mutation is permanently processed |

Each mutation produces at most one new record version. A missing field has implicit field version zero. An unset field keeps an explicit field state/version, so old writes cannot mistake it for a field that never existed. Resolution to the current value changes only conflict metadata; record and field versions stay unchanged. Conflict-only commits do not increment the record version; their group revision changes instead.

Every canonical field stores the mutation's ID, replica, sequence, original base version, actor, integration and proposed value as its origin. Actor/integration come from a separate trusted `AdapterContext`, not the mutation payload. Every processed mutation and result is also retained. Array DTOs rebuild their elements to detach PHP references. JSON field data is stored as immutable encoded text and decoded into a new value on access.

Timestamps do not establish causality. The default UUIDv7 generator uses time only for identifier layout, following RFC 9562 section 5.7; it does not promise monotonic ordering within one millisecond. Inject another `IdGenerator` when needed.

The reference `Store` exposes a whole-state transaction workspace intentionally. It proves a small working contract, not an efficient SQL repository design. A future durable adapter must provide isolation, atomicity, identity uniqueness and ordered visibility itself.

## Application and persistence boundaries

The processing pipeline is identity/sequence checks → strict preconditions → per-field conflict detection → resolver/merge → validation of the resulting complete entity → atomic persistence. Work is staged in a draft inside the store transaction. `RejectOnConflict` or a typed validation failure discards tentative records and conflict metadata, then commits the complete mutation, terminal result and acknowledgement. An exception discards the entire transaction, including that result and acknowledgement.

**Atomic domain application** is the default: one unresolved conflict blocks all proposed domain fields. `atomic: false` explicitly allows partial application, subject to whole-state validation. **Atomic persistence** always applies: canonical state, field versions, conflict metadata, receipt, feed and acknowledgement publish together. Partial domain application never means partial persistence.

A **space** is the ordered-log and consistency boundary, typically a tenant. A **view** selects a subset within one space. Each client cursor belongs to one contextualized view; spaces synchronize independently. See [views and cursors](views.md) and [bootstrap](../cookbook/bootstrap.md).
