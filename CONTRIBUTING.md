# Contributing

This repository is a read-only split of [`indexnowkit/php`](https://github.com/indexnowkit/php) (`packages/doctrine`).
Please open issues and pull requests there; releases are tagged in the monorepo as `doctrine@x.y.z` and mirrored here.

Quick rules (details in the monorepo's CONTRIBUTING.md):

- Every change comes with a test in `tests/OrmConformanceTest.php` or next to it. The ORM scenarios are specified in
  the monorepo's `docs/spec/03-conformance.md` (A01–A21); a new behaviour gets a scenario first.
- Nothing may throw out of `onFlush` / `postFlush` or the DBAL middleware into the application's `flush()`.
- DBAL 3 and 4 both stay green (`composer update --with doctrine/dbal:^3.8 --with doctrine/orm:^2.19`).
- phpstan level 9 and php-cs-fixer must pass.
