<?php

namespace Codr\Eventually\Doctrine\Repository;

use Codr\Eventually\Application\Aggregate\AggregateDefinitionResolver;
use Codr\Eventually\Application\Event\RecordedEvent;
use Codr\Eventually\Application\Event\RecordedEventReaderInterface;
use Codr\Eventually\Application\Metadata\MetadataProviderInterface;
use Codr\Eventually\Doctrine\Entity\StoredEvent;
use Codr\Eventually\Doctrine\Serializer\EventPayloadSerializerInterface;
use Codr\Eventually\Domain\Aggregate\AggregateId;
use Codr\Eventually\Domain\AppendReceipt;
use Codr\Eventually\Domain\EventInterface;
use Codr\Eventually\Domain\EventMetadata;
use Codr\Eventually\Domain\EventStoreInterface;
use Codr\Eventually\Domain\Exception\EventStreamGap;
use Codr\Eventually\Domain\Exception\ForeignEventInStream;
use Codr\Eventually\Domain\Upcaster\UpcasterInterface;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use function count;
use function json_decode;
use function sprintf;

use const JSON_THROW_ON_ERROR;

final class DoctrineEventStore implements EventStoreInterface, RecordedEventReaderInterface
{
    private const int READ_CHUNK_SIZE = 500;

    /** @var array<string, UpcasterInterface>|null */
    private ?array $upcasterIndex = null;

