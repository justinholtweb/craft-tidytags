<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;

it('lists duplicates as JSON, under the new and the Craft 5 name', function () {
    $teams = $this->createSection('teams');
    $this->createEntry($teams, 'Arsenal');
    $this->createEntry($teams, 'Arsenl');
    $this->configure([$teams]);

    $this->artisan('tidytags:duplicates', ['--source' => 'teams'])->assertSuccessful()->expectsOutputToContain('1 cluster(s)');
    $this->artisan('craft:tidytags/duplicates', ['--fail-on-duplicates' => true])->assertFailed();
});

it('dry-runs a merge unless --apply is passed', function () {
    $teams = $this->createSection('teams');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenl');
    $this->configure([$teams], [$teams]);

    $this->artisan('tidytags:merge', ['ids' => [(string) $source->id], '--into' => (string) $target->id])
        ->assertSuccessful()->expectsOutputToContain('Dry run');
    expect(Entry::find()->id($source->id)->status(null)->one())->not->toBeNull();

    $this->artisan('tidytags:merge', ['ids' => [(string) $source->id], '--into' => (string) $target->id, '--apply' => true])
        ->assertSuccessful();
    expect($this->trashed($source->id))->not->toBeNull();
});

it('rejects merging into a read-only section with exit 2', function () {
    $teams = $this->createSection('teams');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenl');
    $this->configure([$teams]);

    $this->artisan('tidytags:merge', ['ids' => [(string) $source->id], '--into' => (string) $target->id, '--apply' => true])
        ->assertExitCode(2);
});

it('prunes unused entries from writable sections only', function () {
    $teams = $this->createSection('teams');
    $topics = $this->createSection('topics');
    $team = $this->createEntry($teams, 'Wrexham');
    $topic = $this->createEntry($topics, 'Cricket');
    $this->configure([$teams, $topics], [$teams]);

    $this->artisan('tidytags:unused', ['--delete' => true, '--apply' => true])->assertSuccessful();

    expect($this->trashed($team->id))->not->toBeNull()
        ->and(Entry::find()->id($topic->id)->status(null)->one())->not->toBeNull();

    $this->artisan('tidytags:unused', ['--source' => 'topics', '--delete' => true])->assertExitCode(2);
});
