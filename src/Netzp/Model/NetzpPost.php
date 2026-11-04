<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpPost
{
    /**
     * @param array<string, NetzpPostTranslation> $translations keyed by language id
     * @param list<string> $categoryIds additional categories, without the primary one
     * @param list<string> $productIds
     * @param list<string> $tagNames
     * @param list<NetzpItem> $items ordered by number
     * @param list<string> $galleryMediaIds ordered by number
     */
    public function __construct(
        public string $id,
        public ?\DateTimeImmutable $postDate,
        public ?\DateTimeImmutable $showFrom,
        public ?\DateTimeImmutable $showUntil,
        public ?string $imageId,
        public ?string $previewImageId,
        public ?string $primaryCategoryId,
        public ?string $authorId,
        public bool $noindex,
        public bool $sticky,
        public bool $isProductStream,
        public ?string $productStreamId,
        public ?string $canonicalUrl,
        public ?\DateTimeImmutable $createdAt,
        public array $translations,
        public array $categoryIds,
        public array $productIds,
        public array $tagNames,
        public array $items,
        public array $galleryMediaIds,
    ) {}

    /**
     * @return list<string>
     */
    public function getAllCategoryIds(): array
    {
        $ids = $this->categoryIds;

        if ($this->primaryCategoryId !== null) {
            array_unshift($ids, $this->primaryCategoryId);
        }

        return array_values(array_unique($ids));
    }
}
