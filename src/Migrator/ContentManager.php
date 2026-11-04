<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

/**
 * Names and ids of the Content Manager, referenced by value so this plugin does not depend on its classes.
 */
final class ContentManager
{
    public const ARTICLE = 'swkweb_content_manager_article';
    public const AUTHOR = 'swkweb_content_manager_author';
    public const ARTICLE_SORTING = 'swkweb_content_manager_article_sorting';
    public const ARTICLE_ROUTE = 'frontend.swkweb.content-manager.article.page';
    public const ARTICLE_DETAIL_PAGE_TYPE = 'swkweb_content_manager_article_detail';
    public const DEFAULT_CATEGORY_LAYOUT_ID = 'c71e56d1caf345d09714ea6916fd6de4';
    public const LISTING_ELEMENT = 'swkweb-content-manager-article-listing';
    public const TEASER_ELEMENT = 'swkweb-content-manager-article-teaser';

    private function __construct() {}
}
