# `indexnowkit/doctrine` — commit-safe IndexNow for Doctrine ORM

Listens to `onFlush` / `postFlush`, resolves the URLs of entities that declare `#[IndexNow]` rules, and hands them
over **only after the outermost transaction really committed**, using a DBAL driver middleware. Rolled-back flushes
submit nothing. Deletions are resolved before the row disappears.

Doctrine ORM 2.19+ and 3.x, DBAL 3.x and 4.x, PHP 8.2+.

[Русская версия](README.ru.md)

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/doctrine)](https://packagist.org/packages/indexnowkit/doctrine)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/doctrine)](https://packagist.org/packages/indexnowkit/doctrine)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-orm%2014%2F14-brightgreen)](https://github.com/indexnowkit/spec)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)

**Symfony users: take [`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle)** — it wires all of this, adds the router
bridge, the commands and the profiler panel. This package is for Doctrine without Symfony.

## Install

```bash
composer require indexnowkit/doctrine
```

## Standalone wiring

```php
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
use IndexNowKit\{Config, IndexNowKit};
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Url\{ArrayResolverLocator, AttributeUrlResolver};

$indexNow = IndexNowKit::create(Config::fromEnv(), logger: $logger);

$resolver = new AttributeUrlResolver(
    $indexNow->attributes,
    router: null,                                    // no framework router: see "Routes" below
    locator: new ArrayResolverLocator([
        'post_url' => fn (Post $post): string => '/posts/' . $post->slug,   // #[IndexNow(resolver: 'post_url')]
    ]),
    logger: $logger,
);

$wiring = new IndexNowDoctrine($indexNow, $resolver, $logger, autoFlush: true);

$ormConfiguration = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/src/Entity'], isDevMode: false);
$wiring->registerMiddleware($ormConfiguration);      // BEFORE DriverManager::getConnection()

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => __DIR__ . '/var/app.db'], $ormConfiguration);
$entityManager = new EntityManager($connection, $ormConfiguration);
$wiring->registerListener($entityManager);
```

`registerMiddleware()` must run before the connection is created, because DBAL middlewares wrap the driver at
connect time. In a typical bootstrap that means: build the ORM `Configuration`, call `registerMiddleware()`, then
create the `EntityManager`, then call `registerListener()`.

`$autoFlush: true` submits as soon as the URLs are handed over, which is what a script or a CLI process wants. Pass
`false` and call `$indexNow->flush()` yourself at the end of the unit of work when you control the request cycle.

`IndexNowDoctrine` exposes the three pieces it builds — `$wiring->staging`, `$wiring->listener`,
`$wiring->middleware` — so a container can register them individually instead.

## Declaring pages

The `#[IndexNow]` attribute comes from the core and is repeatable: one rule per family of public URLs.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(resolver: 'post_url')]
#[IndexNow(via: 'category')]            // a changed post also refreshes its category page
#[IndexNow(urls: ['/'])]                // and the homepage
class Post
{
    #[ORM\Column]
    private bool $published = false;

    public function isPublished(): bool { return $this->published; }
}
```

Full model — sources, typed parameters, `when` / `whenFields` / `fields` / `events` / `locales` / `host`,
inheritance and the semantics table — is in the core's
[attribute reference](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

### Routes

`#[IndexNow(route: ...)]` needs a `RouteUrlResolverInterface` bridge to a framework router. Standalone Doctrine has
none, so a rule using `route:` fails at resolution time with *"no router bridge is configured"* (logged, never
thrown into your flush). Use `url:`, `urls:` or `resolver:` instead, or implement the two-method
`RouteUrlResolverInterface` for your own router and pass it as the `router:` argument above.

## What the listener does

In `onFlush`, every scheduled insertion, update, deletion and changed collection is classified **per rule** through
the core's `ObjectChangeHandler`:

- **Insertions** produce `created` events. Their URLs are resolved in `postFlush`, once identifiers are assigned.
- **Updates** are classified per rule from `UnitOfWork::getEntityChangeSet()`. A rule whose `when` turned false
  becomes a **deletion** and is resolved immediately in `onFlush`, while the old state is still live; a rule whose
  `when` turned true becomes a creation; otherwise it is an update, filtered by the rule's `fields`. One entity can
  therefore produce an update for one page and a deletion for another in the same flush.
