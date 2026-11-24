<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\elements\User;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use Throwable;

/**
 * Item mutations (rename, delete, merge) and the element-type-agnostic
 * relation-swap primitive shared by merge and the cross-source swap action.
 *
 * - Rename, delete and merge act on "writable items": tags in any tag group,
 *   and canonical entries in tag-like sections an admin has opted into
 *   `writableSectionUids`. {@see getWritableItem()} enforces that on the server
 *   side, so an ID from anywhere else simply won't resolve.
 * - Entries are soft-deleted (they go to the trash and can be restored); tags
 *   are deleted outright, as they always have been.
 * - {@see swapRelations()} works across any element types — it only touches
 *   the {{%relations}} table and never deletes anything, so it's safe to use
 *   for cross-source cleanups (e.g. re-pointing entries that mistakenly
 *   reference a duplicate Tag onto the canonical Team or Competition entry).
 */
class Tags extends Component
{
    /**
     * Loads an item Tidy Tags may change: a tag, or a canonical entry in a
     * writable tag-like section. Drafts, revisions, trashed elements and
     * elements of any other type resolve to null.
     *
     * With no $siteId the item is loaded in the primary site if it exists
     * there, otherwise in any site it does exist in.
     */
    public function getWritableItem(int $id, ?int $siteId = null): ?ElementInterface
    {
        if ($id <= 0) {
            return null;
        }

        $type = (new Query())
            ->select(['type'])
            ->from(['{{%elements}}'])
            ->where(['id' => $id])
            ->scalar();

        $query = match ($type) {
            Tag::class => Tag::find(),
            Entry::class => Entry::find(),
            default => null,
        };
        if ($query === null) {
            return null;
        }

        $query->id($id)->status(null);
        if ($siteId !== null) {
            $query->siteId($siteId);
        } else {
            $query->site('*')->unique()->preferSites([Craft::$app->getSites()->getPrimarySite()->id]);
        }

        $element = $query->one();
        if ($element === null) {
            return null;
        }

        $source = Plugin::$plugin->sources->getSourceForElement($element);
        return $source !== null && $source->isWritable() ? $element : null;
    }

    /**
     * Renames an item.
     *
     * If $siteId is null, renames across every site; otherwise renames only
     * within the given site. Entries whose type generates its titles (no title
     * field) can't be renamed, since the next save would overwrite the title.
     */
    public function rename(int $id, string $newTitle, ?int $siteId = null): bool
    {
        $newTitle = trim($newTitle);
        if ($newTitle === '') {
            return false;
        }

        $elementsService = Craft::$app->getElements();

        if ($siteId !== null) {
            $item = $this->getWritableItem($id, $siteId);
            if ($item === null || !$this->_hasEditableTitle($item)) {
                return false;
            }
            $item->title = $newTitle;
            return $elementsService->saveElement($item, true, false);
        }

        $ok = true;
        $renamed = false;
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $item = $this->getWritableItem($id, $site->id);
            if ($item === null) {
                continue;
            }
            if (!$this->_hasEditableTitle($item)) {
                return false;
            }
            $renamed = true;
            $item->title = $newTitle;
            if (!$elementsService->saveElement($item, true, true)) {
                $ok = false;
            }
        }

