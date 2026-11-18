<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
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
    public const EMAIL_LIST_XREF      = 'email_list_xref';
    public const SEGMENT_EMAIL_FIELDS = ['lead_email_received', 'lead_email_sent'];
    private const ID_RANGE_TYPES      = ['fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
    private const MAX_ID_SPAN         = 50000;

    /**
     * The core segment filters that read email_stats rows or email clicks without naming an email, such as a date or a count.
     */
    private const SEGMENT_EMAIL_ACTIVITY_FIELDS = ['email_clicked_link_date', 'email_id', 'lead_email_read_count', 'lead_email_read_date', 'lead_email_sent_date'];

    /**
     * The segment filter operators whose query ignores the filter's value.
     */
    private const SEGMENT_VALUELESS_OPERATORS = ['empty', '!empty'];

    /**
     * The segment filter operator that holds a list of filters in its properties.
     */
    private const SEGMENT_CUSTOM_OPERATOR = 'custom_operator';

    public const IP_ADDRESSES          = 'ip_addresses';
    public const IP_KEEP_NEWEST        = 1000000;
    public const IP_SLICE_BLOCK        = 100000;
    private const IP_SCAN_SPAN         = 10000;
    private const IP_INDEX_PREFIX      = 'housekeeping_';
    private const IP_LOCK_WAIT_TIMEOUT = 30;
    private const IP_WINDOW_TYPES      = ['lastId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
    private const IP_ISOLATION         = 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED';
    private const IP_PLAIN_NAME        = '/\A[A-Za-z0-9_$]+\z/';

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

    private int $ipSlice = 1;

    private int $ipSlices = 1;

    private string $ipMessage = '';

    private bool $unlinkSegments = false;

    /**
     * @var list<int>
     */
    private array $protectedEmails = [];

    /**
     * @var array<int, true>
     */
    private array $keptEmails = [];

    private int $unlinkedLinks = 0;

    /**
     * @var array<int, true>
     */
    private array $unlinkedEmails = [];

    /**
     * @var array<string, string>
     */
    private readonly array $defaultQueries;

    /**
     * @var array<string, array<string, int|list<int>|list<string>>>
     */
    private readonly array $defaultParams;

    /**
     * @var array<string, array<string, int>>
     */
    private readonly array $defaultTypes;

    public function __construct(private readonly Connection $connection, ?string $dbPrefix, private readonly Config $config, private readonly LoggerInterface $logger)
    {
        $this->dbPrefix       = $dbPrefix ?? '';
        $this->defaultQueries = $this->queriesTemplate;
        $this->defaultParams  = $this->params;
        $this->defaultTypes   = $this->types;
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
     * Limits the ip_addresses cleanup to one slice of its ID blocks.
     *
     * @param int $slice  the slice to check, from 1 to $slices
     * @param int $slices how many slices share the ID blocks, at least 1
     */
    public function setIpSlice(int $slice, int $slices): void
    {
        if ($slice < 1 || $slice > $slices) {
            throw new \InvalidArgumentException('An ip_addresses slice must be N of M, with N from 1 to M.');
        }

        $this->ipSlice  = $slice;
        $this->ipSlices = $slices;
    }

    /**
     * Makes the email_stats cleanup remove the segments of each email whose stats it deletes, and of that email's variants and translations,
     * and keep the stats of a closed email while one of its variants or translations is still open or excluded.
     *
     * @param bool $unlinkSegments true to remove the segments in the same transaction as the stats
     */
    public function setUnlinkSegments(bool $unlinkSegments): void
    {
        $this->unlinkSegments = $unlinkSegments;
    }

    /**
     * Reads the filters of every segment, published or not, and collects the email IDs that the filters on these fields name.
     * It also lists the segments with a filter on email activity that names no email, such as a date, a count, or one of these fields with the empty or not empty operator,
     * because no email ID can keep them as they are.
     * It rejects a segment whose filters cannot be read, a filter with no field name, and a value of these fields that is not an email ID.
     *
     * @param list<string> $fields the filter fields whose values are email IDs
     *
     * @return array{emails: list<int>, segments: list<int>} the email IDs, and the segments that no email ID can keep, both in ascending order
     */
    public function findSegmentEmails(array $fields): array
    {
        $emails   = [];
        $segments = [];
        $rows     = $this->connection->executeQuery('SELECT id, filters FROM '.$this->dbPrefix.'lead_lists ORDER BY id', [], [])->fetchAllAssociative();

        foreach ($rows as $row) {
            $segmentId = (int) $row['id'];

            foreach ($this->flatFilters($segmentId, $this->filtersOf($segmentId, $row['filters'])) as $filter) {
                $field = $filter['field'];

                if (!in_array($field, $fields, true)) {
                    if (in_array($field, self::SEGMENT_EMAIL_ACTIVITY_FIELDS, true)) {
                        $segments[$segmentId] = true;
                    }

                    continue;
                }

                if (in_array($filter['operator'] ?? null, self::SEGMENT_VALUELESS_OPERATORS, true)) {
                    $segments[$segmentId] = true;

                    continue;
                }

                $ids = $this->emailIdsOf($filter);

                if (null === $ids) {
                    throw new \UnexpectedValueException('Segment '.$segmentId.' has a value in its '.$field.' filter that is not an email ID.');
                }

                if ([] === $ids) {
                    $segments[$segmentId] = true;
                }

                foreach ($ids as $id) {
                    $emails[$id] = true;
                }
            }
        }

        $emails = array_keys($emails);
        sort($emails);

        return ['emails' => $emails, 'segments' => array_keys($segments)];
    }

    /**
     * Each run starts from the default conditions, so nothing an earlier run added to them is kept.
     * A run with $maxRows stops once it has deleted or changed that many rows in all, and later operations do not start.
     * Rows removed through a foreign key cascade are not counted, and neither are the segment links that the segment unlink removes.
     * With $archive, each batch copies its rows, and the child rows a cascade removes with them, to housekeeping_archive_ tables in the same transaction as the write.
     * Both need $batchSize, and a dry run ignores them.
     * The ip_addresses cleanup runs last, outside the transactions of the other operations, and needs $batchSize outside a dry run.
     *
     * @param non-empty-array<string, bool> $operations
     * @param list<int>|null                $excludeEmailIds
     */
    public function deleteEventLogEntries(int $daysOld, ?int $campaignId, bool $dryRun, array $operations, OutputInterface $output, ?array $excludeEmailIds = null, ?int $batchSize = null, ?int $maxRows = null, bool $archive = false): string
    {
        if (!$this->config->isPublished()) {
            return 'Housekeeping by Leuchtfeuer is currently not enabled. To use it, please enable the plugin in your Mautic plugin management.';
        }

        $this->queriesTemplate = $this->defaultQueries;
        $this->params          = $this->defaultParams;
        $this->types           = $this->defaultTypes;

        $this->setUpBatchGuards($dryRun, $batchSize, $maxRows, $archive);

        $ipAddresses     = (bool) ($operations[self::IP_ADDRESSES] ?? false);
        $this->ipMessage = '';
        unset($operations[self::IP_ADDRESSES]);

        if ($ipAddresses && !$dryRun && null === $batchSize) {
            throw new \InvalidArgumentException('The ip_addresses cleanup needs a batch size.');
        }

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

        $unlinkSegments       = $this->unlinkSegments && ($operations[self::EMAIL_STATS] ?? false);
        $this->unlinkedLinks  = 0;
        $this->unlinkedEmails = [];
        $this->keptEmails     = [];

        if ($unlinkSegments) {
            if (!$dryRun && null === $batchSize) {
                throw new \InvalidArgumentException('Removing the segments needs a batch size.');
            }

            $this->prepareSegmentUnlink($dryRun, $excludeEmailIds);
        }

        $result = [
            self::CAMPAIGN_LEAD_EVENTS => 0,
            self::LEAD_EVENTS          => 0,
            self::EMAIL_STATS          => 0,
            self::EMAIL_STATS_TOKENS   => 0,
            self::EMAIL_STATS_DEVICES  => 0,
            self::PAGE_HITS            => 0,
        ];

        $useSingleTransaction = ($dryRun || null === $batchSize) && in_array(true, $operations, true);

        if ($useSingleTransaction) {
            $this->connection->beginTransaction();
        }

        if ($dryRun) {
            if ($unlinkSegments) {
                $this->countSegmentLinks();
            }

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

        if ($ipAddresses) {
            $operations[self::IP_ADDRESSES] = true;
            $result[self::IP_ADDRESSES]     = 0 === $this->rowsLeft ? 0 : $this->cleanIpAddresses($dryRun, $batchSize ?? 0, $output);
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

        if ($unlinkSegments) {
            $message .= $this->segmentMessage($dryRun);
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

        return $message.$this->ipMessage;
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
     * Under a row limit, a batch selects no more rows than the limit has left, and the write never goes past it.
     * Each batch is locked, checked and written by its ID range and its conditions, never by a list of IDs:
     * a long list of IDs can make MySQL give up the range analysis on a table with many indexes and scan the whole table.
     * A selected batch is written in windows of fewer than MAX_ID_SPAN IDs, so rows spread thinly among rows the conditions keep
     * do not lock a long stretch of them, and every window of one select is written before the next select.
     */
    private function runOperationInBatches(string $operation, int $batchSize, OutputInterface $output): int
    {
        $table        = $this->dbPrefix.(self::EMAIL_STATS_TOKENS === $operation ? self::EMAIL_STATS : $operation);
        $idColumn     = $table.'.id';
        $template     = str_replace(self::PREFIX, $this->dbPrefix, $this->queriesTemplate[$operation]);
        $firstNewerId = $this->findFirstNewerId($operation, $table);
        $idBound      = null === $firstNewerId ? '' : ' AND '.$idColumn.' < '.$firstNewerId;
        $idRange      = ' AND '.$idColumn.' BETWEEN :fromId AND :toId';
        $rows         = str_replace(self::SET, '', $template).$idRange;
        $selectSql    = 'SELECT '.$idColumn.' FROM '.str_replace(self::SET, '', $template).' AND '.$idColumn.' > :lastId'.$idBound.' ORDER BY '.$idColumn.' LIMIT ';
        $statements   = [
            'table'   => $table,
            'match'   => 'SELECT '.$idColumn.' FROM '.$rows.' ORDER BY '.$idColumn.' FOR UPDATE',
            'write'   => 'DELETE LOW_PRIORITY '.$table.' FROM '.$template.$idRange,
            'archive' => $this->archive ? $this->prepareArchive($operation, $table, $rows) : null,
            'emails'  => $this->unlinkSegments && self::EMAIL_STATS === $operation ? 'SELECT DISTINCT '.$table.'.email_id FROM '.$rows : null,
        ];

        if (array_key_exists($operation, $this->update)) {
            $statements['write'] = 'UPDATE LOW_PRIORITY '.str_replace(self::SET, $this->update[$operation], $template).$idRange;
        }

        if ($output->isVerbose()) {
            $output->writeln($selectSql.$batchSize);
            $output->writeln($statements['write']);
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

            foreach ($this->windowsOf($ids) as $window) {
                $range       = ['fromId' => $window[0], 'toId' => $window[count($window) - 1]];
                $writtenRows = $this->writeBatch($operation, $statements, $range);
                $affectedRows += $writtenRows;
                $lastId = $range['toId'];

                if (null !== $this->rowsLeft) {
                    $this->rowsLeft = max(0, $this->rowsLeft - $writtenRows);
                }

                if (0 === $this->rowsLeft) {
                    break;
                }
            }
        } while (count($ids) === $limit && 0 !== $this->rowsLeft);

        return $affectedRows;
    }

    /**
     * Splits IDs in ascending order into windows that each span fewer than MAX_ID_SPAN IDs, so one select feeds every window.
     *
     * @param list<int> $ids
     *
     * @return list<non-empty-list<int>>
     */
    private function windowsOf(array $ids): array
    {
        $windows = [];

        foreach ($ids as $id) {
            $last = array_key_last($windows);

            if (null === $last || $id >= $windows[$last][0] + self::MAX_ID_SPAN) {
                $windows[] = [$id];
            } else {
                $windows[$last][] = $id;
            }
        }

        return $windows;
    }

    /**
     * Creates the archive tables an operation needs, and returns the statements each batch uses to copy its rows.
     * A delete also copies the rows of each child table that a foreign key cascade empties with it.
     * The ip_addresses cleanup has no child rows to copy, because it deletes only addresses that no table uses, so no foreign key acts on its delete.
     * The archive tables are made with CREATE TABLE ... SELECT, so they have the same columns as their source, with no keys, and generated columns become plain ones.
     * Rows are copied by column position, so an archive table whose columns differ from its source in name, order, type, size, nullability,
     * character set or collation is rejected. Without strict SQL mode a narrower column would cut values with only a warning.
     *
     * @return array{copy: string, children: list<array{table: string, source: string, column: string}>}
     */
    private function prepareArchive(string $operation, string $table, string $rows): array
    {
        $children = [];

        if (!array_key_exists($operation, $this->update) && self::IP_ADDRESSES !== $operation) {
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
            'copy'     => 'INSERT INTO '.$archiveTable.' SELECT '.$table.'.* FROM '.$rows,
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
     * Returns each column's name, type with its size, nullability, character set and collation, in table order.
     *
     * @return list<string>
     */
    private function columnsOf(string $table): array
    {
        return array_map(strval(...), $this->connection->executeQuery(
            "SELECT CONCAT_WS(' ', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION",
            ['table' => $table],
            ['table' => \PDO::PARAM_STR]
        )->fetchFirstColumn());
    }

    /**
     * Locks, checks and writes one batch in a single transaction, and with the archive on copies it first.
     * It locks the rows of the batch's ID range that still match, in ID order. If more match than the row limit has left,
     * the range ends at the last row the limit allows, so a row that started to match after the select cannot take the run past it.
     * With the segment unlink on, it locks the variant and translation families of the batch's emails. If one of them has to stay whole,
     * it rolls back and writes the same range again without that family's emails. Otherwise it removes their segments before anything else is written.
     * With the archive on it then locks and copies their child rows and copies the rows themselves.
     * Every row stays locked from its copy to the write, whatever the isolation level, so the archive holds the values that were removed.
     * If the write or a copy counts differently from the rows locked, the batch is rolled back.
     *
     * @param array{table: string, match: string, write: string, archive: array{copy: string, children: list<array{table: string, source: string, column: string}>}|null, emails: string|null} $statements
     * @param array{fromId: int, toId: int}                                                                                                                                                    $range
     */
    private function writeBatch(string $operation, array $statements, array $range): int
    {
        $batchTypes = ['batchIds' => ArrayParameterType::INTEGER];
        $params     = $this->params[$operation] + $range;
        $types      = $this->types[$operation] + self::ID_RANGE_TYPES;
        $archive    = $statements['archive'];

        $this->connection->beginTransaction();
        $familyKept = false;

        try {
            $matchingIds = array_map(intval(...), $this->connection->executeQuery($statements['match'], $params, $types)->fetchFirstColumn());

            if (null !== $this->rowsLeft && count($matchingIds) > $this->rowsLeft) {
                $matchingIds    = array_slice($matchingIds, 0, $this->rowsLeft);
                $params['toId'] = $matchingIds[count($matchingIds) - 1];
            }

            $writtenRows = 0;

            if ([] !== $matchingIds && null !== $statements['emails']) {
                $familyKept = !$this->unlinkSegmentsOf($statements['emails'], $params, $types);
            }

            if ([] !== $matchingIds && !$familyKept) {
                foreach (null === $archive ? [] : $archive['children'] as $child) {
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

                $copiedRows  = null === $archive ? null : $this->connection->executeQuery($archive['copy'], $params, $types)->rowCount();
                $writtenRows = $this->connection->executeQuery($statements['write'], $params, $types)->rowCount();

                if (null !== $copiedRows && ($copiedRows !== count($matchingIds) || $writtenRows !== $copiedRows)) {
                    throw new \RuntimeException('Archive check failed on '.$statements['table'].': '.$copiedRows.' rows copied, '.$writtenRows.' written, '.count($matchingIds).' expected. The batch was rolled back.');
                }

                if ($writtenRows !== count($matchingIds)) {
                    throw new \RuntimeException('Batch check failed on '.$statements['table'].': '.$writtenRows.' rows written, '.count($matchingIds).' expected. The batch was rolled back.');
                }
            }

            if (!$familyKept) {
                $this->connection->commit();
            }
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();
            throw $throwable;
        }

        if ($familyKept) {
            $this->connection->rollBack();

            return $this->writeBatch($operation, $statements, $range);
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
     * Gets the email_stats cleanup ready to remove segments. It adds the condition that keeps the rows of the closed emails whose family has to stay whole,
     * which starts with only the ID 0, as no email has it, and grows as the batches find such families.
     * Outside a dry run it also creates the archive table for the removed segment links, and rejects one whose columns no longer match.
     *
     * @param bool           $dryRun          true to only count, so no table is created
     * @param list<int>|null $excludeEmailIds the emails whose rows the run keeps, so their whole family keeps its rows and segments too
     */
    private function prepareSegmentUnlink(bool $dryRun, ?array $excludeEmailIds): void
    {
        $this->protectedEmails = $excludeEmailIds ?? [];

        $this->queriesTemplate[self::EMAIL_STATS] .= ' AND ('.self::PREFIX.'email_stats.email_id IS NULL OR '.self::PREFIX.'email_stats.email_id NOT IN (:keptFamilyEmailIds))';
        $this->params[self::EMAIL_STATS]['keptFamilyEmailIds'] = [0];
        $this->types[self::EMAIL_STATS]['keptFamilyEmailIds']  = ArrayParameterType::INTEGER;

        if (!$dryRun) {
            $archiveTable = $this->archiveTableName(self::EMAIL_LIST_XREF);
            $source       = $this->dbPrefix.self::EMAIL_LIST_XREF;

            $this->connection->executeQuery('CREATE TABLE IF NOT EXISTS '.$archiveTable.' SELECT * FROM '.$source.' LIMIT 0', [], []);

            if ($this->columnsOf($archiveTable) !== $this->columnsOf($source)) {
                throw new \RuntimeException('Cannot keep the removed segment links in '.$archiveTable.', because its columns no longer match '.$source.'. Rename or drop it, and the next run makes a new one.');
            }
        }
    }

    /**
     * Reads every email in the variant and translation family of an email, with whether each one is closed.
     * With $lock the rows are locked in share mode until the transaction ends, so no email of the family can be published, moved or added to it before then.
     *
     * @param int  $emailId the email to start from
     * @param bool $lock    true to lock the rows it reads
     *
     * @return array<int, bool> each family member's ID, with true when it is closed
     */
    private function readFamily(int $emailId, bool $lock): array
    {
        $members = [];
        $queried = [];
        $next    = [$emailId];

        while ([] !== $next) {
            foreach ($next as $id) {
                $queried[$id] = true;
            }

            $rows = $this->connection->executeQuery(
                'SELECT id, variant_parent_id, translation_parent_id, (is_published = 0 OR publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AS closed FROM '.$this->dbPrefix.'emails WHERE id IN (:ids) OR variant_parent_id IN (:variantParents) OR translation_parent_id IN (:translationParents)'.($lock ? ' LOCK IN SHARE MODE' : ''),
                ['ids' => $next, 'variantParents' => $next, 'translationParents' => $next, 'daysOld' => $this->params[self::EMAIL_STATS]['daysOld']],
                ['ids' => ArrayParameterType::INTEGER, 'variantParents' => ArrayParameterType::INTEGER, 'translationParents' => ArrayParameterType::INTEGER, 'daysOld' => \PDO::PARAM_INT]
            )->fetchAllAssociative();

            $found = [];

            foreach ($rows as $row) {
                $id           = (int) $row['id'];
                $members[$id] = 1 === (int) $row['closed'];

                foreach ([$id, $row['variant_parent_id'], $row['translation_parent_id']] as $relatedId) {
                    if (null !== $relatedId && !isset($queried[(int) $relatedId])) {
                        $found[(int) $relatedId] = true;
                    }
                }
            }

            $next = array_keys($found);
        }

        return $members;
    }

    /**
     * Tells whether a family keeps all its rows and segments, because one of its emails is still open or is in the excluded emails.
     *
     * @param array<int, bool> $family each family member's ID, with true when it is closed
     *
     * @return bool true when the family has to stay whole
     */
    private function mustStayWhole(array $family): bool
    {
        foreach ($family as $id => $closed) {
            if (!$closed || in_array($id, $this->protectedEmails, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sorts the emails of a set of rows into the families that keep their rows, whose closed emails are added to $this->keptEmails,
     * and the families that lose their segments.
     *
     * @param array<int, mixed> $emailIds the email ID of each row, where null stands for a deleted email
     * @param bool              $lock     true to lock the families it reads
     *
     * @return array{members: list<int>, kept: list<int>} the emails that lose their segments, and the closed emails newly kept for their family
     */
    private function sortFamilies(array $emailIds, bool $lock): array
    {
        $seen    = [];
        $members = [];
        $kept    = [];

        foreach ($emailIds as $emailId) {
            if (null === $emailId || isset($seen[(int) $emailId])) {
                continue;
            }

            $family = $this->readFamily((int) $emailId, $lock);
            $seen += $family;

            if (!$this->mustStayWhole($family)) {
                $members += $family;

                continue;
            }

            foreach ($family as $id => $closed) {
                if ($closed && !isset($this->keptEmails[$id])) {
                    $this->keptEmails[$id] = true;
                    $kept[]                = $id;
                }
            }
        }

        return ['members' => array_keys($members), 'kept' => $kept];
    }

    /**
     * Reads the segment links of these emails, one email ID per link.
     *
     * @param list<int> $emailIds the emails to read
     * @param bool      $lock     true to lock the links until the transaction ends
     *
     * @return list<int> the email ID of each link
     */
    private function readSegmentLinks(array $emailIds, bool $lock): array
    {
        if ([] === $emailIds) {
            return [];
        }

        return array_map(intval(...), $this->connection->executeQuery(
            'SELECT email_id FROM '.$this->dbPrefix.self::EMAIL_LIST_XREF.' WHERE email_id IN (:emailIds)'.($lock ? ' FOR UPDATE' : ''),
            ['emailIds' => $emailIds],
            ['emailIds' => ArrayParameterType::INTEGER]
        )->fetchFirstColumn());
    }

    /**
     * Counts, on a dry run, the segment links the email_stats cleanup would remove and the emails they belong to,
     * and keeps the rows of the closed emails whose family has to stay whole, so the counts that follow leave them out.
     */
    private function countSegmentLinks(): void
    {
        $emailIds = $this->connection->executeQuery(
            'SELECT DISTINCT '.$this->dbPrefix.'email_stats.email_id FROM '.str_replace([self::SET, self::PREFIX], ['', $this->dbPrefix], $this->queriesTemplate[self::EMAIL_STATS]),
            $this->params[self::EMAIL_STATS],
            $this->types[self::EMAIL_STATS]
        )->fetchFirstColumn();

        $families = $this->sortFamilies($emailIds, false);

        if ([] !== $this->keptEmails) {
            $this->params[self::EMAIL_STATS]['keptFamilyEmailIds'] = array_keys($this->keptEmails);
        }

        $links               = $this->readSegmentLinks($families['members'], false);
        $this->unlinkedLinks = count($links);

        foreach ($links as $emailId) {
            $this->unlinkedEmails[$emailId] = true;
        }
    }

    /**
     * Removes the segments of the emails whose email_stats this batch deletes, and of their variants and translations, in the batch's transaction.
     * The families are read with locks first. If one of them has to stay whole, nothing is removed, its closed emails join the kept emails,
     * and the caller rolls back and writes the batch again without them.
     * The links are locked and copied to the archive table before they are removed. If the copy or the delete counts differently from the links locked, the batch is rolled back.
     *
     * @param string                                    $emailsSql selects the email ID of each row the batch deletes
     * @param array<string, int|list<int>|list<string>> $params    the batch's parameters
     * @param array<string, int>                        $types     the batch's parameter types
     *
     * @return bool false when a family has to stay whole and the batch must be written again
     */
    private function unlinkSegmentsOf(string $emailsSql, array $params, array $types): bool
    {
        $families = $this->sortFamilies($this->connection->executeQuery($emailsSql, $params, $types)->fetchFirstColumn(), true);

        if ([] !== $families['kept']) {
            $this->params[self::EMAIL_STATS]['keptFamilyEmailIds'] = array_keys($this->keptEmails);

            return false;
        }

        $emailIds = $families['members'];
        $links    = $this->readSegmentLinks($emailIds, true);

        if ([] === $links) {
            return true;
        }

        $source     = $this->dbPrefix.self::EMAIL_LIST_XREF;
        $batch      = ['emailIds' => $emailIds];
        $batchTypes = ['emailIds' => ArrayParameterType::INTEGER];
        $copied     = $this->connection->executeQuery('INSERT INTO '.$this->archiveTableName(self::EMAIL_LIST_XREF).' SELECT * FROM '.$source.' WHERE email_id IN (:emailIds)', $batch, $batchTypes)->rowCount();
        $removed    = $this->connection->executeQuery('DELETE FROM '.$source.' WHERE email_id IN (:emailIds)', $batch, $batchTypes)->rowCount();

        if ($copied !== count($links) || $removed !== count($links)) {
            throw new \RuntimeException('Segment check failed on '.$source.': '.$copied.' links copied, '.$removed.' removed, '.count($links).' expected. The batch was rolled back.');
        }

        $this->unlinkedLinks += $removed;

        foreach ($links as $emailId) {
            $this->unlinkedEmails[$emailId] = true;
        }

        return true;
    }

    /**
     * Describes the segment links the email_stats cleanup removed, or would remove on a dry run, and the closed emails it kept for their family.
     *
     * @param bool $dryRun true when the run only counted
     *
     * @return string the sentences to add to the run's message, each starting with a space
     */
    private function segmentMessage(bool $dryRun): string
    {
        $message = ' '.$this->unlinkedLinks.' segment links of '.count($this->unlinkedEmails).' emails '.($dryRun ? 'would be removed.' : 'were removed first and kept in '.$this->archiveTableName(self::EMAIL_LIST_XREF).'.');

        if ([] !== $this->keptEmails) {
            $message .= ' '.count($this->keptEmails).' closed emails keep their email_stats, because a variant or translation of each is still open or excluded.';
        }

        return $message;
    }

    /**
     * Unserializes the filters of a segment, which Mautic stores as a serialized PHP array.
     *
     * @param int   $segmentId the segment the filters belong to
     * @param mixed $raw       the stored value of its filters column
     *
     * @return array<mixed> the filters, or none when the column holds null
     */
    private function filtersOf(int $segmentId, mixed $raw): array
    {
        if (null === $raw || 'N;' === $raw) {
            return [];
        }

        $filters = false;

        if (is_string($raw)) {
            set_error_handler(static fn (): bool => true);

            try {
                $filters = unserialize($raw, ['allowed_classes' => false]);
            } finally {
                restore_error_handler();
            }
        }

        if (!is_array($filters)) {
            throw new \UnexpectedValueException('The filters of segment '.$segmentId.' cannot be read.');
        }

        return $filters;
    }

    /**
     * Lists the filters of a segment one by one, with the filters that a custom operator filter holds in its properties in its place.
     * Rejects a filter that is not an array or has no field name, because what it reads cannot be known.
     *
     * @param int          $segmentId the segment the filters belong to
     * @param array<mixed> $filters   the filters as stored
     *
     * @return list<array<mixed>> the filters, each with a string field name
     */
    private function flatFilters(int $segmentId, array $filters): array
    {
        $flat = [];

        foreach ($filters as $filter) {
            if (is_array($filter) && self::SEGMENT_CUSTOM_OPERATOR === ($filter['operator'] ?? null) && is_array($filter['properties'] ?? null)) {
                array_push($flat, ...$this->flatFilters($segmentId, $filter['properties']));

                continue;
            }

            if (!is_array($filter) || !isset($filter['field']) || !is_string($filter['field'])) {
                throw new \UnexpectedValueException('Segment '.$segmentId.' has a filter that cannot be read.');
            }

            $flat[] = $filter;
        }

        return $flat;
    }

    /**
     * Reads the email IDs that a filter names, from its properties and from the older filter key.
     *
     * @param array<mixed> $filter one filter of a segment
     *
     * @return list<int>|null the IDs, or null when a value is not an email ID
     */
    private function emailIdsOf(array $filter): ?array
    {
        $ids = [];

        foreach ([$filter['properties']['filter'] ?? null, $filter['filter'] ?? null] as $value) {
            foreach (is_array($value) ? $value : [$value] as $item) {
                if (null === $item || '' === $item) {
                    continue;
                }

                if (is_string($item) && ctype_digit($item)) {
                    $item = (int) $item;
                }

                if (!is_int($item) || $item < 1) {
                    return null;
                }

                $ids[] = $item;
            }
        }

        return $ids;
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

    /**
     * Deletes the ip_addresses rows that no table uses in this run's slice, or counts them on a dry run.
     * Before the first batch it lowers the session's lock waits, and puts them back when the run ends or fails.
     *
     * @param bool            $dryRun    true to count only
     * @param int             $batchSize the most rows one batch deletes, unused on a dry run
     * @param OutputInterface $output    prints the statements and one line per block when verbose
     *
     * @return int how many rows were deleted, or would be on a dry run
     */
    private function cleanIpAddresses(bool $dryRun, int $batchSize, OutputInterface $output): int
    {
        if ($this->connection->isTransactionActive()) {
            throw new \LogicException('The ip_addresses cleanup cannot run inside an open transaction.');
        }

        $table = $this->dbPrefix.self::IP_ADDRESSES;
        $copy  = $this->archive ? $this->prepareArchive(self::IP_ADDRESSES, $table, $table.' WHERE '.$table.'.id IN (:batchIds)')['copy'] : null;
        $found = $this->findAddressUsers($table, $output);

        $unused = implode(' AND ', array_map(static function (string $user): string {
            [$userTable, $column] = explode('.', $user, 2);

            return 'NOT EXISTS (SELECT 1 FROM '.$userTable.' u WHERE u.'.$column.' = a.id)';
        }, $found['users']));

        $max    = $this->connection->executeQuery('SELECT MAX(id) FROM '.$table, [], [])->fetchOne();
        $upper  = null === $max ? 0 : (int) $max - self::IP_KEEP_NEWEST;
        $ranges = $this->ipRanges($upper);

        $selectSql  = 'SELECT a.id FROM '.$table.' a WHERE a.id > :lastId AND a.id <= :toId AND '.$unused.' ORDER BY a.id LIMIT ';
        $countSql   = 'SELECT COUNT(*) FROM '.$table.' a WHERE a.id > :lastId AND a.id <= :toId AND '.$unused;
        $statements = [
            'table'  => $table,
            'lock'   => 'SELECT id FROM '.$table.' WHERE id IN (:batchIds) ORDER BY id FOR UPDATE SKIP LOCKED',
            'check'  => 'SELECT a.id FROM '.$table.' a WHERE a.id IN (:batchIds) AND '.$unused.' ORDER BY a.id',
            'copy'   => $copy,
            'delete' => 'DELETE LOW_PRIORITY FROM '.$table.' WHERE '.$table.'.id IN (:batchIds)',
        ];

        if ($output->isVerbose()) {
            if ($upper >= 1) {
                $output->writeln('Checking ip_addresses IDs up to '.$upper.', slice '.$this->ipSlice.' of '.$this->ipSlices.', in blocks of '.self::IP_SLICE_BLOCK.'. The newest '.self::IP_KEEP_NEWEST.' IDs are left out.');
            }

            $printed = $dryRun ? [$countSql] : array_filter([$selectSql.$batchSize, self::IP_ISOLATION, $statements['lock'], $statements['check'], $statements['copy'], $statements['delete']]);

            foreach ($printed as $sql) {
                $output->writeln($sql);
            }
        }

        $total   = 0;
        $kept    = 0;
        $reached = 0;
        $saved   = null;

        try {
            foreach ($ranges as [$from, $to]) {
                if (0 === $this->rowsLeft) {
                    break;
                }

                $rangeTotal = 0;
                $rangeKept  = 0;
                $lastId     = $from - 1;

                while ($lastId < $to && 0 !== $this->rowsLeft) {
                    $window = ['lastId' => $lastId, 'toId' => min($lastId + self::IP_SCAN_SPAN, $to)];

                    if ($dryRun) {
                        $rangeTotal += (int) $this->connection->executeQuery($countSql, $window, self::IP_WINDOW_TYPES)->fetchOne();
                        $lastId = $window['toId'];

                        continue;
                    }

                    do {
                        $limit = null === $this->rowsLeft ? $batchSize : min($batchSize, $this->rowsLeft);
                        $ids   = array_map(intval(...), $this->connection->executeQuery($selectSql.$limit, $window, self::IP_WINDOW_TYPES)->fetchFirstColumn());

                        if ([] !== $ids) {
                            $saved ??= $this->limitIpLockWaits($output);
                            $written = $this->writeIpBatch($statements, $ids, $found);
                            $rangeTotal += $written;
                            $rangeKept += count($ids) - $written;

                            if (null !== $this->rowsLeft) {
                                $this->rowsLeft = max(0, $this->rowsLeft - $written);
                            }
                        }

                        $window['lastId'] = [] !== $ids && count($ids) === $limit ? $ids[count($ids) - 1] : $window['toId'];
                    } while ($window['lastId'] < $window['toId'] && 0 !== $this->rowsLeft);

                    $lastId = $window['lastId'];
                }

                if ($output->isVerbose()) {
                    $output->writeln('IDs '.$from.' to '.$lastId.': '.($dryRun ? $rangeTotal.' would be deleted.' : $rangeTotal.' deleted, '.$rangeKept.' kept for a later run.'));
                }

                $total += $rangeTotal;
                $kept += $rangeKept;
                $reached = $lastId;
            }
        } catch (\Throwable $throwable) {
            if (null !== $saved) {
                try {
                    $this->restoreIpLockWaits($saved, $output);
                } catch (\Throwable) {
                }
            }

            throw $throwable;
        }

        if (null !== $saved) {
            $this->restoreIpLockWaits($saved, $output);
        }

        if ($upper < 1) {
            $this->ipMessage = ' The ip_addresses cleanup checked no IDs, because none is older than the newest '.self::IP_KEEP_NEWEST.'.';
        } elseif ([] !== $ranges && $reached < $ranges[array_key_last($ranges)][1]) {
            $this->ipMessage = ' The ip_addresses cleanup stopped at ID '.$reached.', before it checked '.(1 === $this->ipSlices ? 'every ID' : 'all of slice '.$this->ipSlice.' of '.$this->ipSlices.' of the IDs').' up to '.$upper.'.';
        } elseif (1 === $this->ipSlices) {
            $this->ipMessage = ' The ip_addresses cleanup checked every ID up to '.$upper.' and left out the newest '.self::IP_KEEP_NEWEST.' IDs.';
        } else {
            $this->ipMessage = ' The ip_addresses cleanup checked slice '.$this->ipSlice.' of '.$this->ipSlices.' of the IDs up to '.$upper.' and left out the newest '.self::IP_KEEP_NEWEST.' IDs.';
        }

        if (!$dryRun && $kept > 0) {
            $this->ipMessage .= ' '.$kept.' ip_addresses rows were in use or locked when their batch ran, so they were kept for a later run.';
        }

        return $total;
    }

    /**
     * Finds every table column that can hold an ip_addresses ID, and adds an index to each archive table column that has none.
     * A user is a table with a foreign key to ip_addresses, or a housekeeping_archive_ table, whose rows come only from tables with such a key.
     * Any other table with the same column stops the run, because a row added there takes no lock on its address and so does not wait for the delete.
     *
     * @param string          $table  the ip_addresses table with its prefix
     * @param OutputInterface $output prints the users and each added index when verbose
     *
     * @return array{keys: list<string>, columns: list<string>, users: list<string>} the keys and the users as table.column in sorted order, and the key column names
     */
    private function findAddressUsers(string $table, OutputInterface $output): array
    {
        $found    = $this->readAddressKeys($table);
        $users    = $this->listAddressColumns($found['columns']);
        $keyUsers = array_flip($found['keys']);
        $archives = [];

        foreach ($users as $user) {
            foreach (explode('.', $user, 2) as $name) {
                if (1 !== preg_match(self::IP_PLAIN_NAME, $name)) {
                    throw new \RuntimeException('Cannot clean '.$table.', because '.$name.' is not a plain table or column name.');
                }
            }

            if (isset($keyUsers[$user])) {
                continue;
            }

            if (!str_starts_with($user, $this->dbPrefix.self::ARCHIVE_PREFIX)) {
                throw new \RuntimeException('Cannot clean '.$table.', because '.$user.' can hold an address ID but has no foreign key to it, so a row added there would not wait for the delete. Add a foreign key on it to the id of '.$table.', or drop the table.');
            }

            $archives[] = $user;
        }

        if ([] !== $archives) {
            $indexed = [];
            $indexes = $this->connection->executeQuery(
                'SELECT DISTINCT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND SEQ_IN_INDEX = 1 AND COLUMN_NAME IN (:columns)',
                ['columns' => $found['columns']],
                ['columns' => ArrayParameterType::STRING]
            )->fetchAllAssociative();

            foreach ($indexes as $index) {
                $indexed[$index['table_name'].'.'.$index['column_name']] = true;
            }

            $missing = array_values(array_filter($archives, static fn (string $archive): bool => !isset($indexed[$archive])));

            if ([] !== $missing) {
                $this->addArchiveIndexes($missing, $output);
            }
        }

        if ($output->isVerbose()) {
            $output->writeln('Tables that can hold an ip_addresses ID: '.implode(', ', $users));
        }

        return ['keys' => $found['keys'], 'columns' => $found['columns'], 'users' => $users];
    }

    /**
     * Reads the foreign keys that point at ip_addresses, and rejects any that the cleanup cannot check.
     *
     * @param string $table the ip_addresses table with its prefix
     *
     * @return array{keys: list<string>, columns: list<string>} each key as table.column in sorted order, and the key column names in sorted order
     */
    private function readAddressKeys(string $table): array
    {
        $rows = $this->connection->executeQuery(
            'SELECT k.TABLE_SCHEMA = k.REFERENCED_TABLE_SCHEMA AS same_database, k.TABLE_SCHEMA AS child_schema, k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column, k.REFERENCED_COLUMN_NAME AS referenced_column FROM information_schema.KEY_COLUMN_USAGE k WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = :table',
            ['table' => $table],
            ['table' => \PDO::PARAM_STR]
        )->fetchAllAssociative();

        if ([] === $rows) {
            throw new \RuntimeException('Cannot clean '.$table.', because no foreign key points at it, so the tables that use an address cannot be found.');
        }

        $keys    = [];
        $columns = [];

        foreach ($rows as $key) {
            if (1 !== (int) $key['same_database']) {
                throw new \RuntimeException('Cannot clean '.$table.', because '.$key['child_schema'].'.'.$key['child_table'].' in another database points at it.');
            }

            if ('id' !== $key['referenced_column']) {
                throw new \RuntimeException('Cannot clean '.$table.', because the key from '.$key['child_table'].'.'.$key['child_column'].' points at its '.$key['referenced_column'].' column instead of its id.');
            }

            $childTable  = (string) $key['child_table'];
            $childColumn = (string) $key['child_column'];

            foreach ([$childTable, $childColumn] as $name) {
                if (1 !== preg_match(self::IP_PLAIN_NAME, $name)) {
                    throw new \RuntimeException('Cannot clean '.$table.', because '.$name.' is not a plain table or column name.');
                }
            }

            $keys[]    = $childTable.'.'.$childColumn;
            $columns[] = $childColumn;
        }

        $keys    = array_values(array_unique($keys));
        $columns = array_values(array_unique($columns));
        sort($keys, SORT_STRING);
        sort($columns, SORT_STRING);

        return ['keys' => $keys, 'columns' => $columns];
    }

    /**
     * Lists the base tables of this database that have one of these columns.
     *
     * @param list<string> $columns the column names that point at ip_addresses
     *
     * @return list<string> each table and column as table.column, in sorted order
     */
    private function listAddressColumns(array $columns): array
    {
        $list = array_map(
            static fn (array $row): string => $row['table_name'].'.'.$row['column_name'],
            $this->connection->executeQuery(
                "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND c.COLUMN_NAME IN (:columns)",
                ['columns' => $columns],
                ['columns' => ArrayParameterType::STRING]
            )->fetchAllAssociative()
        );

        sort($list, SORT_STRING);

        return $list;
    }

    /**
     * Adds an index on the address column of each of these archive tables, waiting at most IP_LOCK_WAIT_TIMEOUT seconds for each table.
     *
     * @param non-empty-list<string> $users  the archive tables and columns as table.column
     * @param OutputInterface        $output prints each statement when verbose
     */
    private function addArchiveIndexes(array $users, OutputInterface $output): void
    {
        $savedTimeout = (int) $this->connection->executeQuery('SELECT @@SESSION.lock_wait_timeout', [], [])->fetchOne();
        $statements   = ['SET SESSION lock_wait_timeout = '.self::IP_LOCK_WAIT_TIMEOUT];

        foreach ($users as $user) {
            [$userTable, $column] = explode('.', $user, 2);
            $statements[]         = 'ALTER TABLE '.$userTable.' ADD INDEX '.self::IP_INDEX_PREFIX.$column.' ('.$column.'), ALGORITHM=INPLACE, LOCK=NONE';
        }

        $restore = 'SET SESSION lock_wait_timeout = '.$savedTimeout;

        try {
            foreach ($statements as $sql) {
                if ($output->isVerbose()) {
                    $output->writeln($sql);
                }

                $this->connection->executeStatement($sql);
            }
        } finally {
            if ($output->isVerbose()) {
                $output->writeln($restore);
            }

            $this->connection->executeStatement($restore);
        }
    }

    /**
     * Lowers this session's row and metadata lock waits to IP_LOCK_WAIT_TIMEOUT seconds, so a batch never holds its locks for long while it waits.
     *
     * @param OutputInterface $output prints the statement when verbose
     *
     * @return array{0: int, 1: int} the session's innodb_lock_wait_timeout and lock_wait_timeout before the change
     */
    private function limitIpLockWaits(OutputInterface $output): array
    {
        $saved = $this->connection->executeQuery('SELECT @@SESSION.innodb_lock_wait_timeout, @@SESSION.lock_wait_timeout', [], [])->fetchNumeric();

        if (false === $saved) {
            throw new \RuntimeException('Cannot read the lock wait timeouts of this session.');
        }

        $sql = 'SET SESSION innodb_lock_wait_timeout = '.self::IP_LOCK_WAIT_TIMEOUT.', lock_wait_timeout = '.self::IP_LOCK_WAIT_TIMEOUT;

        if ($output->isVerbose()) {
            $output->writeln($sql);
        }

        $this->connection->executeStatement($sql);

        return [(int) $saved[0], (int) $saved[1]];
    }

    /**
     * Puts back this session's row and metadata lock waits.
     *
     * @param array{0: int, 1: int} $saved  the innodb_lock_wait_timeout and lock_wait_timeout to put back
     * @param OutputInterface       $output prints the statement when verbose
     */
    private function restoreIpLockWaits(array $saved, OutputInterface $output): void
    {
        $sql = 'SET SESSION innodb_lock_wait_timeout = '.$saved[0].', lock_wait_timeout = '.$saved[1];

        if ($output->isVerbose()) {
            $output->writeln($sql);
        }

        $this->connection->executeStatement($sql);
    }

    /**
     * Lists the ID ranges of this run's slice, one per block of IP_SLICE_BLOCK IDs, up to the given ID.
     *
     * @param int $upper the highest ID to check
     *
     * @return list<array{0: int, 1: int}> the first and last ID of each range, in ID order
     */
    private function ipRanges(int $upper): array
    {
        $ranges = [];

        for ($start = ($this->ipSlice - 1) * self::IP_SLICE_BLOCK; $start < $upper; $start += $this->ipSlices * self::IP_SLICE_BLOCK) {
            $ranges[] = [$start + 1, min($start + self::IP_SLICE_BLOCK, $upper)];
        }

        return $ranges;
    }

    /**
     * Deletes the rows of one batch that are still unused, and tries the batch once more when a deadlock or a lock wait timeout rolled it back.
     * A row that another transaction holds, or that came into use since the select, is left for a later run.
     * The run has lowered the lock waits to IP_LOCK_WAIT_TIMEOUT seconds, so a blocked try gives up its locks after that time.
     *
     * @param array{table: string, lock: string, check: string, copy: string|null, delete: string} $statements the statements of this run
     * @param non-empty-list<int>                                                                  $ids        the IDs the select found unused
     * @param array{keys: list<string>, columns: list<string>, users: list<string>}                $found      the keys and users found at the start of the run
     *
     * @return int how many rows were deleted
     */
    private function writeIpBatch(array $statements, array $ids, array $found): int
    {
        try {
            return $this->tryIpBatch($statements, $ids, $found);
        } catch (RetryableException $exception) {
            $this->logger->warning('The ip_addresses batch from ID '.$ids[0].' was rolled back and runs once more: '.$exception->getMessage());

            return $this->tryIpBatch($statements, $ids, $found);
        }
    }

    /**
     * Runs one try of a batch in a single transaction, and with the archive on copies the rows first. Any error rolls the try back.
     * The try runs at READ COMMITTED, so the foreign key checks of the delete lock no gaps in the tables that use an address.
     * Before the delete it reads the foreign keys and the tables with the address column again, and rolls back when either changed since the start of the run.
     *
     * @param array{table: string, lock: string, check: string, copy: string|null, delete: string} $statements the statements of this run
     * @param non-empty-list<int>                                                                  $ids        the IDs the select found unused
     * @param array{keys: list<string>, columns: list<string>, users: list<string>}                $found      the keys and users found at the start of the run
     *
     * @return int how many rows were deleted
     */
    private function tryIpBatch(array $statements, array $ids, array $found): int
    {
        $batchTypes = ['batchIds' => ArrayParameterType::INTEGER];

        $this->connection->executeStatement(self::IP_ISOLATION);
        $this->connection->beginTransaction();

        try {
            $free    = array_map(intval(...), $this->connection->executeQuery($statements['lock'], ['batchIds' => $ids], $batchTypes)->fetchFirstColumn());
            $written = 0;

            if ([] !== $free) {
                $free = array_map(intval(...), $this->connection->executeQuery($statements['check'], ['batchIds' => $free], $batchTypes)->fetchFirstColumn());
            }

            if ([] !== $free) {
                $current = $this->readAddressKeys($statements['table']);

                if ($current['keys'] !== $found['keys'] || $this->listAddressColumns($current['columns']) !== $found['users']) {
                    throw new \RuntimeException('Cannot clean '.$statements['table'].', because the tables that can hold an address ID changed during the run. The batch was rolled back. Run it again.');
                }

                $copied  = null === $statements['copy'] ? null : $this->connection->executeQuery($statements['copy'], ['batchIds' => $free], $batchTypes)->rowCount();
                $written = $this->connection->executeQuery($statements['delete'], ['batchIds' => $free], $batchTypes)->rowCount();

                if (null !== $copied && ($copied !== count($free) || $written !== $copied)) {
                    throw new \RuntimeException('Archive check failed on '.$statements['table'].': '.$copied.' rows copied, '.$written.' written, '.count($free).' expected. The batch was rolled back.');
                }

                if ($written !== count($free)) {
                    throw new \RuntimeException('Batch check failed on '.$statements['table'].': '.$written.' rows written, '.count($free).' expected. The batch was rolled back.');
                }
            }

            $this->connection->commit();

            return $written;
        } catch (\Throwable $throwable) {
            $this->connection->rollBack();

            throw $throwable;
        }
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
