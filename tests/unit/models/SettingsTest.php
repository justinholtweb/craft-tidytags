<?php

namespace justinholtweb\tidytagstests\unit\models;

use craft\test\TestCase;
use justinholtweb\tidytags\models\Settings;
use justinholtweb\tidytags\services\TitleMatcher;

class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $settings = new Settings();

        self::assertSame([], $settings->tagLikeSectionUids);
        self::assertSame([], $settings->sourceFieldConfig);
        self::assertNull($settings->getDifferentiatorHandle('any-uid'));
        self::assertSame([], $settings->getDisplayHandles('any-uid'));
    }

    public function testGetDifferentiatorHandle(): void
    {
        $settings = new Settings();
        $settings->sourceFieldConfig = [
            'a' => ['differentiator' => 'sport'],
            'b' => ['differentiator' => ''],
            'c' => ['display' => ['region']],
        ];

        self::assertSame('sport', $settings->getDifferentiatorHandle('a'));
        self::assertNull($settings->getDifferentiatorHandle('b'), 'Empty string should be treated as unset');
        self::assertNull($settings->getDifferentiatorHandle('c'));
        self::assertNull($settings->getDifferentiatorHandle('missing'));
    }

    public function testGetDifferentiatorHandleIgnoresNonStrings(): void
    {
        $settings = new Settings();
        $settings->sourceFieldConfig = ['a' => ['differentiator' => ['sport']]];

        self::assertNull($settings->getDifferentiatorHandle('a'));
    }

    public function testGetDisplayHandles(): void
    {
        $settings = new Settings();
        $settings->sourceFieldConfig = [
            'a' => ['display' => ['sport', 'region']],
            'b' => ['display' => 'sport'],
            'c' => ['display' => ['sport', '', null, 42, 'region']],
        ];

        self::assertSame(['sport', 'region'], $settings->getDisplayHandles('a'));
        self::assertSame([], $settings->getDisplayHandles('b'), 'Non-array display config should degrade to empty');
        self::assertSame(
            ['sport', 'region'],
            $settings->getDisplayHandles('c'),
            'Blank and non-string handles should be dropped and keys reindexed',
        );
        self::assertSame([], $settings->getDisplayHandles('missing'));
    }

    public function testValidationAcceptsStringUids(): void
    {
        $settings = new Settings([
            'tagLikeSectionUids' => ['uid-one', 'uid-two'],
            'sourceFieldConfig' => ['uid-one' => ['differentiator' => 'sport']],
        ]);

        self::assertTrue($settings->validate(), print_r($settings->getErrors(), true));
    }

    public function testValidationRejectsNonStringUids(): void
    {
        $settings = new Settings();
        $settings->tagLikeSectionUids = [['nested']];

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('tagLikeSectionUids', $settings->getErrors());
    }

    // -- Matching settings ----------------------------------------------------

    public function testMatchStrategyDefaultsToFuzzy(): void
    {
        self::assertSame(TitleMatcher::STRATEGY_FUZZY, (new Settings())->matchStrategy);
    }

    public function testTokenListsHaveNonEmptyDefaults(): void
    {
        $settings = new Settings();

        self::assertContains('fc', $settings->getAffixTokens());
        self::assertContains('women', $settings->getQualifierTokens());
    }

    public function testValidationAcceptsBothStrategies(): void
    {
        foreach ([TitleMatcher::STRATEGY_FUZZY, TitleMatcher::STRATEGY_STRICT] as $strategy) {
            $settings = new Settings(['matchStrategy' => $strategy]);
            self::assertTrue($settings->validate(), print_r($settings->getErrors(), true));
        }
    }

    public function testValidationRejectsAnUnknownStrategy(): void
    {
        $settings = new Settings(['matchStrategy' => 'approximate']);

        self::assertFalse($settings->validate());
        self::assertArrayHasKey('matchStrategy', $settings->getErrors());
    }

    /**
     * The settings screen posts a textarea, so validation has to turn a string
     * into the array the rest of the plugin expects.
     */
    public function testValidationNormalizesAPostedTokenString(): void
    {
        $settings = new Settings([
            'affixTokens' => "FC\nAFC,  cf\n\n",
            'qualifierTokens' => 'Women, Ladies',
        ]);

        self::assertTrue($settings->validate(), print_r($settings->getErrors(), true));
        self::assertSame(['fc', 'afc', 'cf'], $settings->affixTokens);
        self::assertSame(['women', 'ladies'], $settings->qualifierTokens);
    }

    public function testTokenNormalizationLowercasesTrimsAndDeduplicates(): void
    {
        self::assertSame(
            ['fc', 'afc'],
            Settings::normalizeTokenList(['  FC ', 'fc', 'AFC', '', 'FC']),
        );
    }

    public function testTokenNormalizationDiscardsNonStrings(): void
    {
        self::assertSame(['fc'], Settings::normalizeTokenList(['fc', ['afc'], null, 42]));
        self::assertSame([], Settings::normalizeTokenList([]));
    }

    public function testEmptyTokenListsAreAllowed(): void
    {
        $settings = new Settings(['affixTokens' => [], 'qualifierTokens' => '']);

        self::assertTrue($settings->validate(), print_r($settings->getErrors(), true));
        self::assertSame([], $settings->getAffixTokens());
        self::assertSame([], $settings->getQualifierTokens());
    }
}
