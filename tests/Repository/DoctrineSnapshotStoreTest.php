<?php

namespace Codr\Eventually\Doctrine\Tests\Repository;

use Codr\Eventually\Doctrine\Repository\DoctrineSnapshotStore;
use Codr\Eventually\Doctrine\Tests\DoctrineTestCase;
use Codr\Eventually\Doctrine\Tests\Fixture\FixedClock;
use Codr\Eventually\Doctrine\Tests\Fixture\Ledger;
use Codr\Eventually\Doctrine\Tests\Fixture\Note;
use Codr\Eventually\Domain\Aggregate\AggregateId;
use LogicException;
use stdClass;
use Symfony\Component\Uid\Uuid;

final class DoctrineSnapshotStoreTest extends DoctrineTestCase
{
    public function testLoadReturnsNullWithoutSnapshot(): void
    {
        self::assertNull($this->snapshots()->load(Note::class, $this->newId()));
    }

    public function testSaveStagesTheRowWithoutFlushingThenUpdatesInPlace(): void
    {
        $snapshots = $this->snapshots();
        $id = $this->newId();

        $snapshots->save(Note::class, $id, 20, ['count' => 20], 1);
        self::assertSame(0, $this->rowCount('aggregate_snapshot'));

        $this->entityManager->flush();
        $loaded = $snapshots->load(Note::class, $id);
        self::assertSame(20, $loaded?->version);
        self::assertSame(['count' => 20], $loaded->state);
        self::assertSame(1, $loaded->schemaRevision);

        $snapshots->save(Note::class, $id, 40, ['count' => 40], 2);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame(1, $this->rowCount('aggregate_snapshot'));
        $reloaded = $snapshots->load(Note::class, $id);
        self::assertSame(40, $reloaded?->version);
        self::assertSame(2, $reloaded->schemaRevision);
    }

    public function testSnapshotsAreScopedByAggregateType(): void
    {
        $snapshots = $this->snapshots();
        $id = $this->newId();

        $snapshots->save(Note::class, $id, 20, ['k' => 'note'], 1);
        $this->entityManager->flush();

        self::assertNull($snapshots->load(Ledger::class, $id));
    }

    public function testTheStoreRejectsAClassWithoutTheAggregateAttribute(): void
    {
        $snapshots = $this->snapshots();
        $id = $this->newId();

        foreach ([
            static fn () => $snapshots->load(stdClass::class, $id),
            static fn () => $snapshots->save(stdClass::class, $id, 1, [], 1),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (LogicException $e) {
                self::assertStringContainsString('must carry the #[', $e->getMessage());
            }
        }
    }

    private function snapshots(): DoctrineSnapshotStore
    {
        return new DoctrineSnapshotStore($this->entityManager, new FixedClock());
    }

    private function newId(): AggregateId
    {
        return AggregateId::fromString(Uuid::v7()->toRfc4122());
    }
}
