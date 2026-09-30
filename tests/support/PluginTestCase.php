<?php

namespace justinholtweb\tidytagstests\support;

use craft\test\TestCase;
use justinholtweb\tidytags\models\Settings;
use justinholtweb\tidytags\Plugin;
use justinholtweb\tidytags\services\DuplicateDetector;
use justinholtweb\tidytags\services\Sources;
use justinholtweb\tidytags\services\Tags;
use justinholtweb\tidytags\services\TitleMatcher;
use ReflectionProperty;

/**
 * Base class for tests that talk to the plugin's services against a real
 * (per-test, transactional) Craft install.
 */
abstract class PluginTestCase extends TestCase
{
    protected function plugin(): Plugin
    {
        return Plugin::$plugin;
    }

    protected function tags(): Tags
    {
        return $this->plugin()->tags;
    }

    protected function sources(): Sources
    {
        return $this->plugin()->sources;
    }

    protected function detector(): DuplicateDetector
    {
        return $this->plugin()->duplicateDetector;
    }

    protected function matcher(): TitleMatcher
    {
        return $this->plugin()->titleMatcher;
    }

    /**
     * Applies plugin settings for the current test, bypassing the per-request
     * settings cache that {@see Plugin::getSettings()} maintains.
     *
     * @param array<string, mixed> $values
     */
    protected function setPluginSettings(array $values): Settings
    {
        $plugin = $this->plugin();

        $cache = new ReflectionProperty(Plugin::class, '_overlaidSettings');
        $cache->setAccessible(true);
        $cache->setValue($plugin, null);

        $plugin->setSettings($values);

        $cache->setValue($plugin, null);

        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        return $settings;
    }

    /**
     * Extracts titles from a list of detector items or source rows.
     *
     * @param array<int, array<string, mixed>> $items
     * @return string[]
     */
    protected function titles(array $items): array
    {
        return array_map(fn(array $item) => (string)$item['title'], $items);
    }
}
