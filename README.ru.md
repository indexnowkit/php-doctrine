# `indexnowkit/doctrine` — IndexNow для Doctrine ORM с гарантией коммита

Слушает `onFlush` / `postFlush`, вычисляет URL сущностей, объявивших правила `#[IndexNow]`, и передаёт их дальше
**только после реального коммита внешней транзакции** — через DBAL driver middleware. Откатанный flush не
отправляет ничего. URL удаляемых объектов вычисляются до того, как строка исчезнет.

Doctrine ORM 2.19+ и 3.x, DBAL 3.x и 4.x, PHP 8.2+.

[English version](README.md)

**Пользователям Symfony: берите [`indexnowkit/symfony-bundle`](../symfony-bundle)** — он собирает всё это сам,
добавляет мост к роутеру, команды и панель профайлера. Этот пакет — для Doctrine без Symfony.

## Установка

```bash
composer require indexnowkit/doctrine
```

## Сборка без фреймворка

```php
use IndexNowKit\{Config, IndexNowKit};
use IndexNowKit\Doctrine\IndexNowDoctrine;
use IndexNowKit\Url\{ArrayResolverLocator, AttributeUrlResolver};

$indexNow = IndexNowKit::create(Config::fromEnv(), logger: $logger);

$resolver = new AttributeUrlResolver(
    $indexNow->attributes,
    router: null,                                    // без роутера фреймворка: см. «Маршруты» ниже
    locator: new ArrayResolverLocator([
        'post_url' => fn (Post $post): string => '/posts/' . $post->slug,   // #[IndexNow(resolver: 'post_url')]
    ]),
    logger: $logger,
);

$wiring = new IndexNowDoctrine($indexNow, $resolver, $logger, autoFlush: true);

$wiring->registerMiddleware($ormConfiguration);      // ДО DriverManager::getConnection()
$wiring->registerListener($entityManager);
```

`registerMiddleware()` обязан выполниться до создания соединения: DBAL-middleware оборачивают драйвер в момент
подключения. В обычном бутстрапе порядок такой: собрать ORM `Configuration`, вызвать `registerMiddleware()`, создать
`EntityManager`, вызвать `registerListener()`.

`$autoFlush: true` отправляет URL сразу после передачи — это то, что нужно скрипту или CLI-процессу. Передайте
`false` и вызывайте `$indexNow->flush()` сами в конце единицы работы, если вы управляете циклом запроса.

`IndexNowDoctrine` открывает три собранных объекта — `$wiring->staging`, `$wiring->listener`,
`$wiring->middleware`, — чтобы контейнер мог зарегистрировать их по отдельности.

## Объявление страниц

Атрибут `#[IndexNow]` приходит из core и повторяем: одно правило на семейство публичных URL.

```php
use IndexNowKit\Attribute\{IndexNow, IndexNowDefaults};

#[ORM\Entity]
#[IndexNowDefaults(when: 'isPublished', fields: ['slug', 'title', 'body', 'published'])]
#[IndexNow(resolver: 'post_url')]
#[IndexNow(via: 'category')]            // изменившийся пост обновляет и страницу категории
#[IndexNow(urls: ['/'])]                // и главную
class Post
{
    #[ORM\Column]
    private bool $published = false;

    public function isPublished(): bool { return $this->published; }
}
```

Полная модель — источники, типизированные параметры, `when` / `whenFields` / `fields` / `events` / `locales` /
`host`, наследование и таблица семантики — в [справочнике по атрибутам](../core/docs/attribute-reference.md).

### Маршруты

`#[IndexNow(route: ...)]` требует мост `RouteUrlResolverInterface` к роутеру фреймворка. У Doctrine без фреймворка
его нет, поэтому правило с `route:` падает при резолве с сообщением «no router bridge is configured» (оно пишется в
лог и никогда не выбрасывается в ваш flush). Используйте `url:`, `urls:` или `resolver:` — либо реализуйте
`RouteUrlResolverInterface` (два метода) для своего роутера и передайте его аргументом `router:` выше.

## Что делает слушатель

