<?php declare(strict_types=1);

namespace Survos\JsonlBundle\Enhance;

interface RecordEnhancementListenerInterface
{
    public function __invoke(RecordEnhancementContext $context): void;
}
