<?php

namespace Codr\Eventually\Doctrine\Serializer;

use Codr\Eventually\Domain\EventInterface;

interface EventPayloadSerializerInterface
{
    /**
     * @return array<string, mixed>
     */
    public function normalize(EventInterface $event): array;

    /**
     * @param array<string, mixed>         $payload
     * @param class-string<EventInterface> $eventClass
     */
    public function denormalize(array $payload, string $eventClass): EventInterface;
}
