<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\TidyTags\Console\Commands\Concerns\ReportOutput;
use JustinHoltWeb\TidyTags\Services\Items;

/**
 * Merges entries into one, the console twin of the Merge button. A dry run unless `--apply` is
 * passed, and `--dry-run` always wins. The console is trusted: it doesn't check a user's rights
 * over the entries whose relations move.
 */
class MergeCommand extends Command
{
    use CraftCommand;
    use ReportOutput;

    #[\Override]
    protected $signature = 'tidytags:merge
        {ids* : IDs to merge, space- or comma-separated}
        {--into= : The entry to keep. Every other entry’s relations move onto it}
        {--apply : Actually merge. Without it the command only reports}
        {--dry-run : Report without merging, even with --apply}
        {--format=table : table, json, or csv (prints only the retired-URL map)}';

    #[\Override]
    protected $description = 'Merges entries in a writable tag-like section into one; the others go to the trash.';

    /** The Craft 5 command. */
    #[\Override]
    protected $aliases = ['tidytags/merge'];

    public function handle(Items $items): int
    {
        if (! $this->checkFormat()) {
            return self::INVALID;
        }

        $into = (string) $this->option('into');
        if (! ctype_digit($into) || (int) $into === 0) {
            $this->problem('--into=<ID> is required.');

            return self::INVALID;
        }
        $into = (int) $into;

        $sourceIds = [];
        foreach ((array) $this->argument('ids') as $arg) {
            foreach (explode(',', (string) $arg) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (! ctype_digit($part) || (int) $part === 0) {
                    $this->problem("“{$part}” is not an ID.");

                    return self::INVALID;
                }
                $sourceIds[] = (int) $part;
            }
        }
        if ($sourceIds === []) {
            $this->problem("Pass the IDs of the entries to merge into #{$into}.");

            return self::INVALID;
        }

        $problems = $items->mergeProblems($sourceIds, $into);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->problem($problem);
            }
            $this->problem('Nothing was merged.');

            return self::INVALID;
        }

        $plan = $items->previewMerge($sourceIds, $into);
        $write = $this->option('apply') && ! $this->option('dry-run');

        if ($this->format() === 'json') {
            $this->outputJson($plan + ['dryRun' => ! $write]);
        } elseif ($this->format() === 'csv') {
            $this->outputCsv(['site', 'sourceId', 'sourceTitle', 'oldUrl', 'newUrl'], array_map(
                fn (array $u) => [$u['siteHandle'], $u['sourceId'], $u['sourceTitle'], $u['oldUrl'], $u['newUrl']],
                $plan['urls'],
            ));
        } else {
            $this->line(sprintf('Merge into #%d “%s”:', $plan['target']['id'], $plan['target']['title']));
            $this->table(['ID', 'Title', 'Relations', 'Move', 'Drop (target has it)'], array_map(
                fn (array $s) => [(string) $s['id'], $s['title'], (string) $s['relations'], (string) $s['moved'], (string) $s['deduped']],
                $plan['sources'],
            ));
            if ($plan['urls'] !== []) {
                $this->line(sprintf('URLs %s retired:', $write ? 'being' : 'that would be'));
                $this->table(['Site', 'ID', 'Old URL', 'New URL'], array_map(
                    fn (array $u) => [$u['siteHandle'], (string) $u['sourceId'], $u['oldUrl'], $u['newUrl'] ?? '(none)'],
                    $plan['urls'],
                ));
            }
        }

        $will = $write ? 'will be' : 'would be';
        $summary = sprintf('%d item(s) %s moved to the trash; %d relation(s) %s moved and %d duplicate relation(s) dropped.', count($plan['sources']), $will, $plan['moved'], $will, $plan['deduped']);

        if (! $write) {
            $this->say($summary);
            $this->say('Dry run — nothing changed. Re-run with --apply to merge.', 'comment');

            return self::SUCCESS;
        }

        if (! $items->merge($sourceIds, $into)['success']) {
            $this->problem('The merge failed and was rolled back; nothing changed. See the log for the error.');

            return self::FAILURE;
        }

        $this->say(sprintf('Merged %d item(s) into #%d: %d relation(s) moved, %d duplicate relation(s) dropped.', count($plan['sources']), $into, $plan['moved'], $plan['deduped']), 'info');

        return self::SUCCESS;
    }
}
