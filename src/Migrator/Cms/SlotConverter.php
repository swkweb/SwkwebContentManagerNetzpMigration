<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator\Cms;

use Shopware\Core\Defaults;
use Swkweb\ContentManagerNetzpMigration\Migrator\ContentManager;
use Swkweb\ContentManagerNetzpMigration\Migrator\CustomFields;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCmsSlot;
use Swkweb\ContentManagerNetzpMigration\Netzp\Row;

/**
 * Converts the NetzpBlog6 CMS elements into Content Manager and Shopware elements.
 */
class SlotConverter
{
    public const PART_ITEMS = 'items';
    public const PART_GALLERY = 'media';
    public const PART_PRODUCTS = 'products';

    private const NETZP_BLOCKS = ['netzp-blog6-block', 'netzp-blog6-detail-block'];

    /**
     * Element type, block type and slot name of the Netzp detail parts with an equivalent.
     */
    private const DETAIL_TARGETS = [
        'title' => ['swkweb-content-manager-article-detail-title', 'swkweb-content-manager-article-detail-title', 'content'],
        'contents' => ['swkweb-content-manager-article-detail-content', 'swkweb-content-manager-article-detail-content', 'content'],
        'teaser' => ['text', 'text', 'content'],
        'custom' => ['text', 'text', 'content'],
        'meta' => ['swkweb-content-manager-article-detail-byline', 'swkweb-content-manager-article-detail-byline', 'content'],
        'author' => ['swkweb-content-manager-article-detail-author', 'swkweb-content-manager-article-detail-author', 'content'],
        'image' => ['image', 'image', 'image'],
        self::PART_PRODUCTS => ['product-slider', 'product-slider', 'productSlider'],
        self::PART_GALLERY => ['image-gallery', 'image-gallery', 'imageGallery'],
    ];

    public static function getDetailPart(NetzpCmsSlot $slot): string
    {
        $value = self::value(self::getDefaultConfig($slot), 'part', 'title');

        return is_string($value) ? $value : 'title';
    }

    public static function isNetzpBlock(string $blockType): bool
    {
        return in_array($blockType, self::NETZP_BLOCKS, true);
    }

    /**
     * @return ConvertedSlot|null null for parts without an equivalent in the Content Manager
     */
    public function convertDetail(NetzpCmsSlot $slot, bool $withGallery, ConversionContext $context, string $pageName, MigrationReport $report): ?ConvertedSlot
    {
        $part = self::getDetailPart($slot);

        foreach ($slot->configs as $config) {
            if (self::value($config, 'part', 'title') !== $part) {
                $report->warn('Blog post layouts whose detail elements show different parts per language use the part of the default language', $pageName);
            }
        }

        if ($part === 'social') {
            $report->warn('The social sharing buttons of NetzpShariff6 have no equivalent and are left out of the article layouts', $pageName);

            return null;
        }

        if ($part === self::PART_ITEMS) {
            return null;
        }

        if ($part === self::PART_GALLERY && !$withGallery) {
            return null;
        }

        if (!isset(self::DETAIL_TARGETS[$part])) {
            $report->warn(sprintf('Detail elements showing "%s" have no equivalent and are left out of the article layouts', $part), $pageName);

            return null;
        }

        [$type, $blockType, $slotName] = self::DETAIL_TARGETS[$part];

        $configs = [];
        foreach ($this->getConfigs($slot) as $languageId => $config) {
            $configs[$languageId] = $this->getDetailConfig($part, $config, $context, $pageName, $report);
        }

        return $this->createSlot($slot, $type, $blockType, $slotName, $configs);
    }

