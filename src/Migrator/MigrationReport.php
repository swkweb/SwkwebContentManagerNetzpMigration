<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Migrator;

/**
 * Collects what the migration moves and, as warnings, what it cannot move or what behaves differently afterwards.
 * Messages are grouped, each with the affected posts, pages or settings as items.
 */
final class MigrationReport
{
    /**
     * @var array<string, int>
     */
    private array $counts = [];

    /**
     * @var array<string, list<string>>
     */
    private array $warnings = [];

    /**
     * @var array<string, list<string>>
     */
    private array $notices = [];

    public function count(string $label, int $count = 1): void
    {
        $this->counts[$label] = ($this->counts[$label] ?? 0) + $count;
    }

    public function warn(string $message, ?string $item = null): void
    {
        self::add($this->warnings, $message, $item);
    }

    public function notice(string $message, ?string $item = null): void
    {
        self::add($this->notices, $message, $item);
    }

    /**
     * @return array<string, int>
     */
    public function getCounts(): array
    {
        return $this->counts;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * @return array<string, list<string>>
     */
    public function getNotices(): array
    {
        return $this->notices;
    }

    /**
     * @param array<string, list<string>> $messages
     */
    private static function add(array &$messages, string $message, ?string $item): void
    {
        $messages[$message] ??= [];

        if ($item !== null && !in_array($item, $messages[$message], true)) {
            $messages[$message][] = $item;
        }
    }
}
