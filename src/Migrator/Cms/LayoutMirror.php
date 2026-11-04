<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator\Cms;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Migrator\ContentManager;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCmsSlot;
use Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader;

/**
 * Copies a NetzpBlog6 blog post layout into a Content Manager article layout.
 *
 * The copy is made row by row, so blocks and settings of themes and other plugins are kept. Ids are derived from
 * the source ids, so a repeated migration finds the copy again and per-article slot overrides stay valid.
 */
class LayoutMirror
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SlotConverter $converter,
    ) {}

    public static function getPageId(string $sourcePageId, bool $withGallery): string
    {
        return self::deriveId($sourcePageId, $withGallery);
    }

    /**
     * @param array<string, NetzpCmsSlot> $netzpSlots the NetzpBlog6 slots of the page, keyed by id
     */
    public function mirror(string $sourcePageId, string $sourceName, bool $withGallery, array $netzpSlots, ConversionContext $context, MigrationReport $report): MirroredLayout
    {
        $pageId = self::getPageId($sourcePageId, $withGallery);
        $name = sprintf('%s (Content Manager%s)', $sourceName, $withGallery ? ', gallery' : '');

        // A copy from an earlier migration may have been edited since, so it is kept and its actual slots are used.
        if ($this->fetch('cms_page', 'id', [$pageId]) !== []) {
            $report->notice('Article layouts copied by an earlier migration are reused as they are', $name);

            return new MirroredLayout($pageId, $sourcePageId, $name, $withGallery, [], ...$this->getOverridableSlotIds($netzpSlots, $withGallery));
        }

        $page = $this->fetch('cms_page', 'id', [$sourcePageId]);
        $pageTranslations = $this->fetch('cms_page_translation', 'cms_page_id', [$sourcePageId], 'cms_page_version_id');
        $sections = $this->fetch('cms_section', 'cms_page_id', [$sourcePageId]);
        $blocks = $this->fetch('cms_block', 'cms_section_id', self::ids($sections));
        $slots = $this->fetch('cms_slot', 'cms_block_id', self::ids($blocks));
        $slotTranslations = $this->fetch('cms_slot_translation', 'cms_slot_id', self::ids($slots), 'cms_slot_version_id');

        $slotCountByBlock = array_count_values(array_map(static fn (array $slot): string => self::hex($slot['cms_block_id']), $slots));

        $convertedSlots = [];
        $blockTypes = [];
        $droppedBlocks = [];
        $productSliderSlotIds = [];
        $gallerySlotIds = [];

        foreach ($slots as $slot) {
            $netzpSlot = $netzpSlots[self::hex($slot['id'])] ?? null;
            if ($netzpSlot === null) {
                continue;
            }

            $converted = $netzpSlot->type === NetzpBlogReader::DETAIL_ELEMENT
                ? $this->converter->convertDetail($netzpSlot, $withGallery, $context, $sourceName, $report)
                : $this->converter->convertListing($netzpSlot, $context, $sourceName, $report);

            if ($converted === null) {
                if (SlotConverter::isNetzpBlock($netzpSlot->blockType) && ($slotCountByBlock[$netzpSlot->blockId] ?? 0) === 1) {
                    $droppedBlocks[$netzpSlot->blockId] = true;

                    continue;
                }

                $converted = new ConvertedSlot('text', $netzpSlot->slot, null, [Defaults::LANGUAGE_SYSTEM => SlotConverter::emptyTextConfig()]);
            }

            $convertedSlots[$netzpSlot->id] = $converted;
            if ($converted->blockType !== null) {
                $blockTypes[$netzpSlot->blockId] = $converted->blockType;
            }

            $mirroredSlotId = self::deriveId($netzpSlot->id, $withGallery);
            if ($converted->type === 'product-slider') {
                $productSliderSlotIds[] = $mirroredSlotId;
            }

            if ($converted->type === 'image-gallery') {
                $gallerySlotIds[] = $mirroredSlotId;
            }
        }

        $rows = [
            'cms_page' => array_map(fn (array $row): array => $this->copy($row, $withGallery, [
                'type' => ContentManager::ARTICLE_DETAIL_PAGE_TYPE,
            ]), $page),
            'cms_page_translation' => array_map(fn (array $row): array => $this->copy($row, $withGallery, [
                'cms_page_id' => Uuid::fromHexToBytes($pageId),
                'name' => sprintf('%s (Content Manager%s)', is_string($row['name'] ?? null) ? $row['name'] : $sourceName, $withGallery ? ', gallery' : ''),
            ]), $pageTranslations),
            'cms_section' => array_map(fn (array $row): array => $this->copy($row, $withGallery, [
                'cms_page_id' => Uuid::fromHexToBytes($pageId),
            ]), $sections),
            'cms_block' => [],
            'cms_slot' => [],
            'cms_slot_translation' => [],
        ];

        foreach ($blocks as $block) {
            $blockId = self::hex($block['id']);
            if (isset($droppedBlocks[$blockId])) {
                continue;
            }

            $rows['cms_block'][] = $this->copy($block, $withGallery, [
                'cms_section_id' => self::deriveBinaryId(self::hex($block['cms_section_id']), $withGallery),
                ...(isset($blockTypes[$blockId]) ? ['type' => $blockTypes[$blockId]] : []),
            ]);
        }

        $copiedSlotIds = [];
        foreach ($slots as $slot) {
            if (isset($droppedBlocks[self::hex($slot['cms_block_id'])])) {
                continue;
            }

            $slotId = self::hex($slot['id']);
            $converted = $convertedSlots[$slotId] ?? null;
            $copiedSlotIds[$slotId] = true;

            $rows['cms_slot'][] = $this->copy($slot, $withGallery, [
                'cms_block_id' => self::deriveBinaryId(self::hex($slot['cms_block_id']), $withGallery),
                ...($converted !== null ? ['type' => $converted->type, 'slot' => $converted->slot] : []),
            ]);
        }

        foreach ($slotTranslations as $translation) {
            $slotId = self::hex($translation['cms_slot_id']);
            if (!isset($copiedSlotIds[$slotId])) {
                continue;
            }

            $converted = $convertedSlots[$slotId] ?? null;
            $config = null;
            if ($converted !== null) {
                // Languages without a converted config inherit the one of the default language.
                $languageConfig = $converted->configs[self::hex($translation['language_id'])] ?? null;
                $config = $languageConfig !== null ? json_encode($languageConfig, \JSON_THROW_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION) : null;
            }

            $rows['cms_slot_translation'][] = $this->copy($translation, $withGallery, [
                'cms_slot_id' => self::deriveBinaryId($slotId, $withGallery),
                ...($converted !== null ? ['config' => $config] : []),
            ]);
        }

        return new MirroredLayout($pageId, $sourcePageId, $name, $withGallery, $rows, $productSliderSlotIds, $gallerySlotIds);
    }

    /**
     * Finds the copies of the NetzpBlog6 product and gallery elements by their derived ids, so other product sliders
     * and image galleries of the layout are not overridden.
     *
     * @param array<string, NetzpCmsSlot> $netzpSlots
     *
     * @return array{list<string>, list<string>} ids of the product sliders and of the image galleries
     */
    private function getOverridableSlotIds(array $netzpSlots, bool $withGallery): array
    {
        $expectedTypes = [];
        foreach ($netzpSlots as $netzpSlot) {
            if ($netzpSlot->type !== NetzpBlogReader::DETAIL_ELEMENT) {
                continue;
            }

            $type = match (SlotConverter::getDetailPart($netzpSlot)) {
                SlotConverter::PART_PRODUCTS => 'product-slider',
                SlotConverter::PART_GALLERY => $withGallery ? 'image-gallery' : null,
                default => null,
            };

            if ($type !== null) {
                $expectedTypes[self::deriveId($netzpSlot->id, $withGallery)] = $type;
            }
        }

        $productSliderSlotIds = [];
        $gallerySlotIds = [];
        foreach ($this->fetch('cms_slot', 'id', array_keys($expectedTypes)) as $slot) {
            $slotId = self::hex($slot['id']);
            if ($slot['type'] !== $expectedTypes[$slotId]) {
                continue;
            }

            if ($slot['type'] === 'product-slider') {
                $productSliderSlotIds[] = $slotId;
            } else {
                $gallerySlotIds[] = $slotId;
            }
        }

        return [$productSliderSlotIds, $gallerySlotIds];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function copy(array $row, bool $withGallery, array $overrides): array
    {
        if (isset($row['id'])) {
            $row['id'] = self::deriveBinaryId(self::hex($row['id']), $withGallery);
        }

        if (array_key_exists('locked', $row)) {
            $row['locked'] = 0;
        }

        $row['created_at'] = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        $row['updated_at'] = null;

        return [...$row, ...$overrides];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function fetch(string $table, string $idColumn, array $ids, string $versionColumn = 'version_id'): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->connection->fetchAllAssociative(
            sprintf('SELECT * FROM `%s` WHERE `%s` IN (:ids) AND `%s` = :versionId', $table, $idColumn, $versionColumn),
            ['ids' => Uuid::fromHexToBytesList($ids), 'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['ids' => ArrayParameterType::BINARY],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private static function ids(array $rows): array
    {
        return array_map(static fn (array $row): string => self::hex($row['id']), $rows);
    }

    private static function hex(mixed $binaryId): string
    {
        if (!is_string($binaryId)) {
            throw new \UnexpectedValueException('Expected a binary id');
        }

        return Uuid::fromBytesToHex($binaryId);
    }

    private static function deriveId(string $sourceId, bool $withGallery): string
    {
        return Uuid::fromStringToHex(sprintf('swkweb-netzp-migration:layout:%s:%s', $withGallery ? 'gallery' : 'default', $sourceId));
    }

    private static function deriveBinaryId(string $sourceId, bool $withGallery): string
    {
        return Uuid::fromHexToBytes(self::deriveId($sourceId, $withGallery));
    }
}
