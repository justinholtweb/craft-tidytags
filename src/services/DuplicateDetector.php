<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use yii\caching\TagDependency;

/**
 * Near-duplicate detection across every Tidy Tags source.
 *
 * Whether two titles count as near-duplicates is decided by {@see TitleMatcher},
 * which offers a fuzzy (edit-distance) and a strict (affix-and-qualifier)
 * strategy. This service owns the querying, enrichment and clustering; it does
 * not own the comparison.
 *
 * Items returned by every public method are enriched with the source they came
 * from, the differentiator field value (if configured), and a key/value map of
 * display field values, so callers can render disambiguating context (e.g.
 * "England (Football)" vs "England (Cricket)") without re-querying.
 *
 * Scans are built to cope with sources of tens of thousands of items:
 *
 * - Clustering runs on lightweight records (ID, title, normalized title,
 *   differentiator) rather than loaded elements, and only the items actually
 *   returned are enriched.
 * - Candidate pairs come from an index rather than comparing every item with
 *   every other: strict titles are grouped by {@see TitleMatcher::strictKey()},
 *   and fuzzy titles are matched through a pigeonhole segment index.
 * - Records and clusters are cached against Craft's element cache tags, so
 *   they are rebuilt only after a tag or entry changes.
 */
class DuplicateDetector extends Component
{
    /**
     * Maximum Levenshtein distance considered a near-duplicate.
     *
     * Only consulted by the fuzzy strategy; the strict strategy does not use
     * edit distance at all.
     */
    public int $defaultThreshold = 2;

    /**
     * How long scan records and clusters stay cached, in seconds. They are also
     * invalidated whenever an element of the scanned type is saved or deleted.
     */
    public int $cacheDuration = 86400;

    /**
     * Finds clusters of near-duplicate elements within a single source.
     *
     * Clustering respects the source's configured differentiator field: two
     * items with the same normalized title but different differentiator values
     * are not put in the same cluster.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function findDuplicates(Source $source, ?int $siteId = null, ?int $threshold = null): array
    {
        $siteId = $this->_siteId($siteId);
        $clusters = $this->_sourceClusters($source, $siteId, $threshold ?? $this->defaultThreshold);

        return $this->_enrichClusters($clusters, [$source->uid => $source], $siteId);
    }

    /**
     * Finds all near-duplicate clusters across every configured source.
     *
     * @return array<int, array{source: Source, clusters: array}>
     */
    public function findAllDuplicates(?int $siteId = null, ?int $threshold = null): array
    {
        $out = [];
        foreach (Plugin::$plugin->sources->getAllSources() as $source) {
            $clusters = $this->findDuplicates($source, $siteId, $threshold);
            if (!empty($clusters)) {
                $out[] = ['source' => $source, 'clusters' => $clusters];
            }
        }
        return $out;
    }

    /**
     * Finds clusters of near-duplicate items pooled across every configured
     * source — answering "is the same name showing up in Tags and Teams?".
     *
     * Only clusters that span more than one source are returned; same-source
     * clusters are already covered by findDuplicates / findAllDuplicates.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function findCrossSourceDuplicates(?int $siteId = null, ?int $threshold = null): array
    {
        $siteId = $this->_siteId($siteId);
        $sources = Plugin::$plugin->sources->getAllSources();
        $clusters = $this->_crossClusters($sources, $siteId, $threshold ?? $this->defaultThreshold);

        return $this->_enrichClusters($clusters, $this->_byUid($sources), $siteId);
    }

    /**
     * Returns one page of duplicate clusters for the Duplicates screen, enriching
     * only the clusters on that page.
     *
     * Under the `within` scope, clusters from every source are listed in source
     * order and each carries the source it belongs to, so a page can show a
     * heading whenever the source changes. Under `cross`, clusters span sources
     * and `source` is null.
     *
     * @return array{
     *     clusters: array<int, array{source: ?Source, items: array<int, array<string, mixed>>}>,
     *     total: int,
     *     page: int,
     *     totalPages: int,
     *     first: int,
     *     last: int,
     * }
     */
    public function getDuplicatesPage(
        string $scope,
        ?int $siteId,
        ?int $threshold,
        int $page,
        int $perPage,
    ): array {
        $siteId = $this->_siteId($siteId);
        $threshold = $threshold ?? $this->defaultThreshold;
        $perPage = max(1, $perPage);
        $sources = Plugin::$plugin->sources->getAllSources();
        $sourcesByUid = $this->_byUid($sources);

        // Each entry is [source UID or null, records].
        $all = [];
        if ($scope === 'cross') {
            foreach ($this->_crossClusters($sources, $siteId, $threshold) as $cluster) {
                $all[] = [null, $cluster];
            }
        } else {
            foreach ($sources as $source) {
                foreach ($this->_sourceClusters($source, $siteId, $threshold) as $cluster) {
                    $all[] = [$source->uid, $cluster];
                }
            }
        }

        $total = count($all);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        $enriched = $this->_enrichClusters(array_column($slice, 1), $sourcesByUid, $siteId);

        $clusters = [];
        foreach ($slice as $i => [$sourceUid]) {
            // An element deleted since the scan was cached can leave a cluster
            // with one item, which is no longer a duplicate.
            if (count($enriched[$i] ?? []) < 2) {
                continue;
            }
            $clusters[] = [
                'source' => $sourceUid !== null ? $sourcesByUid[$sourceUid] : null,
                'items' => $enriched[$i],
            ];
        }

        return [
            'clusters' => $clusters,
            'total' => $total,
            'page' => $page,
            'totalPages' => $totalPages,
            'first' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
            'last' => ($page - 1) * $perPage + count($slice),
        ];
    }

