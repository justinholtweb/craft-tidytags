<?php

namespace justinholtweb\tidytags\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Control panel asset bundle for the Tidy Tags plugin. Loads the
 * duplicate-warning JavaScript and supporting CSS on every CP page.
 */
class TidyTagsAsset extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = '@justinholtweb/tidytags/web/assets/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/tidytags.js',
        ];

        $this->css = [
            'css/tidytags.css',
        ];

        parent::init();
    }
}
