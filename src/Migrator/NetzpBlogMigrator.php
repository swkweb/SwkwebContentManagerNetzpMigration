<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\ConversionContext;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\LayoutMirror;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\MirroredLayout;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\SlotConverter;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpBlogData;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCategory;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCmsSlot;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpPost;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpPostTranslation;
use Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader;

/**
 * Plans the migration of the NetzpBlog6 data into the Content Manager.
 */
class NetzpBlogMigrator
{
    public const PINNED_SORTING_KEY = 'netzp-pinned';

    private const CUSTOM_FIELD_SET_ENTITIES = [
        NetzpBlogReader::POST_TABLE => ContentManager::ARTICLE,
        's_plugin_netzp_blog_author' => ContentManager::AUTHOR,
        's_plugin_netzp_blog_category' => 'category',
    ];

    public function __construct(
        private readonly NetzpBlogReader $reader,
        private readonly ShopwareLookup $lookup,
        private readonly LayoutMirror $layoutMirror,
        private readonly SlotConverter $slotConverter,
        private readonly bool $htmlSanitizerEnabled,
    ) {}

    public function plan(MigrationOptions $options): MigrationPlan
    {
        if (!$this->lookup->isContentManagerReady()) {
            throw new MigrationException('The migration requires the Content Manager plugin in version 4.4.0 or newer to be installed and active.');
        }

        if (!$this->reader->isInstalled()) {
            throw new MigrationException('No NetzpBlog6 tables found, there is nothing to migrate.');
        }

        $data = $this->reader->read();
        $plan = new MigrationPlan(new MigrationReport());

        $salesChannelIds = $this->getSalesChannelIds($options);
        $parentCategoryId = $this->getParentCategoryId($options, $data);

        $posts = $data->posts;
        $migratedPostIds = $this->lookup->filterExisting(ContentManager::ARTICLE, array_keys($posts), true);
        if (!$options->overwrite) {
            $posts = array_diff_key($posts, array_flip($migratedPostIds));

            if ($migratedPostIds !== []) {
                $plan->report->count('Posts already migrated, skipped (use --overwrite to migrate them again)', count($migratedPostIds));
            }
        }

        $this->planAuthors($data, $plan, $options);
        $categoryMap = $this->planCategories($data, $plan, $parentCategoryId);
        $context = new ConversionContext(
            $categoryMap,
            $this->planSorting($data, $plan),
            $data->categories !== [],
            array_filter($data->posts, static fn (NetzpPost $post) => $post->tagNames !== []) !== [],
        );
        $this->planArticles($data, $posts, $plan, $salesChannelIds, $parentCategoryId, $categoryMap, $context, $options->overwrite);
        if ($options->overwrite) {
            $plan->replacedArticleIds = array_values(array_intersect($migratedPostIds, array_filter(array_column($plan->articles, 'id'), 'is_string')));
        }
        $this->planListingConversions($data, $plan, $context);
        $this->planCustomFieldSets($plan);
        $this->planSettings($data, $plan->report);
        $this->planMediaFolder($plan);
        $this->planDeactivation($plan);

        return $plan;
    }

    /**
     * @return list<string>
     */
    private function getSalesChannelIds(MigrationOptions $options): array
    {
        if ($options->salesChannelIds === []) {
            return $this->lookup->getStorefrontSalesChannelIds();
        }

        $salesChannelIds = array_map('strtolower', $options->salesChannelIds);
        $invalid = array_filter($salesChannelIds, static fn (string $id): bool => !Uuid::isValid($id));
        if ($invalid !== []) {
            throw new MigrationException(sprintf('Invalid sales channel ids: %s', implode(', ', $invalid)));
        }

        $unknown = array_diff($salesChannelIds, $this->lookup->filterExisting('sales_channel', $salesChannelIds));
        if ($unknown !== []) {
            throw new MigrationException(sprintf('Unknown sales channel ids: %s', implode(', ', $unknown)));
        }

        return $salesChannelIds;
    }

    private function getParentCategoryId(MigrationOptions $options, NetzpBlogData $data): ?string
    {
        $categoryId = $options->categoryId !== null ? strtolower($options->categoryId) : null;
        if ($categoryId !== null) {
            if (!Uuid::isValid($categoryId)) {
                throw new MigrationException(sprintf('Invalid category id: %s', $categoryId));
            }

            if ($this->lookup->filterExisting('category', [$categoryId], true) === []) {
                throw new MigrationException(sprintf('Unknown category id: %s', $categoryId));
            }

            return $categoryId;
        }

        $configured = $data->getConfig('navigationcategory');
        if (is_string($configured) && Uuid::isValid($configured) && $this->lookup->filterExisting('category', [strtolower($configured)], true) !== []) {
            return strtolower($configured);
        }

        return null;
    }

    private function planAuthors(NetzpBlogData $data, MigrationPlan $plan, MigrationOptions $options): void
    {
        $existing = $options->overwrite ? [] : $this->lookup->filterExisting(ContentManager::AUTHOR, array_keys($data->authors));
        $mediaIds = $this->lookup->filterExisting('media', array_values(array_filter(array_map(
            static fn ($author) => $author->imageId,
            $data->authors,
        ))));

        foreach ($data->authors as $author) {
            if (in_array($author->id, $existing, true)) {
                continue;
            }

            $translations = $this->withDefaultLanguage($author->translations)
                ?: [Defaults::LANGUAGE_SYSTEM => ['name' => null, 'bio' => null, 'link' => null, 'customFields' => []]];
            $defaultName = $translations[Defaults::LANGUAGE_SYSTEM]['name'] ?? null;
            if ($defaultName === null) {
                $plan->report->warn('Authors without a name are migrated as "Unknown author"', $author->id);
            }

            $payload = ['id' => $author->id, 'mediaId' => null, 'translations' => []];
            foreach ($translations as $languageId => $translation) {
                $payload['translations'][$languageId] = [
                    'name' => $translation['name'] ?? ($languageId === Defaults::LANGUAGE_SYSTEM ? 'Unknown author' : null),
                    'description' => $translation['bio'],
                    'link' => $translation['link'] ?? ($languageId === Defaults::LANGUAGE_SYSTEM ? $author->link : null),
                    'customFields' => $translation['customFields'] !== [] ? $translation['customFields'] : null,
                ];
            }

            if ($author->imageId !== null) {
                if (in_array($author->imageId, $mediaIds, true)) {
                    $payload['mediaId'] = $author->imageId;
                } else {
                    $plan->report->warn('Author images that no longer exist in the media library are not migrated', $defaultName ?? $author->id);
                }
            }

            $plan->authors[] = $payload;
            $plan->report->count('Authors');
        }
    }

