<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\MirroredLayout;
use Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader;

/**
 * Writes a migration plan in one transaction, so a failing migration leaves the shop unchanged.
 */
class PlanExecutor
{
    private const ARTICLE_BATCH_SIZE = 10;

    public function __construct(
        private readonly Connection $connection,
        private readonly DefinitionInstanceRegistry $definitionRegistry,
    ) {}

    /**
     * @param callable(string): void|null $progress
     */
    public function execute(MigrationPlan $plan, ?callable $progress = null): void
    {
        $progress ??= static function (string $step): void {};
        $context = Context::createDefaultContext();

        $this->connection->transactional(function () use ($plan, $progress, $context): void {
            if ($plan->customFieldSet !== null) {
                $progress('Custom fields');
                $this->upsert('custom_field_set', [$plan->customFieldSet], $context);
            }

            $this->upsert('custom_field_set_relation', $plan->customFieldSetRelations, $context);
            if ($plan->removedCustomFieldSetRelationIds !== []) {
                $this->definitionRegistry->getRepository('custom_field_set_relation')->delete(
                    array_map(static fn (string $id): array => ['id' => $id], $plan->removedCustomFieldSetRelationIds),
                    $context,
                );
            }

            if ($plan->sorting !== null) {
                $progress('Article sorting');
                $this->insertSorting($plan->sorting);
            }

            $progress('Authors');
            $this->upsert(ContentManager::AUTHOR, $plan->authors, $context);

            $progress('Categories');
            $this->upsert('category', $plan->categories, $context);

            $progress('Article layouts');
            foreach ($plan->layouts as $layout) {
                $this->insertLayout($layout);
            }

            // The article indexer generates SEO URLs on save; it keeps modified canonical URLs, so they are moved first.
            $progress('SEO URLs');
            $this->moveSeoUrls($plan->seoUrlPostIds);

            $progress('Articles');
            $this->removeAssignments($plan->replacedArticleIds);
            foreach (array_chunk($plan->articles, self::ARTICLE_BATCH_SIZE) as $articles) {
                $this->upsert(ContentManager::ARTICLE, $articles, $context);
            }

            $progress('Blog listings and teasers');
            $this->update('cms_block', $plan->blockUpdates, $context);
            $this->update('cms_slot', $plan->slotUpdates, $context);

            if ($plan->detachedMediaFolderId !== null) {
                $progress('Media folder');
                $this->connection->executeStatement(
                    'UPDATE media_folder SET default_folder_id = NULL WHERE id = :id',
                    ['id' => Uuid::fromHexToBytes($plan->detachedMediaFolderId)],
                );
            }
        });
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function upsert(string $entityName, array $payload, Context $context): void
    {
        if ($payload !== []) {
            $this->definitionRegistry->getRepository($entityName)->upsert($payload, $context);
        }
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function update(string $entityName, array $payload, Context $context): void
    {
        if ($payload !== []) {
            $this->definitionRegistry->getRepository($entityName)->update($payload, $context);
        }
    }

    /**
     * The sorting is inserted directly, as the data abstraction layer never writes its computed "locked" column,
     * which has no default value.
     *
     * @param array<string, mixed> $sorting
     */
    private function insertSorting(array $sorting): void
    {
        $exists = $this->connection->fetchOne(
            sprintf('SELECT 1 FROM `%s` WHERE url_key = :key', ContentManager::ARTICLE_SORTING),
            ['key' => $sorting['key']],
        );
        if ($exists !== false) {
            return;
        }

        $id = is_string($sorting['id'] ?? null) ? Uuid::fromHexToBytes($sorting['id']) : Uuid::randomBytes();
        $now = (new \DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT);

        $this->connection->insert(ContentManager::ARTICLE_SORTING, [
            'id' => $id,
            'url_key' => $sorting['key'],
            'fields' => json_encode($sorting['fields'], \JSON_THROW_ON_ERROR),
            'priority' => $sorting['priority'],
            'active' => 1,
            'locked' => 0,
            'created_at' => $now,
        ]);

        foreach (is_array($sorting['translations'] ?? null) ? $sorting['translations'] : [] as $languageId => $translation) {
            $this->connection->insert(ContentManager::ARTICLE_SORTING . '_translation', [
                'swkweb_content_manager_article_sorting_id' => $id,
                'language_id' => Uuid::fromHexToBytes((string) $languageId),
                'label' => is_array($translation) ? ($translation['label'] ?? null) : null,
                'created_at' => $now,
            ]);
        }
    }

    private function insertLayout(MirroredLayout $layout): void
    {
        $exists = $this->connection->fetchOne('SELECT 1 FROM cms_page WHERE id = :id', ['id' => Uuid::fromHexToBytes($layout->pageId)]);
        if ($exists !== false) {
            return;
        }

        foreach ($layout->rows as $table => $rows) {
            foreach ($rows as $row) {
                $columns = array_keys($row);
                $this->connection->executeStatement(
                    sprintf(
                        'INSERT INTO `%s` (%s) VALUES (%s)',
                        $table,
                        implode(', ', array_map(static fn (string $column) => sprintf('`%s`', $column), $columns)),
                        implode(', ', array_fill(0, count($columns), '?')),
                    ),
                    array_values($row),
                );
            }
        }
    }

    /**
     * Many-to-many assignments are only added by an upsert, so the ones of articles migrated again are removed first.
     *
     * @param list<string> $articleIds
     */
    private function removeAssignments(array $articleIds): void
    {
        if ($articleIds === []) {
            return;
        }

        foreach (['category', 'main_category', 'visibility', 'product', 'inverse_product'] as $assignment) {
            $this->connection->executeStatement(
                sprintf('DELETE FROM `%1$s_%2$s` WHERE %1$s_id IN (:ids) AND %1$s_version_id = :versionId', ContentManager::ARTICLE, $assignment),
                ['ids' => Uuid::fromHexToBytesList($articleIds), 'versionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                ['ids' => ArrayParameterType::BINARY],
            );
        }
    }

    /**
     * @param list<string> $postIds
     */
    private function moveSeoUrls(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        // An article can only have one canonical URL per sales channel and language: the post URL takes precedence.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE seo_url article_url
                JOIN seo_url post_url ON post_url.foreign_key = article_url.foreign_key
                    AND post_url.language_id = article_url.language_id
                    AND post_url.sales_channel_id <=> article_url.sales_channel_id
                    AND post_url.route_name = :postRoute
                    AND post_url.is_canonical = 1
                SET article_url.is_canonical = NULL
                WHERE article_url.route_name = :articleRoute AND article_url.is_canonical = 1 AND article_url.foreign_key IN (:ids)
                SQL,
            [
                'articleRoute' => ContentManager::ARTICLE_ROUTE,
                'postRoute' => NetzpBlogReader::SEO_ROUTE,
                'ids' => Uuid::fromHexToBytesList($postIds),
            ],
            ['ids' => ArrayParameterType::BINARY],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE seo_url
                SET route_name = :articleRoute,
                    path_info = CONCAT('/article/', LOWER(HEX(foreign_key))),
                    is_modified = IF(is_canonical = 1, 1, is_modified)
                WHERE route_name = :postRoute AND foreign_key IN (:ids)
                SQL,
            [
                'articleRoute' => ContentManager::ARTICLE_ROUTE,
                'postRoute' => NetzpBlogReader::SEO_ROUTE,
                'ids' => Uuid::fromHexToBytesList($postIds),
            ],
            ['ids' => ArrayParameterType::BINARY],
        );
    }
}