В `onFlush` каждая запланированная вставка, обновление, удаление и изменённая коллекция классифицируются
**по правилам** через `ObjectChangeHandler` из core:

- **Вставки** дают событие `created`. Их URL вычисляются в `postFlush`, когда идентификаторы уже присвоены.
- **Обновления** классифицируются по каждому правилу на основе `UnitOfWork::getEntityChangeSet()`. Правило, у
  которого `when` стало ложным, превращается в **удаление** и резолвится прямо в `onFlush`, пока старое состояние
  живо; правило, у которого `when` стало истинным, — в создание; иначе это обновление, отфильтрованное по `fields`
  правила. Одна сущность может в одном flush дать обновление одной страницы и удаление другой.
- **Изменённые to-many связи** не попадают в change set владельца, поэтому запланированное обновление или удаление
  коллекции переклассифицирует владельца, подставляя имя поля связи как изменённое. Изменение `post.tags`
  переотправляет страницы поста.
- **Удаления** резолвятся в `onFlush`, до исчезновения строки. Правило, которое не применяется (черновик, никогда
  не бывший публичным), не отправляет ничего.

В `postFlush` отложенные правила резолвятся, каждый URL пишется в лог на уровне `debug` вместе с породившим его
правилом (`indexnow: App\Entity\Post#post_amp (updated) -> https://example.com/amp/hello`), и батч передаётся дальше.

Ничего из этого не выбрасывает исключений в ваше приложение. Некорректный атрибут, нечитаемый accessor в `when` или
падающий резолвер пишутся в лог канала `indexnow` и не дают URL.

## Гарантия коммита

`postFlush` срабатывает до внешнего `COMMIT`, если `flush()` обёрнут в `wrapInTransaction()` или ручную транзакцию,
а события «после коммита» у Doctrine нет. Поэтому:

- если у соединения открыта транзакция, URL складываются в staging по **нативному** объекту соединения;
- DBAL driver middleware видит настоящие `commit()` и `rollBack()` — уровень вложенности 0, одинаково в DBAL 3 и 4 —
  и либо освобождает отложенные URL, либо отбрасывает их;
- `commit()`, который сам бросил исключение, тоже приводит к отбрасыванию, так что переиспользованное соединение
  никогда не отправит их позже;
- вне транзакции URL передаются немедленно.

Если драйвер не отдаёт нативный объект соединения, слушатель пишет предупреждение и отправляет внутри открытой
транзакции, вместо того чтобы потерять URL.

## Ограничения

- Массовые DQL- и QueryBuilder-операции `UPDATE` / `DELETE`, а также `Connection::executeStatement()`, минуют unit of
  work и не отслеживаются. Отправляйте такие URL через `$indexNow->submit()`.
- `route:` требует моста к роутеру (см. выше).
- Сущности, вставленные через `INSERT ... SELECT`, до `postFlush` не доходят.
- Атрибуты не читаются с интерфейсов и трейтов: PHP не наследует атрибуты класса через них, и маппинг Doctrine
  ведёт себя так же.

## Совместимость с другими слушателями

Регистрируйте слушатель **после** всего, что вычисляет значения, от которых зависят URL. Gedmo Sluggable пишет slug
в `onFlush`, поэтому слушатель IndexNow должен отработать позже; бандл Symfony использует приоритет `-100` именно
поэтому.

## Документация

| | |
|---|---|
| Справочник по атрибутам | [core/docs/attribute-reference.md](../core/docs/attribute-reference.md) |
| Конфигурация | [core/docs/configuration.md](../core/docs/configuration.md) |
| Эксплуатация и логи | [core/docs/operations.md](../core/docs/operations.md) |
| Тестирование | [core/docs/testing.md](../core/docs/testing.md) |
| Как написать свой адаптер | [core/docs/adapters.md](../core/docs/adapters.md) |
| Обоснование архитектуры | [docs/spec](../../../docs/spec) |

Changelog: [CHANGELOG.md](CHANGELOG.md). Версионирование: SemVer; до 1.0 минорные версии могут ломать совместимость.

MIT.
