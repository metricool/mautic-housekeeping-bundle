<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use MauticPlugin\HousekeepingBundle\Integration\Config;
use MauticPlugin\HousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;

final class EventLogCleanupTest extends TestCase
{
    private const COLUMNS_SQL = "SELECT CONCAT_WS(' ', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION";
    private const KEYS_SQL    = 'SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column, k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule, k.CONSTRAINT_SCHEMA = DATABASE() AS same_schema FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = :table ORDER BY k.TABLE_NAME, k.COLUMN_NAME';

    private const CONTACT_COLUMNS_SQL   = 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table';
    private const IDENTIFIER_FIELDS_SQL = "SELECT alias FROM prefix_table_lead_fields WHERE object = 'lead' AND is_unique_identifer = 1";

    /**
     * @dataProvider runProvider
     *
     * @param non-empty-array<string, bool>                                         $operations
     * @param array<array{0: string, 1: array<string, int>, 2: array<string, int>}> $queries
     * @param array<int>                                                            $countRows
     */
    public function testDeleteEventLogEntries(array $operations, array $queries, array $countRows, string $message, bool $dryRun, ?int $campaignId): void
    {
        $statements = [];
        foreach ($countRows as $countRow) {
            $statement = $this->createMock(Result::class);
            $statement->expects($this->once())
                ->method($dryRun ? 'fetchOne' : 'rowCount')
                ->willReturn($countRow);

            $statements[] = $statement;
        }

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly(count($queries)))
            ->method('executeQuery')
            ->willReturnCallback(static function (string $sql, array $params, array $types) use (&$queries, &$statements): Result {
                self::assertCount(count($queries), $statements);
                $query     = array_shift($queries);
                $statement = array_shift($statements);

                self::assertCount(3, $query);
                self::assertSame($sql, $query[0]);
                self::assertSame($params, $query[1]);
                self::assertSame($types, $query[2]);

                return $statement;
            });

        $loggedQueries = [];
        foreach ($queries as $query) {
            $loggedQueries[] = [$query[0]];
        }

