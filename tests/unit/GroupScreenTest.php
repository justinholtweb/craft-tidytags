<?php

namespace justinholtweb\tidytagstests\unit;

use Craft;
use craft\web\View;
use justinholtweb\tidytags\models\Source;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;

/**
 * Guards the Manage screen's pager and permission-aware actions against Twig
 * breakage. Only the content block is rendered: the CP layout around it needs
 * a full web request, and nothing in it depends on this plugin.
 */
class GroupScreenTest extends PluginTestCase
{
    /**
     * @param array<string, mixed> $overrides
     */
    private function render(Source $source, array $overrides = []): string
    {
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $rows = $this->sources()->getElementsInSource($source, null, null, 2, 0);

        $variables = array_merge([
            'source' => $source,
            'sites' => Craft::$app->getSites()->getAllSites(),
            'selectedSiteId' => null,
            'rows' => $rows,
            'search' => null,
            'pagination' => ['page' => 1, 'totalPages' => 1, 'total' => count($rows), 'first' => 1, 'last' => count($rows)],
            'canManage' => true,
            'canDelete' => true,
        ], $overrides);

        return $view->getTwig()->load('tidytags/group')->renderBlock('content', $variables);
    }

    private function tagSource(int $tags): Source
    {
        $group = Fixtures::createTagGroup('animals');
        for ($i = 1; $i <= $tags; $i++) {
            Fixtures::createTag($group, sprintf('Tag %02d', $i));
        }
        return $this->sources()->getTagSource($group->id);
    }

    public function testSinglePageShowsNoPager(): void
    {
        $html = $this->render($this->tagSource(2));

        self::assertStringContainsString('Showing 1–2 of 2', $html);
        self::assertStringNotContainsString('tidytags-pagination', $html);
    }

    public function testPagerKeepsFiltersAndWindowsPageLinks(): void
    {
        $source = $this->tagSource(2);

        $html = $this->render($source, [
            'search' => 'tag',
            'pagination' => ['page' => 6, 'totalPages' => 170, 'total' => 17000, 'first' => 501, 'last' => 600],
        ]);

        self::assertStringContainsString('Showing 501–600 of 17,000', $html);
        self::assertStringContainsString('aria-current="page">6<', $html);
        // A window around the current page, plus the first and last pages.
        self::assertMatchesRegularExpression('/search=tag&amp;page=1"/', $html);
        self::assertMatchesRegularExpression('/search=tag&amp;page=4"/', $html);
        self::assertMatchesRegularExpression('/search=tag&amp;page=8"/', $html);
        self::assertMatchesRegularExpression('/search=tag&amp;page=170"/', $html);
        self::assertDoesNotMatchRegularExpression('/page=3"/', $html);
        self::assertDoesNotMatchRegularExpression('/page=9"/', $html);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: string[], 3: string[]}>
     */
    public static function permissionProvider(): array
    {
        return [
            'manage and delete' => [true, true, ['rename', 'merge', 'delete'], []],
            'manage only' => [true, false, ['rename'], ['merge', 'delete']],
            'neither' => [false, false, [], ['rename', 'merge', 'delete']],
        ];
    }

    /**
     * @dataProvider permissionProvider
     * @param string[] $shown
     * @param string[] $hidden
     */
    public function testActionsFollowPermissions(bool $canManage, bool $canDelete, array $shown, array $hidden): void
    {
        $html = $this->render($this->tagSource(2), ['canManage' => $canManage, 'canDelete' => $canDelete]);

        foreach ($shown as $action) {
            self::assertStringContainsString("data-action=\"$action\"", $html);
        }
        foreach ($hidden as $action) {
            self::assertStringNotContainsString("data-action=\"$action\"", $html);
        }
        self::assertSame($shown !== [], str_contains($html, 'tidytags-row-check'));
    }
}
