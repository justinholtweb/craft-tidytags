<?php

namespace justinholtweb\tidytagstests\unit\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\models\Section;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

/**
 * Rename, delete and merge on entries in tag-like sections: off by default,
 * on once a section is opted into `writableSectionUids`, with entries going to
 * the trash and merges reporting the URLs they retire.
 */
class EntryItemsTest extends PluginTestCase
{
    private function makeWritable(Section ...$sections): void
    {
        $uids = array_map(fn(Section $s) => $s->uid, $sections);
        $this->setPluginSettings([
            'tagLikeSectionUids' => $uids,
            'writableSectionUids' => $uids,
        ]);
    }

    private function trashed(int $id): ?Entry
    {
        return Entry::find()->id($id)->status(null)->trashed()->one();
    }

    public function testEntriesInReadOnlySectionsAreNotWritable(): void
    {
        $section = Fixtures::createChannelSection('teams');
        $entry = Fixtures::createEntry($section, 'Arsenal');
        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        self::assertNull($this->tags()->getWritableItem($entry->id));
        self::assertFalse($this->tags()->rename($entry->id, 'Arsenal FC'));
        self::assertFalse($this->tags()->delete($entry->id));
        self::assertNotNull(Entry::find()->id($entry->id)->status(null)->one());
    }

    public function testEntriesOutsideTagLikeSectionsAreNotWritable(): void
    {
        $section = Fixtures::createChannelSection('news');
        $entry = Fixtures::createEntry($section, 'Article');
        // Writable without being tag-like is filtered out.
        $settings = $this->setPluginSettings(['writableSectionUids' => [$section->uid]]);
        $settings->validate();

        self::assertSame([], $settings->writableSectionUids);
        self::assertNull($this->tags()->getWritableItem($entry->id));
    }

    public function testSourcesReportWritableFlag(): void
    {
        $writable = Fixtures::createChannelSection('teams');
        $readOnly = Fixtures::createChannelSection('competitions');
        $this->setPluginSettings([
            'tagLikeSectionUids' => [$writable->uid, $readOnly->uid],
            'writableSectionUids' => [$writable->uid],
        ]);

        self::assertTrue($this->sources()->getEntrySource($writable->id)->isWritable());
        self::assertFalse($this->sources()->getEntrySource($readOnly->id)->isWritable());
    }

    public function testRenameEntryInWritableSection(): void
    {
        $section = Fixtures::createChannelSection('teams');
        $entry = Fixtures::createEntry($section, 'Arsenal');
        $this->makeWritable($section);

        self::assertTrue($this->tags()->rename($entry->id, 'Arsenal FC'));
        self::assertSame('Arsenal FC', Entry::find()->id($entry->id)->status(null)->one()->title);
    }

    public function testDeleteEntrySoftDeletes(): void
    {
        $section = Fixtures::createChannelSection('teams');
        $entry = Fixtures::createEntry($section, 'Arsenal');
        $this->makeWritable($section);

        self::assertTrue($this->tags()->delete($entry->id));
        self::assertNull(Entry::find()->id($entry->id)->status(null)->one());
        self::assertNotNull($this->trashed($entry->id), 'Deleted entries go to the trash.');
    }

    public function testMergeEntriesRepointsRelationsAndTrashesSources(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $target = Fixtures::createEntry($teams, 'Arsenal');
        $source = Fixtures::createEntry($teams, 'Arsenal FC');
        $this->makeWritable($teams);

        $field = Fixtures::createPlainTextField('relatedTeams');
        $news = Fixtures::createChannelSection('news');
        $article = Fixtures::createEntry($news, 'Match report');
        Fixtures::relate($field->id, $article->id, $article->siteId, $source->id);

        self::assertSame([], $this->tags()->mergeProblems([$source->id], $target->id));
        $result = $this->tags()->merge([$source->id], $target->id);

        self::assertTrue($result['success']);
        self::assertSame([], $result['urls'], 'A section without URLs retires none.');
        self::assertNotNull($this->trashed($source->id));
        self::assertSame(
            [$target->id],
            array_map('intval', (new Query())->select(['targetId'])->from(['{{%relations}}'])->where(['sourceId' => $article->id])->column()),
        );
    }

    public function testMergeReportsRetiredUrls(): void
    {
        $teams = Fixtures::createChannelSection('teams', [], 'teams/{slug}');
        $target = Fixtures::createEntry($teams, 'Arsenal');
        $source = Fixtures::createEntry($teams, 'Arsenal FC');
        $this->makeWritable($teams);

        $preview = $this->tags()->previewMerge([$source->id], $target->id);
        self::assertCount(1, $preview['urls']);
        self::assertSame($source->id, $preview['urls'][0]['sourceId']);
        self::assertStringEndsWith('teams/arsenal-fc', $preview['urls'][0]['oldUrl']);
        self::assertStringEndsWith('teams/arsenal', $preview['urls'][0]['newUrl']);

        $result = $this->tags()->merge([$source->id], $target->id);
        self::assertTrue($result['success']);
        self::assertSame($preview['urls'], $result['urls']);
    }

