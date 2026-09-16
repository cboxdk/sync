---
title: "Cbox Sync"
weight: 1
description: "A PHP sync engine that preserves competing offline edits."
---

# Cbox Sync

Cbox Sync is a framework-independent engine for one authoritative server and many
offline clients. It applies independent field edits, preserves competing
proposals instead of silently picking a winner, and journals every processed
mutation with its acknowledgement in a single storage transaction.

Start with the [quickstart](quickstart.md) and [requirements](requirements.md).

- [Getting started](getting-started/_index.md): installation and tests.
- [Core concepts](core-concepts/_index.md): versions, conflicts, dependencies and feed.
- [Cookbook](cookbook/_index.md): resolve conflicts and simulate delivery.
- [Extension points](extension-points/_index.md): resolvers, IDs and [storage](extension-points/persistence.md).
- [Security and scope](security/_index.md): trust boundary and limitations.

Storage is durable on SQLite, MySQL 8+ and PostgreSQL. HTTP, a wire format,
authentication, Laravel and NativePHP are not included — the Laravel integration
lives in `cboxdk/laravel-sync`.
