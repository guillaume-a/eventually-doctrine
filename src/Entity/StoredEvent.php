<?php

namespace Codr\Eventually\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity]
#[ORM\Table(name: 'event_store')]
#[ORM\UniqueConstraint(name: 'uniq_aggregate_version', columns: ['aggregate_id', 'version'])]
#[ORM\UniqueConstraint(name: 'uniq_event_position', columns: ['position'])]
#[ORM\Index(name: 'idx_aggregate_type', columns: ['aggregate_type'])]
final class StoredEvent
{
    #[ORM\Column(type: Types::BIGINT, insertable: false, updatable: false, columnDefinition: 'BIGINT GENERATED ALWAYS AS IDENTITY NOT NULL')]
    private ?int $position = null; // @phpstan-ignore property.unusedType

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'uuid', unique: true)]
        private Uuid $id,
        #[ORM\Column(type: 'uuid')]
        private Uuid $aggregateId,
        #[ORM\Column(length: 255)]
        private string $aggregateType,
        #[ORM\Column(length: 255)]
        private string $eventType,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
        private array $payload,
        #[ORM\Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '{}'])]
        private array $metadata,
        #[ORM\Column(type: Types::INTEGER)]
        private int $version,
        #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
        private int $schemaRevision,
        #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, options: ['default' => 'CURRENT_TIMESTAMP'])]
        private DateTimeImmutable $createdAt
    ) {
    }

    public function id(): Uuid
    {
        return $this->id;
    }

    public function position(): ?int
    {
        return $this->position;
    }

    public function aggregateId(): Uuid
    {
        return $this->aggregateId;
    }

    public function aggregateType(): string
    {
        return $this->aggregateType;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function schemaRevision(): int
    {
        return $this->schemaRevision;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