    public function testMergeAcrossSectionsIsRefused(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $clubs = Fixtures::createChannelSection('clubs');
        $target = Fixtures::createEntry($teams, 'Arsenal');
        $source = Fixtures::createEntry($clubs, 'Arsenal');
        $this->makeWritable($teams, $clubs);

        $problems = $this->tags()->mergeProblems([$source->id], $target->id);
        self::assertCount(1, $problems);
        self::assertStringContainsString('different section', $problems[0]);
        self::assertFalse($this->tags()->merge([$source->id], $target->id)['success']);
        self::assertNotNull(Entry::find()->id($source->id)->status(null)->one());
    }

    public function testMergeIntoReadOnlyTargetIsRefused(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $target = Fixtures::createEntry($teams, 'Arsenal');
        $source = Fixtures::createEntry($teams, 'Arsenal FC');
        $this->setPluginSettings(['tagLikeSectionUids' => [$teams->uid]]);

        $problems = $this->tags()->mergeProblems([$source->id], $target->id);
        self::assertStringContainsString('is not a tag or an entry in a writable section', $problems[0]);
    }

    public function testDraftsAreNotWritable(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $entry = Fixtures::createEntry($teams, 'Arsenal');
        $this->makeWritable($teams);

        $draft = Craft::$app->getDrafts()->createDraft($entry, null, 'Draft');

        self::assertNull($this->tags()->getWritableItem($draft->id));
        self::assertNotEmpty($this->tags()->mergeProblems([$draft->id], $entry->id));
    }

    public function testMergeRefusesTargetMissingFromASourceSite(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $teams = Fixtures::createChannelSection('teams', [], null, null, 'none');

        $target = Fixtures::createEntry($teams, 'Arsenal', [], $primaryId);
        $source = Fixtures::createEntry($teams, 'Arsenal', [], $second->id);
        $this->makeWritable($teams);

        $problems = $this->tags()->mergeProblems([$source->id], $target->id);
        self::assertCount(1, $problems);
        self::assertStringContainsString('exists in Secondary', $problems[0]);
        self::assertFalse($this->tags()->merge([$source->id], $target->id)['success']);
    }

    public function testMergeRefusesRelationsInASiteTheTargetIsMissingFrom(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;
        $teams = Fixtures::createChannelSection('teams', [], null, null, 'none');

        $target = Fixtures::createEntry($teams, 'Arsenal', [], $primaryId);
        $source = Fixtures::createEntry($teams, 'Arsenal FC', [], $primaryId);
        $this->makeWritable($teams);

        $field = Fixtures::createPlainTextField('relatedTeams');
        $news = Fixtures::createChannelSection('news');
        $article = Fixtures::createEntry($news, 'Match report');
        Fixtures::relate($field->id, $article->id, $second->id, $source->id);

        $problems = $this->tags()->mergeProblems([$source->id], $target->id);
        self::assertCount(1, $problems);
        self::assertStringContainsString('1 relation(s) in Secondary', $problems[0]);
    }

    public function testDeleteUnusedTrashesOnlyUnusedEntries(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $used = Fixtures::createEntry($teams, 'Arsenal');
        $unused = Fixtures::createEntry($teams, 'Wrexham');
        $this->makeWritable($teams);

        $field = Fixtures::createPlainTextField('relatedTeams');
        $news = Fixtures::createChannelSection('news');
        $article = Fixtures::createEntry($news, 'Match report');
        Fixtures::relate($field->id, $article->id, $article->siteId, $used->id);

        $result = $this->tags()->deleteUnused([$used->id, $unused->id]);

        self::assertSame([$unused->id], $result['deleted']);
        self::assertSame([$used->id], $result['skipped']);
        self::assertNotNull($this->trashed($unused->id));
    }

    public function testCanMergeItemsNeedsEntryDeleteRights(): void
    {
        $teams = Fixtures::createChannelSection('teams');
        $target = Fixtures::createEntry($teams, 'Arsenal');
        $source = Fixtures::createEntry($teams, 'Arsenal FC');
        $this->makeWritable($teams);

        $editor = Fixtures::createUser('editor', false, [
            'accessPlugin-tidytags',
            "viewEntries:{$teams->uid}",
            "saveEntries:{$teams->uid}",
            "viewPeerEntries:{$teams->uid}",
            "savePeerEntries:{$teams->uid}",
        ]);
        $admin = Fixtures::createUser('admin', true);

        self::assertFalse($this->tags()->canMergeItems([$source->id], $target->id, $editor));
        self::assertTrue($this->tags()->canMergeItems([$source->id], $target->id, $admin));
    }
}