    /**
     * @return array<string, string> Shopware category ids keyed by NetzpBlog6 category id
     */
    private function planCategories(NetzpBlogData $data, MigrationPlan $plan, ?string $parentCategoryId): array
    {
        $usedCategoryIds = [];
        foreach ($data->posts as $post) {
            foreach ($post->getAllCategoryIds() as $categoryId) {
                $usedCategoryIds[$categoryId] = true;
            }
        }

        $navigationCategoryIds = $this->lookup->filterExisting('category', array_values(array_filter(array_map(
            static fn (NetzpCategory $category) => $category->navigationCategoryId,
            $data->categories,
        ))), true);
        $createdCategoryIds = $this->lookup->filterExisting('category', array_map(
            static fn (string $id) => self::deriveId('category', $id),
            array_keys($data->categories),
        ), true);
        $childLayoutId = $parentCategoryId !== null ? $this->getChildCategoryLayoutId($data, $parentCategoryId) : null;

        $categoryMap = [];
        foreach ($data->categories as $category) {
            $translations = $this->withDefaultLanguage($category->translations);
            $title = $translations[Defaults::LANGUAGE_SYSTEM]['title'] ?? $category->id;

            if (!isset($usedCategoryIds[$category->id])) {
                $plan->report->notice('NetzpBlog6 categories without posts are not migrated', $title);

                continue;
            }

            if ($category->includeInRss) {
                $plan->report->warn('The RSS feed of NetzpBlog6 (/blog.rss) has no equivalent, the posts of these categories are no longer published as feed', $title);
            }

            if ($category->navigationCategoryId !== null && in_array($category->navigationCategoryId, $navigationCategoryIds, true)) {
                $categoryMap[$category->id] = $category->navigationCategoryId;
                $plan->report->count('Categories mapped to their navigation category');

                foreach ($translations as $translation) {
                    if ($translation['teaser'] !== null || $translation['customFields'] !== []) {
                        $plan->report->warn('The teaser and custom fields of categories mapped to an existing navigation category are not copied', $title);
                    }
                }

                continue;
            }

            if ($parentCategoryId === null) {
                $plan->report->warn('Categories cannot be created without a parent category (set one with --category), their posts lose the category', $title);

                continue;
            }

            $categoryId = self::deriveId('category', $category->id);
            if (in_array($categoryId, $createdCategoryIds, true)) {
                $categoryMap[$category->id] = $categoryId;

                continue;
            }

            if (array_filter(array_column($translations, 'title')) === []) {
                $plan->report->warn('NetzpBlog6 categories without a title are not migrated, their posts keep their other categories or are assigned to the blog category', $title);

                continue;
            }

            $categoryMap[$category->id] = $categoryId;

            $payload = [
                'id' => $categoryId,
                'parentId' => $parentCategoryId,
                'active' => true,
                'visible' => false,
                'type' => 'page',
                'cmsPageId' => $childLayoutId,
                'translations' => [],
            ];
            foreach ($translations as $languageId => $translation) {
                $payload['translations'][$languageId] = [
                    'name' => $translation['title'] ?? ($languageId === Defaults::LANGUAGE_SYSTEM ? $title : null),
                    'description' => $translation['teaser'],
                    'customFields' => $translation['customFields'] !== [] ? $translation['customFields'] : null,
                ];
            }

            $plan->categories[] = $payload;
            $plan->report->count('Categories created');
            $plan->report->notice('Categories are created below the blog category and hidden in the navigation, show them in the category settings if needed', $title);
        }

        return $categoryMap;
    }

    private function getChildCategoryLayoutId(NetzpBlogData $data, string $parentCategoryId): ?string
    {
        $parentLayoutId = $this->lookup->getCategoryLayoutId($parentCategoryId);

        foreach ($data->slots as $slot) {
            if ($slot->pageId === $parentLayoutId && $slot->type === NetzpBlogReader::LISTING_ELEMENT && !self::isTeaser($slot)) {
                return $parentLayoutId;
            }
        }

        return $this->lookup->filterExisting('cms_page', [ContentManager::DEFAULT_CATEGORY_LAYOUT_ID], true) !== []
            ? ContentManager::DEFAULT_CATEGORY_LAYOUT_ID
            : null;
    }

    private function planSorting(NetzpBlogData $data, MigrationPlan $plan): ?string
    {
        $stickyPosts = array_filter($data->posts, static fn (NetzpPost $post) => $post->sticky);
        if ($stickyPosts === []) {
            return null;
        }

        $translations = [];
        foreach ($this->lookup->getLanguageIdsByLocale() as $locale => $languageId) {
            $translations[$languageId] = ['label' => str_starts_with($locale, 'de-') ? 'Angeheftete zuerst' : 'Pinned first'];
        }

        $plan->sorting = [
            'id' => self::deriveId('sorting', self::PINNED_SORTING_KEY),
            'key' => self::PINNED_SORTING_KEY,
            'priority' => 5,
            'active' => true,
            'fields' => [
                [
                    'field' => ContentManager::ARTICLE . '.customFields.' . CustomFields::PIN_PRIORITY,
                    'order' => 'DESC',
                    'priority' => 2,
                    'naturalSorting' => false,
                ],
                [
                    'field' => ContentManager::ARTICLE . '.publicationDate',
                    'order' => 'DESC',
                    'priority' => 1,
                    'naturalSorting' => false,
                ],
            ],
            'translations' => $translations,
        ];

        $plan->report->notice(sprintf(
            'Sticky posts are pinned with the custom field "%s", and the article listings and teasers converted from NetzpBlog6 use the sorting "%s" to list them first',
            CustomFields::PIN_PRIORITY,
            self::PINNED_SORTING_KEY,
        ));

        return self::PINNED_SORTING_KEY;
    }

