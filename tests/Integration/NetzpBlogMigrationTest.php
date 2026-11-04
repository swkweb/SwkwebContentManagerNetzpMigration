<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\UnusedMediaPurger;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\DefinitionInstanceRegistry;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\LayoutMirror;
use Swkweb\ContentManagerNetzpMigration\Migrator\CustomFields;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationException;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationOptions;
use Swkweb\ContentManagerNetzpMigration\Migrator\NetzpBlogMigrator;
use Swkweb\ContentManagerNetzpMigration\Migrator\PlanExecutor;

/**
 * @internal
 */
#[CoversClass(NetzpBlogMigrator::class)]
#[CoversClass(PlanExecutor::class)]
final class NetzpBlogMigrationTest extends TestCase
{
    use IntegrationTestBehaviour;

    private const NETZP_TABLES = [
        's_plugin_netzp_blog' => <<<'SQL'
            id binary(16) NOT NULL PRIMARY KEY, postdate date, showfrom date, showuntil date, imageid binary(16), imagepreviewid binary(16),
            categoryid binary(16), authorid binary(16), noindex tinyint(1) NOT NULL DEFAULT 0, sticky tinyint(1) NOT NULL DEFAULT 0,
            isproductstream tinyint(1) NOT NULL DEFAULT 0, productstreamid binary(16), canonicalUrl varchar(255), tag_ids json, tags binary(16),
            created_at datetime(3) NOT NULL, updated_at datetime(3)
            SQL,
        's_plugin_netzp_blog_translation' => <<<'SQL'
            s_plugin_netzp_blog_id binary(16) NOT NULL, language_id binary(16) NOT NULL, title varchar(255), teaser varchar(255),
            slug varchar(255), contents longtext, custom longtext, metatitle varchar(255), metadescription varchar(255), custom_fields json,
            created_at datetime(3) NOT NULL, updated_at datetime(3), PRIMARY KEY (s_plugin_netzp_blog_id, language_id)
            SQL,
        's_plugin_netzp_blog_author' => 'id binary(16) NOT NULL PRIMARY KEY, imageid binary(16), link varchar(255), created_at datetime(3) NOT NULL, updated_at datetime(3)',
        's_plugin_netzp_blog_author_translation' => <<<'SQL'
            s_plugin_netzp_blog_author_id binary(16) NOT NULL, language_id binary(16) NOT NULL, name varchar(255), bio longtext,
            link varchar(255), custom_fields json, created_at datetime(3) NOT NULL, updated_at datetime(3), PRIMARY KEY (s_plugin_netzp_blog_author_id, language_id)
            SQL,
        's_plugin_netzp_blog_category' => <<<'SQL'
            id binary(16) NOT NULL PRIMARY KEY, cmspageid varchar(255), saleschannel_id binary(16), customergroup_id binary(16),
            onlyloggedin tinyint(1) NOT NULL DEFAULT 0, includeinrss tinyint(1) NOT NULL DEFAULT 0, navigationcategoryid binary(16),
            created_at datetime(3) NOT NULL, updated_at datetime(3)
            SQL,
        's_plugin_netzp_blog_category_translation' => <<<'SQL'
            s_plugin_netzp_blog_category_id binary(16) NOT NULL, language_id binary(16) NOT NULL, title varchar(255),
            teaser longtext, custom_fields json, created_at datetime(3) NOT NULL, updated_at datetime(3), PRIMARY KEY (s_plugin_netzp_blog_category_id, language_id)
            SQL,
        's_plugin_netzp_blog_categories' => 'blog_id binary(16) NOT NULL, category_id binary(16) NOT NULL, created_at datetime(3) NOT NULL, PRIMARY KEY (blog_id, category_id)',
        's_plugin_netzp_blog_product' => 'blog_id binary(16) NOT NULL, product_id binary(16) NOT NULL, product_version_id binary(16) NOT NULL, created_at datetime(3) NOT NULL',
        's_plugin_netzp_blog_tag' => 'blog_id binary(16) NOT NULL, tag_id binary(16) NOT NULL',
        's_plugin_netzp_blog_item' => <<<'SQL'
            id binary(16) NOT NULL PRIMARY KEY, blog_id binary(16) NOT NULL, number tinyint(1) NOT NULL DEFAULT 0, image_id binary(16),
            created_at datetime(3) NOT NULL, updated_at datetime(3)
            SQL,
        's_plugin_netzp_blog_item_translation' => <<<'SQL'
            s_plugin_netzp_blog_item_id binary(16) NOT NULL, language_id binary(16) NOT NULL, title varchar(255), content longtext,
            created_at datetime(3) NOT NULL, updated_at datetime(3), PRIMARY KEY (s_plugin_netzp_blog_item_id, language_id)
            SQL,
        's_plugin_netzp_blog_media' => <<<'SQL'
            id binary(16) NOT NULL PRIMARY KEY, blog_id binary(16) NOT NULL, number tinyint(1) NOT NULL DEFAULT 0, media_id binary(16),
            created_at datetime(3) NOT NULL, updated_at datetime(3)
            SQL,
    ];

    private Connection $connection;

    private Context $context;

    public static function setUpBeforeClass(): void
    {
        $connection = self::service(Connection::class);
        foreach (self::NETZP_TABLES as $table => $columns) {
            $connection->executeStatement(sprintf('CREATE TABLE IF NOT EXISTS `%s` (%s) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci', $table, $columns));
        }
    }

