<?php
declare(strict_types=1);

namespace Survos\JsonlBundle\Service;

/**
 * Default implementation of JsonlProfilerInterface.
 *
 * Goals:
 *  - Fast, dependency-free stats for JSONL rows.
 *  - Useful schema hints for downstream generators:
 *      - booleanLike
 *      - urlLike / jsonLike / imageLike
 *      - naturalLanguageLike (+ weak locale guess)
 *      - splitCandidate (delimited “list-like strings”)
 *  - Backwards compatible keys: total, nulls, distinct, distinctCapReached, types, stringLengths, booleanLike, storageHint.
 *
 * Notes:
 *  - The profiler is intentionally conservative: it emits hints/suggestions; you choose whether to apply transforms.
 *  - Distinct tracking is capped; we also keep a small sample of distinct strings for heuristics (stopwords, boolean-like, etc).
 */
final class JsonlProfiler implements JsonlProfilerInterface
{
    /** @var string[] */
    private const IMAGE_EXTENSIONS = ['jpg','jpeg','png','gif','webp','avif','svg','bmp'];

    /** @var string[] */
    private const LIST_DELIMITERS = [';', '|', ','];

    /**
     * Very small stopword sets for cheap language-ish guessing.
     * Keep tiny; this is not a full language detector.
     *
     * @var array<string, string[]>
     */
    private const STOPWORDS = [
        'en' => ['the','and','of','to','in','for','with','on','at','by','from','is','are','was','were','be','as','or'],
        'nl' => ['de','het','een','en','van','voor','met','op','aan','bij','uit','is','zijn','was','waren','te','als','of'],
        'fr' => ['le','la','les','un','une','et','de','des','pour','avec','sur','à','par','dans','est','sont','été','ou'],
        'es' => ['el','la','los','las','un','una','y','de','del','para','con','en','a','por','es','son','fue','o'],
    ];

    /**
     * @param int $distinctCap   Maximum distinct values to track per field.
     * @param int $sampleCap     Maximum sample strings to keep per field for heuristics.
     * @param array<string,mixed> $hints Optional per-field hints to guide profiling.
     *
     * Hints shape (all optional):
     *   [
     *     'trefwoorden' => [
     *        'preferDelimiter' => ';',            // for splitCandidate scoring
     *        'disableSplitCandidate' => true,     // force off
     *        'forceStorageHint' => 'string',      // override inferred storageHint
     *        'locale' => 'nl',                    // bias naturalLanguageLike locale guess
     *     ],
     *   ]
     */
    public function __construct(
        private readonly int $distinctCap = 100000, // hack, we need to assume if it reaches this all unique then it is unique.
        private readonly int $sampleCap = 256,
        private readonly array $hints = [],
    ) {
    }

