<?php

namespace Codr\Eventually\Doctrine\Tests;

use Codr\Eventually\Doctrine\Repository\DoctrineEventStore;
use Codr\Eventually\Doctrine\Tests\Fixture\ArrayEventPayloadSerializer;
use Codr\Eventually\Doctrine\Tests\Fixture\FixedClock;
use Codr\Eventually\Doctrine\Tests\Fixture\FixedMetadataProvider;
use Codr\Eventually\Doctrine\Tests\Fixture\NoteWasRevised;
use Codr\Eventually\Doctrine\Tests\Fixture\NoteWasWritten;
use Codr\Eventually\Domain\Upcaster\UpcasterInterface;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Bridge\Doctrine\Types\UuidType;

use function assert;
use function getenv;
use function is_int;
use function is_string;

abstract class DoctrineTestCase extends TestCase
{
    protected EntityManager $entityManager;

    public static function setUpBeforeClass(): void
    {
        $entityManager = self::createEntityManager();
        $tool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
        $entityManager->close();
    }

    protected function setUp(): void
    {
        $this->entityManager = self::createEntityManager();
        $this->entityManager->getConnection()->executeStatement('TRUNCATE event_store, aggregate_snapshot, view_counter RESTART IDENTITY');
    }

    protected function tearDown(): void
    {
        $this->entityManager->close();
    }

    /**
     * @param list<UpcasterInterface> $upcasters
     */
    protected function eventStore(array $upcasters = []): DoctrineEventStore
    {
        return new DoctrineEventStore(
            $this->entityManager,
            [
                'note.written' => ['class' => NoteWasWritten::class, 'revision' => 1],
                'note.revised' => ['class' => NoteWasRevised::class, 'revision' => 2],
            ],
            $upcasters,
            new ArrayEventPayloadSerializer(),
            new FixedClock(),
            new FixedMetadataProvider(),
        );
    }

    protected function rowCount(string $table): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
        assert(is_int($count) || is_string($count));

        return (int) $count;
    }

    private static function createEntityManager(): EntityManager
    {
        if (!Type::hasType('uuid')) {
            Type::addType('uuid', UuidType::class);
        }

        $root = __DIR__.'/..';
        $xmlDriver = new SimplifiedXmlDriver([$root.'/src/Resources/mapping' => 'Codr\Eventually'], '.orm.xml', true);
        $fixtureDriver = new SimplifiedXmlDriver([__DIR__.'/Fixture/mapping' => 'Codr\Eventually\Doctrine\Tests'], '.orm.xml', true);
        $chain = new MappingDriverChain();
        $chain->addDriver(new AttributeDriver([$root.'/src/Entity']), 'Codr\Eventually\Doctrine\Entity');
        $chain->addDriver($xmlDriver, 'Codr\Eventually\Application\View');
        $chain->addDriver($fixtureDriver, 'Codr\Eventually\Doctrine\Tests\Fixture');

        $config = new Configuration();
        $config->setMetadataDriverImpl($chain);
        $config->setNamingStrategy(new UnderscoreNamingStrategy());
        $config->setProxyDir(sys_get_temp_dir().'/eventually-doctrine-proxies');
        $config->setProxyNamespace('Codr\Eventually\Doctrine\Tests\Proxies');
        $config->enableNativeLazyObjects(true);

        $url = getenv('DATABASE_URL');
        $params = new DsnParser(['postgresql' => 'pdo_pgsql'])->parse(is_string($url) ? $url : throw new RuntimeException('DATABASE_URL is not set.'));

        return new EntityManager(DriverManager::getConnection($params, $config), $config);
    }
}
