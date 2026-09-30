<?php

namespace justinholtweb\tidytags\models;

use craft\base\Model;
use justinholtweb\tidytags\services\TitleMatcher;

class Settings extends Model
{
    /**
     * UIDs of channel sections that should be treated as tag-like entry sources.
     *
     * @var string[]
     */
    public array $tagLikeSectionUids = [];

    /**
     * How titles are compared — {@see TitleMatcher::STRATEGY_FUZZY} or
     * {@see TitleMatcher::STRATEGY_STRICT}.
     *
     * Fuzzy is the default because it suits the vocabulary most sites have:
     * free-text tags typed by editors, where the duplicates are typos. Strict
     * suits controlled vocabularies of proper nouns — clubs, competitions,
     * places, people — where names sit close together in edit space and the
     * real variation is whole affix words rather than misspellings.
     */
    public string $matchStrategy = TitleMatcher::STRATEGY_FUZZY;

    /**
     * Tokens stripped from the start and end of a title before comparing, under
     * the strict strategy. Unused by the fuzzy strategy.
     *
     * The default covers the club prefixes and suffixes common in association
     * football and rugby, which is where entrified tag vocabularies most often
     * collide. Override it for your own domain.
     *
     * Accepts either an array of tokens or a string of whitespace/comma
     * separated tokens, so the CP textarea and `config/tidytags.php` can both
     * write it.
     *
     * @var string[]|string
     */
    public $affixTokens = [
        'fc', 'afc', 'cfc', 'rfc', 'ufc', 'cf', 'sc', 'ac', 'as', 'ss', 'ssc',
        'sv', 'vfb', 'vfl', 'bsc', 'rc', 'cd', 'ud', 'ca', 'sd', 'fk', 'bk',
        'if', 'ik', 'nk', 'hk', 'sk',
    ];

    /**
     * Tokens that make two otherwise-matching titles different things, under the
     * strict strategy. Unused by the fuzzy strategy.
     *
     * When one title carries one of these and the other does not, the pair is
     * rejected outright. This is what keeps "Arsenal" from being reported as a
     * duplicate of "Arsenal Women", or "County Championship" of "County
     * Championship One".
     *
     * This list is the accuracy-critical setting in strict mode, and it is
     * domain-specific: a cricket, rugby or futsal vocabulary needs different
     * words from a football one. Expect to curate it against your own titles.
     *
     * Accepts either an array of tokens or a string of whitespace/comma
     * separated tokens.
     *
     * @var string[]|string
     */
    public $qualifierTokens = [
        'women', 'womens', 'ladies', 'w', 'femminile', 'feminine', 'feminin',
        'femenino', 'men', 'mens',
        'ii', 'iii', 'b', 'reserves', 'academy', 'youth', 'development',
        'u16', 'u18', 'u19', 'u20', 'u21', 'u23',
        'one', 'two', 'three', 'elite',
    ];

    /**
     * Per-source field configuration, keyed by source UID (tag group UID or
     * section UID). Each entry can specify:
     *
     *   - `differentiator`: handle of a custom field used to distinguish
     *     same-named items (e.g. a "Sport" field that separates
     *     "England (Football)" from "England (Cricket)"). When set, two items
     *     with the same normalized title but different differentiator values
     *     are NOT clustered as duplicates.
     *   - `display`: list of field handles whose values should be shown next
     *     to each item in duplicate clusters and the editor "did you mean"
     *     warning, so reviewers can tell visually similar items apart.
     *
     * @var array<string, array{differentiator?: string, display?: string[]}>
     */
    public array $sourceFieldConfig = [];

    public function rules(): array
    {
        return [
            [['tagLikeSectionUids'], 'each', 'rule' => ['string']],
            [
                ['affixTokens', 'qualifierTokens'],
                'filter',
                'filter' => [self::class, 'normalizeTokenList'],
            ],
            [
                ['matchStrategy'],
                'in',
                'range' => [TitleMatcher::STRATEGY_FUZZY, TitleMatcher::STRATEGY_STRICT],
            ],
            [['sourceFieldConfig'], 'safe'],
        ];
    }

    /**
     * Returns the configured differentiator field handle for a source UID,
     * or null if none is configured.
     */
    public function getDifferentiatorHandle(string $sourceUid): ?string
    {
        $handle = $this->sourceFieldConfig[$sourceUid]['differentiator'] ?? null;
        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * Returns configured display field handles for a source UID.
     *
     * @return string[]
     */
    public function getDisplayHandles(string $sourceUid): array
    {
        $handles = $this->sourceFieldConfig[$sourceUid]['display'] ?? [];
        if (!is_array($handles)) {
            return [];
        }
        return array_values(array_filter(
            array_map(fn($h) => is_string($h) ? $h : '', $handles),
            fn($h) => $h !== '',
        ));
    }

    /**
     * Coerces a token list into a clean, lowercased, de-duplicated array.
     *
     * Accepts an array (from `config/tidytags.php`) or a string of tokens
     * separated by newlines, commas or spaces (from the settings screen), so
     * an operator can paste a curated list in whatever shape they have it.
     *
     * @param mixed $value
     * @return string[]
     */
    public static function normalizeTokenList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $tokens = [];
        foreach ($value as $token) {
            if (!is_string($token)) {
                continue;
            }
            $token = mb_strtolower(trim($token));
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return string[]
     */
    public function getAffixTokens(): array
    {
        return self::normalizeTokenList($this->affixTokens);
    }

    /**
     * @return string[]
     */
    public function getQualifierTokens(): array
    {
        return self::normalizeTokenList($this->qualifierTokens);
    }
}
