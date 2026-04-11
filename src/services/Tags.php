<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\db\Query;
use craft\elements\Tag;
use Throwable;

/**
 * Tag element mutations — rename, delete, merge.
 *
 * Reads have moved to the Sources service, which unifies native tag groups
 * with tag-like entry sections. These mutations are intentionally tag-only:
 * entry-backed sources are read-only in Tidy Tags because entries carry URLs,
 * bodies, drafts, and authorship that can't be safely mutated through a
 * tag-sized interface. The `Tag::find()->id()` scoping enforces that on the
 * server side — an entry ID posted to these actions simply won't resolve.
 */
class Tags extends Component
{
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
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
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
