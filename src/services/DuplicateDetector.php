<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\elements\Tag;
use craft\models\TagGroup;

/**
 * Near-duplicate tag detection for the Tidy Tags plugin.
 */
class DuplicateDetector extends Component
{
    /**
     * Maximum Levenshtein distance considered a near-duplicate.
     */
    public int $defaultThreshold = 2;

    /**
     * Finds clusters of near-duplicate tags within a group.
     *
     * @return array<int, array<int, array{id: int, title: string, siteId: int}>>
     */
    public function findDuplicates(int $groupId, ?int $siteId = null, ?int $threshold = null): array
    {
        $threshold = $threshold ?? $this->defaultThreshold;

        $query = Tag::find()
            ->groupId($groupId)
            ->status(null);

        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $primary = Craft::$app->getSites()->getPrimarySite();
            $query->siteId($primary->id);
        }

        $tags = $query->all();

        $items = [];
        foreach ($tags as $tag) {
            $items[] = [
                'id' => (int)$tag->id,
                'title' => (string)$tag->title,
                'siteId' => (int)$tag->siteId,
                'normalized' => $this->_normalize((string)$tag->title),
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
     * Finds all near-duplicate clusters across every tag group.
     *
     * @return array<int, array{group: TagGroup, clusters: array}>
     */
    public function findAllDuplicates(?int $siteId = null, ?int $threshold = null): array
    {
        $out = [];
        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $clusters = $this->findDuplicates($group->id, $siteId, $threshold);
            if (!empty($clusters)) {
                $out[] = ['group' => $group, 'clusters' => $clusters];
            }
        }
        return $out;
    }

    /**
     * Finds tags similar to a given title. Used by the "did you mean" AJAX endpoint.
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

    /**
     * Normalizes a tag title for similarity comparison.
     */
    private function _normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        return $s;
    }

    /**
     * Returns whether two normalized strings are within the given Levenshtein distance.
     */
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
