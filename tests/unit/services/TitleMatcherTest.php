<?php

namespace justinholtweb\tidytagstests\unit\services;

use justinholtweb\tidytags\services\TitleMatcher;
use justinholtweb\tidytagstests\support\PluginTestCase;

class TitleMatcherTest extends PluginTestCase
{
    private function strict(): TitleMatcher
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_STRICT]);
        return $this->matcher();
    }

    // -- Strategy selection ---------------------------------------------------

    public function testDefaultStrategyIsFuzzy(): void
    {
        self::assertSame(TitleMatcher::STRATEGY_FUZZY, $this->matcher()->getStrategy());
    }

    public function testStrategyIsReadFromSettings(): void
    {
        self::assertSame(TitleMatcher::STRATEGY_STRICT, $this->strict()->getStrategy());
    }

    /**
     * A typo in config/tidytags.php should leave the plugin behaving as it did
     * before, not silently stop matching everything.
     */
    public function testUnrecognisedStrategyFallsBackToFuzzy(): void
    {
        $this->setPluginSettings(['matchStrategy' => 'nonsense']);

        self::assertSame(TitleMatcher::STRATEGY_FUZZY, $this->matcher()->getStrategy());
        self::assertSame(1, $this->matcher()->compare('Manchester', 'Manchestor', 2));
    }

    // -- Normalization --------------------------------------------------------

    public function testNormalizeLowercasesAndCollapsesWhitespace(): void
    {
        self::assertSame('new york', $this->matcher()->normalize('  New   YORK '));
    }

    public function testNormalizeReducesPunctuationToSpaces(): void
    {
        self::assertSame('shannon o brien', $this->matcher()->normalize("Shannon O'Brien"));
    }

    public function testNormalizeSpellsOutAmpersands(): void
    {
        self::assertSame('taff and daff', $this->matcher()->normalize('Taff & Daff'));
    }

    public function testNormalizeKeepsAccentedLettersIntact(): void
    {
        self::assertSame('atlético madrid', $this->matcher()->normalize('Atlético Madrid'));
    }

    public function testNormalizeKeepsDigits(): void
    {
        self::assertSame('forty20', $this->matcher()->normalize('Forty20'));
    }

    // -- Core key -------------------------------------------------------------

    public function testCoreKeyStripsLeadingAffix(): void
    {
        self::assertSame('bayern munich', $this->matcher()->coreKey('FC Bayern Munich'));
    }

    public function testCoreKeyStripsTrailingAffix(): void
    {
        self::assertSame('wrexham', $this->matcher()->coreKey('Wrexham AFC'));
    }

    public function testCoreKeyStripsAffixesFromBothEnds(): void
    {
        self::assertSame('porto', $this->matcher()->coreKey('FC Porto SC'));
    }

    /**
     * The case that makes position matter: stripping affixes anywhere would
     * collapse this into "fifa world cup" and report a duplicate of a genuinely
     * separate competition.
     */
    public function testCoreKeyLeavesAffixesInTheMiddleAlone(): void
    {
        $matcher = $this->matcher();

        self::assertSame(
            'fifa club world cup',
            $matcher->coreKey('FIFA Club World Cup'),
        );
        self::assertNotSame(
            $matcher->coreKey('FIFA World Cup'),
            $matcher->coreKey('FIFA Club World Cup'),
        );
    }

    public function testCoreKeyOfAnAllAffixTitleFallsBackToTheNormalizedTitle(): void
    {
        self::assertSame('fc', $this->matcher()->coreKey('FC'));
    }

    public function testCoreKeyIsUnaffectedWhenNoAffixesArePresent(): void
    {
        self::assertSame('leicester city', $this->matcher()->coreKey('Leicester City'));
    }

    // -- Qualifiers -----------------------------------------------------------

    public function testQualifiersFindsConfiguredTokens(): void
    {
        self::assertSame(['women'], $this->matcher()->qualifiers('Arsenal Women'));
    }

    public function testQualifiersReturnsSortedUniqueTokens(): void
    {
        self::assertSame(['ii', 'women'], $this->matcher()->qualifiers('Bayern Munich II Women'));
    }

    public function testQualifiersIsEmptyWhenNonePresent(): void
    {
        self::assertSame([], $this->matcher()->qualifiers('Arsenal'));
    }

    // -- Fuzzy strategy -------------------------------------------------------

    public function testFuzzyReportsZeroForNormalizedEquality(): void
    {
        self::assertSame(0, $this->matcher()->compare('New   York', 'new york', 2));
    }

    public function testFuzzyReportsDistanceForATypo(): void
    {
        self::assertSame(1, $this->matcher()->compare('Manchester', 'Manchestor', 2));
    }

    public function testFuzzyRejectsBeyondThreshold(): void
    {
        self::assertNull($this->matcher()->compare('Rugby', 'Rugbies', 1));
        self::assertSame(3, $this->matcher()->compare('Rugby', 'Rugbies', 3));
    }

    public function testFuzzyRejectsOnTheLengthGuardWithoutComputingDistance(): void
    {
        self::assertNull($this->matcher()->compare('Cat', 'Caterpillar', 2));
    }

    /**
     * Documents what fuzzy costs you, and why strict exists: these are two
     * unrelated clubs two characters apart.
     */
    public function testFuzzyFlagsUnrelatedShortClubNames(): void
    {
        self::assertSame(2, $this->matcher()->compare('Durham', 'Fulham', 2));
    }

    // -- Strict strategy: the matches it should find ---------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function affixVariantProvider(): array
    {
        return [
            'leading affix' => ['FC Bayern Munich', 'Bayern Munich'],
            'trailing affix added' => ['Wrexham', 'Wrexham AFC'],
            'trailing affix on the other side' => ['Real Madrid', 'Real Madrid CF'],
            'affix on the first argument' => ['Barrow AFC', 'Barrow'],
        ];
    }

    /**
     * @dataProvider affixVariantProvider
     */
    public function testStrictMatchesAcrossAffixVariants(string $a, string $b): void
    {
        self::assertSame(TitleMatcher::DISTANCE_AFFIX, $this->strict()->compare($a, $b, 2));
    }

    public function testStrictReportsZeroForExactMatches(): void
    {
        self::assertSame(0, $this->strict()->compare('Arsenal', 'arsenal', 2));
    }

    public function testStrictMatchesWhenBothSidesShareAQualifier(): void
    {
        self::assertSame(0, $this->strict()->compare('Somerset Women', 'somerset women', 2));
    }

    // -- Strict strategy: the matches it should refuse -------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function strictNonMatchProvider(): array
    {
        return [
            // A qualifier on one side only means a different team entirely.
            'womens side' => ['Arsenal', 'Arsenal Women'],
            'womens side, multi-word' => ['Manchester United', 'Manchester United Women'],
            'tiered competition' => ['County Championship', 'County Championship One'],
            'second XI' => ['Bayern Munich', 'Bayern Munich II'],
            // Player names must not collide with national team names.
            'player vs country' => ['Jordan Pickford', 'Jordan'],
            // Edit distance would flag these; strict must not.
            'unrelated short names' => ['Durham', 'Fulham'],
            'unrelated numbered names' => ['Top 10', 'Top 14'],
            // A mid-title affix is not noise.
            'affix in the middle' => ['FIFA World Cup', 'FIFA Club World Cup'],
            // Genuinely unrelated.
            'no relation at all' => ['Arsenal', 'Chelsea'],
        ];
    }

    /**
     * @dataProvider strictNonMatchProvider
     */
    public function testStrictRefusesNonMatches(string $a, string $b): void
    {
        self::assertNull($this->strict()->compare($a, $b, 2));
    }

    public function testStrictIgnoresTheThresholdEntirely(): void
    {
        $matcher = $this->strict();

        foreach ([0, 1, 2, 10] as $threshold) {
            self::assertNull($matcher->compare('Durham', 'Fulham', $threshold));
            self::assertSame(
                TitleMatcher::DISTANCE_AFFIX,
                $matcher->compare('FC Bayern Munich', 'Bayern Munich', $threshold),
            );
        }
    }

    // -- Configurable token lists ---------------------------------------------

    public function testAffixTokensAreConfigurable(): void
    {
        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'affixTokens' => ['club'],
        ]);

        // "fc" is no longer an affix, so this pair stops matching...
        self::assertNull($this->matcher()->compare('FC Bayern Munich', 'Bayern Munich', 2));
        // ...and "club" now is.
        self::assertSame(
            TitleMatcher::DISTANCE_AFFIX,
            $this->matcher()->compare('Club Brugge', 'Brugge', 2),
        );
    }

    public function testQualifierTokensAreConfigurable(): void
    {
        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'qualifierTokens' => ['veterans'],
        ]);

        // "women" is no longer a qualifier, so the veto stops applying.
        self::assertSame(0, $this->matcher()->compare('Arsenal Women', 'arsenal women', 2));
        self::assertNull($this->matcher()->compare('Arsenal', 'Arsenal Veterans', 2));
    }

    public function testTokenListsAcceptANewlineSeparatedString(): void
    {
        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'affixTokens' => "FC\nAFC",
            'qualifierTokens' => "Women,  Ladies\n\n",
        ]);

        $matcher = $this->matcher();

        self::assertSame(TitleMatcher::DISTANCE_AFFIX, $matcher->compare('FC Bayern Munich', 'Bayern Munich', 2));
        self::assertNull($matcher->compare('Arsenal', 'Arsenal Ladies', 2));
    }

    public function testEmptyTokenListsDegradeToExactMatchingOnly(): void
    {
        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'affixTokens' => [],
            'qualifierTokens' => [],
        ]);

        $matcher = $this->matcher();

        self::assertSame(0, $matcher->compare('Arsenal', 'arsenal', 2));
        self::assertNull($matcher->compare('FC Bayern Munich', 'Bayern Munich', 2));
    }
}
