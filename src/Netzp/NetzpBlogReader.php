<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpAuthor;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpBlogData;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCategory;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCmsSlot;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpItem;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpPost;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpPostTranslation;

/**
 * Reads the NetzpBlog6 data directly from its tables, so the migration also works with the plugin deactivated.
 * Columns added by later NetzpBlog6 versions are read as null when they are missing.
 */
class NetzpBlogReader
{
    public const PLUGIN_NAME = 'NetzpBlog6';
    public const POST_TABLE = 's_plugin_netzp_blog';
    public const SEO_ROUTE = 'frontend.blog.post';
    public const LISTING_ELEMENT = 'netzp-blog6';
    public const DETAIL_ELEMENT = 'netzp-blog6-detail';
    public const MEDIA_DEFAULT_FOLDER_ID = '59d4f5b90e944d44b997ed0a60804034';

    private const CONFIG_PREFIX = 'NetzpBlog6.config.';

    /**
     * @var array<string, array<string, true>>
     */
    private array $columns = [];

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function isInstalled(): bool
    {
        return $this->tableExists(self::POST_TABLE);
    }

    public function read(): NetzpBlogData
    {
        return new NetzpBlogData(
            $this->readPosts(),
            $this->readAuthors(),
            $this->readCategories(),
            $this->readConfig(),
            $this->readSlots(),
            $this->readSeoPaths(),
            $this->readDraftPageIds(),
        );
    }

