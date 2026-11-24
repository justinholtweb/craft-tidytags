<?php

declare(strict_types=1);

namespace JustinHoltWeb\TidyTags\Tests;

use CraftCms\Cms\Edition;
use CraftCms\Cms\Plugin\Testing\PluginTestCase;
use JustinHoltWeb\TidyTags\Tests\Support\CreatesContent;
use Override;

abstract class TestCase extends PluginTestCase
{
    use CreatesContent;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // Solo allows one user account, and the permission tests need editors beside the admin.
        Edition::set(Edition::Team);
    }
}
