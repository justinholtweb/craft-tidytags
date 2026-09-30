<?php

namespace justinholtweb\tidytags\services;

use craft\base\Component;
use justinholtweb\tidytags\Plugin;

/**
 * Decides whether two titles name the same thing.
 *
 * Two strategies are available, selected by the `matchStrategy` setting:
 *
 * - **fuzzy** (default) — normalized equality, then Levenshtein distance within
 *   a threshold. Good for catching typos in free-text tags ("Manchestor" →
 *   "Manchester"), which is the case most tag vocabularies suffer from.
 *
 * - **strict** — normalized equality, then equality after stripping affixes
 *   from the start and end of the title ("FC Bayern Munich" → "Bayern Munich").
 *   No edit distance at all. Additionally, a match is *rejected* when one title
 *   carries a qualifier token the other lacks, so "Arsenal" is not reported as a
 *   duplicate of "Arsenal Women".
 *
 * Which one is right depends entirely on the vocabulary. In a corpus of short,
 * proper-noun names — sports clubs, place names, people — names sit close
 * together in edit space, so edit distance generates false positives
 * ("Durham"/"Fulham" are two characters apart) while missing the differences
 * that actually occur, which are whole affix words rather than typos. In a
 * corpus of editor-typed free text, the reverse is true. Hence the choice
 * rather than a compromise.
 */
class TitleMatcher extends Component
{
    public const STRATEGY_FUZZY = 'fuzzy';
    public const STRATEGY_STRICT = 'strict';

    /**
     * Distance reported for a strict-mode match that only held after affix
     * stripping. Exact matches report 0, so callers that sort by distance still
     * put the unambiguous matches first.
     */
    public const DISTANCE_AFFIX = 1;

    /**
     * Returns the active strategy, falling back to fuzzy for an unrecognised
     * value so a typo in `config/tidytags.php` degrades to today's behaviour
     * rather than silently matching nothing.
     */
    public function getStrategy(): string
    {
        $strategy = Plugin::$plugin->getSettings()->matchStrategy ?? self::STRATEGY_FUZZY;

        return $strategy === self::STRATEGY_STRICT
            ? self::STRATEGY_STRICT
            : self::STRATEGY_FUZZY;
    }

    /**
     * Compares two titles.
     *
     * @return int|null The distance between them (0 = identical once
     *                  normalized), or null when they are not a match.
     */
    public function compare(string $a, string $b, int $threshold): ?int
    {
        return $this->getStrategy() === self::STRATEGY_STRICT
            ? $this->compareStrict($a, $b)
            : $this->compareFuzzy($a, $b, $threshold);
    }

    /**
     * Normalized equality, then Levenshtein within the threshold.
     *
     * The length pre-check is not just an optimisation: it short-circuits
     * levenshtein() for the overwhelming majority of pairs, which matters
     * because callers compare one title against an entire vocabulary.
     */
    public function compareFuzzy(string $a, string $b, int $threshold): ?int
    {
        $a = $this->normalize($a);
        $b = $this->normalize($b);

        if ($a === $b) {
            return 0;
        }
        if (abs(strlen($a) - strlen($b)) > $threshold) {
            return null;
        }

        $distance = levenshtein($a, $b);

        return $distance <= $threshold ? $distance : null;
    }

    /**
     * Exact-or-affix equality, gated on the two titles carrying the same
     * qualifier tokens.
     *
     * The qualifier check runs first and is a hard veto. "Arsenal" and "Arsenal
     * Women" reduce to the same core key, so without the veto they would be
     * reported as duplicates — and they are two different teams. Treating a
     * qualifier asymmetry as "definitely different" is the opposite of how the
     * differentiator field treats a missing value, and deliberately so: a
     * missing differentiator means *unknown*, whereas a qualifier word present
     * in the title itself is a positive statement about which thing this is.
     */
    public function compareStrict(string $a, string $b): ?int
    {
        if ($this->qualifiers($a) !== $this->qualifiers($b)) {
            return null;
        }

        if ($this->normalize($a) === $this->normalize($b)) {
            return 0;
        }

        $keyA = $this->coreKey($a);

        return $keyA !== '' && $keyA === $this->coreKey($b)
            ? self::DISTANCE_AFFIX
            : null;
    }

    /**
     * Lowercases, spells out ampersands, and reduces everything that isn't a
     * letter or number to a single space. Unicode-aware so accented names are
     * not mangled into separate tokens.
     */
    public function normalize(string $title): string
    {
        $title = mb_strtolower(trim($title));
        $title = str_replace('&', ' and ', $title);
        $title = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $title) ?? '';

        return trim(preg_replace('/\s+/u', ' ', $title) ?? '');
    }

    /**
     * The normalized title with configured affixes removed from the start and
     * end only.
     *
     * Position matters. Stripping affixes anywhere in the string would turn
     * "FIFA Club World Cup" into "FIFA World Cup" and report a duplicate of a
     * genuinely separate competition, whereas the real-world variation being
     * targeted — "FC Bayern Munich" vs "Bayern Munich", "Wrexham" vs "Wrexham
     * AFC" — is always leading or trailing.
     *
     * A title made up entirely of affixes is returned normalized rather than
     * empty, so it can still match itself.
     */
    public function coreKey(string $title): string
    {
        $tokens = $this->tokens($title);
        $affixes = $this->affixTokens();

        while ($tokens !== [] && in_array($tokens[0], $affixes, true)) {
            array_shift($tokens);
        }
        while ($tokens !== [] && in_array($tokens[count($tokens) - 1], $affixes, true)) {
            array_pop($tokens);
        }

        return $tokens === [] ? $this->normalize($title) : implode(' ', $tokens);
    }

    /**
     * The configured qualifier tokens present in a title, sorted so two titles
     * can be compared directly.
     *
     * @return string[]
     */
    public function qualifiers(string $title): array
    {
        $found = array_unique(array_intersect($this->tokens($title), $this->qualifierTokens()));
        sort($found);

        return $found;
    }

    /**
     * @return string[]
     */
    public function tokens(string $title): array
    {
        $normalized = $this->normalize($title);

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @return string[]
     */
    private function affixTokens(): array
    {
        return Plugin::$plugin->getSettings()->getAffixTokens();
    }

    /**
     * @return string[]
     */
    private function qualifierTokens(): array
    {
        return Plugin::$plugin->getSettings()->getQualifierTokens();
    }
}