    public function profile(iterable $rows): array
    {
        /** @var array<string, array<string, mixed>> $stats */
        $stats = [];

        foreach ($rows as $row) {
//            assert(is_array($row), "Row is not an array");

            foreach ($row as $field => $value) {
                if (!\array_key_exists($field, $stats)) {
                    $stats[$field] = $this->createEmptyFieldStats();
                }

                $fieldStats = &$stats[$field];
                $fieldStats['total']++;

                if ($value === null) {
                    $fieldStats['nulls']++;
                    unset($fieldStats);
                    continue;
                }

                $type = $this->normalizeType($value);
                if (!\in_array($type, $fieldStats['types'], true)) {
                    $fieldStats['types'][] = $type;
                }

                // Distinct values (non-null only), capped
                if (\count($fieldStats['distinctValues']) < $this->distinctCap) {
                    $dk = $this->normalizeDistinctKey($value);
                    $fieldStats['distinctValues'][$dk] = true;

                    // Keep a small sample of distinct strings for heuristics (boolean/stopwords/etc.)
                    if (\is_string($value) && \count($fieldStats['sampleStrings']) < $this->sampleCap) {
                        $fieldStats['sampleStrings'][] = $value;
                    }
                } else {
                    $fieldStats['distinctCapReached'] = true;
                }

                // String length stats + string-like hints
                if (\is_string($value)) {
                    $this->accumulateStringStats($fieldStats, $value);
                }

                // Array element stats (if arrays are present in JSONL)
                if (\is_array($value)) {
                    $this->accumulateArrayStats($fieldStats, $value);
                }

                unset($fieldStats);
            }
        }

        // Finalize per-field
        foreach ($stats as $field => &$fieldStats) {
            $fieldHint = $this->hints[$field] ?? [];

            $fieldStats['distinct'] = \count($fieldStats['distinctValues']);

            // booleanLike must be computed BEFORE distinctValues is unset
            $fieldStats['booleanLike'] = $this->isBooleanLike($fieldStats);

            // finalize string length avg
            $this->finalizeStringLengths($fieldStats);

            // finalize URL/JSON/image ratios
            $this->finalizeStringHints($fieldStats, $fieldHint);

            // compute splitCandidate (delimited list-like strings)
            $this->finalizeSplitCandidate($fieldStats, $fieldHint);

            // compute naturalLanguageLike
            $this->finalizeNaturalLanguageLike($fieldStats, $fieldHint);

            // infer storage hint (unless forced)
            if (isset($fieldHint['forceStorageHint']) && \is_string($fieldHint['forceStorageHint'])) {
                $fieldStats['storageHint'] = $fieldHint['forceStorageHint'];
            } else {
                $fieldStats['storageHint'] = $this->inferStorageHint($fieldStats);
            }

            // cleanup heavy scratch data
            unset($fieldStats['distinctValues'], $fieldStats['sampleStrings']);
        }
        unset($fieldStats);

        return $stats;
    }

    private function createEmptyFieldStats(): array
    {
        return [
            'total'              => 0,
            'nulls'              => 0,
            'distinct'           => 0,
            'distinctCapReached' => false,
            'types'              => [],

            // internal scratch (removed at end)
            'distinctValues'     => [],
            'sampleStrings'      => [],

            // string lengths
            'stringLengths'      => [
                'min'   => null,
                'max'   => null,
                'avg'   => null,
                'sum'   => 0,
                'count' => 0,
            ],

            // boolean-like
            'booleanLike'        => false,

            // inferred
            'storageHint'        => null,

            // string-like hints (counters)
            '_stringSamples'     => 0,
            '_urlHits'           => 0,
            '_jsonHits'          => 0,
            '_imageHits'         => 0,

            // split candidate counters
            '_split' => [
                'checked' => 0,
                // per delimiter: parsedRows, partsSum, tokenLenSum, tokenLenCount, tokenLenMax
                'delims'  => [],
                // tiny reservoir for token-length median-ish estimation
                'tokenLens' => [],
            ],

            // array element stats
            'arrayStats' => [
                'arrays'        => 0,
                'elements'      => 0,
                'elemTypes'     => [],
                'elemStrLens'   => ['min' => null, 'max' => null, 'sum' => 0, 'count' => 0],
                'elemDistinct'  => 0,
                'elemCapReached'=> false,
                // scratch
                '_elemDistinctValues' => [],
            ],
        ];
    }

    private function normalizeType(mixed $value): string
    {
        return match (true) {
            \is_int($value)    => 'int',
            \is_float($value)  => 'float',
            \is_bool($value)   => 'bool',
            \is_string($value) => 'string',
            \is_array($value)  => 'array',
            default            => 'other',
        };
    }

