<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Netzp\Row;

/**
 * Looks up the state of the target shop the migration depends on.
 */
class ShopwareLookup
{
    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
        private readonly EntityRepository $mediaRepository,
    ) {}

    public function isContentManagerReady(): bool
    {
        return $this->definitionRegistry->has(ContentManager::AUTHOR);
    }

    public function isPluginActive(string $name): bool
    {
        return (bool) $this->connection->fetchOne('SELECT active FROM plugin WHERE name = :name', ['name' => $name]);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the given ids that exist in the table
     */
    public function filterExisting(string $table, array $ids, bool $versioned = false): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $parameters = ['ids' => Uuid::fromHexToBytesList($ids)];
        if ($versioned) {
            $parameters['versionId'] = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);
        }

        $existing = $this->connection->fetchFirstColumn(
            sprintf('SELECT DISTINCT LOWER(HEX(id)) FROM `%s` WHERE id IN (:ids)%s', $table, $versioned ? ' AND version_id = :versionId' : ''),
            $parameters,
            ['ids' => ArrayParameterType::BINARY],
        );

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $existing, true)));
    }

    /**
     * @return list<string>
     */
    public function getStorefrontSalesChannelIds(): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM sales_channel WHERE type_id = :typeId ORDER BY created_at',
            ['typeId' => Uuid::fromHexToBytes(Defaults::SALES_CHANNEL_TYPE_STOREFRONT)],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the given category ids that are the root category or below it
     */
    public function filterCategoriesInTree(string $rootId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        $inTree = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM category WHERE id IN (:ids) AND version_id = :versionId AND (id = :rootId OR path LIKE :path)',
            [
                'ids' => Uuid::fromHexToBytesList($ids),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'rootId' => Uuid::fromHexToBytes($rootId),
                'path' => '%|' . $rootId . '|%',
            ],
            ['ids' => ArrayParameterType::BINARY],
        );

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $inTree, true)));
    }

    public function getCategoryLayoutId(string $categoryId): ?string
    {
        $layoutId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(cms_page_id)) FROM category WHERE id = :id AND version_id = :versionId',
            ['id' => Uuid::fromHexToBytes($categoryId), 'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );

        return is_string($layoutId) && $layoutId !== '' ? $layoutId : null;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> page names in the default language, keyed by page id
     */
    public function getLayoutNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(cms_page_id)) AS id, name FROM cms_page_translation
                WHERE cms_page_id IN (:ids) AND cms_page_version_id = :versionId AND language_id = :languageId
                SQL,
            [
                'ids' => Uuid::fromHexToBytesList(array_values(array_unique($ids))),
                'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                'languageId' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            ],
            ['ids' => ArrayParameterType::BINARY],
        );

        $names = [];
        foreach ($rows as $row) {
            $names[Row::string($row, 'id')] = Row::nullableString($row, 'name') ?? Row::string($row, 'id');
        }

        return $names;
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, string> absolute media URLs keyed by media id
     */
    public function getMediaUrls(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $urls = [];
        foreach ($this->mediaRepository->search(new Criteria(array_values(array_unique($ids))), Context::createDefaultContext())->getEntities() as $media) {
            if ($media->getUrl() !== '') {
                $urls[$media->getId()] = $media->getUrl();
            }
        }

        return $urls;
    }

    /**
     * @return list<string> ids of the languages without a parent language
     */
    public function getRootLanguageIds(): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT LOWER(HEX(id)) FROM language WHERE parent_id IS NULL');
    }

    /**
     * @return array<string, string> language ids keyed by locale code
     */
    public function getLanguageIdsByLocale(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(language.id)) AS id, locale.code FROM language JOIN locale ON locale.id = language.locale_id',
        );

        $languages = [];
        foreach ($rows as $row) {
            $languages[Row::string($row, 'code')] = Row::string($row, 'id');
        }

        return $languages;
    }

    /**
     * @param list<string> $entityNames
     *
     * @return list<array{id: string, setId: string, entityName: string}>
     */
    public function getCustomFieldSetRelations(array $entityNames): array
    {
        return array_map(static fn (array $row): array => [
            'id' => Row::string($row, 'id'),
            'setId' => Row::string($row, 'set_id'),
            'entityName' => Row::string($row, 'entity_name'),
        ], $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(set_id)) AS set_id, entity_name FROM custom_field_set_relation WHERE entity_name IN (:names)',
            ['names' => $entityNames],
            ['names' => ArrayParameterType::STRING],
        ));
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string> the given media ids that are images
     */
    public function filterImages(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $images = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM media WHERE id IN (:ids) AND mime_type LIKE :image',
            ['ids' => Uuid::fromHexToBytesList(array_values(array_unique($ids))), 'image' => 'image/%'],
            ['ids' => ArrayParameterType::BINARY],
        );

        return array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $images, true)));
    }

    /**
     * @return list<string> lower case hosts of all sales channel domains
     */
    public function getDomainHosts(): array
    {
        $hosts = [];
        foreach ($this->connection->fetchFirstColumn('SELECT url FROM sales_channel_domain') as $url) {
            $host = is_string($url) ? parse_url($url, \PHP_URL_HOST) : null;
            if (is_string($host)) {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * Categories, landing pages and products can override the settings of the slots of their layout.
     *
     * @param list<string> $slotIds
     *
     * @return list<string> names of the entities overriding one of the slots
     */
    public function findSlotConfigOverrides(array $slotIds): array
    {
        if ($slotIds === []) {
            return [];
        }

        $names = [];
        foreach (['category_translation', 'landing_page_translation', 'product_translation'] as $table) {
            $rows = $this->connection->fetchAllAssociative(sprintf('SELECT name, slot_config FROM `%s` WHERE slot_config IS NOT NULL', $table));
            foreach ($rows as $row) {
                $overrides = Row::json($row, 'slot_config');
                if (array_intersect(array_map('strtolower', array_keys($overrides)), $slotIds) !== []) {
                    $names[] = Row::nullableString($row, 'name') ?? $table;
                }
            }
        }

        return array_values(array_unique($names));
    }

    public function getColumnByteLimit(string $table, string $column): ?int
    {
        $limit = $this->connection->fetchOne(
            'SELECT CHARACTER_OCTET_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column',
            ['table' => $table, 'column' => $column],
        );

        return is_numeric($limit) ? (int) $limit : null;
    }

    public function getMediaFolderIdOfDefaultFolder(string $defaultFolderId): ?string
    {
        $folderId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(id)) FROM media_folder WHERE default_folder_id = :id',
            ['id' => Uuid::fromHexToBytes($defaultFolderId)],
        );

        return is_string($folderId) ? $folderId : null;
    }
}
