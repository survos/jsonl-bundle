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
 *
 * @deprecated since survos/jsonl-bundle 2.8. The canonical profiler is now
 *     {@see \Survos\JsonlBundle\Sqlite\SqlProfiler} (SQL / json_tree, bounded by
 *     construction) which writes the sidecar `field_stats` table; read it back via
 *     {@see \Survos\JsonlBundle\Sqlite\SidecarDb::loadFieldStats()}. This in-PHP,
 *     iterable-based interface is retained only for legacy consumers (md & meili
 *     apps, folio FolioSchemaSnapshotter, code-bundle, import-bundle, past-perfect)
 *     and will be removed once they migrate to the file/SQL profiler.
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
