<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpAuthor
{
    /**
     * @param array<string, array{name: ?string, bio: ?string, link: ?string, customFields: array<string, mixed>}> $translations keyed by language id
     */
    public function __construct(
        public string $id,
        public ?string $imageId,
        public ?string $link,
        public array $translations,
    ) {}
}
