# Backward compatibility

`indexnowkit/doctrine` follows SemVer. **Before 1.0, minor versions may contain breaking changes**; every one is listed
under "Changed" in [CHANGELOG.md](../CHANGELOG.md) with the migration. After 1.0 the rules below become the promise.
The core's tiers ("call", "implement", "may grow") apply to every core class you touch through this package:
[core bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md).

## What the package keeps stable

| Surface | Promise |
|---|---|
| **`IndexNowDoctrine`** (`registerMiddleware()`, `registerListener()`, the constructor's named parameters) | Method and parameter names stay; new parameters are appended with defaults. |
| **`IndexNowListener`** (the `onFlush` / `postFlush` subscriber) and **`Middleware\IndexNowMiddleware`** | Their constructors' named parameters and the Doctrine events they subscribe to stay. They are `final`: put your own logic on top of the core `ObjectChangeHandler`, not on a subclass. |
| **Behaviour** described in the README: URLs resolved in `onFlush` while the old state is live, handed over only after the outermost transaction committed, nothing on rollback, deletions resolved before the row disappears, savepoints honoured | A change to any of these is a breaking change and appears in the changelog with the conformance ids (A01–A21) it affects. |
| **DBAL 3 and 4, ORM 2.19+ and 3** | Dropping a major of either is a breaking change of this package. |

Not a contract: `Middleware\IndexNowDriver`, `Middleware\IndexNowConnection`, `Middleware\IndexNowConnectionV3`,
`Middleware\SavepointStatement` (the DBAL wrapping, which follows DBAL's own API), and log message texts (their
`context` keys are).

## Pinning

`composer require indexnowkit/doctrine:^0.5` gets every 0.5.x patch. Read the changelog before a minor.
