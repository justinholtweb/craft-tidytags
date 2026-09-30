<?php

namespace justinholtweb\tidytagstests\unit;

use Craft;
use craft\events\RegisterUrlRulesEvent;
use craft\web\UrlManager;
use justinholtweb\tidytags\models\Settings;
use justinholtweb\tidytags\Plugin;
use justinholtweb\tidytagstests\support\PluginTestCase;
use yii\base\Event;

class PluginTest extends PluginTestCase
{
    public function testComponentsAreRegistered(): void
    {
        self::assertInstanceOf(\justinholtweb\tidytags\services\Tags::class, $this->plugin()->tags);
        self::assertInstanceOf(\justinholtweb\tidytags\services\Sources::class, $this->plugin()->sources);
        self::assertInstanceOf(
            \justinholtweb\tidytags\services\DuplicateDetector::class,
            $this->plugin()->duplicateDetector,
        );
    }

    public function testSettingsModel(): void
    {
        self::assertInstanceOf(Settings::class, $this->plugin()->getSettings());
    }

    public function testCpNavItem(): void
    {
        $item = $this->plugin()->getCpNavItem();

        self::assertSame('Tidy Tags', $item['label']);
        self::assertSame('tidytags', $item['url']);
        self::assertArrayHasKey('dashboard', $item['subnav']);
        self::assertArrayHasKey('duplicates', $item['subnav']);
        self::assertSame('tidytags', $item['subnav']['dashboard']['url']);
        self::assertSame('tidytags/duplicates', $item['subnav']['duplicates']['url']);
    }

    public function testCpUrlRulesAreRegistered(): void
    {
        $event = new RegisterUrlRulesEvent(['rules' => []]);
        Event::trigger(UrlManager::class, UrlManager::EVENT_REGISTER_CP_URL_RULES, $event);

        self::assertSame('tidytags/dashboard/index', $event->rules['tidytags']);
        self::assertSame('tidytags/dashboard/duplicates', $event->rules['tidytags/duplicates']);
        self::assertSame(
            'tidytags/sources/group',
            $event->rules['tidytags/group/<groupId:\d+>'],
        );
        self::assertSame(
            'tidytags/sources/group',
            $event->rules['tidytags/group/<groupId:\d+>/site/<siteId:\d+>'],
        );
        self::assertSame(
            'tidytags/sources/section',
            $event->rules['tidytags/section/<sectionId:\d+>'],
        );
        self::assertSame(
            'tidytags/sources/section',
            $event->rules['tidytags/section/<sectionId:\d+>/site/<siteId:\d+>'],
        );
    }

    public function testHasCpSectionAndSettings(): void
    {
        self::assertTrue($this->plugin()->hasCpSection);
        self::assertTrue($this->plugin()->hasCpSettings);
    }

    /**
     * `config/tidytags.php` should win over the saved settings so operators can
     * drive tag-like sections from env-aware config.
     */
    public function testFileConfigOverlaysSavedSettings(): void
    {
        $configPath = Craft::$app->getPath()->getConfigPath() . '/tidytags.php';
        file_put_contents(
            $configPath,
            "<?php\n\nreturn ['tagLikeSectionUids' => ['from-file-uid']];\n",
        );

        try {
            $this->setPluginSettings(['tagLikeSectionUids' => ['from-db-uid']]);

            /** @var Settings $settings */
            $settings = $this->plugin()->getSettings();
            self::assertSame(['from-file-uid'], $settings->tagLikeSectionUids);
        } finally {
            @unlink($configPath);
        }
    }

    public function testSettingsAreCachedWithinARequest(): void
    {
        $first = $this->plugin()->getSettings();
        self::assertSame($first, $this->plugin()->getSettings());
    }

    public function testPluginStaticIsSet(): void
    {
        self::assertSame(Craft::$app->getPlugins()->getPlugin('tidytags'), Plugin::$plugin);
    }
}
