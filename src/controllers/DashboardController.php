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
     * Renders the dashboard with a list of tag groups and per-site counts.
     */
    public function actionIndex(): Response
    {
        $plugin = Plugin::$plugin;
        $groups = $plugin->tags->getAllGroups();
        $sites = $plugin->tags->getAllSites();

        $rows = [];
        foreach ($groups as $group) {
            $rows[] = [
                'group' => $group,
                'total' => $plugin->tags->getGroupTotalCount($group->id),
                'countsBySite' => $plugin->tags->getGroupCountsBySite($group->id),
            ];
        }

        return $this->renderTemplate('tidytags/index', [
            'rows' => $rows,
            'sites' => $sites,
            'selectedSubnavItem' => 'dashboard',
        ]);
    }

    /**
     * Renders the duplicate-scanner view with clusters for every group.
     */
    public function actionDuplicates(): Response
    {
        $plugin = Plugin::$plugin;
        $request = Craft::$app->getRequest();

        $siteIdParam = $request->getQueryParam('siteId');
        $siteId = ($siteIdParam !== null && $siteIdParam !== '') ? (int)$siteIdParam : null;

        $thresholdParam = $request->getQueryParam('threshold');
        $threshold = ($thresholdParam !== null && $thresholdParam !== '') ? (int)$thresholdParam : null;

        $results = $plugin->duplicateDetector->findAllDuplicates($siteId, $threshold);

        return $this->renderTemplate('tidytags/duplicates', [
            'results' => $results,
            'sites' => $plugin->tags->getAllSites(),
            'selectedSiteId' => $siteId,
            'threshold' => $threshold ?? $plugin->duplicateDetector->defaultThreshold,
            'selectedSubnavItem' => 'duplicates',
        ]);
    }
}