    /**
     * @param array<string, NetzpPost> $posts
     * @param list<string> $salesChannelIds
     * @param array<string, string> $categoryMap
     */
    private function planArticles(
        NetzpBlogData $data,
        array $posts,
        MigrationPlan $plan,
        array $salesChannelIds,
        ?string $parentCategoryId,
        array $categoryMap,
        ConversionContext $context,
        bool $overwrite,
    ): void {
        $report = $plan->report;

        $detailSlotsByPage = [];
        foreach ($data->slots as $slot) {
            if ($slot->type === NetzpBlogReader::DETAIL_ELEMENT) {
                $detailSlotsByPage[$slot->pageId][] = $slot;
            }
        }

        $layoutNames = $this->lookup->getLayoutNames(array_keys($detailSlotsByPage));
        $globalLayoutId = $this->getGlobalLayoutId($data, $detailSlotsByPage, $report);

        $referencedMediaIds = [];
        $itemImageIds = [];
        foreach ($posts as $post) {
            array_push($referencedMediaIds, ...array_filter([$post->imageId, $post->previewImageId]), ...$post->galleryMediaIds);
            foreach ($post->items as $item) {
                if ($item->imageId !== null) {
                    $itemImageIds[] = $item->imageId;
                }
            }
        }

        $existingMediaIds = array_flip($this->lookup->filterExisting('media', $referencedMediaIds));
        $imageIds = array_flip($this->lookup->filterImages($referencedMediaIds));
        $mediaUrls = $this->lookup->getMediaUrls($itemImageIds);
        $existingStreamIds = $this->lookup->filterExisting('product_stream', array_values(array_filter(array_map(
            static fn (NetzpPost $post) => $post->productStreamId,
            $posts,
        ))));
        $existingSalesChannelIds = $this->lookup->filterExisting('sales_channel', array_values(array_filter(array_map(
            static fn (NetzpCategory $category) => $category->salesChannelId,
            $data->categories,
        ))));

        $contentByteLimit = $this->lookup->getColumnByteLimit(ContentManager::ARTICLE . '_translation', 'content');
        $domainHosts = $this->lookup->getDomainHosts();
        $categoryIdsOutsideBlog = $this->getCategoryIdsOutsideBlog($data, $categoryMap, $parentCategoryId);
        $rootLanguageIds = $this->lookup->getRootLanguageIds();

        /** @var array<string, MirroredLayout> $layouts */
        $layouts = [];

        foreach ($posts as $post) {
            $translations = $this->getPostTranslations($post, $report);
            if ($translations === null) {
                continue;
            }

            $title = $translations[Defaults::LANGUAGE_SYSTEM]->title ?? $post->id;

            $category = $post->primaryCategoryId !== null ? ($data->categories[$post->primaryCategoryId] ?? null) : null;

            $sourceLayoutId = $category?->cmsPageId !== null && isset($detailSlotsByPage[$category->cmsPageId]) ? $category->cmsPageId : $globalLayoutId;
            $parts = $sourceLayoutId !== null ? array_map(SlotConverter::getDetailPart(...), $detailSlotsByPage[$sourceLayoutId]) : [];

            if (in_array(SlotConverter::PART_ITEMS, $parts, true) && !in_array('contents', $parts, true)) {
                $report->warn('Layouts showing the post items but not the post text show neither, as the items are appended to the article text', $layoutNames[(string) $sourceLayoutId] ?? (string) $sourceLayoutId);
            }

            $galleryMediaIds = array_values(array_filter($post->galleryMediaIds, static fn (string $id) => isset($existingMediaIds[$id])));
            if (array_diff($galleryMediaIds, array_keys($imageIds)) !== []) {
                $report->warn('Image galleries only show images: documents, audio and video files of post galleries are not migrated', $title);
                $galleryMediaIds = array_values(array_filter($galleryMediaIds, static fn (string $id) => isset($imageIds[$id])));
            }

            $withGallery = $galleryMediaIds !== [] && in_array(SlotConverter::PART_GALLERY, $parts, true);
            if ($galleryMediaIds !== [] && !$withGallery) {
                $report->warn('Image galleries of posts whose layout does not show a gallery are not migrated', $title);
            }

            $layout = null;
            if ($sourceLayoutId !== null) {
                $layoutKey = $sourceLayoutId . ($withGallery ? ':gallery' : '');
                $layout = $layouts[$layoutKey] ??= $this->layoutMirror->mirror(
                    $sourceLayoutId,
                    $layoutNames[$sourceLayoutId] ?? $sourceLayoutId,
                    $withGallery,
                    $this->getSlotsOfPage($data, $sourceLayoutId),
                    $context,
                    $report,
                );
            } else {
                $report->warn('Posts without a NetzpBlog6 layout use the default Content Manager article layout, which shows no products, product streams, galleries or additional content', $title);
            }

            $articleTranslations = [];
            foreach ($translations as $languageId => $translation) {
                $articleTranslations[$languageId] = $this->getArticleTranslation(
                    $post,
                    $languageId,
                    $translation,
                    $translations[Defaults::LANGUAGE_SYSTEM]->contents,
                    $title,
                    in_array(SlotConverter::PART_ITEMS, $parts, true),
                    $overwrite,
                    $mediaUrls,
                    $report,
                );
            }

            if ($contentByteLimit !== null && !$this->fitsContentLimit($articleTranslations, $contentByteLimit)) {
                $report->warn(sprintf(
                    'Posts with a text longer than the Content Manager can store (%d KB) are not migrated, update the Content Manager to 4.4.0 or newer',
                    intdiv($contentByteLimit, 1024),
                ), $title);

                continue;
            }

            $slotConfig = [];

            if ($post->isProductStream && $post->productStreamId !== null) {
                if (!in_array($post->productStreamId, $existingStreamIds, true)) {
                    $report->warn('Product streams that no longer exist are not migrated', $title);
                } elseif ($layout === null || $layout->productSliderSlotIds === []) {
                    $report->warn('Product streams of posts whose layout does not show products are not migrated', $title);
                } else {
                    foreach ($layout->productSliderSlotIds as $slotId) {
                        $slotConfig[$slotId] = [
                            'products' => ['source' => 'product_stream', 'value' => $post->productStreamId],
                        ];
                    }
                }
            }

            if ($withGallery) {
                foreach ($layout->gallerySlotIds as $slotId) {
                    $slotConfig[$slotId] = [
                        'sliderItems' => ['source' => 'static', 'value' => array_map(static fn (string $id) => ['mediaId' => $id], $galleryMediaIds)],
                    ];
                }
            }

            // Layout overrides are only inherited from parent languages, not from the default language, so every root
            // language gets its own copy, also without a post text in it.
            $overrideLanguageIds = array_keys($articleTranslations);
            if ($slotConfig !== [] || $overwrite) {
                $overrideLanguageIds = array_unique([...$overrideLanguageIds, ...$rootLanguageIds]);
            }

            foreach ($overrideLanguageIds as $languageId) {
                $articleTranslations[$languageId]['slotConfig'] = $slotConfig !== [] ? $slotConfig : null;
            }

            $restricted = $this->isRestricted($category, $title, $report);
            $expired = $this->isPastShowUntil($post, $title, $report);
            $categoryIds = $this->getCategoryIds($post, $categoryMap, $parentCategoryId, $categoryIdsOutsideBlog, $title, $report);
            $visibilityIds = $this->getVisibilities($category, $salesChannelIds, $existingSalesChannelIds, $title, $report);

            $plan->articles[] = [
                'id' => $post->id,
                'active' => !$restricted && !$expired,
                'searchEnabled' => true,
                'noindex' => $post->noindex,
                'publicationDate' => $this->getPublicationDate($post, $title, $report),
                'mediaId' => $this->getMediaId($post, $existingMediaIds, $imageIds, $title, $report),
                'authorId' => $this->getAuthorId($data, $post, $title, $report),
                'cmsPageId' => $layout?->pageId,
                ...($post->createdAt !== null ? ['createdAt' => $post->createdAt->format(Defaults::STORAGE_DATE_TIME_FORMAT)] : []),
                'translations' => $articleTranslations,
                'categories' => array_map(static fn (string $id) => ['id' => $id], $categoryIds),
                'mainCategories' => self::getMainCategories($post, $category, $categoryMap, $categoryIds, $parentCategoryId, $visibilityIds),
                'visibilities' => array_map(static fn (string $id) => ['id' => $id], $visibilityIds),
                'products' => array_map(static fn (string $id) => ['id' => $id], $post->productIds),
                'inverseProducts' => array_map(static fn (string $id) => ['id' => $id], $post->productIds),
            ];

            if ($post->tagNames !== []) {
                $report->warn('Tags have no equivalent and are not migrated', sprintf('%s (%s)', $title, implode(', ', $post->tagNames)));
            }

            if (isset($data->seoPaths[$post->id])) {
                $plan->seoUrlPostIds[] = $post->id;
                $report->count('SEO URLs kept', count($data->seoPaths[$post->id]));
            }

            $this->checkCanonicalUrl($data, $post, $domainHosts, $title, $report);
            $report->count('Posts');
            $report->count('Product links', count($post->productIds));
        }

        $plan->layouts = array_values($layouts);
        $plan->report->count('Article layouts created', count(array_filter($layouts, static fn (MirroredLayout $layout) => $layout->rows !== [])));
    }

