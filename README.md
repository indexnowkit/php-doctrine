# `indexnowkit/doctrine` — commit-safe IndexNow for Doctrine ORM

Listens to `onFlush`/`postFlush`, resolves URLs of entities marked with `#[IndexNow]` and submits them
**after the outermost transaction really committed**, using a DBAL driver middleware (DBAL 3 and 4, ORM 2.19+ and 3).
Rolled-back flushes are discarded. Deletions are resolved before the row disappears.

Symfony users: take [`indexnowkit/symfony-bundle`](../symfony-bundle), it wires all of this.

Standalone:

```php
use IndexNowKit\{Config, IndexNow};
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Url\{AttributeUrlResolver, ArrayResolverLocator};

$indexNow = IndexNow::create(Config::fromEnv());
$resolver = new AttributeUrlResolver($indexNow->attributes, router: null, locator: new ArrayResolverLocator([
    'post_url' => fn (Post $p) => '/posts/'.$p->slug,   // #[IndexNow(resolver: 'post_url')]
]));
$wiring = new IndexNowDoctrine($indexNow, $resolver);

$wiring->registerMiddleware($ormConfig);        // before DriverManager::getConnection()
$wiring->registerListener($entityManager);
```

Bulk DQL `UPDATE`/`DELETE` do not go through the unit of work and are not detected.

MIT.
