<?php

namespace justinholtweb\tidytagstests\support;

use Craft;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\fields\Tags as TagsField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craft\models\TagGroup;
use RuntimeException;

/**
 * Builders for the Craft content the plugin reads: tag groups, tags, tag-like
 * channel sections, entries, custom fields, and extra sites.
 *
 * Everything is created through Craft's own services so the resulting state is
 * indistinguishable from a real install.
 */
final class Fixtures
{
    private static int $counter = 0;

    /**
     * Returns a short unique suffix so repeated fixtures never collide on
     * handles within a suite run.
     */
    public static function uniq(): string
    {
        return (string)(++self::$counter);
    }

    public static function createSite(string $handle, string $name, bool $primary = false): Site
    {
        $site = new Site([
            'groupId' => Craft::$app->getSites()->getAllGroups()[0]->id,
            'name' => $name,
            'handle' => $handle,
            'language' => 'en-GB',
            'hasUrls' => false,
            'primary' => $primary,
        ]);

        if (!Craft::$app->getSites()->saveSite($site)) {
            throw new RuntimeException('Could not save site: ' . self::errors($site->getErrors()));
        }

        // Craft memoizes "is this install multi-site?" and omits the siteId
        // condition from every element query while it's false. Since we add the
        // second site mid-request, that flag has to be recomputed or all
        // site-scoped queries silently return rows for every site.
        Craft::$app->getIsMultiSite(true);
        Craft::$app->getIsMultiSite(true, true);

        return $site;
    }

    public static function createPlainTextField(string $handle, ?string $name = null): PlainText
    {
        $field = new PlainText([
            'name' => $name ?? ucfirst($handle),
            'handle' => $handle,
            'columnSuffix' => null,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException('Could not save field: ' . self::errors($field->getErrors()));
        }

        return $field;
    }

    public static function createTagsField(string $handle, string $tagGroupUid): TagsField
    {
        $field = new TagsField([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'source' => 'taggroup:' . $tagGroupUid,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException('Could not save field: ' . self::errors($field->getErrors()));
        }

        return $field;
    }

    /**
     * @param \craft\base\FieldInterface[] $fields
     */
    public static function createTagGroup(string $handle, array $fields = []): TagGroup
    {
        $group = new TagGroup([
            'name' => ucfirst($handle),
            'handle' => $handle,
        ]);

        $group->setFieldLayout(self::fieldLayout(Tag::class, $fields));

        if (!Craft::$app->getTags()->saveTagGroup($group)) {
            throw new RuntimeException('Could not save tag group: ' . self::errors($group->getErrors()));
        }

        return $group;
    }

    /**
     * @param array<string, mixed> $fieldValues
     */
    public static function createTag(TagGroup $group, string $title, array $fieldValues = [], ?int $siteId = null): Tag
    {
        $tag = new Tag([
            'groupId' => $group->id,
            'title' => $title,
        ]);

        if ($siteId !== null) {
            $tag->siteId = $siteId;
        }

        if ($fieldValues !== []) {
            $tag->setFieldValues($fieldValues);
        }

        if (!Craft::$app->getElements()->saveElement($tag)) {
            throw new RuntimeException('Could not save tag: ' . self::errors($tag->getErrors()));
        }

        return $tag;
    }

    /**
     * Creates a channel section — the shape `php craft entrify/tags` produces.
     *
     * @param \craft\base\FieldInterface[] $fields
     */
    public static function createChannelSection(string $handle, array $fields = []): Section
    {
        $siteSettings = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $siteSettings[] = new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
                'enabledByDefault' => true,
            ]);
        }

        $entryType = new EntryType([
            'name' => ucfirst($handle),
            'handle' => $handle . self::uniq(),
        ]);
        $entryType->setFieldLayout(self::fieldLayout(Entry::class, $fields));

        if (!Craft::$app->getEntries()->saveEntryType($entryType)) {
            throw new RuntimeException('Could not save entry type: ' . self::errors($entryType->getErrors()));
        }

        $section = new Section([
            'name' => ucfirst($handle),
            'handle' => $handle,
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => $siteSettings,
        ]);
        $section->setEntryTypes([$entryType]);

        if (!Craft::$app->getEntries()->saveSection($section)) {
            throw new RuntimeException('Could not save section: ' . self::errors($section->getErrors()));
        }

        return $section;
    }

    /**
     * @param array<string, mixed> $fieldValues
     */
    public static function createEntry(Section $section, string $title, array $fieldValues = [], ?int $siteId = null): Entry
    {
        $entryTypes = $section->getEntryTypes();

        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $entryTypes[0]->id,
            'title' => $title,
        ]);

        if ($siteId !== null) {
            $entry->siteId = $siteId;
        }

        if ($fieldValues !== []) {
            $entry->setFieldValues($fieldValues);
        }

        if (!Craft::$app->getElements()->saveElement($entry)) {
            throw new RuntimeException('Could not save entry: ' . self::errors($entry->getErrors()));
        }

        return $entry;
    }

    /**
     * Inserts a raw relation row, the same shape Craft writes when a relational
     * field on $sourceId points at $targetId.
     */
    public static function relate(int $fieldId, int $sourceId, ?int $sourceSiteId, int $targetId, int $sortOrder = 1): int
    {
        Craft::$app->getDb()->createCommand()
            ->insert('{{%relations}}', [
                'fieldId' => $fieldId,
                'sourceId' => $sourceId,
                'sourceSiteId' => $sourceSiteId,
                'targetId' => $targetId,
                'sortOrder' => $sortOrder,
                'dateCreated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
                'dateUpdated' => \craft\helpers\Db::prepareDateForDb(new \DateTime()),
                'uid' => \craft\helpers\StringHelper::UUID(),
            ])
            ->execute();

        return (int)Craft::$app->getDb()->getLastInsertID('{{%relations}}');
    }

    /**
     * @param \craft\base\FieldInterface[] $fields
     */
    private static function fieldLayout(string $elementType, array $fields): FieldLayout
    {
        $layout = new FieldLayout(['type' => $elementType]);

        $elements = array_map(
            fn($field) => new CustomField($field),
            $fields,
        );

        // Craft derives EntryType::$hasTitleField from whether the layout
        // includes the native title element (Entries::saveEntryType()). Without
        // it, every entry saves with an empty title.
        if ($elementType === Entry::class) {
            array_unshift($elements, new EntryTitleField());
        }

        if ($elements !== []) {
            // Configure tabs through the array form so Craft wires each tab
            // back to its layout — a hand-built FieldLayoutTab has no layout
            // and blows up as soon as its elements are read.
            $layout->setTabs([
                [
                    'name' => 'Content',
                    'sortOrder' => 1,
                    'elements' => $elements,
                ],
            ]);
        }

        return $layout;
    }

    /**
     * @param array<string, array<int, string>> $errors
     */
    private static function errors(array $errors): string
    {
        $out = [];
        foreach ($errors as $attribute => $messages) {
            $out[] = $attribute . ': ' . implode(', ', (array)$messages);
        }
        return implode('; ', $out) ?: 'unknown error';
    }
}