    private function normalizeDistinctKey(mixed $value): string
    {
        if (\is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return \json_encode($value, \JSON_THROW_ON_ERROR);
    }

    private function accumulateStringStats(array &$fieldStats, string $value): void
    {
        $fieldStats['_stringSamples']++;

        $len     = \strlen($value);
        $lengths = &$fieldStats['stringLengths'];

        $lengths['min'] = $lengths['min'] === null ? $len : \min((int) $lengths['min'], $len);
        $lengths['max'] = $lengths['max'] === null ? $len : \max((int) $lengths['max'], $len);
        $lengths['sum'] += $len;
        $lengths['count']++;

        unset($lengths);

        // URL-like
        if ($this->isUrlLike($value)) {
            $fieldStats['_urlHits']++;
        }

        // JSON-like string
        if ($this->isJsonLikeString($value)) {
            $fieldStats['_jsonHits']++;
        }

        // image-like URL/path
        if ($this->isImageLike($value)) {
            $fieldStats['_imageHits']++;
        }

        // split candidate evidence (structure-based; not length-based)
        $this->accumulateSplitEvidence($fieldStats, $value);
    }

    /**
     * @param array<int|string,mixed> $value
     */
    private function accumulateArrayStats(array &$fieldStats, array $value): void
    {
        $a = &$fieldStats['arrayStats'];
        $a['arrays']++;

        foreach ($value as $elem) {
            $a['elements']++;

            $t = $this->normalizeType($elem);
            if (!\in_array($t, $a['elemTypes'], true)) {
                $a['elemTypes'][] = $t;
            }

            // element string length stats
            if (\is_string($elem)) {
                $l = \strlen($elem);
                $ls = &$a['elemStrLens'];
                $ls['min'] = $ls['min'] === null ? $l : \min((int) $ls['min'], $l);
                $ls['max'] = $ls['max'] === null ? $l : \max((int) $ls['max'], $l);
                $ls['sum'] += $l;
                $ls['count']++;
                unset($ls);
            }

            // element distinct (string/int/bool only), capped
            if (\is_scalar($elem) || $elem === null) {
                if (\count($a['_elemDistinctValues']) < $this->distinctCap) {
                    $a['_elemDistinctValues'][(string) $elem] = true;
                } else {
                    $a['elemCapReached'] = true;
                }
            }
        }

        unset($a);
    }

    private function finalizeStringLengths(array &$fieldStats): void
    {
        $lengths = &$fieldStats['stringLengths'];
        if (($lengths['count'] ?? 0) > 0) {
            $lengths['avg'] = $lengths['sum'] / $lengths['count'];
        } else {
            $lengths['min'] = null;
            $lengths['max'] = null;
            $lengths['avg'] = null;
        }

        unset($lengths['sum'], $lengths['count']);
        unset($lengths);
    }

    private function finalizeStringHints(array &$fieldStats, array $fieldHint): void
    {
        $n = (int) ($fieldStats['_stringSamples'] ?? 0);
        if ($n <= 0) {
            $fieldStats['urlLike'] = false;
            $fieldStats['jsonLike'] = false;
            $fieldStats['imageLike'] = false;
            unset($fieldStats['_stringSamples'], $fieldStats['_urlHits'], $fieldStats['_jsonHits'], $fieldStats['_imageHits']);
            return;
        }

        $urlRatio   = ((int) $fieldStats['_urlHits']) / $n;
        $jsonRatio  = ((int) $fieldStats['_jsonHits']) / $n;
        $imageRatio = ((int) $fieldStats['_imageHits']) / $n;

        // conservative defaults
        $fieldStats['urlLike']   = $urlRatio >= 0.60 && $fieldStats['_urlHits'] >= 3;
        $fieldStats['jsonLike']  = $jsonRatio >= 0.60 && $fieldStats['_jsonHits'] >= 3;
        $fieldStats['imageLike'] = $imageRatio >= 0.50 && $fieldStats['_imageHits'] >= 3;

        // allow forcing off via hints
        foreach (['urlLike','jsonLike','imageLike'] as $k) {
            if (isset($fieldHint['force'][$k]) && \is_bool($fieldHint['force'][$k])) {
                $fieldStats[$k] = $fieldHint['force'][$k];
            }
        }

        unset($fieldStats['_stringSamples'], $fieldStats['_urlHits'], $fieldStats['_jsonHits'], $fieldStats['_imageHits']);
    }

    private function finalizeSplitCandidate(array &$fieldStats, array $fieldHint): void
    {
        if (!empty($fieldHint['disableSplitCandidate'])) {
            $fieldStats['splitCandidate'] = ['enabled' => false];
            unset($fieldStats['_split']);
            return;
        }

        $checked = (int) ($fieldStats['_split']['checked'] ?? 0);
        if ($checked <= 0) {
            $fieldStats['splitCandidate'] = ['enabled' => false];
            unset($fieldStats['_split']);
            return;
        }

        $prefer = $fieldHint['preferDelimiter'] ?? null;
        $best = null;

        foreach (($fieldStats['_split']['delims'] ?? []) as $delim => $d) {
            $parsed = (int) ($d['parsed'] ?? 0);
            if ($parsed <= 0) {
                continue;
            }

            $ratio = $parsed / $checked;
            $avgParts = ($d['partsSum'] ?? 0) > 0 ? ($d['partsSum'] / $parsed) : 0.0;
            $avgTokenLen = ($d['tokenLenCount'] ?? 0) > 0 ? ($d['tokenLenSum'] / $d['tokenLenCount']) : 0.0;
            $maxTokenLen = (int) ($d['tokenLenMax'] ?? 0);

            // paragraph guard: if tokens get huge, demote hard
            $paragraphPenalty = 1.0;
            if ($maxTokenLen > 250) {
                $paragraphPenalty = 0.25;
            } elseif ($maxTokenLen > 160) {
                $paragraphPenalty = 0.5;
            }

            // score: ratio dominates; also prefer stable small-ish tokens
            $score = $ratio * 1.0;
            if ($avgParts >= 2.0) {
                $score += 0.10;
            }
            if ($avgTokenLen > 0 && $avgTokenLen <= 40) {
                $score += 0.10;
            }

            // comma is ambiguous; require stronger evidence
            if ($delim === ',') {
                $score *= 0.75;
                if ($ratio < 0.35) {
                    $score *= 0.5;
                }
            }

            // honor preferDelimiter slightly
            if (\is_string($prefer) && $prefer !== '' && $prefer === $delim) {
                $score += 0.08;
            }

            $score *= $paragraphPenalty;

            if ($best === null || $score > $best['score']) {
                $best = [
                    'delimiter' => $delim,
                    'ratio' => $ratio,
                    'avgParts' => $avgParts,
                    'avgTokenLen' => $avgTokenLen,
                    'maxTokenLen' => $maxTokenLen,
                    'score' => $score,
                ];
            }
        }

        if ($best === null) {
            $fieldStats['splitCandidate'] = ['enabled' => false];
            unset($fieldStats['_split']);
            return;
        }

        // conservative enable threshold; tune as needed
        $enabled = ($best['ratio'] >= 0.30 && $best['avgParts'] >= 2.0 && $best['maxTokenLen'] <= 250);

        // confidence: primarily ratio + small bonus for avgParts
        $confidence = \min(1.0, ($best['ratio'] * 1.15) + \min(0.10, ($best['avgParts'] - 2.0) * 0.03));
        if (!$enabled) {
            $confidence *= 0.6;
        }

        $fieldStats['splitCandidate'] = [
            'enabled'    => $enabled,
            'delimiter'  => $best['delimiter'],
            'ratio'      => \round($best['ratio'], 4),
            'avgParts'   => \round($best['avgParts'], 3),
            'avgTokenLen'=> \round($best['avgTokenLen'], 3),
            'maxTokenLen'=> $best['maxTokenLen'],
            'confidence' => \round($confidence, 4),
        ];

        unset($fieldStats['_split']);
    }

    private function finalizeNaturalLanguageLike(array &$fieldStats, array $fieldHint): void
    {
        $samples = $fieldStats['sampleStrings'] ?? [];
        if (!\is_array($samples) || $samples === []) {
            $fieldStats['naturalLanguageLike'] = false;
            return;
        }

        $forcedLocale = $fieldHint['locale'] ?? null;
        $scores = ['en' => 0, 'nl' => 0, 'fr' => 0, 'es' => 0];

        $checked = 0;
        $nlHits = 0;

        foreach ($samples as $s) {
            if (!\is_string($s)) {
                continue;
            }
            $s = \trim($s);
            if ($s === '') {
                continue;
            }

            // quick filters: code-ish strings aren’t NL
            if ($this->looksCodeLike($s)) {
                $checked++;
                continue;
            }

            // we want multi-word strings; single tokens are rarely “natural language”
            if (\substr_count($s, ' ') < 1) {
                $checked++;
                continue;
            }

            // tokenize alpha words
            $words = \preg_split('/[^\\p{L}]+/u', \mb_strtolower($s), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (\count($words) < 3) {
                $checked++;
                continue;
            }

            $checked++;

            foreach ($scores as $lang => $_) {
                $stop = self::STOPWORDS[$lang];
                $hit = 0;
                foreach ($words as $w) {
                    if (\in_array($w, $stop, true)) {
                        $hit++;
                    }
                }
                if ($hit >= 1) {
                    $scores[$lang] += $hit;
                }
            }

            // a weak general “NL-ish” score: has spaces + low digit density + not url/json
            if (!$this->isUrlLike($s) && !$this->isJsonLikeString($s)) {
                $digits = \preg_match_all('/\\d/', $s) ?: 0;
                if ($digits <= \max(2, (int) (\strlen($s) / 25))) {
                    $nlHits++;
                }
            }
        }

        if ($checked === 0) {
            $fieldStats['naturalLanguageLike'] = false;
            return;
        }

        // pick locale with highest stopword score (unless forced)
        $bestLocale = null;
        $bestScore = 0;
        foreach ($scores as $lang => $score) {
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestLocale = $lang;
            }
        }

        if (\is_string($forcedLocale) && $forcedLocale !== '') {
            $bestLocale = $forcedLocale;
        }

        // NL-like: requires that a decent fraction of samples look sentence-ish
        $ratio = $nlHits / $checked;
        $isNL = ($ratio >= 0.35) && !$fieldStats['urlLike'] && !$fieldStats['jsonLike'];

        $fieldStats['naturalLanguageLike'] = $isNL;
        if ($isNL) {
            $fieldStats['localeGuess'] = $bestLocale;
            $fieldStats['naturalLanguageRatio'] = \round($ratio, 4);
        }
    }

    private function isBooleanLike(array $fieldStats): bool
    {
        $types = $fieldStats['types'] ?? [];
        if (!\is_array($types)) {
            return false;
        }

        // Real bool types present?
        if ($types === ['bool'] || $types === ['bool', 'null'] || $types === ['null', 'bool']) {
            return true;
        }

        // String-like boolean domain?
        $distinct = (int) ($fieldStats['distinct'] ?? 0);
        if ($distinct <= 6 && \in_array('string', $types, true)) {
            $values = $fieldStats['distinctValues'] ?? [];
            if (!\is_array($values) || $values === []) {
                return false;
            }

            $keys = \array_keys($values);
            $normalized = \array_map(static fn(string $v) => \strtolower(\trim($v)), $keys);

            $allowed = ['0','1','true','false','yes','no','y','n','t','f'];
            foreach ($normalized as $v) {
                if ($v === '') {
                    continue;
                }
                if (!\in_array($v, $allowed, true)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private function inferStorageHint(array $fieldStats): ?string
    {
        $types = $fieldStats['types'] ?? [];
        if (!\is_array($types) || $types === []) {
            return null;
        }

        // Prefer explicit array presence
        if (\in_array('array', $types, true)) {
            return 'json';
        }

        if ($types === ['bool'] || $types === ['bool', 'null'] || $types === ['null', 'bool']) {
            return 'bool';
        }

        if ($types === ['int'] || $types === ['int', 'null'] || $types === ['null', 'int']) {
            return 'int';
        }

        if ($types === ['float'] || $types === ['float', 'null'] || $types === ['null', 'float']) {
            return 'float';
        }

        // JSON-like strings: keep as string but it’s a hint that downstream may decode
        if (!empty($fieldStats['jsonLike'])) {
            return 'string';
        }

        return 'string';
    }

    private function isUrlLike(string $s): bool
    {
        // Avoid expensive validation for enormous blobs
        if (\strlen($s) > 2048) {
            return false;
        }
        if (!\str_starts_with($s, 'http://') && !\str_starts_with($s, 'https://')) {
            return false;
        }
        return \filter_var($s, \FILTER_VALIDATE_URL) !== false;
    }

    private function isJsonLikeString(string $s): bool
    {
        $t = \ltrim($s);
        if ($t === '' || \strlen($t) > 4096) {
            return false;
        }
        $c = $t[0];
        if ($c !== '{' && $c !== '[') {
            return false;
        }

        try {
            \json_decode($t, true, 32, \JSON_THROW_ON_ERROR);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function isImageLike(string $s): bool
    {
        $path = \parse_url($s, \PHP_URL_PATH);
        if (!\is_string($path) || $path === '') {
            $path = $s;
        }
        $ext = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));
        return $ext !== '' && \in_array($ext, self::IMAGE_EXTENSIONS, true);
    }

    private function looksCodeLike(string $s): bool
    {
        // heuristic: a lot of braces, underscores, slashes, equals, etc.
        $len = \strlen($s);
        if ($len < 12) {
            return false;
        }
        $punct = \preg_match_all('/[{}<>$#@=:_\\\\\\/]/', $s) ?: 0;
        $digits = \preg_match_all('/\\d/', $s) ?: 0;

        // Code-ish if punctuation density is high and spaces are low
        $spaces = \substr_count($s, ' ');
        if ($spaces === 0 && ($punct >= 3 || $digits >= 4)) {
            return true;
        }

        return ($punct / $len) >= 0.12;
    }

    private function accumulateSplitEvidence(array &$fieldStats, string $value): void
    {
        // avoid paragraph-y / multi-line blobs early
        if (\str_contains($value, "\n") || \str_contains($value, "\r")) {
            return;
        }

        // don’t try to split URLs / JSON blobs
        if ($this->isUrlLike($value) || $this->isJsonLikeString($value)) {
            return;
        }

        $s = \trim($value);
        if ($s === '') {
            return;
        }

        // “checked” counts strings that are eligible for split detection
        $fieldStats['_split']['checked']++;

        // keep effort bounded
        if (\strlen($s) > 4000) {
            return;
        }

        foreach (self::LIST_DELIMITERS as $delim) {
            if (!\str_contains($s, $delim)) {
                continue;
            }

            // Avoid comma splitting on numbers like "1,234" or "12,5"
            if ($delim === ',' && \preg_match('/\\d,\\d/', $s) === 1) {
                continue;
            }

            $parts = \explode($delim, $s);
            $parts = \array_map('trim', $parts);
            $parts = \array_values(\array_filter($parts, static fn($p) => $p !== ''));

            if (\count($parts) < 2) {
                continue;
            }

            // Token sanity: reject if tokens look like full sentences/paragraph pieces
            $tokenLenSum = 0;
            $tokenLenCount = 0;
            $tokenLenMax = 0;

            foreach ($parts as $p) {
                $l = \strlen($p);
                $tokenLenSum += $l;
                $tokenLenCount++;
                $tokenLenMax = \max($tokenLenMax, $l);

                // keep a tiny reservoir for debugging/tuning (not surfaced)
                if (\count($fieldStats['_split']['tokenLens']) < 64) {
                    $fieldStats['_split']['tokenLens'][] = $l;
                }
            }

            $d = &$fieldStats['_split']['delims'][$delim];
            if (!\is_array($d)) {
                $d = [
                    'parsed' => 0,
                    'partsSum' => 0,
                    'tokenLenSum' => 0,
                    'tokenLenCount' => 0,
                    'tokenLenMax' => 0,
                ];
            }

            $d['parsed']++;
            $d['partsSum'] += \count($parts);
            $d['tokenLenSum'] += $tokenLenSum;
            $d['tokenLenCount'] += $tokenLenCount;
            $d['tokenLenMax'] = \max((int) $d['tokenLenMax'], $tokenLenMax);

            unset($d);
        }
    }
}
