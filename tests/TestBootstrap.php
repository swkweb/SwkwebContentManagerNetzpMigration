<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$isShopwareRoot = static fn (string $dir): bool => is_file($dir . '/vendor/autoload.php')
    && (is_dir($dir . '/vendor/shopware/core') || is_file($dir . '/src/Core/TestBootstrapper.php'));

$projectRoot = $_SERVER['PROJECT_ROOT'] ?? $_ENV['PROJECT_ROOT'] ?? null;

if (!is_string($projectRoot) || $projectRoot === '') {
    $dir = __DIR__;

    while (!$isShopwareRoot($dir)) {
        $parent = dirname($dir);

        if ($parent === $dir) {
            $dir = null;

            break;
        }

        $dir = $parent;
    }

    // CI (platform-plugin-dev image): the checkout is outside the Shopware install
    $projectRoot = $dir ?? '/opt/shopware';
}

if (!$isShopwareRoot($projectRoot)) {
    throw new RuntimeException(sprintf(
        'No Shopware installation found (walked up from "%s", checked "%s"). Set the PROJECT_ROOT environment variable to your Shopware root.',
        __DIR__,
        $projectRoot,
    ));
}

require $projectRoot . '/vendor/autoload.php';

$bootstrapper = (new TestBootstrapper())
    ->setProjectDir($projectRoot)
    ->addCallingPlugin()
    ->setForceInstallPlugins(true)
;

// The integration tests need the Content Manager and are skipped without it
if (is_dir($projectRoot . '/custom/plugins/SwkwebContentManager') || is_dir('/plugins/SwkwebContentManager')) {
    $bootstrapper->addActivePlugins('SwkwebContentManager');
}

$bootstrapper
    ->bootstrap()
    // Registers the plugin's autoload-dev namespace for non-test-class helpers
    ->getClassLoader()
;
