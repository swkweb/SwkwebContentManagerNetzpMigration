<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Tests\Unit\Netzp;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swkweb\ContentManagerNetzpMigration\Netzp\Row;

/**
 * @internal
 */
#[CoversClass(Row::class)]
final class RowTest extends TestCase
{
    public function testTreatsEmptyAndPlaceholderIdsAsNull(): void
    {
        $row = ['empty' => '', 'placeholder' => '00000000000000000000000000000000', 'upper' => 'ABCDEF0123456789ABCDEF0123456789'];

        self::assertNull(Row::id($row, 'empty'));
        self::assertNull(Row::id($row, 'placeholder'));
        self::assertNull(Row::id($row, 'missing'));
        self::assertSame('abcdef0123456789abcdef0123456789', Row::id($row, 'upper'));
    }

    public function testParsesDatesAndIgnoresZeroDates(): void
    {
        $row = ['date' => '2023-10-02', 'zero' => '0000-00-00', 'none' => null];

        self::assertSame('2023-10-02T00:00:00+00:00', Row::date($row, 'date')?->format(\DATE_ATOM));
        self::assertNull(Row::date($row, 'zero'));
        self::assertNull(Row::date($row, 'none'));
    }

    public function testDecodesJsonObjectsOnly(): void
    {
        self::assertSame(['foo' => 'bar'], Row::json(['value' => '{"foo":"bar"}'], 'value'));
        self::assertSame([], Row::json(['value' => '"text"'], 'value'));
        self::assertSame([], Row::json(['value' => null], 'value'));
    }
}
