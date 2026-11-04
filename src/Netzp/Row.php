<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Netzp;

/**
 * Typed access to database rows, where binary ids are selected as lower case hex strings.
 *
 * @internal
 */
final class Row
{
    public const EMPTY_ID = '00000000000000000000000000000000';

    /**
     * @param array<string, mixed> $row
     */
    public static function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException(sprintf('Column "%s" is missing or not scalar', $key));
        }

        return (string) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /**
     * Ids that are empty, null or the "all zero" placeholder some plugins use for "none" are returned as null.
     *
     * @param array<string, mixed> $row
     */
    public static function id(array $row, string $key): ?string
    {
        $value = self::nullableString($row, $key);

        return $value === null || $value === self::EMPTY_ID ? null : strtolower($value);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function bool(array $row, string $key): bool
    {
        return (bool) ($row[$key] ?? false);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function int(array $row, string $key): int
    {
        $value = $row[$key] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function date(array $row, string $key): ?\DateTimeImmutable
    {
        $value = self::nullableString($row, $key);
        if ($value === null || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function json(array $row, string $key): array
    {
        $value = self::nullableString($row, $key);
        if ($value === null) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