    /**
     * @param array<string, list<NetzpCmsSlot>> $detailSlotsByPage
     */
    private function getGlobalLayoutId(NetzpBlogData $data, array $detailSlotsByPage, MigrationReport $report): ?string
    {
        $layoutId = $data->getConfig('cmspage');
        $layoutId = is_string($layoutId) && Uuid::isValid($layoutId) ? strtolower($layoutId) : null;

        if ($layoutId !== null && !isset($detailSlotsByPage[$layoutId])) {
            $report->warn('The blog post layout configured in NetzpBlog6 contains no blog post elements and is not migrated');
            $layoutId = null;
        }

        foreach ($data->getSalesChannelConfig('cmspage') as $salesChannelId => $salesChannelLayoutId) {
            if (is_string($salesChannelLayoutId) && strtolower($salesChannelLayoutId) !== $layoutId) {
                $report->warn('Content Manager articles have one layout in all sales channels: the blog post layouts configured for single sales channels are not migrated', $salesChannelId);
            }
        }

        return $layoutId;
    }

    /**
     * @return array<string, NetzpCmsSlot>
     */
    private function getSlotsOfPage(NetzpBlogData $data, string $pageId): array
    {
        $slots = [];
        foreach ($data->slots as $slot) {
            if ($slot->pageId === $pageId) {
                $slots[$slot->id] = $slot;
            }
        }

        return $slots;
    }

