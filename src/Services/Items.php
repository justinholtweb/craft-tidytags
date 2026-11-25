<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\ElementCaches;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Item mutations (rename, delete, merge) and the relation swap they share with the cross-source
 * Swap action.
 *
 * - Rename, delete and merge act on writable items: canonical entries in tag-like sections that
 *   allow it. {@see getWritableItem()} enforces that server side, so an ID from anywhere else
 *   simply doesn't resolve.
 * - Deleted and merged entries go to the trash and can be restored.
 * - {@see swapRelations()} only touches the `relations` table and never deletes anything, so it
 *   works between read-only sources too.
 */
class Items
{
    public function __construct(private readonly Sources $sources) {}

    /**
     * A canonical entry in a writable tag-like section, or null. Drafts, revisions, trashed
     * entries and anything else resolve to null. With no $siteId the entry is loaded in the
     * primary site if it exists there, otherwise in any site it does exist in.
     */
    public function getWritableItem(int $id, ?int $siteId = null): ?Entry
    {
        if ($id <= 0) {
            return null;
        }

        $query = Entry::find()->id($id)->status(null);
        $siteId !== null
            ? $query->siteId($siteId)
            : $query->site('*')->unique()->preferSites([Sites::getPrimarySite()->id]);

        $entry = $query->one();
        if (! $entry instanceof Entry) {
            return null;
        }

        $source = $this->sources->getSourceForElement($entry);

        return $source !== null && $source->isWritable() ? $entry : null;
    }

    /**
     * Renames an item in one site, or in every site it exists in when $siteId is null. Entries
     * whose type generates its titles can't be renamed: the next save would undo it.
     */
    public function rename(int $id, string $newTitle, ?int $siteId = null): bool
    {
        $newTitle = trim($newTitle);
        if ($newTitle === '') {
            return false;
        }

        if ($siteId !== null) {
            $item = $this->getWritableItem($id, $siteId);
            if ($item === null || ! $item->getType()->hasTitleField) {
                return false;
            }
            $item->title = $newTitle;

            return Elements::saveElement($item, true, false);
        }

        $ok = true;
        $renamed = false;
        foreach (Sites::getAllSites() as $site) {
            $item = $this->getWritableItem($id, (int) $site->id);
            if ($item === null) {
                continue;
            }
            if (! $item->getType()->hasTitleField) {
                return false;
            }
            $renamed = true;
            $item->title = $newTitle;
            if (! Elements::saveElement($item, true, true)) {
                $ok = false;
            }
        }

        // Report failure when the ID resolved to nothing at all, rather than announcing
        // "Renamed." after a no-op.
        return $renamed && $ok;
    }

    /**
     * Moves an item to the trash, from every site.
     */
    public function delete(int $id): bool
    {
        $item = $this->getWritableItem($id);

        return $item !== null && Elements::deleteElement($item);
    }

