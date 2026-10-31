# codr/eventually-doctrine

Doctrine ORM adapter for [`codr/eventually`](../eventually): an append-only event store, a snapshot store and a base class for view repositories, on Postgres.

## What is inside

- `Codr\Eventually\Doctrine\Repository\DoctrineEventStore`: implements `EventStoreInterface` and `RecordedEventReaderInterface`. Events are staged on the entity manager and never flushed here, so the caller owns the transaction. Replay applies the upcaster chain.
- `Codr\Eventually\Doctrine\Repository\DoctrineSnapshotStore`: implements `SnapshotStoreInterface`, one row per aggregate.
- `Codr\Eventually\Doctrine\Repository\AbstractDoctrineViewRepository`: `paginate`, `save`, `clear` for views extending `Codr\Eventually\Application\View\View`.
- `Codr\Eventually\Doctrine\Entity\StoredEvent` and `StoredSnapshot`: tables `event_store` and `aggregate_snapshot`.
- `Codr\Eventually\Doctrine\Serializer\EventPayloadSerializerInterface`: the port you implement to turn an event into a payload array and back.

No Symfony wiring is required: every collaborator arrives by constructor. `codr/eventually-bundle` does the wiring for Symfony applications.

## Install

```bash
composer require codr/eventually-doctrine
```

Register the mappings in your entity manager:

| Mapping | Type | Path | Prefix |
| ------- | ---- | ---- | ------ |
| Entities | attribute | `vendor/codr/eventually-doctrine/src/Entity` | `Codr\Eventually\Doctrine\Entity` |
| `View` mapped superclass | xml | `vendor/codr/eventually-doctrine/src/Resources/mapping` | `Codr\Eventually` |

The mappings rely on the underscore naming strategy (`Doctrine\ORM\Mapping\UnderscoreNamingStrategy`, `doctrine.orm.naming_strategy.underscore_number_aware` in DoctrineBundle), which turns `aggregateId` into `aggregate_id`.

Entities use the `uuid` DBAL type: register `Symfony\Bridge\Doctrine\Types\UuidType` under that name.

## Build the event store

```php
$store = new DoctrineEventStore(
    $entityManager,
    $eventTypes,
    $upcasters,
    $payloadSerializer,
    $clock,
    $metadataProvider,
);
```

- `$eventTypes`: `array<string, array{class: class-string, revision: int}>`, keyed by the `#[DomainEvent]` type.
- `$upcasters`: `iterable<UpcasterInterface>`.
- `$clock`: any `Psr\Clock\ClockInterface`.

## Quality

```bash
docker compose up -d --wait   # Postgres on 127.0.0.1:5434
composer check                # validate, phpstan, php-cs-fixer, deptrac, phpunit
composer bc-check             # backward compatibility against the last tag
```

Point the tests at another database with `DATABASE_URL`.

## AI docs

`ai/memory/` holds the rules of this package for AI coding agents. [`codr/eventually-bundle`](../eventually-bundle) copies it into your project with `eventually:ai:install`.

## License

MIT, see `LICENSE`.
