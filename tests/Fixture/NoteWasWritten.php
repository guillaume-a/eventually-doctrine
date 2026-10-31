<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Domain\Aggregate\AggregateId;
use Codr\Eventually\Domain\Attribute\DomainEvent;

use function is_string;

#[DomainEvent('note.written')]
final readonly class NoteWasWritten implements ArrayPayloadEvent
{
    public function __construct(
        private AggregateId $aggregateId,
        public string $text,
    ) {
    }

    public function aggregateId(): AggregateId
    {
        return $this->aggregateId;
    }

    public function toPayload(): array
    {
        return ['aggregateId' => $this->aggregateId->toString(), 'text' => $this->text];
    }

    public static function fromPayload(array $payload): static
    {
        $id = $payload['aggregateId'] ?? null;
        $text = $payload['text'] ?? null;

        return new self(AggregateId::fromString(is_string($id) ? $id : ''), is_string($text) ? $text : '');
    }
}
