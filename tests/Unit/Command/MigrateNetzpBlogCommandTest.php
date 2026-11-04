<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swkweb\ContentManagerNetzpMigration\Command\MigrateNetzpBlogCommand;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationPlan;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;
use Swkweb\ContentManagerNetzpMigration\Migrator\NetzpBlogMigrator;
use Swkweb\ContentManagerNetzpMigration\Migrator\PlanExecutor;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\CommandLoader\FactoryCommandLoader;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @internal
 */
#[CoversClass(MigrateNetzpBlogCommand::class)]
final class MigrateNetzpBlogCommandTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $calls = [];

    /**
     * @var list<mixed>
     */
    private array $deactivatedPlugins = [];

    public function testDeactivatesNetzpBlog6AfterTheMigration(): void
    {
        $tester = $this->createTester($this->createPlan(true, true));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame(['execute', 'plugin:deactivate'], $this->calls);
        self::assertSame([['NetzpBlog6']], $this->deactivatedPlugins);
        self::assertStringContainsString('NetzpBlog6 was deactivated.', $tester->getDisplay());
    }

    public function testDeactivatesNetzpBlog6IfThereIsNothingToMigrate(): void
    {
        $tester = $this->createTester($this->createPlan(false, true));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame(['plugin:deactivate'], $this->calls);
    }

    public function testKeepsNetzpBlog6ActiveInADryRun(): void
    {
        $tester = $this->createTester($this->createPlan(true, true));

        self::assertSame(Command::SUCCESS, $tester->execute(['--dry-run' => true]));
        self::assertSame([], $this->calls);
    }

    public function testLeavesAnInactiveNetzpBlog6Alone(): void
    {
        $tester = $this->createTester($this->createPlan(false, false));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true]));
        self::assertSame([], $this->calls);
        self::assertStringContainsString('There is nothing to migrate.', $tester->getDisplay());
    }

    public function testFailsWithInstructionsIfNetzpBlog6CannotBeDeactivated(): void
    {
        $tester = $this->createTester(
            $this->createPlan(true, true),
            new \RuntimeException('Plugin "NetzpBlog6" has active dependants: NetzpExample6'),
        );

        self::assertSame(Command::FAILURE, $tester->execute(['--force' => true]));
        self::assertSame(['execute', 'plugin:deactivate'], $this->calls);

        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('NetzpExample6', $display);
        self::assertStringContainsString('bin/console plugin:deactivate NetzpBlog6', $display);
        self::assertStringNotContainsString('Next steps', $display);
    }

    private function createPlan(bool $hasArticles, bool $deactivateNetzpBlog): MigrationPlan
    {
        $plan = new MigrationPlan(new MigrationReport());
        $plan->articles = $hasArticles ? [['id' => 'article']] : [];
        $plan->deactivateNetzpBlog = $deactivateNetzpBlog;

        return $plan;
    }

    private function createTester(MigrationPlan $plan, ?\Throwable $deactivationError = null): CommandTester
    {
        $migrator = $this->createMock(NetzpBlogMigrator::class);
        $migrator->method('plan')->willReturn($plan);

        $executor = $this->createMock(PlanExecutor::class);
        $executor->method('execute')->willReturnCallback(function (): void {
            $this->calls[] = 'execute';
        });

        $deactivateCommand = new Command('plugin:deactivate');
        $deactivateCommand->addArgument('plugins', InputArgument::IS_ARRAY);
        $deactivateCommand->setCode(function (InputInterface $input, OutputInterface $output) use ($deactivationError): int {
            $this->calls[] = 'plugin:deactivate';
            $this->deactivatedPlugins[] = $input->getArgument('plugins');

            if ($deactivationError !== null) {
                throw $deactivationError;
            }

            return Command::SUCCESS;
        });

        $application = new Application();
        $application->setCommandLoader(new FactoryCommandLoader(['plugin:deactivate' => static fn () => $deactivateCommand]));

        $command = new MigrateNetzpBlogCommand($migrator, $executor);
        $command->setApplication($application);

        return new CommandTester($command);
    }
}
