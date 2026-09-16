---
title: "Spaces and views"
weight: 45
description: "Bind each cursor to one ordered space and one exact filtered view."
---

# Spaces and views

A **sync space** is the boundary of one ordered change log and its consistency guarantees. It will commonly be a tenant, but the host chooses the meaning. The first protocol version synchronizes each space separately; it does not provide a cursor or atomic snapshot across spaces.

A **view** is a filtered subset inside one space, such as the entities assigned to a set of projects or resources. `ViewDefinition` deliberately exposes only identity, filter version, filter signature and membership evaluation. `FieldEqualsView` is the fixed reference implementation. It demonstrates the contract without introducing a query language.

`CursorContext` binds a cursor to all of the following:

- space;
- view identity;
- declared filter version;
- a signature of the actual filter and its values;
- schema version;
- server epoch.

The actual signature matters. Reusing the same view name and version while changing `project = alpha` to `project = beta` is rejected. A view-definition change, schema migration that changes projected meaning, epoch rotation, unknown bootstrap token, or cursor beyond the source log raises typed `ResetRequired`. The client must explicitly migrate compatible local state or discard that view's state and bootstrap again. Core supplies a reason and no HTTP status.

A cursor is continuity evidence, not authority. Every adapter must authenticate the caller and authorize the space, view definition and projected fields for every bootstrap and delta request.

## Membership transitions

The projector evaluates both `Change::previousRecord` and `Change::record`. Looking only at current state cannot reveal that an entity left a view.

| Previous membership | New membership | Projected change |
| --- | --- | --- |
| outside | inside | `upsert` with the full canonical record |
| inside | inside | `upsert` with the full canonical record |
| inside | outside, entity exists | `removed_from_scope` |
| inside | globally deleted | `deleted` |
| outside | outside | no projected change |

`removed_from_scope` never creates or replays a domain tombstone. `deleted` represents a canonical tombstone. An entity entering a view receives a full record even when its original create commit predates the cursor.

The reference projector exposes canonical record data and structured provenance. It intentionally does not copy mutation receipts or conflict groups into a filtered feed: either can contain proposed fields that are outside the view's authorized projection. A host that exposes conflict metadata must define and enforce a field-level projection policy. Membership filtering alone is not a data-redaction boundary.

## Multiple local views

Local retention belongs to the union of view memberships. `MultiViewClient` demonstrates the rule: an upsert adds ownership for its view, `removed_from_scope` removes only that view's ownership, and the local record is removed only when no subscribed view owns it. A global `deleted` change removes the entity and every membership.

For example, an item can be present in both an `open-items` view and a `project-alpha` view. Moving it to project beta removes only `project-alpha`; the item remains because `open-items` still owns it.

Views can arrive independently. Every projected change therefore carries the canonical `RecordVersion`, including removals and deletes. The reference client retains the highest observed version and a tombstone watermark after local deletion. An older upsert from a slower view cannot replace a newer record or resurrect a deleted entity. It also keeps each cursor under the full context fingerprint, which includes the space, so equally named views in separate spaces progress independently.

`MultiViewClient::applyBootstrap($page)` and `applyDelta($page)` derive identity from the page context; callers cannot label a page as another view. They stage record, membership, replay metadata and cursor changes on a clone and publish them together. Duplicate pages are no-ops, a gap or out-of-order page is rejected, and the cursor is exposed with `cursor($context)`. A production client must provide the equivalent local transaction.

An older frozen page can still establish that its view owned an entity at that snapshot without replacing a newer canonical record from another view. Tombstone barriers take precedence and prevent old membership or data from resurrecting a global delete. `resetView($oldContext)` clears one view's membership and progress for a compatible filter migration, but retains shared canonical/version/tombstone knowledge. When an epoch or retained history changes, discard all state for the old history; a fresh `MultiViewClient` demonstrates that stronger reset.
