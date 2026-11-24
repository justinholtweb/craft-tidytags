<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Http\Controllers;

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Http\RespondsWithFlash;
use CraftCms\Cms\Support\Facades\Elements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JustinHoltWeb\TidyTags\Services\DuplicateDetector;
use JustinHoltWeb\TidyTags\Services\Items;
use JustinHoltWeb\TidyTags\Services\Sources;
use JustinHoltWeb\TidyTags\Support\Csv;
use Symfony\Component\HttpFoundation\Response;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;

/**
 * Rename, delete, merge and swap, plus the read-only usage and "did you mean?" lookups.
 *
 * The routes check Tidy Tags' permissions; these actions check the user's rights over the
 * entries involved, and over every element whose relations a merge or swap rewrites.
 */
class ItemsController
{
    use RespondsWithFlash;

    /** Session flash key holding the last merge's URL report: token and count. */
    public const string URL_REPORT_FLASH = 'tidytagsUrlReport';

    /** How long a merge's URL report stays downloadable, in seconds. */
    public const int URL_REPORT_TTL = 86400;

    /** The most usages the usage lookup returns: it's a preview, not a report. */
    public const int USAGES_LIMIT = 200;

    public function __construct(
        private readonly Items $items,
        private readonly Sources $sources,
    ) {}

    /**
     * The URL report a merge just flashed, with its download link, for the next screen to offer.
     *
     * @return array{count: int, url: string}|null
     */
    public static function pendingUrlReport(): ?array
    {
        $flash = session(self::URL_REPORT_FLASH);

        return is_array($flash) && isset($flash['token'], $flash['count'])
            ? ['count' => (int) $flash['count'], 'url' => cp_url('tidytags/tags/url-report', ['report' => $flash['token']])]
            : null;
    }

    public function rename(Request $request): Response
    {
        $id = $request->integer('tagId');
        $title = (string) $request->input('title', '');
        $siteId = $request->integer('siteId') ?: null;

        $user = currentUser() ?? abort(403);
        abort_unless($this->items->canRename($id, $siteId, $user), 403, 'You can’t edit this item in every site it would be renamed in.');

        return $this->items->rename($id, $title, $siteId)
            ? $this->asSuccess('Renamed.')
            : $this->asFailure('Unable to rename.');
    }

    public function delete(Request $request): Response
    {
        $ids = $request->input('tagIds');
        if (! is_array($ids)) {
            abort_unless($request->filled('tagId'), 400, 'tagId or tagIds is required.');
            $ids = [$request->integer('tagId')];
        }

        $user = currentUser() ?? abort(403);
        abort_unless($this->items->canDeleteItems($ids, $user), 403, 'You can’t delete every one of these items.');

        $count = $this->items->deleteMany($ids);

        return $count > 0
            ? $this->asSuccess("Moved {$count} item(s) to the trash.", ['deleted' => $count])
            : $this->asFailure('Nothing was deleted.', ['deleted' => 0]);
    }

    /**
     * Merges source items into a target. When the merge retires URLs, the report is cached and
     * its token flashed, so the next screen offers it as a CSV download.
     */
    public function merge(Request $request): Response
    {
        $sourceIds = $request->input('sourceIds');
        $targetId = $request->integer('targetId');
        abort_unless(is_array($sourceIds), 400, 'sourceIds must be an array.');

        $user = currentUser() ?? abort(403);
        abort_unless(
            $this->items->canMergeItems($sourceIds, $targetId, $user) && $this->items->canRepointRelations($sourceIds, $user),
            403,
            'You can’t edit the item being kept, delete the items being merged, or edit everything that uses them.',
        );

        $problems = $this->items->mergeProblems($sourceIds, $targetId);
        if ($problems !== []) {
            return $this->asFailure(implode(' ', $problems), ['urls' => [], 'urlReport' => null]);
        }

        $result = $this->items->merge($sourceIds, $targetId);
        if (! $result['success']) {
            return $this->asFailure('Merge failed.', ['urls' => [], 'urlReport' => null]);
        }

        $reportUrl = null;
        if ($result['urls'] !== []) {
            $token = Str::random(32);
            Cache::put(self::reportCacheKey($token), $result['urls'], self::URL_REPORT_TTL);
            session()->flash(self::URL_REPORT_FLASH, ['token' => $token, 'count' => count($result['urls'])]);
            $reportUrl = cp_url('tidytags/tags/url-report', ['report' => $token]);
        }

        return $this->asSuccess('Merged.', ['urls' => $result['urls'], 'urlReport' => $reportUrl]);
    }

