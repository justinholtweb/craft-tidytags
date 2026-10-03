<?php

namespace justinholtweb\tidytags\controllers;

use Craft;
use craft\web\Controller;
use justinholtweb\tidytags\Plugin;
use yii\web\Response;

/**
 * Dashboard and duplicate-scanner controller for the Tidy Tags CP section.
 */
class DashboardController extends Controller
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
     * Renders the dashboard with every source (tag groups + configured entry
     * sections) and their per-site counts.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        $sources = $plugin->sources->getAllSources();
        $sites = Craft::$app->getSites()->getAllSites();

        $rows = [];
        foreach ($sources as $source) {
            $rows[] = [
                'source' => $source,
                'total' => $plugin->sources->getTotalCount($source),
                'countsBySite' => $plugin->sources->getCountsBySite($source),
            ];
        }

        return $this->renderTemplate('tidytags/index', [
            'rows' => $rows,
            'sites' => $sites,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }

    /**
     * The largest similarity threshold the scanner accepts. Beyond this almost
     * every short title matches every other, which is noise, not duplicates.
     */
    public const MAX_THRESHOLD = 6;

    /**
     * Renders one page of duplicate clusters, within each source or across
     * sources. Only the clusters on the page are loaded as elements.
     */
    public function actionDuplicates(): Response
    {
        $plugin = Plugin::$plugin;
        $request = Craft::$app->getRequest();

        $siteIdParam = $request->getQueryParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        $thresholdParam = $request->getQueryParam('threshold');
        $threshold = ($thresholdParam !== null && $thresholdParam !== '')
            ? min(max(0, (int)$thresholdParam), self::MAX_THRESHOLD)
            : $plugin->duplicateDetector->defaultThreshold;

        $scope = $request->getQueryParam('scope') === 'cross' ? 'cross' : 'within';

        $result = $plugin->duplicateDetector->getDuplicatesPage(
            $scope,
            $siteId,
            $threshold,
            (int)$request->getQueryParam('page', 1),
            $plugin->getSettings()->duplicatesPageSize,
        );

        $user = static::currentUser();

        return $this->renderTemplate('tidytags/duplicates', [
            'clusters' => $result['clusters'],
            'pagination' => $result,
            'scope' => $scope,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => $siteId,
            'threshold' => $threshold,
            'maxThreshold' => self::MAX_THRESHOLD,
            'canManage' => $user?->can(Plugin::PERMISSION_MANAGE_TAGS) ?? false,
            'canDelete' => $user?->can(Plugin::PERMISSION_DELETE_TAGS) ?? false,
            'selectedSubnavItem' => 'duplicates',
        ]);
    }
}
