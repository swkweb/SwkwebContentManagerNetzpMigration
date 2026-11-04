<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator\Cms;

final readonly class MirroredLayout
{
    /**
     * @param array<string, list<array<string, mixed>>> $rows database rows keyed by table, in insert order
     * @param list<string> $productSliderSlotIds
     * @param list<string> $gallerySlotIds
     */
    public function __construct(
        public string $pageId,
        public string $sourcePageId,
        public string $name,
        public bool $withGallery,
        public array $rows,
        public array $productSliderSlotIds,
        public array $gallerySlotIds,
    ) {}
}
