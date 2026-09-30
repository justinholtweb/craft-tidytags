<?php

namespace justinholtweb\tidytagstests\unit\services;

use Craft;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

class SourcesTest extends PluginTestCase
{
    public function testGetAllSourcesReturnsTagGroups(): void
    {
        $group = Fixtures::createTagGroup('animals');

        $sources = $this->sources()->getAllSources();
        $handles = array_map(fn(Source $s) => $s->handle, $sources);

        self::assertContains('animals', $handles);

        $source = $this->sources()->getTagSource($group->id);
        self::assertNotNull($source);
        self::assertSame(Source::TYPE_TAG, $source->type);
        self::assertSame($group->uid, $source->uid);
        self::assertTrue($source->isWritable());
    }

    public function testGetAllSourcesIncludesOnlyConfiguredSections(): void
    {
        $configured = Fixtures::createChannelSection('teams');
        $unconfigured = Fixtures::createChannelSection('news');

        $this->setPluginSettings(['tagLikeSectionUids' => [$configured->uid]]);

        $handles = array_map(fn(Source $s) => $s->handle, $this->sources()->getAllSources());

        self::assertContains('teams', $handles);
        self::assertNotContains('news', $handles);

        self::assertNotNull($this->sources()->getEntrySource($configured->id));
        self::assertNull($this->sources()->getEntrySource($unconfigured->id));
    }

    public function testGetTagSourceReturnsNullForUnknownGroup(): void
    {
        self::assertNull($this->sources()->getTagSource(999999));
    }

    public function testIsSectionConfigured(): void
    {
        $section = Fixtures::createChannelSection('teams');

        self::assertFalse($this->sources()->isSectionConfigured($section));

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        self::assertTrue($this->sources()->isSectionConfigured($section));
    }

