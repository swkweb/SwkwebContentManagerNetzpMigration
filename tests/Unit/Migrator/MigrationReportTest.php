<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Tests\Unit\Migrator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;

/**
 * @internal
 */
#[CoversClass(MigrationReport::class)]
final class MigrationReportTest extends TestCase
{
    public function testGroupsItemsByMessageWithoutDuplicates(): void
    {
        $report = new MigrationReport();
        $report->warn('Tags are not migrated', 'First post');
        $report->warn('Tags are not migrated', 'Second post');
        $report->warn('Tags are not migrated', 'First post');
        $report->warn('The RSS feed is gone');
        $report->notice('Layouts are converted', 'Home');

        self::assertSame([
            'Tags are not migrated' => ['First post', 'Second post'],
            'The RSS feed is gone' => [],
        ], $report->getWarnings());
        self::assertSame(['Layouts are converted' => ['Home']], $report->getNotices());
    }

    public function testSumsCounts(): void
    {
        $report = new MigrationReport();
        $report->count('Posts');
        $report->count('Posts');
        $report->count('SEO URLs kept', 3);

        self::assertSame(['Posts' => 2, 'SEO URLs kept' => 3], $report->getCounts());
    }
}
