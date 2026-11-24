<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags;

use CraftCms\Cms\Cp\Data\NavItem;
use CraftCms\Cms\Element\Events\ElementDeleted;
use CraftCms\Cms\Element\Events\ElementSaved;
use CraftCms\Cms\Plugin\Plugin as BasePlugin;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Ui\Controls\Choice;
use CraftCms\Cms\Ui\Controls\Number;
use CraftCms\Cms\Ui\Controls\Textarea;
use CraftCms\Cms\Ui\Enums\ChoicePresentation;
use CraftCms\Cms\Ui\Nodes\Callout;
use CraftCms\Cms\Ui\Nodes\Field;
use CraftCms\Cms\Ui\Nodes\Heading;
use CraftCms\Cms\Ui\Ui;
use CraftCms\Cms\Ui\UiContext;
use CraftCms\Cms\User\Data\Permission;
use JustinHoltWeb\TidyTags\Console\Commands\DuplicatesCommand;
use JustinHoltWeb\TidyTags\Console\Commands\MergeCommand;
use JustinHoltWeb\TidyTags\Console\Commands\UnusedCommand;
use JustinHoltWeb\TidyTags\Listeners\ForgetScans;
use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Services\DuplicateDetector;
use JustinHoltWeb\TidyTags\Services\Items;
use JustinHoltWeb\TidyTags\Services\Sources;
use JustinHoltWeb\TidyTags\Services\TitleMatcher;
use Override;

use function CraftCms\Cms\currentUser;
use function CraftCms\Cms\t;

