<?php

namespace justinholtweb\tidytags\models;

use craft\base\Model;

class Settings extends Model
{
    /**
     * UIDs of channel sections that should be treated as tag-like entry sources.
     *
     * @var string[]
     */
    public array $tagLikeSectionUids = [];

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
}
