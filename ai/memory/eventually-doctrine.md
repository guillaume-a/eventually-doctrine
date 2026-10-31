# codr/eventually-doctrine

Doctrine ORM adapter of `codr/eventually` (`Codr\Eventually\Doctrine`): event store, snapshot store and the view repository base class. It has no Symfony wiring: upcasters, the event type map and the snapshot frequency arrive by constructor.

## Schema

- `StoredEvent` is the append-only event table. Its primary key is a UUIDv7, and an identity `position` column gives the global read order.
- A unique `(aggregate_id, version)` index gives optimistic concurrency. It also covers lookups by aggregate, so there is no separate index on `aggregate_id`.
- `StoredSnapshot` holds the aggregate state that shortens replay, keyed by aggregate and version.
- Table and column names are persisted contracts. The consuming app owns its migrations: run a schema diff after every package upgrade.

## Event store

- Ports take a `class-string $aggregateClass`, never a type string. Only the Doctrine stores resolve the persisted type, through `AggregateDefinitionResolver::typeOf()`.
- The stores never flush. `append` and the snapshot `save` only stage rows, and the caller's transaction commits. A failing command leaves no event.
- A lost concurrency race surfaces at commit as a unique violation on `uniq_aggregate_version`.
- `append` returns an `AppendReceipt` with the stored `recordedAt`, cut to whole seconds, and the metadata stamped on the row.
- Metadata comes from `MetadataProviderInterface`: `actorId`, `correlationId` and `causationId`.
- `position` is assigned at insert, not at commit. A future catch-up reader must tolerate a late-committing lower position.
- Replay applies the upcaster chain. The payload is serialized through `EventPayloadSerializerInterface`, a port the consumer implements.
- The store implements `RecordedEventReaderInterface`: `readAll()` yields every event ordered by `position`, upcast, with `recordedAt` read back from `created_at`.

## View repositories

- Extend `AbstractDoctrineViewRepository`: `find`, `save` (a `persist`, nothing flushes), `paginate` ordered by `position` with a count query, and `clear` (`TRUNCATE ... RESTART IDENTITY` plus identity-map clear).
- Views are Doctrine entities mapped by XML. The shared columns come from the `View` mapped superclass mapping shipped in this package. Map each view subclass with its own XML file, no ORM attribute in the Application layer.
- A DQL query does not autoflush: code that saves outside a command bus must flush before reading.
- `position` is mapped but never written by the ORM, and stays null on a managed entity until it is reloaded.
- Views are derived: never edit them by hand, rebuild them from the events.

## Rebuilding

- A new view, or a projector fix, is a rebuild, not a data migration: truncate the views and replay `readAll()` through the projectors in one transaction, flushing and clearing the entity manager every few hundred events to bound memory.
- Entities that hold data not derived from events (credentials, for example) must make `reset()` a no-op and project as an upsert.