    /**
     * @return array<string, NetzpPost>
     */
    private function readPosts(): array
    {
        $rows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, postdate, showfrom, showuntil, LOWER(HEX(imageid)) AS imageid, %s, %s, %s, %s, %s, %s, %s, %s, created_at
                FROM s_plugin_netzp_blog
                ORDER BY postdate, created_at
                SQL,
            $this->hexColumn(self::POST_TABLE, 'imagepreviewid'),
            $this->hexColumn(self::POST_TABLE, 'categoryid'),
            $this->hexColumn(self::POST_TABLE, 'authorid'),
            $this->column(self::POST_TABLE, 'noindex'),
            $this->column(self::POST_TABLE, 'sticky'),
            $this->column(self::POST_TABLE, 'isproductstream'),
            $this->hexColumn(self::POST_TABLE, 'productstreamid'),
            $this->column(self::POST_TABLE, 'canonicalUrl'),
        ));

        $translations = $this->readPostTranslations();
        $categoryIds = $this->readGroupedIds('s_plugin_netzp_blog_categories', 'blog_id', 'category_id');
        $productIds = $this->readGroupedIds('s_plugin_netzp_blog_product', 'blog_id', 'product_id', 'product_version_id = :liveVersionId');
        $galleryMediaIds = $this->readGroupedIds('s_plugin_netzp_blog_media', 'blog_id', 'media_id', 'media_id IS NOT NULL', 'number');
        $tagNames = $this->readTagNames();
        $items = $this->readItems();

        $posts = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $primaryCategoryId = Row::id($row, 'categoryid');

            $posts[$id] = new NetzpPost(
                $id,
                Row::date($row, 'postdate'),
                Row::date($row, 'showfrom'),
                Row::date($row, 'showuntil'),
                Row::id($row, 'imageid'),
                Row::id($row, 'imagepreviewid'),
                $primaryCategoryId,
                Row::id($row, 'authorid'),
                Row::bool($row, 'noindex'),
                Row::bool($row, 'sticky'),
                Row::bool($row, 'isproductstream'),
                Row::id($row, 'productstreamid'),
                Row::nullableString($row, 'canonicalUrl'),
                Row::date($row, 'created_at'),
                $translations[$id] ?? [],
                array_values(array_diff($categoryIds[$id] ?? [], [$primaryCategoryId])),
                $productIds[$id] ?? [],
                $tagNames[$id] ?? [],
                $items[$id] ?? [],
                $galleryMediaIds[$id] ?? [],
            );
        }

        return $posts;
    }

    /**
     * @return array<string, array<string, NetzpPostTranslation>>
     */
    private function readPostTranslations(): array
    {
        $table = 's_plugin_netzp_blog_translation';
        $rows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT LOWER(HEX(s_plugin_netzp_blog_id)) AS post_id, LOWER(HEX(language_id)) AS language_id,
                    title, teaser, slug, contents, metatitle, metadescription, %s, %s
                FROM %s
                SQL,
            $this->column($table, 'custom'),
            $this->column($table, 'custom_fields'),
            $table,
        ));

        $translations = [];
        foreach ($rows as $row) {
            $translations[Row::string($row, 'post_id')][Row::string($row, 'language_id')] = new NetzpPostTranslation(
                Row::nullableString($row, 'title'),
                Row::nullableString($row, 'teaser'),
                Row::nullableString($row, 'slug'),
                Row::nullableString($row, 'contents'),
                Row::nullableString($row, 'custom'),
                Row::nullableString($row, 'metatitle'),
                Row::nullableString($row, 'metadescription'),
                Row::json($row, 'custom_fields'),
            );
        }

        return $translations;
    }

    /**
     * @return array<string, list<string>>
     */
    private function readGroupedIds(string $table, string $groupColumn, string $idColumn, ?string $condition = null, ?string $orderColumn = null): array
    {
        if (!$this->tableExists($table)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT LOWER(HEX(%s)) AS group_id, LOWER(HEX(%s)) AS id FROM %s %s %s',
                $groupColumn,
                $idColumn,
                $table,
                $condition !== null ? 'WHERE ' . $condition : '',
                $orderColumn !== null ? 'ORDER BY ' . $orderColumn : '',
            ),
            $condition !== null && str_contains($condition, ':liveVersionId') ? ['liveVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)] : [],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[Row::string($row, 'group_id')][] = Row::string($row, 'id');
        }

        return $ids;
    }

    /**
     * @return array<string, list<string>>
     */
    private function readTagNames(): array
    {
        if (!$this->tableExists('s_plugin_netzp_blog_tag')) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(blog_tag.blog_id)) AS post_id, tag.name
                FROM s_plugin_netzp_blog_tag blog_tag
                JOIN tag ON tag.id = blog_tag.tag_id
                ORDER BY tag.name
                SQL,
        );

        $names = [];
        foreach ($rows as $row) {
            $names[Row::string($row, 'post_id')][] = Row::string($row, 'name');
        }

        return $names;
    }

    /**
     * @return array<string, list<NetzpItem>>
     */
    private function readItems(): array
    {
        if (!$this->tableExists('s_plugin_netzp_blog_item')) {
            return [];
        }

        $translationRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(s_plugin_netzp_blog_item_id)) AS item_id, LOWER(HEX(language_id)) AS language_id, title, content
                FROM s_plugin_netzp_blog_item_translation
                SQL,
        );

        $translations = [];
        foreach ($translationRows as $row) {
            $translations[Row::string($row, 'item_id')][Row::string($row, 'language_id')] = [
                'title' => Row::nullableString($row, 'title'),
                'content' => Row::nullableString($row, 'content'),
            ];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, LOWER(HEX(blog_id)) AS post_id, number, LOWER(HEX(image_id)) AS image_id
                FROM s_plugin_netzp_blog_item
                ORDER BY number, created_at
                SQL,
        );

        $items = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $items[Row::string($row, 'post_id')][] = new NetzpItem(
                $id,
                Row::int($row, 'number'),
                Row::id($row, 'image_id'),
                $translations[$id] ?? [],
            );
        }

        return $items;
    }

    /**
     * @return array<string, NetzpAuthor>
     */
    private function readAuthors(): array
    {
        $table = 's_plugin_netzp_blog_author';
        if (!$this->tableExists($table)) {
            return [];
        }

        $translationTable = 's_plugin_netzp_blog_author_translation';
        $translationRows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT LOWER(HEX(s_plugin_netzp_blog_author_id)) AS author_id, LOWER(HEX(language_id)) AS language_id, name, bio, %s, %s
                FROM %s
                SQL,
            $this->column($translationTable, 'link'),
            $this->column($translationTable, 'custom_fields'),
            $translationTable,
        ));

        $translations = [];
        foreach ($translationRows as $row) {
            $translations[Row::string($row, 'author_id')][Row::string($row, 'language_id')] = [
                'name' => Row::nullableString($row, 'name'),
                'bio' => Row::nullableString($row, 'bio'),
                'link' => Row::nullableString($row, 'link'),
                'customFields' => Row::json($row, 'custom_fields'),
            ];
        }

        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT LOWER(HEX(id)) AS id, LOWER(HEX(imageid)) AS imageid, %s FROM %s',
            $this->column($table, 'link'),
            $table,
        ));

        $authors = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $authors[$id] = new NetzpAuthor($id, Row::id($row, 'imageid'), Row::nullableString($row, 'link'), $translations[$id] ?? []);
        }

        return $authors;
    }

    /**
     * @return array<string, NetzpCategory>
     */
    private function readCategories(): array
    {
        $table = 's_plugin_netzp_blog_category';
        if (!$this->tableExists($table)) {
            return [];
        }

        $translationTable = 's_plugin_netzp_blog_category_translation';
        $translationRows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                SELECT LOWER(HEX(s_plugin_netzp_blog_category_id)) AS category_id, LOWER(HEX(language_id)) AS language_id, title, teaser, %s
                FROM %s
                SQL,
            $this->column($translationTable, 'custom_fields'),
            $translationTable,
        ));

        $translations = [];
        foreach ($translationRows as $row) {
            $translations[Row::string($row, 'category_id')][Row::string($row, 'language_id')] = [
                'title' => Row::nullableString($row, 'title'),
                'teaser' => Row::nullableString($row, 'teaser'),
                'customFields' => Row::json($row, 'custom_fields'),
            ];
        }

        $rows = $this->connection->fetchAllAssociative(sprintf(
            'SELECT LOWER(HEX(id)) AS id, %s, %s, %s, %s, %s, %s FROM %s',
            $this->hexColumn($table, 'saleschannel_id'),
            $this->hexColumn($table, 'customergroup_id'),
            $this->column($table, 'onlyloggedin'),
            $this->column($table, 'includeinrss'),
            $this->column($table, 'cmspageid'),
            $this->hexColumn($table, 'navigationcategoryid'),
            $table,
        ));

        $categories = [];
        foreach ($rows as $row) {
            $id = Row::string($row, 'id');
            $categories[$id] = new NetzpCategory(
                $id,
                Row::id($row, 'saleschannel_id'),
                Row::id($row, 'customergroup_id'),
                Row::bool($row, 'onlyloggedin'),
                Row::bool($row, 'includeinrss'),
                Row::id($row, 'cmspageid'),
                Row::id($row, 'navigationcategoryid'),
                $translations[$id] ?? [],
            );
        }

        return $categories;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readConfig(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT configuration_key, configuration_value, LOWER(HEX(sales_channel_id)) AS sales_channel_id
                FROM system_config
                WHERE configuration_key LIKE :prefix
                SQL,
            ['prefix' => self::CONFIG_PREFIX . '%'],
        );

        $config = [];
        foreach ($rows as $row) {
            $key = substr(Row::string($row, 'configuration_key'), strlen(self::CONFIG_PREFIX));
            $config[$key][Row::nullableString($row, 'sales_channel_id') ?? ''] = Row::json($row, 'configuration_value')['_value'] ?? null;
        }

        return $config;
    }

    /**
     * @return list<NetzpCmsSlot>
     */
    private function readSlots(): array
    {
        $liveVersionId = Uuid::fromHexToBytes(Defaults::LIVE_VERSION);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(slot.id)) AS id, slot.type, slot.slot, LOWER(HEX(block.id)) AS block_id, block.type AS block_type,
                    LOWER(HEX(section.cms_page_id)) AS page_id
                FROM cms_slot slot
                JOIN cms_block block ON block.id = slot.cms_block_id AND block.version_id = slot.cms_block_version_id
                JOIN cms_section section ON section.id = block.cms_section_id AND section.version_id = block.cms_section_version_id
                WHERE slot.type IN (:types) AND slot.version_id = :versionId
                SQL,
            ['types' => [self::LISTING_ELEMENT, self::DETAIL_ELEMENT], 'versionId' => $liveVersionId],
            ['types' => ArrayParameterType::STRING],
        );

        if ($rows === []) {
            return [];
        }

        $translationRows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(cms_slot_id)) AS slot_id, LOWER(HEX(language_id)) AS language_id, config
                FROM cms_slot_translation
                WHERE cms_slot_id IN (:ids) AND cms_slot_version_id = :versionId
                SQL,
            ['ids' => Uuid::fromHexToBytesList(array_map(static fn (array $row) => Row::string($row, 'id'), $rows)), 'versionId' => $liveVersionId],
            ['ids' => ArrayParameterType::BINARY],
        );

        $configs = [];
        foreach ($translationRows as $row) {
            $languageId = Row::string($row, 'language_id');

            // A language without a config of its own inherits the one of the default language.
            if (Row::nullableString($row, 'config') === null && $languageId !== Defaults::LANGUAGE_SYSTEM) {
                continue;
            }

            $configs[Row::string($row, 'slot_id')][$languageId] = Row::json($row, 'config');
        }

        return array_map(static fn (array $row) => new NetzpCmsSlot(
            Row::string($row, 'id'),
            Row::string($row, 'type'),
            Row::string($row, 'slot'),
            Row::string($row, 'block_id'),
            Row::string($row, 'block_type'),
            Row::string($row, 'page_id'),
            $configs[Row::string($row, 'id')] ?? [],
        ), $rows);
    }

    /**
     * @return list<string>
     */
    private function readDraftPageIds(): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT LOWER(HEX(section.cms_page_id))
                FROM cms_slot slot
                JOIN cms_block block ON block.id = slot.cms_block_id AND block.version_id = slot.cms_block_version_id
                JOIN cms_section section ON section.id = block.cms_section_id AND section.version_id = block.cms_section_version_id
                WHERE slot.type IN (:types) AND slot.version_id != :versionId
                SQL,
            ['types' => [self::LISTING_ELEMENT, self::DETAIL_ELEMENT], 'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
            ['types' => ArrayParameterType::STRING],
        );
    }

    /**
     * @return array<string, list<array{path: string, canonical: bool}>>
     */
    private function readSeoPaths(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(foreign_key)) AS post_id, seo_path_info, is_canonical FROM seo_url WHERE route_name = :route AND is_deleted = 0',
            ['route' => self::SEO_ROUTE],
        );

        $paths = [];
        foreach ($rows as $row) {
            $paths[Row::string($row, 'post_id')][] = [
                'path' => Row::string($row, 'seo_path_info'),
                'canonical' => Row::bool($row, 'is_canonical'),
            ];
        }

        return $paths;
    }

    private function column(string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? sprintf('`%s`', $column) : sprintf('NULL AS `%s`', $column);
    }

    private function hexColumn(string $table, string $column): string
    {
        return $this->hasColumn($table, $column) ? sprintf('LOWER(HEX(`%1$s`)) AS `%1$s`', $column) : sprintf('NULL AS `%s`', $column);
    }

    private function hasColumn(string $table, string $column): bool
    {
        if (!isset($this->columns[$table])) {
            $names = $this->connection->fetchFirstColumn(
                'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
                ['table' => $table],
            );

            $this->columns[$table] = [];
            foreach ($names as $name) {
                $this->columns[$table][strtolower(is_string($name) ? $name : '')] = true;
            }
        }

        return isset($this->columns[$table][strtolower($column)]);
    }

    private function tableExists(string $table): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table',
            ['table' => $table],
        ) !== false;
    }
}
