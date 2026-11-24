<?php

namespace justinholtweb\tidytags\console\controllers;

use Craft;
use craft\console\Controller;
use craft\fields\Tags as TagsField;
use craft\helpers\Console;
use justinholtweb\tidytags\console\ReportOutput;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use yii\console\ExitCode;

/**
 * A pre-flight check before converting tag groups to entry sections.
 *
 *     php craft tidytags/entrify-check
 *     php craft tidytags/entrify-check --format=json
 *     php craft tidytags/entrify-check --fail-on-issues   # exit 1 while any group needs cleaning
 *
 * Tags are deprecated in Craft 6, and an upgrade may convert tag groups to
 * entry sections automatically. Converting them yourself on Craft 5 with
 * `php craft entrify/tags` lets you choose the section and entry type, and
 * cleaning up first means the duplicates and dead tags don't come along.
 *
 * For each tag group this reports how many tags it has, how many nothing uses,
 * how many duplicate clusters the current match strategy finds, and which Tags
 * fields point at it. Read-only: it never changes anything.
 */
class EntrifyCheckController extends Controller
{
    use ReportOutput;

    /**
     * @var bool Exit 1 when any tag group still has duplicates or unused tags.
     */
    public bool $failOnIssues = false;

    public $defaultAction = 'index';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['format', 'failOnIssues']);
    }

    /**
     * Reports how ready each tag group is to be converted to entries.
     */
    public function actionIndex(): int
    {
        if (($code = $this->checkFormat()) !== null) {
            return $code;
        }

        $sourcesService = Plugin::$plugin->sources;
        $detector = Plugin::$plugin->duplicateDetector;
        $fieldsBySource = $this->_tagFieldsByGroupUid();

        $groups = [];
        foreach ($sourcesService->getAllSources() as $source) {
            if ($source->type !== Source::TYPE_TAG) {
                continue;
            }

            $clusters = array_filter(
                $detector->findDuplicates($source),
                fn(array $items) => count($items) > 1,
            );

            $groups[] = [
                'handle' => $source->handle,
                'name' => $source->name,
                'tags' => $sourcesService->getTotalCount($source),
                'unused' => count($sourcesService->getUnused($source)),
                'duplicateClusters' => count($clusters),
                'fields' => $fieldsBySource[$source->uid] ?? [],
            ];
        }

        $issues = array_filter($groups, fn(array $g) => $g['unused'] > 0 || $g['duplicateClusters'] > 0);

        $this->_render($groups);

        if ($groups === []) {
            $this->say('No tag groups left. Nothing to convert.', Console::FG_GREEN);
            return ExitCode::OK;
        }

        $this->say('');
        foreach ($groups as $g) {
            $this->say($this->_nextSteps($g));
        }
        $this->say('');
        $this->say('After converting, add the new section under Settings → Tidy Tags as a tag-like section, and allow rename, merge and delete for it to keep cleaning it here.');

        if ($this->failOnIssues && $issues !== []) {
            $this->stderr(count($issues) . " tag group(s) still need cleaning.\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * @param array<int, array{handle: string, name: string, tags: int, unused: int, duplicateClusters: int, fields: string[]}> $groups
     */
    private function _render(array $groups): void
    {
        if ($this->format === 'json') {
            $this->outputJson(['groups' => $groups]);
            return;
        }

        $rows = array_map(fn(array $g) => [
            $g['handle'],
            $g['tags'],
            $g['unused'],
            $g['duplicateClusters'],
            implode(' ', $g['fields']),
        ], $groups);

        if ($this->format === 'csv') {
            $this->outputCsv(['group', 'tags', 'unused', 'duplicateClusters', 'fields'], $rows);
            return;
        }

        if ($groups === []) {
            return;
        }

        $this->table(
            ['Group', 'Tags', 'Unused', 'Duplicate clusters', 'Tags fields'],
            array_map(fn(array $r) => array_map(fn($v) => (string)$v, $r), $rows),
        );
    }

    /**
     * The commands to run for one group, in order.
     *
     * @param array{handle: string, tags: int, unused: int, duplicateClusters: int} $g
     */
    private function _nextSteps(array $g): string
    {
        $steps = [];
        if ($g['duplicateClusters'] > 0) {
            $steps[] = "review duplicates: php craft tidytags/duplicates --source=group:{$g['handle']}";
        }
        if ($g['unused'] > 0) {
            $steps[] = "prune unused tags: php craft tidytags/unused --source=group:{$g['handle']} --delete --apply";
        }
        $steps[] = "convert: php craft entrify/tags {$g['handle']}";

        return "{$g['handle']}: " . implode('; then ', $steps) . '.';
    }

    /**
     * Handles of the Tags fields that point at each tag group, keyed by the
     * group's UID.
     *
     * @return array<string, string[]>
     */
    private function _tagFieldsByGroupUid(): array
    {
        $out = [];
        foreach (Craft::$app->getFields()->getAllFields() as $field) {
            if ($field instanceof TagsField && is_string($field->source) && str_starts_with($field->source, 'taggroup:')) {
                $out[substr($field->source, strlen('taggroup:'))][] = $field->handle;
            }
        }
        return $out;
    }
}