    public static function tearDownAfterClass(): void
    {
        $connection = self::service(Connection::class);
        foreach (array_keys(self::NETZP_TABLES) as $table) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    protected function setUp(): void
    {
        if (!self::service(DefinitionInstanceRegistry::class)->has('swkweb_content_manager_author')) {
            self::markTestSkipped('The Content Manager 4.4.0 or newer is required.');
        }

        $this->connection = self::service(Connection::class);
        $this->context = Context::createDefaultContext();
    }

    public function testMigratesPostsAuthorsCategoriesLayoutsAndSeoUrls(): void
    {
        $parentCategoryId = $this->createEntity('category', ['name' => 'Blog']);
        $mediaId = $this->createEntity('media', ['mimeType' => 'image/jpeg', 'fileExtension' => 'jpg', 'fileName' => 'first-post']);
        $authorId = $this->insertAuthor('Jane Doe', '<p>Writes about darts.</p>');
        $newsCategoryId = $this->insertNetzpCategory('News');
        $membersCategoryId = $this->insertNetzpCategory('Members', ['onlyloggedin' => 1]);

        [$detailLayoutId, $productSliderSlotId] = $this->createDetailLayout();
        $homeSlotId = $this->createTeaserLayout();

        $systemConfig = self::service(SystemConfigService::class);
        $systemConfig->set('NetzpBlog6.config.cmspage', $detailLayoutId);
        $systemConfig->set('NetzpBlog6.config.navigationcategory', $parentCategoryId);

        $firstPostId = $this->insertPost('First post', [
            'postdate' => '2024-01-10',
            'imageid' => Uuid::fromHexToBytes($mediaId),
            'authorid' => Uuid::fromHexToBytes($authorId),
            'categoryid' => Uuid::fromHexToBytes($newsCategoryId),
        ], ['slug' => 'first-post', 'contents' => '<p>Hello darts</p>', 'teaser' => 'A short teaser']);
        $this->insertItem($firstPostId, 'Checkout tips', '<p>Practice doubles.</p>');
        $this->insertSeoUrl($firstPostId, 'blog/first-post');

        $membersPostId = $this->insertPost('Members post', [
            'postdate' => '2019-12-01',
            'showuntil' => '2020-01-01',
            'sticky' => 1,
            'categoryid' => Uuid::fromHexToBytes($membersCategoryId),
        ], ['slug' => 'members-post', 'contents' => '<p>Only for members</p>']);
        $this->insertTag($membersPostId, 'darts');

        $expiredPostId = $this->insertPost('Expired post', [
            'postdate' => '2019-12-01',
            'showuntil' => '2020-01-01',
            'categoryid' => Uuid::fromHexToBytes($newsCategoryId),
        ], ['slug' => 'expired-post', 'contents' => '<p>Old news</p>']);

        $endingPostId = $this->insertPost('Ending post', [
            'postdate' => '2024-01-10',
            'showuntil' => (new \DateTimeImmutable('+1 year'))->format('Y-m-d'),
            'categoryid' => Uuid::fromHexToBytes($newsCategoryId),
        ], ['slug' => 'ending-post', 'contents' => '<p>News for a while</p>']);

        $migrator = self::service(NetzpBlogMigrator::class);
        $plan = $migrator->plan(new MigrationOptions());
        self::service(PlanExecutor::class)->execute($plan);

        $firstPost = $this->getArticle($firstPostId);
        self::assertSame('First post', $firstPost->get('title'));
        $content = $firstPost->get('content');
        self::assertIsString($content);
        self::assertStringContainsString('<p>Hello darts</p>', $content);
        self::assertStringContainsString('<h4>Checkout tips</h4><p>Practice doubles.</p>', $content);
        self::assertSame('A short teaser', $firstPost->get('description'));
        self::assertTrue($firstPost->get('active'));
        self::assertSame($authorId, $firstPost->get('authorId'));
        self::assertSame($mediaId, $firstPost->get('mediaId'));
        self::assertSame(LayoutMirror::getPageId($detailLayoutId, false), $firstPost->get('cmsPageId'));
        self::assertSame('first-post', $this->getCustomFields($firstPost)[CustomFields::SLUG] ?? null);

        $categoryIds = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(category_id)) FROM swkweb_content_manager_article_category WHERE swkweb_content_manager_article_id = ?',
            [Uuid::fromHexToBytes($firstPostId)],
        );
        self::assertCount(1, $categoryIds);
        $category = $this->connection->fetchAssociative(
            'SELECT LOWER(HEX(parent_id)) AS parent_id, visible FROM category WHERE id = ? AND version_id = ?',
            [Uuid::fromHexToBytes(self::string($categoryIds[0])), Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        );
        self::assertSame(['parent_id' => $parentCategoryId, 'visible' => '0'], self::stringify($category));

        $membersPost = $this->getArticle($membersPostId);
        self::assertFalse($membersPost->get('active'));
        self::assertSame(1, $this->getCustomFields($membersPost)[CustomFields::PIN_PRIORITY] ?? null);

        self::assertFalse($this->getArticle($expiredPostId)->get('active'));
        self::assertTrue($this->getArticle($endingPostId)->get('active'));

        $author = self::service(DefinitionInstanceRegistry::class)->getRepository('swkweb_content_manager_author')
            ->search(new Criteria([$authorId]), $this->context)->getEntities()->first()
        ;
        self::assertInstanceOf(Entity::class, $author);
        self::assertSame('Jane Doe', $author->get('name'));
        self::assertSame('<p>Writes about darts.</p>', $author->get('description'));

        $seoUrl = $this->connection->fetchAssociative(
            'SELECT route_name, path_info, is_canonical, is_modified FROM seo_url WHERE seo_path_info = ?',
            ['blog/first-post'],
        );
        self::assertSame([
            'route_name' => 'frontend.swkweb.content-manager.article.page',
            'path_info' => '/article/' . $firstPostId,
            'is_canonical' => '1',
            'is_modified' => '1',
        ], self::stringify($seoUrl));

        self::assertSame(
            ['product-slider', 'swkweb-content-manager-article-detail-content', 'swkweb-content-manager-article-detail-title'],
            $this->getSlotTypes(LayoutMirror::getPageId($detailLayoutId, false)),
        );
        self::assertNotFalse($this->connection->fetchOne(
            'SELECT 1 FROM cms_slot WHERE id = ?',
            [Uuid::fromHexToBytes(Uuid::fromStringToHex('swkweb-netzp-migration:layout:default:' . $productSliderSlotId))],
        ));

        self::assertSame('swkweb-content-manager-article-teaser', $this->connection->fetchOne(
            'SELECT type FROM cms_slot WHERE id = ? AND version_id = ?',
            [Uuid::fromHexToBytes($homeSlotId), Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
        ));

        self::assertNotFalse($this->connection->fetchOne(
            'SELECT 1 FROM swkweb_content_manager_article_sorting WHERE url_key = ?',
            [NetzpBlogMigrator::PINNED_SORTING_KEY],
        ));

        $warnings = $plan->report->getWarnings();
        self::assertSame(['Members post (darts)'], $warnings['Tags have no equivalent and are not migrated'] ?? null);
        self::assertContains('Members post', $warnings['Articles cannot be restricted to customer groups or logged-in customers: posts of restricted categories are migrated inactive, so they do not become public'] ?? []);
        $expiredPosts = $warnings['Articles have no publication end date: posts past their "show until" date are migrated inactive'] ?? [];
        sort($expiredPosts);
        self::assertSame(['Expired post', 'Members post'], $expiredPosts);
        self::assertSame(['Ending post'], $warnings['Articles have no publication end date: posts with a "show until" date in the future stay published after it, deactivate them manually'] ?? null);
        self::assertArrayHasKey('The social sharing buttons of NetzpShariff6 have no equivalent and are left out of the article layouts', $warnings);

        $secondPlan = $migrator->plan(new MigrationOptions());
        self::assertSame([], $secondPlan->articles);
        self::assertSame([], $secondPlan->authors);
        self::assertSame([], $secondPlan->categories);
        self::assertSame([], $secondPlan->slotUpdates);
    }

    public function testOverwriteReplacesAssignmentsAndKeepsThePostUrlCanonical(): void
    {
        $parentCategoryId = $this->createEntity('category', ['name' => 'Blog']);
        $firstCategoryId = $this->insertNetzpCategory('First');
        $secondCategoryId = $this->insertNetzpCategory('Second');
        $postId = $this->insertPost('Moving post', [
            'postdate' => '2024-01-10',
            'sticky' => 1,
            'categoryid' => Uuid::fromHexToBytes($firstCategoryId),
        ], ['slug' => 'moving-post', 'contents' => '<p>Text</p>']);

        $skippedPostId = $this->insertPost('Skipped post', ['postdate' => '2024-01-10'], ['contents' => '<p>Text</p>']);

        $options = new MigrationOptions($parentCategoryId);
        $migrator = self::service(NetzpBlogMigrator::class);
        $executor = self::service(PlanExecutor::class);
        $executor->execute($migrator->plan($options));

        $draftVersionId = self::service(DefinitionInstanceRegistry::class)->getRepository('swkweb_content_manager_article')->createVersion($postId, $this->context);
        self::assertSame(1, $this->countVisibilities($postId, $draftVersionId));

        $this->connection->update('s_plugin_netzp_blog', ['sticky' => 0, 'categoryid' => Uuid::fromHexToBytes($secondCategoryId)], ['id' => Uuid::fromHexToBytes($postId)]);
        $this->connection->delete('s_plugin_netzp_blog_translation', ['s_plugin_netzp_blog_id' => Uuid::fromHexToBytes($skippedPostId)]);
        $this->insertSeoUrl($postId, 'blog/moving-post');

        $executor->execute($migrator->plan(new MigrationOptions($parentCategoryId, [], true)));

        self::assertSame(1, $this->countVisibilities($skippedPostId, Defaults::LIVE_VERSION));
        self::assertSame(1, $this->countVisibilities($postId, $draftVersionId));

        $categoryNames = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT translation.name FROM swkweb_content_manager_article_category assignment
                JOIN category_translation translation ON translation.category_id = assignment.category_id
                WHERE assignment.swkweb_content_manager_article_id = ? AND assignment.swkweb_content_manager_article_version_id = ? AND translation.language_id = ?
                SQL,
            [Uuid::fromHexToBytes($postId), Uuid::fromHexToBytes(Defaults::LIVE_VERSION), Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)],
        );
        self::assertSame(['Second'], $categoryNames);
        self::assertNull($this->getCustomFields($this->getArticle($postId))[CustomFields::PIN_PRIORITY] ?? null);

