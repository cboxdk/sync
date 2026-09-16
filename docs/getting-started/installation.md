---
title: "Installation"
weight: 10
description: "Use the unreleased local checkout or a Composer path repository."
---

# Installation

The package has no tagged release yet. Clone [cboxdk/sync](https://github.com/cboxdk/sync), then install its development tools from the checkout:

```sh
composer install
composer qa
composer simulate
```

To use it in another local PHP project, add a Composer path repository pointing at this checkout, then require `cboxdk/sync` with `*@dev`. Keep that repository configuration in the consuming project. The namespace is `Cbox\Sync`.

See the generated [requirements](../requirements.md). There are no third-party runtime dependencies; Pest, Pint and PHPStan are development tooling. Laravel is not required. Composer's lock file records the exact tooling used for the spike.
