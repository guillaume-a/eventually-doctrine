<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Application\View\View;
use DateTimeImmutable;

final class CounterView extends View
{
    public function __construct(
        string $id,
        public int $count,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
    ) {
        parent::__construct($id, $createdAt, $updatedAt);
    }

    public function increment(DateTimeImmutable $at): void
    {
        ++$this->count;
        $this->touch($at);
    }
}
