# AGENTS.md

Guidance for AI agents working in `codr/eventually-doctrine`.

## Layout

- `src/Entity`: `StoredEvent`, `StoredSnapshot`. Table and column names are persisted contracts.
- `src/Repository`: `DoctrineEventStore`, `DoctrineSnapshotStore`, `AbstractDoctrineViewRepository`.
- `src/Serializer`: the payload serializer port.
- `src/Resources/mapping`: Doctrine XML mapping of the `View` mapped superclass.
- `tests`: PHPUnit on a real Postgres through a bare entity manager. No Symfony kernel.

## Rules

- Depends on `codr/eventually`, Doctrine, `psr/clock` and `symfony/uid` only. Deptrac forbids any other `Doctrine`, `Psr` or `Symfony` namespace.
- Stores never flush. Callers own the transaction.
- Changing a table or column needs a schema diff check in every consumer and an `UPGRADE.md` entry.
- Public API is everything not `@internal`. Follow `UPGRADE.md` before removing or changing it.
- No prose comments in PHP.

## Gate

Start Postgres with `docker compose up -d --wait`, then run `composer check` before every commit.
