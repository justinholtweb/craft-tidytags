<?php

namespace justinholtweb\tidytags;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\events\TemplateEvent;
use craft\services\UserPermissions;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\tidytags\assetbundles\TidyTagsAsset;
use justinholtweb\tidytags\models\Settings;
use justinholtweb\tidytags\services\DuplicateDetector;
use justinholtweb\tidytags\services\Sources;
use justinholtweb\tidytags\services\Tags;
use justinholtweb\tidytags\services\TitleMatcher;
use Throwable;
use yii\base\Event;

/**
 * Tidy Tags plugin.
 *
 * @property-read Tags $tags
 * @property-read Sources $sources
 * @property-read DuplicateDetector $duplicateDetector
 * @property-read TitleMatcher $titleMatcher
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    /**
     * Rename tags, merge tags, and swap relations between source elements.
     * Merge additionally requires {@see PERMISSION_DELETE_TAGS}.
     */
    public const PERMISSION_MANAGE_TAGS = 'tidytags-manageTags';

    /**
     * Delete tags, either directly or as the second half of a merge.
     */
    public const PERMISSION_DELETE_TAGS = 'tidytags-deleteTags';

    public static ?Plugin $plugin = null;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    private ?Settings $_overlaidSettings = null;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'tags' => Tags::class,
            'sources' => Sources::class,
            'duplicateDetector' => DuplicateDetector::class,
            'titleMatcher' => TitleMatcher::class,
        ]);

        $this->_registerCpUrlRules();
        $this->_registerPermissions();
        $this->_registerCpAssetBundle();
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = 'Tidy Tags';
        $item['url'] = 'tidytags';
        $item['subnav'] = [
            'dashboard' => ['label' => 'Dashboard', 'url' => 'tidytags'],
            'duplicates' => ['label' => 'Duplicates', 'url' => 'tidytags/duplicates'],
        ];
        return $item;
    }

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Overlays values from `config/tidytags.php` (if present) on top of the
     * saved settings model so operators can drive `tagLikeSectionUids` from
     * env-aware config alongside the CP settings screen.
     *
     * @return Settings|null
     */
    public function getSettings(): ?Model
    {
        if ($this->_overlaidSettings !== null) {
            return $this->_overlaidSettings;
        }

        $settings = parent::getSettings();
        if ($settings === null) {
            return null;
        }

        $fileConfig = Craft::$app->getConfig()->getConfigFromFile('tidytags');
        foreach ($fileConfig as $name => $value) {
            if ($settings->canSetProperty($name)) {
                $settings->$name = $value;
            }
        }

        /** @var Settings $settings */
        $this->_overlaidSettings = $settings;
        return $settings;
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $sources = $this->sources->getAllSources();
        $fieldsBySourceUid = [];
        foreach ($sources as $source) {
            $fieldsBySourceUid[$source->uid] = $this->sources->getAvailableFields($source);
        }

        return Craft::$app->getView()->renderTemplate('tidytags/_settings', [
            'settings' => $this->getSettings(),
            'sections' => Craft::$app->getEntries()->getAllSections(),
            'sources' => $sources,
            'fieldsBySourceUid' => $fieldsBySourceUid,
        ]);
    }

    /**
     * Registers CP URL rules for the dashboard, duplicates, and per-source views.
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['tidytags'] = 'tidytags/dashboard/index';
                $event->rules['tidytags/duplicates'] = 'tidytags/dashboard/duplicates';
                $event->rules['tidytags/group/<groupId:\d+>'] = 'tidytags/sources/group';
                $event->rules['tidytags/group/<groupId:\d+>/site/<siteId:\d+>'] = 'tidytags/sources/group';
                $event->rules['tidytags/section/<sectionId:\d+>'] = 'tidytags/sources/section';
                $event->rules['tidytags/section/<sectionId:\d+>/site/<siteId:\d+>'] = 'tidytags/sources/section';
            }
        );
    }

    /**
     * Registers the permissions that gate every mutation. Plugin access alone
     * only allows browsing sources, scanning for duplicates and viewing usages.
     */
    private function _registerPermissions(): void
    {
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Tidy Tags',
                    'permissions' => [
                        self::PERMISSION_MANAGE_TAGS => [
                            'label' => 'Rename and merge tags, and swap relations',
                            'nested' => [
                                self::PERMISSION_DELETE_TAGS => [
                                    'label' => 'Delete tags',
                                ],
                            ],
                        ],
                    ],
                ];
            }
        );
    }

    /**
     * Registers the CP asset bundle so the "did you mean" duplicate warning
     * JavaScript runs on every control panel page. The JS only hooks native
     * Tags fields, so entry-backed sources are intentionally not covered.
     */
    private function _registerCpAssetBundle(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                if (Craft::$app->getRequest()->getIsCpRequest()) {
                    try {
                        Craft::$app->getView()->registerAssetBundle(TidyTagsAsset::class);
                    } catch (Throwable $e) {
                        Craft::error('Failed to register TidyTagsAsset: ' . $e->getMessage(), __METHOD__);
                    }
                }
            }
        );
    }
}
