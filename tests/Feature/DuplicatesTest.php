<?php

declare(strict_types=1);

use JustinHoltWeb\TidyTags\Services\DuplicateDetector;
use JustinHoltWeb\TidyTags\Services\Sources;

function titlesOf(array $items): array
{
    $titles = array_map(fn (array $i) => $i['title'], $items);
    sort($titles);

    return $titles;
}

it('clusters typos in a section and finds similar titles', function () {
    $teams = $this->createSection('teams');
    $this->createEntry($teams, 'Manchester United');
    $this->createEntry($teams, 'Manchestor United');
    $this->createEntry($teams, 'Wrexham');
    $this->configure([$teams]);

    $clusters = app(DuplicateDetector::class)->findDuplicates(app(Sources::class)->getSource($teams->id));

    expect($clusters)->toHaveCount(1)
        ->and(titlesOf($clusters[0]))->toBe(['Manchester United', 'Manchestor United']);

    $similar = app(DuplicateDetector::class)->findSimilar('Manchster United');
    expect(titlesOf($similar))->toBe(['Manchester United', 'Manchestor United']);
});

it('clusters across sections', function () {
    $teams = $this->createSection('teams');
    $topics = $this->createSection('topics');
    $this->createEntry($teams, 'England');
    $this->createEntry($topics, 'England');
    $this->configure([$teams, $topics]);

    $clusters = app(DuplicateDetector::class)->findCrossSourceDuplicates();

    expect($clusters)->toHaveCount(1)->and($clusters[0])->toHaveCount(2);
});

it('sees a new entry after the scan was cached', function () {
    $teams = $this->createSection('teams');
    $this->createEntry($teams, 'Arsenal');
    $this->configure([$teams]);
    $source = app(Sources::class)->getSource($teams->id);

    expect(app(DuplicateDetector::class)->findDuplicates($source))->toBe([]);

    $this->createEntry($teams, 'Arsenl');

    expect(app(DuplicateDetector::class)->findDuplicates($source))->toHaveCount(1);
});
