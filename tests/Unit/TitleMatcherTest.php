<?php

declare(strict_types=1);

use JustinHoltWeb\TidyTags\Models\Settings;
use JustinHoltWeb\TidyTags\Services\TitleMatcher;

function strictMatcher(): TitleMatcher
{
    $settings = new Settings;

    return new TitleMatcher(TitleMatcher::STRATEGY_STRICT, $settings->getAffixTokens(), $settings->getQualifierTokens());
}

it('defaults to fuzzy and falls back to fuzzy for an unknown strategy', function () {
    expect(new TitleMatcher()->getStrategy())->toBe(TitleMatcher::STRATEGY_FUZZY)
        ->and(new TitleMatcher('nonsense')->getStrategy())->toBe(TitleMatcher::STRATEGY_FUZZY)
        ->and(new TitleMatcher('nonsense')->compare('Manchester', 'Manchestor', 2))->toBe(1);
});

it('normalizes case, whitespace, punctuation and ampersands', function () {
    $m = new TitleMatcher;

    expect($m->normalize('  New   YORK '))->toBe('new york')
        ->and($m->normalize("Shannon O'Brien"))->toBe('shannon o brien')
        ->and($m->normalize('Taff & Daff'))->toBe('taff and daff')
        ->and($m->normalize('Atlético Madrid'))->toBe('atlético madrid');
});

it('matches typos under fuzzy, within the threshold', function () {
    $m = new TitleMatcher;

    expect($m->compare('Manchester United', 'Manchestor United', 2))->toBe(1)
        ->and($m->compare('Durham', 'Fulham', 1))->toBeNull();
});

it('matches whole affixes but not typos under strict', function () {
    $m = strictMatcher();

    expect($m->compare('FC Bayern Munich', 'Bayern Munich', 2))->not->toBeNull()
        ->and($m->compare('Wrexham', 'Wrexham AFC', 2))->not->toBeNull()
        ->and($m->compare('Manchester', 'Manchestor', 2))->toBeNull()
        ->and($m->compare('Essex', 'Sussex', 2))->toBeNull();
});

it('lets a qualifier veto a strict match', function () {
    $m = strictMatcher();

    expect($m->compare('Arsenal', 'Arsenal Women', 2))->toBeNull()
        ->and($m->compare('Bayern Munich', 'Bayern Munich II', 2))->toBeNull();
});

it('only strips affixes from the ends', function () {
    expect(strictMatcher()->compare('FIFA Club World Cup', 'FIFA World Cup', 2))->toBeNull();
});