/**
 * Tidy Tags: keeps a site's taxonomy clean. Finds near-duplicate titles in tag-like entry
 * sections, and renames, merges and prunes their entries across every site.
 *
 * Craft 6 has no Tags, so every source is an entry section an admin marks as tag-like
 * (usually the output of Craft 5's `entrify/tags`).
 *
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /** Core's own plugin-access permission. Browsing, scanning and usage lookups. */
    public const string PERMISSION_ACCESS = 'accessPlugin-tidytags';

    /** Rename items and swap relations. Same key as 5.x, so grants carry over. */
    public const string PERMISSION_MANAGE_TAGS = 'tidytags-manageTags';

    /** Delete items; merge needs this and manage. Same key as 5.x. */
    public const string PERMISSION_DELETE_TAGS = 'tidytags-deleteTags';

    /** Tidy Tags has no tables. */
    public string $schemaVersion = '1.0.0';

    /**
     * Settings are read with the 5.5 keys (`writableSectionUids` first appeared there), so an
     * upgrade comes from 5.5 or later.
     */
    public string $minVersionRequired = '5.5.0';

    public bool $hasCpSection = true;

    public bool $hasCpSettings = true;

    protected array $styles = [
        __DIR__.'/../resources/css/tidytags.css' => 'css/tidytags.css',
    ];

    protected array $scripts = [
        __DIR__.'/../resources/js/tidytags.js' => 'js/tidytags.js',
    ];

    protected array $commands = [
        DuplicatesCommand::class,
        MergeCommand::class,
        UnusedCommand::class,
    ];

    protected array $events = [
        ElementSaved::class => ForgetScans::class,
        ElementDeleted::class => ForgetScans::class,
    ];

    #[Override]
    protected static function createSettings(): Settings
    {
        return new Settings;
    }

    public function register(): void
    {
        parent::register();

        foreach ([Sources::class, Items::class, DuplicateDetector::class] as $service) {
            $this->app->singleton($service);
        }

        $this->app->bind(TitleMatcher::class, fn () => TitleMatcher::fromSettings($this->getSettings()));
    }

    public function boot(): void
    {
        $this->app->bind(Settings::class, fn () => $this->getSettings());
    }

    #[Override]
    protected function getPermissions(): array
    {
        return [
            new Permission(
                key: self::PERMISSION_MANAGE_TAGS,
                label: t('Rename and merge tag-like entries, and swap relations', category: 'tidytags'),
                nested: collect([
                    new Permission(
                        key: self::PERMISSION_DELETE_TAGS,
                        label: t('Delete tag-like entries', category: 'tidytags'),
                    ),
                ]),
            ),
        ];
    }

    #[Override]
    public function getCpNavItem(): NavItem|array|null
    {
        $item = parent::getCpNavItem();
        $user = currentUser();

        if (! $item instanceof NavItem || $user === null || ! $user->can(self::PERMISSION_ACCESS)) {
            return null;
        }

        return $item->subnav([
            'dashboard' => new NavItem()->label(t('Dashboard', category: 'tidytags'))->href('tidytags'),
            'duplicates' => new NavItem()->label(t('Duplicates', category: 'tidytags'))->href('tidytags/duplicates'),
        ]);
    }

    #[Override]
    public function settingsUi(UiContext $context = new UiContext): ?Ui
    {
        $channels = [];
        foreach (Sections::getAllSections() as $section) {
            if ($section->type === SectionType::Channel) {
                $channels[] = ['label' => "{$section->name} ({$section->handle})", 'value' => $section->uid];
            }
        }

        $nodes = [
            Heading::make('tidytags-matching', t('Matching', category: 'tidytags'))
                ->description(t('How Tidy Tags decides that two titles name the same thing. Pick the strategy that describes your titles.', category: 'tidytags')),
            Field::make(t('Match strategy', category: 'tidytags'), Choice::make('matchStrategy')->options([
                ['label' => t('Fuzzy — tolerate typos (default)', category: 'tidytags'), 'value' => TitleMatcher::STRATEGY_FUZZY],
                ['label' => t('Strict — affixes and qualifiers, no typo tolerance', category: 'tidytags'), 'value' => TitleMatcher::STRATEGY_STRICT],
            ]))->instructions(t('**Fuzzy** suits free-text tags typed by editors, where duplicates are misspellings (`Manchestor` → `Manchester`). **Strict** suits controlled vocabularies of proper nouns — clubs, competitions, places, people — where `FC Bayern Munich` and `Bayern Munich` are the same thing but `Arsenal` and `Arsenal Women` are not.', category: 'tidytags')),
            Field::make(t('Affix tokens', category: 'tidytags'), Textarea::make('affixTokens')->rows(4)->monospace())
                ->instructions(t('Strict only. Words removed from the start and end of a title before comparing — one per line, or comma separated.', category: 'tidytags')),
            Field::make(t('Qualifier tokens', category: 'tidytags'), Textarea::make('qualifierTokens')->rows(6)->monospace())
                ->instructions(t('Strict only. Words that make two otherwise-matching titles different things. When one title has one and the other doesn’t, they’re not duplicates. Curate this against your own titles.', category: 'tidytags')),

            Heading::make('tidytags-sources', t('Tag-like sections', category: 'tidytags'))
                ->description(t('Channel sections Tidy Tags treats as vocabularies — typically the sections your tag groups became. They’re read-only until you also allow rename, merge and delete.', category: 'tidytags')),
        ];

        if ($channels === []) {
            $nodes[] = Callout::make('tidytags-no-channels', t('No channel sections exist yet.', category: 'tidytags'));
        } else {
            $nodes[] = Field::make(t('Tag-like sections', category: 'tidytags'), Choice::make('tagLikeSectionUids')
                ->options($channels)->multiple()->presentation(ChoicePresentation::Checkboxes))
                ->instructions(t('Shown on the dashboard, scanned for duplicates, and checked by the “did you mean?” warning on entry titles.', category: 'tidytags'));
            $nodes[] = Field::make(t('Allow rename, merge and delete', category: 'tidytags'), Choice::make('writableSectionUids')
                ->options($channels)->multiple()->presentation(ChoicePresentation::Checkboxes))
                ->instructions(t('Sections whose entries Tidy Tags may rename, merge and delete. Merged and deleted entries go to the trash. Sections ticked here but not above are ignored.', category: 'tidytags'));
        }

        $nodes = [...$nodes, ...$this->fieldConfigNodes()];

        $nodes[] = Heading::make('tidytags-pages', t('Paging', category: 'tidytags'));
        $nodes[] = Field::make(t('Items per page', category: 'tidytags'), Number::make('pageSize')->min(1)->max(1000))->required();
        $nodes[] = Field::make(t('Duplicate clusters per page', category: 'tidytags'), Number::make('duplicatesPageSize')->min(1)->max(500))->required();

        return Ui::make($nodes);
    }

    /**
     * Per-section differentiator and display fields, for each configured tag-like section.
     *
     * @return list<Heading|Field>
     */
    private function fieldConfigNodes(): array
    {
        $sources = $this->app->make(Sources::class);
        $nodes = [];

        foreach ($sources->getAllSources() as $source) {
            $fields = [['label' => t('None', category: 'tidytags'), 'value' => '']];
            foreach ($sources->getAvailableFields($source) as $field) {
                $fields[] = ['label' => "{$field->name} ({$field->handle})", 'value' => (string) $field->handle];
            }

            $nodes[] = Heading::make('tidytags-fields-'.$source->uid, t('{name}: fields', ['name' => $source->name], category: 'tidytags'));
            $nodes[] = Field::make(t('Differentiator field', category: 'tidytags'), Choice::make(['sourceFieldConfig', $source->uid, 'differentiator'])->options($fields))
                ->instructions(t('Items with the same title but different values here are not duplicates — e.g. a Sport field separating England (Football) from England (Cricket).', category: 'tidytags'));
            $nodes[] = Field::make(t('Display fields', category: 'tidytags'), Choice::make(['sourceFieldConfig', $source->uid, 'display'])
                ->options(array_slice($fields, 1))->multiple()->presentation(ChoicePresentation::Checkboxes))
                ->instructions(t('Shown beside each item in duplicate clusters and the “did you mean?” warning.', category: 'tidytags'));
        }

        return $nodes;
    }
}
