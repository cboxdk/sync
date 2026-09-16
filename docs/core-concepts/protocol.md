---
title: "Mutation protocol"
weight: 20
description: "Validate requests and advance acknowledgement only with a committed result."
---

# Mutation protocol

A `Mutation` carries its global ID, entity key, replica, sequence, kind, base version, explicit operations, optional dependency, optional resolution and optional `expectedVersion`. Types accept host-assigned nonempty string IDs; UUIDv7 is the default generator, not a mandatory ID encoding. Sequence starts at one per replica/space; create uses base zero.

Processing checks global mutation identity first. An exact retry under the same trusted actor/integration context returns the stored immutable result. Changing that trusted identity context also fails as a protocol error. Reusing that ID with changed content, including replica, sequence, operations, `from`, atomic mode, expected revision or resolution, throws `ProtocolException`. Reusing an acknowledged sequence with a different ID is also a protocol error. Fingerprints use internal PHP serialization of normalized request content (independent of PHP object sharing), not a public cross-language wire format. Operation ordering is part of the payload: retries must keep it.

The next expected sequence is processed. A higher sequence returns `mutation_gap` with the current acknowledgement and expected sequence in `reason`; it creates no receipt or commit. A gap result is not final: send the missing mutation and retry. A permanently processed mutation advances acknowledgement even if all domain proposals are rejected.

| Outcome | Examples | Receipt/acknowledgement | Client action |
| --- | --- | --- | --- |
| Permanent domain result | Applied, no-op, partial, preserved conflict; entity missing/deleted/existing; resolver rejection, strict precondition failure, validation failure, stale resolution or invalid candidate | Committed atomically | Advance queue, surface rejection/conflict; use a new mutation to correct intent |
| Invalid request | Negative version, future base, duplicate field operations, invalid dependency or resolution shape | None | Repair the request before retrying the unacknowledged sequence |
| Protocol error | Reused ID with changed payload or reused sequence with a new ID | None | Repair client identity/queue handling; never blindly retry changed content |
| Transient failure | Store failure; nested/concurrent store transaction | None | Retry the identical mutation |
| Mutation gap | Sequence above next expected | None | Deliver missing sequence first |

Unknown exceptions also roll back the transaction. A host should distinguish an operational retryable exception from a programming error; the core does not swallow either. A missing entity request with a nonzero base is a future-base invalid request. An update of an absent entity from base zero is a permanent `entity_not_found` rejection.

## Offline dependencies

Two offline edits can both carry the last downloaded `baseVersion`. The second sets `dependsOn` to the first mutation's ID. That ID must identify a processed earlier mutation for the same replica, space **and entity**. The engine computes each field's effective base as the maximum of the explicit base and that field's accepted version in the dependency result.

Only fields applied or found equal to canonical contribute new accepted versions. Preserved conflicts and `ServerWins` decisions do not grant new knowledge. Accepted knowledge propagates through dependency chains, including across writes to other fields. A remote change after that accepted field version still conflicts. Atomic mutations blocked by a conflict retain only inherited knowledge. Permanent rejections carry no accepted field knowledge, so a dependency on one is conservative.

Example: edit `title` offline as mutation A1, then edit it again as A2 with `dependsOn: A1`. A2 can replace A1's accepted title. If a different replica writes the title after A1, A2 preserves a conflict. `from` is untrusted context and never substitutes for this check.

Clients that already saw a committed canonical version may send that base directly. The host must authenticate replica ownership; these fields are not cryptographic proof that an untrusted caller actually read a version.

## Field concurrency and strict revision preconditions

Without `expectedVersion`, stale record bases can merge when the affected fields have not changed. This remains true under atomic default: atomicity is about applying the chosen domain edits together, not refusing all stale records.

Supply `expectedVersion: new RecordVersion($version)` to require an exact entity revision before field comparison or no-op detection. Absent records have revision zero for this check. A mismatch returns `MutationStatus::PreconditionFailed` and typed `PreconditionFailure` containing expected/actual versions. It changes no canonical or conflict state, but is a final mutation outcome with a receipt, commit and acknowledgement. This is suitable for an adapter implementing ETag/If-Match; core has no HTTP status codes.

The strict precondition is an additional guard; it does not rewrite `baseVersion` or the causal meaning of the field operations. Normally send matching base and expected versions for a strict edit. After the precondition passes, normal field conflict and resolution rules still apply. Even an equal target fails when the precondition is stale. Preconditions also apply to create, delete and resolution. Exact retries return the original terminal outcome; changed expected/base/payload requires a fresh ID and the next sequence.

## Trusted origin and no-op behavior

Call `Engine::process($mutation, new AdapterContext(actorId: ..., integrationId: ...))` from trusted host code. These are distinct identities from `Mutation::$replica` and `Mutation::$id`. The adapter authenticates replica ownership and obtains actor/integration from its trusted context. Do not map arbitrary client JSON into `AdapterContext`. The core API cannot authenticate PHP callers.

Each processed mutation receipt and every commit change carries `Provenance`, and field candidates retain their original actor/integration as well as replica/mutation. The same-value canonical origin stays intact; the new attempt's origin is in its receipt.

A real no-op creates only its receipt/acknowledgement commit. It does not increase record/field versions, emit a record change, or qualify as a normal data notification. `Change::isDataChange()` is true only for canonical record/deletion changes. A same-value resolution additionally emits necessary conflict metadata; it must not be optimized away. There is no notification or webhook transport. A future `excludeOwnChanges` option belongs to **notifications**, not delta: clients must still receive their own canonical writes in delta.
