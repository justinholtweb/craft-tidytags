<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Tests\Support;

use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Entries;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\Support\Facades\UserGroups;
use CraftCms\Cms\Support\Facades\UserPermissions;
use CraftCms\Cms\User\Elements\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Plugin;

/**
 * Real content, saved through Craft's own services: tag-like channel sections, entries in them,
 * an Entries field for relations, users, and the plugin settings that tie them together.
 */
trait CreatesContent
{
    private ?User $adminUser = null;

    private ?Entries $relatedField = null;

    protected function admin(): User
    {
        return $this->adminUser ??= User::find()->admin()->one() ?? throw new \RuntimeException('No admin user.');
    }

    /**
     * A channel section. With $uriFormat its entries get URLs.
     */
    protected function createSection(string $handle, ?string $uriFormat = null): Section
    {
        $layout = new FieldLayout(['type' => Entry::class]);
        $layout->setTabs([['name' => 'Content', 'elements' => [new EntryTitleField]]]);

        $type = new EntryType(['name' => Str::headline($handle), 'handle' => $handle.'Type']);
        $type->setFieldLayout($layout);
        expect(EntryTypes::saveEntryType($type))->toBeTrue();

        $siteSettings = [];
        foreach (Sites::getAllSites() as $site) {
            $siteSettings[] = new SectionSiteSettings([
                'siteId' => $site->id,
                'enabledByDefault' => true,
                'hasUrls' => $uriFormat !== null,
                'uriFormat' => $uriFormat,
            ]);
        }

        $section = new Section([
            'name' => Str::headline($handle),
            'handle' => $handle,
            'type' => SectionType::Channel,
            'siteSettings' => $siteSettings,
        ]);
        $section->setEntryTypes([$type]);
        expect(Sections::saveSection($section))->toBeTrue();

        return $section;
    }

    protected function createEntry(Section $section, string $title): Entry
    {
        $entry = new Entry([
            'sectionId' => $section->id,
            'typeId' => $section->getEntryTypes()[0]->id,
            'siteId' => Sites::getPrimarySite()->id,
            'title' => $title,
            'enabled' => true,
        ]);
        $entry->setAuthorIds([$this->admin()->id]);

        expect(Elements::saveElement($entry))->toBeTrue(json_encode($entry->errors()->getMessages()) ?: '');

        return $entry;
    }

    /**
     * Marks sections as tag-like, and the $writable ones as allowing rename, merge and delete.
     *
     * @param  list<Section>  $tagLike
     * @param  list<Section>  $writable
     */
    protected function configure(array $tagLike, array $writable = []): Settings
    {
        $settings = Plugin::getInstance()->getSettings();
        $settings->tagLikeSectionUids = array_map(fn (Section $s) => (string) $s->uid, $tagLike);
        $settings->writableSectionUids = array_map(fn (Section $s) => (string) $s->uid, $writable);

        return $settings;
    }

    /**
     * A raw relation row, the shape Craft writes when $ownerId's Entries field points at $targetId.
     */
    protected function relate(int $ownerId, int $targetId, ?int $siteId = null): void
    {
        if ($this->relatedField === null) {
            $this->relatedField = new Entries(['name' => 'Related', 'handle' => 'related']);
            expect(Fields::saveField($this->relatedField))->toBeTrue();
        }

        DB::table('relations')->insert([
            'fieldId' => $this->relatedField->id,
            'sourceId' => $ownerId,
            'sourceSiteId' => $siteId,
            'targetId' => $targetId,
            'sortOrder' => 1,
            'dateCreated' => now(),
            'dateUpdated' => now(),
            'uid' => (string) Str::uuid(),
        ]);
    }

    /**
     * A non-admin with these permissions. On Team every non-admin shares the Team group's
     * permissions, so this sets the group's.
     *
     * @param  list<string>  $permissions
     */
    protected function userWith(array $permissions): User
    {
        $username = 'editor'.Str::lower(Str::random(6));
        $user = new User(['username' => $username, 'email' => $username.'@example.com', 'active' => true]);
        expect(Elements::saveElement($user))->toBeTrue(json_encode($user->errors()->getMessages()) ?: '');

        UserPermissions::saveGroupPermissions(UserGroups::getTeamGroup()->id, ['accessCp', ...$permissions]);

        return User::find()->id($user->id)->status(null)->one();
    }

    protected function trashed(int $id): ?Entry
    {
        return Entry::find()->id($id)->status(null)->trashed()->one();
    }
}
