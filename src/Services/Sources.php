<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Services;

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Element\Queries\EntryQuery;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;
use CraftCms\Cms\Support\Facades\Sites;
use CraftCms\Cms\User\Contracts\CraftUser;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use JustinHoltWeb\TidyTags\Data\Source;
use JustinHoltWeb\TidyTags\Models\Settings;
use Stringable;
use Throwable;

/**
 * Source enumeration and read queries.
 *
 * A source is a channel section an admin has marked as tag-like. Everything downstream —
 * dashboard rows, per-site counts, the Manage screen, duplicate scans — runs off this.
 */
class Sources
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Every configured tag-like section, in the order they were configured.
     *
     * @return list<Source>
     */
    public function getAllSources(): array
    {
        $sources = [];
        foreach ($this->getConfiguredSections() as $section) {
            $sources[] = Source::fromSection($section, $this->isSectionWritable($section));
        }

        return $sources;
    }

    /**
     * The source for a section, or null when the section isn't configured as tag-like, so
     * controllers 404 on anything else.
     */
    public function getSource(int $sectionId): ?Source
    {
        $section = Sections::getSectionById($sectionId);
        if ($section === null || ! $this->settings->isSectionTagLike((string) $section->uid)) {
            return null;
        }

        return Source::fromSection($section, $this->isSectionWritable($section));
    }

    /**
     * The source an element belongs to: its section when that section is tag-like. Null for
     * anything else, including drafts and revisions, which Tidy Tags never acts on.
     */
    public function getSourceForElement(ElementInterface $element): ?Source
    {
        if (! $element instanceof Entry || $element->sectionId === null || $element->getIsDraft() || $element->getIsRevision()) {
            return null;
        }

        return $this->getSource((int) $element->sectionId);
    }

    /**
     * Whether an element is in a tag-like section. Actions that take arbitrary element IDs use
     * this to stay inside the plugin's remit.
     */
    public function isSourceElement(ElementInterface $element): bool
    {
        return $this->getSourceForElement($element) !== null;
    }

    /**
     * @return list<Section>
     */
    public function getConfiguredSections(): array
    {
        $sections = [];
        foreach ($this->settings->getTagLikeSectionUids() as $uid) {
            $section = Sections::getSectionByUid($uid);
            if ($section !== null) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Whether rename, merge and delete are allowed in a section. Only channels qualify: structure
     * entries have parents and children a merge would have to rearrange.
     */
    public function isSectionWritable(Section $section): bool
    {
        return $section->type === SectionType::Channel
            && $this->settings->isSectionWritable((string) $section->uid);
    }

    /**
     * Sources by handle, for the console's `--source`. `section:<handle>` is accepted too, as in 5.x.
     *
     * @return list<Source>
     */
    public function findSourcesByHandle(string $handle): array
    {
        if (str_starts_with($handle, 'section:')) {
            $handle = substr($handle, 8);
        }

        return array_values(array_filter(
            $this->getAllSources(),
            fn (Source $s) => $s->handle === $handle,
        ));
    }

    /**
     * @return array<int, int> siteId => count
     */
    public function getCountsBySite(Source $source): array
    {
        $counts = [];
        foreach (Sites::getAllSites() as $site) {
            $counts[(int) $site->id] = $this->baseQuery($source)->siteId($site->id)->count();
        }

        return $counts;
    }

    public function getTotalCount(Source $source): int
    {
        return $this->baseQuery($source)->site('*')->unique()->count();
    }

    /**
     * Rows for the Manage screen. With no $siteId, one row per element keyed to the primary site,
     * with each site's title alongside; otherwise rows scoped to that site. Per-site titles are
     * only looked up for the page being shown.
     *
     * @return list<array{element: Entry, titles: array<int, string>}>
     */
    public function getElementsInSource(
        Source $source,
        ?int $siteId = null,
        ?string $search = null,
        ?int $limit = null,
        int $offset = 0,
    ): array {
        $query = $this->listingQuery($source, $siteId, $search)
            ->orderBy('title')
            ->limit($limit)
            ->offset($offset > 0 ? $offset : null);

        /** @var list<Entry> $elements */
        $elements = $query->all();

        if ($siteId !== null) {
            return array_map(fn (Entry $e) => ['element' => $e, 'titles' => [(int) $e->siteId => (string) $e->title]], $elements);
        }

        $ids = array_map(fn (Entry $e) => (int) $e->id, $elements);
        $perSite = [];
        if ($ids !== []) {
            foreach (Sites::getAllSites() as $site) {
                /** @var Entry $se */
                foreach ($this->baseQuery($source)->id($ids)->siteId($site->id)->all() as $se) {
                    $perSite[(int) $se->id][(int) $site->id] = (string) $se->title;
                }
            }
        }

        return array_map(fn (Entry $e) => [
            'element' => $e,
            'titles' => $perSite[(int) $e->id] ?? [(int) $e->siteId => (string) $e->title],
        ], $elements);
    }

    public function countElementsInSource(Source $source, ?int $siteId = null, ?string $search = null): int
    {
        return $this->listingQuery($source, $siteId, $search)->count();
    }

    private function listingQuery(Source $source, ?int $siteId, ?string $search): EntryQuery
    {
        $query = $this->baseQuery($source)->siteId($siteId ?? Sites::getPrimarySite()->id);

        if ($search !== null && $search !== '') {
            $query->search($search);
        }

        return $query;
    }

    /**
     * Items nothing relates to — no row in `relations` targets them from any field, element or
     * site — as ID => title, ordered by title. Titles are the primary site's where the item exists
     * there. One query with a NOT EXISTS subquery, so it never loads elements.
     *
     * @return array<int, string>
     */
    public function getUnused(Source $source): array
    {
        $pairs = $this->baseQuery($source)
            ->site('*')
            ->unique()
            ->preferSites([Sites::getPrimarySite()->id])
            ->whereNotExists(fn (Builder $q) => $q
                ->from('relations as tidytags_r')
                ->whereColumn('tidytags_r.targetId', 'elements.id'))
            ->orderBy('elements_sites.title')
            ->orderBy('elements.id')
            ->pluck('elements_sites.title', 'elements.id');

        $out = [];
        foreach ($pairs as $id => $title) {
            $out[(int) $id] = (string) $title;
        }

        return $out;
    }

    /**
     * Every entry in a source, disabled ones included. All reads go through this.
     */
    public function baseQuery(Source $source): EntryQuery
    {
        return Entry::find()->sectionId($source->id)->status(null);
    }

    /**
     * Custom fields on a source: the union of its entry types' field layouts.
     *
     * @return list<FieldInterface>
     */
    public function getAvailableFields(Source $source): array
    {
        $section = Sections::getSectionById($source->id);
        if ($section === null) {
            return [];
        }

        $fields = [];
        foreach ($section->getEntryTypes() as $entryType) {
            foreach ($entryType->getFieldLayout()->getCustomFields() as $field) {
                $fields[(string) $field->handle] = $field;
            }
        }

        return array_values($fields);
    }

    public function getDifferentiatorHandle(Source $source): ?string
    {
        return $this->settings->getDifferentiatorHandle($source->uid);
    }

    /**
     * @return list<string>
     */
    public function getDisplayHandles(Source $source): array
    {
        return $this->settings->getDisplayHandles($source->uid);
    }

    /**
     * Every element that relates to $elementId through a relational field, for the Duplicates
     * screen's "Show usages". With $user, elements they can't view are left out, so the list never
     * reveals titles from sections they can't see.
     *
     * @return list<array{elementId: int, title: string, elementType: string, fieldHandle: string, fieldName: string, siteId: ?int, siteName: ?string, cpEditUrl: ?string}>
     */
    public function getUsages(int $elementId, int $limit = 200, ?CraftUser $user = null): array
    {
        $rows = DB::table('relations')
            ->select(['fieldId', 'sourceId', 'sourceSiteId'])
            ->where('targetId', $elementId)
            ->limit($limit)
            ->get();

        $usages = [];
        foreach ($rows as $row) {
            $field = Fields::getFieldById((int) $row->fieldId);
            $siteId = $row->sourceSiteId !== null ? (int) $row->sourceSiteId : null;
            $site = $siteId !== null ? Sites::getSiteById($siteId, true) : null;

            $element = Elements::getElementById((int) $row->sourceId, null, $siteId ?? Sites::getPrimarySite()->id);
            if ($element === null || ($user !== null && ! $user->can('view', $element))) {
                continue;
            }

            $usages[] = [
                'elementId' => (int) $element->id,
                'title' => (string) ($element->title ?? ('#'.$element->id)),
                'elementType' => class_basename($element),
                'fieldHandle' => (string) ($field->handle ?? '?'),
                'fieldName' => (string) ($field->name ?? '?'),
                'siteId' => $siteId,
                'siteName' => $site?->getName(),
                'cpEditUrl' => $element->getCpEditUrl(),
            ];
        }

        return $usages;
    }

    /**
     * A field's value as a short string, or null when it's empty or not a field on the element.
     * Relations become a comma-joined list of titles.
     */
    public function readFieldValue(ElementInterface $element, string $handle): ?string
    {
        try {
            $value = $element->getFieldValue($handle);
        } catch (Throwable) {
            return null;
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof ElementQueryInterface) {
            $titles = [];
            foreach ($value->all() as $related) {
                $title = (string) ($related->title ?? '');
                if ($title !== '') {
                    $titles[] = $title;
                }
            }

            return $titles === [] ? null : implode(', ', $titles);
        }

        if ($value instanceof Stringable) {
            $s = trim((string) $value);

            return $s === '' ? null : $s;
        }

        if (is_array($value)) {
            $flat = array_filter(array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $value));

            return $flat === [] ? null : implode(', ', $flat);
        }

        return null;
    }
}
