<?php

namespace justinholtweb\tidytags\console\controllers;

use Craft;
use craft\console\Controller;
use craft\models\Site;
use justinholtweb\tidytags\console\ReportOutput;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use justinholtweb\tidytags\services\TitleMatcher;
use yii\console\ExitCode;

/**
 * Scans for near-duplicate tags, the console twin of the Duplicates screen.
 *
 *     php craft tidytags/duplicates
 *     php craft tidytags/duplicates --source=topics --strategy=strict --format=json
 *     php craft tidytags/duplicates --scope=cross --format=csv > duplicates.csv
 *     php craft tidytags/duplicates --fail-on-duplicates      # CI: exit 1 when any are found
 *
 * Read-only. Runs the same {@see \justinholtweb\tidytags\services\DuplicateDetector}
 * scan as the control panel, so both report the same clusters.
 */
class DuplicatesController extends Controller
{
    use ReportOutput;

    /**
     * @var string|null Only this source: a tag group or tag-like section handle.
     * Use `group:<handle>` or `section:<handle>` when a handle names both.
     */
    public ?string $source = null;

    /**
     * @var string `within` (clusters inside each source) or `cross` (clusters
     * that span more than one source).
     */
    public string $scope = 'within';

    /**
     * @var string|null `fuzzy` or `strict`, for this run only. Defaults to the
     * `matchStrategy` setting.
     */
    public ?string $strategy = null;

    /**
     * @var int|null Levenshtein threshold for the fuzzy strategy, 0–6. Defaults to 2.
     */
    public ?int $threshold = null;

    /**
     * @var string|null Site handle whose titles are compared. Defaults to the primary site.
     */
    public ?string $site = null;

    /**
     * @var bool Exit with status 1 when any duplicates are found, for CI.
     */
    public bool $failOnDuplicates = false;

    public $defaultAction = 'index';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'source', 'scope', 'strategy', 'threshold', 'site', 'format', 'failOnDuplicates',
        ]);
    }

    /**
     * Lists clusters of near-duplicate tags and tag-like entries.
     */
    public function actionIndex(): int
    {
        if (($code = $this->checkFormat()) !== null) {
            return $code;
        }

        if (!in_array($this->scope, ['within', 'cross'], true)) {
            $this->fail('--scope must be `within` or `cross`.');
            return ExitCode::USAGE;
        }

        if ($this->strategy !== null) {
            if (!in_array($this->strategy, [TitleMatcher::STRATEGY_FUZZY, TitleMatcher::STRATEGY_STRICT], true)) {
                $this->fail('--strategy must be `fuzzy` or `strict`.');
                return ExitCode::USAGE;
            }
            // In memory only: the saved setting is untouched.
            Plugin::$plugin->getSettings()->matchStrategy = $this->strategy;
        }

        if ($this->threshold !== null && ($this->threshold < 0 || $this->threshold > 6)) {
            $this->fail('--threshold must be between 0 and 6.');
            return ExitCode::USAGE;
        }

        $site = $this->_resolveSite();
        if ($site === null) {
            return ExitCode::USAGE;
        }

        $sources = Plugin::$plugin->sources->getAllSources();
        if ($this->source !== null) {
            if ($this->scope === 'cross') {
                $this->fail('--source can\'t be combined with --scope=cross, which pools every source.');
                return ExitCode::USAGE;
            }
            $sources = Plugin::$plugin->sources->findSourcesByHandle($this->source);
            if ($sources === []) {
                $this->fail("No tag group or tag-like section has the handle “{$this->source}”.");
                return ExitCode::USAGE;
            }
            if (count($sources) > 1) {
                $this->fail("“{$this->source}” is both a tag group and a section handle. Use group:{$this->source} or section:{$this->source}.");
                return ExitCode::USAGE;
            }
        }

        $detector = Plugin::$plugin->duplicateDetector;
        $strategy = Plugin::$plugin->titleMatcher->getStrategy();
        $threshold = $this->threshold ?? $detector->defaultThreshold;

        $clusters = [];
        if ($this->scope === 'cross') {
            foreach ($detector->findCrossSourceDuplicates($site->id, $threshold) as $items) {
                $clusters[] = ['source' => null, 'items' => $items];
            }
        } else {
            foreach ($sources as $source) {
                foreach ($detector->findDuplicates($source, $site->id, $threshold) as $items) {
                    if (count($items) > 1) {
                        $clusters[] = ['source' => $source, 'items' => $items];
                    }
                }
            }
        }

        $this->_render($clusters, $strategy, $threshold, $site);

        if ($this->failOnDuplicates && $clusters !== []) {
            $this->stderr(count($clusters) . " duplicate cluster(s) found.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    private function _resolveSite(): ?Site
    {
        $sites = Craft::$app->getSites();
        if ($this->site === null) {
            return $sites->getPrimarySite();
        }
        $site = $sites->getSiteByHandle($this->site);
        if ($site === null) {
            $this->fail("No site has the handle “{$this->site}”.");
        }
        return $site;
    }

    /**
     * @param array<int, array{source: ?Source, items: array<int, array<string, mixed>>}> $clusters
     */
    private function _render(array $clusters, string $strategy, int $threshold, Site $site): void
    {
        $handles = [];
        foreach (Plugin::$plugin->sources->getAllSources() as $s) {
            $handles[$s->uid] = $s->handle;
        }

        $rows = [];
        foreach ($clusters as $n => $cluster) {
            foreach ($cluster['items'] as $item) {
                $rows[] = [
                    $n + 1,
                    $item['id'],
                    $item['title'],
                    $handles[$item['sourceUid']] ?? '',
                    $item['sourceType'],
                    $item['differentiator'],
                ];
            }
        }

        $settingsLine = sprintf(
            'Strategy %s%s, scope %s, site %s.',
            $strategy,
            $strategy === TitleMatcher::STRATEGY_FUZZY ? " (threshold $threshold)" : '',
            $this->scope,
            $site->handle,
        );

        if ($this->format === 'json') {
            $this->outputJson([
                'strategy' => $strategy,
                'threshold' => $strategy === TitleMatcher::STRATEGY_FUZZY ? $threshold : null,
                'scope' => $this->scope,
                'site' => $site->handle,
                'total' => count($clusters),
                'clusters' => array_map(fn(array $c) => [
                    'source' => $c['source'] !== null ? [
                        'handle' => $c['source']->handle,
                        'name' => $c['source']->name,
                        'type' => $c['source']->type,
                    ] : null,
                    'items' => array_map(fn(array $i) => [
                        'id' => $i['id'],
                        'title' => $i['title'],
                        'source' => $handles[$i['sourceUid']] ?? null,
                        'sourceType' => $i['sourceType'],
                        'differentiator' => $i['differentiator'],
                        'displayValues' => (object)$i['displayValues'],
                    ], $c['items']),
                ], $clusters),
            ]);
            $this->say($settingsLine);
            return;
        }

        if ($this->format === 'csv') {
            $this->outputCsv(['cluster', 'id', 'title', 'source', 'sourceType', 'differentiator'], $rows);
            $this->say(count($clusters) . ' cluster(s). ' . $settingsLine);
            return;
        }

        if ($clusters === []) {
            $this->say('No duplicates found. ' . $settingsLine);
            return;
        }

        $this->table(['Cluster', 'ID', 'Title', 'Source', 'Type', 'Differentiator'], array_map(
            fn(array $r) => array_map(fn($v) => (string)($v ?? ''), $r),
            $rows,
        ));
        $this->say(sprintf('%d cluster(s), %d item(s). %s', count($clusters), count($rows), $settingsLine));
    }
}
