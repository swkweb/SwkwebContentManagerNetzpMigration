<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator\Cms;

final readonly class ConvertedSlot
{
    /**
     * @param string|null $blockType the new type of the surrounding block, null to keep it
     * @param array<string, array<string, mixed>> $configs keyed by language id
     */
    public function __construct(
        public string $type,
        public string $slot,
        public ?string $blockType,
        public array $configs,
    ) {}
}
