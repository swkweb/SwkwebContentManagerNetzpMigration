<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpPostTranslation
{
    /**
     * @param array<string, mixed> $customFields
     */
    public function __construct(
        public ?string $title,
        public ?string $teaser,
        public ?string $slug,
        public ?string $contents,
        public ?string $custom,
        public ?string $metaTitle,
        public ?string $metaDescription,
        public array $customFields,
    ) {}
}
