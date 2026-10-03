<?php

namespace justinholtweb\tidytagstests\unit;

use Craft;
use craft\web\View;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

/**
 * Guards the Duplicates screen's markup: paging, per-source headings, and
 * actions that follow permissions. Only the content block is rendered, as in
 * {@see GroupScreenTest}.
 */
class DuplicatesScreenTest extends PluginTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function render(string $scope, int $page, int $perPage, array $overrides = []): string
    {
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $result = $this->detector()->getDuplicatesPage($scope, null, 2, $page, $perPage);

        return $view->getTwig()->load('tidytags/duplicates')->renderBlock('content', array_merge([
            'clusters' => $result['clusters'],
            'pagination' => $result,
            'scope' => $scope,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => null,
            'threshold' => 2,
            'maxThreshold' => 6,
            'canManage' => true,
            'canDelete' => true,
        ], $overrides));
    }

    private function clusters(): void
    {
        $animals = Fixtures::createTagGroup('animals');
        foreach (['Cat', 'Cats', 'Dog', 'Dogs'] as $title) {
            Fixtures::createTag($animals, $title);
        }
        $plants = Fixtures::createTagGroup('plants');
        foreach (['Fern', 'Ferns'] as $title) {
            Fixtures::createTag($plants, $title);
        }
    }

    public function testPagesClustersAndHeadsEachSource(): void
    {
        $this->clusters();

        $html = $this->render('within', 2, 2);

        self::assertStringContainsString('Showing clusters 3–3 of 3', $html);
        self::assertStringContainsString('Plants', $html);
        self::assertStringNotContainsString('<span>Animals</span>', $html);
        self::assertMatchesRegularExpression('/scope=within&amp;threshold=2&amp;page=1"/', $html);
        self::assertStringContainsString('tidytags/tags/merge', $html);
    }

    /**
     * A source heading repeats only when the source changes between clusters.
     */
    public function testHeadingShowsOncePerSourceOnAPage(): void
    {
        $this->clusters();

        $html = $this->render('within', 1, 25);

        self::assertSame(1, substr_count($html, '<span>Animals</span>'));
        self::assertSame(1, substr_count($html, '<span>Plants</span>'));
    }

    public function testControlsAreHiddenWithoutPermission(): void
    {
        $this->clusters();

        $html = $this->render('within', 1, 25, ['canManage' => false, 'canDelete' => false]);

        self::assertStringContainsString('Cats', $html);
        self::assertStringNotContainsString('name="targetId"', $html);
        self::assertStringNotContainsString('Merge cluster', $html);
    }

    public function testEmptyStateNamesTheScope(): void
    {
        self::assertStringContainsString('No cross-source duplicates', $this->render('cross', 1, 25));
        self::assertStringContainsString('No within-source duplicate clusters', $this->render('within', 1, 25));
    }
}
