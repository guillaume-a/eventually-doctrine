<?php

namespace Codr\Eventually\Doctrine\Tests\Repository;

use Codr\Eventually\Doctrine\Entity\StoredEvent;
use Codr\Eventually\Doctrine\Tests\DoctrineTestCase;
use Codr\Eventually\Doctrine\Tests\Fixture\Ledger;
use Codr\Eventually\Doctrine\Tests\Fixture\NameToLabelUpcaster;
use Codr\Eventually\Doctrine\Tests\Fixture\Note;
use Codr\Eventually\Doctrine\Tests\Fixture\NoteWasRevised;
use Codr\Eventually\Doctrine\Tests\Fixture\NoteWasWritten;
use Codr\Eventually\Domain\Aggregate\AggregateId;
use Codr\Eventually\Domain\Exception\EventStreamGap;
use Codr\Eventually\Domain\Exception\ForeignEventInStream;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\Uid\Uuid;

use function array_map;
use function array_unique;
use function array_values;
use function sort;

final class DoctrineEventStoreTest extends DoctrineTestCase
{
    public function testEventsRoundTripInVersionOrder(): void
    {
        $store = $this->eventStore();
        $id = $this->newId();

        $store->append(Note::class, $id, [new NoteWasWritten($id, 'first'), new NoteWasWritten($id, 'second')], 0);
        $this->entityManager->flush();

        $events = $store->streamFor(Note::class, $id);

        self::assertCount(2, $events);
        self::assertInstanceOf(NoteWasWritten::class, $events[0]);
        self::assertInstanceOf(NoteWasWritten::class, $events[1]);
        self::assertSame(['first', 'second'], [$events[0]->text, $events[1]->text]);
    }

    public function testAppendStampsMetadataAndWholeSecondTimestampWithoutFlushing(): void
    {
        $store = $this->eventStore();
        $id = $this->newId();

        $receipt = $store->append(Note::class, $id, [new NoteWasWritten($id, 'first')], 0);

        self::assertSame(0, $this->rowCount('event_store'));
        self::assertSame('2026-01-02 10:30:45', $receipt->recordedAt->format('Y-m-d H:i:s'));
        self::assertSame('000000', $receipt->recordedAt->format('u'));

        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->entityManager->getRepository(StoredEvent::class)->findOneBy(['aggregateId' => Uuid::fromString($id->toString())]);
        self::assertNotNull($stored);
        self::assertEquals(['correlationId' => 'correlation-1', 'causationId' => 'causation-1', 'actorId' => 'actor-1'], $stored->metadata());
    }

    public function testReadAllReplaysEveryEventByPositionWithItsRecordedMetadata(): void
    {
        $store = $this->eventStore();
        $first = $this->newId();
        $second = $this->newId();

        $store->append(Note::class, $second, [new NoteWasWritten($second, 'b1')], 0);
        $store->append(Note::class, $first, [new NoteWasWritten($first, 'a1')], 0);
        $store->append(Note::class, $second, [new NoteWasWritten($second, 'b2')], 1);
        $this->entityManager->flush();

        $recorded = [...$store->readAll()];

        self::assertCount(3, $recorded);
        self::assertSame(['b1', 'a1', 'b2'], array_map(static fn ($r): string => $r->event instanceof NoteWasWritten ? $r->event->text : '', $recorded));
        self::assertSame([1, 1, 2], array_map(static fn ($r): int => $r->version, $recorded));
        self::assertSame('actor-1', $recorded[0]->metadata->actorId);
        self::assertEquals(new DateTimeImmutable('2026-01-02 10:30:45 UTC'), $recorded[0]->recordedAt);
    }

    public function testAForeignEventIsRejectedAndNothingIsWritten(): void
    {
        $store = $this->eventStore();
        $id = $this->newId();

        try {
            $store->append(Note::class, $id, [new NoteWasWritten($id, 'mine'), new NoteWasWritten($this->newId(), 'intruder')], 0);
            self::fail('A foreign event must be rejected.');
        } catch (ForeignEventInStream) {
        }

        $this->entityManager->flush();

        self::assertSame(0, $this->rowCount('event_store'));
    }

    public function testTwoAppendsOnTheSameVersionViolateTheUniqueIndexAtFlush(): void
    {
        $store = $this->eventStore();
        $id = $this->newId();

        $store->append(Note::class, $id, [new NoteWasWritten($id, 'one')], 0);
        $store->append(Note::class, $id, [new NoteWasWritten($id, 'two')], 0);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->expectExceptionMessage('uniq_aggregate_version');

        $this->entityManager->flush();
    }