        $output = $this->createMock(OutputInterface::class);
        $output->expects($this->exactly(count($queries)))
            ->method('isVerbose')
            ->willReturn(true);
        $output->expects($this->exactly(count($queries)))
            ->method('writeln')
            ->willReturnCallback(static function (string $loggedQuery) use (&$loggedQueries): void {
                $expected = array_shift($loggedQueries);
                self::assertCount(1, $expected);
                self::assertSame($loggedQuery, $expected[0]);
            });

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $logger = $this->createStub(\Psr\Log\LoggerInterface::class);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $logger);
        $this->assertSame($message, $eventLogCleanup->deleteEventLogEntries(4, $campaignId, $dryRun, $operations, $output));
    }

    /**
     * @dataProvider pageHitsExcludeEmailsProvider
     */
    public function testExcludeEmailsOnPageHitsStillDeletesHitsWithoutEmail(bool $dryRun, string $sql, string $resultMethod, string $message): void
    {
        $statement = $this->createMock(Result::class);
        $statement->expects($this->once())
            ->method($resultMethod)
            ->willReturn(5);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                $sql,
                ['daysOld' => 4, 'excludeEmailIds' => [1, 44]],
                ['daysOld' => \PDO::PARAM_INT, 'excludeEmailIds' => ArrayParameterType::INTEGER]
            )
            ->willReturn($statement);

        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        $eventLogCleanup = new EventLogCleanup($connection, 'prefix_table_', $config, $this->createStub(\Psr\Log\LoggerInterface::class));

        $this->assertSame(
            $message,
            $eventLogCleanup->deleteEventLogEntries(4, null, $dryRun, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), [1, 44])
        );
    }

    public static function pageHitsExcludeEmailsProvider(): \Generator
    {
        yield 'dry run' => [
            true,
            'SELECT COUNT(1) as cnt FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND (prefix_table_page_hits.email_id IS NULL OR prefix_table_page_hits.email_id NOT IN (:excludeEmailIds))',
            'fetchOne',
            '5 page_hits rows would have been deleted. This is a dry run.',
        ];

        yield 'real run' => [
            false,
            'DELETE LOW_PRIORITY prefix_table_page_hits FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND (prefix_table_page_hits.email_id IS NULL OR prefix_table_page_hits.email_id NOT IN (:excludeEmailIds))',
            'rowCount',
            '5 page_hits rows have been deleted.',
        ];
    }

    public static function runProvider(): \Generator
    {
        $daysOld = 4;

        yield 'dry run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
                EventLogCleanup::PAGE_HITS            => true,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 23, 4, 55, 57],
            '3 lead_event_log, 14 campaign_lead_event_log, 4 page_hits, 55 email_stats_devices and 57 email_stats rows would have been deleted. 23 email_stats_tokens will be set to NULL. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run email_stats and see email_stats_devices is also cleared' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [31],
            '31 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run single table' => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 67],
            '42 email_stats_devices and 67 email_stats rows would have been deleted. This is a dry run.',
            true,
            null,
        ];

        yield 'dry run all tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 11, 3, 87],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 87 email_stats rows would have been deleted. 11 email_stats_tokens will be set to NULL. This is a dry run.',
            true,
            12235,
        ];

        yield 'dry run two tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'SELECT COUNT(1) as cnt FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows would have been deleted. This is a dry run.',
            true,
            65487,
        ];

        yield 'real run all tables' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
                EventLogCleanup::PAGE_HITS            => true,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY))',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_page_hits FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [3, 14, 32, 21, 55, 41],
            '3 lead_event_log, 14 campaign_lead_event_log, 21 page_hits, 55 email_stats_devices and 41 email_stats rows have been deleted. 32 email_stats_tokens have been set to NULL.',
            false,
            null,
        ];

        yield 'real run single table' => [
            [
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42],
            '42 lead_event_log rows have been deleted.',
            false,
            null,
        ];

        yield 'real run email_stats table to see if email_stats_devices is also cleared' => [
            [
                EventLogCleanup::LEAD_EVENTS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => false,
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [42, 12],
            '42 email_stats_devices and 12 email_stats rows have been deleted.',
            false,
            null,
        ];

        yield 'real run all tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => true,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 12235],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [34, 41, 19, 3, 6],
            '34 lead_event_log, 41 campaign_lead_event_log, 3 email_stats_devices and 6 email_stats rows have been deleted. 19 email_stats_tokens have been set to NULL.',
            false,
            12235,
        ];

        yield 'real run two tables with campaignId' => [
            [
                EventLogCleanup::EMAIL_STATS          => false,
                EventLogCleanup::CAMPAIGN_LEAD_EVENTS => true,
                EventLogCleanup::LEAD_EVENTS          => true,
                EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            ],
            [
                [
                    'DELETE LOW_PRIORITY prefix_table_campaign_lead_event_log FROM prefix_table_campaign_lead_event_log WHERE (prefix_table_campaign_lead_event_log.id NOT IN (SELECT maxId FROM (SELECT MAX(clel2.id) as maxId FROM prefix_table_campaign_lead_event_log clel2 GROUP BY lead_id, campaign_id) as maxIds) AND prefix_table_campaign_lead_event_log.date_triggered < DATE_SUB(NOW(),INTERVAL :daysOld DAY)) AND campaign_id = :cmpId',
                    ['daysOld' => $daysOld, 'cmpId' => 65487],
                    ['daysOld' => \PDO::PARAM_INT, 'cmpId' => \PDO::PARAM_INT],
                ],
                [
                    'DELETE LOW_PRIORITY prefix_table_lead_event_log FROM prefix_table_lead_event_log WHERE date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                    ['daysOld' => $daysOld],
                    ['daysOld' => \PDO::PARAM_INT],
                ],
            ],
            [61, 22],
            '61 campaign_lead_event_log and 22 lead_event_log rows have been deleted.',
            false,
            65487,
        ];
    }

    /**
     * @dataProvider batchProvider
     *
     * @param non-empty-array<string, bool>                                                                            $operations
     * @param list<array{0: string, 1: array<string, int|list<int>>, 2: array<string, int>, 3: list<string|null>|int}> $calls
     */
    public function testBatchSizeProcessesRowsInBatches(array $operations, array $calls, string $message, ?int $maxRows = null, int $batchSize = 2): void
    {
        $batches    = count(array_filter($calls, static fn (array $call): bool => str_contains($call[0], 'FOR UPDATE')));
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly($batches))
            ->method('beginTransaction');
        $connection->expects($this->exactly($batches))
            ->method('commit');
        $connection->expects($this->never())
            ->method('rollBack');
        $connection->expects($this->exactly(count($calls)))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$calls): Result {
                $call = array_shift($calls);

                self::assertSame($call[0], $sql);
                self::assertSame($call[1], $params);
                self::assertSame($call[2], $types);

                $result = $this->createMock(Result::class);
                if (str_contains($call[0], 'MIN(id)')) {
                    $result->method('fetchNumeric')
                        ->willReturn($call[3]);
                } elseif (str_contains($call[0], ':probeId')) {
                    $result->method('fetchOne')
                        ->willReturn($call[3]);
                } elseif (is_array($call[3])) {
                    $result->method('fetchFirstColumn')
                        ->willReturn($call[3]);
                } else {
                    $result->method('rowCount')
                        ->willReturn($call[3]);
                }

                return $result;
            });

        $this->assertSame(
            $message,
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, $operations, $this->createStub(OutputInterface::class), null, $batchSize, $maxRows)
        );
    }

    public static function batchProvider(): \Generator
    {
        $probeTypes  = ['daysOld' => \PDO::PARAM_INT, 'probeId' => \PDO::PARAM_INT];
        $selectTypes = ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT];
        $writeTypes  = ['daysOld' => \PDO::PARAM_INT, 'fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
        $noRows      = [null, null];

        $rangeHits  = 'SELECT MIN(id), MAX(id) FROM prefix_table_page_hits';
        $probeHits  = 'SELECT date_hit >= DATE_SUB(NOW(),INTERVAL :daysOld DAY) FROM prefix_table_page_hits WHERE id >= :probeId ORDER BY id LIMIT 1';
        $selectHits ='SELECT prefix_table_page_hits.id FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_page_hits.id > :lastId ORDER BY prefix_table_page_hits.id LIMIT 2';
        $deleteHits = 'DELETE LOW_PRIORITY prefix_table_page_hits FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_page_hits.id BETWEEN :fromId AND :toId';
        $matchHits  = 'SELECT prefix_table_page_hits.id FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_page_hits.id BETWEEN :fromId AND :toId ORDER BY prefix_table_page_hits.id FOR UPDATE';

        yield 'searches for where the newer rows begin and stops selecting there' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], ['1', '8']],
                [$probeHits, ['daysOld' => 4, 'probeId' => 4], $probeTypes, 0],
                [$probeHits, ['daysOld' => 4, 'probeId' => 6], $probeTypes, 1],
                [$probeHits, ['daysOld' => 4, 'probeId' => 5], $probeTypes, 0],
                [$probeHits, ['daysOld' => 4, 'probeId' => 6], $probeTypes, 1],
                ['SELECT prefix_table_page_hits.id FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_page_hits.id > :lastId AND prefix_table_page_hits.id < 6 ORDER BY prefix_table_page_hits.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['3']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 3, 'toId' => 3], $writeTypes, ['3']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 3, 'toId' => 3], $writeTypes, 1],
            ],
            '1 page_hits rows have been deleted.',
        ];

        yield 'adds no bound when no row is newer than the cutoff' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], ['1', '8']],
                [$probeHits, ['daysOld' => 4, 'probeId' => 4], $probeTypes, 0],
                [$probeHits, ['daysOld' => 4, 'probeId' => 6], $probeTypes, 0],
                [$probeHits, ['daysOld' => 4, 'probeId' => 7], $probeTypes, 0],
                [$probeHits, ['daysOld' => 4, 'probeId' => 8], $probeTypes, 0],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
            ],
            '0 page_hits rows have been deleted.',
        ];

        yield 'stops on a short batch and moves past the last id' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 2],
                [$selectHits, ['daysOld' => 4, 'lastId' => 20], $selectTypes, ['30']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, ['30']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, 1],
            ],
            '3 page_hits rows have been deleted.',
        ];

        yield 'stops on an empty batch' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 2],
                [$selectHits, ['daysOld' => 4, 'lastId' => 20], $selectTypes, []],
            ],
            '2 page_hits rows have been deleted.',
        ];

        yield 'counts only the rows that still match when written' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 1],
                [$selectHits, ['daysOld' => 4, 'lastId' => 20], $selectTypes, []],
            ],
            '1 page_hits rows have been deleted.',
        ];

        yield 'writes nothing when no row matches' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
            ],
            '0 page_hits rows have been deleted.',
        ];

        yield 'updates tokens by id' => [
            [EventLogCleanup::EMAIL_STATS_TOKENS => true],
            [
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats', [], [], $noRows],
                ['SELECT prefix_table_email_stats.id FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['7']],
                ['SELECT prefix_table_email_stats.id FROM prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND prefix_table_email_stats.id BETWEEN :fromId AND :toId ORDER BY prefix_table_email_stats.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 7, 'toId' => 7], $writeTypes, ['7']],
                ['UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 7, 'toId' => 7], $writeTypes, 1],
            ],
            ' 1 email_stats_tokens have been set to NULL.',
        ];

        yield 'qualifies the id on the email_stats join and clears devices first' => [
            [EventLogCleanup::EMAIL_STATS => true],
            [
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats_devices', [], [], $noRows],
                ['SELECT prefix_table_email_stats_devices.id FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats_devices.id > :lastId ORDER BY prefix_table_email_stats_devices.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats', [], [], $noRows],
                ['SELECT prefix_table_email_stats.id FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['5']],
                ['SELECT prefix_table_email_stats.id FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats.id BETWEEN :fromId AND :toId ORDER BY prefix_table_email_stats.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 5, 'toId' => 5], $writeTypes, ['5']],
                ['DELETE LOW_PRIORITY prefix_table_email_stats FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 5, 'toId' => 5], $writeTypes, 1],
            ],
            '0 email_stats_devices and 1 email_stats rows have been deleted.',
        ];

        $selectHitsUpToOne = 'SELECT prefix_table_page_hits.id FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_page_hits.id > :lastId ORDER BY prefix_table_page_hits.id LIMIT 1';
        $limitReached      = ' Stopped at the limit of %d rows for this run. Any rows left wait for the next run.';

        yield 'selects no more rows than the limit has left' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 2],
                [$selectHitsUpToOne, ['daysOld' => 4, 'lastId' => 20], $selectTypes, ['30']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, ['30']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, 1],
            ],
            '3 page_hits rows have been deleted.'.sprintf($limitReached, 3),
            3,
        ];

        yield 'counts against the limit only the rows it writes' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 1],
                [$selectHitsUpToOne, ['daysOld' => 4, 'lastId' => 20], $selectTypes, ['30']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, ['30']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, 1],
            ],
            '2 page_hits rows have been deleted.'.sprintf($limitReached, 2),
            2,
        ];

        yield 'starts no later operation once the limit is reached' => [
            [EventLogCleanup::EMAIL_STATS => true],
            [
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats_devices', [], [], $noRows],
                ['SELECT prefix_table_email_stats_devices.id FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats_devices.id > :lastId ORDER BY prefix_table_email_stats_devices.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['3', '4']],
                ['SELECT prefix_table_email_stats_devices.id FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats_devices.id BETWEEN :fromId AND :toId ORDER BY prefix_table_email_stats_devices.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 3, 'toId' => 4], $writeTypes, ['3', '4']],
                ['DELETE LOW_PRIORITY prefix_table_email_stats_devices FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats_devices.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 3, 'toId' => 4], $writeTypes, 2],
            ],
            '2 email_stats_devices and 0 email_stats rows have been deleted.'.sprintf($limitReached, 2),
            2,
        ];

        yield 'says nothing about the limit when the rows run out first' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 2],
                [$selectHits, ['daysOld' => 4, 'lastId' => 20], $selectTypes, ['30']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, ['30']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 30, 'toId' => 30], $writeTypes, 1],
            ],
            '3 page_hits rows have been deleted.',
            5,
        ];

        yield 'stops at the limit when a row starts to match between the select and the lock' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '15', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 15], $writeTypes, 2],
            ],
            '2 page_hits rows have been deleted.'.sprintf($limitReached, 2),
            2,
        ];

        yield 'keeps each batch within 50,000 IDs' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [$selectHits, ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '50010']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 10], $writeTypes, ['10']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 10], $writeTypes, 1],
                [$matchHits, ['daysOld' => 4, 'fromId' => 50010, 'toId' => 50010], $writeTypes, ['50010']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 50010, 'toId' => 50010], $writeTypes, 1],
                [$selectHits, ['daysOld' => 4, 'lastId' => 50010], $selectTypes, []],
            ],
            '2 page_hits rows have been deleted.',
        ];

        yield 'stops between the windows of one select once the limit is reached' => [
            [EventLogCleanup::PAGE_HITS => true],
            [
                [$rangeHits, [], [], $noRows],
                [str_replace('LIMIT 2', 'LIMIT 3', $selectHits), ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['10', '20', '50010']],
                [$matchHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '15', '20']],
                [$deleteHits, ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 3],
            ],
            '3 page_hits rows have been deleted.'.sprintf($limitReached, 3),
            3,
            3,
        ];
    }

    public function testPlainBatchRollsBackWhenTheWriteDiffersFromTheLock(): void
    {
        $writeTypes = ['daysOld' => \PDO::PARAM_INT, 'fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
        $hitsWhere  = 'prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)';

        $connection = $this->createArchiveConnection([
            ['SELECT MIN(id), MAX(id) FROM prefix_table_page_hits', [], [], [null, null]],
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id > :lastId ORDER BY prefix_table_page_hits.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT], ['10', '20']],
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId ORDER BY prefix_table_page_hits.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
            ['DELETE LOW_PRIORITY prefix_table_page_hits FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 1],
        ], 1, 0, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Batch check failed on prefix_table_page_hits: 1 rows written, 2 expected. The batch was rolled back.');

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), null, 2);
    }

    /**
     * @dataProvider invalidBatchGuardProvider
     */
    public function testRejectsAnInvalidRowLimitOrArchiveBeforeTouchingTheDatabase(?int $batchSize, ?int $maxRows, bool $archive): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())
            ->method('executeQuery');
        $connection->expects($this->never())
            ->method('beginTransaction');

        $this->expectException(\InvalidArgumentException::class);

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), null, $batchSize, $maxRows, $archive);
    }

    public static function invalidBatchGuardProvider(): \Generator
    {
        yield 'row limit without a batch size' => [null, 10, false];

        yield 'row limit of zero' => [2, 0, false];

        yield 'archive without a batch size' => [null, null, true];
    }

    /**
     * @dataProvider archiveProvider
     *
     * @param non-empty-array<string, bool>                                                    $operations
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, int>, 3: mixed}> $calls
     */
    public function testArchiveCopiesEachBatchInTheSameTransactionAsTheWrite(array $operations, array $calls, int $transactions, string $message): void
    {
        $connection = $this->createArchiveConnection($calls, $transactions, $transactions, 0);

        $this->assertSame(
            $message,
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, $operations, $this->createStub(OutputInterface::class), null, 2, null, true)
        );
    }

    public static function archiveProvider(): \Generator
    {
        $noRows      = [null, null];
        $batchTypes  = ['batchIds' => ArrayParameterType::INTEGER];
        $selectTypes = ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT];
        $writeTypes  = ['daysOld' => \PDO::PARAM_INT, 'fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
        $keysTypes   = ['table' => \PDO::PARAM_STR];
        $statsWhere  = 'prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY)';
        $devices     = 'prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY)';

        yield 'copies email_stats with the child rows its cascade removes, and leaves a row that stopped matching' => [
            [EventLogCleanup::EMAIL_STATS => true],
            [
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats_devices', [], [], $noRows],
                [self::KEYS_SQL, ['table' => 'prefix_table_email_stats_devices'], $keysTypes, []],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_email_stats_devices SELECT * FROM prefix_table_email_stats_devices LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_email_stats_devices'], $keysTypes, ['id', 'stat_id']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_email_stats_devices'], $keysTypes, ['id', 'stat_id']],
                ['SELECT prefix_table_email_stats_devices.id FROM '.$devices.' AND prefix_table_email_stats_devices.id > :lastId ORDER BY prefix_table_email_stats_devices.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats', [], [], $noRows],
                [self::KEYS_SQL, ['table' => 'prefix_table_email_stats'], $keysTypes, [
                    ['child_table' => 'prefix_table_email_stat_replies', 'child_column' => 'stat_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                    ['child_table' => 'prefix_table_email_stats_devices', 'child_column' => 'stat_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                    ['child_table' => 'prefix_table_some_report', 'child_column' => 'stat_id', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT', 'same_schema' => '1'],
                    ['child_table' => 'prefix_table_some_report', 'child_column' => 'stat_hash', 'referenced_column' => 'tracking_hash', 'delete_rule' => 'NO ACTION', 'same_schema' => '1'],
                ]],
                [self::KEYS_SQL, ['table' => 'prefix_table_email_stat_replies'], $keysTypes, []],
                [self::KEYS_SQL, ['table' => 'prefix_table_email_stats_devices'], $keysTypes, []],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_email_stat_replies SELECT * FROM prefix_table_email_stat_replies LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_email_stat_replies'], $keysTypes, ['id', 'stat_id']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_email_stat_replies'], $keysTypes, ['id', 'stat_id']],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_email_stats_devices SELECT * FROM prefix_table_email_stats_devices LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_email_stats_devices'], $keysTypes, ['id', 'stat_id']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_email_stats_devices'], $keysTypes, ['id', 'stat_id']],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_email_stats SELECT * FROM prefix_table_email_stats LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_email_stats'], $keysTypes, ['id', 'email_id']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_email_stats'], $keysTypes, ['id', 'email_id']],
                ['SELECT prefix_table_email_stats.id FROM '.$statsWhere.' AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['5', '6']],
                ['SELECT prefix_table_email_stats.id FROM '.$statsWhere.' AND prefix_table_email_stats.id BETWEEN :fromId AND :toId ORDER BY prefix_table_email_stats.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 5, 'toId' => 6], $writeTypes, ['5']],
                ['SELECT COUNT(*) FROM prefix_table_email_stat_replies WHERE stat_id IN (:batchIds) FOR UPDATE', ['batchIds' => [5]], $batchTypes, '0'],
                ['INSERT INTO prefix_table_housekeeping_archive_email_stat_replies SELECT * FROM prefix_table_email_stat_replies WHERE stat_id IN (:batchIds)', ['batchIds' => [5]], $batchTypes, 0],
                ['SELECT COUNT(*) FROM prefix_table_email_stats_devices WHERE stat_id IN (:batchIds) FOR UPDATE', ['batchIds' => [5]], $batchTypes, '2'],
                ['INSERT INTO prefix_table_housekeeping_archive_email_stats_devices SELECT * FROM prefix_table_email_stats_devices WHERE stat_id IN (:batchIds)', ['batchIds' => [5]], $batchTypes, 2],
                ['INSERT INTO prefix_table_housekeeping_archive_email_stats SELECT prefix_table_email_stats.* FROM '.$statsWhere.' AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 5, 'toId' => 6], $writeTypes, 1],
                ['DELETE LOW_PRIORITY prefix_table_email_stats FROM '.$statsWhere.' AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 5, 'toId' => 6], $writeTypes, 1],
                ['SELECT prefix_table_email_stats.id FROM '.$statsWhere.' AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 6], $selectTypes, []],
            ],
            1,
            '0 email_stats_devices and 1 email_stats rows have been deleted. Every deleted or changed row was copied to a housekeeping_archive_ table first.',
        ];

        $tokensWhere = 'prefix_table_email_stats  WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL';

        yield 'copies the email_stats rows whose tokens it clears to their own archive table' => [
            [EventLogCleanup::EMAIL_STATS_TOKENS => true],
            [
                ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats', [], [], $noRows],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_email_stats_tokens SELECT * FROM prefix_table_email_stats LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_email_stats_tokens'], ['table' => \PDO::PARAM_STR], ['id', 'tokens']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_email_stats'], ['table' => \PDO::PARAM_STR], ['id', 'tokens']],
                ['SELECT prefix_table_email_stats.id FROM '.$tokensWhere.' AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, ['7']],
                ['SELECT prefix_table_email_stats.id FROM '.$tokensWhere.' AND prefix_table_email_stats.id BETWEEN :fromId AND :toId ORDER BY prefix_table_email_stats.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 7, 'toId' => 7], $writeTypes, ['7']],
                ['INSERT INTO prefix_table_housekeeping_archive_email_stats_tokens SELECT prefix_table_email_stats.* FROM '.$tokensWhere.' AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 7, 'toId' => 7], $writeTypes, 1],
                ['UPDATE LOW_PRIORITY prefix_table_email_stats SET tokens = NULL WHERE date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND tokens IS NOT NULL AND prefix_table_email_stats.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 7, 'toId' => 7], $writeTypes, 1],
            ],
            1,
            ' 1 email_stats_tokens have been set to NULL. Every deleted or changed row was copied to a housekeeping_archive_ table first.',
        ];
    }

    /**
     * @dataProvider mismatchedCopyProvider
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, int>, 3: mixed}> $calls
     */
    public function testArchiveRollsTheBatchBackWhenACountDiffers(array $calls, string $error): void
    {
        $connection = $this->createArchiveConnection($calls, 1, 0, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($error);

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), null, 2, null, true);
    }

    public static function mismatchedCopyProvider(): \Generator
    {
        $batchTypes = ['batchIds' => ArrayParameterType::INTEGER];
        $writeTypes = ['daysOld' => \PDO::PARAM_INT, 'fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
        $keysTypes  = ['table' => \PDO::PARAM_STR];
        $hitsWhere  = 'prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)';
        $range      = ['SELECT MIN(id), MAX(id) FROM prefix_table_page_hits', [], [], [null, null]];
        $create     = ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_page_hits SELECT * FROM prefix_table_page_hits LIMIT 0', [], [], null];
        $columns    = [
            [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_page_hits'], $keysTypes, ['id', 'date_hit']],
            [self::COLUMNS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, ['id', 'date_hit']],
        ];
        $batch      = [
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id > :lastId ORDER BY prefix_table_page_hits.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT], ['10', '20']],
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId ORDER BY prefix_table_page_hits.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, ['10', '20']],
        ];

        yield 'the write removes fewer rows than were copied' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, []],
                $create,
                ...$columns,
                ...$batch,
                ['INSERT INTO prefix_table_housekeeping_archive_page_hits SELECT prefix_table_page_hits.* FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 2],
                ['DELETE LOW_PRIORITY prefix_table_page_hits FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 10, 'toId' => 20], $writeTypes, 1],
            ],
            'Archive check failed on prefix_table_page_hits: 2 rows copied, 1 written, 2 expected. The batch was rolled back.',
        ];

        yield 'a child copy holds fewer rows than were locked' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1']]],
                [self::KEYS_SQL, ['table' => 'prefix_table_hit_notes'], $keysTypes, []],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_hit_notes SELECT * FROM prefix_table_hit_notes LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_hit_notes'], $keysTypes, ['id', 'hit_id']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_hit_notes'], $keysTypes, ['id', 'hit_id']],
                $create,
                ...$columns,
                ...$batch,
                ['SELECT COUNT(*) FROM prefix_table_hit_notes WHERE hit_id IN (:batchIds) FOR UPDATE', ['batchIds' => [10, 20]], $batchTypes, '3'],
                ['INSERT INTO prefix_table_housekeeping_archive_hit_notes SELECT * FROM prefix_table_hit_notes WHERE hit_id IN (:batchIds)', ['batchIds' => [10, 20]], $batchTypes, 2],
            ],
            'Archive check failed on prefix_table_hit_notes: 2 rows copied, 3 expected. The batch was rolled back.',
        ];
    }

    /**
     * @dataProvider unarchivableProvider
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, int>, 3: mixed}> $calls
     */
    public function testArchiveRejectsATableWhoseDeleteItCouldNotUndo(array $calls, string $error): void
    {
        $connection = $this->createArchiveConnection($calls, 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($error);

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), null, 2, null, true);
    }

    public static function unarchivableProvider(): \Generator
    {
        $range     = ['SELECT MIN(id), MAX(id) FROM prefix_table_page_hits', [], [], [null, null]];
        $keysTypes = ['table' => \PDO::PARAM_STR];

        yield 'a child set to NULL' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_id', 'referenced_column' => 'id', 'delete_rule' => 'SET NULL', 'same_schema' => '1']]],
            ],
            'Cannot archive prefix_table_page_hits, because a delete there changes prefix_table_hit_notes through SET NULL.',
        ];

        yield 'a child in another database' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '0']]],
            ],
            'Cannot work on prefix_table_page_hits, because prefix_table_hit_notes in another database points at it.',
        ];

        yield 'a cascade two levels deep' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1']]],
                [self::KEYS_SQL, ['table' => 'prefix_table_hit_notes'], $keysTypes, [['child_table' => 'prefix_table_note_tags', 'child_column' => 'note_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1']]],
            ],
            'Cannot archive prefix_table_page_hits, because a delete there cascades from prefix_table_hit_notes to further tables.',
        ];

        yield 'a cascade through a key on another column' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_code', 'referenced_column' => 'code', 'delete_rule' => 'CASCADE', 'same_schema' => '1']]],
            ],
            'Cannot archive prefix_table_page_hits, because the cascade to prefix_table_hit_notes does not come through one key on its id.',
        ];

        yield 'two cascading key columns from one child' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, [
                    ['child_table' => 'prefix_table_hit_notes', 'child_column' => 'hit_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                    ['child_table' => 'prefix_table_hit_notes', 'child_column' => 'first_hit_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                ]],
            ],
            'Cannot archive prefix_table_page_hits, because the cascade to prefix_table_hit_notes does not come through one key on its id.',
        ];

        yield 'an archive table whose columns moved' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, []],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_page_hits SELECT * FROM prefix_table_page_hits LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_page_hits'], $keysTypes, ['id', 'email_id', 'date_hit']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, ['id', 'date_hit', 'email_id']],
            ],
            'Cannot archive into prefix_table_housekeeping_archive_page_hits, because its columns no longer match prefix_table_page_hits. Rename or drop it, and the next run makes a new one.',
        ];

        yield 'an archive table with a narrower column' => [
            [
                $range,
                [self::KEYS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, []],
                ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_page_hits SELECT * FROM prefix_table_page_hits LIMIT 0', [], [], null],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_page_hits'], $keysTypes, ['id bigint unsigned NO', 'url varchar(191) YES utf8mb4 utf8mb4_unicode_ci']],
                [self::COLUMNS_SQL, ['table' => 'prefix_table_page_hits'], $keysTypes, ['id bigint unsigned NO', 'url text YES utf8mb4 utf8mb4_unicode_ci']],
            ],
            'Cannot archive into prefix_table_housekeeping_archive_page_hits, because its columns no longer match prefix_table_page_hits. Rename or drop it, and the next run makes a new one.',
        ];
    }

    public function testBatchSizeIsIgnoredOnDryRun(): void
    {
        $statement = $this->createMock(Result::class);
        $statement->expects($this->once())
            ->method('fetchOne')
            ->willReturn(5);

        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('beginTransaction');
        $connection->expects($this->once())
            ->method('commit');
        $connection->expects($this->once())
            ->method('executeQuery')
            ->with(
                'SELECT COUNT(1) as cnt FROM prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)',
                ['daysOld' => 4],
                ['daysOld' => \PDO::PARAM_INT]
            )
            ->willReturn($statement);

        $this->assertSame(
            '5 page_hits rows would have been deleted. This is a dry run.',
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, true, [EventLogCleanup::PAGE_HITS => true], $this->createStub(OutputInterface::class), null, 2, 1, true)
        );
    }

    /**
     * Counts as anonymous only the contacts whose every identifier field is NULL or empty, so a value of only spaces keeps the contact.
     */
    public function testAnonymousContactsCountOnlyEmptyIdentifiersAsEmpty(): void
    {
        $keysTypes  = ['table' => \PDO::PARAM_STR];
        $connection = $this->createArchiveConnection([
            [self::CONTACT_COLUMNS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, ['id', 'email', 'phone']],
            [self::IDENTIFIER_FIELDS_SQL, [], [], ['phone']],
            [self::KEYS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, []],
            ['SELECT COUNT(1) as cnt FROM prefix_table_leads WHERE prefix_table_leads.date_identified IS NULL AND prefix_table_leads.last_active IS NULL AND prefix_table_leads.date_added < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND (prefix_table_leads.email IS NULL OR OCTET_LENGTH(CAST(prefix_table_leads.email AS CHAR)) = 0) AND (prefix_table_leads.phone IS NULL OR OCTET_LENGTH(CAST(prefix_table_leads.phone AS CHAR)) = 0)', ['daysOld' => 30], ['daysOld' => \PDO::PARAM_INT], 3],
            [self::KEYS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, []],
        ], 1, 1, 0);

        $this->assertSame(
            '3 leads rows would have been deleted. This is a dry run.',
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(30, null, true, [EventLogCleanup::ANONYMOUS_CONTACTS => true], $this->createStub(OutputInterface::class))
        );
    }

    /**
     * Rejects a foreign key from leads to itself before anything is written, because MySQL cannot delete from a table that the same statement reads.
     *
     * @dataProvider selfKeyProvider
     *
     * @param string $deleteRule what a delete of a contact does to the contacts that point at it
     */
    public function testAnonymousContactsRejectAKeyFromLeadsToItself(string $deleteRule): void
    {
        $keysTypes  = ['table' => \PDO::PARAM_STR];
        $connection = $this->createArchiveConnection([
            [self::CONTACT_COLUMNS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, ['id', 'email', 'referrer_id']],
            [self::IDENTIFIER_FIELDS_SQL, [], [], []],
            [self::KEYS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, [
                ['child_table' => 'prefix_table_lead_notes', 'child_column' => 'lead_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                ['child_table' => 'prefix_table_leads', 'child_column' => 'referrer_id', 'referenced_column' => 'id', 'delete_rule' => $deleteRule, 'same_schema' => '1'],
            ]],
        ], 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete anonymous contacts, because prefix_table_leads points at itself through referrer_id.');

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(30, null, true, [EventLogCleanup::ANONYMOUS_CONTACTS => true], $this->createStub(OutputInterface::class));
    }

    /**
     * Returns the delete rules a foreign key from leads to itself can have.
     *
     * @return \Generator<string, array{string}>
     */
    public static function selfKeyProvider(): \Generator
    {
        yield 'set to NULL' => ['SET NULL'];
        yield 'cascading' => ['CASCADE'];
    }

    /**
     * Rejects, without --archive and before anything is written, a key from another database on a table that the contact cascade reaches two levels down, reading each table once.
     */
    public function testAnonymousContactsRejectAKeyFromAnotherDatabaseBelowTheCascade(): void
    {
        $keysTypes  = ['table' => \PDO::PARAM_STR];
        $connection = $this->createArchiveConnection([
            [self::CONTACT_COLUMNS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, ['id', 'email']],
            [self::IDENTIFIER_FIELDS_SQL, [], [], []],
            [self::KEYS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, [
                ['child_table' => 'prefix_table_lead_notes', 'child_column' => 'lead_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                ['child_table' => 'prefix_table_lead_tags', 'child_column' => 'lead_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
            ]],
            [self::KEYS_SQL, ['table' => 'prefix_table_lead_notes'], $keysTypes, [
                ['child_table' => 'prefix_table_note_tags', 'child_column' => 'note_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
            ]],
            [self::KEYS_SQL, ['table' => 'prefix_table_note_tags'], $keysTypes, []],
            [self::KEYS_SQL, ['table' => 'prefix_table_lead_tags'], $keysTypes, [
                ['child_table' => 'prefix_table_note_tags', 'child_column' => 'tag_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
                ['child_table' => 'prefix_table_tag_votes', 'child_column' => 'tag_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
            ]],
            [self::KEYS_SQL, ['table' => 'prefix_table_tag_votes'], $keysTypes, [
                ['child_table' => 'vote_audit', 'child_column' => 'vote_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '0'],
            ]],
        ], 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot work on prefix_table_tag_votes, because vote_audit in another database points at it.');

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(30, null, false, [EventLogCleanup::ANONYMOUS_CONTACTS => true], $this->createStub(OutputInterface::class), null, 2);
    }

    /**
     * Rejects a key from leads to a table that the contact cascade reaches, before anything is written, because the delete would then remove, change or stop on contacts that are not anonymous.
     *
     * @dataProvider cascadeBackProvider
     *
     * @param string $deleteRule what a delete in the reached table does to the contacts that point at it
     */
    public function testAnonymousContactsRejectAKeyFromLeadsIntoTheCascade(string $deleteRule): void
    {
        $keysTypes  = ['table' => \PDO::PARAM_STR];
        $connection = $this->createArchiveConnection([
            [self::CONTACT_COLUMNS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, ['id', 'email', 'note_id']],
            [self::IDENTIFIER_FIELDS_SQL, [], [], []],
            [self::KEYS_SQL, ['table' => 'prefix_table_leads'], $keysTypes, [
                ['child_table' => 'prefix_table_lead_notes', 'child_column' => 'lead_id', 'referenced_column' => 'id', 'delete_rule' => 'CASCADE', 'same_schema' => '1'],
            ]],
            [self::KEYS_SQL, ['table' => 'prefix_table_lead_notes'], $keysTypes, [
                ['child_table' => 'prefix_table_leads', 'child_column' => 'note_id', 'referenced_column' => 'id', 'delete_rule' => $deleteRule, 'same_schema' => '1'],
            ]],
        ], 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete anonymous contacts, because prefix_table_leads points at prefix_table_lead_notes, which the contact cascade reaches, through note_id.');

        $this->createEventLogCleanup($connection)->deleteEventLogEntries(30, null, false, [EventLogCleanup::ANONYMOUS_CONTACTS => true], $this->createStub(OutputInterface::class), null, 2);
    }

    /**
     * Returns the delete rules a key from leads to a table of the contact cascade can have.
     *
     * @return \Generator<string, array{string}>
     */
    public static function cascadeBackProvider(): \Generator
    {
        yield 'cascading' => ['CASCADE'];
        yield 'set to NULL' => ['SET NULL'];
        yield 'restricting' => ['RESTRICT'];
    }

    /**
     * Returns a connection that expects these queries in this order and answers each with the value given for it.
     *
     * @param list<array{0: string, 1: array<string, mixed>, 2: array<string, int>, 3: mixed}> $calls
     */
    private function createArchiveConnection(array $calls, int $begins, int $commits, int $rollBacks): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->exactly($begins))
            ->method('beginTransaction');
        $connection->expects($this->exactly($commits))
            ->method('commit');
        $connection->expects($this->exactly($rollBacks))
            ->method('rollBack');
        $connection->expects($this->exactly(count($calls)))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$calls): Result {
                $call = array_shift($calls);

                self::assertSame($call[0], $sql);
                self::assertSame($call[1], $params);
                self::assertSame($call[2], $types);

                $result = $this->createStub(Result::class);
                if (str_contains($sql, 'MIN(id)')) {
                    $result->method('fetchNumeric')->willReturn($call[3]);
                } elseif (str_contains($sql, 'KEY_COLUMN_USAGE')) {
                    $result->method('fetchAllAssociative')->willReturn($call[3]);
                } elseif (str_contains($sql, 'COUNT(*)') || str_contains($sql, 'COUNT(1)')) {
                    $result->method('fetchOne')->willReturn($call[3]);
                } elseif (is_array($call[3])) {
                    $result->method('fetchFirstColumn')->willReturn($call[3]);
                } elseif (is_int($call[3])) {
                    $result->method('rowCount')->willReturn($call[3]);
                }

                return $result;
            });

        return $connection;
    }

    private function createEventLogCleanup(Connection $connection): EventLogCleanup
    {
        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        return new EventLogCleanup($connection, 'prefix_table_', $config, $this->createStub(\Psr\Log\LoggerInterface::class));
    }
}
