<?php

namespace justinholtweb\tidytags\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\tidytags\helpers\Csv;
use justinholtweb\tidytags\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Item mutations, cross-type relation swap, and the editor-side duplicate /
 * usage lookup endpoints.
 *
 * Browse/search views for all sources live in SourcesController. Rename,
 * merge and delete act on writable items only — tags, and entries in sections
 * opted into `writableSectionUids` — via {@see \justinholtweb\tidytags\services\Tags::getWritableItem()},
 * so an ID from anywhere else won't resolve and the action no-ops.
 *
 * The exceptions are:
 * - {@see actionUsages()} reads relations against any Tidy Tags source element.
 * - {@see actionSwap()} re-points relations between any two Tidy Tags source
 *   elements without deleting either side, so it's safe to call across
 *   element types.
 *
 * Plugin access alone only allows the read-only lookups. Mutations need the
 * permissions registered in {@see Plugin::_registerPermissions()}, and merge and
 * swap also need save rights on every element whose relations they rewrite.
 */
class TagsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * The most usages the usage lookup returns. A heavily used tag can have
     * thousands; the list is a preview, not a report.
     */
    public const USAGES_LIMIT = 200;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-tidytags');

        $required = match ($action->id) {
            'rename', 'swap' => [Plugin::PERMISSION_MANAGE_TAGS],
            'url-report' => [Plugin::PERMISSION_MANAGE_TAGS, Plugin::PERMISSION_DELETE_TAGS],
            'delete' => [Plugin::PERMISSION_DELETE_TAGS],
            'merge' => [Plugin::PERMISSION_MANAGE_TAGS, Plugin::PERMISSION_DELETE_TAGS],
            default => [],
        };
        foreach ($required as $permission) {
            $this->requirePermission($permission);
        }

        return parent::beforeAction($action);
    }

    /**
     * Session flash key holding the token of the last merge's URL report.
     */
    public const URL_REPORT_FLASH = 'tidytagsUrlReport';

    /**
     * How long a merge's URL report stays downloadable, in seconds.
     */
    public const URL_REPORT_TTL = 86400;

    /**
     * Renames a single item across all sites or within a given site.
     */
    public function actionRename(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $tagId = (int)$request->getRequiredBodyParam('tagId');
        $newTitle = (string)$request->getRequiredBodyParam('title');
        $siteIdParam = $request->getBodyParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        $user = static::currentUser();
        if ($user === null || !Plugin::$plugin->tags->canRename($tagId, $siteId, $user)) {
            throw new ForbiddenHttpException('You can\'t edit this item in every site it would be renamed in.');
        }

        $ok = Plugin::$plugin->tags->rename($tagId, $newTitle, $siteId);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $ok]);
        }

        if ($ok) {
            Craft::$app->getSession()->setNotice('Renamed.');
        } else {
            Craft::$app->getSession()->setError('Unable to rename.');
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes one or more items. Entries go to the trash; tags are deleted
     * outright.
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $tagIds = $request->getBodyParam('tagIds');
        if (!is_array($tagIds)) {
            $single = $request->getBodyParam('tagId');
            if ($single === null) {
                throw new BadRequestHttpException('tagId or tagIds is required.');
            }
            $tagIds = [(int)$single];
        }

        $user = static::currentUser();
        if ($user === null || !Plugin::$plugin->tags->canDeleteItems($tagIds, $user)) {
            throw new ForbiddenHttpException('You can\'t delete every one of these items.');
        }

        $count = Plugin::$plugin->tags->deleteMany($tagIds);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $count > 0, 'deleted' => $count]);
        }

        Craft::$app->getSession()->setNotice("Deleted {$count} item(s).");
        return $this->redirectToPostedUrl();
    }

    /**
     * Merges source items into a target item in the same source.
     *
     * When the merge retires URLs (entries with URIs), the report is cached and
     * its token flashed, so the next page can offer it as a CSV download.
     */
    public function actionMerge(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $sourceIds = $request->getRequiredBodyParam('sourceIds');
        $targetId = (int)$request->getRequiredBodyParam('targetId');

        if (!is_array($sourceIds)) {
            throw new BadRequestHttpException('sourceIds must be an array.');
        }

        $user = static::currentUser();
        if ($user === null || !Plugin::$plugin->tags->canMergeItems($sourceIds, $targetId, $user)) {
            throw new ForbiddenHttpException('You can\'t edit the item being kept or delete the items being merged.');
        }
        $this->_requireCanRepoint($sourceIds);

        $tags = Plugin::$plugin->tags;
        $problems = $tags->mergeProblems($sourceIds, $targetId);
        $result = $problems === [] ? $tags->merge($sourceIds, $targetId) : ['success' => false, 'urls' => []];
        $message = $problems !== [] ? implode(' ', $problems) : ($result['success'] ? null : 'Merge failed.');

        $reportUrl = null;
        if ($result['success'] && $result['urls'] !== []) {
            $token = Craft::$app->getSecurity()->generateRandomString(24);
            Craft::$app->getCache()->set($this->_reportCacheKey($token), $result['urls'], self::URL_REPORT_TTL);
            Craft::$app->getSession()->setFlash(self::URL_REPORT_FLASH, ['token' => $token, 'count' => count($result['urls'])]);
            $reportUrl = UrlHelper::actionUrl('tidytags/tags/url-report', ['report' => $token]);
        }

        if ($request->getAcceptsJson()) {
            return $this->asJson([
                'success' => $result['success'],
                'message' => $message,
                'urls' => $result['urls'],
                'urlReport' => $reportUrl,
            ]);
        }

        if ($result['success']) {
            Craft::$app->getSession()->setNotice('Merged.');
        } else {
            Craft::$app->getSession()->setError($message);
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * Downloads the URL report of a recent merge as CSV: site, merged item,
     * old URL, new URL. Ready to feed into whatever redirect tool the site uses.
     *
     * The parameter is `report`, not `token`: Craft reserves `token` for its
     * own preview and impersonation tokens and rejects requests whose `token`
     * it doesn't recognise.
     */
    public function actionUrlReport(string $report): Response
    {
        $rows = Craft::$app->getCache()->get($this->_reportCacheKey($report));
        if (!is_array($rows)) {
            throw new NotFoundHttpException('That URL report has expired.');
        }

        $csv = Csv::build(
            ['site', 'sourceId', 'sourceTitle', 'oldUrl', 'newUrl'],
            array_map(fn(array $row) => [
                $row['siteHandle'],
                $row['sourceId'],
                $row['sourceTitle'],
                $row['oldUrl'],
                $row['newUrl'],
            ], $rows),
        );

        return $this->response->sendContentAsFile($csv, 'tidytags-merged-urls.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    /**
     * Returns JSON with tags and entries similar to a given title, used by the
     * editor-side "did you mean" warning.
     */
    public function actionCheckDuplicate(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $title = (string)$request->getParam('title', '');

        $groupIdParam = $request->getParam('groupId');
        $groupId = ($groupIdParam !== null && $groupIdParam !== '') ? (int)$groupIdParam : null;

        $siteIdParam = $request->getParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        // From an entry's title field: only entries in tag-like sections are
        // checked, and the entry itself is left out of its own matches.
        $elementIdParam = $request->getParam('elementId');
        if ($elementIdParam !== null && $elementIdParam !== '') {
            $canonicalId = $this->_tagLikeEntryId((int)$elementIdParam);
            if ($canonicalId === null) {
                return $this->asJson(['success' => true, 'applies' => false, 'matches' => []]);
            }

            $matches = Plugin::$plugin->duplicateDetector->findSimilar($title, null, $siteId, null, 11);
            $matches = array_values(array_filter($matches, fn(array $m) => (int)$m['id'] !== $canonicalId));

            return $this->asJson([
                'success' => true,
                'applies' => true,
                'matches' => array_slice($matches, 0, 10),
            ]);
        }

        $matches = Plugin::$plugin->duplicateDetector->findSimilar($title, $groupId, $siteId);

        return $this->asJson([
            'success' => true,
            'applies' => true,
            'matches' => $matches,
        ]);
    }

    /**
     * The canonical ID of an entry (or of the entry a draft belongs to) when it
     * is in a tag-like section, otherwise null. Unpublished drafts — what the
     * "New entry" button in an Entries field creates — count as their own
     * canonical entry.
     */
    private function _tagLikeEntryId(int $elementId): ?int
    {
        if ($elementId <= 0) {
            return null;
        }

        $entry = Entry::find()
            ->id($elementId)
            ->drafts(null)
            ->provisionalDrafts(null)
            ->status(null)
            ->site('*')
            ->unique()
            ->one();

        if ($entry === null || $entry->sectionId === null) {
            return null;
        }
        if (Plugin::$plugin->sources->getEntrySource((int)$entry->sectionId) === null) {
            return null;
        }

        return (int)$entry->getCanonicalId();
    }

    /**
     * Returns JSON with every relation that targets a given element. Used by
     * the duplicates view to expand a cluster item and preview what would
     * move during a swap or merge.
     */
    public function actionUsages(): Response
    {
        $this->requireAcceptsJson();

        $elementId = (int)Craft::$app->getRequest()->getParam('elementId');
        if ($elementId <= 0) {
            return $this->asJson(['success' => false, 'usages' => []]);
        }

        $element = Craft::$app->getElements()->getElementById($elementId);
        if ($element === null || !Plugin::$plugin->sources->isSourceElement($element)) {
            return $this->asJson(['success' => false, 'usages' => []]);
        }

        // Ask for one more than is shown, so the UI can say the list is cut off.
        $usages = Plugin::$plugin->sources->getUsages($elementId, self::USAGES_LIMIT + 1, static::currentUser());

        return $this->asJson([
            'success' => true,
            'usages' => array_slice($usages, 0, self::USAGES_LIMIT),
            'hasMore' => count($usages) > self::USAGES_LIMIT,
        ]);
    }

    /**
     * Re-points every relation from the given source elements onto the target
     * element, without deleting the source elements themselves. Works across
     * element types — tag → tag, entry → entry, or any cross-type combination.
     */
    public function actionSwap(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $sourceIds = $request->getRequiredBodyParam('sourceIds');
        $targetId = (int)$request->getRequiredBodyParam('targetId');

        if (!is_array($sourceIds)) {
            throw new BadRequestHttpException('sourceIds must be an array.');
        }
        $sourceIds = array_map(fn($id) => (int)$id, $sourceIds);

        // Swap is the one mutation that accepts any element type, so keep it to
        // elements Tidy Tags manages rather than letting it re-point relations
        // between arbitrary entries, assets or users.
        $elementsService = Craft::$app->getElements();
        foreach (array_unique([...$sourceIds, $targetId]) as $id) {
            $element = $elementsService->getElementById($id);
            // Fail closed: an ID that doesn't resolve (trashed, a draft, nothing) is refused too,
            // or swap would re-point relations from elements outside the plugin's remit.
            if ($element === null || !Plugin::$plugin->sources->isSourceElement($element)) {
                throw new ForbiddenHttpException('Swap only works between Tidy Tags source elements.');
            }
        }

        $this->_requireCanRepoint($sourceIds);

        $ok = Plugin::$plugin->tags->swapRelations($sourceIds, $targetId);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $ok]);
        }

        if ($ok) {
            Craft::$app->getSession()->setNotice('Relations swapped.');
        } else {
            Craft::$app->getSession()->setError('Swap failed.');
        }
        return $this->redirectToPostedUrl();
    }

    private function _reportCacheKey(string $token): string
    {
        // Keyed by user too, so a leaked token can't be used by someone else.
        return 'tidytags.urlReport.' . (static::currentUser()?->id ?? 0) . '.' . preg_replace('/[^A-Za-z0-9_-]/', '', $token);
    }

    /**
     * Throws a 403 unless the current user can save every element whose
     * relations a merge or swap of $sourceIds would rewrite.
     *
     * @param array<int|string> $sourceIds
     * @throws ForbiddenHttpException
     */
    private function _requireCanRepoint(array $sourceIds): void
    {
        $user = static::currentUser();
        if ($user === null || !Plugin::$plugin->tags->canRepointRelations($sourceIds, $user)) {
            throw new ForbiddenHttpException('You can\'t edit every element that uses these items.');
        }
    }
}
