<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Tests\Unit\Migrator\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Uuid\Uuid;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\ConversionContext;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\ConvertedSlot;
use Swkweb\ContentManagerNetzpMigration\Migrator\Cms\SlotConverter;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;
use Swkweb\ContentManagerNetzpMigration\Netzp\Model\NetzpCmsSlot;

/**
 * @internal
 */
#[CoversClass(SlotConverter::class)]
final class SlotConverterTest extends TestCase
{
    private SlotConverter $converter;

    private MigrationReport $report;

    protected function setUp(): void
    {
        $this->converter = new SlotConverter();
        $this->report = new MigrationReport();
    }

    public function testConvertsTheTitleOfANetzpBlockIntoTheArticleTitleBlock(): void
    {
        $converted = $this->convertDetail(['part' => 'title', 'titleTag' => 'h1']);

        self::assertNotNull($converted);
        self::assertSame('swkweb-content-manager-article-detail-title', $converted->type);
        self::assertSame('swkweb-content-manager-article-detail-title', $converted->blockType);
        self::assertSame('content', $converted->slot);
        self::assertSame(
            ['source' => 'mapped', 'value' => 'swkweb_content_manager_article.title'],
            $converted->configs[Defaults::LANGUAGE_SYSTEM]['content'],
        );
    }

    public function testKeepsThemeBlocksAndTheirSlotNames(): void
    {
        $converted = $this->convertDetail(['part' => 'contents'], 'zen-grid-2-8-2', 'col2');

        self::assertNotNull($converted);
        self::assertSame('swkweb-content-manager-article-detail-content', $converted->type);
        self::assertNull($converted->blockType);
        self::assertSame('col2', $converted->slot);
    }

    public function testLeavesOutSocialButtonsWithAWarning(): void
    {
        self::assertNull($this->convertDetail(['part' => 'social']));
        self::assertArrayHasKey(
            'The social sharing buttons of NetzpShariff6 have no equivalent and are left out of the article layouts',
            $this->report->getWarnings(),
        );
    }

    public function testLeavesOutItemsAsTheyAreAppendedToTheContent(): void
    {
        self::assertNull($this->convertDetail(['part' => 'items']));
        self::assertSame([], $this->report->getWarnings());
    }

    public function testConvertsTheGalleryOnlyForLayoutsWithGallery(): void
    {
        self::assertNull($this->convertDetail(['part' => 'media']));

        $converted = $this->convertDetail(['part' => 'media'], withGallery: true);
        self::assertNotNull($converted);
        self::assertSame('image-gallery', $converted->type);
        self::assertSame('imageGallery', $converted->slot);
        self::assertSame(['source' => 'static', 'value' => []], $converted->configs[Defaults::LANGUAGE_SYSTEM]['sliderItems']);
    }

    public function testConvertsProductsIntoASliderOfTheArticleProducts(): void
    {
        $converted = $this->convertDetail([
            'part' => 'products',
            'productLayout' => 'minimal',
            'productDisplayMode' => 'cover',
            'productMinWidth' => '250',
        ]);

        self::assertNotNull($converted);
        self::assertSame('product-slider', $converted->type);
        self::assertSame('product-slider', $converted->blockType);
        self::assertSame('productSlider', $converted->slot);

        $config = $converted->configs[Defaults::LANGUAGE_SYSTEM];
        self::assertSame(['source' => 'mapped', 'value' => 'article.products'], $config['products']);
        self::assertSame(['source' => 'static', 'value' => 'minimal'], $config['boxLayout']);
        self::assertSame(['source' => 'static', 'value' => 'cover'], $config['displayMode']);
        self::assertSame(['source' => 'static', 'value' => '250px'], $config['elMinWidth']);
    }

