<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpCmsSlot
{
    /**
     * @param array<string, array<string, mixed>> $configs slot config keyed by language id
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $slot,
        public string $blockId,
        public string $blockType,
        public string $pageId,
        public array $configs,
    ) {}
}
