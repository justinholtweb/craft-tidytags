<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Plugin;
use JustinHoltWeb\TidyTags\Services\DuplicateDetector;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * One page of near-duplicate clusters, within each source or across sources. Only the clusters
 * on the page are loaded as elements.
 */
class DuplicatesController
{
    /**
     * The largest similarity threshold accepted. Beyond this almost every short title matches
     * every other, which is noise, not duplicates.
     */
    public const int MAX_THRESHOLD = 6;

    public function __invoke(Request $request, DuplicateDetector $detector, Settings $settings): CpScreenResponse
    {
        $siteId = $request->integer('siteId') ?: null;
        $threshold = $request->filled('threshold')
            ? min(max(0, $request->integer('threshold')), self::MAX_THRESHOLD)
            : $detector->defaultThreshold;
        $scope = $request->query('scope') === 'cross' ? 'cross' : 'within';

        $result = $detector->getDuplicatesPage($scope, $siteId, $threshold, $request->integer('page', 1), $settings->duplicatesPageSize);

        $user = currentUser();
        $filterParams = array_filter(['scope' => $scope, 'threshold' => $threshold, 'siteId' => $siteId], fn ($v) => $v !== null);

        return new CpScreenResponse()
            ->title(t('Duplicates', category: 'tidytags'))
            ->selectedSubnavItem('duplicates')
            ->crumbs([['label' => t('Tidy Tags', category: 'tidytags'), 'url' => cp_url('tidytags')]])
            ->contentTemplate('tidytags/_duplicates', [
                'clusters' => $result['clusters'],
                'pagination' => $result,
                'scope' => $scope,
                'sites' => Sites::getAllSites()->all(),
                'selectedSiteId' => $siteId,
                'threshold' => $threshold,
                'maxThreshold' => self::MAX_THRESHOLD,
                'filterParams' => $filterParams,
                'baseUrl' => cp_url('tidytags/duplicates'),
                'returnUrl' => cp_url('tidytags/duplicates', $filterParams + ($result['page'] > 1 ? ['page' => $result['page']] : [])),
                'canManage' => $user?->can(Plugin::PERMISSION_MANAGE_TAGS) ?? false,
                'canDelete' => $user?->can(Plugin::PERMISSION_DELETE_TAGS) ?? false,
                'urlReport' => ItemsController::pendingUrlReport(),
                'actions' => [
                    'merge' => cp_url('tidytags/tags/merge'),
                    'swap' => cp_url('tidytags/tags/swap'),
                    'usages' => cp_url('tidytags/tags/usages'),
                ],
                'settingsUrl' => cp_url('settings/plugins/tidytags'),
            ]);
    }
}
