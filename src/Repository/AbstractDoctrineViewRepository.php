<?php

namespace Codr\Eventually\Doctrine\Repository;

use Codr\Eventually\Application\ReadModel\Page;
use Codr\Eventually\Application\ReadModel\PageRequest;
use Codr\Eventually\Application\View\View;
use Doctrine\ORM\EntityManagerInterface;

use function is_numeric;
use function sprintf;

/**
 * What every view repository shares. Writes are staged on the entity manager and flushed with the event rows by the
 * `doctrine_transaction` middleware, so a failing command leaves neither. Nothing here flushes.
 *
 * @template T of View
 */
abstract readonly class AbstractDoctrineViewRepository
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return class-string<T>
     */
    abstract protected function viewClass(): string;

    /**
     * @return Page<T>
     */
    public function paginate(PageRequest $request): Page
    {
        /** @var list<T> $items */
        $items = $this->entityManager->createQueryBuilder()
            ->select('v')
            ->from($this->viewClass(), 'v')
            ->orderBy('v.position', 'ASC')
            ->setFirstResult($request->offset())
            ->setMaxResults($request->limit)
            ->getQuery()
            ->getResult();

        $total = $this->entityManager->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from($this->viewClass(), 'v')
            ->getQuery()
            ->getSingleScalarResult();

        return new Page($items, is_numeric($total) ? (int) $total : 0, $request->page, $request->limit);
    }

    /**
     * @param T $view
     */
    public function save(View $view): void
    {
        $this->entityManager->persist($view);
    }

    /**
     * Rebuild only: the identity map is dropped whole, so nothing staged and unflushed survives it.
     */
    public function clear(): void
    {
        $table = $this->entityManager->getClassMetadata($this->viewClass())->getTableName();
        $this->entityManager->getConnection()->executeStatement(sprintf('TRUNCATE %s RESTART IDENTITY', $table));
        $this->entityManager->clear();
    }

    /**
     * @return T|null
     */
    protected function findView(string $id): ?View
    {
        return $this->entityManager->find($this->viewClass(), $id);
    }
}
