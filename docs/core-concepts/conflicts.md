---
title: "Field conflicts"
weight: 30
description: "Preserve concurrent proposals and resolve named candidates explicitly."
---

# Field conflicts

An omitted field means no operation. `FieldOperation::set('name', null)` creates a present null value. `FieldOperation::unset('name')` makes the field absent while retaining its version and origin. Whole JSON values are atomic at the named field boundary; there is no nested object/array merge. Object key order is normalized; list order and scalar types matter (`1`, `1.0` and `"1"` differ).

After strict revision preconditions pass, the engine compares the proposed value with canonical. Equality is a no-op, including a concurrent proposal equal to canonical. Otherwise it applies the value when the field version is at most the effective base; when newer, it calls the resolver. Future record bases are rejected before these comparisons.

| Resolver | Concurrent different value | Result decision |
| --- | --- | --- |
| `PreserveConflict` (default) | Keeps canonical; adds incoming candidate and canonical origin | `preserve_conflict` |
| `ServerWins` | Keeps canonical; retains rejected proposal in mutation journal | `server_wins` |
| `ClientWins` | Applies incoming; earlier canonical remains in journal/feed | `client_wins` |
| `RejectOnConflict` | Rejects the entire mutation without canonical or candidate changes | `reject_on_conflict` |

## Letting the writer decide

`Engine::process()` takes an optional third argument, `OnConflict`. The default,
`OnConflict::Resolve`, is everything above. `OnConflict::Pull` changes one case
only: a field the resolver decides to **preserve** is not preserved. The whole
mutation is refused with `pull_required` instead, and nothing is stored - no
record change, no group, no receipt, no acknowledgement, no commit.
`MutationResult::$conflicts` names the contested fields and `$recordVersion` is
the version that carries them.

The writer then sends the same mutation again - same id, same sequence - with
the operations it now wants and that record version as its base. The identity is
reused on purpose: the refusal stored nothing, so this is still the first time
the server records this write. `Mutation::rebased()` and `Outbox::rebase()` do
this for a queued write.

Decisions the resolver makes itself (`client_wins`, `server_wins`,
`reject_on_conflict`) are unaffected, so a writer cannot use `Pull` to get
around them. The mode is not part of the mutation's identity: a replay is
answered from its receipt whichever mode it arrives in.

## Decisions

Automatic decisions are returned per field, never hidden behind `latest()`. A server-wins mutation with no other changes returns `noop` **with** the server-wins decision; inspect `decisions` as well as `status`.

There is one open group per entity/field, with candidates identified by mutation ID plus field. Equal noncanonical proposals from different mutations remain separate candidates. Each candidate retains its own base and provenance. A later ordinary write leaves all open candidates intact. If canonical subsequently conflicts again, its new origin is added to that same group.

The first arrival can determine the temporary canonical value. The default guarantees preservation of proposals, not order-independent canonical convergence. A no-op equal to canonical stays in the journal and does not create a redundant conflict candidate.

`atomic: true` is the default. An unresolved conflict blocks **all domain field changes** from the mutation; candidates, the complete original mutation, result and acknowledgement still commit together. Access `Store::receipt($mutationId)->mutation->operations` to recover both conflicting and nonconflicting proposed fields. (`Inspectable::snapshot()` reaches the same data but is a testing seam: a durable adapter cannot materialize its whole state, so no production path may depend on it.) Resolving one conflict group does **not** automatically replay companion fields: the host presents the retained proposal and submits approved companion edits as a new mutation with a fresh base, ID and sequence.

Use `atomic: false` for explicit partial application: nonconflicting fields may apply and the result is `partial`. The resulting complete entity must still pass validation. `RejectOnConflict` rejects the whole mutation even in partial mode. `MutationResult::$conflicts` identifies concurrent fields, canonical/proposed values, field versions and effective bases; `decisions` records each resolver choice. Rejection is a terminal journaled outcome that advances acknowledgement. A retry with changed intent requires a new mutation identity.

Domain application atomicity is distinct from storage atomicity: both modes always persist all selected effects and protocol metadata atomically.

Resolution supplies the exact current record base, group ID/revision, field operation and explicit candidate IDs. Only named open candidates are resolved. Group revision catches new proposals that arrived without changing canonical record version. Resolved groups and their provenance remain stored. A late different candidate from before resolution opens a new group if the old group is closed, or joins the remaining open group. An equal late proposal is a journaled no-op. A resolution that selects the existing canonical value still closes the named candidates and advances group revision, but creates no canonical data-change and changes neither record nor field version.

## Delete

Without a strict revision precondition, a valid delete is authoritative even from an older base, and creates a tombstone with provenance while retaining fields and all conflict groups. Later updates are rejected as `entity_deleted`; their full proposed operations remain in the journal. Create never revives a tombstone, and resolve cannot write through one. Existing groups remain auditable on deleted records but cannot be resolved into domain values without a future restore policy. Restore and tombstone compaction are out of scope.

A global delete emits `deleted` with a tombstone. Moving an entity out of a view emits `removed_from_scope` in the projected feed and never creates a domain tombstone. See [views](views.md).
