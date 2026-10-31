<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Domain\EventInterface;

interface ArrayPayloadEvent extends EventInterface
{
    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload): static;
}
