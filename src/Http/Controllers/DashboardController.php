<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Http\Controllers;

use CraftCms\Cms\Http\Responses\CpScreenResponse;
use CraftCms\Cms\Support\Facades\Sites;
use JustinHoltWeb\TidyTags\Services\Sources;

use function CraftCms\Cms\cp_url;
use function CraftCms\Cms\t;

/**
 * Every tag-like section with its total and per-site counts.
 */
class DashboardController
{
    public function __invoke(Sources $sources): CpScreenResponse
    {
        $rows = [];
        foreach ($sources->getAllSources() as $source) {
            $rows[] = [
                'source' => $source,
                'url' => cp_url($source->cpPath()),
                'total' => $sources->getTotalCount($source),
                'countsBySite' => $sources->getCountsBySite($source),
            ];
        }

        return new CpScreenResponse()
            ->title(t('Tidy Tags', category: 'tidytags'))
            ->selectedSubnavItem('dashboard')
            ->contentTemplate('tidytags/_dashboard', [
                'rows' => $rows,
                'sites' => Sites::getAllSites()->all(),
                'settingsUrl' => cp_url('settings/plugins/tidytags'),
            ]);
    }
}