        // Report failure when the ID resolved to nothing at all, rather than
        // letting the CP announce "Renamed." after a no-op.
        return $renamed && $ok;
    }

    /**
     * Deletes an item from every site. Entries go to the trash; tags are
     * deleted outright.
     */
    public function delete(int $id): bool
    {
        $item = $this->getWritableItem($id);
        if ($item === null) {
            return false;
        }
        return $this->_deleteItem($item);
    }

    /**
     * Deletes a batch of items and returns how many were removed.
     *
     * @param int[] $ids
     */
    public function deleteMany(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->delete((int)$id)) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Merges source items into a target item in the same source.
     *
     * Re-points every relation from the sources to the target, then deletes
     * the sources: tags outright, entries to the trash. Refuses to run when
     * {@see mergeProblems()} reports anything.
     *
     * The result lists every URL the merge retires — one row per source entry
     * per site it has a URI in — with the target's URL in that site, so the
     * site can set up redirects. Tags have no URLs, so a tag merge's list is
     * empty.
     *
     * @param int[] $sourceIds
     * @return array{success: bool, urls: array<int, array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>}
     */
    public function merge(array $sourceIds, int $targetId): array
    {
        $failed = ['success' => false, 'urls' => []];

        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
        if (empty($sourceIds) || $this->mergeProblems($sourceIds, $targetId) !== []) {
            return $failed;
        }

        $target = $this->getWritableItem($targetId);
        if ($target === null) {
            return $failed;
        }

        $urls = $this->urlReport($sourceIds, $targetId);

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $affectedTypes = $this->_repointRelations($sourceIds, $targetId);

            foreach ($sourceIds as $id) {
                $item = $this->getWritableItem($id);
                if ($item !== null && !$this->_deleteItem($item)) {
                    throw new \RuntimeException("Couldn't delete #{$id}.");
                }
            }

            $transaction->commit();
            $this->_invalidateCaches($affectedTypes, $target);
            return ['success' => true, 'urls' => $urls];
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error('Tidy Tags merge failed: ' . $e->getMessage(), __METHOD__);
            return $failed;
        }
    }

    /**
     * Re-points every relation from the given source elements to the target
     * element. Works for any element types — Tag → Tag, Entry → Entry, or
     * cross-type — because the relations table only stores element IDs.
     *
     * Source elements are NOT deleted; this is the safer primitive to expose
     * across element types and for read-only sources.
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
     * Reasons a merge of $sourceIds into $targetId can't run, or an empty array
     * when it can.
     *
     * - Every item must be writable ({@see getWritableItem()}).
     * - Merge stays inside one source: a relational field is limited to its
     *   sources, so moving relations onto an item from another tag group or
     *   section would leave the field pointing outside them.
     * - The target must exist in every site the sources exist in, and in every
     *   site a relation being moved belongs to. Otherwise those relations would
     *   point at an item that isn't there. Tags exist in every site, so this
     *   only ever stops entry merges.
     *
     * The control panel only offers merges that pass these checks, the merge
     * itself re-checks them, and the console command reports them.
     *
     * @param int[] $sourceIds
     * @return string[]
     */
    public function mergeProblems(array $sourceIds, int $targetId): array
    {
        $target = $this->getWritableItem($targetId);
        if ($target === null) {
            return ["#{$targetId} is not a tag or an entry in a writable section."];
        }
        $targetSource = Plugin::$plugin->sources->getSourceForElement($target);

        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
        if (empty($sourceIds)) {
            return ['There is nothing to merge into #' . $targetId . '.'];
        }

        $problems = [];
        $items = [];
        foreach ($sourceIds as $id) {
            $item = $this->getWritableItem($id);
            if ($item === null) {
                $problems[] = "#{$id} is not a tag or an entry in a writable section.";
                continue;
            }
            $source = Plugin::$plugin->sources->getSourceForElement($item);
            if ($source === null || $source->type !== $targetSource->type || $source->id !== $targetSource->id) {
                $container = $targetSource->type === Source::TYPE_TAG ? 'tag group' : 'section';
                $problems[] = "#{$id} “{$item->title}” is in a different {$container} from #{$targetId} “{$target->title}”.";
                continue;
            }
            $items[$id] = $item;
        }

        if ($problems !== []) {
            return $problems;
        }

        $sitesService = Craft::$app->getSites();
        $siteName = fn(int $siteId) => $sitesService->getSiteById($siteId, true)?->name ?? "site {$siteId}";
        $targetSites = $this->_siteIds($targetId);

        foreach ($items as $id => $item) {
            $missing = array_diff($this->_siteIds($id), $targetSites);
            if ($missing !== []) {
                $names = implode(', ', array_map($siteName, $missing));
                $problems[] = "#{$id} “{$item->title}” exists in {$names}, where #{$targetId} “{$target->title}” does not.";
            }
        }

        [, $toUpdate] = $this->_classifyRelations($sourceIds, $targetId);
        $orphaned = [];
        foreach ($toUpdate as $r) {
            if ($r['sourceSiteId'] !== null && !in_array((int)$r['sourceSiteId'], $targetSites, true)) {
                $orphaned[(int)$r['sourceSiteId']] = ($orphaned[(int)$r['sourceSiteId']] ?? 0) + 1;
            }
        }
        foreach ($orphaned as $siteId => $count) {
            $problems[] = "{$count} relation(s) in {$siteName($siteId)} would point at #{$targetId} “{$target->title}”, which doesn't exist in that site.";
        }

        return $problems;
    }

    /**
     * Describes what {@see merge()} would do without doing it: per source
     * item, how many relations would move to the target and how many would be
     * dropped as duplicates of a relation the target already has, plus the
     * URLs the merge would retire.
     *
     * @param int[] $sourceIds
     * @return array{
     *     target: array{id: int, title: string},
     *     sources: array<int, array{id: int, title: string, relations: int, moved: int, deduped: int}>,
     *     moved: int,
     *     deduped: int,
     *     urls: array<int, array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>,
     * }
     */
    public function previewMerge(array $sourceIds, int $targetId): array
    {
        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
        $target = $this->getWritableItem($targetId);

        $sources = [];
        foreach ($sourceIds as $id) {
            $sources[$id] = [
                'id' => $id,
                'title' => (string)($this->getWritableItem($id)?->title ?? ''),
                'relations' => 0,
                'moved' => 0,
                'deduped' => 0,
            ];
        }

        $moved = 0;
        $deduped = 0;
        if (!empty($sourceIds)) {
            [, $toUpdate, $toDelete] = $this->_classifyRelations($sourceIds, $targetId);
            foreach ($toUpdate as $r) {
                $sources[(int)$r['targetId']]['moved']++;
                $sources[(int)$r['targetId']]['relations']++;
                $moved++;
            }
            foreach ($toDelete as $r) {
                $sources[(int)$r['targetId']]['deduped']++;
                $sources[(int)$r['targetId']]['relations']++;
                $deduped++;
            }
        }

        return [
            'target' => ['id' => $targetId, 'title' => (string)($target?->title ?? '')],
            'sources' => array_values($sources),
            'moved' => $moved,
            'deduped' => $deduped,
            'urls' => empty($sourceIds) ? [] : $this->urlReport($sourceIds, $targetId),
        ];
    }

    /**
     * The URLs a merge of $sourceIds into $targetId would retire: one row per
     * source item per site where it has a URI, with the target's URL in that
     * site (null when the target has no URL there). Ordered by site, then by
     * source ID.
     *
     * @param int[] $sourceIds
     * @return array<int, array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>
     */
    public function urlReport(array $sourceIds, int $targetId): array
    {
        $sourceIds = $this->_normalizeSourceIds($sourceIds, $targetId);
        $rows = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $target = null;
            $targetLoaded = false;

            foreach ($sourceIds as $id) {
                $item = $this->getWritableItem($id, $site->id);
                $oldUrl = $item?->getUrl();
                if ($oldUrl === null) {
                    continue;
                }
                if (!$targetLoaded) {
                    $target = $this->getWritableItem($targetId, $site->id);
                    $targetLoaded = true;
                }
                $rows[] = [
                    'siteId' => (int)$site->id,
                    'siteHandle' => (string)$site->handle,
                    'sourceId' => $id,
                    'sourceTitle' => (string)$item->title,
                    'oldUrl' => $oldUrl,
                    'newUrl' => $target?->getUrl(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Deletes the given items, but only those that are still unused when the
     * delete runs — an item that gained a relation since it was listed is left
     * alone and reported back. IDs that aren't writable items are reported as
     * failed.
     *
     * @param int[] $ids
     * @return array{deleted: int[], skipped: int[], failed: int[]}
     */
    public function deleteUnused(array $ids): array
    {
        $result = ['deleted' => [], 'skipped' => [], 'failed' => []];
        $ids = array_values(array_unique(array_filter(array_map(fn($id) => (int)$id, $ids))));

        foreach ($ids as $id) {
            $used = (new Query())
                ->from(['{{%relations}}'])
                ->where(['targetId' => $id])
                ->exists();
            if ($used) {
                $result['skipped'][] = $id;
                continue;
            }
            if ($this->delete($id)) {
                $result['deleted'][] = $id;
            } else {
                $result['failed'][] = $id;
            }
        }

        return $result;
    }

    /**
     * Whether $user may rename item $id in $siteId, or in every site it exists
     * in when $siteId is null: they must be able to edit each of those sites
     * and save the item's copy in each.
     */
    public function canRename(int $id, ?int $siteId, User $user): bool
    {
        $sitesService = Craft::$app->getSites();
        $sites = $siteId !== null
            ? array_filter([$sitesService->getSiteById($siteId, true)])
            : $sitesService->getAllSites(true);
        $multiSite = Craft::$app->getIsMultiSite();
        $elementsService = Craft::$app->getElements();

        foreach ($sites as $site) {
            $item = $this->getWritableItem($id, (int)$site->id);
            if ($item === null) {
                continue;
            }
            if ($multiSite && !$user->can("editSite:{$site->uid}")) {
                return false;
            }
            if (!$elementsService->canSave($item, $user)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $user may merge $sourceIds into $targetId as far as the items
     * themselves go: save the target and delete every source. Relations owned
     * by other elements are checked separately by {@see canRepointRelations()}.
     *
     * @param int[] $sourceIds
     */
    public function canMergeItems(array $sourceIds, int $targetId, User $user): bool
    {
        $target = $this->getWritableItem($targetId);
        if ($target === null || !Craft::$app->getElements()->canSave($target, $user)) {
            return false;
        }
        return $this->canDeleteItems($this->_normalizeSourceIds($sourceIds, $targetId), $user);
    }

    /**
     * Whether $user may delete every one of $ids. IDs that aren't writable
     * items are ignored here; the delete itself skips them.
     *
     * @param int[] $ids
     */
    public function canDeleteItems(array $ids, User $user): bool
    {
        $elementsService = Craft::$app->getElements();
        foreach ($ids as $id) {
            $item = $this->getWritableItem((int)$id);
            if ($item !== null && !$elementsService->canDelete($item, $user)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @deprecated in 5.5.0. Use {@see rename()}.
     */
    public function renameTag(int $tagId, string $newTitle, ?int $siteId = null): bool
    {
        return $this->rename($tagId, $newTitle, $siteId);
    }

    /**
     * @deprecated in 5.5.0. Use {@see delete()}.
     */
    public function deleteTag(int $tagId): bool
    {
        return $this->delete($tagId);
    }

    /**
     * @deprecated in 5.5.0. Use {@see deleteMany()}.
     * @param int[] $tagIds
     */
    public function deleteTags(array $tagIds): int
    {
        return $this->deleteMany($tagIds);
    }

    /**
     * @deprecated in 5.5.0. Use {@see merge()}, which also returns the URLs the
     *             merge retired.
     * @param int[] $sourceIds
     */
    public function mergeTags(array $sourceIds, int $targetId): bool
    {
        return $this->merge($sourceIds, $targetId)['success'];
    }

    /**
     * @deprecated in 5.5.0. Use {@see deleteUnused()}.
     * @param int[] $tagIds
     * @return array{deleted: int[], skipped: int[], failed: int[]}
     */
    public function deleteUnusedTags(array $tagIds): array
    {
        return $this->deleteUnused($tagIds);
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
        [$relations, $toUpdate, $toDelete] = $this->_classifyRelations($sourceIds, $targetId);

        $affectedIds = array_merge($sourceIds, array_map(fn($r) => (int)$r['sourceId'], $relations));
        $affectedTypes = (new Query())
            ->select(['type'])
            ->distinct()
            ->from(['{{%elements}}'])
            ->where(['id' => array_values(array_unique($affectedIds))])
            ->column();

        if (!empty($toDelete)) {
            $db->createCommand()
                ->delete('{{%relations}}', ['id' => array_column($toDelete, 'id')])
                ->execute();
        }
        if (!empty($toUpdate)) {
            $db->createCommand()
                ->update('{{%relations}}', ['targetId' => $targetId], ['id' => array_column($toUpdate, 'id')])
                ->execute();
        }

        return $affectedTypes;
    }

    /**
     * Sorts every relation pointing at $sourceIds into the rows a re-point would
     * update and the rows it would delete because the owner already relates to
     * the target through the same field and site. Shared by the real re-point
     * and {@see previewMerge()}, so a dry run reports exactly what a merge does.
     *
     * @param int[] $sourceIds
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>, 2: array<int, array<string, mixed>>}
     *         [all relations, rows to update, rows to delete]
     */
    private function _classifyRelations(array $sourceIds, int $targetId): array
    {
        $relations = (new Query())
            ->select(['id', 'fieldId', 'sourceId', 'sourceSiteId', 'targetId'])
            ->from(['{{%relations}}'])
            ->where(['targetId' => $sourceIds])
            ->orderBy(['id' => SORT_ASC])
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
                $toDelete[] = $r;
            } else {
                $toUpdate[] = $r;
                $seen[$key] = true;
            }
        }

        return [$relations, $toUpdate, $toDelete];
    }

    /**
     * Deletes one writable item the way its source asks for: entries to the
     * trash, tags outright.
     */
    private function _deleteItem(ElementInterface $item): bool
    {
        $source = Plugin::$plugin->sources->getSourceForElement($item);
        $hardDelete = $source === null || !$source->softDeletes();
        return Craft::$app->getElements()->deleteElement($item, $hardDelete);
    }

    /**
     * Whether an item's title can be changed directly. Entry types without a
     * title field generate titles from a format, which would undo a rename.
     */
    private function _hasEditableTitle(ElementInterface $item): bool
    {
        return !$item instanceof Entry || $item->getType()->hasTitleField;
    }

    /**
     * IDs of every site an element exists in.
     *
     * @return int[]
     */
    private function _siteIds(int $elementId): array
    {
        return array_map('intval', (new Query())
            ->select(['siteId'])
            ->from(['{{%elements_sites}}'])
            ->where(['elementId' => $elementId])
            ->column());
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
