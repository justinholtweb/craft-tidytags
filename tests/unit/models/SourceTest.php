<?php

namespace justinholtweb\tidytagstests\unit\models;

use craft\models\Section;
use craft\models\TagGroup;
use craft\test\TestCase;
use justinholtweb\tidytags\models\Source;

/**
 * Pure unit coverage for the Source value object — no Craft app state needed
 * beyond constructing the two model types it wraps.
 */
class SourceTest extends TestCase
{
    public function testFromTagGroup(): void
    {
        $group = new TagGroup([
            'id' => 7,
            'uid' => 'group-uid',
            'name' => 'Animals',
            'handle' => 'animals',
        ]);

        $source = Source::fromTagGroup($group);

        self::assertSame(Source::TYPE_TAG, $source->type);
        self::assertSame(7, $source->id);
        self::assertSame('group-uid', $source->uid);
        self::assertSame('Animals', $source->name);
        self::assertSame('animals', $source->handle);
        self::assertTrue($source->isWritable());
        self::assertSame('Tags', $source->typeLabel());
        self::assertSame('tidytags/group/7', $source->cpPath());
    }

    public function testFromSection(): void
    {
        $section = new Section([
            'id' => 12,
            'uid' => 'section-uid',
            'name' => 'Teams',
            'handle' => 'teams',
            'type' => Section::TYPE_CHANNEL,
        ]);

        $source = Source::fromSection($section);

        self::assertSame(Source::TYPE_ENTRY, $source->type);
        self::assertSame(12, $source->id);
        self::assertSame('section-uid', $source->uid);
        self::assertSame('Teams', $source->name);
        self::assertSame('teams', $source->handle);
        self::assertSame('Entries', $source->typeLabel());
        self::assertSame('tidytags/section/12', $source->cpPath());
    }

    /**
     * Entry sources must never report as writable — rename/merge/delete are
     * tag-only, and the templates key their action buttons off this flag.
     */
    public function testEntrySourcesAreNotWritable(): void
    {
        $section = new Section([
            'id' => 12,
            'uid' => 'section-uid',
            'name' => 'Teams',
            'handle' => 'teams',
            'type' => Section::TYPE_CHANNEL,
        ]);

        self::assertFalse(Source::fromSection($section)->isWritable());
    }
}
