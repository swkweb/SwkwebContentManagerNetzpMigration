<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp\Model;

final readonly class NetzpBlogData
{
    /**
     * @param array<string, NetzpPost> $posts keyed by id
     * @param array<string, NetzpAuthor> $authors keyed by id
     * @param array<string, NetzpCategory> $categories keyed by id
     * @param array<string, array<string, mixed>> $config plugin config values keyed by key, then by sales channel id ('' for all sales channels)
     * @param list<NetzpCmsSlot> $slots all CMS slots of the blog listing and detail elements
     * @param array<string, list<array{path: string, canonical: bool}>> $seoPaths SEO paths keyed by post id
     * @param list<string> $draftPageIds CMS pages whose drafts or older versions contain blog elements
     */
    public function __construct(
        public array $posts,
        public array $authors,
        public array $categories,
        public array $config,
        public array $slots,
        public array $seoPaths,
        public array $draftPageIds = [],
    ) {}

    public function getConfig(string $key): mixed
    {
        return $this->config[$key][''] ?? null;
    }

    /**
     * @return array<string, mixed> values keyed by sales channel id, without the value for all sales channels
     */
    public function getSalesChannelConfig(string $key): array
    {
        $values = $this->config[$key] ?? [];
        unset($values['']);

        return $values;
    }
}
