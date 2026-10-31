<?php

namespace Codr\Eventually\Doctrine\Repository;

use Codr\Eventually\Application\Aggregate\AggregateDefinitionResolver;
use Codr\Eventually\Doctrine\Entity\StoredSnapshot;
use Codr\Eventually\Domain\Aggregate\AggregateId;
use Codr\Eventually\Domain\Snapshot;
use Codr\Eventually\Domain\SnapshotStoreInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

final readonly class DoctrineSnapshotStore implements SnapshotStoreInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function load(string $aggregateClass, AggregateId $id): ?Snapshot
    {
        $stored = $this->findStored($aggregateClass, $id);

        return null === $stored ? null : new Snapshot($stored->version(), $stored->state(), $stored->schemaRevision());
    }

    public function save(string $aggregateClass, AggregateId $id, int $version, array $state, int $schemaRevision): void
    {
        $stored = $this->findStored($aggregateClass, $id);

        if (null === $stored) {
            $this->entityManager->persist(new StoredSnapshot(
                Uuid::v7(),
                Uuid::fromString($id->toString()),
                AggregateDefinitionResolver::typeOf($aggregateClass),
                $version,
                $state,
                $schemaRevision,
                $this->clock->now(),
            ));

            return;
        }

        $stored->update($version, $state, $schemaRevision, $this->clock->now());
    }

    /**
     * @param class-string $aggregateClass
     */
    private function findStored(string $aggregateClass, AggregateId $id): ?StoredSnapshot
    {
        return $this->entityManager->getRepository(StoredSnapshot::class)->findOneBy([
            'aggregateId' => Uuid::fromString($id->toString()),
            'aggregateType' => AggregateDefinitionResolver::typeOf($aggregateClass),
        ]);
    }
}
