<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Http\Request;
use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Plugin;
use JustinHoltWeb\TidyTags\Services\Sources;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * One tag-like section, a page at a time, with per-site titles and the Rename, Merge and Delete
 * actions when the section allows them. Sections can hold tens of thousands of entries, so only
 * `pageSize` are loaded per request.
 */
class SourceController
{
    public function __invoke(int $sectionId, Request $request, Sources $sources, Settings $settings): CpScreenResponse
    {
        $source = $sources->getSource($sectionId) ?? abort(404, 'That section isn’t a Tidy Tags source.');

        $siteId = $request->integer('siteId') ?: null;
        $search = trim((string) $request->query('search', ''));
        $search = $search === '' ? null : $search;

        $pageSize = $settings->pageSize;
        $total = $sources->countElementsInSource($source, $siteId, $search);
        $totalPages = max(1, (int) ceil($total / $pageSize));
        $page = min(max(1, $request->integer('page', 1)), $totalPages);

        $rows = $sources->getElementsInSource($source, $siteId, $search, $pageSize, ($page - 1) * $pageSize);

        $user = currentUser();
        $filterParams = array_filter(['search' => $search, 'siteId' => $siteId]);
        $basePath = $source->cpPath();

        return new CpScreenResponse()
            ->title($source->name)
            ->selectedSubnavItem('dashboard')
            ->crumbs([['label' => t('Tidy Tags', category: 'tidytags'), 'url' => cp_url('tidytags')]])
            ->contentTemplate('tidytags/_section', [
                'source' => $source,
                'sites' => Sites::getAllSites()->all(),
                'selectedSiteId' => $siteId,
                'rows' => $rows,
                'search' => $search,
                'baseUrl' => cp_url($basePath),
                'returnUrl' => cp_url($basePath, $filterParams + ($page > 1 ? ['page' => $page] : [])),
                'filterParams' => $filterParams,
                'pagination' => [
                    'page' => $page,
                    'totalPages' => $totalPages,
                    'total' => $total,
                    'first' => $total === 0 ? 0 : ($page - 1) * $pageSize + 1,
                    'last' => ($page - 1) * $pageSize + count($rows),
                ],
                'canManage' => $user?->can(Plugin::PERMISSION_MANAGE_TAGS) ?? false,
                'canDelete' => $user?->can(Plugin::PERMISSION_DELETE_TAGS) ?? false,
                'urlReport' => ItemsController::pendingUrlReport(),
                'actions' => [
                    'rename' => cp_url('tidytags/tags/rename'),
                    'merge' => cp_url('tidytags/tags/merge'),
                    'delete' => cp_url('tidytags/tags/delete'),
                ],
            ]);
    }
}
