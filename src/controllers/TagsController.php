<?php

namespace justinholtweb\tidytags\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tidytags\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

/**
 * Tag element mutations and the editor-side duplicate check endpoint.
 *
 * Browse/search views for all sources (including entry-backed tag-like
 * sections) live in SourcesController. This controller is intentionally
 * tag-only: rename, merge, and delete operate on Tag elements exclusively,
 * and the element query scoping makes that safe even if a caller posts an
 * entry ID — the record won't be found and the action no-ops.
 */
class TagsController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = false;

    /**
     * @inheritdoc
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-tidytags');
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
     * Returns JSON with tags similar to a given title, used by the editor-side
     * "did you mean" warning.
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
}
