<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpCategory
{
    /**
     * @param array<string, array{title: ?string, teaser: ?string, customFields: array<string, mixed>}> $translations keyed by language id
     */
    public function __construct(
        public string $id,
        public ?string $salesChannelId,
        public ?string $customerGroupId,
        public bool $onlyLoggedIn,
        public bool $includeInRss,
        public ?string $cmsPageId,
        public ?string $navigationCategoryId,
        public array $translations,
    ) {}

    public function isRestrictedToCustomers(): bool
    {
        return $this->onlyLoggedIn || $this->customerGroupId !== null;
    }
}
