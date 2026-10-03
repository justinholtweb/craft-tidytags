<?php

namespace justinholtweb\tidytags\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tidytags\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Tag mutations, cross-type relation swap, and the editor-side duplicate /
 * usage lookup endpoints.
 *
 * Browse/search views for all sources (including entry-backed tag-like
 * sections) live in SourcesController. Most actions here remain tag-only:
 * rename, merge, and delete operate on Tag elements exclusively, and the
 * element query scoping makes that safe even if a caller posts an entry ID
 * — the record won't be found and the action no-ops.
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
     * Renames a single tag across all sites or within a given site.
     */
    public function actionRename(): Response
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();

        $tagId = (int)$request->getRequiredBodyParam('tagId');
        $newTitle = (string)$request->getRequiredBodyParam('title');
        $siteIdParam = $request->getBodyParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        $ok = Plugin::$plugin->tags->renameTag($tagId, $newTitle, $siteId);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $ok]);
        }

        if ($ok) {
            Craft::$app->getSession()->setNotice('Tag renamed.');
        } else {
            Craft::$app->getSession()->setError('Unable to rename tag.');
        }
        return $this->redirectToPostedUrl();
    }

    /**
     * Deletes one or more tags.
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

        $count = Plugin::$plugin->tags->deleteTags($tagIds);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $count > 0, 'deleted' => $count]);
        }

        Craft::$app->getSession()->setNotice("Deleted {$count} tag(s).");
        return $this->redirectToPostedUrl();
    }

    /**
     * Merges source tags into a target tag.
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

        $this->_requireCanRepoint($sourceIds);

        $ok = Plugin::$plugin->tags->mergeTags($sourceIds, $targetId);

        if ($request->getAcceptsJson()) {
            return $this->asJson(['success' => $ok]);
        }

        if ($ok) {
            Craft::$app->getSession()->setNotice('Tags merged.');
        } else {
            Craft::$app->getSession()->setError('Merge failed.');
        }
        return $this->redirectToPostedUrl();
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

        $matches = Plugin::$plugin->duplicateDetector->findSimilar($title, $groupId, $siteId);

        return $this->asJson([
            'success' => true,
            'matches' => $matches,
        ]);
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
            if ($element !== null && !Plugin::$plugin->sources->isSourceElement($element)) {
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
