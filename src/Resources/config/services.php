<?php declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Command
    $services->set(\Swkweb\ContentManagerNetzpMigration\Command\MigrateNetzpBlogCommand::class)
        ->args([
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\NetzpBlogMigrator::class),
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\PlanExecutor::class),
        ])
        ->tag('console.command')
    ;

    // Netzp
    $services->set(\Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader::class)
        ->args([
            service(\Doctrine\DBAL\Connection::class),
        ])
    ;

    // Migrator
    $services->set(\Swkweb\ContentManagerNetzpMigration\Migrator\NetzpBlogMigrator::class)
        ->args([
            service(\Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader::class),
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\ShopwareLookup::class),
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\Cms\LayoutMirror::class),
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\Cms\SlotConverter::class),
            param('shopware.html_sanitizer.enabled'),
        ])
    ;

    $services->set(\Swkweb\ContentManagerNetzpMigration\Migrator\PlanExecutor::class)
        ->args([
            service(\Doctrine\DBAL\Connection::class),
            service(\Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class),
        ])
    ;

    $services->set(\Swkweb\ContentManagerNetzpMigration\Migrator\ShopwareLookup::class)
        ->args([
            service(\Doctrine\DBAL\Connection::class),
            service(\Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry::class),
            service('media.repository'),
        ])
    ;

    // Migrator\Cms
    $services->set(\Swkweb\ContentManagerNetzpMigration\Migrator\Cms\SlotConverter::class);

    $services->set(\Swkweb\ContentManagerNetzpMigration\Migrator\Cms\LayoutMirror::class)
        ->args([
            service(\Doctrine\DBAL\Connection::class),
            service(\Swkweb\ContentManagerNetzpMigration\Migrator\Cms\SlotConverter::class),
        ])
    ;
};
