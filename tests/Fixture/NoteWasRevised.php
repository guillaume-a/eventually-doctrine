<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Domain\Aggregate\AggregateId;
use Codr\Eventually\Domain\Attribute\DomainEvent;

use function is_string;

#[DomainEvent('note.revised', revision: 2)]
final readonly class NoteWasRevised implements ArrayPayloadEvent
{
    public function __construct(
        private AggregateId $aggregateId,
        public string $label,
    ) {
    }

    public function aggregateId(): AggregateId
    {
        return $this->aggregateId;
    }

    public function toPayload(): array
    {
        return ['aggregateId' => $this->aggregateId->toString(), 'label' => $this->label];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['aggregateId'] ?? null;
        $label = $payload['label'] ?? null;

        return new self(AggregateId::fromString(is_string($id) ? $id : ''), is_string($label) ? $label : '');
    }
}