    /**
     * Downloads a recent merge's URL report as CSV: site, merged item, old URL, new URL.
     */
    public function urlReport(Request $request): Response
    {
        $rows = Cache::get(self::reportCacheKey((string) $request->query('report', '')));
        abort_unless(is_array($rows), 404, 'That URL report has expired.');

        $csv = Csv::build(
            ['site', 'sourceId', 'sourceTitle', 'oldUrl', 'newUrl'],
            array_map(fn (array $row) => [$row['siteHandle'], $row['sourceId'], $row['sourceTitle'], $row['oldUrl'], $row['newUrl']], $rows),
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="tidytags-merged-urls.csv"',
        ]);
    }

    /**
     * Re-points relations from the source elements onto the target without deleting either side.
     * Only between Tidy Tags source elements, so it can't rewrite arbitrary entries' relations.
     */
    public function swap(Request $request): Response
    {
        $sourceIds = $request->input('sourceIds');
        $targetId = $request->integer('targetId');
        abort_unless(is_array($sourceIds), 400, 'sourceIds must be an array.');
        $sourceIds = array_map('intval', $sourceIds);

        foreach (array_unique([...$sourceIds, $targetId]) as $id) {
            $element = Elements::getElementById($id, null, '*');
            abort_if($element !== null && ! $this->sources->isSourceElement($element), 403, 'Swap only works between Tidy Tags source elements.');
        }

        $user = currentUser() ?? abort(403);
        abort_unless($this->items->canRepointRelations($sourceIds, $user), 403, 'You can’t edit every element that uses these items.');

        return $this->items->swapRelations($sourceIds, $targetId)
            ? $this->asSuccess('Relations swapped.')
            : $this->asFailure('Swap failed.');
    }

    /**
     * Every relation that targets a Tidy Tags source element, for "Show usages".
     */
    public function usages(Request $request): JsonResponse
    {
        $elementId = $request->integer('elementId');
        $element = $elementId > 0 ? Elements::getElementById($elementId, null, '*') : null;

        if ($element === null || ! $this->sources->isSourceElement($element)) {
            return response()->json(['success' => false, 'usages' => []]);
        }

        // One more than is shown, so the UI can say the list is cut off.
        $usages = $this->sources->getUsages($elementId, self::USAGES_LIMIT + 1, currentUser());

        return response()->json([
            'success' => true,
            'usages' => array_slice($usages, 0, self::USAGES_LIMIT),
            'hasMore' => count($usages) > self::USAGES_LIMIT,
        ]);
    }

    /**
     * Titles similar to the one being typed into an entry's title field. Only entries in tag-like
     * sections are checked, and the entry itself is left out; anything else answers
     * `applies: false` so the editor stops asking.
     */
    public function checkDuplicate(Request $request, DuplicateDetector $detector): JsonResponse
    {
        $title = (string) $request->query('title', '');
        $siteId = $request->integer('siteId') ?: null;
        $canonicalId = $this->tagLikeEntryId($request->integer('elementId'));

        if ($canonicalId === null) {
            return response()->json(['success' => true, 'applies' => false, 'matches' => []]);
        }

        $matches = array_values(array_filter(
            $detector->findSimilar($title, $siteId, null, 11),
            fn (array $m) => (int) $m['id'] !== $canonicalId,
        ));

        return response()->json(['success' => true, 'applies' => true, 'matches' => array_slice($matches, 0, 10)]);
    }

    /**
     * The canonical ID of an entry (or of the entry a draft belongs to) in a tag-like section,
     * otherwise null. Unpublished drafts — what "New entry" creates — are their own canonical.
     */
    private function tagLikeEntryId(int $elementId): ?int
    {
        if ($elementId <= 0) {
            return null;
        }

        $entry = Entry::find()->id($elementId)->drafts(null)->provisionalDrafts(null)->status(null)->site('*')->unique()->one();

        if (! $entry instanceof Entry || $entry->sectionId === null || $this->sources->getSource((int) $entry->sectionId) === null) {
            return null;
        }

        return (int) $entry->getCanonicalId();
    }

    /**
     * Keyed by user too, so a leaked token is no use to anyone else.
     */
    private static function reportCacheKey(string $token): string
    {
        return 'tidytags.urlReport.'.(currentUser()?->getAuthIdentifier() ?? 0).'.'.preg_replace('/[^A-Za-z0-9]/', '', $token);
    }
}
