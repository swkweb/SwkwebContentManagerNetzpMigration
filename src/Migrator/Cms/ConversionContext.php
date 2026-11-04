<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator\Cms;

final readonly class ConversionContext
{
    /**
     * @param array<string, string> $categoryMap Shopware category ids keyed by NetzpBlog6 category id
     */
    public function __construct(
        public array $categoryMap,
        public ?string $stickySortingKey,
        public bool $hasCategories,
        public bool $hasTags,
    ) {}
}
