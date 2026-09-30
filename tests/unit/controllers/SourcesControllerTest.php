<?php

namespace justinholtweb\tidytagstests\unit\controllers;

use justinholtweb\tidytags\controllers\SourcesController;
use justinholtweb\tidytags\Plugin;
use justinholtweb\tidytagstests\support\Fixtures;
use justinholtweb\tidytagstests\support\PluginTestCase;
use yii\web\NotFoundHttpException;

/**
 * Covers the routing guards. The success paths render CP templates, which is
 * outside what a unit suite can meaningfully assert.
 */
class SourcesControllerTest extends PluginTestCase
{
    private function controller(): SourcesController
    {
        return new SourcesController('sources', Plugin::$plugin);
    }

    public function testGroupActionThrowsForUnknownTagGroup(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->actionGroup(999999);
    }

    /**
     * A section that hasn't been opted in as tag-like must 404 rather than
     * exposing arbitrary entry sections through the plugin.
     */
    public function testSectionActionThrowsForUnconfiguredSection(): void
    {
        $section = Fixtures::createChannelSection('news');

        $this->expectException(NotFoundHttpException::class);
        $this->controller()->actionSection($section->id);
    }

    public function testSectionActionThrowsForUnknownSection(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->controller()->actionSection(999999);
    }
}
