<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Contract\Progress;

/** Receive progress events after each in-order append. */
interface ProgressListenerInterface
{
    /**
     * @param int $unitIndex Block/page index appended
     * @param int $unitLines Lines appended by this unit
     * @param int $totalLines Cumulative lines written
     */
    public function onProgress(int $unitIndex, int $unitLines, int $totalLines): void;
}
