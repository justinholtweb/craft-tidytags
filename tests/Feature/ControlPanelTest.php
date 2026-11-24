<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use JustinHoltWeb\TidyTags\Http\Controllers\ItemsController;
use JustinHoltWeb\TidyTags\Plugin;

use function CraftCms\Cms\cp_url;

it('shows the dashboard and sources to Tidy Tags users only', function () {
    $teams = $this->createSection('teams');
    $this->createEntry($teams, 'Arsenal');
    $this->configure([$teams]);

    $this->actingAs($this->admin())->get(cp_url('tidytags'))->assertOk()->assertSee('Teams');
    $this->actingAs($this->admin())->get(cp_url("tidytags/section/{$teams->id}"))->assertOk()->assertSee('Arsenal');
    $this->actingAs($this->admin())->get(cp_url('tidytags/duplicates'))->assertOk();

    $this->actingAs($this->userWith([]))->get(cp_url('tidytags'))->assertForbidden();
});

it('404s on a section that isn’t tag-like', function () {
    $news = $this->createSection('news');

    $this->actingAs($this->admin())->get(cp_url("tidytags/section/{$news->id}"))->assertNotFound();
});

it('merges from the CP and offers the URL report', function () {
    $teams = $this->createSection('teams', 'teams/{slug}');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($teams, 'Arsenal FC');
    $this->configure([$teams], [$teams]);

    $response = $this->actingAs($this->admin())
        ->postJson(cp_url('tidytags/tags/merge'), ['sourceIds' => [$source->id], 'targetId' => $target->id])
        ->assertOk();

    expect($response->json('urls'))->toHaveCount(1)
        ->and($this->trashed($source->id))->not->toBeNull();

    $report = $response->json('urlReport');
    expect($report)->toContain('report=');

    $this->actingAs($this->admin())->get($report)->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee('teams/arsenal-fc');
});

it('refuses a cross-section merge with the reason', function () {
    $teams = $this->createSection('teams');
    $clubs = $this->createSection('clubs');
    $target = $this->createEntry($teams, 'Arsenal');
    $source = $this->createEntry($clubs, 'Arsenal');
    $this->configure([$teams, $clubs], [$teams, $clubs]);

    $this->actingAs($this->admin())
        ->postJson(cp_url('tidytags/tags/merge'), ['sourceIds' => [$source->id], 'targetId' => $target->id])
        ->assertStatus(400)
        ->assertJsonFragment(['message' => "#{$source->id} “Arsenal” is in a different section from #{$target->id} “Arsenal”."]);
});

it('needs the delete permission to merge or delete', function () {
    $teams = $this->createSection('teams');
    $entry = $this->createEntry($teams, 'Arsenal');
    $this->configure([$teams], [$teams]);

    $manager = $this->userWith([Plugin::PERMISSION_ACCESS, Plugin::PERMISSION_MANAGE_TAGS]);

    $this->actingAs($manager)->postJson(cp_url('tidytags/tags/delete'), ['tagIds' => [$entry->id]])->assertForbidden();
    expect(Entry::find()->id($entry->id)->status(null)->one())->not->toBeNull();
});

it('answers the editor’s duplicate check only for tag-like entries', function () {
    $teams = $this->createSection('teams');
    $news = $this->createSection('news');
    $arsenal = $this->createEntry($teams, 'Arsenal');
    $editing = $this->createEntry($teams, 'Arsenl');
    $article = $this->createEntry($news, 'Match report');
    $this->configure([$teams]);

    $json = $this->actingAs($this->admin())
        ->getJson(cp_url('tidytags/tags/check-duplicate', ['title' => 'Arsenl', 'elementId' => $editing->id]))
        ->assertOk()->json();

    expect($json['applies'])->toBeTrue()
        ->and(array_column($json['matches'], 'id'))->toContain($arsenal->id)
        ->and(array_column($json['matches'], 'id'))->not->toContain($editing->id);

    $this->actingAs($this->admin())
        ->getJson(cp_url('tidytags/tags/check-duplicate', ['title' => 'Match reprot', 'elementId' => $article->id]))
        ->assertOk()->assertJson(['applies' => false, 'matches' => []]);
});

it('refuses a swap involving an ID that isn’t a live source element', function () {
    $teams = $this->createSection('teams');
    $target = $this->createEntry($teams, 'Arsenal');
    $gone = $this->createEntry($teams, 'Arsenl');
    $this->configure([$teams]);
    \CraftCms\Cms\Support\Facades\Elements::deleteElement($gone);

    $this->actingAs($this->admin())
        ->postJson(cp_url('tidytags/tags/swap'), ['sourceIds' => [$gone->id, 999999], 'targetId' => $target->id])
        ->assertForbidden();
});

it('keeps the flash key the screens read', function () {
    expect(ItemsController::URL_REPORT_FLASH)->toBe('tidytagsUrlReport');
});
