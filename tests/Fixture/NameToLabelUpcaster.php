<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Domain\Upcaster\UpcasterInterface;

final class NameToLabelUpcaster implements UpcasterInterface
{
    public function eventType(): string
    {
        return 'note.revised';
    }

    public function fromRevision(): int
    {
        return 1;
    }

    public function upcast(array $payload): array
    {
        $payload['label'] = $payload['name'];
        unset($payload['name']);

        return $payload;
    }
}
