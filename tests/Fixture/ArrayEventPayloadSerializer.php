<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Doctrine\Serializer\EventPayloadSerializerInterface;
use Codr\Eventually\Domain\EventInterface;
use LogicException;

use function is_subclass_of;

final class ArrayEventPayloadSerializer implements EventPayloadSerializerInterface
{
    public function normalize(EventInterface $event): array
    {
        return $event instanceof ArrayPayloadEvent ? $event->toPayload() : throw new LogicException('Unsupported event.');
    }

    public function denormalize(array $payload, string $eventClass): EventInterface
    {
        return is_subclass_of($eventClass, ArrayPayloadEvent::class) ? $eventClass::fromPayload($payload) : throw new LogicException('Unsupported event.');
    }
}
