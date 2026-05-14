<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;

/**
 * Near-duplicate detection across every Tidy Tags source.
 *
 * Items returned by every public method are enriched with the source they came
 * from, the differentiator field value (if configured), and a key/value map of
 * display field values, so callers can render disambiguating context (e.g.
 * "England (Football)" vs "England (Cricket)") without re-querying.
 */
class DuplicateDetector extends Component
{
    /**
     * Maximum Levenshtein distance considered a near-duplicate.
     */
    public int $defaultThreshold = 2;

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
        $threshold = $threshold ?? $this->defaultThreshold;

        $query = Plugin::$plugin->sources->baseQuery($source);
        $query->siteId($siteId ?? Craft::$app->getSites()->getPrimarySite()->id);

        $items = [];
        foreach ($query->all() as $element) {
            $items[] = $this->_buildItem($element, $source);
        }

        return $this->_clusterItems($items, $threshold);
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
        $threshold = $threshold ?? $this->defaultThreshold;
        $sources = Plugin::$plugin->sources->getAllSources();
        $effectiveSiteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;

        $items = [];
        foreach ($sources as $source) {
            $query = Plugin::$plugin->sources->baseQuery($source)->siteId($effectiveSiteId);
            foreach ($query->all() as $element) {
                $items[] = $this->_buildItem($element, $source);
            }
        }

        $clusters = $this->_clusterItems($items, $threshold);

        return array_values(array_filter($clusters, function(array $cluster): bool {
            $sourceUids = [];
            foreach ($cluster as $item) {
                $sourceUids[$item['sourceUid']] = true;
            }
            return count($sourceUids) > 1;
        }));
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

        $effectiveSiteId = $siteId ?? Craft::$app->getSites()->getPrimarySite()->id;
        $normalized = $this->_normalize($title);

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
            $query = Plugin::$plugin->sources->baseQuery($source)->siteId($effectiveSiteId);
            foreach ($query->all() as $element) {
                $candidate = $this->_normalize((string)$element->title);
                if ($candidate === $normalized) {
                    $distance = 0;
                } else {
                    if (abs(strlen($candidate) - strlen($normalized)) > $threshold) {
                        continue;
                    }
                    $distance = levenshtein($normalized, $candidate);
                    if ($distance > $threshold) {
                        continue;
                    }
                }

                $item = $this->_buildItem($element, $source);
                $item['distance'] = $distance;
                $matches[] = $item;
            }
        }

        usort($matches, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return array_slice($matches, 0, $limit);
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
     * Greedy single-pass clustering. Items in the same cluster have similar
     * normalized titles AND compatible differentiator values (same value, or
     * at least one side missing — which is treated as "could match, surface
     * for review").
     *
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function _clusterItems(array $items, int $threshold): array
    {
        foreach ($items as &$item) {
            $item['_normalized'] = $this->_normalize($item['title']);
        }
        unset($item);

        $clusters = [];
        $assigned = [];
        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            if (isset($assigned[$i])) {
                continue;
            }
            $cluster = [$items[$i]];
            $assigned[$i] = true;

            for ($j = $i + 1; $j < $count; $j++) {
                if (isset($assigned[$j])) {
                    continue;
                }
                if (!$this->_titleSimilar($items[$i]['_normalized'], $items[$j]['_normalized'], $threshold)) {
                    continue;
                }
                if (!$this->_differentiatorCompatible($items[$i]['differentiator'], $items[$j]['differentiator'])) {
                    continue;
                }
                $cluster[] = $items[$j];
                $assigned[$j] = true;
            }

            if (count($cluster) > 1) {
                $clusters[] = array_map(function(array $c): array {
                    unset($c['_normalized']);
                    return $c;
                }, $cluster);
            }
        }

        return $clusters;
    }

    private function _titleSimilar(string $a, string $b, int $threshold): bool
    {
        if ($a === $b) {
            return true;
        }
        if (abs(strlen($a) - strlen($b)) > $threshold) {
            return false;
        }
        return levenshtein($a, $b) <= $threshold;
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

    private function _normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }
}
