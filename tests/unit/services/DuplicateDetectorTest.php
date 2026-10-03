<?php

namespace justinholtweb\tidytagstests\unit\services;

use Craft;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytags\services\TitleMatcher;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

class DuplicateDetectorTest extends PluginTestCase
{
    public function testFindDuplicatesClustersNearMatches(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchester');
        Fixtures::createTag($group, 'Manchestor');
        Fixtures::createTag($group, 'Liverpool');

        $source = $this->sources()->getTagSource($group->id);
        $clusters = $this->detector()->findDuplicates($source);

        self::assertCount(1, $clusters);
        $titles = $this->titles($clusters[0]);
        sort($titles);
        self::assertSame(['Manchester', 'Manchestor'], $titles);
    }

    public function testFindDuplicatesIsCaseAndWhitespaceInsensitive(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'New   York');
        Fixtures::createTag($group, 'new york');

        $source = $this->sources()->getTagSource($group->id);
        $clusters = $this->detector()->findDuplicates($source);

        self::assertCount(1, $clusters);
        self::assertCount(2, $clusters[0]);
    }

    public function testFindDuplicatesReturnsNothingForDistinctTitles(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');
        Fixtures::createTag($group, 'Elephant');

        $source = $this->sources()->getTagSource($group->id);

        self::assertSame([], $this->detector()->findDuplicates($source));
    }

    public function testThresholdIsRespected(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Rugby');
        Fixtures::createTag($group, 'Rugbies');

        $source = $this->sources()->getTagSource($group->id);

        self::assertSame([], $this->detector()->findDuplicates($source, null, 1));
        self::assertCount(1, $this->detector()->findDuplicates($source, null, 3));
    }

    /**
     * Craft enforces unique titles within a tag group, so exact same-name
     * collisions only exist on entry-backed sources — which is where the
     * differentiator earns its keep.
     */
    public function testDifferentiatorSplitsIdenticallyTitledEntries(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $section = Fixtures::createChannelSection('teams', [$sport]);
        Fixtures::createEntry($section, 'England', ['sport' => 'Football']);
        Fixtures::createEntry($section, 'England', ['sport' => 'Cricket']);

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'sourceFieldConfig' => [$section->uid => ['differentiator' => 'sport']],
        ]);

        $source = $this->sources()->getEntrySource($section->id);

        self::assertSame(
            [],
            $this->detector()->findDuplicates($source),
            'Different differentiator values must not cluster',
        );
    }

    public function testDifferentiatorSplitsNearDuplicateTags(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $group = Fixtures::createTagGroup('teams', [$sport]);
        Fixtures::createTag($group, 'England', ['sport' => 'Football']);
        Fixtures::createTag($group, 'Englnd', ['sport' => 'Cricket']);

        $this->setPluginSettings([
            'sourceFieldConfig' => [$group->uid => ['differentiator' => 'sport']],
        ]);

        $source = $this->sources()->getTagSource($group->id);

        self::assertSame([], $this->detector()->findDuplicates($source));
    }

    public function testMissingDifferentiatorValuesStillCluster(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $section = Fixtures::createChannelSection('teams', [$sport]);
        Fixtures::createEntry($section, 'England', ['sport' => 'Football']);
        Fixtures::createEntry($section, 'England', ['sport' => '']);

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'sourceFieldConfig' => [$section->uid => ['differentiator' => 'sport']],
        ]);

        $source = $this->sources()->getEntrySource($section->id);
        $clusters = $this->detector()->findDuplicates($source);

        self::assertCount(1, $clusters, 'An unclassified item should still surface for review');
    }

    /**
     * Craft has no standalone CP edit page for tags, so `getCpEditUrl()` is
     * always null on Tag elements. The duplicates view and the "did you mean"
     * JS both guard on it, so tag rows render as plain text while entry rows
     * become links.
     */
    public function testTagItemsHaveNoCpEditUrlButEntryItemsDo(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchester');
        Fixtures::createTag($group, 'Manchestor');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Liverpool');
        Fixtures::createEntry($section, 'Liverpol');

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        $tagSource = $this->sources()->getTagSource($group->id);
        $entrySource = $this->sources()->getEntrySource($section->id);

        self::assertNull($this->detector()->findDuplicates($tagSource)[0][0]['cpEditUrl']);
        self::assertNotNull($this->detector()->findDuplicates($entrySource)[0][0]['cpEditUrl']);
    }

    public function testItemsAreEnrichedWithSourceAndDisplayValues(): void
    {
        $sport = Fixtures::createPlainTextField('sport');
        $group = Fixtures::createTagGroup('teams', [$sport]);
        Fixtures::createTag($group, 'England', ['sport' => 'Football']);
        Fixtures::createTag($group, 'Englannd', ['sport' => 'Football']);

        $this->setPluginSettings([
            'sourceFieldConfig' => [
                $group->uid => ['differentiator' => 'sport', 'display' => ['sport']],
            ],
        ]);

        $source = $this->sources()->getTagSource($group->id);
        $item = $this->detector()->findDuplicates($source)[0][0];

        self::assertSame($group->uid, $item['sourceUid']);
        self::assertSame($group->id, $item['sourceId']);
        self::assertSame(Source::TYPE_TAG, $item['sourceType']);
        self::assertTrue($item['sourceWritable']);
        self::assertSame('tidytags/group/' . $group->id, $item['sourceCpPath']);
        self::assertSame('Football', $item['differentiator']);
        self::assertSame('sport', $item['differentiatorHandle']);
        self::assertSame(['sport' => 'Football'], $item['displayValues']);
        self::assertArrayNotHasKey('_normalized', $item, 'Internal clustering key must not leak to callers');
        self::assertArrayHasKey('cpEditUrl', $item);
    }

    public function testFindAllDuplicatesCoversTagsAndConfiguredSections(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchester');
        Fixtures::createTag($group, 'Manchestor');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Liverpool');
        Fixtures::createEntry($section, 'Liverpol');

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        $results = $this->detector()->findAllDuplicates();

        self::assertCount(2, $results);

        $byHandle = [];
        foreach ($results as $result) {
            $byHandle[$result['source']->handle] = $result['clusters'];
        }

        self::assertArrayHasKey('animals', $byHandle);
        self::assertArrayHasKey('teams', $byHandle);
        self::assertCount(1, $byHandle['animals']);
        self::assertCount(1, $byHandle['teams']);
    }

    public function testFindAllDuplicatesSkipsSourcesWithoutClusters(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');
        Fixtures::createTag($group, 'Elephant');

        self::assertSame([], $this->detector()->findAllDuplicates());
    }

    public function testFindCrossSourceDuplicatesOnlyReturnsMultiSourceClusters(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Liverpool');
        // A same-source near-duplicate, which cross-source scanning should ignore.
        Fixtures::createTag($group, 'Manchester');
        Fixtures::createTag($group, 'Manchestor');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Liverpool');

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        $clusters = $this->detector()->findCrossSourceDuplicates();

        self::assertCount(1, $clusters);
        self::assertCount(2, $clusters[0]);

        $sourceTypes = array_map(fn(array $i) => $i['sourceType'], $clusters[0]);
        sort($sourceTypes);
        self::assertSame([Source::TYPE_ENTRY, Source::TYPE_TAG], $sourceTypes);
        self::assertSame(['Liverpool', 'Liverpool'], $this->titles($clusters[0]));
    }

    public function testFindSimilarMatchesTagsInAGroup(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchester');
        Fixtures::createTag($group, 'Liverpool');

        $matches = $this->detector()->findSimilar('Manchestor', $group->id);

        self::assertCount(1, $matches);
        self::assertSame('Manchester', $matches[0]['title']);
        self::assertSame(1, $matches[0]['distance']);
    }

    public function testFindSimilarReportsExactMatchesAtDistanceZeroFirst(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Manchestor');
        Fixtures::createTag($group, 'Manchester');

        $matches = $this->detector()->findSimilar('manchester', $group->id);

        self::assertSame('Manchester', $matches[0]['title']);
        self::assertSame(0, $matches[0]['distance']);
        self::assertSame(1, $matches[1]['distance']);
    }

    public function testFindSimilarAlsoScansConfiguredEntrySections(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Liverpool');

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        $matches = $this->detector()->findSimilar('Liverpol', $group->id);

        self::assertCount(1, $matches);
        self::assertSame('Liverpool', $matches[0]['title']);
        self::assertSame(Source::TYPE_ENTRY, $matches[0]['sourceType']);
        self::assertFalse($matches[0]['sourceWritable']);
    }

    public function testFindSimilarWithoutGroupIdOnlyScansEntrySections(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Liverpool');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Liverpool');

        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);

        $matches = $this->detector()->findSimilar('Liverpool');

        self::assertCount(1, $matches);
        self::assertSame(Source::TYPE_ENTRY, $matches[0]['sourceType']);
    }

    public function testFindSimilarReturnsEmptyForBlankTitle(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');

        self::assertSame([], $this->detector()->findSimilar('   ', $group->id));
    }

    public function testFindSimilarHandlesUnknownGroupId(): void
    {
        self::assertSame([], $this->detector()->findSimilar('Cat', 999999));
    }

    public function testFindSimilarRespectsLimit(): void
    {
        $group = Fixtures::createTagGroup('animals');
        foreach (['Cat', 'Cot', 'Cut', 'Bat'] as $title) {
            Fixtures::createTag($group, $title);
        }

        $matches = $this->detector()->findSimilar('Cat', $group->id, null, 2, 2);

        self::assertCount(2, $matches);
    }

    public function testDuplicateScanIsScopedToTheRequestedSite(): void
    {
        $second = Fixtures::createSite('secondary', 'Secondary');
        $group = Fixtures::createTagGroup('animals');
        $a = Fixtures::createTag($group, 'Manchester');
        $b = Fixtures::createTag($group, 'Manchestor');

        // Give the second site titles that are no longer similar.
        foreach ([$a->id => 'Alpha', $b->id => 'Omega'] as $id => $title) {
            $localized = Craft::$app->getElements()->getElementById($id, null, $second->id);
            $localized->title = $title;
            Craft::$app->getElements()->saveElement($localized);
        }

        $source = $this->sources()->getTagSource($group->id);

        self::assertCount(1, $this->detector()->findDuplicates($source));
        self::assertSame([], $this->detector()->findDuplicates($source, $second->id));
    }

    public function testDefaultThresholdIsTwo(): void
    {
        self::assertSame(2, $this->detector()->defaultThreshold);
    }

    // -- Strict strategy integration -----------------------------------------

    /**
     * Configures a tag-like entry section and switches the plugin to the strict
     * matching strategy.
     */
    private function strictSection(string $handle, array $titles): \craft\models\Section
    {
        $section = Fixtures::createChannelSection($handle);
        foreach ($titles as $title) {
            Fixtures::createEntry($section, $title);
        }

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
        ]);

        return $section;
    }

    public function testStrictStrategyClustersAffixVariants(): void
    {
        $section = $this->strictSection('teams', ['FC Bayern Munich', 'Bayern Munich', 'Arsenal']);

        $clusters = $this->detector()->findDuplicates(Source::fromSection($section));

        self::assertCount(1, $clusters);
        $titles = $this->titles($clusters[0]);
        sort($titles);
        self::assertSame(['Bayern Munich', 'FC Bayern Munich'], $titles);
    }

    public function testStrictStrategyDoesNotClusterQualifiedSides(): void
    {
        $section = $this->strictSection('teams', ['Arsenal', 'Arsenal Women']);

        self::assertSame([], $this->detector()->findDuplicates(Source::fromSection($section)));
    }

    /**
     * The same data under each strategy, to pin down the trade-off: fuzzy pairs
     * two unrelated clubs that are two characters apart, strict does not.
     */
    public function testStrategiesDisagreeOnEditDistanceNeighbours(): void
    {
        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Durham');
        Fixtures::createEntry($section, 'Fulham');

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'matchStrategy' => TitleMatcher::STRATEGY_FUZZY,
        ]);
        self::assertCount(1, $this->detector()->findDuplicates(Source::fromSection($section)));

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
        ]);
        self::assertSame([], $this->detector()->findDuplicates(Source::fromSection($section)));
    }

    public function testStrictStrategyFindSimilarReportsAffixMatches(): void
    {
        $this->strictSection('teams', ['Bayern Munich']);

        $matches = $this->detector()->findSimilar('FC Bayern Munich');

        self::assertCount(1, $matches);
        self::assertSame('Bayern Munich', $matches[0]['title']);
        self::assertSame(TitleMatcher::DISTANCE_AFFIX, $matches[0]['distance']);
    }

    public function testStrictStrategyFindSimilarIgnoresTypos(): void
    {
        $this->strictSection('teams', ['Manchester']);

        self::assertSame([], $this->detector()->findSimilar('Manchestor'));
    }

    public function testStrictStrategyFindSimilarRefusesQualifiedSides(): void
    {
        $this->strictSection('teams', ['Arsenal Women']);

        self::assertSame([], $this->detector()->findSimilar('Arsenal'));
    }

    /**
     * The case the strict strategy was built for: an editor creating a tag for
     * something already maintained as an entry, under a different affix.
     */
    public function testStrictStrategyFindsCrossSourceAffixDuplicates(): void
    {
        $group = Fixtures::createTagGroup('topics');
        Fixtures::createTag($group, 'FC Bayern Munich');

        $section = Fixtures::createChannelSection('teams');
        Fixtures::createEntry($section, 'Bayern Munich');

        $this->setPluginSettings([
            'tagLikeSectionUids' => [$section->uid],
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
        ]);

        $clusters = $this->detector()->findCrossSourceDuplicates();

        self::assertCount(1, $clusters);
        $sourceTypes = array_map(fn(array $i) => $i['sourceType'], $clusters[0]);
        sort($sourceTypes);
        self::assertSame([Source::TYPE_ENTRY, Source::TYPE_TAG], $sourceTypes);
    }

    /**
     * Builds two tag groups holding three and two clusters respectively.
     *
     * @return array{0: Source, 1: Source}
     */
    private function twoGroupsOfClusters(): array
    {
        $animals = Fixtures::createTagGroup('animals');
        foreach (['Cat', 'Cats', 'Dog', 'Dogs', 'Horse', 'Horses'] as $title) {
            Fixtures::createTag($animals, $title);
        }
        $plants = Fixtures::createTagGroup('plants');
        foreach (['Fern', 'Ferns', 'Moss', 'Mosses'] as $title) {
            Fixtures::createTag($plants, $title);
        }

        return [$this->sources()->getTagSource($animals->id), $this->sources()->getTagSource($plants->id)];
    }

    public function testDuplicatesPagePagesAcrossSourcesInSourceOrder(): void
    {
        [$animals, $plants] = $this->twoGroupsOfClusters();
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);

        $sourceNames = function(array $page) {
            return array_map(fn(array $c) => $c['source']->uid, $page['clusters']);
        };

        $first = $this->detector()->getDuplicatesPage('within', null, 2, 1, 2);
        self::assertSame(5, $first['total']);
        self::assertSame(3, $first['totalPages']);
        self::assertSame([1, 2], [$first['first'], $first['last']]);
        self::assertSame([$animals->uid, $animals->uid], $sourceNames($first));

        $second = $this->detector()->getDuplicatesPage('within', null, 2, 2, 2);
        self::assertSame([$animals->uid, $plants->uid], $sourceNames($second));

        $last = $this->detector()->getDuplicatesPage('within', null, 2, 3, 2);
        self::assertSame([$plants->uid], $sourceNames($last));
        self::assertSame(['Moss', 'Mosses'], $this->titles($last['clusters'][0]['items']));
        self::assertArrayHasKey('cpEditUrl', $last['clusters'][0]['items'][0], 'Page items are enriched.');
    }

    public function testDuplicatesPageClampsOutOfRangePages(): void
    {
        $this->twoGroupsOfClusters();

        self::assertSame(3, $this->detector()->getDuplicatesPage('within', null, 2, 99, 2)['page']);
        self::assertSame(1, $this->detector()->getDuplicatesPage('within', null, 2, -4, 2)['page']);
    }

    public function testDuplicatesPageHandlesNoClusters(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');

        $page = $this->detector()->getDuplicatesPage('within', null, 2, 1, 25);

        self::assertSame(0, $page['total']);
        self::assertSame(1, $page['totalPages']);
        self::assertSame([], $page['clusters']);
    }

    public function testDuplicatesPageCrossScopeHasNoSourcePerCluster(): void
    {
        $group = Fixtures::createTagGroup('teams');
        Fixtures::createTag($group, 'Arsenal');
        $section = Fixtures::createChannelSection('clubs');
        $this->setPluginSettings(['tagLikeSectionUids' => [$section->uid]]);
        Fixtures::createEntry($section, 'Arsenal');

        $page = $this->detector()->getDuplicatesPage('cross', null, 2, 1, 25);

        self::assertSame(1, $page['total']);
        self::assertNull($page['clusters'][0]['source']);
        self::assertCount(2, $page['clusters'][0]['items']);
    }

    /**
     * Scans are cached, so a change to a title must invalidate the cache
     * rather than leave a fixed duplicate on screen.
     */
    public function testCachedScanIsInvalidatedWhenATagChanges(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');
        $typo = Fixtures::createTag($group, 'Cats');
        $source = $this->sources()->getTagSource($group->id);

        self::assertCount(1, $this->detector()->findDuplicates($source));

        $typo->title = 'Giraffe';
        Craft::$app->getElements()->saveElement($typo);

        self::assertSame([], $this->detector()->findDuplicates($source));
    }

    public function testCachedScanIsInvalidatedWhenATagIsAdded(): void
    {
        $group = Fixtures::createTagGroup('animals');
        Fixtures::createTag($group, 'Cat');
        $source = $this->sources()->getTagSource($group->id);

        self::assertSame([], $this->detector()->findDuplicates($source));

        Fixtures::createTag($group, 'Cats');

        self::assertCount(1, $this->detector()->findDuplicates($source));
    }

    /**
     * Strategy and token lists are part of the cache key, so changing the
     * settings takes effect on the next scan.
     */
    public function testCachedScanFollowsMatchSettings(): void
    {
        $group = Fixtures::createTagGroup('teams');
        Fixtures::createTag($group, 'Bayern Munich');
        Fixtures::createTag($group, 'FC Bayern Munich');
        $source = $this->sources()->getTagSource($group->id);

        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);
        self::assertSame([], $this->detector()->findDuplicates($source));

        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_STRICT]);
        self::assertCount(1, $this->detector()->findDuplicates($source));

        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_STRICT, 'affixTokens' => []]);
        self::assertSame([], $this->detector()->findDuplicates($source));
    }
}
