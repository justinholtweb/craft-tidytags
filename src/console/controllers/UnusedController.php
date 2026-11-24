<?php

namespace justinholtweb\tidytags\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\tidytags\console\ReportOutput;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;
use yii\console\ExitCode;

/**
 * Lists — and optionally prunes — tags and tag-like entries that nothing
 * relates to.
 *
 *     php craft tidytags/unused                          # every source
 *     php craft tidytags/unused --source=topics --format=csv
 *     php craft tidytags/unused --delete                 # dry run: lists what would go
 *     php craft tidytags/unused --delete --apply         # deletes
 *
 * "Unused" means no row in `{{%relations}}` targets the item, from any field,
 * element or site. Entries are deleted only from sections that allow it
 * (`writableSectionUids`), and go to the trash; other entry-backed sources are
 * listed but never deleted.
 *
 * Deleting is a dry run unless `--apply` is passed (and `--dry-run` always
 * wins). Each item is re-checked just before it is deleted, so one that gained
 * a relation since the scan is skipped. Exits 1 if any delete fails.
 */
class UnusedController extends Controller
{
    use ReportOutput;

    /**
     * @var string|null Only this source: a tag group or tag-like section handle.
     * Use `group:<handle>` or `section:<handle>` when a handle names both.
     */
    public ?string $source = null;

    /**
     * @var bool Delete the unused items (a dry run unless --apply is passed too).
     */
    public bool $delete = false;

    /**
     * @var bool With --delete, actually delete. Without it --delete only reports.
     */
    public bool $apply = false;

    /**
     * @var bool Report without deleting, even when --apply is passed.
     */
    public bool $dryRun = false;

    public $defaultAction = 'index';

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['source', 'delete', 'apply', 'dryRun', 'format']);
    }

    /**
     * Lists unused tags and tag-like entries; with --delete, prunes the ones in
     * writable sources.
     */
    public function actionIndex(): int
    {
        if (($code = $this->checkFormat()) !== null) {
            return $code;
        }

        if ($this->apply && !$this->delete) {
            $this->fail('--apply only applies to --delete.');
            return ExitCode::USAGE;
        }

        $sourcesService = Plugin::$plugin->sources;
        $sources = $sourcesService->getAllSources();
        if ($this->source !== null) {
            $sources = $sourcesService->findSourcesByHandle($this->source);
            if ($sources === []) {
                $this->fail("No tag group or tag-like section has the handle “{$this->source}”.");
                return ExitCode::USAGE;
            }
            if (count($sources) > 1) {
                $this->fail("“{$this->source}” is both a tag group and a section handle. Use group:{$this->source} or section:{$this->source}.");
                return ExitCode::USAGE;
            }
            if ($this->delete && !$sources[0]->isWritable()) {
                $this->fail("“{$sources[0]->name}” is read-only. Allow rename, merge and delete for it in the Tidy Tags settings, or use Craft's entry index.");
                return ExitCode::USAGE;
            }
        }

        $rows = [];
        $deletableIds = [];
        $readOnlyCount = 0;
        $trashCount = 0;
        foreach ($sources as $source) {
            foreach ($sourcesService->getUnused($source) as $id => $title) {
                $rows[] = ['source' => $source, 'id' => $id, 'title' => $title];
                if ($source->isWritable()) {
                    $deletableIds[] = $id;
                    if ($source->softDeletes()) {
                        $trashCount++;
                    }
                } else {
                    $readOnlyCount++;
                }
            }
        }

        $this->_renderList($rows);

        if (!$this->delete) {
            return ExitCode::OK;
        }

        if ($readOnlyCount > 0) {
            $this->say("$readOnlyCount unused entr" . ($readOnlyCount === 1 ? 'y' : 'ies') . ' in read-only sources are listed but never deleted.');
        }

        if ($deletableIds === []) {
            $this->say('No unused items to delete.');
            return ExitCode::OK;
        }

        $trashNote = $trashCount > 0 ? " ({$trashCount} entr" . ($trashCount === 1 ? 'y' : 'ies') . ' to the trash)' : '';

        if (!$this->apply || $this->dryRun) {
            $this->say(count($deletableIds) . " unused item(s) would be deleted{$trashNote}.");
            $this->say('Dry run — nothing changed. Re-run with --apply to delete them.', Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $result = Plugin::$plugin->tags->deleteUnused($deletableIds);

        $this->say(count($result['deleted']) . " unused item(s) deleted{$trashNote}.", Console::FG_GREEN);
        if ($result['skipped'] !== []) {
            $this->say(count($result['skipped']) . ' item(s) gained a relation since the scan and were kept: #' . implode(', #', $result['skipped']) . '.');
        }
        if ($result['failed'] !== []) {
            $this->fail(count($result['failed']) . ' item(s) could not be deleted: #' . implode(', #', $result['failed']) . '. See the Craft log.');
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * @param array<int, array{source: Source, id: int, title: string}> $rows
     */
    private function _renderList(array $rows): void
    {
        $flat = array_map(fn(array $r) => [$r['id'], $r['title'], $r['source']->handle, $r['source']->type], $rows);

        if ($this->format === 'json') {
            $this->outputJson([
                'total' => count($rows),
                'items' => array_map(fn(array $r) => [
                    'id' => $r['id'],
                    'title' => $r['title'],
                    'source' => $r['source']->handle,
                    'sourceType' => $r['source']->type,
                ], $rows),
            ]);
            return;
        }

        if ($this->format === 'csv') {
            $this->outputCsv(['id', 'title', 'source', 'sourceType'], $flat);
            return;
        }

        if ($rows === []) {
            $this->say('No unused tags or tag-like entries.');
            return;
        }

        $this->table(['ID', 'Title', 'Source', 'Type'], array_map(
            fn(array $r) => array_map(fn($v) => (string)$v, $r),
            $flat,
        ));
        $this->say(count($rows) . ' unused item(s).');
    }
}
