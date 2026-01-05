<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Model;

use Survos\JsonlBundle\Contract\JsonlStateInterface;

final readonly class JsonlWriterResult
{
    public function __construct(
        public JsonlStateInterface $state,
    ) {
    }
}
