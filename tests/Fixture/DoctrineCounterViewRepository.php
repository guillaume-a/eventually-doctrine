<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Doctrine\Repository\AbstractDoctrineViewRepository;

/**
 * @extends AbstractDoctrineViewRepository<CounterView>
 */
final readonly class DoctrineCounterViewRepository extends AbstractDoctrineViewRepository
{
    public function find(string $id): ?CounterView
    {
        return $this->findView($id);
    }

    protected function viewClass(): string
    {
        return CounterView::class;
    }
}
