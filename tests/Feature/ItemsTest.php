<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Drafts;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\TidyTags\Services\Items;
use JustinHoltWeb\TidyTags\Services\Sources;

function items(): Items
{
    return app(Items::class);
}

it('leaves entries in read-only and unconfigured sections alone', function () {
    $teams = $this->createSection('teams');
    $news = $this->createSection('news');
    $team = $this->createEntry($teams, 'Arsenal');
    $article = $this->createEntry($news, 'Match report');
    $this->configure([$teams]);

    expect(items()->getWritableItem($team->id))->toBeNull()
        ->and(items()->getWritableItem($article->id))->toBeNull()
        ->and(items()->rename($team->id, 'Arsenal FC'))->toBeFalse()
        ->and(items()->delete($team->id))->toBeFalse()
        ->and(app(Sources::class)->getSource($teams->id)?->isWritable())->toBeFalse()
        ->and(app(Sources::class)->getSource($news->id))->toBeNull();
});

it('renames an entry in a writable section', function () {
    $teams = $this->createSection('teams');
    $entry = $this->createEntry($teams, 'Arsenal');
    $this->configure([$teams], [$teams]);

    expect(items()->rename($entry->id, '  Arsenal FC  '))->toBeTrue()
        ->and(Entry::find()->id($entry->id)->status(null)->one()->title)->toBe('Arsenal FC')
        ->and(items()->rename($entry->id, '   '))->toBeFalse();
});

it('moves deleted entries to the trash', function () {
    $teams = $this->createSection('teams');
    $entry = $this->createEntry($teams, 'Arsenal');
    $this->configure([$teams], [$teams]);

    expect(items()->delete($entry->id))->toBeTrue()
        ->and(Entry::find()->id($entry->id)->status(null)->one())->toBeNull()
        ->and($this->trashed($entry->id))->not->toBeNull();
});

it('merges: relations move, duplicates drop, sources go to the trash', function () {
    $teams = $this->createSection('teams');
    $news = $this->createSection('news');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenal FC');
    $a = $this->createEntry($news, 'Report A');
    $b = $this->createEntry($news, 'Report B');
    $this->configure([$teams], [$teams]);

    $this->relate($a->id, $source->id);
    $this->relate($b->id, $source->id);
    $this->relate($b->id, $target->id);

    $preview = items()->previewMerge([$source->id], $target->id);
    expect($preview['moved'])->toBe(1)->and($preview['deduped'])->toBe(1);

    expect(items()->merge([$source->id], $target->id)['success'])->toBeTrue()
        ->and($this->trashed($source->id))->not->toBeNull()
        ->and(DB::table('relations')->where('targetId', $source->id)->count())->toBe(0)
        ->and(DB::table('relations')->where('targetId', $target->id)->pluck('sourceId')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe([$a->id, $b->id]);
});

it('reports the URLs a merge retires', function () {
    $teams = $this->createSection('teams', 'teams/{slug}');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenal FC');
    $this->configure([$teams], [$teams]);

    $preview = items()->previewMerge([$source->id], $target->id);

    expect($preview['urls'])->toHaveCount(1)
        ->and($preview['urls'][0]['sourceId'])->toBe($source->id)
        ->and($preview['urls'][0]['oldUrl'])->toEndWith('teams/arsenal-fc')
        ->and($preview['urls'][0]['newUrl'])->toEndWith('teams/arsenal')
        ->and(items()->merge([$source->id], $target->id)['urls'])->toBe($preview['urls']);
});

it('refuses merges across sections, into read-only entries, or of drafts', function () {
    $teams = $this->createSection('teams');
    $clubs = $this->createSection('clubs');
    $target = $this->createEntry($teams, 'Arsenal');
    $other = $this->createEntry($clubs, 'Arsenal');
    $this->configure([$teams, $clubs], [$teams, $clubs]);

    expect(items()->mergeProblems([$other->id], $target->id)[0])->toContain('different section')
        ->and(items()->merge([$other->id], $target->id)['success'])->toBeFalse()
        ->and(Entry::find()->id($other->id)->status(null)->one())->not->toBeNull();

    $draft = Drafts::createDraft($target, $this->admin()->id);
    expect(items()->getWritableItem((int) $draft->id))->toBeNull()
        ->and(items()->mergeProblems([(int) $draft->id], $target->id))->not->toBeEmpty();

    $this->configure([$teams]);
    expect(items()->mergeProblems([$target->id], $other->id)[0])->toContain('not an entry in a writable tag-like section');
});

it('trashes only entries that are still unused', function () {
    $teams = $this->createSection('teams');
    $news = $this->createSection('news');
    $used = $this->createEntry($teams, 'Arsenal');
    $unused = $this->createEntry($teams, 'Wrexham');
    $article = $this->createEntry($news, 'Report');
    $this->configure([$teams], [$teams]);
    $this->relate($article->id, $used->id);

    expect(app(Sources::class)->getUnused(app(Sources::class)->getSource($teams->id)))->toBe([$unused->id => 'Wrexham']);

    $result = items()->deleteUnused([$used->id, $unused->id]);

    expect($result['deleted'])->toBe([$unused->id])
        ->and($result['skipped'])->toBe([$used->id])
        ->and($this->trashed($unused->id))->not->toBeNull();
});

it('checks entry permissions before a merge or delete', function () {
    $teams = $this->createSection('teams');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenal FC');
    $this->configure([$teams], [$teams]);

    $editor = $this->userWith(["viewEntries:{$teams->uid}", "saveEntries:{$teams->uid}", "viewPeerEntries:{$teams->uid}", "savePeerEntries:{$teams->uid}"]);

    expect(items()->canMergeItems([$source->id], $target->id, $editor))->toBeFalse()
        ->and(items()->canDeleteItems([$source->id], $editor))->toBeFalse()
        ->and(items()->canMergeItems([$source->id], $target->id, $this->admin()))->toBeTrue();
});