        $canonicalPaths = $this->connection->fetchFirstColumn(
            'SELECT seo_path_info FROM seo_url WHERE foreign_key = ? AND route_name = ? AND is_canonical = 1',
            [Uuid::fromHexToBytes($postId), 'frontend.swkweb.content-manager.article.page'],
        );
        self::assertContains('blog/moving-post', $canonicalPaths);
        self::assertFalse($this->connection->fetchOne(
            'SELECT 1 FROM seo_url WHERE foreign_key = ? AND route_name = ? AND is_canonical = 1 GROUP BY sales_channel_id, language_id HAVING COUNT(*) > 1',
            [Uuid::fromHexToBytes($postId), 'frontend.swkweb.content-manager.article.page'],
        ));
    }

    public function testKeepsPostsOfCategoriesLimitedToADeletedSalesChannelInvisible(): void
    {
        $categoryId = $this->insertNetzpCategory('Old shop', ['saleschannel_id' => Uuid::randomBytes()]);
        $postId = $this->insertPost('Old shop post', ['postdate' => '2024-01-10', 'categoryid' => Uuid::fromHexToBytes($categoryId)], ['contents' => '<p>Text</p>']);

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog']))));

        self::assertFalse($this->connection->fetchOne(
            'SELECT 1 FROM swkweb_content_manager_article_visibility WHERE swkweb_content_manager_article_id = ?',
            [Uuid::fromHexToBytes($postId)],
        ));
    }

    public function testAlsoAssignsPostsOfNavigationCategoriesOutsideTheBlogToTheBlogCategory(): void
    {
        $blogCategoryId = $this->createEntity('category', ['name' => 'Blog']);
        $newsCategoryId = $this->createEntity('category', ['name' => 'News', 'parentId' => $blogCategoryId]);
        $boardsCategoryId = $this->createEntity('category', ['name' => 'Dartboards']);

        $newsPostId = $this->insertPost('Club news', [
            'postdate' => '2024-01-10',
            'categoryid' => Uuid::fromHexToBytes($this->insertNetzpCategory('News', ['navigationcategoryid' => Uuid::fromHexToBytes($newsCategoryId)])),
        ], ['contents' => '<p>Text</p>']);
        $boardsPostId = $this->insertPost('Board review', [
            'postdate' => '2024-01-10',
            'categoryid' => Uuid::fromHexToBytes($this->insertNetzpCategory('Boards', ['navigationcategoryid' => Uuid::fromHexToBytes($boardsCategoryId)])),
        ], ['contents' => '<p>Text</p>']);

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($blogCategoryId)));

        self::assertSame([$newsCategoryId], $this->getAssignedCategoryIds($newsPostId));
        self::assertSame([], $this->getMainCategoryIds($newsPostId));

        $boardsCategoryIds = [$blogCategoryId, $boardsCategoryId];
        sort($boardsCategoryIds);
        self::assertSame($boardsCategoryIds, $this->getAssignedCategoryIds($boardsPostId));
        self::assertSame([$boardsCategoryId], $this->getMainCategoryIds($boardsPostId));
    }

    public function testOverridesTheLayoutInRootLanguagesWithoutPostText(): void
    {
        [$detailLayoutId, $productSliderSlotId] = $this->createDetailLayout();
        self::service(SystemConfigService::class)->set('NetzpBlog6.config.cmspage', $detailLayoutId);

        $languageId = $this->createEntity('language', [
            'name' => 'Français',
            'localeId' => $this->getLocaleId('fr-FR'),
            'translationCodeId' => $this->getLocaleId('fr-FR'),
        ]);
        $streamId = $this->createEntity('product_stream', ['name' => 'Dartboards']);
        $postId = $this->insertPost('Board deals', [
            'postdate' => '2024-01-10',
            'isproductstream' => 1,
            'productstreamid' => Uuid::fromHexToBytes($streamId),
        ], ['contents' => '<p>Text</p>']);

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog']))));

        $slotConfig = $this->connection->fetchOne(
            'SELECT slot_config FROM swkweb_content_manager_article_translation WHERE swkweb_content_manager_article_id = ? AND language_id = ?',
            [Uuid::fromHexToBytes($postId), Uuid::fromHexToBytes($languageId)],
        );
        $overrides = json_decode(self::string($slotConfig), true);
        self::assertIsArray($overrides);
        self::assertSame(
            ['products' => ['source' => 'product_stream', 'value' => $streamId]],
            $overrides[Uuid::fromStringToHex('swkweb-netzp-migration:layout:default:' . $productSliderSlotId)] ?? null,
        );

        $frenchContext = new Context(new SystemSource(), [], Defaults::CURRENCY, [$languageId, Defaults::LANGUAGE_SYSTEM]);
        $article = self::service(DefinitionInstanceRegistry::class)->getRepository('swkweb_content_manager_article')
            ->search(new Criteria([$postId]), $frenchContext)->getEntities()->first()
        ;
        self::assertInstanceOf(Entity::class, $article);
        self::assertSame('Board deals', $article->getTranslation('title'));
    }

    public function testPublishesPostsThatNetzpBlog6AlreadyShowsDespiteAPostDateInTheFuture(): void
    {
        $nextYear = new \DateTimeImmutable('+1 year');
        $shownPostId = $this->insertPost('Shown early', ['postdate' => $nextYear->format('Y-m-d')], ['contents' => '<p>Text</p>']);
        $scheduledPostId = $this->insertPost('Scheduled', [
            'postdate' => $nextYear->format('Y-m-d'),
            'showfrom' => (new \DateTimeImmutable('+1 month'))->format('Y-m-d'),
        ], ['contents' => '<p>Text</p>']);

        $plan = self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog'])));
        self::service(PlanExecutor::class)->execute($plan);

        $shownDate = $this->getArticle($shownPostId)->get('publicationDate');
        self::assertInstanceOf(\DateTimeInterface::class, $shownDate);
        self::assertLessThanOrEqual(time(), $shownDate->getTimestamp());

        $scheduledDate = $this->getArticle($scheduledPostId)->get('publicationDate');
        self::assertInstanceOf(\DateTimeInterface::class, $scheduledDate);
        self::assertSame($nextYear->format('Y-m-d'), $scheduledDate->format('Y-m-d'));

        self::assertSame(
            ['Shown early'],
            $plan->report->getWarnings()['Posts with a post date in the future that NetzpBlog6 already shows are published and dated at the time of the migration'] ?? null,
        );
    }

    public function testKeepsLanguagesInheritingTheLayoutSettingsOfTheDefaultLanguage(): void
    {
        $imageSlotId = Uuid::randomHex();
        $layoutId = $this->createLayout('Post layout', 'landingpage', [[
            'position' => 0,
            'type' => 'netzp-blog6-detail-block',
            'sectionPosition' => 'main',
            'slots' => [[
                'id' => $imageSlotId,
                'type' => 'netzp-blog6-detail',
                'slot' => 'content',
                'config' => [
                    'part' => ['source' => 'static', 'value' => 'image'],
                    'imageMode' => ['source' => 'static', 'value' => 'fullcontain'],
                    'height' => ['source' => 'static', 'value' => '20'],
                ],
            ]],
        ]]);
        self::service(SystemConfigService::class)->set('NetzpBlog6.config.cmspage', $layoutId);

        $languageId = $this->createEntity('language', [
            'name' => 'Deutsch (Schweiz)',
            'localeId' => $this->getLocaleId('de-CH'),
            'translationCodeId' => $this->getLocaleId('de-CH'),
        ]);
        $this->connection->insert('cms_slot_translation', [
            'cms_slot_id' => Uuid::fromHexToBytes($imageSlotId),
            'cms_slot_version_id' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            'language_id' => Uuid::fromHexToBytes($languageId),
            'config' => null,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);
        $this->insertPost('Post', ['postdate' => '2024-01-10'], ['contents' => '<p>Text</p>']);

        $plan = self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog'])));
        self::service(PlanExecutor::class)->execute($plan);

        $configs = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(language_id)), config FROM cms_slot_translation WHERE cms_slot_id = ?',
            [Uuid::fromHexToBytes(Uuid::fromStringToHex('swkweb-netzp-migration:layout:default:' . $imageSlotId))],
        );
        self::assertArrayHasKey($languageId, $configs);
        self::assertNull($configs[$languageId]);

        $defaultConfig = json_decode(self::string($configs[Defaults::LANGUAGE_SYSTEM] ?? null), true);
        self::assertIsArray($defaultConfig);
        self::assertSame(['source' => 'static', 'value' => 'contain'], $defaultConfig['displayMode'] ?? null);
        self::assertSame(['source' => 'static', 'value' => '20rem'], $defaultConfig['minHeight'] ?? null);
        self::assertArrayNotHasKey('Blog post layouts whose detail elements show different parts per language use the part of the default language', $plan->report->getWarnings());
    }

    public function testMigratesAuthorsAndCategoriesWithoutTranslations(): void
    {
        $blogCategoryId = $this->createEntity('category', ['name' => 'Blog']);
        $authorId = Uuid::randomHex();
        $this->connection->insert('s_plugin_netzp_blog_author', ['id' => Uuid::fromHexToBytes($authorId), 'created_at' => '2024-01-01 00:00:00.000']);
        $netzpCategoryId = Uuid::randomHex();
        $this->connection->insert('s_plugin_netzp_blog_category', ['id' => Uuid::fromHexToBytes($netzpCategoryId), 'created_at' => '2024-01-01 00:00:00.000']);
        $postId = $this->insertPost('Untranslated post', [
            'postdate' => '2024-01-10',
            'authorid' => Uuid::fromHexToBytes($authorId),
            'categoryid' => Uuid::fromHexToBytes($netzpCategoryId),
        ], ['contents' => '<p>Text</p>']);

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($blogCategoryId)));

        $author = self::service(DefinitionInstanceRegistry::class)->getRepository('swkweb_content_manager_author')
            ->search(new Criteria([$authorId]), $this->context)->getEntities()->first()
        ;
        self::assertInstanceOf(Entity::class, $author);
        self::assertSame('Unknown author', $author->get('name'));
        self::assertSame($authorId, $this->getArticle($postId)->get('authorId'));
        self::assertSame([$blogCategoryId], $this->getAssignedCategoryIds($postId));
    }

    public function testOnlyOverridesTheGalleryOfThePostInAReusedLayout(): void
    {
        $postGallerySlotId = Uuid::randomHex();
        $staticGallerySlotId = Uuid::randomHex();
        $layoutId = $this->createLayout('Post layout', 'landingpage', [
            [
                'position' => 0,
                'type' => 'netzp-blog6-detail-block',
                'sectionPosition' => 'main',
                'slots' => [[
                    'id' => $postGallerySlotId,
                    'type' => 'netzp-blog6-detail',
                    'slot' => 'content',
                    'config' => ['part' => ['source' => 'static', 'value' => 'media']],
                ]],
            ],
            [
                'position' => 1,
                'type' => 'image-gallery',
                'sectionPosition' => 'main',
                'slots' => [[
                    'id' => $staticGallerySlotId,
                    'type' => 'image-gallery',
                    'slot' => 'imageGallery',
                    'config' => ['sliderItems' => ['source' => 'static', 'value' => []]],
                ]],
            ],
        ]);
        self::service(SystemConfigService::class)->set('NetzpBlog6.config.cmspage', $layoutId);

        $options = new MigrationOptions($this->createEntity('category', ['name' => 'Blog']));
        $this->insertGalleryPost('First gallery');
        $firstPlan = self::service(NetzpBlogMigrator::class)->plan($options);
        self::service(PlanExecutor::class)->execute($firstPlan);
        self::assertSame(1, $firstPlan->report->getCounts()['Article layouts created'] ?? null);

        $secondPostId = $this->insertGalleryPost('Second gallery');
        $secondPlan = self::service(NetzpBlogMigrator::class)->plan($options);
        self::service(PlanExecutor::class)->execute($secondPlan);
        self::assertSame(0, $secondPlan->report->getCounts()['Article layouts created'] ?? null);

        $slotConfig = $this->connection->fetchOne(
            'SELECT slot_config FROM swkweb_content_manager_article_translation WHERE swkweb_content_manager_article_id = ? AND language_id = ?',
            [Uuid::fromHexToBytes($secondPostId), Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM)],
        );
        $overrides = json_decode(self::string($slotConfig), true);
        self::assertIsArray($overrides);
        self::assertSame([Uuid::fromStringToHex('swkweb-netzp-migration:layout:gallery:' . $postGallerySlotId)], array_keys($overrides));
        self::assertNotFalse($this->connection->fetchOne(
            'SELECT 1 FROM cms_slot WHERE id = ?',
            [Uuid::fromHexToBytes(Uuid::fromStringToHex('swkweb-netzp-migration:layout:gallery:' . $staticGallerySlotId))],
        ));
    }

    public function testMovesTheCustomFieldSetsSoThatCleaningUpUnusedMediaKeepsWorking(): void
    {
        $setId = $this->createEntity('custom_field_set', [
            'name' => 'netzp_extras',
            'relations' => [['entityName' => 's_plugin_netzp_blog']],
            'customFields' => [['name' => 'netzp_extras_banner', 'type' => 'media', 'config' => ['customFieldType' => 'media']]],
        ]);
        $this->createEntity('media', []);
        $this->insertPost('Post', ['postdate' => '2024-01-10'], ['contents' => '<p>Text</p>']);

        $failure = null;

        try {
            $this->getUnusedMediaIds();
        } catch (\Throwable $exception) {
            $failure = $exception;
        }
        self::assertNotNull($failure);
        self::assertStringContainsString('s_plugin_netzp_blog', $failure->getMessage());

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog']))));

        self::assertSame(['swkweb_content_manager_article'], $this->connection->fetchFirstColumn(
            'SELECT entity_name FROM custom_field_set_relation WHERE set_id = ?',
            [Uuid::fromHexToBytes($setId)],
        ));
        $this->getUnusedMediaIds();
    }

    public function testLeavesOutPostImagesThatAreVideos(): void
    {
        $videoId = $this->createEntity('media', ['mimeType' => 'video/mp4', 'fileExtension' => 'mp4', 'fileName' => 'teaser']);
        $previewImageId = $this->createEntity('media', ['mimeType' => 'image/jpeg', 'fileExtension' => 'jpg', 'fileName' => 'preview']);
        $videoPostId = $this->insertPost('Video post', ['postdate' => '2024-01-10', 'imageid' => Uuid::fromHexToBytes($videoId)], ['contents' => '<p>Text</p>']);
        $previewPostId = $this->insertPost('Video post with preview', [
            'postdate' => '2024-01-10',
            'imageid' => Uuid::fromHexToBytes($videoId),
            'imagepreviewid' => Uuid::fromHexToBytes($previewImageId),
        ], ['contents' => '<p>Text</p>']);

        $plan = self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog'])));
        self::service(PlanExecutor::class)->execute($plan);

        self::assertNull($this->getArticle($videoPostId)->get('mediaId'));
        self::assertSame($previewImageId, $this->getArticle($previewPostId)->get('mediaId'));

        $videoPosts = $plan->report->getWarnings()['Articles only show images: post images that are videos or other files are not migrated, set an image on these articles'] ?? [];
        sort($videoPosts);
        self::assertSame(['Video post', 'Video post with preview'], $videoPosts);
    }

    public function testKeepsTheItemImagesFromBeingCleanedUpAsUnusedMedia(): void
    {
        [$detailLayoutId] = $this->createDetailLayout();
        self::service(SystemConfigService::class)->set('NetzpBlog6.config.cmspage', $detailLayoutId);

        $imageId = $this->createEntity('media', ['path' => 'media/netzp-item.jpg', 'fileName' => 'netzp-item', 'fileExtension' => 'jpg', 'mimeType' => 'image/jpeg']);
        $postId = $this->insertPost('Post with items', ['postdate' => '2024-01-10'], ['contents' => '<p>Text</p>']);
        $this->insertItem($postId, 'Board setup', '<p>Hang it at 173 cm.</p>', $imageId);

        self::assertContains($imageId, $this->getUnusedMediaIds());

        self::service(PlanExecutor::class)->execute(self::service(NetzpBlogMigrator::class)->plan(new MigrationOptions($this->createEntity('category', ['name' => 'Blog']))));

        $article = $this->getArticle($postId);
        self::assertStringContainsString('netzp-item.jpg', self::string($article->get('content')));
        self::assertSame([$imageId], $this->getCustomFields($article)[CustomFields::ITEM_MEDIA] ?? null);
        self::assertNotContains($imageId, $this->getUnusedMediaIds());
    }

    public function testRejectsInvalidIds(): void
    {
        $migrator = self::service(NetzpBlogMigrator::class);

        try {
            $migrator->plan(new MigrationOptions('blog'));
            self::fail('An invalid category id is accepted');
        } catch (MigrationException $exception) {
            self::assertSame('Invalid category id: blog', $exception->getMessage());
        }

        $this->expectExceptionObject(new MigrationException('Invalid sales channel ids: storefront'));
        $migrator->plan(new MigrationOptions(null, ['storefront']));
    }

    public function testPlansToDeactivateAnActiveNetzpBlog6(): void
    {
        $migrator = self::service(NetzpBlogMigrator::class);
        $pluginRepository = self::service(DefinitionInstanceRegistry::class)->getRepository('plugin');

        // A shop requiring NetzpBlog6 via composer already has it in the plugin table.
        $pluginId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM plugin WHERE name = :name', ['name' => 'NetzpBlog6']);
        if (!is_string($pluginId)) {
            $pluginId = $this->createEntity('plugin', [
                'name' => 'NetzpBlog6',
                'baseClass' => 'NetzpBlog6\NetzpBlog6',
                'composerName' => 'netzp/netzpblog6',
                'autoload' => ['psr-4' => ['NetzpBlog6\\' => 'src/']],
                'license' => 'proprietary',
                'version' => '4.0.12',
                'label' => 'Blog',
            ]);
        }

        $pluginRepository->update([['id' => $pluginId, 'active' => false]], $this->context);
        self::assertFalse($migrator->plan(new MigrationOptions())->deactivateNetzpBlog);

        $pluginRepository->update([['id' => $pluginId, 'active' => true, 'installedAt' => new \DateTimeImmutable()]], $this->context);

        $plan = $migrator->plan(new MigrationOptions());
        self::assertTrue($plan->deactivateNetzpBlog);
        self::assertArrayHasKey(
            'NetzpBlog6 is deactivated after the migration: while it is active, saving a post or rebuilding the indexes takes the SEO URLs of the articles back to the posts',
            $plan->report->getNotices(),
        );
    }

    /**
     * @param array<string, mixed> $columns
     * @param array<string, mixed> $translation
     */
    private function insertPost(string $title, array $columns, array $translation): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('s_plugin_netzp_blog', [
            'id' => Uuid::fromHexToBytes($id),
            'created_at' => '2024-01-01 00:00:00.000',
            ...$columns,
        ]);
        $this->connection->insert('s_plugin_netzp_blog_translation', [
            's_plugin_netzp_blog_id' => Uuid::fromHexToBytes($id),
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'title' => $title,
            'created_at' => '2024-01-01 00:00:00.000',
            ...$translation,
        ]);

        return $id;
    }

    private function insertAuthor(string $name, string $bio): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('s_plugin_netzp_blog_author', ['id' => Uuid::fromHexToBytes($id), 'created_at' => '2024-01-01 00:00:00.000']);
        $this->connection->insert('s_plugin_netzp_blog_author_translation', [
            's_plugin_netzp_blog_author_id' => Uuid::fromHexToBytes($id),
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'name' => $name,
            'bio' => $bio,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insertNetzpCategory(string $title, array $columns = []): string
    {
        $id = Uuid::randomHex();
        $this->connection->insert('s_plugin_netzp_blog_category', ['id' => Uuid::fromHexToBytes($id), 'created_at' => '2024-01-01 00:00:00.000', ...$columns]);
        $this->connection->insert('s_plugin_netzp_blog_category_translation', [
            's_plugin_netzp_blog_category_id' => Uuid::fromHexToBytes($id),
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'title' => $title,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);

        return $id;
    }

    private function insertItem(string $postId, string $title, string $content, ?string $imageId = null): void
    {
        $id = Uuid::randomBytes();
        $this->connection->insert('s_plugin_netzp_blog_item', [
            'id' => $id,
            'blog_id' => Uuid::fromHexToBytes($postId),
            'number' => 1,
            'image_id' => $imageId !== null ? Uuid::fromHexToBytes($imageId) : null,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);
        $this->connection->insert('s_plugin_netzp_blog_item_translation', [
            's_plugin_netzp_blog_item_id' => $id,
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'title' => $title,
            'content' => $content,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);
    }

    private function insertGalleryPost(string $title): string
    {
        $postId = $this->insertPost($title, ['postdate' => '2024-01-10'], ['contents' => '<p>Text</p>']);
        $this->connection->insert('s_plugin_netzp_blog_media', [
            'id' => Uuid::randomBytes(),
            'blog_id' => Uuid::fromHexToBytes($postId),
            'number' => 1,
            'media_id' => Uuid::fromHexToBytes($this->createEntity('media', ['mimeType' => 'image/jpeg', 'fileExtension' => 'jpg', 'fileName' => $title])),
            'created_at' => '2024-01-01 00:00:00.000',
        ]);

        return $postId;
    }

    private function insertTag(string $postId, string $name): void
    {
        $tagId = $this->createEntity('tag', ['name' => $name]);
        $this->connection->insert('s_plugin_netzp_blog_tag', ['blog_id' => Uuid::fromHexToBytes($postId), 'tag_id' => Uuid::fromHexToBytes($tagId)]);
    }

    private function insertSeoUrl(string $postId, string $path): void
    {
        $salesChannelId = $this->connection->fetchOne(
            'SELECT sales_channel_id FROM sales_channel_domain LIMIT 1',
        );

        $this->connection->insert('seo_url', [
            'id' => Uuid::randomBytes(),
            'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
            'sales_channel_id' => $salesChannelId,
            'foreign_key' => Uuid::fromHexToBytes($postId),
            'route_name' => 'frontend.blog.post',
            'path_info' => '/blog/' . $postId,
            'seo_path_info' => $path,
            'is_canonical' => 1,
            'is_modified' => 0,
            'is_deleted' => 0,
            'created_at' => '2024-01-01 00:00:00.000',
        ]);
    }

    /**
     * @return array{string, string} the layout id and the id of its product slot
     */
    private function createDetailLayout(): array
    {
        $productSlotId = Uuid::randomHex();
        $blocks = [];
        foreach (['title', 'contents', 'items', 'social', 'products'] as $position => $part) {
            $blocks[] = [
                'position' => $position,
                'type' => 'netzp-blog6-detail-block',
                'sectionPosition' => 'main',
                'slots' => [[
                    ...($part === 'products' ? ['id' => $productSlotId] : []),
                    'type' => 'netzp-blog6-detail',
                    'slot' => 'content',
                    'config' => ['part' => ['source' => 'static', 'value' => $part]],
                ]],
            ];
        }

        return [$this->createLayout('Post layout', 'landingpage', $blocks), $productSlotId];
    }

    private function createTeaserLayout(): string
    {
        $slotId = Uuid::randomHex();
        $this->createLayout('Home', 'landingpage', [[
            'position' => 0,
            'type' => 'netzp-blog6-block',
            'sectionPosition' => 'main',
            'slots' => [[
                'id' => $slotId,
                'type' => 'netzp-blog6',
                'slot' => 'content',
                'config' => [
                    'noPagination' => ['source' => 'static', 'value' => true],
                    'numberOfPosts' => ['source' => 'static', 'value' => 3],
                ],
            ]],
        ]]);

        return $slotId;
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    private function createLayout(string $name, string $type, array $blocks): string
    {
        return $this->createEntity('cms_page', [
            'name' => $name,
            'type' => $type,
            'sections' => [['position' => 0, 'type' => 'default', 'blocks' => $blocks]],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function createEntity(string $entityName, array $payload): string
    {
        $id = Uuid::randomHex();
        self::service(DefinitionInstanceRegistry::class)->getRepository($entityName)->create([['id' => $id, ...$payload]], $this->context);

        return $id;
    }

    private function getArticle(string $id): Entity
    {
        $article = self::service(DefinitionInstanceRegistry::class)->getRepository('swkweb_content_manager_article')
            ->search(new Criteria([$id]), $this->context)->getEntities()->first()
        ;
        self::assertInstanceOf(Entity::class, $article);

        return $article;
    }

    private function countVisibilities(string $articleId, string $versionId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM swkweb_content_manager_article_visibility WHERE swkweb_content_manager_article_id = ? AND swkweb_content_manager_article_version_id = ?',
            [Uuid::fromHexToBytes($articleId), Uuid::fromHexToBytes($versionId)],
        );
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function getLocaleId(string $code): string
    {
        return self::string($this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM locale WHERE code = ?', [$code]));
    }

    /**
     * @return list<string>
     */
    private function getAssignedCategoryIds(string $articleId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(category_id)) AS id FROM swkweb_content_manager_article_category WHERE swkweb_content_manager_article_id = ? ORDER BY id',
            [Uuid::fromHexToBytes($articleId)],
        );
    }

    /**
     * @return list<string>
     */
    private function getMainCategoryIds(string $articleId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(category_id)) FROM swkweb_content_manager_article_main_category WHERE swkweb_content_manager_article_id = ?',
            [Uuid::fromHexToBytes($articleId)],
        );
    }

    /**
     * @return list<string>
     */
    private function getUnusedMediaIds(): array
    {
        $mediaIds = [];
        foreach (self::service(UnusedMediaPurger::class)->getNotUsedMedia(500) as $batch) {
            foreach ($batch as $media) {
                $mediaIds[] = $media->getId();
            }
        }

        return $mediaIds;
    }

    /**
     * @return array<string, mixed>
     */
    private function getCustomFields(Entity $entity): array
    {
        $customFields = $entity->get('customFields');
        self::assertIsArray($customFields);

        /** @var array<string, mixed> */
        return $customFields;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private static function service(string $class): object
    {
        $service = self::getContainer()->get($class);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @return array<string, string>
     */
    private static function stringify(mixed $row): array
    {
        self::assertIsArray($row);

        return array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $row);
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    /**
     * @return list<string>
     */
    private function getSlotTypes(string $pageId): array
    {
        /** @var list<string> */
        return $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT slot.type FROM cms_slot slot
                JOIN cms_block block ON block.id = slot.cms_block_id AND block.version_id = slot.cms_block_version_id
                JOIN cms_section section ON section.id = block.cms_section_id AND section.version_id = block.cms_section_version_id
                WHERE section.cms_page_id = ?
                ORDER BY slot.type
                SQL,
            [Uuid::fromHexToBytes($pageId)],
        );
    }
}
