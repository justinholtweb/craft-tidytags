<?php

namespace justinholtweb\tidytags\services;

use Craft;
use craft\base\Component;
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
}
