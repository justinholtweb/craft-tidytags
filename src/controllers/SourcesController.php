<?php

namespace justinholtweb\tidytags\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Renders the group view for any source — a native tag group or a channel
 * section that's been configured as tag-like.
 */
class SourcesController extends Controller
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

    public function actionGroup(int $groupId, ?int $siteId = null): Response
    {
        $source = Plugin::$plugin->sources->getTagSource($groupId);
        if ($source === null) {
            throw new NotFoundHttpException('Tag group not found.');
        }
        return $this->renderSource($source, $siteId);
    }

    public function actionSection(int $sectionId, ?int $siteId = null): Response
    {
        $source = Plugin::$plugin->sources->getEntrySource($sectionId);
        if ($source === null) {
            throw new NotFoundHttpException('Section not found or not configured as a Tidy Tags source.');
        }
        return $this->renderSource($source, $siteId);
    }

    /**
     * Renders one page of a source. Sources can hold tens of thousands of
     * items, so only `pageSize` elements are ever loaded per request.
     */
    private function renderSource(Source $source, ?int $siteId): Response
    {
        $plugin = Plugin::$plugin;
        $request = Craft::$app->getRequest();
        $search = $request->getQueryParam('search');
        $search = is_string($search) ? trim($search) : null;

        $pageSize = $plugin->getSettings()->pageSize;
        $total = $plugin->sources->countElementsInSource($source, $siteId, $search);
        $totalPages = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, (int)$request->getQueryParam('page', 1)), $totalPages);

        $rows = $plugin->sources->getElementsInSource(
            $source,
            $siteId,
            $search,
            $pageSize,
            ($page - 1) * $pageSize,
        );

        $user = static::currentUser();

        return $this->renderTemplate('tidytags/group', [
            'source' => $source,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => $siteId,
            'rows' => $rows,
            'search' => $search,
            'pagination' => [
                'page' => $page,
                'totalPages' => $totalPages,
                'total' => $total,
                'first' => $total === 0 ? 0 : ($page - 1) * $pageSize + 1,
                'last' => ($page - 1) * $pageSize + count($rows),
            ],
            'canManage' => $user?->can(Plugin::PERMISSION_MANAGE_TAGS) ?? false,
            'canDelete' => $user?->can(Plugin::PERMISSION_DELETE_TAGS) ?? false,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }
}