    public function convertListing(NetzpCmsSlot $slot, ConversionContext $context, string $pageName, MigrationReport $report): ConvertedSlot
    {
        $config = self::getDefaultConfig($slot);
        $isTeaser = (bool) self::value($config, 'noPagination', false);

        foreach ($slot->configs as $languageConfig) {
            if ($languageConfig !== $config) {
                $report->warn('Blog listings with different settings per language use the settings of the default language in all languages', $pageName);
            }
        }
        $layout = self::value($config, 'layout', 'cards');

        if ($layout === 'tpl') {
            $report->warn('Blog listings with a custom Twig template show the regular article boxes', $pageName);
        }

        if (self::id($config, 'author') !== null) {
            $report->warn('Blog listings limited to one author show the articles of all authors', $pageName);
        }

        if (self::value($config, 'tags', null) !== null && self::value($config, 'tags', null) !== []) {
            $report->warn('Blog listings limited to tags show articles regardless of tags, as tags are not migrated', $pageName);
        }

        $sorting = $context->stickySortingKey;
        if (self::value($config, 'sortOrder', 'desc') === 'asc') {
            $sorting = 'date-asc';

            if ($context->stickySortingKey !== null) {
                $report->warn('Blog listings sorted by ascending date no longer show sticky posts first', $pageName);
            }
        }

        $boxStyle = $layout === 'list' ? 'box-list' : 'box-standard';

        if ($isTeaser) {
            return $this->createSlot(
                $slot,
                ContentManager::TEASER_ELEMENT,
                ContentManager::TEASER_ELEMENT,
                'content',
                $this->forAllLanguages($slot, $this->getTeaserConfig($config, $context, $sorting, $boxStyle, $pageName, $report)),
            );
        }

        if (self::id($config, 'category') !== null) {
            $report->warn('Paginated blog listings limited to a category show the articles of the category the page belongs to', $pageName);
        }

        if ((bool) self::value($config, 'filterCategory', false) || (bool) self::value($config, 'filterTags', false) || (bool) self::value($config, 'filterAuthor', false)) {
            $report->warn('The category, tag and author filters of blog listings have no equivalent', $pageName);
        }

        $report->notice('Paginated article listings show as many articles per page as configured in the Content Manager settings ("Articles per page")', $pageName);

        return $this->createSlot($slot, ContentManager::LISTING_ELEMENT, ContentManager::LISTING_ELEMENT, 'content', $this->forAllLanguages($slot, [
            'defaultSorting' => self::static($sorting),
            'alphabetFilter' => self::static(false),
            'articleListLayout' => self::static($boxStyle),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyTextConfig(): array
    {
        return ['content' => self::static(''), 'verticalAlign' => self::static(null)];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function getDetailConfig(string $part, array $config, ConversionContext $context, string $pageName, MigrationReport $report): array
    {
        switch ($part) {
            case 'title':
                return ['content' => self::mapped(ContentManager::ARTICLE . '.title'), 'verticalAlign' => self::static(null)];

            case 'contents':
                return ['content' => self::mapped(ContentManager::ARTICLE . '.content'), 'verticalAlign' => self::static(null)];

            case 'teaser':
                return ['content' => self::mapped(ContentManager::ARTICLE . '.description'), 'verticalAlign' => self::static(null)];

            case 'custom':
                return [
                    'content' => self::mapped(ContentManager::ARTICLE . '.translated.customFields.' . CustomFields::CUSTOM_HTML),
                    'verticalAlign' => self::static(null),
                ];

            case 'meta':
                if (($context->hasCategories && (bool) self::value($config, 'showCategory', false)) || ($context->hasTags && (bool) self::value($config, 'showTags', false))) {
                    $report->warn('The article date & author element does not show categories and tags', $pageName);
                }

                return [
                    'showPublicationDate' => self::static((bool) self::value($config, 'showDate', true)),
                    'showAuthor' => self::static((bool) self::value($config, 'showAuthor', true)),
                    'dateFormat' => self::static('long'),
                ];

            case 'author':
                if (!(bool) self::value($config, 'showAuthorAvatar', true) || !(bool) self::value($config, 'showAuthorBio', true)) {
                    $report->warn('The article author element always shows the author\'s image and description', $pageName);
                }

                return [];

            case 'image':
                return $this->getImageConfig($config, $pageName, $report);

            case self::PART_PRODUCTS:
                $minWidth = self::value($config, 'productMinWidth', '300');

                return [
                    'products' => self::mapped('article.products'),
                    'title' => self::static(''),
                    'displayMode' => self::static(self::value($config, 'productDisplayMode', 'standard')),
                    'boxLayout' => self::static(self::value($config, 'productLayout', 'standard')),
                    'navigationArrows' => self::static('outside'),
                    'rotate' => self::static(false),
                    'autoplayTimeout' => self::static(5000),
                    'speed' => self::static(300),
                    'border' => self::static(false),
                    'elMinWidth' => self::static((is_numeric($minWidth) ? (int) $minWidth : 300) . 'px'),
                    'verticalAlign' => self::static(null),
                    'productStreamSorting' => self::static('name:ASC'),
                    'productStreamLimit' => self::static(25),
                ];

            case self::PART_GALLERY:
                return [
                    'sliderItems' => self::static([]),
                    'navigationArrows' => self::static('inside'),
                    'navigationDots' => self::static('none'),
                    'galleryPosition' => self::static('left'),
                    'displayMode' => self::static('standard'),
                    'minHeight' => self::static('340px'),
                    'verticalAlign' => self::static(null),
                    'zoom' => self::static(true),
                    'fullScreen' => self::static(true),
                ];

            default:
                return [];
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function getImageConfig(array $config, string $pageName, MigrationReport $report): array
    {
        if (self::value($config, 'imageType', 'post') === 'preview') {
            $report->notice('Image elements showing the preview image show the article image', $pageName);
        }

        $height = self::value($config, 'height', '12');
        $minHeight = (is_numeric($height) ? (float) $height : 12) . 'rem';

        [$displayMode, $minHeight] = match (self::value($config, 'imageMode', 'full')) {
            'full' => ['cover', $minHeight],
            'fullcontain' => ['contain', $minHeight],
            default => ['standard', ''],
        };

        return [
            'media' => self::mapped(ContentManager::ARTICLE . '.media'),
            'displayMode' => self::static($displayMode),
            'minHeight' => self::static($minHeight),
            'verticalAlign' => self::static(null),
            'horizontalAlign' => self::static('center'),
            'url' => self::static(null),
            'newTab' => self::static(false),
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function getTeaserConfig(array $config, ConversionContext $context, ?string $sorting, string $boxStyle, string $pageName, MigrationReport $report): array
    {
        $categoryIds = [];
        $netzpCategoryId = self::id($config, 'category');
        if ($netzpCategoryId !== null) {
            if (isset($context->categoryMap[$netzpCategoryId])) {
                $categoryIds[] = $context->categoryMap[$netzpCategoryId];
            } else {
                $report->warn('Blog teasers limited to a category that is not migrated show the articles of all categories', $pageName);
            }
        }

        $limit = self::value($config, 'numberOfPosts', 5);
        $limit = is_numeric($limit) ? (int) $limit : 5;

        // NetzpBlog6 only shows the selected post in teasers limited to one post.
        if ($limit === 1 && self::id($config, 'blogPost') !== null) {
            $report->warn('Blog teasers showing a single post show the newest articles instead', $pageName);
        }

        if ($limit < 1) {
            $limit = 24;
            $report->warn('Blog teasers showing all posts show the 24 newest articles', $pageName);
        }

        return [
            'categories' => self::static($categoryIds),
            'limit' => self::static($limit),
            'sorting' => self::static($sorting),
            'displayMode' => self::static(self::value($config, 'layout', 'cards') === 'slider' ? 'slider' : 'grid'),
            'articleListLayout' => self::static($boxStyle),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     */
    private function createSlot(NetzpCmsSlot $slot, string $type, string $blockType, string $slotName, array $configs): ConvertedSlot
    {
        // Theme blocks render their slots by name, so only the plugin's own single-slot blocks are replaced.
        if (!self::isNetzpBlock($slot->blockType)) {
            return new ConvertedSlot($type, $slot->slot, null, $configs);
        }

        return new ConvertedSlot($type, $slotName, $blockType, $configs);
    }

    /**
     * The listing elements of the Content Manager have no translatable settings, so every language gets the same config.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function forAllLanguages(NetzpCmsSlot $slot, array $config): array
    {
        return array_map(static fn (): array => $config, $this->getConfigs($slot));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getConfigs(NetzpCmsSlot $slot): array
    {
        return $slot->configs !== [] ? $slot->configs : [Defaults::LANGUAGE_SYSTEM => []];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getDefaultConfig(NetzpCmsSlot $slot): array
    {
        return $slot->configs[Defaults::LANGUAGE_SYSTEM] ?? $slot->configs[array_key_first($slot->configs)] ?? [];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function value(array $config, string $key, mixed $default): mixed
    {
        $field = $config[$key] ?? null;

        return is_array($field) && array_key_exists('value', $field) ? $field['value'] : $default;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function id(array $config, string $key): ?string
    {
        $value = self::value($config, $key, null);

        return Row::id(['value' => is_string($value) ? $value : null], 'value');
    }

    /**
     * @return array{source: string, value: mixed}
     */
    private static function static(mixed $value): array
    {
        return ['source' => 'static', 'value' => $value];
    }

    /**
     * @return array{source: string, value: string}
     */
    private static function mapped(string $value): array
    {
        return ['source' => 'mapped', 'value' => $value];
    }
}
