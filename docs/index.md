---
title: "Cbox Sync"
weight: 1
description: "A PHP sync engine that preserves competing offline edits."
---

# Cbox Sync

Cbox Sync is a framework-independent correctness spike for one authoritative server and many offline clients. It applies independent field edits, preserves competing proposals, and journals every processed mutation with its acknowledgement in a single in-memory transaction.

Start with the [quickstart](quickstart.md) and [requirements](requirements.md).

- [Getting started](getting-started/_index.md): installation and tests.
- [Core concepts](core-concepts/_index.md): versions, conflicts, dependencies and feed.
- [Cookbook](cookbook/_index.md): resolve conflicts and simulate delivery.
- [Extension points](extension-points/_index.md): resolvers, IDs and storage.
- [Security and scope](security/_index.md): trust boundary and limitations.

This is an unreleased PHP core. HTTP, durable storage, Laravel/Eloquent and NativePHP/SQLite integration are not included.
