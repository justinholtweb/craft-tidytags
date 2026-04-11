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

    private function renderSource(Source $source, ?int $siteId): Response
    {
        $plugin = Plugin::$plugin;
        $search = Craft::$app->getRequest()->getQueryParam('search');
        $rows = $plugin->sources->getElementsInSource($source, $siteId, $search);

        return $this->renderTemplate('tidytags/group', [
            'source' => $source,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => $siteId,
            'rows' => $rows,
            'search' => $search,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }
}
