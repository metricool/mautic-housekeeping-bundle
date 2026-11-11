<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Command;

use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'leuchtfeuer:housekeeping',
    description: 'Database Cleanup Command to delete lead_event_log table entries, campaign_lead_event_log table entries, email_stats table entries where the referenced email entry is currently not published and email_stats_devices table entries.',
)]
class EventLogCleanupCommand extends Command
{
    private const DEFAULT_DAYS = 365;

    public function __construct(private readonly EventLogCleanup $eventLogCleanup)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDefinition(
                [
                    new InputOption(
                        'days-old',
                        'd',
                        InputOption::VALUE_OPTIONAL,
                        'Purge records older than this number of days. Defaults to 365.',
                        self::DEFAULT_DAYS
                    ),
                    new InputOption('dry-run', 'r', InputOption::VALUE_NONE, 'Do a dry run without actually deleting anything.'),
                    new InputOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Process this many rows at a time, each batch committed on its own, instead of one large transaction. Off by default. Has no effect on a dry run.'),
                    new InputOption('max-rows', null, InputOption::VALUE_REQUIRED, 'Stop the run once it has deleted or changed this many rows in all. Needs --batch-size. Rows removed through a foreign key cascade are not counted. Off by default. Has no effect on a dry run.'),
                    new InputOption('archive', null, InputOption::VALUE_NONE, 'Copy every row the run deletes or changes, and the rows a foreign key cascade removes with them, to a '.EventLogCleanup::ARCHIVE_PREFIX.'<table> table in the same transaction, so they can be put back. Needs --batch-size. The archive tables are kept until you drop them. Has no effect on a dry run.'),
                    new InputOption('campaign-lead', 'c', InputOption::VALUE_NONE, 'Purge only Campaign Lead Event Log Records'),
                    new InputOption('lead', 'l', InputOption::VALUE_NONE, 'Purge only Lead Event Log Records'),
                    new InputOption('lead-object', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of lead_event_log objects to purge (e.g., segment,import). All other objects are kept. Implies --lead.'),
                    new InputOption('page-hits', 'p', InputOption::VALUE_NONE, 'Purge only Hit (page_hit) Records'),
                    new InputOption('email-stats', 'm', InputOption::VALUE_NONE, 'Purge only Email Stats Records where the referenced email entry is currently not published and purge Email Stats Devices. Important: If referenced email is ever switched back to published, the contacts will get the email again.'),
                    new InputOption('email-stats-tokens', 't', InputOption::VALUE_NONE, 'Set only tokens fields in Email Stats Records to NULL. Important: This option can not be combined with any "-c", "-l" or "-m" flag in one command. And: If the option flag "-t" is not set, the NULL setting of tokens will not be done with the basis command, so if you just run mautic:leuchtfeuer:housekeeping without a flag)'),
                    new InputOption('cmp-id', 'i', InputOption::VALUE_OPTIONAL, 'Delete only campaign_lead_eventLog for a specific CampaignID. Implies --campaign-lead.', 'none'),
                    new InputOption('exclude-emails', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of email IDs to exclude from deletion (e.g., 1,44,61). Works with --email-stats, --email-stats-tokens and --page-hits.'),
                    new InputOption('ip-addresses', null, InputOption::VALUE_NONE, 'Delete the ip_addresses rows that no table uses. A row counts as used when a table with a foreign key to ip_addresses, a '.EventLogCleanup::ARCHIVE_PREFIX.'<table> table or another indexed table with the same column points at it. The newest '.EventLogCleanup::IP_KEEP_NEWEST.' IDs are never touched. Needs --batch-size. Not part of the default cleanup. --days-old has no effect on it.'),
                    new InputOption('ip-slice', null, InputOption::VALUE_REQUIRED, 'With --ip-addresses, check only slice N of M, for example 5/31. The IDs are cut into blocks of '.EventLogCleanup::IP_SLICE_BLOCK.', and slice N takes block N and every Mth block after it. Off by default, so every ID is checked.'),
                    new InputOption('optimize-tables', 'o', InputOption::VALUE_NONE, 'Optimize all database tables. If no cleanup flag is set, only the optimization runs and no data is deleted.'),
                ]
            )
            ->setHelp(
                <<<'EOT'
                The <info>%command.name%</info> command is used to clean up the campaign_lead_event_log table, the lead_event_log table, the page_hits table, the email_stats table (but only email_stats entries where the referenced email entry is currently not published) and the email_stats_devices table or just clean up the field tokens in email_stats if the option flag "-t" is set.

                <info>php %command.full_name%</info>

                Specify the number of days old data should be before purging:
                <info>php %command.full_name% --days-old=365</info>

                You can also optionally specify a dry run without deleting any records:
                <info>php %command.full_name% --days-old=365 --dry-run</info>

                Process large tables in batches, each committed on its own:
                <info>php %command.full_name% --page-hits --batch-size=5000</info>

                Stop the run once it has deleted 100000 rows, a little above what the dry run counted:
                <info>php %command.full_name% --page-hits --batch-size=5000 --max-rows=100000</info>

                Keep a copy of every row the run removes, in housekeeping_archive_ tables, so it can be put back:
                <info>php %command.full_name% --page-hits --batch-size=5000 --archive</info>

                Delete the ip_addresses rows that no table uses, in batches, with a copy of each:
                <info>php %command.full_name% --ip-addresses --batch-size=1000 --archive</info>

                Check only slice 5 of 31 of the ip_addresses IDs, so a nightly run stays short:
                <info>php %command.full_name% --ip-addresses --ip-slice=5/31 --batch-size=1000</info>

                If you run --optimize-tables on its own, with no other cleanup flag, no data is deleted. Only the table optimization runs:
                <info>php %command.full_name% --optimize-tables</info>

                You can also optionally specify for which campaign the entries should be purged from campaign_lead_event_log:
                <info>php %command.full_name% --cmp-id=123</info>

                Purge only campaign_lead_event_log records:
                <info>php %command.full_name% --campaign-lead </info>

                Purge only lead_event_log records
                <info>php %command.full_name% --lead</info>

                Purge only specific objects from lead_event_log and keep the rest:
                <info>php %command.full_name% --lead-object=segment</info>

                Purge only email_stats where the referenced email entry is currently not published and email_stats_devices records [Important: If referenced email is ever switched back to published, the contacts will get the email again]:
                <info>php %command.full_name% --email-stats</info>

                Set tokens field in email_stats to NULL:
                <info>php %command.full_name% --email-stats-tokens</info>

                Purge only page_hits records
                <info>php %command.full_name% --page-hits</info>

                Exclude specific emails from cleanup:
                <info>php %command.full_name% --email-stats --exclude-emails=1,44,61</info>

                Keep the page_hits records of specific emails, such as clicks that a segment still reads:
                <info>php %command.full_name% --page-hits --exclude-emails=1,44,61</info>
                EOT
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = $this->parsePositiveInteger($input, 'batch-size');
        $maxRows   = $this->parsePositiveInteger($input, 'max-rows');

        if (false === $batchSize || false === $maxRows) {
            $output->writeln('<error>--'.(false === $batchSize ? 'batch-size' : 'max-rows').' must be a whole number greater than 0.</error>');

            return Command::FAILURE;
        }

        $archive     = (bool) $input->getOption('archive');
        $ipAddresses = (bool) $input->getOption('ip-addresses');

        foreach (['max-rows' => null !== $maxRows, 'archive' => $archive, 'ip-addresses' => $ipAddresses] as $option => $given) {
            if ($given && null === $batchSize) {
                $output->writeln('<error>--'.$option.' needs --batch-size.</error>');

                return Command::FAILURE;
            }
        }

        $ipSlice = $this->parseIpSlice($input);

        if (false === $ipSlice) {
            $output->writeln('<error>--ip-slice must be N/M, two whole numbers with N from 1 to M and M from 1 to 1000, for example 5/31.</error>');

            return Command::FAILURE;
        }

        if (null !== $ipSlice && !$ipAddresses) {
            $output->writeln('<error>--ip-slice needs --ip-addresses.</error>');

            return Command::FAILURE;
        }

        $daysOld                              = (int) $input->getOption('days-old');
        $dryRun                               = $input->getOption('dry-run');
        $campaignId                           = 'none' === $input->getOption('cmp-id') ? null : (int) $input->getOption('cmp-id');
        $excludeEmails                        = $this->parseExcludeEmailIds($input, $output);
        $optimizeTables                       = $input->getOption('optimize-tables');
        $leadObjects                          = $this->parseLeadObjects($input);

        if ([] === $leadObjects) {
            $output->writeln('<error>--lead-object needs at least one object name.</error>');

            return Command::FAILURE;
        }

        $operations                           = [
            EventLogCleanup::CAMPAIGN_LEAD_EVENTS => $input->getOption('campaign-lead') || null !== $campaignId,
            EventLogCleanup::LEAD_EVENTS          => $input->getOption('lead') || null !== $leadObjects,
            EventLogCleanup::EMAIL_STATS          => $input->getOption('email-stats'),
            EventLogCleanup::EMAIL_STATS_TOKENS   => $input->getOption('email-stats-tokens'),
            EventLogCleanup::PAGE_HITS            => $input->getOption('page-hits'),
            EventLogCleanup::IP_ADDRESSES         => $ipAddresses,
        ];

        $hasCleanupOperations = array_sum($operations) > 0;
        if ($optimizeTables && !$hasCleanupOperations) {
            // Only --optimize-tables was given. Skip every delete/update operation.
            $operations = array_combine(array_keys($operations), array_fill(0, count($operations), false));
        } elseif (0 === array_sum($operations)) {
            // No flag at all was given. Fall back to the default full cleanup.
            $operations                                      = array_combine(array_keys($operations), array_fill(0, count($operations), true));
            $operations[EventLogCleanup::EMAIL_STATS_TOKENS] = false;
            $operations[EventLogCleanup::IP_ADDRESSES]       = false;
        }

        if ((true === $operations[EventLogCleanup::EMAIL_STATS_TOKENS]) && in_array(true, [$operations[EventLogCleanup::EMAIL_STATS], $operations[EventLogCleanup::CAMPAIGN_LEAD_EVENTS], $operations[EventLogCleanup::LEAD_EVENTS]], true)) {
            $output->writeln('<error>The combination of "-t" flag with either "-m" flag or "-c" flag or "-l" flag is not supported/possible. You can only combine the "-t" flag with "-d" flag and/or "-r" flag.</error>');

            return Command::FAILURE;
        }

        if (null !== $leadObjects) {
            $this->eventLogCleanup->setLeadObjects($leadObjects);
        }

        if ($ipAddresses) {
            [$slice, $slices] = $ipSlice ?? [1, 1];
            $this->eventLogCleanup->setIpSlice($slice, $slices);
        }

        if (array_sum($operations) > 0) {
            try {
                $message = $this->eventLogCleanup->deleteEventLogEntries(
                    $daysOld,
                    $campaignId,
                    $dryRun,
                    $operations,
                    $output,
                    $excludeEmails,
                    $batchSize,
                    $maxRows,
                    $archive
                );
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Deletion of Log Rows failed because of database error: %s</error>', $e->getMessage()));

                return Command::FAILURE;
            }

            $output->writeln('<info>'.$message.'<info>');
        }

        if ($optimizeTables && !$dryRun) {
            try {
                $message = $this->eventLogCleanup->optimizeTables($output);
                $output->writeln('<info>'.$message.'<info>');
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Table optimization failed: %s</error>', $e->getMessage()));

                return Command::FAILURE;
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @return list<int>|null
     */
    private function parseExcludeEmailIds(InputInterface $input, OutputInterface $output): ?array
    {
        $rawValue = $input->getOption('exclude-emails');

        if (null === $rawValue || '' === $rawValue) {
            return null;
        }

        $ids = [];

        foreach (explode(',', $rawValue) as $part) {
            $part = trim($part);

            if (!ctype_digit($part)) {
                continue;
            }

            $id = (int) $part;

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        if ([] === $ids) {
            $output->writeln('<comment>Warning: --exclude-emails contained no valid IDs and will be ignored.</comment>');

            return null;
        }

        return array_values($ids);
    }

    /**
     * Returns null when the option is not given, and false when it is not a whole number greater than 0.
     */
    private function parsePositiveInteger(InputInterface $input, string $option): int|false|null
    {
        $rawValue = $input->getOption($option);

        if (null === $rawValue) {
            return null;
        }

        if (!ctype_digit((string) $rawValue) || 0 === (int) $rawValue) {
            return false;
        }

        return (int) $rawValue;
    }

    /**
     * Returns null when the option is not given, and an empty list when it holds no names.
     *
     * @return list<string>|null
     */
    private function parseLeadObjects(InputInterface $input): ?array
    {
        $rawValue = $input->getOption('lead-object');

        if (null === $rawValue) {
            return null;
        }

        $objects = array_filter(array_map(trim(...), explode(',', (string) $rawValue)), static fn (string $object): bool => '' !== $object);

        return array_values(array_unique($objects));
    }

    /**
     * Reads --ip-slice as slice N of M.
     *
     * @param InputInterface $input the command input
     *
     * @return array{0: int, 1: int}|false|null the values of N and M, null when the option is not given, false when it is not N/M with N from 1 to M and M from 1 to 1000
     */
    private function parseIpSlice(InputInterface $input): array|false|null
    {
        $rawValue = $input->getOption('ip-slice');

        if (null === $rawValue) {
            return null;
        }

        if (1 !== preg_match('/\A(\d{1,4})\/(\d{1,4})\z/', (string) $rawValue, $matches)) {
            return false;
        }

        $slice  = (int) $matches[1];
        $slices = (int) $matches[2];

        if ($slice < 1 || $slice > $slices || $slices > 1000) {
            return false;
        }

        return [$slice, $slices];
    }
}
