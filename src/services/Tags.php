<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Tag;
use craft\elements\User;
use Throwable;

/**
 * Tag mutations (rename, delete, merge) and the element-type-agnostic
 * relation-swap primitive shared by merge and the cross-source swap action.
 *
 * - Rename, delete, merge are intentionally tag-only: the `Tag::find()->id()`
 *   scoping enforces that on the server side, so an entry ID posted to these
 *   actions simply won't resolve.
 * - {@see swapRelations()} works across any element types — it only touches
 *   the {{%relations}} table and never deletes anything, so it's safe to use
 *   for cross-source cleanups (e.g. re-pointing entries that mistakenly
 *   reference a duplicate Tag onto the canonical Team or Competition entry).
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
        $renamed = false;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $tag = Tag::find()->id($tagId)->siteId($site->id)->status(null)->one();
            if ($tag === null) {
                continue;
            }
            $renamed = true;
            $tag->title = $newTitle;
            if (!Craft::$app->getElements()->saveElement($tag, true, true)) {
                $ok = false;
            }
        }

        // Report failure when the ID resolved to nothing at all, rather than
        // letting the CP announce "Tag renamed." after a no-op.
        return $renamed && $ok;
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
     * Re-points every relation from the sources to the target, then deletes
     * the source tag elements. Tag-only — for cross-type or entry-involved
     * cleanups, use {@see swapRelations()} which leaves the source elements
     * in place.
     *
     * @param int[] $sourceIds
     */
    public function mergeTags(array $sourceIds, int $targetId): bool
    {
        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
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
            $affectedTypes = $this->_repointRelations($sourceIds, $targetId);

            foreach ($sourceIds as $id) {
                $tag = Tag::find()->id($id)->status(null)->one();
                if ($tag !== null) {
                    Craft::$app->getElements()->deleteElement($tag, true);
                }
            }

            $transaction->commit();
            $this->_invalidateCaches($affectedTypes, $target);
            return true;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error('Tidy Tags merge failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Re-points every relation from the given source elements to the target
     * element. Works for any element types — Tag → Tag, Entry → Entry, or
     * cross-type — because the relations table only stores element IDs.
     *
     * Source elements are NOT deleted; this is the safer primitive to expose
     * across element types where automatic deletion (especially of entries
     * with URLs, drafts, and revisions) would be inappropriate.
     *
     * @param int[] $sourceIds
     */
    public function swapRelations(array $sourceIds, int $targetId): bool
    {
        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
        if (empty($sourceIds)) {
            return false;
        }

        $target = Craft::$app->getElements()->getElementById($targetId);
        if ($target === null) {
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $affectedTypes = $this->_repointRelations($sourceIds, $targetId);
            $transaction->commit();
            $this->_invalidateCaches($affectedTypes, $target);
            return true;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error('Tidy Tags swap failed: ' . $e->getMessage(), __METHOD__);
            return false;
        }
    }

    /**
     * Whether $user may save every element whose relations a merge or swap of
     * $sourceIds would rewrite — the entries, assets, users and so on that
     * hold a relational field pointing at one of the sources.
     *
     * Re-pointing a relation edits the element that owns it, so this is the
     * check that stops a merge or swap from changing content in a section the
     * user can't edit. Revisions are checked against their canonical element,
     * since nobody can save a revision directly.
     *
     * @param int[] $sourceIds
     */
    public function canRepointRelations(array $sourceIds, User $user): bool
    {
        $sourceIds = array_values(array_filter(array_map(fn($id) => (int)$id, $sourceIds)));
        if (empty($sourceIds)) {
            return true;
        }

        $rows = (new Query())
            ->select(['r.sourceId', 'r.sourceSiteId', 'e.type'])
            ->distinct()
            ->from(['r' => '{{%relations}}'])
            ->innerJoin(['e' => '{{%elements}}'], '[[e.id]] = [[r.sourceId]]')
            ->where(['r.targetId' => $sourceIds])
            ->all();

        // Group owner IDs by element type and site so each group loads in one
        // query. Relations from non-localized fields carry no site; any site
        // the owner exists in will do for an authorization check.
        $groups = [];
        foreach ($rows as $row) {
            $site = $row['sourceSiteId'] !== null ? (int)$row['sourceSiteId'] : '*';
            $groups[$row['type']][$site][] = (int)$row['sourceId'];
        }

        $elementsService = Craft::$app->getElements();

        foreach ($groups as $type => $bySite) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                return false;
            }
            foreach ($bySite as $site => $ids) {
                foreach (array_chunk(array_unique($ids), 100) as $chunk) {
                    $query = $type::find()
                        ->id($chunk)
                        ->status(null)
                        ->drafts(null)
                        ->provisionalDrafts(null)
                        ->revisions(null);
                    $site === '*' ? $query->site('*')->unique() : $query->siteId($site);

                    foreach ($query->all() as $owner) {
                        $allowed = $owner->getIsRevision()
                            ? $elementsService->canSaveCanonical($owner, $user)
                            : $elementsService->canSave($owner, $user);
                        if (!$allowed) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Coerces, dedupes, and removes the target ID from a list of source IDs.
     *
     * @param int[] $sourceIds
     * @return int[]
     */
    private function _normalizeSourceIds(array $sourceIds, int $targetId): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn($id) => (int)$id, $sourceIds),
            fn($id) => $id !== 0 && $id !== $targetId
        )));
    }

    /**
     * Re-points every {{%relations}} row whose `targetId` is in $sourceIds to
     * point at $targetId instead, deleting any that would become duplicates of
     * an existing target relation.
     *
     * Returns the element types whose cached output may now be stale: the
     * owners of every rewritten relation, plus the sources themselves.
     *
     * @param int[] $sourceIds
     * @return string[]
     */
    private function _repointRelations(array $sourceIds, int $targetId): array
    {
        $db = Craft::$app->getDb();

        $relations = (new Query())
            ->select(['id', 'fieldId', 'sourceId', 'sourceSiteId', 'targetId'])
            ->from(['{{%relations}}'])
            ->where(['targetId' => $sourceIds])
            ->all();

        $affectedIds = array_merge($sourceIds, array_map(fn($r) => (int)$r['sourceId'], $relations));
        $affectedTypes = (new Query())
            ->select(['type'])
            ->distinct()
            ->from(['{{%elements}}'])
            ->where(['id' => array_values(array_unique($affectedIds))])
            ->column();

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

        return $affectedTypes;
    }

    /**
     * Clears template and element caches after relations were rewritten with
     * raw SQL, which bypasses the element saves that would normally do it.
     *
     * @param string[] $elementTypes
     */
    private function _invalidateCaches(array $elementTypes, ElementInterface $target): void
    {
        $elementsService = Craft::$app->getElements();
        foreach ($elementTypes as $type) {
            $elementsService->invalidateCachesForElementType($type);
        }
        $elementsService->invalidateCachesForElement($target);
    }
}
