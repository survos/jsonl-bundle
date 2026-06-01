<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

use Survos\JsonlBundle\Model\JsonlState;

/**
 * @deprecated Use JsonlStateService. Kept as the older read-oriented entry point.
 */
final class JsonlStateRepository
{
    public function __construct(
        private readonly JsonlStateService $stateService = new JsonlStateService(),
    ) {
    }

    public function load(string $jsonlPath): JsonlState
    {
        return $this->stateService->load($jsonlPath);
    }

    public function ensure(string $jsonlPath): JsonlState
    {
        return $this->stateService->ensure($jsonlPath);
    }
}