    public function testGetConfiguredEntrySectionsSkipsMissingUids(): void
    {
        $section = Fixtures::createChannelSection('teams');

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid, 'not-a-real-uid'],
        ]);

        $sections = $this->sources()->getConfiguredEntrySections();

        self::assertCount(1, $sections);
        self::assertSame($section->id, $sections[0]->id);
    }

    public function testBaseQueryIncludesDisabledElements(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $enabled = Fixtures::createTag($group, 'Cat');
        $disabled = Fixtures::createTag($group, 'Dog');
        $disabled->enabled = false;
        Craft::$app->getElements()->saveElement($disabled);

        $source = $this->sources()->getTagSource($group->id);
        $titles = array_map(
            fn($el) => $el->title,
            $this->sources()->baseQuery($source)->all(),
        );

        sort($titles);
        self::assertSame(['Cat', 'Dog'], $titles);
        self::assertNotNull($enabled->id);
    }

    public function testCountsBySiteAndTotalCount(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');
        Fixtures::createTag($group, 'Dog');

        $source = $this->sources()->getTagSource($group->id);

        $counts = $this->sources()->getCountsBySite($source);
        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;

        self::assertSame(2, $counts[$primaryId]);
        self::assertArrayHasKey($second->id, $counts);
        self::assertSame(2, $this->sources()->getTotalCount($source));
    }

    public function testGetElementsInSourceIsSortedAndSearchable(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Zebra');
        Fixtures::createTag($group, 'Antelope');

        $source = $this->sources()->getTagSource($group->id);

        $rows = $this->sources()->getElementsInSource($source);
        self::assertSame(
            ['Antelope', 'Zebra'],
            array_map(fn(array $row) => $row['element']->title, $rows),
        );

        foreach ($rows as $row) {
            self::assertArrayHasKey('titles', $row);
            self::assertNotEmpty($row['titles']);
        }
    }

    public function testGetElementsInSourceCollectsPerSiteTitles(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $localized = Craft::$app->getElements()->getElementById($tag->id, null, $second->id);
        $localized->title = 'Chat';
        Craft::$app->getElements()->saveElement($localized);

        $source = $this->sources()->getTagSource($group->id);
        $rows = $this->sources()->getElementsInSource($source);

        self::assertCount(1, $rows);
        $titles = $rows[0]['titles'];
        self::assertSame('Cat', $titles[Craft::$app->getSites()->getPrimarySite()->id]);
        self::assertSame('Chat', $titles[$second->id]);
    }

    public function testGetElementsInSourceScopedToSite(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $localized = Craft::$app->getElements()->getElementById($tag->id, null, $second->id);
        $localized->title = 'Chat';
        Craft::$app->getElements()->saveElement($localized);

        $source = $this->sources()->getTagSource($group->id);
        $rows = $this->sources()->getElementsInSource($source, $second->id);

        self::assertCount(1, $rows);
        self::assertSame('Chat', $rows[0]['element']->title);
        self::assertSame(['Chat'], array_values($rows[0]['titles']));
    }

    public function testGetAvailableFieldsForTagGroupAndSection(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $group = Fixtures::createTagGroup('animals', [$sport]);
        $section = Fixtures::createChannelSection('teams', [$sport]);

        $tagSource = $this->sources()->getTagSource($group->id);
        $handles = array_map(fn($f) => $f->handle, $this->sources()->getAvailableFields($tagSource));
        self::assertSame(['sport'], $handles);

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);
        $entrySource = $this->sources()->getEntrySource($section->id);
        $handles = array_map(fn($f) => $f->handle, $this->sources()->getAvailableFields($entrySource));
        self::assertSame(['sport'], $handles);
    }

    public function testDifferentiatorAndDisplayHandlesComeFromSettings(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $source = $this->sources()->getTagSource($group->id);

        self::assertNull($this->sources()->getDifferentiatorHandle($source));
        self::assertSame([], $this->sources()->getDisplayHandles($source));

        $this->setPluginSettings([
            'sourceFieldConfig' => [
                $group->uid => ['differentiator' => 'sport', 'display' => ['sport', 'region']],
            ],
        ]);

        self::assertSame('sport', $this->sources()->getDifferentiatorHandle($source));
        self::assertSame(['sport', 'region'], $this->sources()->getDisplayHandles($source));
    }

    public function testReadFieldValueHandlesScalarsAndMissingFields(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $group = Fixtures::createTagGroup('animals', [$sport]);
        $tag = Fixtures::createTag($group, 'England', ['sport' => 'Football']);

        self::assertSame('Football', $this->sources()->readFieldValue($tag, 'sport'));
        self::assertNull($this->sources()->readFieldValue($tag, 'nonexistent'));

        $blank = Fixtures::createTag($group, 'Wales', ['sport' => '']);
        self::assertNull($this->sources()->readFieldValue($blank, 'sport'));
    }

    public function testGetUsagesReturnsRelationDetails(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);
        $entry = Fixtures::createEntry($section, 'Some article');

        Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $tag->id);

        $usages = $this->sources()->getUsages($tag->id);

        self::assertCount(1, $usages);
        self::assertSame($entry->id, $usages[0]['elementId']);
        self::assertSame('Some article', $usages[0]['title']);
        self::assertSame('Entry', $usages[0]['elementType']);
        self::assertSame('animalTags', $usages[0]['fieldHandle']);
        self::assertSame($entry->siteId, $usages[0]['siteId']);
        self::assertNotNull($usages[0]['cpEditUrl']);
    }

    public function testGetUsagesReturnsEmptyArrayWhenUnused(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        self::assertSame([], $this->sources()->getUsages($tag->id));
    }

    public function testGetUsagesRespectsLimit(): void
    {
        $group = Fixtures::createTagGroup('animals');
        $tag = Fixtures::createTag($group, 'Cat');

        $tagsField = Fixtures::createTagsField('animalTags', $group->uid);
        $section = Fixtures::createChannelSection('news', [$tagsField]);

        foreach (['A', 'B', 'C'] as $title) {
            $entry = Fixtures::createEntry($section, $title);
            Fixtures::relate($tagsField->id, $entry->id, $entry->siteId, $tag->id);
        }

        self::assertCount(2, $this->sources()->getUsages($tag->id, 2));
        self::assertCount(3, $this->sources()->getUsages($tag->id));
    }
}
