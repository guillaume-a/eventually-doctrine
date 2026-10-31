<?php

namespace Codr\Eventually\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'aggregate_snapshot')]
#[ORM\UniqueConstraint(name: 'uniq_aggregate_snapshot', columns: ['aggregate_id', 'aggregate_type'])]
final class StoredSnapshot
{
    /**
     * @param array<string, mixed> $state
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $aggregateId,
        #[ORM\Column(length: 255)]
        private string $aggregateType,
        #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $state,
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
        private int $schemaRevision,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
        private DateTimeImmutable $createdAt,
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function aggregateId(): Uuid
    {
        return $this->aggregateId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array<string, mixed>
     */
    public function state(): array
    {
        return $this->state;
    }

    public function schemaRevision(): int
    {
        return $this->schemaRevision;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * @param array<string, mixed> $state
     */
    public function update(int $version, array $state, int $schemaRevision, DateTimeImmutable $createdAt): void
    {
        $this->version = $version;
        $this->state = $state;
        $this->schemaRevision = $schemaRevision;
        $this->createdAt = $createdAt;
    }
}