    /** @var array<class-string<EventInterface>, array{type: string, revision: int}>|null */
    private ?array $classIndex = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        /** @var array<string, array{class: class-string<EventInterface>, revision: int}> */
        private readonly array $eventTypes,
        /** @var iterable<UpcasterInterface> */
        private readonly iterable $upcasters,
        private readonly EventPayloadSerializerInterface $serializer,
        private readonly ClockInterface $clock,
        private readonly MetadataProviderInterface $metadataProvider,
    ) {
    }

    public function append(string $aggregateClass, AggregateId $aggregateId, array $events, int $expectedVersion): AppendReceipt
    {
        $aggregateType = AggregateDefinitionResolver::typeOf($aggregateClass);

        foreach ($events as $event) {
            if (!$event->aggregateId()->equals($aggregateId)) {
                throw new ForeignEventInStream(sprintf('Event "%s" belongs to aggregate "%s", not to the "%s" stream "%s".', $event::class, $event->aggregateId()->toString(), $aggregateType, $aggregateId->toString()));
            }
        }

        $version = $expectedVersion;
        $doctrineAggregateId = Uuid::fromString($aggregateId->toString());
        $eventMetadata = $this->metadataProvider->current();
        $metadata = $eventMetadata->toArray();
        $recordedAt = $this->now();

        foreach ($events as $event) {
            ++$version;

            $payload = $this->serializer->normalize($event);

            $identity = $this->classIndex()[$event::class]
                ?? throw new RuntimeException(sprintf('No event type registered for class "%s".', $event::class));

            $storedEvent = new StoredEvent(
                Uuid::v7(),
                $doctrineAggregateId,
                $aggregateType,
                $identity['type'],
                $payload,
                $metadata,
                $version,
                $identity['revision'],
                $recordedAt,
            );

            $this->entityManager->persist($storedEvent);
        }

        return new AppendReceipt($eventMetadata, $recordedAt);
    }

    public function streamFor(string $aggregateClass, AggregateId $aggregateId, int $fromVersion = 0): array
    {
        $aggregateType = AggregateDefinitionResolver::typeOf($aggregateClass);

        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from(StoredEvent::class, 'e')
            ->where('e.aggregateId = :aggregateId')
            ->andWhere('e.aggregateType = :aggregateType')
            ->orderBy('e.version', 'ASC')
            ->setParameter('aggregateId', Uuid::fromString($aggregateId->toString()))
            ->setParameter('aggregateType', $aggregateType);

        if ($fromVersion > 0) {
            $queryBuilder->andWhere('e.version > :fromVersion')->setParameter('fromVersion', $fromVersion);
        }

        /** @var list<StoredEvent> $storedEvents */
        $storedEvents = $queryBuilder->getQuery()->getResult();

        $expectedVersion = $fromVersion;
        foreach ($storedEvents as $storedEvent) {
            ++$expectedVersion;

            if ($storedEvent->version() !== $expectedVersion) {
                throw new EventStreamGap(sprintf('Event stream "%s" (%s) has a gap: expected version %d, found %d.', $aggregateId->toString(), $aggregateType, $expectedVersion, $storedEvent->version()));
            }
        }

        return array_map(
            function (StoredEvent $storedEvent): EventInterface {
                ['class' => $class, 'revision' => $current] = $this->definitionFor($storedEvent->eventType());

                return $this->serializer->denormalize($this->upcast($storedEvent->eventType(), $storedEvent->schemaRevision(), $storedEvent->payload(), $current), $class);
            },
            $storedEvents,
        );
    }

    public function aggregateIdsFor(string $aggregateClass): array
    {
        $aggregateType = AggregateDefinitionResolver::typeOf($aggregateClass);

        /** @var list<string> $ids */
        $ids = $this->entityManager->createQueryBuilder()
            ->select('e.aggregateId')
            ->from(StoredEvent::class, 'e')
            ->where('e.aggregateType = :aggregateType')
            ->groupBy('e.aggregateId')
            ->orderBy('MIN(e.position)', 'ASC')
            ->setParameter('aggregateType', $aggregateType)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(
            static fn (string $id): AggregateId => AggregateId::fromString(Uuid::fromString($id)->toRfc4122()),
            $ids,
        );
    }

    public function readAll(): iterable
    {
        $connection = $this->entityManager->getConnection();
        $lastPosition = 0;

        do {
            /** @var list<array{position: int|string, event_type: string, payload: string, metadata: string, version: int|string, schema_revision: int|string, created_at: string}> $rows */
            $rows = $connection->fetchAllAssociative(
                'SELECT position, event_type, payload, metadata, version, schema_revision, created_at FROM event_store WHERE position > :position ORDER BY position ASC LIMIT :limit',
                ['position' => $lastPosition, 'limit' => self::READ_CHUNK_SIZE],
            );

            foreach ($rows as $row) {
                $lastPosition = (int) $row['position'];
                $eventType = $row['event_type'];
                ['class' => $class, 'revision' => $current] = $this->definitionFor($eventType);

                /** @var array<string, mixed> $payload */
                $payload = json_decode($row['payload'], true, flags: JSON_THROW_ON_ERROR);
                /** @var array<string, string> $metadata */
                $metadata = json_decode($row['metadata'], true, flags: JSON_THROW_ON_ERROR);

                $event = $this->serializer->denormalize($this->upcast($eventType, (int) $row['schema_revision'], $payload, $current), $class);

                yield new RecordedEvent(
                    $event,
                    (int) $row['version'],
                    new EventMetadata($metadata['correlationId'] ?? null, $metadata['causationId'] ?? null, $metadata['actorId'] ?? null),
                    new DateTimeImmutable($row['created_at']),
                );
            }
        } while (self::READ_CHUNK_SIZE === count($rows));
    }

    private function now(): DateTimeImmutable
    {
        $now = $this->clock->now();

        return $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function upcast(string $eventType, int $revision, array $payload, int $current): array
    {
        if ($revision > $current) {
            throw new RuntimeException(sprintf('Event type "%s" is stored at schema revision %d, newer than the current %d.', $eventType, $revision, $current));
        }

        for (; $revision < $current; ++$revision) {
            $upcaster = $this->upcasterIndex()[$eventType.':'.$revision]
                ?? throw new RuntimeException(sprintf('No upcaster for event type "%s" from schema revision %d.', $eventType, $revision));
            $payload = $upcaster->upcast($payload);
        }

        return $payload;
    }

    /**
     * @return array<string, UpcasterInterface>
     */
    private function upcasterIndex(): array
    {
        if (null !== $this->upcasterIndex) {
            return $this->upcasterIndex;
        }

        $index = [];
        foreach ($this->upcasters as $upcaster) {
            $index[$upcaster->eventType().':'.$upcaster->fromRevision()] = $upcaster;
        }

        return $this->upcasterIndex = $index;
    }

    /**
     * @return array{class: class-string<EventInterface>, revision: int}
     */
    private function definitionFor(string $eventType): array
    {
        return $this->eventTypes[$eventType]
            ?? throw new RuntimeException(sprintf('No domain event class registered for event type "%s".', $eventType));
    }

    /**
     * @return array<class-string<EventInterface>, array{type: string, revision: int}>
     */
    private function classIndex(): array
    {
        if (null !== $this->classIndex) {
            return $this->classIndex;
        }

        $index = [];
        foreach ($this->eventTypes as $type => $definition) {
            $index[$definition['class']] = ['type' => $type, 'revision' => $definition['revision']];
        }

        return $this->classIndex = $index;
    }
}
