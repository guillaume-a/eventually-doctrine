# Contributing

## Setup

```bash
docker compose up -d --wait
composer install
composer check
```

`composer check` runs `composer validate`, phpstan (level max), php-cs-fixer (`@Symfony`, `@Symfony:risky`, applies fixes), deptrac and phpunit. All must pass. Tests need Postgres: `compose.yaml` starts one on `127.0.0.1:5434`, or set `DATABASE_URL`.

## Running without local PHP

The base `php:8.5-cli-alpine` image lacks `pdo_pgsql`. Build a local image once, start Postgres, then run any gate. The parent directory is mounted because `codr/eventually` is a path repository at `../eventually`.

```bash
printf 'FROM php:8.5-cli-alpine\nRUN apk add --no-cache postgresql-dev $PHPIZE_DEPS && docker-php-ext-install pdo_pgsql && apk del $PHPIZE_DEPS\n' | docker build -t eventually-doctrine-php -
docker compose up -d --wait
docker run --rm --network host -u "$(id -u):$(id -g)" -v "$PWD/..":/work -w /work/eventually-doctrine eventually-doctrine-php vendor/bin/phpunit
```

Swap `vendor/bin/phpunit` for `vendor/bin/deptrac analyse` or `vendor/bin/phpstan analyse` to run the other gates. `composer install` runs on the host.

## Rules

- Keep the adapter free of Symfony wiring: deptrac allows only the namespaces of required packages (`Doctrine\DBAL`, `Doctrine\ORM`, `Psr\Clock`, `Symfony\Component\Uid`, `Symfony\Bridge\Doctrine`) and fails on any other `Doctrine`, `Psr` or `Symfony` import.
- No prose comments. Names, types and tests carry the intent. PHPDoc type tags that PHPStan needs are fine.
- English only for code, docs and commit messages.
- Commits are short lowercase `type: description`, imperative, no trailing period.
- Add a test with every behavior change. Fixtures live in `tests/Fixture`.
- A public API change follows `UPGRADE.md`: deprecate first, remove in the next major.
