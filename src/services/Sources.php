<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
use craft\base\FieldInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\models\Section;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\Plugin;

/**
 * Source enumeration and read queries.
 *
 * A "source" is either a native tag group or a channel section that an admin
 * has designated as tag-like in plugin settings (usually the output of
 * `php craft entrify/tags`). Everything downstream — dashboard rows, per-site
 * counts, group view, duplicate scans — runs off this abstraction so the tag
 * and entry cases stay in lockstep without branching at every call site.
 */
class Sources extends Component
{
    /**
     * Every source known to Tidy Tags: tag groups followed by configured
     * entry sections.
     *
     * @return Source[]
     */
    public function getAllSources(): array
    {
        $sources = [];

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $sources[] = Source::fromTagGroup($group);
        }

        foreach ($this->getConfiguredEntrySections() as $section) {
            $sources[] = Source::fromSection($section);
        }

        return $sources;
    }

    public function getTagSource(int $groupId): ?Source
    {
        $group = Craft::$app->getTags()->getTagGroupById($groupId);
        return $group !== null ? Source::fromTagGroup($group) : null;
    }

    /**
     * Returns an entry-backed source only if the section has been configured
     * as tag-like; otherwise null, so controllers 404 on un-opted sections.
     */
    public function getEntrySource(int $sectionId): ?Source
    {
        $section = Craft::$app->getEntries()->getSectionById($sectionId);
        if ($section === null || !$this->isSectionConfigured($section)) {
            return null;
        }
        return Source::fromSection($section);
    }

    /**
     * @return Section[]
     */
    public function getConfiguredEntrySections(): array
    {
        $uids = Plugin::$plugin->getSettings()->tagLikeSectionUids;
        if (empty($uids)) {
            return [];
        }

        $sections = [];
        foreach ($uids as $uid) {
            $section = Craft::$app->getEntries()->getSectionByUid($uid);
            if ($section !== null) {
                $sections[] = $section;
            }
        }
        return $sections;
    }

    public function isSectionConfigured(Section $section): bool
    {
        return in_array(
            $section->uid,
            Plugin::$plugin->getSettings()->tagLikeSectionUids,
            true,
        );
    }

    /**
     * @return array<int, int> siteId => count
     */
    public function getCountsBySite(Source $source): array
    {
        $counts = [];
        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $counts[$site->id] = (int)$this->baseQuery($source)
                ->siteId($site->id)
                ->count();
        }
        return $counts;
    }

    public function getTotalCount(Source $source): int
    {
        return (int)$this->baseQuery($source)
            ->site('*')
            ->unique()
            ->count();
    }

    /**
     * Returns rows for the group view.
     *
     * When $siteId is null, one row per element keyed to the primary site with
     * per-site titles alongside. Otherwise rows are scoped to the given site.
     *
     * @return array<int, array{element: \craft\base\ElementInterface, titles: array<int, string>}>
     */
    public function getElementsInSource(Source $source, ?int $siteId = null, ?string $search = null): array
    {
        $sites = Craft::$app->getSites()->getAllSites();

        if ($siteId !== null) {
            $query = $this->baseQuery($source)
                ->siteId($siteId)
                ->orderBy(['title' => SORT_ASC]);

            if ($search !== null && $search !== '') {
                $query->search($search);
            }

            $result = [];
            foreach ($query->all() as $element) {
                $result[] = [
                    'element' => $element,
                    'titles' => [$element->siteId => $element->title],
                ];
            }
            return $result;
        }

        $primarySite = Craft::$app->getSites()->getPrimarySite();

        $query = $this->baseQuery($source)
            ->siteId($primarySite->id)
            ->orderBy(['title' => SORT_ASC]);

        if ($search !== null && $search !== '') {
            $query->search($search);
        }

        $primary = $query->all();
        $elementIds = array_map(fn($e) => $e->id, $primary);

        $perSiteTitles = [];
        if (!empty($elementIds)) {
            foreach ($sites as $site) {
                $siteElements = $this->baseQuery($source)
                    ->id($elementIds)
                    ->siteId($site->id)
                    ->all();
                foreach ($siteElements as $se) {
                    $perSiteTitles[$se->id][$site->id] = $se->title;
                }
            }
        }

        $result = [];
        foreach ($primary as $element) {
            $result[] = [
                'element' => $element,
                'titles' => $perSiteTitles[$element->id] ?? [$element->siteId => $element->title],
            ];
        }
        return $result;
    }

    /**
     * Base element query for a source, scoped to its container and including
     * disabled elements. All reads go through this so tag and entry queries
     * share the same filtering semantics.
     */
    public function baseQuery(Source $source): ElementQueryInterface
    {
        return $source->type === Source::TYPE_TAG
            ? Tag::find()->groupId($source->id)->status(null)
            : Entry::find()->sectionId($source->id)->status(null);
    }

    /**
     * Returns custom fields available on a source — the union of every entry
     * type's field layout for an entry section, or the tag group's single
     * field layout. Used to populate the per-source field-config picker on
     * the settings screen.
     *
     * @return array<int, FieldInterface>
     */
    public function getAvailableFields(Source $source): array
    {
        $fields = [];

        if ($source->type === Source::TYPE_TAG) {
            $group = Craft::$app->getTags()->getTagGroupById($source->id);
            if ($group !== null) {
                foreach ($group->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                    $fields[$field->handle] = $field;
                }
            }
        } else {
            $section = Craft::$app->getEntries()->getSectionById($source->id);
            if ($section !== null) {
                foreach ($section->getEntryTypes() as $entryType) {
                    foreach ($entryType->getFieldLayout()?->getCustomFields() ?? [] as $field) {
                        $fields[$field->handle] = $field;
                    }
                }
            }
        }

        return array_values($fields);
    }

    /**
     * Returns the configured differentiator field handle for a source, or null.
     */
    public function getDifferentiatorHandle(Source $source): ?string
    {
        return Plugin::$plugin->getSettings()->getDifferentiatorHandle($source->uid);
    }

    /**
     * Returns the configured display field handles for a source.
     *
     * @return string[]
     */
    public function getDisplayHandles(Source $source): array
    {
        return Plugin::$plugin->getSettings()->getDisplayHandles($source->uid);
    }

    /**
     * Returns every relation that targets the given element ID — i.e. every
     * source element (entry, asset, etc.) that holds a reference to this
     * tag/entry through a relational field.
     *
     * Used by the duplicates view to let editors expand a cluster item and see
     * exactly what would move during a swap or merge.
     *
     * @return array<int, array{
     *     elementId: int,
     *     title: string,
     *     elementType: string,
     *     fieldHandle: string,
     *     fieldName: string,
     *     siteId: ?int,
     *     siteName: ?string,
     *     cpEditUrl: ?string,
     * }>
     */
    public function getUsages(int $elementId, int $limit = 200): array
    {
        $rows = (new Query())
            ->select(['fieldId', 'sourceId', 'sourceSiteId'])
            ->from(['{{%relations}}'])
            ->where(['targetId' => $elementId])
            ->limit($limit)
            ->all();

        if (empty($rows)) {
            return [];
        }

        $fieldsService = Craft::$app->getFields();
        $sitesService = Craft::$app->getSites();
        $elementsService = Craft::$app->getElements();

        $usages = [];
        foreach ($rows as $row) {
            $field = $fieldsService->getFieldById((int)$row['fieldId']);
            $siteId = $row['sourceSiteId'] !== null ? (int)$row['sourceSiteId'] : null;
            $site = $siteId !== null ? $sitesService->getSiteById($siteId) : null;

            $element = $elementsService->getElementById(
                (int)$row['sourceId'],
                null,
                $siteId ?? $sitesService->getPrimarySite()->id,
            );

            if ($element === null) {
                continue;
            }

            $usages[] = [
                'elementId' => (int)$element->id,
                'title' => (string)($element->title ?? ('#' . $element->id)),
                'elementType' => $this->_shortClassName($element::class),
                'fieldHandle' => $field?->handle ?? '?',
                'fieldName' => $field?->name ?? '?',
                'siteId' => $siteId,
                'siteName' => $site?->name,
                'cpEditUrl' => $element->getCpEditUrl(),
            ];
        }

        return $usages;
    }

    private function _shortClassName(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts) ?: $fqcn;
    }

    /**
     * Reads a stringy value out of a custom field on an element. Returns null
     * if the field isn't present, the value is empty, or it can't be coerced
     * to a sensible scalar (relations and matrix-like data are stringified
     * into a comma-joined preview).
     */
    public function readFieldValue(\craft\base\ElementInterface $element, string $handle): ?string
    {
        try {
            $value = $element->getFieldValue($handle);
        } catch (\Throwable) {
            return null;
        }

        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if ($value instanceof \craft\elements\db\ElementQueryInterface) {
            $titles = [];
            foreach ($value->all() as $related) {
                $title = (string)($related->title ?? '');
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
            return $titles === [] ? null : implode(', ', $titles);
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            $s = trim((string)$value);
            return $s === '' ? null : $s;
        }

        if (is_array($value)) {
            $flat = array_filter(array_map(fn($v) => is_scalar($v) ? (string)$v : null, $value));
            return $flat === [] ? null : implode(', ', $flat);
        }

        return null;
    }
}
