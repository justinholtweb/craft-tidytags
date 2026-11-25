<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Models;

use CraftCms\Cms\Plugin\PluginSettings;
use Illuminate\Validation\Rule;
use JustinHoltWeb\TidyTags\Services\TitleMatcher;

/**
 * Tidy Tags' settings. The 5.x keys are unchanged, so an upgraded site keeps what it had in
 * project config, and `config/craft/tidytags.php` overrides them as `config/tidytags.php` did.
 *
 * List settings accept null: Laravel turns a blank field into null before Craft assigns it.
 */
class Settings extends PluginSettings
{
    /**
     * UIDs of channel sections treated as tag-like sources.
     *
     * @var list<string>|null
     */
    public ?array $tagLikeSectionUids = [];

    /**
     * Tag-like sections whose entries may be renamed, merged and deleted. UIDs that aren't also
     * in {@see $tagLikeSectionUids} are ignored.
     *
     * @var list<string>|null
     */
    public ?array $writableSectionUids = [];

    /** {@see TitleMatcher::STRATEGY_FUZZY} or {@see TitleMatcher::STRATEGY_STRICT}. */
    public string $matchStrategy = TitleMatcher::STRATEGY_FUZZY;

    /**
     * Strict only: words stripped from the start and end of a title before comparing. An array, or
     * a string of tokens separated by newlines, commas or spaces.
     *
     * @var list<string>|string|null
     */
    public array|string|null $affixTokens = [
        'fc', 'afc', 'cfc', 'rfc', 'ufc', 'cf', 'sc', 'ac', 'as', 'ss', 'ssc',
        'sv', 'vfb', 'vfl', 'bsc', 'rc', 'cd', 'ud', 'ca', 'sd', 'fk', 'bk',
        'if', 'ik', 'nk', 'hk', 'sk',
    ];

    /**
     * Strict only: words that make two otherwise-matching titles different things.
     *
     * @var list<string>|string|null
     */
    public array|string|null $qualifierTokens = [
        'women', 'womens', 'ladies', 'w', 'femminile', 'feminine', 'feminin',
        'femenino', 'men', 'mens',
        'ii', 'iii', 'b', 'reserves', 'academy', 'youth', 'development',
        'u16', 'u18', 'u19', 'u20', 'u21', 'u23',
        'one', 'two', 'three', 'elite',
    ];

    /**
     * Per-section field configuration, keyed by section UID: `differentiator` (a field handle
     * that separates same-named items) and `display` (field handles shown beside each item).
     *
     * @var array<string, array<string, mixed>>|null
     */
    public ?array $sourceFieldConfig = [];

    /** Items per page on the Manage screen. */
    public int $pageSize = 100;

    /** Clusters per page on the Duplicates screen. */
    public int $duplicatesPageSize = 25;

    public function getRules(): array
    {
        return [
            'tagLikeSectionUids' => ['nullable', 'array'],
            'tagLikeSectionUids.*' => ['string'],
            'writableSectionUids' => ['nullable', 'array'],
            'writableSectionUids.*' => ['string'],
            'matchStrategy' => ['required', Rule::in([TitleMatcher::STRATEGY_FUZZY, TitleMatcher::STRATEGY_STRICT])],
            'affixTokens' => ['nullable'],
            'qualifierTokens' => ['nullable'],
            'sourceFieldConfig' => ['nullable', 'array'],
            'pageSize' => ['required', 'integer', 'min:1', 'max:1000'],
            'duplicatesPageSize' => ['required', 'integer', 'min:1', 'max:500'],
        ];
    }

    /**
     * The settings screen and saved project config get the token lists as text, one per line,
     * which is what the textareas edit. Arrays from 5.x or a config file still work.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function validationData(): array
    {
        $data = parent::validationData();
        $data['affixTokens'] = implode("\n", $this->getAffixTokens());
        $data['qualifierTokens'] = implode("\n", $this->getQualifierTokens());

        return $data;
    }

    /**
     * @return list<string>
     */
    public function getTagLikeSectionUids(): array
    {
        return array_values(array_filter($this->tagLikeSectionUids ?? [], 'is_string'));
    }

    /**
     * Writable sections that are also tag-like.
     *
     * @return list<string>
     */
    public function getWritableSectionUids(): array
    {
        return array_values(array_intersect(
            array_filter($this->writableSectionUids ?? [], 'is_string'),
            $this->getTagLikeSectionUids(),
        ));
    }

    public function isSectionTagLike(string $sectionUid): bool
    {
        return in_array($sectionUid, $this->getTagLikeSectionUids(), true);
    }

    public function isSectionWritable(string $sectionUid): bool
    {
        return in_array($sectionUid, $this->getWritableSectionUids(), true);
    }

    public function getDifferentiatorHandle(string $sectionUid): ?string
    {
        $handle = $this->sourceFieldConfig[$sectionUid]['differentiator'] ?? null;

        return is_string($handle) && $handle !== '' ? $handle : null;
    }

    /**
     * @return list<string>
     */
    public function getDisplayHandles(string $sectionUid): array
    {
        $handles = $this->sourceFieldConfig[$sectionUid]['display'] ?? [];

        return is_array($handles)
            ? array_values(array_filter($handles, fn ($h) => is_string($h) && $h !== ''))
            : [];
    }

    /**
     * @return list<string>
     */
    public function getAffixTokens(): array
    {
        return self::normalizeTokenList($this->affixTokens);
    }

    /**
     * @return list<string>
     */
    public function getQualifierTokens(): array
    {
        return self::normalizeTokenList($this->qualifierTokens);
    }

    /**
     * A clean, lowercased, de-duplicated token list from an array or a string of tokens separated
     * by newlines, commas or spaces.
     *
     * @return list<string>
     */
    public static function normalizeTokenList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,]+/u', $value) ?: [];
        }
        if (! is_array($value)) {
            return [];
        }

        $tokens = [];
        foreach ($value as $token) {
            if (is_string($token) && ($token = mb_strtolower(trim($token))) !== '') {
                $tokens[$token] = true;
            }
        }

        return array_keys($tokens);
    }
}
