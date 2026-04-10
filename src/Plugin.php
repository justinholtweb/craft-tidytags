<?php

namespace justinholtweb\tidytags;

use Craft;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\TemplateEvent;
use craft\web\UrlManager;
use craft\web\View;
use justinholtweb\tidytags\assetbundles\TidyTagsAsset;
use justinholtweb\tidytags\services\DuplicateDetector;
use justinholtweb\tidytags\services\Tags;
use Throwable;
use yii\base\Event;

/**
 * Tidy Tags plugin.
 *
 * @property-read Tags $tags
 * @property-read DuplicateDetector $duplicateDetector
 */
class Plugin extends BasePlugin
{
    public static ?Plugin $plugin = null;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = false;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'tags' => Tags::class,
            'duplicateDetector' => DuplicateDetector::class,
        ]);

        $this->_registerCpUrlRules();
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
     * Registers CP URL rules for the dashboard, duplicates, and per-group views.
     */
    private function _registerCpUrlRules(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['tidytags'] = 'tidytags/dashboard/index';
                $event->rules['tidytags/duplicates'] = 'tidytags/dashboard/duplicates';
                $event->rules['tidytags/group/<groupId:\d+>'] = 'tidytags/tags/group';
                $event->rules['tidytags/group/<groupId:\d+>/site/<siteId:\d+>'] = 'tidytags/tags/group';
            }
        );
    }

    /**
     * Registers the CP asset bundle so the "did you mean" duplicate warning
     * JavaScript runs on every control panel page.
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
