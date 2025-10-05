<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Path;

use Survos\JsonlBundle\Contract\UrlBuilderInterface;

final class DefaultUrlBuilder implements UrlBuilderInterface
{
    public function build(string $base, array $query = []): string
    {
        if ($query === []) {
            return $base;
        }

        // Use RFC3986 encoding and preserve numeric/string keys for arrays.
        $qs = http_build_query($query, arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);
        return str_contains($base, '?') ? ($base . '&' . $qs) : ($base . '?' . $qs);
    }
}
