<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The custom field set holding the NetzpBlog6 data without an article field of its own.
 */
final class CustomFields
{
    public const SET_NAME = 'swkweb_netzp_blog';

    /**
     * An optional number instead of a flag: an unticked flag is stored as false, which would sort above articles
     * that never had the field, while an emptied number is stored as null.
     */
    public const PIN_PRIORITY = 'swkweb_netzp_blog_pin_priority';
    public const CUSTOM_HTML = 'swkweb_netzp_blog_custom';
    public const SLUG = 'swkweb_netzp_blog_slug';
    public const CANONICAL_URL = 'swkweb_netzp_blog_canonical_url';

    /**
     * The item images are only linked by URL in the article text, which cleaning up unused media does not see, while
     * media selected in a custom field count as used.
     */
    public const ITEM_MEDIA = 'swkweb_netzp_blog_item_media';

    private function __construct() {}

    public static function getSetId(): string
    {
        return Uuid::fromStringToHex('swkweb-netzp-migration:custom-field-set');
    }

    /**
     * @return array<string, mixed>
     */
    public static function getSetPayload(): array
    {
        return [
            'id' => self::getSetId(),
            'name' => self::SET_NAME,
            'config' => [
                'label' => [
                    'en-GB' => 'NetzpBlog6 migration',
                    'de-DE' => 'NetzpBlog6-Migration',
                ],
            ],
            'relations' => [
                [
                    'id' => Uuid::fromStringToHex('swkweb-netzp-migration:custom-field-set-relation'),
                    'entityName' => ContentManager::ARTICLE,
                ],
            ],
            'customFields' => [
                self::field(self::PIN_PRIORITY, 'int', 1, [
                    'type' => 'number',
                    'numberType' => 'int',
                    'componentName' => 'sw-field',
                    'customFieldType' => 'number',
                    'label' => ['en-GB' => 'Pin to the top (priority)', 'de-DE' => 'Oben anheften (Priorität)'],
                    'helpText' => [
                        'en-GB' => 'Pinned articles are listed first, higher numbers first. Leave empty to not pin the article.',
                        'de-DE' => 'Angeheftete Artikel werden zuerst gelistet, höhere Zahlen zuerst. Leer lassen, um den Artikel nicht anzuheften.',
                    ],
                ]),
                self::field(self::CUSTOM_HTML, 'html', 2, [
                    'componentName' => 'sw-text-editor',
                    'customFieldType' => 'textEditor',
                    'label' => ['en-GB' => 'Additional content', 'de-DE' => 'Zusätzlicher Inhalt'],
                ]),
                self::field(self::SLUG, 'text', 3, [
                    'type' => 'text',
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'label' => ['en-GB' => 'NetzpBlog6 slug', 'de-DE' => 'NetzpBlog6-Slug'],
                ]),
                self::field(self::CANONICAL_URL, 'text', 4, [
                    'type' => 'text',
                    'componentName' => 'sw-field',
                    'customFieldType' => 'text',
                    'label' => ['en-GB' => 'NetzpBlog6 canonical URL', 'de-DE' => 'NetzpBlog6-Canonical-URL'],
                    'helpText' => [
                        'en-GB' => 'Only kept for reference, articles always use their own URL as canonical URL.',
                        'de-DE' => 'Nur zur Nachverfolgung, Artikel verwenden immer ihre eigene URL als Canonical-URL.',
                    ],
                ]),
                self::field(self::ITEM_MEDIA, 'select', 5, [
                    'componentName' => 'sw-entity-multi-id-select',
                    'customFieldType' => 'entity',
                    'entity' => 'media',
                    'labelProperty' => 'fileName',
                    'label' => ['en-GB' => 'Images of the NetzpBlog6 additional contents', 'de-DE' => 'Bilder der NetzpBlog6-Zusatzinhalte'],
                    'helpText' => [
                        'en-GB' => 'The images of the additional contents, which are part of the article text. Listing them here keeps them from being deleted as unused media.',
                        'de-DE' => 'Die Bilder der Zusatzinhalte, die Teil des Artikeltexts sind. Die Auflistung hier verhindert, dass sie als unbenutzte Medien gelöscht werden.',
                    ],
                ]),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function field(string $name, string $type, int $position, array $config): array
    {
        return [
            'id' => Uuid::fromStringToHex('swkweb-netzp-migration:custom-field:' . $name),
            'name' => $name,
            'type' => $type,
            'config' => [...$config, 'customFieldPosition' => $position],
        ];
    }
}