    /**
     * Finds tags and entries similar to a given title, used by the editor-side
     * "did you mean" warning.
     *
     * Always scans the named tag group (when $groupId is provided) plus every
     * configured entry-backed source so an editor typing into a tag field is
     * warned about both existing tags and existing same-named entries (e.g. a
     * Team or Competition the licensee should reuse).
     *
     * Runs on every pause in typing, so it compares against cached records and
     * only loads the elements it returns.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSimilar(
        string $title,
        ?int $groupId = null,
        ?int $siteId = null,
        ?int $threshold = null,
        int $limit = 10,
    ): array {
        $threshold = $threshold ?? $this->defaultThreshold;
        $title = trim($title);
        if ($title === '') {
            return [];
        }

        $siteId = $this->_siteId($siteId);
        $matcher = $this->_matcher();
        $strict = $matcher->getStrategy() === TitleMatcher::STRATEGY_STRICT;
        $normalized = $matcher->normalize($title);

        $candidateSources = [];

        if ($groupId !== null) {
            $tagSource = Plugin::$plugin->sources->getTagSource($groupId);
            if ($tagSource !== null) {
                $candidateSources[] = $tagSource;
            }
        }

        foreach (Plugin::$plugin->sources->getConfiguredEntrySections() as $section) {
            $candidateSources[] = Source::fromSection($section);
        }

        $matches = [];
        foreach ($candidateSources as $source) {
            foreach ($this->_records($source, $siteId) as $record) {
                $distance = $strict
                    ? $matcher->compareStrict($title, $record['title'])
                    : $matcher->compareFuzzyNormalized($normalized, $record['normalized'], $threshold);
                if ($distance !== null) {
                    $record['distance'] = $distance;
                    $matches[] = $record;
                }
            }
        }

        usort($matches, fn($a, $b) => $a['distance'] <=> $b['distance']);
        $matches = array_slice($matches, 0, $limit);

        [$enriched] = $this->_enrichClusters([$matches], $this->_byUid($candidateSources), $siteId) + [[]];
        $distances = array_column($matches, 'distance', 'id');
        foreach ($enriched as &$item) {
            $item['distance'] = $distances[$item['id']];
        }

        return $enriched;
    }

    /**
     * Within-source clusters as lightweight records, cached.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function _sourceClusters(Source $source, int $siteId, int $threshold): array
    {
        return $this->_cached(
            ['clusters', $source->uid, $siteId, $this->_matchFingerprint($threshold), $this->_differentiatorFingerprint([$source])],
            [$source],
            fn() => $this->_clusterRecords($this->_records($source, $siteId), $threshold),
        );
    }

    /**
     * Clusters pooled across sources, keeping only those that span more than
     * one source, cached.
     *
     * @param Source[] $sources
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function _crossClusters(array $sources, int $siteId, int $threshold): array
    {
        $uids = array_map(fn(Source $s) => $s->uid, $sources);
        sort($uids);

        return $this->_cached(
            ['cross', $uids, $siteId, $this->_matchFingerprint($threshold), $this->_differentiatorFingerprint($sources)],
            $sources,
            function() use ($sources, $siteId, $threshold) {
                $records = [];
                foreach ($sources as $source) {
                    array_push($records, ...$this->_records($source, $siteId));
                }

                return array_values(array_filter(
                    $this->_clusterRecords($records, $threshold),
                    fn(array $cluster) => count(array_unique(array_column($cluster, 'sourceUid'))) > 1,
                ));
            },
        );
    }

    /**
     * Every item in a source as a lightweight record, cached.
     *
     * Elements are only loaded when the source has a differentiator field,
     * whose value has to be read through the element; otherwise IDs and titles
     * come straight from the database. Either way the records keep the
     * source's default element order, which clustering depends on.
     *
     * @return array<int, array{id: int, title: string, normalized: string, differentiator: ?string, sourceUid: string}>
     */
    private function _records(Source $source, int $siteId): array
    {
        $sources = Plugin::$plugin->sources;
        $differentiatorHandle = $sources->getDifferentiatorHandle($source);

        return $this->_cached(
            ['records', $source->uid, $siteId, $differentiatorHandle],
            [$source],
            function() use ($source, $siteId, $sources, $differentiatorHandle) {
                $matcher = $this->_matcher();
                $query = $sources->baseQuery($source)->siteId($siteId);
                $records = [];

                if ($differentiatorHandle === null) {
                    $pairs = $query->select(['elements.id', 'elements_sites.title'])->pairs();
                    foreach ($pairs as $id => $title) {
                        $records[] = $this->_record((int)$id, (string)$title, null, $source, $matcher);
                    }
                    return $records;
                }

                foreach ($query->each(250) as $element) {
                    $records[] = $this->_record(
                        (int)$element->id,
                        (string)$element->title,
                        $sources->readFieldValue($element, $differentiatorHandle),
                        $source,
                        $matcher,
                    );
                }
                return $records;
            },
        );
    }