    public function testAGapBetweenTheSnapshotAndTheRemainingEventsThrows(): void
    {
        $id = $this->newId();

        foreach ([1, 2, 4] as $version) {
            $this->storeRow($id, 'note.written', 1, ['aggregateId' => $id->toString(), 'text' => 'N'.$version], $version);
        }

        $this->expectException(EventStreamGap::class);
        $this->expectExceptionMessage('expected version 3, found 4');

        $this->eventStore()->streamFor(Note::class, $id);
    }

    public function testRemainingEventsMustStartRightAfterTheSnapshotVersion(): void
    {
        $id = $this->newId();

        foreach ([1, 2, 3, 22] as $version) {
            $this->storeRow($id, 'note.written', 1, ['aggregateId' => $id->toString(), 'text' => 'N'.$version], $version);
        }

        $this->expectException(EventStreamGap::class);

        $this->eventStore()->streamFor(Note::class, $id, 20);
    }

    public function testEventsGetAnIncreasingPositionAndAggregatesAreListedByFirstPosition(): void
    {
        $store = $this->eventStore();
        $first = $this->newId();
        $second = $this->newId();

        $store->append(Ledger::class, $second, [new NoteWasWritten($second, 'Second')], 0);
        $store->append(Ledger::class, $first, [new NoteWasWritten($first, 'First')], 0);
        $store->append(Ledger::class, $second, [new NoteWasWritten($second, 'Second again')], 1);
        $other = $this->newId();
        $store->append(Note::class, $other, [new NoteWasWritten($other, 'Other type')], 0);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $rows = $this->entityManager->getRepository(StoredEvent::class)->findBy(['aggregateType' => 'ledger'], ['position' => 'ASC']);
        $positions = array_map(static fn (StoredEvent $event): ?int => $event->position(), $rows);

        self::assertCount(3, $rows);
        self::assertNotContains(null, $positions);
        self::assertSame(['Second', 'First', 'Second again'], array_map(static fn (StoredEvent $event): mixed => $event->payload()['text'], $rows));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, array_values(array_unique($positions)));
        self::assertSame(
            [$second->toString(), $first->toString()],
            array_map(static fn (AggregateId $id): string => $id->toString(), $store->aggregateIdsFor(Ledger::class)),
        );
    }

    public function testAnUpcasterLiftsAV1PayloadToV2OnReplay(): void
    {
        $id = $this->newId();
        $this->storeRow($id, 'note.revised', 1, ['aggregateId' => $id->toString(), 'name' => 'Acme'], 1);

        $events = $this->eventStore([new NameToLabelUpcaster()])->streamFor(Note::class, $id);

        self::assertCount(1, $events);
        self::assertInstanceOf(NoteWasRevised::class, $events[0]);
        self::assertSame('Acme', $events[0]->label);
    }

    public function testAMissingUpcasterFailsExplicitly(): void
    {
        $id = $this->newId();
        $this->storeRow($id, 'note.revised', 1, ['aggregateId' => $id->toString(), 'name' => 'Acme'], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No upcaster for event type "note.revised" from schema revision 1');

        $this->eventStore()->streamFor(Note::class, $id);
    }

    public function testAnUnregisteredEventTypeFailsExplicitly(): void
    {
        $id = $this->newId();
        $this->storeRow($id, 'note.unknown', 1, [], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No domain event class registered for event type "note.unknown"');

        $this->eventStore()->streamFor(Note::class, $id);
    }

    public function testAnEventStoredAtANewerRevisionThanTheCurrentFailsExplicitly(): void
    {
        $id = $this->newId();
        $this->storeRow($id, 'note.written', 2, ['aggregateId' => $id->toString(), 'text' => 'x'], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('newer than the current 1');

        $this->eventStore()->streamFor(Note::class, $id);
    }

    public function testTheStoreRejectsAClassWithoutTheAggregateAttribute(): void
    {
        $store = $this->eventStore();
        $id = $this->newId();

        foreach ([
            static fn () => $store->append(stdClass::class, $id, [], 0),
            static fn () => $store->streamFor(stdClass::class, $id),
            static fn () => $store->aggregateIdsFor(stdClass::class),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (LogicException $e) {
                self::assertStringContainsString('must carry the #[', $e->getMessage());
            }
        }
    }

    private function newId(): AggregateId
    {
        return AggregateId::fromString(Uuid::v7()->toRfc4122());
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeRow(AggregateId $id, string $eventType, int $schemaRevision, array $payload, int $version): void
    {
        $this->entityManager->persist(new StoredEvent(
            Uuid::v7(),
            Uuid::fromString($id->toString()),
            'note',
            $eventType,
            $payload,
            [],
            $version,
            $schemaRevision,
            new DateTimeImmutable(),
        ));
        $this->entityManager->flush();
    }
}