    /**
     * @param  array<int|string>  $ids
     */
    public function deleteMany(array $ids): int
    {
        $count = 0;
        foreach ($ids as $id) {
            if ($this->delete((int) $id)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Merges source items into a target in the same section: re-points every relation from the
     * sources to the target, then moves the sources to the trash. Refuses to run when
     * {@see mergeProblems()} reports anything.
     *
     * The result lists every URL the merge retires — one row per source per site it has a URI
     * in — with the target's URL in that site, so the site can set up redirects.
     *
     * @param  array<int|string>  $sourceIds
     * @return array{success: bool, urls: list<array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>}
     */
    public function merge(array $sourceIds, int $targetId): array
    {
        $failed = ['success' => false, 'urls' => []];

        $sourceIds = $this->normalizeSourceIds($sourceIds, $targetId);
        if ($sourceIds === [] || $this->mergeProblems($sourceIds, $targetId) !== []) {
            return $failed;
        }

        $target = $this->getWritableItem($targetId);
        if ($target === null) {
            return $failed;
        }

        $urls = $this->urlReport($sourceIds, $targetId);

        DB::beginTransaction();

        try {
            $affectedTypes = $this->repointRelations($sourceIds, $targetId);

            foreach ($sourceIds as $id) {
                $item = $this->getWritableItem($id);
                if ($item !== null && ! Elements::deleteElement($item)) {
                    throw new RuntimeException("Couldn't delete #{$id}.");
                }
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Tidy Tags merge failed: '.$e->getMessage());

            return $failed;
        }

        $this->invalidateCaches($affectedTypes, $target);

        return ['success' => true, 'urls' => $urls];
    }

    /**
     * Re-points every relation from the source elements to the target. Works for any element
     * types, because the relations table only stores IDs. Never deletes anything.
     *
     * @param  array<int|string>  $sourceIds
     */
    public function swapRelations(array $sourceIds, int $targetId): bool
    {
        $sourceIds = $this->normalizeSourceIds($sourceIds, $targetId);
        if ($sourceIds === []) {
            return false;
        }

        $target = Elements::getElementById($targetId, null, '*');
        if ($target === null) {
            return false;
        }

        DB::beginTransaction();

        try {
            $affectedTypes = $this->repointRelations($sourceIds, $targetId);
            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Tidy Tags swap failed: '.$e->getMessage());

            return false;
        }

        $this->invalidateCaches($affectedTypes, $target);

        return true;
    }

    /**
     * Why a merge of $sourceIds into $targetId can't run, or an empty array when it can.
     *
     * - Every item must be writable ({@see getWritableItem()}).
     * - Merge stays inside one section: a relational field is limited to its sources, so moving
     *   relations onto an entry from another section could leave the field pointing outside them.
     * - The target must exist in every site the sources exist in, and in every site a relation
     *   being moved belongs to; otherwise those relations would point at an entry that isn't there.
     *
     * @param  array<int|string>  $sourceIds
     * @return list<string>
     */
    public function mergeProblems(array $sourceIds, int $targetId): array
    {
        $target = $this->getWritableItem($targetId);
        if ($target === null) {
            return ["#{$targetId} is not an entry in a writable tag-like section."];
        }

        $sourceIds = $this->normalizeSourceIds($sourceIds, $targetId);
        if ($sourceIds === []) {
            return ["There is nothing to merge into #{$targetId}."];
        }

        $problems = [];
        $items = [];
        foreach ($sourceIds as $id) {
            $item = $this->getWritableItem($id);
            if ($item === null) {
                $problems[] = "#{$id} is not an entry in a writable tag-like section.";

                continue;
            }
            if ((int) $item->sectionId !== (int) $target->sectionId) {
                $problems[] = "#{$id} “{$item->title}” is in a different section from #{$targetId} “{$target->title}”.";

                continue;
            }
            $items[$id] = $item;
        }

        if ($problems !== []) {
            return $problems;
        }

        $siteName = fn (int $siteId) => Sites::getSiteById($siteId, true)?->getName() ?? "site {$siteId}";
        $targetSites = $this->siteIds($targetId);

        foreach ($items as $id => $item) {
            $missing = array_diff($this->siteIds($id), $targetSites);
            if ($missing !== []) {
                $names = implode(', ', array_map($siteName, $missing));
                $problems[] = "#{$id} “{$item->title}” exists in {$names}, where #{$targetId} “{$target->title}” does not.";
            }
        }

        [, $toUpdate] = $this->classifyRelations($sourceIds, $targetId);
        $orphaned = [];
        foreach ($toUpdate as $r) {
            if ($r->sourceSiteId !== null && ! in_array((int) $r->sourceSiteId, $targetSites, true)) {
                $orphaned[(int) $r->sourceSiteId] = ($orphaned[(int) $r->sourceSiteId] ?? 0) + 1;
            }
        }
        foreach ($orphaned as $siteId => $count) {
            $problems[] = "{$count} relation(s) in {$siteName($siteId)} would point at #{$targetId} “{$target->title}”, which doesn't exist in that site.";
        }

        return $problems;
    }

    /**
     * What {@see merge()} would do: per source, how many relations would move and how many would
     * be dropped as duplicates of one the target already has, plus the URLs it would retire.
     *
     * @param  array<int|string>  $sourceIds
     * @return array{target: array{id: int, title: string}, sources: list<array{id: int, title: string, relations: int, moved: int, deduped: int}>, moved: int, deduped: int, urls: list<array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>}
     */
    public function previewMerge(array $sourceIds, int $targetId): array
    {
        $sourceIds = $this->normalizeSourceIds($sourceIds, $targetId);
        $target = $this->getWritableItem($targetId);

        $sources = [];
        foreach ($sourceIds as $id) {
            $sources[$id] = [
                'id' => $id,
                'title' => (string) ($this->getWritableItem($id)->title ?? ''),
                'relations' => 0,
                'moved' => 0,
                'deduped' => 0,
            ];
        }

        $moved = 0;
        $deduped = 0;
        if ($sourceIds !== []) {
            [, $toUpdate, $toDelete] = $this->classifyRelations($sourceIds, $targetId);
            foreach ($toUpdate as $r) {
                $sources[(int) $r->targetId]['moved']++;
                $sources[(int) $r->targetId]['relations']++;
                $moved++;
            }
            foreach ($toDelete as $r) {
                $sources[(int) $r->targetId]['deduped']++;
                $sources[(int) $r->targetId]['relations']++;
                $deduped++;
            }
        }

        return [
            'target' => ['id' => $targetId, 'title' => (string) ($target->title ?? '')],
            'sources' => array_values($sources),
            'moved' => $moved,
            'deduped' => $deduped,
            'urls' => $sourceIds === [] ? [] : $this->urlReport($sourceIds, $targetId),
        ];
    }

    /**
     * The URLs a merge would retire: one row per source per site where it has a URL, with the
     * target's URL in that site (null when it has none). Ordered by site, then source.
     *
     * @param  array<int|string>  $sourceIds
     * @return list<array{siteId: int, siteHandle: string, sourceId: int, sourceTitle: string, oldUrl: string, newUrl: ?string}>
     */
    public function urlReport(array $sourceIds, int $targetId): array
    {
        $sourceIds = $this->normalizeSourceIds($sourceIds, $targetId);
        $rows = [];

        foreach (Sites::getAllSites() as $site) {
            $target = null;
            $targetLoaded = false;

            foreach ($sourceIds as $id) {
                $item = $this->getWritableItem($id, (int) $site->id);
                $oldUrl = $item?->getUrl();
                if ($item === null || $oldUrl === null) {
                    continue;
                }
                if (! $targetLoaded) {
                    $target = $this->getWritableItem($targetId, (int) $site->id);
                    $targetLoaded = true;
                }
                $rows[] = [
                    'siteId' => (int) $site->id,
                    'siteHandle' => (string) $site->handle,
                    'sourceId' => $id,
                    'sourceTitle' => (string) $item->title,
                    'oldUrl' => $oldUrl,
                    'newUrl' => $target?->getUrl(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Trashes the given items, but only those still unused when the delete runs — one that
     * gained a relation since it was listed is kept and reported. IDs that aren't writable items
     * are reported as failed.
     *
     * @param  array<int|string>  $ids
     * @return array{deleted: list<int>, skipped: list<int>, failed: list<int>}
     */
    public function deleteUnused(array $ids): array
    {
        $result = ['deleted' => [], 'skipped' => [], 'failed' => []];
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => (int) $id, $ids))));

        foreach ($ids as $id) {
            if (DB::table('relations')->where('targetId', $id)->exists()) {
                $result['skipped'][] = $id;
            } elseif ($this->delete($id)) {
                $result['deleted'][] = $id;
            } else {
                $result['failed'][] = $id;
            }
        }

        return $result;
    }

    /**
     * Whether $user may rename item $id in $siteId, or in every site it exists in when $siteId is
     * null: they must be able to edit each of those sites and save the entry's copy there.
     */
    public function canRename(int $id, ?int $siteId, CraftUser $user): bool
    {
        $sites = $siteId !== null
            ? array_filter([Sites::getSiteById($siteId, true)])
            : Sites::getAllSites(true)->all();
        $multiSite = Sites::isMultiSite();

        foreach ($sites as $site) {
            $item = $this->getWritableItem($id, (int) $site->id);
            if ($item === null) {
                continue;
            }
            if ($multiSite && ! $user->can("editSite:{$site->uid}")) {
                return false;
            }
            if (! $user->can('save', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $user may merge, as far as the items themselves go: save the target and delete each
     * source. Relations owned by other elements are checked by {@see canRepointRelations()}.
     *
     * @param  array<int|string>  $sourceIds
     */
    public function canMergeItems(array $sourceIds, int $targetId, CraftUser $user): bool
    {
        $target = $this->getWritableItem($targetId);
        if ($target === null || ! $user->can('save', $target)) {
            return false;
        }

        return $this->canDeleteItems($this->normalizeSourceIds($sourceIds, $targetId), $user);
    }

    /**
     * Whether $user may delete every one of $ids. IDs that aren't writable items are ignored
     * here; the delete skips them.
     *
     * @param  array<int|string>  $ids
     */
    public function canDeleteItems(array $ids, CraftUser $user): bool
    {
        foreach ($ids as $id) {
            $item = $this->getWritableItem((int) $id);
            if ($item !== null && ! $user->can('delete', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether $user may save every element whose relations a merge or swap of $sourceIds would
     * rewrite — the entries, assets, users and so on with a relational field pointing at a source.
     * Re-pointing a relation edits its owner, so this stops a merge or swap from changing content
     * the user can't edit. Revisions are checked against their canonical element.
     *
     * @param  array<int|string>  $sourceIds
     */
    public function canRepointRelations(array $sourceIds, CraftUser $user): bool
    {
        $sourceIds = array_values(array_filter(array_map(fn ($id) => (int) $id, $sourceIds)));
        if ($sourceIds === []) {
            return true;
        }

        $rows = DB::table('relations as r')
            ->join('elements as e', 'e.id', '=', 'r.sourceId')
            ->whereIn('r.targetId', $sourceIds)
            ->distinct()
            ->get(['r.sourceId', 'r.sourceSiteId', 'e.type']);

        // Owners grouped by type and site, so each group loads in one query. Relations from
        // non-localized fields carry no site; any site the owner exists in will do.
        $groups = [];
        foreach ($rows as $row) {
            $site = $row->sourceSiteId !== null ? (int) $row->sourceSiteId : '*';
            $groups[(string) $row->type][$site][] = (int) $row->sourceId;
        }

        foreach ($groups as $type => $bySite) {
            if (! is_subclass_of($type, ElementInterface::class)) {
                return false;
            }
            foreach ($bySite as $site => $ids) {
                foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
                    $query = $type::find()->id($chunk)->status(null);
                    foreach (['drafts', 'provisionalDrafts', 'revisions'] as $method) {
                        if (method_exists($query, $method)) {
                            $query->$method(null);
                        }
                    }
                    $site === '*' ? $query->site('*')->unique() : $query->siteId($site);

                    foreach ($query->all() as $owner) {
                        $ability = $owner->getIsRevision() ? 'saveCanonical' : 'save';
                        if (! $user->can($ability, $owner)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Integer, de-duplicated source IDs without the target.
     *
     * @param  array<int|string>  $sourceIds
     * @return list<int>
     */
    private function normalizeSourceIds(array $sourceIds, int $targetId): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($id) => (int) $id, $sourceIds),
            fn (int $id) => $id !== 0 && $id !== $targetId,
        )));
    }

    /**
     * Re-points every relation targeting $sourceIds onto $targetId, deleting any that would
     * duplicate one the target already has. Returns the element types whose cached output may now
     * be stale: every rewritten relation's owner, plus the sources.
     *
     * @param  list<int>  $sourceIds
     * @return list<string>
     */
    private function repointRelations(array $sourceIds, int $targetId): array
    {
        [$relations, $toUpdate, $toDelete] = $this->classifyRelations($sourceIds, $targetId);

        $affectedIds = array_merge($sourceIds, array_map(fn ($r) => (int) $r->sourceId, $relations));
        $affectedTypes = DB::table('elements')
            ->whereIn('id', array_values(array_unique($affectedIds)))
            ->distinct()
            ->pluck('type')
            ->map(fn ($t) => (string) $t)
            ->all();

        if ($toDelete !== []) {
            DB::table('relations')->whereIn('id', array_map(fn ($r) => (int) $r->id, $toDelete))->delete();
        }
        if ($toUpdate !== []) {
            DB::table('relations')->whereIn('id', array_map(fn ($r) => (int) $r->id, $toUpdate))->update(['targetId' => $targetId]);
        }

        return array_values($affectedTypes);
    }

    /**
     * Sorts every relation pointing at $sourceIds into the rows a re-point would update and the
     * rows it would delete because the owner already relates to the target through the same field
     * and site. Shared by the merge and its preview, so a dry run reports exactly what a merge does.
     *
     * @param  list<int>  $sourceIds
     * @return array{0: list<object>, 1: list<object>, 2: list<object>} [all relations, to update, to delete]
     */
    private function classifyRelations(array $sourceIds, int $targetId): array
    {
        $relations = DB::table('relations')
            ->select(['id', 'fieldId', 'sourceId', 'sourceSiteId', 'targetId'])
            ->whereIn('targetId', $sourceIds)
            ->orderBy('id')
            ->get()
            ->all();

        $seen = [];
        foreach (DB::table('relations')->select(['fieldId', 'sourceId', 'sourceSiteId'])->where('targetId', $targetId)->get() as $r) {
            $seen[$r->fieldId.'|'.$r->sourceId.'|'.($r->sourceSiteId ?? 'null')] = true;
        }

        $toDelete = [];
        $toUpdate = [];
        foreach ($relations as $r) {
            $key = $r->fieldId.'|'.$r->sourceId.'|'.($r->sourceSiteId ?? 'null');
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
     * IDs of every site an element exists in.
     *
     * @return list<int>
     */
    private function siteIds(int $elementId): array
    {
        return DB::table('elements_sites')
            ->where('elementId', $elementId)
            ->pluck('siteId')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Clears element caches after relations were rewritten with raw SQL, which bypasses the element
     * saves that would normally do it.
     *
     * @param  list<string>  $elementTypes
     */
    private function invalidateCaches(array $elementTypes, ElementInterface $target): void
    {
        foreach ($elementTypes as $type) {
            ElementCaches::invalidateForElementType($type);
        }
        ElementCaches::invalidateForElement($target);
    }
}
