<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EventLogCleanup
{
    private const PREFIX = '%PREFIX%';
    private readonly string $dbPrefix;

    /**
     * Constant used to indicate where the query can place "SET a = :a" when query is an update.
     */
    private const SET = '%SET%';

    public const DEFAULT_DAYS         = 365;
    public const CAMPAIGN_LEAD_EVENTS = 'campaign_lead_event_log';
    public const LEAD_EVENTS          = 'lead_event_log';
    public const EMAIL_STATS          = 'email_stats';
    public const EMAIL_STATS_TOKENS   = 'email_stats_tokens';
    private const EMAIL_STATS_DEVICES = 'email_stats_devices';
    public const PAGE_HITS            = 'page_hits';
    public const ARCHIVE_PREFIX       = 'housekeeping_archive_';

    /**
     * The date column of each table whose IDs grow with that date. The campaign log is left out, because its date_triggered can be set long after the row is created.
     */
    private const DATE_COLUMNS = [
        self::LEAD_EVENTS         => 'date_added',
        self::EMAIL_STATS         => 'date_sent',
        self::EMAIL_STATS_TOKENS  => 'date_sent',
        self::EMAIL_STATS_DEVICES => 'date_opened',
        self::PAGE_HITS           => 'date_hit',
    ];

    /**
     * @var array<string, string>
     */
    private array $queriesTemplate = [
        self::CAMPAIGN_LEAD_EVENTS => self::PREFIX.'campaign_lead_event_log WHERE ('.self::PREFIX.'campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM '.self::PREFIX.'campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND '.self::PREFIX.'campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
        self::LEAD_EVENTS          => self::PREFIX.'lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS          => self::PREFIX.'email_stats LEFT JOIN '.self::PREFIX.'emails ON '.self::PREFIX.'email_stats.email_id = '.self::PREFIX.'emails.id WHERE ('.self::PREFIX.'emails.is_published = 0 OR '.self::PREFIX.'emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR '.self::PREFIX.'email_stats.email_id IS NULL) AND '.self::PREFIX.'email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::EMAIL_STATS_TOKENS   => self::PREFIX.'email_stats '.self::SET.' WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
        self::EMAIL_STATS_DEVICES  => self::PREFIX.'email_stats_devices WHERE '.self::PREFIX.'email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
        self::PAGE_HITS            => self::PREFIX.'page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
    ];

    /**
     * @var array<string, string>
     */
    private array $update = [
        self::EMAIL_STATS_TOKENS => 'SET tokens = NULL',
    ];

    /**
     * @var array<string, array<string, int|list<int>|list<string>>>
     */
    private array $params = [
        self::CAMPAIGN_LEAD_EVENTS => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::LEAD_EVENTS          => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS          => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_TOKENS   => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::EMAIL_STATS_DEVICES  => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
        self::PAGE_HITS            => [
            'daysOld' => self::DEFAULT_DAYS,
        ],
    ];

    /**
     * @var array<string, array<string, int>>
     */
    private array $types = [
        self::CAMPAIGN_LEAD_EVENTS => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::LEAD_EVENTS          => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS          => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_TOKENS   => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::EMAIL_STATS_DEVICES  => [
            'daysOld' => \PDO::PARAM_INT,
        ],
        self::PAGE_HITS            => [
            'daysOld' => \PDO::PARAM_INT,
        ],
    ];

    private string $dryRunMessage       = ' This is a dry run.';
    private string $dryRunDeleteMessage = ' rows would have been deleted.';
    private string $runDeleteMessage    = ' rows have been deleted.';
    private string $dryRunUpdateMessage = ' will be set to NULL.';
    private string $runUpdateMessage    = ' have been set to NULL.';

    /**
     * @var list<string>
     */
    private array $leadObjects = [];

    private ?int $rowsLeft = null;

    private bool $archive = false;

    public function __construct(private readonly Connection $connection, ?string $dbPrefix, private readonly Config $config, private readonly LoggerInterface $logger)
    {
        $this->dbPrefix = $dbPrefix ?? '';
    }

    /**
     * Limits the lead_event_log cleanup to these values of its object column.
     *
     * @param list<string> $leadObjects
     */
    public function setLeadObjects(array $leadObjects): void
    {
        $this->leadObjects = $leadObjects;
    }

    /**
     * A run with $maxRows stops once it has deleted or changed that many rows in all, and later operations do not start.
     * Rows removed through a foreign key cascade are not counted.
     * With $archive, each batch copies its rows, and the child rows a cascade removes with them, to housekeeping_archive_ tables in the same transaction as the write.
     * Both need $batchSize, and a dry run ignores them.
     *
     * @param non-empty-array<string, bool> $operations
     * @param list<int>|null                $excludeEmailIds
     */
    public function deleteEventLogEntries(int $daysOld, ?int $campaignId, bool $dryRun, array $operations, OutputInterface $output, ?array $excludeEmailIds = null, ?int $batchSize = null, ?int $maxRows = null, bool $archive = false): string
    {
        if (!$this->config->isPublished()) {
            return 'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.';
        }

        $this->setUpBatchGuards($dryRun, $batchSize, $maxRows, $archive);

        if (self::DEFAULT_DAYS !== $daysOld) {
            foreach ($this->params as $index => $item) {
                $this->params[$index]['daysOld'] = $daysOld;
            }
        }

        if (null !== $campaignId && $operations[self::CAMPAIGN_LEAD_EVENTS]) {
            $this->params[self::CAMPAIGN_LEAD_EVENTS]['cmpId'] = $campaignId;
            $this->types[self::CAMPAIGN_LEAD_EVENTS]['cmpId']  = \PDO::PARAM_INT;
            $this->queriesTemplate[self::CAMPAIGN_LEAD_EVENTS] .= ' AND campaign_id = :cmpId';
        }

        if ([] !== $this->leadObjects && ($operations[self::LEAD_EVENTS] ?? false)) {
            $this->params[self::LEAD_EVENTS]['leadObjects'] = $this->leadObjects;
            $this->types[self::LEAD_EVENTS]['leadObjects']  = ArrayParameterType::STRING;
            $this->queriesTemplate[self::LEAD_EVENTS] .= ' AND '.self::PREFIX.'lead_event_log.object IN (:leadObjects)';
        }

        if (array_key_exists(self::EMAIL_STATS, $operations) && $operations[self::EMAIL_STATS]) {
            unset($operations[self::EMAIL_STATS]);
            $operations[self::EMAIL_STATS_DEVICES] = true;
            $operations[self::EMAIL_STATS]         = true;
        }

        if (null !== $excludeEmailIds && [] !== $excludeEmailIds) {
            if ($operations[self::EMAIL_STATS] ?? false) {
                $this->queriesTemplate[self::EMAIL_STATS] .= ' AND '.self::PREFIX.'email_stats.email_id NOT IN (:excludeEmailIds)';
                $this->params[self::EMAIL_STATS]['excludeEmailIds'] = $excludeEmailIds;
                $this->types[self::EMAIL_STATS]['excludeEmailIds']  = ArrayParameterType::INTEGER;
            }

            if ($operations[self::EMAIL_STATS_TOKENS] ?? false) {
                $this->queriesTemplate[self::EMAIL_STATS_TOKENS] .= ' AND '.self::PREFIX.'email_stats.email_id NOT IN (:excludeEmailIds)';
                $this->params[self::EMAIL_STATS_TOKENS]['excludeEmailIds'] = $excludeEmailIds;
                $this->types[self::EMAIL_STATS_TOKENS]['excludeEmailIds']  = ArrayParameterType::INTEGER;
            }

            if ($operations[self::EMAIL_STATS_DEVICES] ?? false) {
                $this->queriesTemplate[self::EMAIL_STATS_DEVICES] .= ' AND '.self::PREFIX.'email_stats_devices.stat_id NOT IN (SELECT id FROM '.self::PREFIX.'email_stats WHERE email_id IN (:excludeEmailIds))';
                $this->params[self::EMAIL_STATS_DEVICES]['excludeEmailIds'] = $excludeEmailIds;
                $this->types[self::EMAIL_STATS_DEVICES]['excludeEmailIds']  = ArrayParameterType::INTEGER;
            }

            if ($operations[self::PAGE_HITS] ?? false) {
                $this->queriesTemplate[self::PAGE_HITS] .= ' AND ('.self::PREFIX.'page_hits.email_id IS NULL OR '.self::PREFIX.'page_hits.email_id NOT IN (:excludeEmailIds))';
                $this->params[self::PAGE_HITS]['excludeEmailIds'] = $excludeEmailIds;
                $this->types[self::PAGE_HITS]['excludeEmailIds']  = ArrayParameterType::INTEGER;
            }
        }

        $result = [
            self::CAMPAIGN_LEAD_EVENTS => 0,
            self::LEAD_EVENTS          => 0,
            self::EMAIL_STATS          => 0,
            self::EMAIL_STATS_TOKENS   => 0,
            self::EMAIL_STATS_DEVICES  => 0,
            self::PAGE_HITS            => 0,
        ];

        $useSingleTransaction = $dryRun || null === $batchSize;

        if ($useSingleTransaction) {
            $this->connection->beginTransaction();
        }

        if ($dryRun) {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $queryTemplate = $this->queriesTemplate[$operation];
                $queryTemplate = str_replace(self::SET, '', $queryTemplate);

                $sql                = 'SELECT COUNT(1) as cnt FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                $statement          = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                $result[$operation] = $statement->fetchOne();

                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        } elseif (null !== $batchSize) {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled || 0 === $this->rowsLeft) {
                    continue;
                }

                $result[$operation] = $this->runOperationInBatches($operation, $batchSize, $output);
            }
        } else {
            foreach ($operations as $operation => $enabled) {
                if (false === $enabled) {
                    continue;
                }

                $queryTemplate = $this->queriesTemplate[$operation];
                if (array_key_exists($operation, $this->update)) {
                    $queryTemplate = str_replace(self::SET, $this->update[$operation], $queryTemplate);
                    $sql           = 'UPDATE LOW_PRIORITY '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                } else {
                    $sql = 'DELETE LOW_PRIORITY '.$this->dbPrefix.$operation.' FROM '.str_replace(self::PREFIX, $this->dbPrefix, $queryTemplate);
                }

                $statement          = $this->connection->executeQuery($sql, $this->params[$operation], $this->types[$operation]);
                $result[$operation] = $statement->rowCount();

                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }
            }
        }

        if ($useSingleTransaction) {
            try {
                $this->connection->commit();
            } catch (\Throwable $throwable) {
                $this->connection->rollBack();
                throw $throwable;
            }
        }

        $operationsResult = [
            'delete' => [],
            'update' => [],
        ];
        foreach ($operations as $operation => $enabled) {
            if (false === $enabled) {
                continue;
            }

            if (array_key_exists($operation, $this->update)) {
                $operationsResult['update'][$operation] = $result[$operation];
            } else {
                $operationsResult['delete'][$operation] = $result[$operation];
            }
        }

        $message = $this->generateMessage($operationsResult['delete'], $dryRun ? $this->dryRunDeleteMessage : $this->runDeleteMessage);
        if ('' !== $updateMessage = $this->generateMessage($operationsResult['update'], $dryRun ? $this->dryRunUpdateMessage : $this->runUpdateMessage)) {
            $message .= ' '.$updateMessage;
        }

        if ($dryRun) {
            $message .= $this->dryRunMessage;
        }

        if ($this->archive) {
            $message .= ' Every deleted or changed row was copied to a '.self::ARCHIVE_PREFIX.' table first.';
        }

        if (0 === $this->rowsLeft) {
            $message .= ' Stopped at the limit of '.$maxRows.' rows for this run. Any rows left wait for the next run.';
        }

        return $message;
    }

    /**
     * Checks the row limit and the archive against the batch size, and keeps them for the batches. A dry run uses neither.
     */
    private function setUpBatchGuards(bool $dryRun, ?int $batchSize, ?int $maxRows, bool $archive): void
    {
        if (null !== $maxRows && $maxRows < 1) {
            throw new \InvalidArgumentException('A row limit must be greater than 0.');
        }

        if (null === $batchSize && !$dryRun && (null !== $maxRows || $archive)) {
            throw new \InvalidArgumentException('A row limit and an archive need a batch size.');
        }

        $this->rowsLeft = $dryRun ? null : $maxRows;
        $this->archive  = $archive && !$dryRun;
    }

    /**
     * @param array<string, int> $result
     */
    private function generateMessage(array $result, string $postfix): string
    {
        $message       = '';
        $lastOperation = array_key_last($result);
        foreach ($result as $operation => $resultCount) {
            if ('' !== $message) {
                if ($lastOperation === $operation) {
                    $message .= ' and ';
                } else {
                    $message .= ', ';
                }
            }

            $message .= $resultCount.' '.$operation;
        }

        if ('' === $message) {
            return '';
        }

        return $message.$postfix;
    }

    /**
     * Deletes or updates the rows of one operation in batches, each committed on its own.
     * It selects forward by ID, so rows the conditions keep are not read again for every batch.
     * The write repeats the conditions, so a row that stopped matching after the select stays.
     * The select stops where the rows newer than the cutoff begin, so the last batch does not read on to the end of the table.
     * A row older than the cutoff with an ID past that point waits for a later run.
     * Under a row limit, a batch selects no more rows than the limit has left, so the run never writes past it.
     * With the archive on, each batch is copied and written in one transaction.
     */
    private function runOperationInBatches(string $operation, int $batchSize, OutputInterface $output): int
    {
        $table        = $this->dbPrefix.(self::EMAIL_STATS_TOKENS === $operation ? self::EMAIL_STATS : $operation);
        $idColumn     = $table.'.id';
        $template     = str_replace(self::PREFIX, $this->dbPrefix, $this->queriesTemplate[$operation]);
        $firstNewerId = $this->findFirstNewerId($operation, $table);
        $idBound      = null === $firstNewerId ? '' : ' AND '.$idColumn.' < '.$firstNewerId;
        $selectSql    = 'SELECT '.$idColumn.' FROM '.str_replace(self::SET, '', $template).' AND '.$idColumn.' > :lastId'.$idBound.' ORDER BY '.$idColumn.' LIMIT ';
        $writeSql     = 'DELETE LOW_PRIORITY '.$table.' FROM '.$template.' AND '.$idColumn.' IN (:batchIds)';

        if (array_key_exists($operation, $this->update)) {
            $writeSql = 'UPDATE LOW_PRIORITY '.str_replace(self::SET, $this->update[$operation], $template).' AND '.$idColumn.' IN (:batchIds)';
        }

        $archive = $this->archive ? $this->prepareArchive($operation, $table, $template) : null;

        if ($output->isVerbose()) {
            $output->writeln($selectSql.$batchSize);
            $output->writeln($writeSql);
        }

        $affectedRows = 0;
        $lastId       = 0;

        do {
            $limit = null === $this->rowsLeft ? $batchSize : min($batchSize, $this->rowsLeft);
            $ids   = $this->connection->executeQuery(
                $selectSql.$limit,
                $this->params[$operation] + ['lastId' => $lastId],
                $this->types[$operation] + ['lastId' => \PDO::PARAM_INT]
            )->fetchFirstColumn();

            if ([] === $ids) {
                break;
            }

            $ids = array_map(intval(...), $ids);

            if (null === $archive) {
                $writtenRows = $this->connection->executeQuery(
                    $writeSql,
                    $this->params[$operation] + ['batchIds' => $ids],
                    $this->types[$operation] + ['batchIds' => ArrayParameterType::INTEGER]
                )->rowCount();
            } else {
                $writtenRows = $this->archiveAndWrite($operation, $writeSql, $archive, $ids);
            }

            $affectedRows += $writtenRows;

            if (null !== $this->rowsLeft) {
                $this->rowsLeft = max(0, $this->rowsLeft - $writtenRows);
            }

            $lastId = max($ids);
        } while (count($ids) === $limit && 0 !== $this->rowsLeft);

        return $affectedRows;
    }

    /**
     * Creates the archive tables an operation needs, and returns the statements each batch uses to copy its rows.
     * A delete also copies the rows of each child table that a foreign key cascade empties with it.
     * The archive tables are made with CREATE TABLE ... SELECT, so they have the same columns as their source, with no keys, and generated columns become plain ones.
     * Rows are copied by column position, so an archive table whose column names or order differ from its source is rejected.
     *
     * @return array{lock: string, match: string, table: string, source: string, children: list<array{table: string, source: string, column: string}>}
     */
    private function prepareArchive(string $operation, string $table, string $template): array
    {
        $idColumn = $table.'.id';
        $children = [];

        if (!array_key_exists($operation, $this->update)) {
            foreach ($this->findCascadeChildren($table) as $child => $column) {
                if ([] !== $this->findCascadeChildren($child)) {
                    throw new \RuntimeException('Cannot archive '.$table.', because a delete there cascades from '.$child.' to further tables.');
                }

                $children[] = ['table' => $this->archiveTableName(substr($child, strlen($this->dbPrefix))), 'source' => $child, 'column' => $column];
            }
        }

        $archiveTable = $this->archiveTableName($operation);

        foreach ([...$children, ['table' => $archiveTable, 'source' => $table]] as $copy) {
            $this->connection->executeQuery('CREATE TABLE IF NOT EXISTS '.$copy['table'].' SELECT * FROM '.$copy['source'].' LIMIT 0', [], []);

            if ($this->columnsOf($copy['table']) !== $this->columnsOf($copy['source'])) {
                throw new \RuntimeException('Cannot archive into '.$copy['table'].', because its columns no longer match '.$copy['source'].'. Rename or drop it, and the next run makes a new one.');
            }
        }

        return [
            'lock'     => 'SELECT '.$idColumn.' FROM '.$table.' WHERE '.$idColumn.' IN (:batchIds) FOR UPDATE',
            'match'    => 'SELECT '.$idColumn.' FROM '.str_replace(self::SET, '', $template).' AND '.$idColumn.' IN (:batchIds)',
            'table'    => $archiveTable,
            'source'   => $table,
            'children' => $children,
        ];
    }

    /**
     * Lists the child tables whose rows a delete on this table removes through a foreign key cascade, each with its column that points here.
     * Rejects a table that a delete would change through SET NULL or SET DEFAULT, because a copy of the deleted rows could not undo that.
     * Rejects a cascade that does not come through one single-column key on the parent's id, because the copy finds the child rows by that key.
     *
     * @return array<string, string>
     */
    private function findCascadeChildren(string $table): array
    {
        $children = [];
        $keys     = $this->connection->executeQuery(
            'SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column, k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = :table',
            ['table' => $table],
            ['table' => \PDO::PARAM_STR]
        )->fetchAllAssociative();

        foreach ($keys as $key) {
            if (in_array($key['delete_rule'], ['SET NULL', 'SET DEFAULT'], true)) {
                throw new \RuntimeException('Cannot archive '.$table.', because a delete there changes '.$key['child_table'].' through '.$key['delete_rule'].'.');
            }

            if ('CASCADE' === $key['delete_rule']) {
                if ('id' !== $key['referenced_column'] || array_key_exists((string) $key['child_table'], $children)) {
                    throw new \RuntimeException('Cannot archive '.$table.', because the cascade to '.$key['child_table'].' does not come through one key on its id.');
                }

                $children[(string) $key['child_table']] = (string) $key['child_column'];
            }
        }

        return $children;
    }

    /**
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_map(strval(...), $this->connection->executeQuery(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION',
            ['table' => $table],
            ['table' => \PDO::PARAM_STR]
        )->fetchFirstColumn());
    }

    /**
     * Copies one batch and writes it in a single transaction.
     * It locks the batch rows, keeps those that still match, locks and copies their child rows, copies the rows themselves, and writes.
     * Every row stays locked from its copy to the write, whatever the isolation level, so the archive holds the values that were removed.
     * If a copy or the write counts differently from the rows locked, the batch is rolled back.
     *
     * @param array{lock: string, match: string, table: string, source: string, children: list<array{table: string, source: string, column: string}>} $archive
     * @param list<int>                                                                                                                               $ids
     */
    private function archiveAndWrite(string $operation, string $writeSql, array $archive, array $ids): int
    {
        $batchTypes = ['batchIds' => ArrayParameterType::INTEGER];

        $this->connection->beginTransaction();

        try {
            $this->connection->executeQuery($archive['lock'], ['batchIds' => $ids], $batchTypes);

            $matchingIds = array_map(intval(...), $this->connection->executeQuery(
                $archive['match'],
                $this->params[$operation] + ['batchIds' => $ids],
                $this->types[$operation] + $batchTypes
            )->fetchFirstColumn());

            $writtenRows = 0;

            if ([] !== $matchingIds) {
                foreach ($archive['children'] as $child) {
                    $lockedRows = (int) $this->connection->executeQuery(
                        'SELECT COUNT(*) FROM '.$child['source'].' WHERE '.$child['column'].' IN (:batchIds) FOR UPDATE',
                        ['batchIds' => $matchingIds],
                        $batchTypes
                    )->fetchOne();
                    $copiedRows = $this->copyRows($child['table'], $child['source'], $child['column'], $matchingIds);

                    if ($copiedRows !== $lockedRows) {
                        throw new \RuntimeException('Archive check failed on '.$child['source'].': '.$copiedRows.' rows copied, '.$lockedRows.' expected. The batch was rolled back.');
                    }
                }

                $copiedRows  = $this->copyRows($archive['table'], $archive['source'], 'id', $matchingIds);
                $writtenRows = $this->connection->executeQuery(
                    $writeSql,
                    $this->params[$operation] + ['batchIds' => $matchingIds],
                    $this->types[$operation] + $batchTypes
                )->rowCount();

                if ($copiedRows !== count($matchingIds) || $writtenRows !== $copiedRows) {
                    throw new \RuntimeException('Archive check failed on '.$archive['source'].': '.$copiedRows.' rows copied, '.$writtenRows.' written, '.count($matchingIds).' expected. The batch was rolled back.');
                }
            }

            $this->connection->commit();
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();
            throw $throwable;
        }

        return $writtenRows;
    }

    /**
     * @param list<int> $ids
     */
    private function copyRows(string $archiveTable, string $sourceTable, string $column, array $ids): int
    {
        return $this->connection->executeQuery(
            'INSERT INTO '.$archiveTable.' SELECT * FROM '.$sourceTable.' WHERE '.$column.' IN (:batchIds)',
            ['batchIds' => $ids],
            ['batchIds' => ArrayParameterType::INTEGER]
        )->rowCount();
    }

    private function archiveTableName(string $name): string
    {
        return $this->dbPrefix.self::ARCHIVE_PREFIX.$name;
    }

    /**
     * Finds where the rows newer than the cutoff begin, by a binary search on the ID, so it reads about 30 rows whatever the size of the table.
     * Returns null when the search finds no newer row, or the table has no date column to go by.
     * The result only limits how far the select reads. The select and the write still check the conditions, so a row out of date order
     * can move the result, but a row newer than the cutoff is never removed, and an older one is at most left for a later run.
     */
    private function findFirstNewerId(string $operation, string $table): ?int
    {
        if (!array_key_exists($operation, self::DATE_COLUMNS)) {
            return null;
        }

        [$low, $high] = $this->connection->executeQuery('SELECT MIN(id), MAX(id) FROM '.$table, [], [])->fetchNumeric();

        if (null === $high) {
            return null;
        }

        $low  = (int) $low;
        $high = (int) $high;

        while ($low < $high) {
            $middle = $low + (int) (($high - $low) / 2);

            if ($this->isNewerFromId($operation, $table, $middle)) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $this->isNewerFromId($operation, $table, $low) ? $low : null;
    }

    /**
     * Tells whether the first row at or after this ID is newer than the cutoff.
     */
    private function isNewerFromId(string $operation, string $table, int $id): bool
    {
        return (bool) $this->connection->executeQuery(
            'SELECT '.self::DATE_COLUMNS[$operation].' >= DATE_SUB(NOW(),INTERVAL :daysOld DAY) FROM '.$table.' WHERE id >= :probeId ORDER BY id LIMIT 1',
            ['daysOld' => $this->params[$operation]['daysOld'], 'probeId' => $id],
            ['daysOld' => \PDO::PARAM_INT, 'probeId' => \PDO::PARAM_INT]
        )->fetchOne();
    }

    public function optimizeTables(OutputInterface $output): string
    {
        try {
            $tables = $this->getAllTables();

            if ([] === $tables) {
                return 'No tables found to optimize.';
            }

            $tableList = '`'.implode('`, `', $tables).'`';
            $sql       = "OPTIMIZE TABLE {$tableList}";

            if ($output->isVerbose()) {
                $output->writeln('Optimizing '.count($tables).' tables...');
                $output->writeln($sql);
            }

            $statement = $this->connection->executeQuery($sql);
            $results   = $statement->fetchAllAssociative();

            return 'All tables have been optimized.';
        } catch (\Throwable $e) {
            $errorMsg = 'Table optimization failed: '.$e->getMessage();
            $this->logger->error($errorMsg);
            throw $e;
        }
    }

    /**
     * @return array<string>
     */
    private function getAllTables(): array
    {
        $sql       = 'SHOW TABLES';
        $statement = $this->connection->executeQuery($sql);

        return $statement->fetchFirstColumn();
    }
}
