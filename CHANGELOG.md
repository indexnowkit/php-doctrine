# Changelog

Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: SemVer; until 1.0 minor versions may
contain breaking changes, listed under "Changed".

## [Unreleased]

### Documentation

- README: "Notes for AI assistants" (package, minimal complete snippet, verification, pitfalls across the adapters);
  `ReadmeAiNotesTest` keeps it consistent with the commands and configuration keys.

## [0.4.0] — 2026-09-05

### Changed

- Requires `indexnowkit/core ^0.6` (wave 0a of docs/spec/17). No change in this package's own API; upgrade
  together with the core.

### Documentation

- [docs/bc.md](docs/bc.md): what this package keeps stable (`IndexNowDoctrine`, the listener and middleware, the
  commit-safety behaviour, the DBAL/ORM majors) and what is not a contract. README: "Why this over X", the issues link.

## [0.3.1] — 2026-09-05

### Changed

- Requires `indexnowkit/core ^0.5` (the second adapter kit release, additive). No change in this package's own API;
  upgrade together with the core.

## [0.3.0] — 2026-09-05

### Changed

- Requires `indexnowkit/core ^0.4` (the adapter kit release: the sitemap reader is `indexnowkit/sitemap`,
  `Result::urlsOf()` is gone). No change in this package's own API; upgrade together with the core.
- Dev tooling: phpstan runs on the DBAL 4 / ORM 3 install only (DBAL 3 / ORM 2 declare their signatures in
  docblocks that contradict it); the `lowest` and `dbal3` flavours still run the tests.

## [0.2.1] — 2026-09-04

### Changed

- Requires `indexnowkit/core ^0.2.2 || ^0.3`, so the package installs next to `indexnowkit/symfony-bundle 0.3`. The test suite runs the shared ORM conformance kit
  (`IndexNowKit\Testing\Conformance\OrmConformanceTestCase`, A01–A21 including the renamed-page scenario A21) through
  a Doctrine driver; Doctrine-specific behaviour stays in `tests/ListenerTest.php`. No runtime change.

## [0.2.0] — 2026-09-04

### Added

- Renamed pages (A21). When a route parameter of a rule reads a changed field (the slug), the listener resolves the
  rule against the previous values of the change set and submits the old URLs as deleted next to the new ones
  (`ObjectChangeHandler::renamed()`). Route rules only; a `readonly` field the URL depends on is skipped with a
  debug line.
- Per-rule classification. `IndexNowListener` now runs every changed entity through
  `IndexNowKit\Url\ObjectChangeHandler`, so a class with several `#[IndexNow]` rules gets one decision per rule:
  the article page can be an update while the AMP page of the same entity is a deletion, both in one flush.
- Changed to-many associations are detected. A `PersistentCollection` scheduled for update or deletion is not part
  of its owner's change set, so `post.tags` used to change without the post's pages being resubmitted. The owner is
  now re-classified with the association's field name as the changed field.
- Savepoints. The DBAL middleware mirrors `SAVEPOINT` / `RELEASE SAVEPOINT` / `ROLLBACK TO SAVEPOINT` (and the SQL
  Server spellings) into the staging, so an inner transaction rolled back to its savepoint drops the URLs staged
  inside it while the outer `COMMIT` still delivers the rest (conformance A05, `SavepointStatement`).
- Every resolved URL is logged at `debug` with its provenance: `indexnow: {source} ({event}) -> {url}`, where the
  source is `App\Entity\Post#post_amp`.

### Changed

- **Breaking:** requires `indexnowkit/core ^0.2` and the renamed facade `IndexNowKit\IndexNowKit`.
- **Breaking:** `TransactionStaging` moved into the core as `IndexNowKit\Transaction\TransactionStaging`. It is
  framework-agnostic and every adapter that promises commit safety now builds on the same class.
- **Breaking:** `IndexNowListener` subscribes to `onFlush` and `postFlush` only (`IndexNowListener::EVENTS`).
  Attribute metadata is compiled and cached by the core's `AttributeReader`, so `loadClassMetadata` is no longer
  needed.
- **Breaking:** deleting an entity whose rule does not apply submits nothing. Purging drafts used to announce URLs
  that were never public.
- Deletions are resolved in `onFlush`, while the row and its old state still exist; so is a rule whose `when` turned
  false during an update. Inserts and ordinary updates are resolved in `postFlush`, once identifiers are assigned.
- Change classification is delegated to the core (`Attribute\ChangeClassifier`), including the fix for a `when`
  given as a getter name: `when: 'isPublished'` now detects the `published` field in the change set, so
  publish/unpublish transitions are classified correctly.

### Fixed

- A typo in an attribute, an unreadable `when` accessor or a failing resolver is logged and yields no URLs instead
  of breaking the flush; the same holds for the old URL of a renamed page (`renamed()` never throws into `flush()`).
- Staged URLs are discarded when `commit()` itself throws, so a reused connection never delivers them later.
- A rolled-back transaction logs the discard at `debug` instead of dropping the URLs silently.
- A custom `UrlResolverInterface` is called once per entity and event, not once per `#[IndexNow]` rule.

### Notes

- Bulk DQL and QueryBuilder `UPDATE` / `DELETE` and `Connection::executeStatement()` bypass the unit of work and are
  not detected (conformance A13). Submit those URLs yourself.
- `route:` needs a router bridge. Standalone Doctrine has none, so use `url:`, `urls:`, `resolver:` or supply your
  own `RouteUrlResolverInterface`.

## [0.1.0] — 2026-09-03

- `onFlush` / `postFlush` listener resolving URLs (deletions before removal, publish and unpublish transitions).
- DBAL driver middleware (DBAL 3 and 4) delivering URLs only after the outermost COMMIT.

[0.2.1]: https://github.com/indexnowkit/php-doctrine/compare/0.2.0...0.2.1
[0.2.0]: https://github.com/indexnowkit/php-doctrine/compare/0.1.0...0.2.0
[0.1.0]: https://github.com/indexnowkit/php-doctrine/releases/tag/0.1.0