    /**
     * @return array<string, NetzpPostTranslation>|null
     */
    private function getPostTranslations(NetzpPost $post, MigrationReport $report): ?array
    {
        if ($post->translations === []) {
            $report->warn('Posts without any text are not migrated', $post->id);

            return null;
        }

        if (!isset($post->translations[Defaults::LANGUAGE_SYSTEM])) {
            $first = $post->translations[array_key_first($post->translations)];
            $report->warn('Posts without a text in the default language use the text of another language as default', $first->title ?? $post->id);

            return [Defaults::LANGUAGE_SYSTEM => $first] + $post->translations;
        }

        return $post->translations;
    }

    /**
     * @param array<string, string> $mediaUrls
     *
     * @return array<string, mixed>
     */
    private function getArticleTranslation(
        NetzpPost $post,
        string $languageId,
        NetzpPostTranslation $translation,
        ?string $defaultContents,
        string $title,
        bool $layoutShowsItems,
        bool $overwrite,
        array $mediaUrls,
        MigrationReport $report,
    ): array {
        $isDefaultLanguage = $languageId === Defaults::LANGUAGE_SYSTEM;
        $content = $translation->contents;

        $customFields = $translation->customFields;

        $items = $this->renderItems($post, $languageId, $mediaUrls);
        if ($items !== '') {
            if ($layoutShowsItems) {
                $itemMediaIds = self::getItemMediaIds($post, $mediaUrls);
                if ($isDefaultLanguage && $itemMediaIds !== []) {
                    $customFields[CustomFields::ITEM_MEDIA] = $itemMediaIds;
                }

                // A text of its own stops a translation from falling back to the text of the default language.
                $content = ($content ?? ($isDefaultLanguage ? '' : $defaultContents ?? '')) . $items;
                $report->notice('The items of these posts are appended to their content', $title);
            } else {
                $report->notice('Items of posts whose layout does not show items are not migrated', $title);
            }
        }

        foreach ([$translation->contents, $translation->teaser, $translation->custom] as $text) {
            if ($text !== null && preg_match('/\{\{|\{%/', $text) === 1) {
                $report->warn('Twig code in the post texts is not rendered by the Content Manager and shows as plain text', $title);
            }

            if ($text !== null && preg_match('/src=["\']data:image\//i', $text) === 1) {
                $report->warn('Images embedded into the post text as data URI make the pages slow to load, upload them to the media library instead', $title);
            }

            if ($text !== null && preg_match('#/blog/[0-9a-f]{32}#i', $text) === 1) {
                $report->warn('Links to the technical post URLs (/blog/<id>) stop working, link the SEO URLs instead', $title);
            }

            if ($this->htmlSanitizerEnabled && $text !== null && preg_match('/<(script|iframe|style|object|embed|form)\b/i', $text) === 1) {
                $report->warn('The HTML sanitizer is enabled: scripts, iframes, styles and forms in the post texts are removed when the articles are saved', $title);
            }
        }

        if ($translation->custom !== null) {
            $customFields[CustomFields::CUSTOM_HTML] = $translation->custom;
        }

        if ($translation->slug !== null) {
            $customFields[CustomFields::SLUG] = $translation->slug;
        }

        if ($isDefaultLanguage && $post->sticky) {
            $customFields[CustomFields::PIN_PRIORITY] = 1;
        }

        if ($isDefaultLanguage && $post->canonicalUrl !== null) {
            $customFields[CustomFields::CANONICAL_URL] = $post->canonicalUrl;
        }

        // Custom fields are merged on update, so values removed in NetzpBlog6 are cleared explicitly.
        if ($overwrite) {
            $customFields += [CustomFields::CUSTOM_HTML => null, CustomFields::SLUG => null];
            if ($isDefaultLanguage) {
                $customFields += [CustomFields::PIN_PRIORITY => null, CustomFields::CANONICAL_URL => null, CustomFields::ITEM_MEDIA => null];
            }
        }

        $articleTitle = $translation->title;
        if ($isDefaultLanguage && $articleTitle === null) {
            $articleTitle = $translation->slug ?? $post->id;
            $report->warn('Posts without a title use their slug as title', $articleTitle);
        }

        return [
            'title' => $articleTitle,
            'content' => $content,
            'description' => $translation->teaser,
            'metaTitle' => $translation->metaTitle,
            'metaDescription' => $translation->metaDescription,
            'customFields' => $customFields !== [] ? $customFields : null,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $articleTranslations
     */
    private function fitsContentLimit(array $articleTranslations, int $byteLimit): bool
    {
        foreach ($articleTranslations as $translation) {
            $content = $translation['content'] ?? null;
            if (is_string($content) && strlen($content) > $byteLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, string> $mediaUrls
     */
    private function renderItems(NetzpPost $post, string $languageId, array $mediaUrls): string
    {
        $html = '';
        foreach ($post->items as $item) {
            $translation = $item->translations[$languageId] ?? $item->translations[Defaults::LANGUAGE_SYSTEM] ?? ['title' => null, 'content' => null];
            $imageUrl = $item->imageId !== null ? ($mediaUrls[$item->imageId] ?? null) : null;

            if ($translation['title'] === null && $translation['content'] === null && $imageUrl === null) {
                continue;
            }

            $html .= '<div class="netzp-blog-item">';
            if ($translation['title'] !== null) {
                $html .= '<h4>' . htmlspecialchars($translation['title']) . '</h4>';
            }

            $html .= $translation['content'] ?? '';
            if ($imageUrl !== null) {
                $html .= '<img src="' . htmlspecialchars($imageUrl) . '" alt="" class="img-fluid">';
            }

            $html .= '</div>';
        }

        return $html !== '' ? '<div class="netzp-blog-items">' . $html . '</div>' : '';
    }

    /**
     * @param array<string, string> $mediaUrls
     *
     * @return list<string> the media ids of the item images rendered into the article text
     */
    private static function getItemMediaIds(NetzpPost $post, array $mediaUrls): array
    {
        $mediaIds = [];
        foreach ($post->items as $item) {
            if ($item->imageId !== null && isset($mediaUrls[$item->imageId])) {
                $mediaIds[] = $item->imageId;
            }
        }

        return array_values(array_unique($mediaIds));
    }

    /**
     * Content Manager articles have one date for display and scheduling, while NetzpBlog6 displays the post date
     * and publishes from the "show from" date. The later one keeps posts unpublished as long as before, except for
     * posts that NetzpBlog6 already shows despite a post date in the future.
     */
    private function getPublicationDate(NetzpPost $post, string $title, MigrationReport $report): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $postDate = $post->postDate ?? $post->createdAt ?? $now;

        if ($postDate > $now && ($post->showFrom === null || $post->showFrom <= $now)) {
            $report->warn('Posts with a post date in the future that NetzpBlog6 already shows are published and dated at the time of the migration', $title);

            return $now->format(Defaults::STORAGE_DATE_TIME_FORMAT);
        }

        $publicationDate = $postDate;

        if ($post->showFrom !== null && $post->showFrom->getTimestamp() !== $postDate->getTimestamp()) {
            $publicationDate = max($postDate, $post->showFrom);
            $report->warn('Posts whose "show from" date differs from the post date are published and dated at the later of both dates', $title);
        }

        return $publicationDate->format(Defaults::STORAGE_DATE_TIME_FORMAT);
    }

    /**
     * Content Manager articles have no publication end date, so only posts that NetzpBlog6 already hides are
     * migrated inactive.
     */
    private function isPastShowUntil(NetzpPost $post, string $title, MigrationReport $report): bool
    {
        if ($post->showUntil === null) {
            return false;
        }

        // NetzpBlog6 shows posts until the end of the "show until" day.
        if ($post->showUntil->modify('+1 day') <= new \DateTimeImmutable()) {
            $report->warn('Articles have no publication end date: posts past their "show until" date are migrated inactive', $title);

            return true;
        }

        $report->warn('Articles have no publication end date: posts with a "show until" date in the future stay published after it, deactivate them manually', $title);

        return false;
    }

    /**
     * NetzpBlog6 shows videos as post image, while articles show their image with image elements and in image boxes.
     *
     * @param array<string, int> $existingMediaIds
     * @param array<string, int> $imageIds
     */
    private function getMediaId(NetzpPost $post, array $existingMediaIds, array $imageIds, string $title, MigrationReport $report): ?string
    {
        foreach ([$post->imageId, $post->previewImageId] as $mediaId) {
            if ($mediaId === null) {
                continue;
            }

            if (!isset($existingMediaIds[$mediaId])) {
                $report->warn('Post images that no longer exist in the media library are not migrated', $title);
            } elseif (!isset($imageIds[$mediaId])) {
                $report->warn('Articles only show images: post images that are videos or other files are not migrated, set an image on these articles', $title);
            }
        }

        $imageId = $post->imageId !== null && isset($imageIds[$post->imageId]) ? $post->imageId : null;
        $previewImageId = $post->previewImageId !== null && isset($imageIds[$post->previewImageId]) ? $post->previewImageId : null;

        if ($imageId !== null && $previewImageId !== null && $imageId !== $previewImageId) {
            $report->warn('Articles have one image: preview images that differ from the post image are not migrated', $title);
        }

        return $imageId ?? $previewImageId;
    }

    private function getAuthorId(NetzpBlogData $data, NetzpPost $post, string $title, MigrationReport $report): ?string
    {
        if ($post->authorId === null) {
            return null;
        }

        if (!isset($data->authors[$post->authorId])) {
            $report->warn('Authors that no longer exist are not assigned', $title);

            return null;
        }

        return $post->authorId;
    }

    /**
     * @param array<string, string> $categoryMap
     *
     * @return array<string, true> the existing navigation categories the NetzpBlog6 categories are mapped to, which are
     *                             not the blog category or below it
     */
    private function getCategoryIdsOutsideBlog(NetzpBlogData $data, array $categoryMap, ?string $parentCategoryId): array
    {
        $navigationCategoryIds = [];
        foreach ($categoryMap as $netzpCategoryId => $categoryId) {
            if ($categoryId === ($data->categories[$netzpCategoryId]->navigationCategoryId ?? null)) {
                $navigationCategoryIds[] = $categoryId;
            }
        }

        $inBlog = $parentCategoryId !== null ? $this->lookup->filterCategoriesInTree($parentCategoryId, $navigationCategoryIds) : [];

        return array_fill_keys(array_diff($navigationCategoryIds, $inBlog), true);
    }

    /**
     * Unfiltered NetzpBlog6 listings show all posts, while article listings only show the articles of their category
     * and the categories below it.
     *
     * @param array<string, string> $categoryMap
     * @param array<string, true> $categoryIdsOutsideBlog
     *
     * @return list<string>
     */
    private function getCategoryIds(NetzpPost $post, array $categoryMap, ?string $parentCategoryId, array $categoryIdsOutsideBlog, string $title, MigrationReport $report): array
    {
        $categoryIds = [];
        foreach ($post->getAllCategoryIds() as $netzpCategoryId) {
            if (isset($categoryMap[$netzpCategoryId])) {
                $categoryIds[] = $categoryMap[$netzpCategoryId];
            }
        }

        if ($categoryIds === []) {
            if ($parentCategoryId === null) {
                $report->warn('Posts without a category are not listed anywhere, as there is no blog category to assign them to (set one with --category)', $title);

                return [];
            }

            $report->count('Posts without category, assigned to the blog category');

            return [$parentCategoryId];
        }

        $categoryIds = array_values(array_unique($categoryIds));
        if (array_intersect_key(array_flip($categoryIds), $categoryIdsOutsideBlog) === []) {
            return $categoryIds;
        }

        if ($parentCategoryId === null) {
            $report->warn('Article listings only show the articles of their own category: posts of categories mapped to a navigation category are only listed there, as there is no blog category to add them to (set one with --category)', $title);

            return $categoryIds;
        }

        $report->notice('Article listings only show the articles of their own category: posts of categories mapped to a navigation category outside the blog category are also assigned to the blog category', $title);

        return [...$categoryIds, $parentCategoryId];
    }

    /**
     * NetzpBlog6 shows the navigation category of the primary category in the breadcrumb, or the blog category, while
     * articles in several categories show any of them unless a main category is set.
     *
     * @param array<string, string> $categoryMap
     * @param list<string> $categoryIds
     * @param list<string> $salesChannelIds
     *
     * @return list<array{id: string, salesChannelId: string, categoryId: string}>
     */
    private static function getMainCategories(NetzpPost $post, ?NetzpCategory $category, array $categoryMap, array $categoryIds, ?string $parentCategoryId, array $salesChannelIds): array
    {
        if (count($categoryIds) < 2) {
            return [];
        }

        $mainCategoryId = $category !== null && $category->navigationCategoryId !== null && ($categoryMap[$category->id] ?? null) === $category->navigationCategoryId
            ? $category->navigationCategoryId
            : $parentCategoryId;

        if ($mainCategoryId === null || !in_array($mainCategoryId, $categoryIds, true)) {
            return [];
        }

        return array_map(static fn (string $salesChannelId): array => [
            'id' => self::deriveId('main-category', $post->id . ':' . $salesChannelId),
            'salesChannelId' => $salesChannelId,
            'categoryId' => $mainCategoryId,
        ], $salesChannelIds);
    }

    /**
     * NetzpBlog6 restricts posts by the settings of their primary category only.
     */
    private function isRestricted(?NetzpCategory $category, string $title, MigrationReport $report): bool
    {
        if ($category === null || !$category->isRestrictedToCustomers()) {
            return false;
        }

        $report->warn('Articles cannot be restricted to customer groups or logged-in customers: posts of restricted categories are migrated inactive, so they do not become public', $title);

        return true;
    }

    /**
     * @param list<string> $salesChannelIds
     * @param list<string> $existingSalesChannelIds
     *
     * @return list<string>
     */
    private function getVisibilities(?NetzpCategory $category, array $salesChannelIds, array $existingSalesChannelIds, string $title, MigrationReport $report): array
    {
        if ($category?->salesChannelId === null) {
            return $salesChannelIds;
        }

        if (!in_array($category->salesChannelId, $existingSalesChannelIds, true)) {
            $report->warn('Posts of categories limited to a sales channel that no longer exists were not visible and are not visible in any sales channel', $title);

            return [];
        }

        if (!in_array($category->salesChannelId, $salesChannelIds, true)) {
            $report->warn('Posts of categories limited to a sales channel that is not migrated are not visible in any sales channel', $title);

            return [];
        }

        return [$category->salesChannelId];
    }

    /**
     * @param list<string> $domainHosts
     */
    private function checkCanonicalUrl(NetzpBlogData $data, NetzpPost $post, array $domainHosts, string $title, MigrationReport $report): void
    {
        if ($post->canonicalUrl === null) {
            return;
        }

        $canonicalHost = parse_url($post->canonicalUrl, \PHP_URL_HOST);
        $canonicalPath = trim((string) parse_url($post->canonicalUrl, \PHP_URL_PATH), '/');
        $isOwnHost = !is_string($canonicalHost) || in_array(strtolower($canonicalHost), $domainHosts, true);

        foreach ($data->seoPaths[$post->id] ?? [] as $seoPath) {
            if ($isOwnHost && $seoPath['canonical'] && trim($seoPath['path'], '/') === $canonicalPath) {
                return;
            }
        }

        $report->warn(sprintf(
            'Articles always use their own URL as canonical URL: canonical URLs pointing elsewhere are only kept in the custom field "%s"',
            CustomFields::CANONICAL_URL,
        ), $title);
    }

    private function planListingConversions(NetzpBlogData $data, MigrationPlan $plan, ConversionContext $context): void
    {
        $postLayoutIds = [...array_map(static fn (MirroredLayout $layout) => $layout->sourcePageId, $plan->layouts), ...$this->getConfiguredLayoutIds($data)];
        $pageIds = array_values(array_unique([...array_map(static fn (NetzpCmsSlot $slot) => $slot->pageId, $data->slots), ...$data->draftPageIds]));
        $pageNames = $this->lookup->getLayoutNames($pageIds);

        foreach ($data->draftPageIds as $pageId) {
            $plan->report->notice('Drafts and older versions of these layouts still contain NetzpBlog6 elements, discard them instead of restoring them', $pageNames[$pageId] ?? $pageId);
        }

        foreach ($data->slots as $slot) {
            $pageName = $pageNames[$slot->pageId] ?? $slot->pageId;

            // Post layouts stay as they are, the articles use converted copies of them.
            if (in_array($slot->pageId, $postLayoutIds, true)) {
                continue;
            }

            if ($slot->type === NetzpBlogReader::DETAIL_ELEMENT) {
                $plan->report->warn('Blog post elements outside of the blog post layouts have no post to show and are not converted', $pageName);

                continue;
            }

            $converted = $this->slotConverter->convertListing($slot, $context, $pageName, $plan->report);

            $plan->slotUpdates[] = [
                'id' => $slot->id,
                'type' => $converted->type,
                'slot' => $converted->slot,
                'translations' => array_map(static fn (array $config) => ['config' => $config], $converted->configs),
            ];

            if ($converted->blockType !== null) {
                $plan->blockUpdates[] = ['id' => $slot->blockId, 'type' => $converted->blockType];
            }

            $plan->report->count('Blog listings and teasers converted');
            $plan->report->notice('Blog listings and teasers are converted in place', $pageName);
        }

        $convertedSlotIds = array_values(array_filter(array_map(static fn (array $update) => $update['id'] ?? null, $plan->slotUpdates), 'is_string'));
        foreach ($this->lookup->findSlotConfigOverrides($convertedSlotIds) as $name) {
            $plan->report->warn('Categories, landing pages and products overriding the settings of converted blog listings keep the NetzpBlog6 settings, which are ignored: set them again in the layout settings of these entities', $name);
        }
    }

    /**
     * @return list<string>
     */
    private function getConfiguredLayoutIds(NetzpBlogData $data): array
    {
        $layoutIds = array_map(static fn (NetzpCategory $category) => $category->cmsPageId, $data->categories);
        foreach ([$data->getConfig('cmspage'), ...array_values($data->getSalesChannelConfig('cmspage'))] as $layoutId) {
            $layoutIds[] = is_string($layoutId) ? strtolower($layoutId) : null;
        }

        return array_values(array_filter($layoutIds, static fn (?string $id) => $id !== null && $id !== ''));
    }

    private function planCustomFieldSets(MigrationPlan $plan): void
    {
        foreach ($plan->articles as $article) {
            foreach (is_array($article['translations'] ?? null) ? $article['translations'] : [] as $translation) {
                $customFields = is_array($translation) && is_array($translation['customFields'] ?? null) ? $translation['customFields'] : [];
                if (array_intersect_key($customFields, array_flip([CustomFields::PIN_PRIORITY, CustomFields::CUSTOM_HTML, CustomFields::SLUG, CustomFields::CANONICAL_URL, CustomFields::ITEM_MEDIA])) !== []) {
                    $plan->customFieldSet = CustomFields::getSetPayload();
                    $plan->report->notice(sprintf(
                        'The custom field set "%s" keeps the slug, canonical URL, additional content and sticky flag of the posts, and the item images so that cleaning up unused media keeps them',
                        CustomFields::SET_NAME,
                    ));

                    break 2;
                }
            }
        }

        $targetEntities = array_values(self::CUSTOM_FIELD_SET_ENTITIES);
        $existing = [];
        foreach ($this->lookup->getCustomFieldSetRelations($targetEntities) as $relation) {
            $existing[$relation['setId'] . ':' . $relation['entityName']] = true;
        }

        foreach ($this->lookup->getCustomFieldSetRelations(array_keys(self::CUSTOM_FIELD_SET_ENTITIES)) as $relation) {
            $entityName = self::CUSTOM_FIELD_SET_ENTITIES[$relation['entityName']] ?? null;
            if ($entityName === null) {
                continue;
            }

            // Cleaning up unused media fails on media custom fields assigned to an entity that no longer exists,
            // as the ones of NetzpBlog6 once it is deactivated, so the assignments are moved instead of copied.
            $plan->removedCustomFieldSetRelationIds[] = $relation['id'];
            $plan->report->notice('Custom field sets of NetzpBlog6 posts, authors and categories are moved to articles, authors and categories', $relation['setId']);

            if (isset($existing[$relation['setId'] . ':' . $entityName])) {
                continue;
            }

            $plan->customFieldSetRelations[] = [
                'id' => self::deriveId('custom-field-set-relation', $relation['setId'] . ':' . $entityName),
                'customFieldSetId' => $relation['setId'],
                'entityName' => $entityName,
            ];
        }
    }

    private function planSettings(NetzpBlogData $data, MigrationReport $report): void
    {
        if ($data->posts === []) {
            return;
        }

        foreach (['excludesitemap' => true, 'showblogposts' => true] as $key => $enabled) {
            $values = [$data->getConfig($key), ...array_values($data->getSalesChannelConfig($key))];
            if (!in_array($enabled, $values, true)) {
                continue;
            }

            $report->warn(match ($key) {
                'excludesitemap' => 'Content Manager articles are always listed in the sitemap, set "noindex" on articles to exclude them',
                default => 'Blog posts on product pages are not shown automatically: add the Content Manager element "Product Article Slider" to the product page layouts',
            });
        }

        $report->notice('Content Manager articles are found by the storefront search, disable "Display in search results" on articles to exclude them');
        $report->notice('The SEO URLs of the posts are kept, new articles get URLs from the Content Manager SEO URL template');
        $report->notice('Headless frontends need to switch from the Store API routes /store-api/blogpost and /store-api/bloglisting to the Content Manager routes');
    }

    private function planMediaFolder(MigrationPlan $plan): void
    {
        $folderId = $this->lookup->getMediaFolderIdOfDefaultFolder(NetzpBlogReader::MEDIA_DEFAULT_FOLDER_ID);
        if ($folderId === null) {
            return;
        }

        $plan->detachedMediaFolderId = $folderId;
        $plan->report->notice('The NetzpBlog6 media folder is detached from NetzpBlog6, so uninstalling NetzpBlog6 does not delete it');
    }

    private function planDeactivation(MigrationPlan $plan): void
    {
        if (!$this->lookup->isPluginActive(NetzpBlogReader::PLUGIN_NAME)) {
            return;
        }

        $plan->deactivateNetzpBlog = true;
        $plan->report->notice('NetzpBlog6 is deactivated after the migration: while it is active, saving a post or rebuilding the indexes takes the SEO URLs of the articles back to the posts');
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param array<string, T> $translations
     *
     * @return array<string, T>
     */
    private function withDefaultLanguage(array $translations): array
    {
        if ($translations === [] || isset($translations[Defaults::LANGUAGE_SYSTEM])) {
            return $translations;
        }

        return [Defaults::LANGUAGE_SYSTEM => $translations[array_key_first($translations)]] + $translations;
    }

    private static function isTeaser(NetzpCmsSlot $slot): bool
    {
        $config = $slot->configs[Defaults::LANGUAGE_SYSTEM] ?? $slot->configs[array_key_first($slot->configs)] ?? [];
        $noPagination = $config['noPagination'] ?? null;

        return is_array($noPagination) && (bool) ($noPagination['value'] ?? false);
    }

    private static function deriveId(string $kind, string $sourceId): string
    {
        return Uuid::fromStringToHex(sprintf('swkweb-netzp-migration:%s:%s', $kind, $sourceId));
    }
}
