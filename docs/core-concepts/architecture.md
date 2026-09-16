---
title: "Architecture"
weight: 10
description: "Keep canonical revisions separate from feed and client order."
---

# Architecture

The core uses immutable value objects and DTOs. `Engine` depends on `Store`, `ConflictResolver`, `EntityValidator` and `IdGenerator`. A store runs a synchronous callback against a `Ledger`: one transaction, scoped to one space, whose every read is keyed, so an adapter never materializes the whole store. Records, groups, mutations, receipts and feed entries are immutable. Success publishes atomically. An exception discards everything. The engine never compares object identity, so a ledger is free to rehydrate its values from rows.

| Concept | Scope | Advances when |
| --- | --- | --- |
| `RecordVersion` | Entity (`space`, public `type`, stable `id`) | Create, canonical field changes (including resolution to a different value), or delete |
| `FieldVersion` | Entity field | That field changes |
| `CommitSequence` | Sync space | Any mutation is permanently processed, even no-op or rejection |
| `MutationSequence` | Replica and space | Next expected mutation is permanently processed |

Each mutation produces at most one new record version. A missing field has implicit field version zero. An unset field keeps an explicit field state/version, so old writes cannot mistake it for a field that never existed. Resolution to the current value changes only conflict metadata; record and field versions stay unchanged. Conflict-only commits do not increment the record version; their group revision changes instead.

Every canonical field stores the mutation's ID, replica, sequence, original base version, actor, integration and proposed value as its origin. Actor/integration come from a separate trusted `AdapterContext`, not the mutation payload. Every processed mutation and result is also retained. Array DTOs rebuild their elements to detach PHP references. JSON field data is stored as immutable encoded text and decoded into a new value on access.

Timestamps do not establish causality. The default UUIDv7 generator uses time only for identifier layout, following RFC 9562 section 5.7; it does not promise monotonic ordering within one millisecond. Inject another `IdGenerator` when needed.

`Ledger` offers one level of nesting, `beginDraft`/`commitDraft`/`rollbackDraft`, over records and conflict groups only. It exists because a blocked domain mutation is discarded while its receipt, acknowledgement and commit are still published. A durable adapter implements it with a savepoint rather than a copy, which keeps staged writes visible to a validator sharing the transaction.

The commit sequence is not an auto-increment. `Ledger::watermark()` is a pure read and `appendCommit()` is the only thing that consumes a number, so a replay or a mutation gap — neither of which produces a commit — burns nothing. An adapter takes the space write lock when the transaction opens, before the first read, and therefore serializes writers within a space. That is what makes the sequence gapless and its visibility order match its numbering. A durable adapter still owns isolation, atomicity and global mutation-identity uniqueness: the space lock does not cover mutation IDs, which are global, so those need their own unique constraint.

## Application and persistence boundaries

The processing pipeline is identity/sequence checks → strict preconditions → per-field conflict detection → resolver/merge → validation of the resulting complete entity → atomic persistence. Work is staged in a draft inside the store transaction. `RejectOnConflict` or a typed validation failure discards tentative records and conflict metadata, then commits the complete mutation, terminal result and acknowledgement. An exception discards the entire transaction, including that result and acknowledgement.

**Atomic domain application** is the default: one unresolved conflict blocks all proposed domain fields. `atomic: false` explicitly allows partial application, subject to whole-state validation. **Atomic persistence** always applies: canonical state, field versions, conflict metadata, receipt, feed and acknowledgement publish together. Partial domain application never means partial persistence.

A **space** is the ordered-log and consistency boundary, typically a tenant. A **view** selects a subset within one space. Each client cursor belongs to one contextualized view; spaces synchronize independently. See [views and cursors](views.md) and [bootstrap](../cookbook/bootstrap.md).