    /**
     * @return array{id: int, title: string, normalized: string, differentiator: ?string, sourceUid: string}
     */
    private function _record(int $id, string $title, ?string $differentiator, Source $source, TitleMatcher $matcher): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'normalized' => $matcher->normalize($title),
            'differentiator' => $differentiator,
            'sourceUid' => $source->uid,
        ];
    }

    /**
     * Turns clusters of records into clusters of enriched items, loading only
     * the elements those clusters contain. Records whose element has gone are
     * dropped, along with any cluster left with fewer than two items.
     *
     * @param array<int, array<int, array<string, mixed>>> $clusters
     * @param array<string, Source> $sourcesByUid
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function _enrichClusters(array $clusters, array $sourcesByUid, int $siteId): array
    {
        $idsBySource = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster as $record) {
                $idsBySource[$record['sourceUid']][] = $record['id'];
            }
        }

        $items = [];
        foreach ($idsBySource as $uid => $ids) {
            $source = $sourcesByUid[$uid] ?? null;
            if ($source === null) {
                continue;
            }
            $elements = Plugin::$plugin->sources->baseQuery($source)
                ->siteId($siteId)
                ->id(array_values(array_unique($ids)))
                ->all();
            foreach ($elements as $element) {
                $items[$uid][$element->id] = $this->_buildItem($element, $source);
            }
        }

        $out = [];
        foreach ($clusters as $i => $cluster) {
            $built = [];
            foreach ($cluster as $record) {
                if (isset($items[$record['sourceUid']][$record['id']])) {
                    $built[] = $items[$record['sourceUid']][$record['id']];
                }
            }
            $out[$i] = $built;
        }

        return $out;
    }

    /**
     * Builds an enriched item dict for an element under a given source.
     *
     * @return array<string, mixed>
     */
    private function _buildItem(ElementInterface $element, Source $source): array
    {
        $sources = Plugin::$plugin->sources;
        $differentiatorHandle = $sources->getDifferentiatorHandle($source);
        $displayHandles = $sources->getDisplayHandles($source);

        $displayValues = [];
        foreach ($displayHandles as $handle) {
            $value = $sources->readFieldValue($element, $handle);
            if ($value !== null) {
                $displayValues[$handle] = $value;
            }
        }

        $differentiator = $differentiatorHandle !== null
            ? $sources->readFieldValue($element, $differentiatorHandle)
            : null;

        return [
            'id' => (int)$element->id,
            'title' => (string)$element->title,
            'siteId' => (int)$element->siteId,
            'cpEditUrl' => $element->getCpEditUrl(),
            'displayValues' => $displayValues,
            'differentiator' => $differentiator,
            'differentiatorHandle' => $differentiatorHandle,
            'sourceUid' => $source->uid,
            'sourceId' => $source->id,
            'sourceName' => $source->name,
            'sourceType' => $source->type,
            'sourceWritable' => $source->isWritable(),
            'sourceCpPath' => $source->cpPath(),
        ];
    }

    /**
     * Greedy single-pass clustering. Items in the same cluster have matching
     * titles (per the active strategy) AND compatible differentiator values
     * (same value, or at least one side missing — which is treated as "could
     * match, surface for review").
     *
     * Each unassigned item, in order, seeds a cluster and takes every later
     * unassigned item that matches it. Candidates come from an index rather
     * than a scan of every later item, but the result is the same as comparing
     * every pair.
     *
     * @param array<int, array<string, mixed>> $records
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function _clusterRecords(array $records, int $threshold): array
    {
        if (count($records) < 2) {
            return [];
        }

        $matcher = $this->_matcher();
        $strict = $matcher->getStrategy() === TitleMatcher::STRATEGY_STRICT;
        $candidates = $strict
            ? $this->_strictCandidates($records)
            : $this->_fuzzyCandidates($records, $threshold);

        $clusters = [];
        $assigned = [];

        foreach ($records as $i => $seed) {
            if (isset($assigned[$i])) {
                continue;
            }
            $cluster = [$seed];
            $assigned[$i] = true;

            foreach ($candidates($i) as $j) {
                if ($j <= $i || isset($assigned[$j])) {
                    continue;
                }
                // A strict candidate is a match by construction; a fuzzy one
                // only shares an indexed segment and still needs measuring.
                if (!$strict && $matcher->compareFuzzyNormalized($seed['normalized'], $records[$j]['normalized'], $threshold) === null) {
                    continue;
                }
                if (!$this->_differentiatorCompatible($seed['differentiator'], $records[$j]['differentiator'])) {
                    continue;
                }
                $cluster[] = $records[$j];
                $assigned[$j] = true;
            }

            if (count($cluster) > 1) {
                $clusters[] = $cluster;
            }
        }

        return $clusters;
    }

    /**
     * Strict matching is an equivalence on {@see TitleMatcher::strictKey()}, so
     * an item's candidates are exactly the items sharing its key.
     *
     * @param array<int, array<string, mixed>> $records
     * @return callable(int): int[]
     */
    private function _strictCandidates(array $records): callable
    {
        $matcher = $this->_matcher();
        $keys = [];
        $buckets = [];
        foreach ($records as $i => $record) {
            $keys[$i] = $matcher->strictKey($record['title']);
            $buckets[$keys[$i]][] = $i;
        }

        return fn(int $i): array => $buckets[$keys[$i]];
    }

    /**
     * Candidate lookup for the fuzzy strategy, by the pigeonhole principle.
     *
     * Split a title of length L ≥ t+1 into t+1 segments. An edit touches at most
     * one segment, so any title within distance t of it contains at least one
     * of those segments unchanged, shifted by at most t positions. Indexing each
     * title's segments and probing every title's substrings at those positions
     * therefore finds every pair within distance t, plus some that aren't,
     * which the caller measures.
     *
     * Titles shorter than t+1 can't be split. They can only match titles of
     * length 2t or less, which are compared directly.
     *
     * Lengths and offsets are in bytes, as levenshtein() measures them.
     *
     * @param array<int, array<string, mixed>> $records
     * @return callable(int): int[]
     */
    private function _fuzzyCandidates(array $records, int $threshold): callable
    {
        $t = max(0, $threshold);
        $lengths = [];
        $index = [];
        $tiny = [];
        $short = [];

        foreach ($records as $i => $record) {
            $length = strlen($record['normalized']);
            $lengths[$i] = $length;

            if ($length <= 2 * $t) {
                $short[] = $i;
            }
            if ($length <= $t) {
                $tiny[] = $i;
                continue;
            }
            foreach ($this->_segments($length, $t) as $s => [$offset, $segmentLength]) {
                $index["$length:$s:" . substr($record['normalized'], $offset, $segmentLength)][] = $i;
            }
        }

        return function(int $i) use ($records, $lengths, $index, $tiny, $short, $t): array {
            $normalized = $records[$i]['normalized'];
            $length = $lengths[$i];
            $found = [];

            if ($length > $t) {
                for ($target = max($t + 1, $length - $t); $target <= $length + $t; $target++) {
                    foreach ($this->_segments($target, $t) as $s => [$offset, $segmentLength]) {
                        $from = max(0, $offset - $t);
                        $to = min($length - $segmentLength, $offset + $t);
                        for ($start = $from; $start <= $to; $start++) {
                            $key = "$target:$s:" . substr($normalized, $start, $segmentLength);
                            foreach ($index[$key] ?? [] as $j) {
                                $found[$j] = true;
                            }
                        }
                    }
                }
            }

            if ($length <= 2 * $t) {
                foreach ($length <= $t ? $short : $tiny as $j) {
                    $found[$j] = true;
                }
            }

            $candidates = array_keys($found);
            sort($candidates);
            return $candidates;
        };
    }

    /**
     * Splits a length into t+1 near-equal segments.
     *
     * @return array<int, array{0: int, 1: int}> [offset, length] per segment
     */
    private function _segments(int $length, int $t): array
    {
        static $memo = [];
        $key = "$length:$t";
        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $parts = $t + 1;
        $base = intdiv($length, $parts);
        $longer = $length % $parts;
        $segments = [];
        $offset = 0;
        for ($s = 0; $s < $parts; $s++) {
            $segmentLength = $base + ($s >= $parts - $longer ? 1 : 0);
            $segments[] = [$offset, $segmentLength];
            $offset += $segmentLength;
        }

        return $memo[$key] = $segments;
    }

    /**
     * Two items are differentiator-compatible if their values are equal, or if
     * at least one side has no differentiator value. We treat missing values as
     * "unknown" rather than "definitely different" so legitimate near-duplicates
     * still surface for human review when one side hasn't been classified yet.
     */
    private function _differentiatorCompatible(?string $a, ?string $b): bool
    {
        if ($a === null || $a === '' || $b === null || $b === '') {
            return true;
        }
        return mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }

    /**
     * Caches a scan result until an element of one of the sources' types is
     * saved or deleted — the same tags Craft's own element query caches use.
     *
     * @param array<int, mixed> $key
     * @param Source[] $sources
     */
    private function _cached(array $key, array $sources, callable $build): mixed
    {
        $tags = [];
        foreach ($sources as $source) {
            $type = $source->type === Source::TYPE_TAG ? \craft\elements\Tag::class : \craft\elements\Entry::class;
            $tags["element::$type"] = true;
            $tags["element::$type::*"] = true;
        }

        return Craft::$app->getCache()->getOrSet(
            'tidytags:' . md5(serialize($key)),
            $build,
            $this->cacheDuration,
            new TagDependency(['tags' => array_keys($tags)]),
        );
    }

    /**
     * Everything about the active match settings that changes which titles
     * match, for use in cache keys.
     */
    private function _matchFingerprint(int $threshold): array
    {
        $matcher = $this->_matcher();
        if ($matcher->getStrategy() === TitleMatcher::STRATEGY_FUZZY) {
            return [TitleMatcher::STRATEGY_FUZZY, $threshold];
        }

        $settings = Plugin::$plugin->getSettings();
        return [TitleMatcher::STRATEGY_STRICT, $settings->getAffixTokens(), $settings->getQualifierTokens()];
    }

    /**
     * @param Source[] $sources
     * @return array<string, ?string>
     */
    private function _differentiatorFingerprint(array $sources): array
    {
        $handles = [];
        foreach ($sources as $source) {
            $handles[$source->uid] = Plugin::$plugin->sources->getDifferentiatorHandle($source);
        }
        return $handles;
    }

    /**
     * @param Source[] $sources
     * @return array<string, Source>
     */
    private function _byUid(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            $out[$source->uid] = $source;
        }
        return $out;
    }

    private function _siteId(?int $siteId): int
    {
        return $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
    }

    private function _matcher(): TitleMatcher
    {
        return Plugin::$plugin->titleMatcher;
    }
}
