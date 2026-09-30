<?php

namespace justinholtweb\tidytagstests\unit;

use Craft;
use craft\test\TestCase;
use justinholtweb\tidytags\Plugin;

/**
 * Sanity check that the harness boots Craft with the plugin installed.
 */
class SmokeTest extends TestCase
{
    public function testCraftIsInstalled(): void
    {
        self::assertTrue(Craft::$app->getIsInstalled());
    }

    public function testPluginIsInstalledAndWired(): void
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('tidytags');
        self::assertInstanceOf(Plugin::class, $plugin);
        self::assertSame($plugin, Plugin::$plugin);
        self::assertNotNull($plugin->tags);
        self::assertNotNull($plugin->sources);
        self::assertNotNull($plugin->duplicateDetector);
    }
}
