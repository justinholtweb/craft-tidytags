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
     * Renders the duplicate-scanner view with clusters for every source.
     */
    public function actionDuplicates(): Response
    {
        $plugin = Plugin::$plugin;
        $request = Craft::$app->getRequest();

        $siteIdParam = $request->getQueryParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        $thresholdParam = $request->getQueryParam('threshold');
        $threshold = ($thresholdParam !== null && $thresholdParam !== '') ? (int)$thresholdParam : null;

        $scope = $request->getQueryParam('scope', 'within');

        if ($scope === 'cross') {
            $crossClusters = $plugin->duplicateDetector->findCrossSourceDuplicates($siteId, $threshold);
            $results = [];
        } else {
            $results = $plugin->duplicateDetector->findAllDuplicates($siteId, $threshold);
            $crossClusters = [];
        }

        return $this->renderTemplate('tidytags/duplicates', [
            'results' => $results,
            'crossClusters' => $crossClusters,
            'scope' => $scope === 'cross' ? 'cross' : 'within',
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => $siteId,
            'threshold' => $threshold ?? $plugin->duplicateDetector->defaultThreshold,
            'selectedSubnavItem' => 'duplicates',
        ]);
    }
}
