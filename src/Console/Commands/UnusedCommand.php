<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Console\Commands;

use CraftCms\Cms\Console\CraftCommand;
use Illuminate\Console\Command;
use JustinHoltWeb\TidyTags\Console\Commands\Concerns\ReportOutput;
use JustinHoltWeb\TidyTags\Data\Source;
use JustinHoltWeb\TidyTags\Services\Items;
use JustinHoltWeb\TidyTags\Services\Sources;

/**
 * Lists — and optionally trashes — entries in tag-like sections that nothing relates to. Deletes
 * only from sections that allow it; `--delete` is a dry run unless `--apply` is passed.
 */
class UnusedCommand extends Command
{
    use CraftCommand;
    use ReportOutput;

    #[\Override]
    protected $signature = 'tidytags:unused
        {--source= : Only this tag-like section, by handle}
        {--delete : Move the unused entries to the trash (a dry run unless --apply is passed too)}
        {--apply : With --delete, actually delete}
        {--dry-run : Report without deleting, even with --apply}
        {--format=table : table, json or csv}';

    #[\Override]
    protected $description = 'Lists entries in tag-like sections that nothing relates to, and optionally trashes them.';

    /** The Craft 5 command. */
    #[\Override]
    protected $aliases = ['tidytags/unused'];

    public function handle(Sources $sources, Items $items): int
    {
        if (! $this->checkFormat()) {
            return self::INVALID;
        }

        if ($this->option('apply') && ! $this->option('delete')) {
            $this->problem('--apply only applies to --delete.');

            return self::INVALID;
        }

        $selected = $sources->getAllSources();
        if (($handle = $this->option('source')) !== null) {
            $selected = $sources->findSourcesByHandle((string) $handle);
            if ($selected === []) {
                $this->problem("No tag-like section has the handle “{$handle}”.");

                return self::INVALID;
            }
            if ($this->option('delete') && ! $selected[0]->isWritable()) {
                $this->problem("“{$selected[0]->name}” is read-only. Allow rename, merge and delete for it in the Tidy Tags settings.");

                return self::INVALID;
            }
        }

        $rows = [];
        $deletable = [];
        $readOnly = 0;
        foreach ($selected as $source) {
            foreach ($sources->getUnused($source) as $id => $title) {
                $rows[] = ['source' => $source, 'id' => $id, 'title' => $title];
                if ($source->isWritable()) {
                    $deletable[] = $id;
                } else {
                    $readOnly++;
                }
            }
        }

        $this->renderList($rows);

        if (! $this->option('delete')) {
            return self::SUCCESS;
        }

        if ($readOnly > 0) {
            $this->say("{$readOnly} unused entr".($readOnly === 1 ? 'y' : 'ies').' in read-only sections are listed but never deleted.');
        }

        if ($deletable === []) {
            $this->say('No unused items to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('apply') || $this->option('dry-run')) {
            $this->say(count($deletable).' unused item(s) would be moved to the trash.');
            $this->say('Dry run — nothing changed. Re-run with --apply to delete them.', 'comment');

            return self::SUCCESS;
        }

        $result = $items->deleteUnused($deletable);

        $this->say(count($result['deleted']).' unused item(s) moved to the trash.', 'info');
        if ($result['skipped'] !== []) {
            $this->say(count($result['skipped']).' item(s) gained a relation since the scan and were kept: #'.implode(', #', $result['skipped']).'.');
        }
        if ($result['failed'] !== []) {
            $this->problem(count($result['failed']).' item(s) could not be deleted: #'.implode(', #', $result['failed']).'. See the log.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{source: Source, id: int, title: string}>  $rows
     */
    private function renderList(array $rows): void
    {
        $flat = array_map(fn (array $r) => [$r['id'], $r['title'], $r['source']->handle], $rows);

        if ($this->format() === 'json') {
            $this->outputJson([
                'total' => count($rows),
                'items' => array_map(fn (array $r) => ['id' => $r['id'], 'title' => $r['title'], 'source' => $r['source']->handle], $rows),
            ]);

            return;
        }

        if ($this->format() === 'csv') {
            $this->outputCsv(['id', 'title', 'source'], $flat);

            return;
        }

        if ($rows === []) {
            $this->say('No unused entries in tag-like sections.');

            return;
        }

        $this->table(['ID', 'Title', 'Section'], array_map(fn (array $r) => array_map(fn ($v) => (string) $v, $r), $flat));
        $this->say(count($rows).' unused item(s).');
    }
}
