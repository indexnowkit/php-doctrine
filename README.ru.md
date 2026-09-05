# `indexnowkit/doctrine` — IndexNow для Doctrine ORM с гарантией коммита

Слушает `onFlush` / `postFlush`, вычисляет URL сущностей, объявивших правила `#[IndexNow]`, и передаёт их дальше
**только после реального коммита внешней транзакции** — через DBAL driver middleware. Откатанный flush не
отправляет ничего. URL удаляемых объектов вычисляются до того, как строка исчезнет.

Doctrine ORM 2.19+ и 3.x, DBAL 3.x и 4.x, PHP 8.2+.

[English version](README.md) · Issues и pull requests: [github.com/indexnowkit/php](https://github.com/indexnowkit/php/issues) (репозитории `php-*` — read-only сплиты)

[![Packagist](https://img.shields.io/packagist/v/indexnowkit/doctrine)](https://packagist.org/packages/indexnowkit/doctrine)
[![Downloads](https://img.shields.io/packagist/dt/indexnowkit/doctrine)](https://packagist.org/packages/indexnowkit/doctrine)
[![CI](https://github.com/indexnowkit/php/actions/workflows/ci.yml/badge.svg)](https://github.com/indexnowkit/php/actions)
[![Conformance](https://img.shields.io/badge/conformance-orm%2014%2F14-brightgreen)](https://github.com/indexnowkit/spec)
![PHPStan](https://img.shields.io/badge/phpstan-level%209-4c1)
![PHP](https://img.shields.io/badge/php-%5E8.2-777bb4)
[![License](https://img.shields.io/packagist/l/indexnowkit/doctrine)](LICENSE)

**Пользователям Symfony: берите [`indexnowkit/symfony-bundle`](https://github.com/indexnowkit/php/tree/main/packages/symfony-bundle)** — он собирает всё это сам,
добавляет мост к роутеру, команды и панель профайлера. Этот пакет — для Doctrine без Symfony.

## Почему это, а не X

Большинство пакетов IndexNow — тонкий HTTP-клиент: URL собираете вы, вызываете вы, ответ читаете вы. Это семейство делает
то, что на практике ломается:

- **Объявлено на модели** (`#[IndexNow]`) и отправляется из хуков ORM — нет кода в контроллере, который можно забыть.
- **После commit**, не на flush: откатившаяся транзакция ничего не объявляет.
- **Дебаунс** (10 минут на URL, через ваш кэш), **батчи** до 10 000 URL, ключ на host из env.
- **Ответы обработаны**: 202 (ключ проверяется), 422, 429 с `Retry-After` и повтором через вашу очередь, эскалация 403.
- **`check` и `explain`** в Symfony-бандле говорят, что не так до первой отправки и почему URL ушёл или не ушёл.
- **Одно ядро** под адаптерами Symfony, Laravel, Yii2 и Doctrine с общим conformance-набором: поведение одинаковое везде и описано один раз.


## Установка

```bash
composer require indexnowkit/doctrine
```

## Сборка без фреймворка

```php
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\{EntityManager, ORMSetup};
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

$ormConfiguration = ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/src/Entity'], isDevMode: false);
$wiring->registerMiddleware($ormConfiguration);      // ДО DriverManager::getConnection()

$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => __DIR__ . '/var/app.db'], $ormConfiguration);
$entityManager = new EntityManager($connection, $ormConfiguration);
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
`host`, наследование и таблица семантики — в [справочнике по атрибутам](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md).

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

## Переименованные страницы

Когда меняется поле, которое читает параметр маршрута — slug, категория в пути, — старый URL начинает отвечать 404.
При обновлении слушатель резолвит правило по **предыдущим** значениям change set и объявляет эти URL удалёнными
рядом с новыми, объявленными обновлёнными, в том же flush (`ObjectChangeHandler::renamed()`, сценарий A21). Только
route-правила; старая страница должна была быть публичной (`when` истинно до изменения); поле, которое нельзя
вернуть на место (`readonly`, неинициализированное), пропускает старый URL со строкой `debug`. Ничего из этого не
бросает исключений в `flush()`.

## Гарантия коммита

`postFlush` срабатывает до внешнего `COMMIT`, если `flush()` обёрнут в `wrapInTransaction()` или ручную транзакцию,
а события «после коммита» у Doctrine нет. Поэтому:

- если у соединения открыта транзакция, URL складываются в staging по **нативному** объекту соединения;
- DBAL driver middleware видит настоящие `commit()` и `rollBack()` — уровень вложенности 0, одинаково в DBAL 3 и 4 —
  и либо освобождает отложенные URL, либо отбрасывает их;
- `commit()`, который сам бросил исключение, тоже приводит к отбрасыванию, так что переиспользованное соединение
  никогда не отправит их позже;
- вложенная транзакция, откаченная к своему savepoint (`ROLLBACK TO SAVEPOINT` — то, что DBAL делает при внутреннем
  `rollBack()`), отбрасывает URL, отложенные внутри неё; внешний `COMMIT` доставляет остальные;
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

## Совместимость

Публичный API пакета: классы, названные в changelog и README, имена параметров их конструкторов (необязательные
аргументы передавайте по имени) и классы DBAL-middleware. Действуют правила core, включая интерфейсы «may grow»:
[bc.md](https://github.com/indexnowkit/php-core/blob/main/docs/bc.md); что стабильно в самом пакете: [docs/bc.md](docs/bc.md). До 1.0 минорная версия может ломать
совместимость; каждый такой случай перечислен в разделе «Changed» файла [CHANGELOG.md](CHANGELOG.md) вместе с
миграцией.

## Документация

| | |
|---|---|
| Справочник по атрибутам | [core/docs/attribute-reference.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/attribute-reference.md) |
| Конфигурация | [core/docs/configuration.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/configuration.md) |
| Эксплуатация и логи | [core/docs/operations.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/operations.md) |
| Тестирование | [core/docs/testing.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/testing.md) |
| Как написать свой адаптер | [core/docs/adapters.md](https://github.com/indexnowkit/php/blob/main/packages/core/docs/adapters.md) |
| Обоснование архитектуры | [docs/spec](https://github.com/indexnowkit/php/tree/main/docs/spec) |

Changelog: [CHANGELOG.md](CHANGELOG.md). Версионирование: SemVer; до 1.0 минорные версии могут ломать совместимость.

MIT. IndexNow — товарный знак его владельца; проект независимый и не связан с Microsoft, Яндексом или indexnow.org.
