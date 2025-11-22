<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

/**
 * Generic, engine-agnostic profiler for JSONL-style row streams.
 *
 * - The profiler works over any iterable of associative arrays.
 * - It returns a normalized stats array keyed by field name.
 * - Higher-level bundles (Import/Meili/Code) can wrap this into
 *   JsonlProfile / FieldStats models or other domain objects.
 */
interface JsonlProfilerInterface
{
    /**
     * @param iterable<array<string, mixed>> $rows
     *
     * @return array<string, array<string, mixed>> Field => stats
     *     [
     *       'total'              => int,
     *       'nulls'              => int,
     *       'distinct'           => int,
     *       'distinctCapReached' => bool,
     *       'types'              => string[],
     *       'stringLengths'      => [
     *           'min' => ?int,
     *           'max' => ?int,
     *           'avg' => ?float,
     *       ],
     *       'booleanLike'        => bool,
     *       'storageHint'        => ?string
     *     ]
     */
    public function profile(iterable $rows): array;
}
