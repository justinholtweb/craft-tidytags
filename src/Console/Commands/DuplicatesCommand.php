<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use CraftCms\Cms\Site\Data\Site;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Console\Command;
use JustinHoltWeb\TidyTags\Console\Commands\Concerns\ReportOutput;
use JustinHoltWeb\TidyTags\Data\Source;
use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Services\DuplicateDetector;
use JustinHoltWeb\TidyTags\Services\Sources;
use JustinHoltWeb\TidyTags\Services\TitleMatcher;

/**
 * Scans for near-duplicates, the console twin of the Duplicates screen. Read-only.
 */
class DuplicatesCommand extends Command
{
    use CraftCommand;
    use ReportOutput;

    #[\Override]
    protected $signature = 'tidytags:duplicates
        {--source= : Only this tag-like section, by handle}
        {--scope=within : within (clusters inside each section) or cross (clusters spanning sections)}
        {--strategy= : fuzzy or strict, for this run only}
        {--threshold= : Levenshtein threshold for fuzzy, 0–6 (default 2)}
        {--site= : Site handle whose titles are compared (default: the primary site)}
        {--format=table : table, json or csv}
        {--fail-on-duplicates : Exit 1 when any duplicates are found, for CI}';

    #[\Override]
    protected $description = 'Lists clusters of near-duplicate entries in tag-like sections.';

    /** The Craft 5 command. */
    #[\Override]
    protected $aliases = ['tidytags/duplicates'];

    public function handle(Sources $sources, DuplicateDetector $detector, Settings $settings): int
    {
        if (! $this->checkFormat()) {
            return self::INVALID;
        }

        $scope = (string) $this->option('scope');
        if (! in_array($scope, ['within', 'cross'], true)) {
            $this->problem('--scope must be `within` or `cross`.');

            return self::INVALID;
        }

        if (($strategy = $this->option('strategy')) !== null) {
            if (! in_array($strategy, [TitleMatcher::STRATEGY_FUZZY, TitleMatcher::STRATEGY_STRICT], true)) {
                $this->problem('--strategy must be `fuzzy` or `strict`.');

                return self::INVALID;
            }
            // In memory only: the saved setting is untouched.
            $settings->matchStrategy = (string) $strategy;
        }

        $threshold = $this->option('threshold');
        if ($threshold !== null && (! ctype_digit((string) $threshold) || (int) $threshold > 6)) {
            $this->problem('--threshold must be between 0 and 6.');

            return self::INVALID;
        }
        $threshold = $threshold !== null ? (int) $threshold : $detector->defaultThreshold;

        $site = $this->resolveSite();
        if ($site === null) {
            return self::INVALID;
        }

        $selected = $sources->getAllSources();
        if (($handle = $this->option('source')) !== null) {
            if ($scope === 'cross') {
                $this->problem('--source can’t be combined with --scope=cross, which pools every section.');

                return self::INVALID;
            }
            $selected = $sources->findSourcesByHandle((string) $handle);
            if ($selected === []) {
                $this->problem("No tag-like section has the handle “{$handle}”.");

                return self::INVALID;
            }
        }

        $clusters = [];
        if ($scope === 'cross') {
            foreach ($detector->findCrossSourceDuplicates((int) $site->id, $threshold) as $items) {
                $clusters[] = ['source' => null, 'items' => $items];
            }
        } else {
            foreach ($selected as $source) {
                foreach ($detector->findDuplicates($source, (int) $site->id, $threshold) as $items) {
                    if (count($items) > 1) {
                        $clusters[] = ['source' => $source, 'items' => $items];
                    }
                }
            }
        }

        $this->render($clusters, app(TitleMatcher::class)->getStrategy(), $threshold, $site, $scope, $sources);

        if ($this->option('fail-on-duplicates') && $clusters !== []) {
            $this->stderr(count($clusters).' duplicate cluster(s) found.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function resolveSite(): ?Site
    {
        $handle = $this->option('site');
        if ($handle === null) {
            return Sites::getPrimarySite();
        }

        $site = Sites::getSiteByHandle((string) $handle, true);
        if ($site === null) {
            $this->problem("No site has the handle “{$handle}”.");
        }

        return $site;
    }

    /**
     * @param  list<array{source: ?Source, items: array<int, array<string, mixed>>}>  $clusters
     */
    private function render(array $clusters, string $strategy, int $threshold, Site $site, string $scope, Sources $sources): void
    {
        $handles = [];
        foreach ($sources->getAllSources() as $s) {
            $handles[$s->uid] = $s->handle;
        }

        $rows = [];
        foreach ($clusters as $n => $cluster) {
            foreach ($cluster['items'] as $item) {
                $rows[] = [$n + 1, $item['id'], $item['title'], $handles[$item['sourceUid']] ?? '', $item['differentiator']];
            }
        }

        $fuzzy = $strategy === TitleMatcher::STRATEGY_FUZZY;
        $settingsLine = sprintf('Strategy %s%s, scope %s, site %s.', $strategy, $fuzzy ? " (threshold {$threshold})" : '', $scope, $site->handle);

        if ($this->format() === 'json') {
            $this->outputJson([
                'strategy' => $strategy,
                'threshold' => $fuzzy ? $threshold : null,
                'scope' => $scope,
                'site' => $site->handle,
                'total' => count($clusters),
                'clusters' => array_map(fn (array $c) => [
                    'source' => $c['source'] !== null ? ['handle' => $c['source']->handle, 'name' => $c['source']->name] : null,
                    'items' => array_map(fn (array $i) => [
                        'id' => $i['id'],
                        'title' => $i['title'],
                        'source' => $handles[$i['sourceUid']] ?? null,
                        'differentiator' => $i['differentiator'],
                        'displayValues' => (object) $i['displayValues'],
                    ], $c['items']),
                ], $clusters),
            ]);
            $this->say($settingsLine);

            return;
        }

        if ($this->format() === 'csv') {
            $this->outputCsv(['cluster', 'id', 'title', 'source', 'differentiator'], $rows);
            $this->say(count($clusters).' cluster(s). '.$settingsLine);

            return;
        }

        if ($clusters === []) {
            $this->say('No duplicates found. '.$settingsLine);

            return;
        }

        $this->table(['Cluster', 'ID', 'Title', 'Source', 'Differentiator'], array_map(
            fn (array $r) => array_map(fn ($v) => (string) ($v ?? ''), $r),
            $rows,
        ));
        $this->say(sprintf('%d cluster(s), %d item(s). %s', count($clusters), count($rows), $settingsLine));
    }
}