- **Changed to-many associations** are not part of the owner's change set, so a scheduled collection update or
  deletion re-classifies its owner with the association's field name as the changed field. Changing `post.tags`
  resubmits the post's pages.
- **Deletions** are resolved in `onFlush`, before the row disappears. A rule that does not apply — a draft that was
  never public — submits nothing.

In `postFlush` the deferred rules are resolved, every URL is logged at `debug` with the rule that produced it
(`indexnow: App\Entity\Post#post_amp (updated) -> https://example.com/amp/hello`), and the batch is handed off.

Nothing here throws into your application. An invalid attribute, an unreadable `when` accessor or a failing resolver
is logged on the `indexnow` channel and yields no URLs.

## Renamed pages

When a field a route parameter reads changes — the slug, the category the path goes through — the old URL now
answers 404. On an update the listener resolves the rule against the **previous** values of the change set and
announces those URLs as deleted, next to the new URLs as updated, in the same flush (`ObjectChangeHandler::renamed()`,
scenario A21). Route rules only; the old page must have been public (`when` true before the change); a field the URL
depends on that cannot be written back (`readonly`, uninitialized) skips the old URL with a `debug` line. Nothing in
this path throws into `flush()`.

## Commit safety

`postFlush` runs before the outer `COMMIT` whenever `flush()` is wrapped in `wrapInTransaction()` or a manual
transaction, and Doctrine has no after-commit event. So:

- if the connection has an open transaction, the URLs are staged against its **native** connection object;
- the DBAL driver middleware sees the real `commit()` and `rollBack()` — nesting level 0, identically in DBAL 3 and
  4 (`Middleware\IndexNowConnection` / `IndexNowConnectionV3`, picked by `IndexNowDriver` at connect time) — and
  either releases the staged URLs or discards them;
- a `commit()` that itself throws discards them too, so a pooled connection never delivers them later;
- a nested transaction rolled back to its savepoint (`ROLLBACK TO SAVEPOINT`, what DBAL issues for an inner
  `rollBack()`) drops the URLs staged inside it; the outer `COMMIT` delivers the rest;
- outside a transaction the URLs are handed over immediately.

If the driver exposes no native connection object, the listener logs a warning and submits inside the open
transaction rather than losing the URLs.

## Limitations

- DQL and QueryBuilder bulk `UPDATE` / `DELETE`, and `Connection::executeStatement()`, bypass the unit of work and
  are not detected. Submit those URLs with `$indexNow->submit()`.
- `route:` needs a router bridge (see above).
- Entities inserted through `INSERT ... SELECT` never reach `postFlush`.
- Attributes are not read from interfaces or traits: PHP does not inherit class attributes through them, and
  Doctrine mapping behaves the same way.

## Compatibility with other listeners

Register the listener **after** anything that computes values the URLs depend on. With Gedmo Sluggable the slug is
written in `onFlush`, so the IndexNow listener must run later; the Symfony bundle uses priority `-100` for exactly
this reason.

## Compatibility

Public API of this package: the classes named in the changelog and the README, their constructor parameter names
(pass optional arguments by name), and the DBAL middleware classes. The core's rules apply, including the
"may grow" interfaces: [bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md). Before 1.0 a minor
version may break; every break is listed under "Changed" in [CHANGELOG.md](CHANGELOG.md) with the migration.

## Documentation

| | |
|---|---|
| Attribute reference | [core/docs/attribute-reference.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md) |
| Configuration | [core/docs/configuration.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md) |
| Operations and logging | [core/docs/operations.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md) |
| Testing | [core/docs/testing.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/testing.md) |
| Writing your own adapter | [core/docs/adapters.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md) |
| Design rationale | [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec) |

Changelog: [CHANGELOG.md](CHANGELOG.md). Versioning: SemVer; before 1.0 minor versions may break.

MIT.
