<?php

namespace justinholtweb\tidytagstests\unit\services;

use Craft;
use craft\db\Query;
use craft\elements\Tag;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

class TagsTest extends PluginTestCase
{
    public function testRenameTagAcrossAllSites(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertTrue($this->tags()->renameTag($tag->id, 'Feline'));

        foreach ([Craft::$app->getSites()->getPrimarySite()->id, $second->id] as $siteId) {
            $reloaded = Tag::find()->id($tag->id)->siteId($siteId)->status(null)->one();
            self::assertSame('Feline', $reloaded->title);
        }
    }

    public function testRenameTagForASingleSiteLeavesOtherSitesAlone(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;

        self::assertTrue($this->tags()->renameTag($tag->id, 'Chat', $second->id));

        self::assertSame(
            'Chat',
            Tag::find()->id($tag->id)->siteId($second->id)->status(null)->one()->title,
        );
        self::assertSame(
            'Cat',
            Tag::find()->id($tag->id)->siteId($primaryId)->status(null)->one()->title,
        );
    }

    public function testRenameTagTrimsWhitespace(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertTrue($this->tags()->renameTag($tag->id, '  Feline  '));
        self::assertSame('Feline', Tag::find()->id($tag->id)->status(null)->one()->title);
    }

    public function testRenameTagRejectsBlankTitles(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertFalse($this->tags()->renameTag($tag->id, '   '));
        self::assertSame('Cat', Tag::find()->id($tag->id)->status(null)->one()->title);
    }

    public function testRenameTagReturnsFalseForUnknownTagInSingleSiteMode(): void
    {
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        self::assertFalse($this->tags()->renameTag(999999, 'Nope', $siteId));
    }

    /**
     * The all-sites branch must not report success when the tag ID resolves to
     * nothing — otherwise the CP shows "Tag renamed." after a no-op.
     */
    public function testRenameTagReturnsFalseForUnknownTagAcrossAllSites(): void
    {
        self::assertFalse($this->tags()->renameTag(999999, 'Nope'));
    }

    public function testDeleteTag(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertTrue($this->tags()->deleteTag($tag->id));
        self::assertNull(Tag::find()->id($tag->id)->status(null)->one());
    }

    public function testDeleteTagReturnsFalseForUnknownTag(): void
    {
        self::assertFalse($this->tags()->deleteTag(999999));
    }

    public function testDeleteTagsReturnsDeletedCount(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $cat = Fixtures::createTag($group, 'Cat');
        $dog = Fixtures::createTag($group, 'Dog');

        self::assertSame(2, $this->tags()->deleteTags([$cat->id, $dog->id, 999999]));
        self::assertSame(0, (int)Tag::find()->groupId($group->id)->status(null)->count());
    }

    public function testMergeTagsRepointsRelationsAndDeletesSources(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');
        $source = Fixtures::createTag($group, 'Cats');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Article');

        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $source->id);

        self::assertTrue($this->tags()->mergeTags([$source->id], $target->id));

        self::assertNull(Tag::find()->id($source->id)->status(null)->one());
        self::assertNotNull(Tag::find()->id($target->id)->status(null)->one());

        $targetIds = (new Query())
            ->select(['targetId'])
            ->from(['{{%relations}}'])
            ->where(['sourceId' => $entry->id])
            ->column();

        self::assertSame([(string)$target->id], array_map('strval', $targetIds));
    }

    public function testMergeTagsDedupesRelationsThatWouldCollide(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');
        $source = Fixtures::createTag($group, 'Cats');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Article');

        // The same entry already references both tags through the same field.
        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $target->id, 1);
        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $source->id, 2);

        self::assertTrue($this->tags()->mergeTags([$source->id], $target->id));

        $rows = (new Query())
            ->from(['{{%relations}}'])
            ->where(['sourceId' => $entry->id, 'fieldId' => $tagsField->id])
            ->all();

        self::assertCount(1, $rows, 'The colliding relation should have been dropped, not duplicated');
        self::assertSame($target->id, (int)$rows[0]['targetId']);
    }

    public function testMergeTagsIgnoresTargetInSourceListAndDedupes(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');
        $source = Fixtures::createTag($group, 'Cats');

        self::assertTrue($this->tags()->mergeTags([$source->id, $source->id, $target->id], $target->id));

        self::assertNotNull(Tag::find()->id($target->id)->status(null)->one());
        self::assertNull(Tag::find()->id($source->id)->status(null)->one());
    }

    public function testMergeTagsReturnsFalseWhenSourceListEmptiesOut(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $target = Fixtures::createTag($group, 'Cat');

        self::assertFalse($this->tags()->mergeTags([$target->id], $target->id));
        self::assertFalse($this->tags()->mergeTags([], $target->id));
        self::assertFalse($this->tags()->mergeTags([0], $target->id));
    }

    public function testMergeTagsReturnsFalseForUnknownTarget(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $source = Fixtures::createTag($group, 'Cats');

        self::assertFalse($this->tags()->mergeTags([$source->id], 999999));
        self::assertNotNull(
            Tag::find()->id($source->id)->status(null)->one(),
            'A failed merge must not delete the source tag',
        );
    }

    public function testSwapRelationsMovesRelationsWithoutDeletingSources(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $teams = Fixtures::createChannelSection('teams');
        $canonical = Fixtures::createEntry($teams, 'Cat');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $news = Fixtures::createChannelSection('news', [$tagsField]);
        $article = Fixtures::createEntry($news, 'Article');

        Fixtures::relate($tagsField->id, $article->id, $article->siteId, $tag->id);

        // Cross-type: re-point the article's reference from the Tag to the Entry.
        self::assertTrue($this->tags()->swapRelations([$tag->id], $canonical->id));

        $targetIds = (new Query())
            ->select(['targetId'])
            ->from(['{{%relations}}'])
            ->where(['sourceId' => $article->id])
            ->column();

        self::assertSame([$canonical->id], array_map('intval', $targetIds));
        self::assertNotNull(
            Tag::find()->id($tag->id)->status(null)->one(),
            'swapRelations must never delete the source element',
        );
    }

    public function testSwapRelationsReturnsFalseForUnknownTarget(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertFalse($this->tags()->swapRelations([$tag->id], 999999));
    }

    public function testSwapRelationsReturnsFalseWhenSourceListEmptiesOut(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertFalse($this->tags()->swapRelations([$tag->id], $tag->id));
        self::assertFalse($this->tags()->swapRelations([], $tag->id));
    }

    public function testSwapRelationsLeavesUnrelatedRelationsUntouched(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');
        $other = Fixtures::createTag($group, 'Dog');
        $target = Fixtures::createTag($group, 'Feline');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $news = Fixtures::createChannelSection('news', [$tagsField]);
        $article = Fixtures::createEntry($news, 'Article');

        Fixtures::relate($tagsField->id, $article->id, $article->siteId, $tag->id, 1);
        Fixtures::relate($tagsField->id, $article->id, $article->siteId, $other->id, 2);

        self::assertTrue($this->tags()->swapRelations([$tag->id], $target->id));

        $targetIds = (new Query())
            ->select(['targetId'])
            ->from(['{{%relations}}'])
            ->where(['sourceId' => $article->id])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->column();

        self::assertSame([$target->id, $other->id], array_map('intval', $targetIds));
    }
}
