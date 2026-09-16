---
title: "Run the simulator"
weight: 20
description: "Replay deterministic competing edits and response loss."
---

# Run the simulator

Run all three built-in seeds with one command:

```sh
composer simulate
```

Or supply an integer seed:

```sh
composer simulate -- 99
```

Each run creates one record, shuffles 100 offline client writes to the same field, and repeats every request one to four times to model lost responses. It verifies all 100 distinct proposals survive, including the first canonical change. It then explicitly resolves all candidates, delivers a late proposal, and reads the entire feed with a one-change page budget.

Successful runs report `100/100 proposals preserved` and `103 whole commits / 103 pages. OK`. The first client and retry count vary by seed; the proposal set and commit count must not. An invariant failure throws and produces a nonzero exit. Run `composer test` for deletion, independent fields, dependencies, transient storage failure and the remaining protocol scenarios.
