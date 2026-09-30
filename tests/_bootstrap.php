<?php
/**
 * Codeception bootstrap for the Tidy Tags plugin test suite.
 *
 * Craft's test framework expects a handful of CRAFT_* constants pointing at a
 * minimal "project" (tests/_craft) that stands in for a real Craft install.
 */

use craft\test\TestSetup;

ini_set('date.timezone', 'UTC');

// Load the test environment's DB credentials.
$dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__, '.env');
$dotenv->safeLoad();

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_STORAGE_PATH', __DIR__ . '/_craft/storage');
define('CRAFT_TEMPLATES_PATH', __DIR__ . '/_craft/templates');
define('CRAFT_CONFIG_PATH', __DIR__ . '/_craft/config');
define('CRAFT_MIGRATIONS_PATH', __DIR__ . '/_craft/migrations');
define('CRAFT_TRANSLATIONS_PATH', __DIR__ . '/_craft/translations');
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_ROOT_PATH', dirname(__DIR__));

TestSetup::configureCraft();
