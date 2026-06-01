<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

/**
 * @deprecated Use JsonlStateService::rows() / countNewlines().
 */
final class JsonlCountService
{
    public function __construct(
        private readonly JsonlStateService $stateService,
    ) {}

    public function rows(string $jsonlPath): int
    {
        return $this->stateService->rows($jsonlPath);
    }

    public function countNewlines(string $path): int
    {
        return $this->stateService->countNewlines($path);
    }
}
