<?php declare(strict_types=1);

namespace Swkweb\ContentManagerNetzpMigration\Command;

use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationException;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationOptions;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationPlan;
use Swkweb\ContentManagerNetzpMigration\Migrator\MigrationReport;
use Swkweb\ContentManagerNetzpMigration\Migrator\NetzpBlogMigrator;
use Swkweb\ContentManagerNetzpMigration\Migrator\PlanExecutor;
use Swkweb\ContentManagerNetzpMigration\Netzp\NetzpBlogReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'swkweb:content-manager:migrate-netzp-blog',
    description: 'Migrates the NetzpBlog6 blog to the Content Manager and reports data that cannot be migrated',
)]
class MigrateNetzpBlogCommand extends Command
{
    private const MAX_ITEMS = 10;

    public function __construct(
        private readonly NetzpBlogMigrator $migrator,
        private readonly PlanExecutor $executor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only report what would be migrated, without changing anything')
            ->addOption('category', null, InputOption::VALUE_REQUIRED, 'Category for posts without a category and parent of the created categories (default: the "navigation category" of NetzpBlog6)')
            ->addOption('sales-channel', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Sales channel the articles are visible in (default: all storefront sales channels)')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Migrate posts and authors again that were already migrated, overwriting changes made since')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Migrate without asking for confirmation')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('NetzpBlog6 → Content Manager');

        $category = $input->getOption('category');
        /** @var list<string> $salesChannelIds */
        $salesChannelIds = $input->getOption('sales-channel');

        try {
            $plan = $this->migrator->plan(new MigrationOptions(
                is_string($category) ? $category : null,
                $salesChannelIds,
                (bool) $input->getOption('overwrite'),
            ));
        } catch (MigrationException $exception) {
            $io->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderReport($io, $plan->report, $output->isVerbose());

        if ($input->getOption('dry-run')) {
            $io->note('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        $hasChanges = $this->hasChanges($plan);
        if (!$hasChanges && !$plan->deactivateNetzpBlog) {
            $io->success('There is nothing to migrate.');

            return self::SUCCESS;
        }

        if (!$input->getOption('force') && !$this->confirm($input, $io, $hasChanges, $plan->deactivateNetzpBlog)) {
            return self::FAILURE;
        }

        if ($hasChanges) {
            $this->executor->execute($plan, static function (string $step) use ($io): void {
                $io->text('Migrating: ' . $step);
            });

            $io->success('The blog was migrated.');
        }

        // Deactivating a plugin reboots the kernel, so it only happens once the migration is committed.
        if ($plan->deactivateNetzpBlog && !$this->deactivateNetzpBlog($io)) {
            return self::FAILURE;
        }

        $io->section('Next steps');
        $io->listing([
            'Clear the cache: bin/console cache:clear',
            'Check the articles, their layouts and the blog pages in the storefront, and resolve the warnings above',
            'Uninstall NetzpBlog6 and this migration plugin once everything is checked',
        ]);

        return self::SUCCESS;
    }

    private function confirm(InputInterface $input, SymfonyStyle $io, bool $hasChanges, bool $deactivatesNetzpBlog): bool
    {
        if (!$input->isInteractive()) {
            $io->error('Review the report above, then run the migration again with --force.');

            return false;
        }

        if (!$hasChanges) {
            return $io->confirm('There is nothing to migrate, but NetzpBlog6 is still active. Do you want to deactivate it now?', false);
        }

        return $io->confirm(sprintf(
            'The migration changes CMS layouts and SEO URLs of the shop%s. Did you create a database backup, and do you want to migrate now?',
            $deactivatesNetzpBlog ? ', and deactivates NetzpBlog6' : '',
        ), false);
    }

    /**
     * Runs the plugin:deactivate command, as the plugin lifecycle service is internal to Shopware.
     */
    private function deactivateNetzpBlog(SymfonyStyle $io): bool
    {
        $input = new ArrayInput(['plugins' => [NetzpBlogReader::PLUGIN_NAME]]);
        $input->setInteractive(false);
        $output = new BufferedOutput();

        try {
            $application = $this->getApplication() ?? throw new \LogicException('The command is not registered in a console application.');
            $error = $application->find('plugin:deactivate')->run($input, $output) === self::SUCCESS ? null : trim($output->fetch());
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        if ($error !== null) {
            $io->error(sprintf(
                "NetzpBlog6 could not be deactivated: %s\n\nDeactivate it now with \"bin/console plugin:deactivate %s\": while it is active, saving a post or rebuilding the indexes takes the SEO URLs of the articles back to the posts.",
                $error,
                NetzpBlogReader::PLUGIN_NAME,
            ));

            return false;
        }

        $io->success('NetzpBlog6 was deactivated.');

        return true;
    }

    private function renderReport(SymfonyStyle $io, MigrationReport $report, bool $verbose): void
    {
        $io->section('Migration');
        $counts = $report->getCounts();
        $io->table(['', 'Count'], array_map(
            static fn (string $label, int $count) => [$label, $count],
            array_keys($counts),
            array_values($counts),
        ));

        if ($report->getWarnings() !== []) {
            $io->section('Data that is not migrated or behaves differently');
            $this->renderMessages($io, $report->getWarnings(), '<fg=yellow>!</>', $verbose);
        }

        if ($report->getNotices() !== []) {
            $io->section('Notes');
            $this->renderMessages($io, $report->getNotices(), '<fg=cyan>i</>', $verbose);
        }
    }

    /**
     * @param array<string, list<string>> $messages
     */
    private function renderMessages(SymfonyStyle $io, array $messages, string $marker, bool $verbose): void
    {
        foreach ($messages as $message => $items) {
            $io->writeln(sprintf(' %s %s', $marker, $message));

            $shown = $verbose ? $items : array_slice($items, 0, self::MAX_ITEMS);
            foreach ($shown as $item) {
                $io->writeln('     · ' . $item);
            }

            if (count($items) > count($shown)) {
                $io->writeln(sprintf('     · … and %d more (use -v to show all)', count($items) - count($shown)));
            }

            $io->newLine();
        }
    }

    private function hasChanges(MigrationPlan $plan): bool
    {
        return $plan->articles !== []
            || $plan->authors !== []
            || $plan->categories !== []
            || $plan->slotUpdates !== []
            || $plan->customFieldSetRelations !== []
            || $plan->removedCustomFieldSetRelationIds !== []
            || $plan->detachedMediaFolderId !== null;
    }
}
