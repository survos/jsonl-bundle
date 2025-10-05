<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Path;

/** Turns baseUrl + query params into a concrete URL (headers handled elsewhere). */
interface UrlBuilderInterface
{
    /**
     * @param array<string,scalar|array> $query
     */
    public function build(string $baseUrl, array $query): string;
}
