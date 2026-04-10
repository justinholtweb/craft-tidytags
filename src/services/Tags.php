<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Tag;
use craft\models\Site;
use craft\models\TagGroup;
use Throwable;

/**
 * Tag management service for the Tidy Tags plugin.
 */
class Tags extends Component
{
    /**
     * Returns every tag group defined in the project.
     *
     * @return TagGroup[]
     */
    public function getAllGroups(): array
    {
        return Craft::$app->getTags()->getAllTagGroups();
    }

    /**
     * Returns a tag group by its ID, or null if it does not exist.
     */
    public function getGroupById(int $groupId): ?TagGroup
    {
        return Craft::$app->getTags()->getTagGroupById($groupId);
    }

    /**
     * Returns every site defined in the project.
     *
     * @return Site[]
     */
    public function getAllSites(): array
    {
        return Craft::$app->getSites()->getAllSites();
    }

    /**
     * Returns per-site tag counts for a group.
     *
     * @return array<int, int> siteId => count
     */
    public function getGroupCountsBySite(int $groupId): array
    {
        $counts = [];
        foreach ($this->getAllSites() as $site) {
            $counts[$site->id] = Tag::find()
                ->groupId($groupId)
                ->siteId($site->id)
                ->status(null)
                ->count();
        }
        return $counts;
    }

    /**
     * Returns the total distinct-element count for a group across all sites.
     */
    public function getGroupTotalCount(int $groupId): int
    {
        return (int)Tag::find()
            ->groupId($groupId)
            ->site('*')
            ->unique()
            ->status(null)
            ->count();
    }

    /**
     * Returns tags in a group.
     *
     * When $siteId is null, returns one row per element (primary site) plus
     * an array of per-site titles keyed by siteId. Otherwise returns tags
     * scoped to the given site.
     *
     * @return array<int, array{tag: Tag, titles: array<int, string>}>
     */
    public function getTagsInGroup(int $groupId, ?int $siteId = null, ?string $search = null): array
    {
        $sites = $this->getAllSites();

        if ($siteId !== null) {
            $query = Tag::find()
                ->groupId($groupId)
                ->siteId($siteId)
                ->status(null)
                ->orderBy(['title' => SORT_ASC]);

            if ($search !== null && $search !== '') {
                $query->search($search);
            }

            $result = [];
            foreach ($query->all() as $tag) {
                $result[] = [
                    'tag' => $tag,
                    'titles' => [$tag->siteId => $tag->title],
                ];
            }
            return $result;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();

        $query = Tag::find()
            ->groupId($groupId)
            ->siteId($primarySite->id)
            ->status(null)
            ->orderBy(['title' => SORT_ASC]);

        if ($search !== null && $search !== '') {
            $query->search($search);
        }

        $primaryTags = $query->all();
        $elementIds = array_map(fn($t) => $t->id, $primaryTags);

        $perSiteTitles = [];
        if (!empty($elementIds)) {
            foreach ($sites as $site) {
                $siteTags = Tag::find()
                    ->id($elementIds)
                    ->siteId($site->id)
                    ->status(null)
                    ->all();
                foreach ($siteTags as $st) {
                    $perSiteTitles[$st->id][$site->id] = $st->title;
                }
            }
        }

        $result = [];
        foreach ($primaryTags as $tag) {
            $result[] = [
                'tag' => $tag,
                'titles' => $perSiteTitles[$tag->id] ?? [$tag->siteId => $tag->title],
            ];
        }
        return $result;
    }

    /**
     * Renames a tag.
     *
     * If $siteId is null, renames across every site; otherwise renames only
     * within the given site.
     */
    public function renameTag(int $tagId, string $newTitle, ?int $siteId = null): bool
    {
        $newTitle = trim($newTitle);
        if ($newTitle === '') {
            return false;
        }

        if ($siteId !== null) {
            $tag = Tag::find()->id($tagId)->siteId($siteId)->status(null)->one();
            if ($tag === null) {
                return false;
            }
            $tag->title = $newTitle;
            return Craft::$app->getElements()->saveElement($tag, true, false);
        }

        $ok = true;
        foreach ($this->getAllSites() as $site) {
            $tag = Tag::find()->id($tagId)->siteId($site->id)->status(null)->one();
            if ($tag === null) {
                continue;
            }
            $tag->title = $newTitle;
            if (!Craft::$app->getElements()->saveElement($tag, true, true)) {
                $ok = false;
            }
        }
        return $ok;
    }

    /**
     * Deletes a tag entirely (all sites).
     */
    public function deleteTag(int $tagId): bool
    {
        $tag = Tag::find()->id($tagId)->status(null)->one();
        if ($tag === null) {
            return false;
        }
        return Craft::$app->getElements()->deleteElement($tag, true);
    }

    /**
     * Deletes a batch of tags and returns how many were removed.
     *
     * @param int[] $tagIds
     */
    public function deleteTags(array $tagIds): int
    {
        $count = 0;
        foreach ($tagIds as $id) {
            if ($this->deleteTag((int)$id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Merges source tags into a target tag.
     *
     * Re-points every relation from the sources to the target, skipping rows
     * that would create duplicates, then deletes the source tag elements.
     *
     * @param int[] $sourceIds
     */
    public function mergeTags(array $sourceIds, int $targetId): bool
    {
        $sourceIds = array_values(array_filter(
            array_map(fn($id) => (int)$id, $sourceIds),
            fn($id) => $id !== 0 && $id !== $targetId
        ));

        if (empty($sourceIds)) {
            return false;
        }

        $target = Tag::find()->id($targetId)->status(null)->one();
        if ($target === null) {
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $relations = (new Query())
                ->select(['id', 'fieldId', 'sourceId', 'sourceSiteId', 'targetId'])
                ->from(['{{%relations}}'])
                ->where(['targetId' => $sourceIds])
                ->all();

            $existing = (new Query())
                ->select(['fieldId', 'sourceId', 'sourceSiteId'])
                ->from(['{{%relations}}'])
                ->where(['targetId' => $targetId])
                ->all();

            $seen = [];
            foreach ($existing as $r) {
                $key = $r['fieldId'] . '|' . $r['sourceId'] . '|' . ($r['sourceSiteId'] ?? 'null');
                $seen[$key] = true;
            }

            $toDelete = [];
            $toUpdate = [];
            foreach ($relations as $r) {
                $key = $r['fieldId'] . '|' . $r['sourceId'] . '|' . ($r['sourceSiteId'] ?? 'null');
                if (isset($seen[$key])) {
                    $toDelete[] = $r['id'];
                } else {
                    $toUpdate[] = $r['id'];
                    $seen[$key] = true;
                }
            }

            if (!empty($toDelete)) {
                $db->createCommand()
                    ->delete('{{%relations}}', ['id' => $toDelete])
                    ->execute();
            }
            if (!empty($toUpdate)) {
                $db->createCommand()
                    ->update('{{%relations}}', ['targetId' => $targetId], ['id' => $toUpdate])
                    ->execute();
            }

            foreach ($sourceIds as $id) {
                $tag = Tag::find()->id($id)->status(null)->one();
                if ($tag !== null) {
                    Craft::$app->getElements()->deleteElement($tag, true);
                }
            }

            $transaction->commit();
            return true;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error('Tidy Tags merge failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }
}
