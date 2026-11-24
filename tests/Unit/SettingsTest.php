<?php

declare(strict_types=1);

use JustinHoltWeb\TidyTags\Models\Settings;

it('only treats tag-like sections as writable', function () {
    $settings = new Settings;
    $settings->tagLikeSectionUids = ['a', 'b'];
    $settings->writableSectionUids = ['b', 'stray'];

    expect($settings->getWritableSectionUids())->toBe(['b'])
        ->and($settings->isSectionWritable('stray'))->toBeFalse()
        ->and($settings->isSectionTagLike('a'))->toBeTrue();
});

it('accepts null lists from a blank settings form', function () {
    $settings = new Settings;
    $settings->tagLikeSectionUids = null;
    $settings->writableSectionUids = null;

    expect($settings->getTagLikeSectionUids())->toBe([])
        ->and($settings->getWritableSectionUids())->toBe([]);
});

it('reads token lists from arrays or text, and saves them as text', function () {
    $settings = new Settings;
    $settings->affixTokens = "FC\n afc, cf  fc";
    $settings->qualifierTokens = ['Women', ' II '];

    expect($settings->getAffixTokens())->toBe(['fc', 'afc', 'cf'])
        ->and($settings->getQualifierTokens())->toBe(['women', 'ii'])
        ->and($settings->validationData()['affixTokens'])->toBe("fc\nafc\ncf")
        ->and($settings->validationData()['qualifierTokens'])->toBe("women\nii");
});

it('reads per-section differentiator and display fields', function () {
    $settings = new Settings;
    $settings->sourceFieldConfig = ['uid' => ['differentiator' => 'sport', 'display' => ['sport', '', 'country']]];

    expect($settings->getDifferentiatorHandle('uid'))->toBe('sport')
        ->and($settings->getDisplayHandles('uid'))->toBe(['sport', 'country'])
        ->and($settings->getDifferentiatorHandle('other'))->toBeNull();
});
