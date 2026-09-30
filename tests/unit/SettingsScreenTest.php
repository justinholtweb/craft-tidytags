<?php

namespace justinholtweb\tidytagstests\unit;

use Craft;
use craft\web\View;
use justinholtweb\tidytags\services\TitleMatcher;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;
use ReflectionMethod;

/**
 * Guards the settings screen against Twig breakage. The template is only
 * rendered by Craft's plugin settings page, so nothing else in the suite would
 * notice a bad filter or a renamed accessor.
 */
class SettingsScreenTest extends PluginTestCase
{
    private function render(): string
    {
        Craft::$app->getView()->setTemplateMode(View::TEMPLATE_MODE_CP);

        $method = new ReflectionMethod($this->plugin(), 'settingsHtml');
        $method->setAccessible(true);

        return (string)$method->invoke($this->plugin());
    }

    public function testRendersUnderTheFuzzyStrategy(): void
    {
        $this->setPluginSettings(['matchStrategy' => TitleMatcher::STRATEGY_FUZZY]);

        $html = $this->render();

        self::assertStringContainsString('name="matchStrategy"', $html);
        // Token lists are strict-only, so the screen explains itself instead.
        self::assertStringNotContainsString('name="affixTokens"', $html);
        self::assertStringContainsString('only apply to the strict strategy', $html);
    }

    public function testRendersTokenListsUnderTheStrictStrategy(): void
    {
        $section = Fixtures::createChannelSection('teams');

        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'tagLikeSectionUids' => [$section->uid],
        ]);

        $html = $this->render();

        self::assertStringContainsString('name="affixTokens"', $html);
        self::assertStringContainsString('name="qualifierTokens"', $html);
    }

    /**
     * The textareas read through the typed accessors, so a token list stored as
     * a raw string still populates the screen rather than throwing.
     */
    public function testTokenListsRenderWhenStoredAsAString(): void
    {
        $this->setPluginSettings([
            'matchStrategy' => TitleMatcher::STRATEGY_STRICT,
            'affixTokens' => "FC, AFC",
            'qualifierTokens' => "Women",
        ]);

        $html = $this->render();

        self::assertStringContainsString("fc\nafc", $html);
        self::assertStringContainsString('women', $html);
    }
}
