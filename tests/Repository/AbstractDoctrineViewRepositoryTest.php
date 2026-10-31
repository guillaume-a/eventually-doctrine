<?php

namespace Codr\Eventually\Doctrine\Tests\Repository;

use Codr\Eventually\Application\ReadModel\PageRequest;
use Codr\Eventually\Doctrine\Tests\DoctrineTestCase;
use Codr\Eventually\Doctrine\Tests\Fixture\CounterView;
use Codr\Eventually\Doctrine\Tests\Fixture\DoctrineCounterViewRepository;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

use function array_map;

final class AbstractDoctrineViewRepositoryTest extends DoctrineTestCase
{
    public function testSaveInsertsThenUpdatesInPlaceKeepingListOrder(): void
    {
        $repository = new DoctrineCounterViewRepository($this->entityManager);
        $created = new DateTimeImmutable('2026-01-01 10:00:00');
        $touched = new DateTimeImmutable('2026-01-02 11:00:00');
        $first = new CounterView(Uuid::v7()->toRfc4122(), 0, $created, $created);
        $second = new CounterView(Uuid::v7()->toRfc4122(), 0, $created, $created);

        $repository->save($first);
        $repository->save($second);
        $this->entityManager->flush();

        $first->increment($touched);
        $repository->save($first);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $found = $repository->find($first->id);
        self::assertInstanceOf(CounterView::class, $found);
        self::assertSame(1, $found->count);
        self::assertEquals($created, $found->createdAt);
        self::assertEquals($touched, $found->updatedAt);
        self::assertNull($found->deletedAt);

        $page = $repository->paginate(new PageRequest());
        self::assertSame(2, $page->total);
        self::assertSame([$first->id, $second->id], array_map(static fn (CounterView $view): string => $view->id, $page->items));
    }

    public function testPaginateWindowsTheListWithTheTotal(): void
    {
        $repository = new DoctrineCounterViewRepository($this->entityManager);
        $at = new DateTimeImmutable('2026-01-01 10:00:00');
        $ids = [];

        for ($count = 1; $count <= 3; ++$count) {
            $ids[] = $id = Uuid::v7()->toRfc4122();
            $repository->save(new CounterView($id, $count, $at, $at));
        }
        $this->entityManager->flush();
        $this->entityManager->clear();

        $page = $repository->paginate(new PageRequest(2, 2));

        self::assertSame(3, $page->total);
        self::assertSame([$ids[2]], array_map(static fn (CounterView $view): string => $view->id, $page->items));
    }

    public function testClearTruncatesTheTableAndTheIdentityMap(): void
    {
        $repository = new DoctrineCounterViewRepository($this->entityManager);
        $at = new DateTimeImmutable('2026-01-01 10:00:00');
        $view = new CounterView(Uuid::v7()->toRfc4122(), 1, $at, $at);
        $repository->save($view);
        $this->entityManager->flush();

        $repository->clear();

        self::assertSame(0, $this->rowCount('view_counter'));
        self::assertFalse($this->entityManager->contains($view));
    }
}
