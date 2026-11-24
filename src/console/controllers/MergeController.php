<?php

namespace justinholtweb\tidytags\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\tidytags\console\ReportOutput;
use justinholtweb\tidytags\Plugin;
use yii\console\ExitCode;

/**
 * Merges items into one, the console twin of the Merge button.
 *
 *     php craft tidytags/merge --into=12 34 56                     # dry run: shows what would move
 *     php craft tidytags/merge --into=12 34,56 --apply             # merges
 *     php craft tidytags/merge --into=12 34 --apply --format=csv   # merges; prints the retired URLs as CSV
 *
 * Works on tags and on entries in writable tag-like sections. Every item must
 * be in the target's tag group or section, and for entries the target must
 * exist in every site the others do. Merged entries go to the trash.
 *
 * A dry run unless `--apply` is passed (and `--dry-run` always wins). With
 * `--format=csv` the output is the URL map (site, sourceId, sourceTitle,
 * oldUrl, newUrl), ready for a redirect tool. Exits 0 on success or a clean
 * dry run, 64 on bad arguments, 65 when the items can't be merged and 1 when
 * the merge itself fails (it runs in a transaction, so nothing changes).
 *
 * The console is trusted: unlike the control panel action, this doesn't check
 * that a user can save every entry whose relations move.
 */
class MergeController extends Controller
{
    use ReportOutput;

    /**
     * @var int|null The item to keep. Every other item's relations move onto it.
     */
    public ?int $into = null;

    /**
     * @var bool Actually merge. Without it the command only reports.
     */
    public bool $apply = false;

    /**
     * @var bool Report without merging, even when --apply is passed.
     */
    public bool $dryRun = false;

    public $defaultAction = 'index';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['into', 'apply', 'dryRun', 'format']);
    }

    /**
     * Merges the given item IDs into the --into item, deleting them.
     *
     * @param string ...$ids IDs to merge, space- or comma-separated.
     */
    public function actionIndex(string ...$ids): int
    {
        if (($code = $this->checkFormat()) !== null) {
            return $code;
        }
        if ($this->into === null || $this->into <= 0) {
            $this->fail('--into=<ID> is required.');
            return ExitCode::USAGE;
        }

        $sourceIds = [];
        foreach ($ids as $arg) {
            foreach (explode(',', $arg) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (!ctype_digit($part) || (int)$part === 0) {
                    $this->fail("“{$part}” is not an ID.");
                    return ExitCode::USAGE;
                }
                $sourceIds[] = (int)$part;
            }
        }
        if ($sourceIds === []) {
            $this->fail('Pass the IDs of the items to merge into #' . $this->into . '.');
            return ExitCode::USAGE;
        }

        $tags = Plugin::$plugin->tags;
        $problems = $tags->mergeProblems($sourceIds, $this->into);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->fail($problem);
            }
            $this->fail('Nothing was merged.');
            return ExitCode::DATAERR;
        }

        $plan = $tags->previewMerge($sourceIds, $this->into);
        $write = $this->apply && !$this->dryRun;

        if ($this->format === 'json') {
            $this->outputJson($plan + ['dryRun' => !$write]);
        } elseif ($this->format === 'csv') {
            $this->outputCsv(['site', 'sourceId', 'sourceTitle', 'oldUrl', 'newUrl'], array_map(
                fn(array $u) => [$u['siteHandle'], $u['sourceId'], $u['sourceTitle'], $u['oldUrl'], $u['newUrl']],
                $plan['urls'],
            ));
        } else {
            $this->say(sprintf('Merge into #%d “%s”:', $plan['target']['id'], $plan['target']['title']));
            $this->table(['ID', 'Title', 'Relations', 'Move', 'Drop (target has it)'], array_map(
                fn(array $s) => [(string)$s['id'], $s['title'], (string)$s['relations'], (string)$s['moved'], (string)$s['deduped']],
                $plan['sources'],
            ));
            if ($plan['urls'] !== []) {
                $this->say(sprintf('URLs %s retired:', $write ? 'being' : 'that would be'));
                $this->table(['Site', 'ID', 'Old URL', 'New URL'], array_map(
                    fn(array $u) => [$u['siteHandle'], (string)$u['sourceId'], $u['oldUrl'], $u['newUrl'] ?? '(none)'],
                    $plan['urls'],
                ));
            }
        }

        $summary = sprintf(
            '%d item(s) %s deleted; %d relation(s) %s moved and %d duplicate relation(s) dropped.',
            count($plan['sources']),
            $write ? 'will be' : 'would be',
            $plan['moved'],
            $write ? 'will be' : 'would be',
            $plan['deduped'],
        );

        if (!$write) {
            $this->say($summary);
            $this->say('Dry run — nothing changed. Re-run with --apply to merge.', Console::FG_YELLOW);
            return ExitCode::OK;
        }

        if (!$tags->merge($sourceIds, $this->into)['success']) {
            $this->fail('The merge failed and was rolled back; nothing changed. See the Craft log for the error.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->say(sprintf(
            'Merged %d item(s) into #%d: %d relation(s) moved, %d duplicate relation(s) dropped.',
            count($plan['sources']),
            $this->into,
            $plan['moved'],
            $plan['deduped'],
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
