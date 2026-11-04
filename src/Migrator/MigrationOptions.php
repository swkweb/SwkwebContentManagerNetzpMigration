<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

final readonly class MigrationOptions
{
    /**
     * @param list<string> $salesChannelIds empty for all storefront sales channels
     */
    public function __construct(
        public ?string $categoryId = null,
        public array $salesChannelIds = [],
        public bool $overwrite = false,
    ) {}
}
