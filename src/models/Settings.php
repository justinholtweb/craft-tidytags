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

    public function rules(): array
    {
        return [
            [['tagLikeSectionUids'], 'each', 'rule' => ['string']],
        ];
    }
}
