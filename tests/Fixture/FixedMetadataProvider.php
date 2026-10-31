<?php

namespace Codr\Eventually\Doctrine\Tests\Fixture;

use Codr\Eventually\Application\Metadata\MetadataProviderInterface;
use Codr\Eventually\Domain\EventMetadata;

final readonly class FixedMetadataProvider implements MetadataProviderInterface
{
    public function __construct(private EventMetadata $metadata = new EventMetadata('correlation-1', 'causation-1', 'actor-1'))
    {
    }

    public function current(): EventMetadata
    {
        return $this->metadata;
    }
}
