<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpItem
{
    /**
     * @param array<string, array{title: ?string, content: ?string}> $translations keyed by language id
     */
    public function __construct(
        public string $id,
        public int $number,
        public ?string $imageId,
        public array $translations,
    ) {}
}
