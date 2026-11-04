<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\MirroredLayout;

/**
 * The write payloads of a migration, computed up front so a dry run reports exactly what a real run writes.
 */
final class MigrationPlan
{
    /**
     * @var array<string, mixed>|null
     */
    public ?array $customFieldSet = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $customFieldSetRelations = [];

    /**
     * @var list<string>
     */
    public array $removedCustomFieldSetRelationIds = [];

    /**
     * @var array<string, mixed>|null
     */
    public ?array $sorting = null;

    /**
     * @var list<array<string, mixed>>
     */
    public array $authors = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $categories = [];

    /**
     * @var list<MirroredLayout>
     */
    public array $layouts = [];

    /**
     * @var list<string>
     */
    public array $seoUrlPostIds = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $articles = [];

    /**
     * Articles migrated again, whose category, visibility and product assignments are replaced instead of extended.
     *
     * @var list<string>
     */
    public array $replacedArticleIds = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $slotUpdates = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $blockUpdates = [];

    public ?string $detachedMediaFolderId = null;

    /**
     * An active NetzpBlog6 regenerates the post SEO URLs whenever a post is saved or the indexes are rebuilt, which
     * takes over the identical SEO URLs of the migrated articles.
     */
    public bool $deactivateNetzpBlog = false;

    public function __construct(
        public readonly MigrationReport $report,
    ) {}
}