    #[DataProvider('provideConvertsTheImageModesCases')]
    public function testConvertsTheImageModes(string $imageMode, string $displayMode, string $minHeight): void
    {
        $converted = $this->convertDetail(['part' => 'image', 'imageMode' => $imageMode, 'height' => '20']);

        self::assertNotNull($converted);
        self::assertSame('image', $converted->type);
        self::assertSame('image', $converted->slot);

        $config = $converted->configs[Defaults::LANGUAGE_SYSTEM];
        self::assertSame(['source' => 'mapped', 'value' => 'swkweb_content_manager_article.media'], $config['media']);
        self::assertSame(['source' => 'static', 'value' => $displayMode], $config['displayMode']);
        self::assertSame(['source' => 'static', 'value' => $minHeight], $config['minHeight']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideConvertsTheImageModesCases(): iterable
    {
        yield 'full' => ['full', 'cover', '20rem'];
        yield 'full contain' => ['fullcontain', 'contain', '20rem'];
        yield 'responsive' => ['responsive', 'standard', ''];
        yield 'small' => ['small', 'standard', ''];
    }

    public function testWarnsAboutHiddenCategoriesAndTagsOnlyIfThereAreAny(): void
    {
        $config = ['part' => 'meta', 'showDate' => true, 'showAuthor' => false, 'showCategory' => true, 'showTags' => true];

        $converted = $this->convertDetail($config);
        self::assertNotNull($converted);
        self::assertSame(['source' => 'static', 'value' => false], $converted->configs[Defaults::LANGUAGE_SYSTEM]['showAuthor']);
        self::assertSame([], $this->report->getWarnings());

        $this->convertDetail($config, context: new ConversionContext([], null, true, false));
        self::assertArrayHasKey('The article date & author element does not show categories and tags', $this->report->getWarnings());
    }

    public function testConvertsTheDetailConfigOfEveryLanguage(): void
    {
        $languageId = Uuid::randomHex();
        $slot = $this->createSlot('netzp-blog6-detail', [
            Defaults::LANGUAGE_SYSTEM => self::config(['part' => 'products', 'productLayout' => 'standard']),
            $languageId => self::config(['part' => 'products', 'productLayout' => 'image']),
        ]);

        $converted = $this->converter->convertDetail($slot, false, self::context(), 'Layout', $this->report);

        self::assertNotNull($converted);
        self::assertSame(['source' => 'static', 'value' => 'image'], $converted->configs[$languageId]['boxLayout']);
    }

    public function testConvertsUnpaginatedListingsIntoTeasers(): void
    {
        $netzpCategoryId = Uuid::randomHex();
        $categoryId = Uuid::randomHex();
        $languageId = Uuid::randomHex();

        $slot = $this->createSlot('netzp-blog6', [
            Defaults::LANGUAGE_SYSTEM => self::config(['noPagination' => true, 'layout' => 'slider', 'numberOfPosts' => 10, 'category' => $netzpCategoryId]),
            $languageId => self::config(['noPagination' => true]),
        ], 'netzp-blog6-block');

        $converted = $this->converter->convertListing($slot, new ConversionContext([$netzpCategoryId => $categoryId], 'netzp-pinned', true, false), 'Home', $this->report);

        self::assertSame('swkweb-content-manager-article-teaser', $converted->type);
        self::assertSame('swkweb-content-manager-article-teaser', $converted->blockType);
        self::assertSame([Defaults::LANGUAGE_SYSTEM, $languageId], array_keys($converted->configs));

        $config = $converted->configs[$languageId];
        self::assertSame(['source' => 'static', 'value' => [$categoryId]], $config['categories']);
        self::assertSame(['source' => 'static', 'value' => 10], $config['limit']);
        self::assertSame(['source' => 'static', 'value' => 'netzp-pinned'], $config['sorting']);
        self::assertSame(['source' => 'static', 'value' => 'slider'], $config['displayMode']);
    }

    public function testLimitsTeasersShowingAllPostsWithAWarning(): void
    {
        $slot = $this->createSlot('netzp-blog6', [Defaults::LANGUAGE_SYSTEM => self::config(['noPagination' => true, 'numberOfPosts' => 0])]);

        $converted = $this->converter->convertListing($slot, self::context(), 'Home', $this->report);

        self::assertSame(['source' => 'static', 'value' => 24], $converted->configs[Defaults::LANGUAGE_SYSTEM]['limit']);
        self::assertSame(['Home'], $this->report->getWarnings()['Blog teasers showing all posts show the 24 newest articles']);
    }

    public function testWarnsAboutTeasersShowingASinglePostOnlyIfLimitedToOnePost(): void
    {
        $postId = Uuid::randomHex();
        $warning = 'Blog teasers showing a single post show the newest articles instead';

        $this->converter->convertListing($this->createSlot('netzp-blog6', [
            Defaults::LANGUAGE_SYSTEM => self::config(['noPagination' => true, 'numberOfPosts' => 3, 'blogPost' => $postId]),
        ]), self::context(), 'Home', $this->report);
        self::assertArrayNotHasKey($warning, $this->report->getWarnings());

        $this->converter->convertListing($this->createSlot('netzp-blog6', [
            Defaults::LANGUAGE_SYSTEM => self::config(['noPagination' => true, 'numberOfPosts' => 1, 'blogPost' => $postId]),
        ]), self::context(), 'Landing page', $this->report);
        self::assertSame(['Landing page'], $this->report->getWarnings()[$warning] ?? null);
    }

    public function testConvertsPaginatedListingsIntoTheArticleListing(): void
    {
        $slot = $this->createSlot('netzp-blog6', [Defaults::LANGUAGE_SYSTEM => self::config(['layout' => 'list', 'sortOrder' => 'asc', 'filterTags' => true])]);

        $converted = $this->converter->convertListing($slot, new ConversionContext([], 'netzp-pinned', false, false), 'Blog', $this->report);

        self::assertSame('swkweb-content-manager-article-listing', $converted->type);
        self::assertNull($converted->blockType);

        $config = $converted->configs[Defaults::LANGUAGE_SYSTEM];
        self::assertSame(['source' => 'static', 'value' => 'date-asc'], $config['defaultSorting']);
        self::assertSame(['source' => 'static', 'value' => 'box-list'], $config['articleListLayout']);

        $warnings = $this->report->getWarnings();
        self::assertArrayHasKey('Blog listings sorted by ascending date no longer show sticky posts first', $warnings);
        self::assertArrayHasKey('The category, tag and author filters of blog listings have no equivalent', $warnings);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function convertDetail(
        array $values,
        string $blockType = 'netzp-blog6-detail-block',
        string $slotName = 'content',
        bool $withGallery = false,
        ?ConversionContext $context = null,
    ): ?ConvertedSlot {
        $slot = $this->createSlot('netzp-blog6-detail', [Defaults::LANGUAGE_SYSTEM => self::config($values)], $blockType, $slotName);

        return $this->converter->convertDetail($slot, $withGallery, $context ?? self::context(), 'Layout', $this->report);
    }

    /**
     * @param array<string, array<string, mixed>> $configs
     */
    private function createSlot(string $type, array $configs, string $blockType = 'zen-grid', string $slotName = 'content'): NetzpCmsSlot
    {
        return new NetzpCmsSlot(Uuid::randomHex(), $type, $slotName, Uuid::randomHex(), $blockType, Uuid::randomHex(), $configs);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function config(array $values): array
    {
        return array_map(static fn (mixed $value) => ['source' => 'static', 'value' => $value], $values);
    }

    private static function context(): ConversionContext
    {
        return new ConversionContext([], null, false, false);
    }
}
