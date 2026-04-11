<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\elements\Tag;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;

/**
 * Near-duplicate detection across every Tidy Tags source.
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
     * @return array<int, array<int, array{id: int, title: string, siteId: int}>>
     */
    public function findDuplicates(Source $source, ?int $siteId = null, ?int $threshold = null): array
    {
        $threshold = $threshold ?? $this->defaultThreshold;

        $query = Plugin::$plugin->sources->baseQuery($source);

        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $query->siteId(Craft::$app->getSites()->getPrimarySite()->id);
        }

        $elements = $query->all();

        $items = [];
        foreach ($elements as $element) {
            $items[] = [
                'id' => (int)$element->id,
                'title' => (string)$element->title,
                'siteId' => (int)$element->siteId,
                'normalized' => $this->_normalize((string)$element->title),
            ];
        }

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
                if ($this->_isSimilar($items[$i]['normalized'], $items[$j]['normalized'], $threshold)) {
                    $cluster[] = $items[$j];
                    $assigned[$j] = true;
                }
            }

            if (count($cluster) > 1) {
                $clusters[] = array_map(fn($c) => [
                    'id' => $c['id'],
                    'title' => $c['title'],
                    'siteId' => $c['siteId'],
                ], $cluster);
            }
        }

        return $clusters;
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
     * Finds tags similar to a given title. Used by the "did you mean" AJAX
     * endpoint — deliberately tag-only: the editor-side warning JS targets
     * Craft Tags fields only, and we don't want to surface entry suggestions
     * in UI where they'd be confusing or destructive to pick.
     *
     * @return array<int, array{id: int, title: string, distance: int}>
     */
    public function findSimilar(
        string $title,
        ?int $groupId = null,
        ?int $siteId = null,
        ?int $threshold = null,
        int $limit = 5,
    ): array {
        $threshold = $threshold ?? $this->defaultThreshold;
        $title = trim($title);
        if ($title === '') {
            return [];
        }

        $query = Tag::find()->status(null);
        if ($groupId !== null) {
            $query->groupId($groupId);
        }
        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $query->siteId(Craft::$app->getSites()->getPrimarySite()->id);
        }

        $normalized = $this->_normalize($title);

        $matches = [];
        foreach ($query->all() as $tag) {
            $tagNormalized = $this->_normalize((string)$tag->title);
            if ($tagNormalized === $normalized) {
                continue;
            }
            $distance = levenshtein($normalized, $tagNormalized);
            if ($distance <= $threshold) {
                $matches[] = [
                    'id' => (int)$tag->id,
                    'title' => (string)$tag->title,
                    'distance' => $distance,
                ];
            }
        }

        usort($matches, fn($a, $b) => $a['distance'] <=> $b['distance']);
        return array_slice($matches, 0, $limit);
    }

    private function _normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    private function _isSimilar(string $a, string $b, int $threshold): bool
    {
        if ($a === $b) {
            return true;
        }
        if (abs(strlen($a) - strlen($b)) > $threshold) {
            return false;
        }
        return levenshtein($a, $b) <= $threshold;
    }
}
