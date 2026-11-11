<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Result;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Integration\Config;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class EventLogCleanupIpAddressesTest extends TestCase
{
    private const KEYS_SQL       = 'SELECT k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column, k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME WHERE k.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = :table';
    private const COLUMNS_SQL    = "SELECT CONCAT_WS(' ', COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table ORDER BY ORDINAL_POSITION";
    private const IP_KEYS_SQL    = 'SELECT k.TABLE_SCHEMA = k.REFERENCED_TABLE_SCHEMA AS same_database, k.TABLE_SCHEMA AS child_schema, k.TABLE_NAME AS child_table, k.COLUMN_NAME AS child_column, k.REFERENCED_COLUMN_NAME AS referenced_column FROM information_schema.KEY_COLUMN_USAGE k WHERE k.REFERENCED_TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = :table';
    private const IP_TABLES_SQL  = "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME WHERE c.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE' AND c.COLUMN_NAME IN (:columns)";
    private const IP_INDEXES_SQL = 'SELECT DISTINCT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND SEQ_IN_INDEX = 1 AND COLUMN_NAME IN (:columns)';
    private const MAX_SQL        = 'SELECT MAX(id) FROM prefix_table_ip_addresses';
    private const UNUSED         = 'NOT EXISTS (SELECT 1 FROM prefix_table_email_stats u WHERE u.ip_id = a.id) AND NOT EXISTS (SELECT 1 FROM prefix_table_page_hits u WHERE u.ip_id = a.id)';
    private const COUNT_SQL      = 'SELECT COUNT(*) FROM prefix_table_ip_addresses a WHERE a.id > :lastId AND a.id <= :toId AND '.self::UNUSED;
    private const LOCK_SQL       = 'SELECT id FROM prefix_table_ip_addresses WHERE id IN (:batchIds) ORDER BY id FOR UPDATE SKIP LOCKED';
    private const CHECK_SQL      = 'SELECT a.id FROM prefix_table_ip_addresses a WHERE a.id IN (:batchIds) AND '.self::UNUSED.' ORDER BY a.id';
    private const COPY_SQL       = 'INSERT INTO prefix_table_housekeeping_archive_ip_addresses SELECT prefix_table_ip_addresses.* FROM prefix_table_ip_addresses WHERE prefix_table_ip_addresses.id IN (:batchIds)';
    private const DELETE_SQL     = 'DELETE LOW_PRIORITY FROM prefix_table_ip_addresses WHERE prefix_table_ip_addresses.id IN (:batchIds)';
    private const WINDOW_TYPES   = ['lastId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];
    private const CHECKED_ALL    = ' The ip_addresses cleanup checked every ID up to 25000 and left out the newest 1000000 IDs.';
    private const CHECKED_NONE   = ' The ip_addresses cleanup checked no IDs, because none is older than the newest 1000000.';
    private const KEPT           = ' ip_addresses rows were in use or locked when their batch ran, so they were kept for a later run.';
    private const ISOLATION_SQL  = 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED';
    private const READ_COMMITTED = [self::ISOLATION_SQL, [], [], null];

    /**
     * @var list<string>
     */
    private array $events = [];

    /**
     * Counts every table with a key to ip_addresses, every archive table and every other indexed table with the same column as a user.
     */
    public function testReadsTheTablesThatHoldAnAddressFromKeysArchivesAndIndexedTables(): void
    {
        $tables = ['prefix_table_page_hits', 'prefix_table_housekeeping_archive_page_hits', 'prefix_table_email_stats', 'prefix_table_custom_visits'];
        $unused = 'NOT EXISTS (SELECT 1 FROM prefix_table_custom_visits u WHERE u.ip_id = a.id) AND NOT EXISTS (SELECT 1 FROM prefix_table_email_stats u WHERE u.ip_id = a.id) AND NOT EXISTS (SELECT 1 FROM prefix_table_housekeeping_archive_page_hits u WHERE u.ip_id = a.id) AND NOT EXISTS (SELECT 1 FROM prefix_table_page_hits u WHERE u.ip_id = a.id)';

        $connection = $this->createIpConnection([
            [self::IP_KEYS_SQL, ['table' => 'prefix_table_ip_addresses'], ['table' => \PDO::PARAM_STR], self::keys()],
            [self::IP_TABLES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows(...$tables)],
            [self::IP_INDEXES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows(...$tables)],
            [self::MAX_SQL, [], [], '1025000'],
            self::select(0, 10000, [], 2, $unused),
            self::select(10000, 20000, [], 2, $unused),
            self::select(20000, 25000, [], 2, $unused),
        ], 0, 0, 0);

        $lines = [];

        $this->assertSame(
            '0 ip_addresses rows have been deleted.'.self::CHECKED_ALL,
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true], $this->createVerboseOutput($lines), null, 2)
        );
        $this->assertContains('Tables that can hold an ip_addresses ID: prefix_table_custom_visits.ip_id, prefix_table_email_stats.ip_id, prefix_table_housekeeping_archive_page_hits.ip_id, prefix_table_page_hits.ip_id', $lines);
        $this->assertContains('Checking ip_addresses IDs up to 25000, slice 1 of 1, in blocks of 100000. The newest 1000000 IDs are left out.', $lines);
        $this->assertContains(self::selectSql(2, $unused), $lines);
        $this->assertContains('IDs 1 to 25000: 0 deleted, 0 kept for a later run.', $lines);
    }

    /**
     * Stops with the reason before any write when the tables that use an address cannot all be found.
     *
     * @dataProvider rejectedUsersProvider
     *
     * @param list<list<mixed>> $calls the queries expected before the run stops
     * @param string            $error the message the run stops with
     */
    public function testRejectsBeforeItWritesAnything(array $calls, string $error): void
    {
        $connection = $this->createIpConnection($calls, 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\A'.preg_quote($error, '/').'\z/');

        $this->runIpCleanup($connection);
    }

    /**
     * Lists the tables and keys that stop the run, with the queries that run before it stops.
     *
     * @return \Generator<string, array{0: list<list<mixed>>, 1: string}> the cases
     */
    public static function rejectedUsersProvider(): \Generator
    {
        $keysTypes    = ['table' => \PDO::PARAM_STR];
        $columnsTypes = ['columns' => ArrayParameterType::STRING];
        $table        = ['table' => 'prefix_table_ip_addresses'];
        $columns      = ['columns' => ['ip_id']];

        yield 'no foreign key' => [
            [[self::IP_KEYS_SQL, $table, $keysTypes, []]],
            'Cannot clean prefix_table_ip_addresses, because no foreign key points at it, so the tables that use an address cannot be found.',
        ];

        yield 'a key from another database' => [
            [[self::IP_KEYS_SQL, $table, $keysTypes, [self::key('prefix_table_page_hits', 'ip_id', 'id', '0', 'other_db')]]],
            'Cannot clean prefix_table_ip_addresses, because other_db.prefix_table_page_hits in another database points at it.',
        ];

        yield 'a key on another column' => [
            [[self::IP_KEYS_SQL, $table, $keysTypes, [self::key('prefix_table_page_hits', 'ip_text', 'ip_address')]]],
            'Cannot clean prefix_table_ip_addresses, because the key from prefix_table_page_hits.ip_text points at its ip_address column instead of its id.',
        ];

        yield 'a key table whose name is not plain' => [
            [[self::IP_KEYS_SQL, $table, $keysTypes, [self::key('prefix_table_page-hits', 'ip_id')]]],
            'Cannot clean prefix_table_ip_addresses, because prefix_table_page-hits is not a plain table or column name.',
        ];

        yield 'a listed table whose name is not plain' => [
            [
                [self::IP_KEYS_SQL, $table, $keysTypes, self::keys()],
                [self::IP_TABLES_SQL, $columns, $columnsTypes, self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_old hits')],
            ],
            'Cannot clean prefix_table_ip_addresses, because prefix_table_old hits is not a plain table or column name.',
        ];

        yield 'a column with no key and no index' => [
            [
                [self::IP_KEYS_SQL, $table, $keysTypes, self::keys()],
                [self::IP_TABLES_SQL, $columns, $columnsTypes, self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_ip_copy')],
                [self::IP_INDEXES_SQL, $columns, $columnsTypes, self::rows('prefix_table_page_hits', 'prefix_table_email_stats')],
            ],
            'Cannot clean prefix_table_ip_addresses, because prefix_table_ip_copy.ip_id can hold an address ID but has no foreign key and no index. Add an index on it, or drop the table.',
        ];
    }

    /**
     * Adds an index to the archive table that has none, with a short lock wait timeout that is put back afterwards.
     */
    public function testAddsAnIndexOnlyToArchiveTablesThatLackOne(): void
    {
        $connection = $this->createIpConnection([
            [self::IP_KEYS_SQL, ['table' => 'prefix_table_ip_addresses'], ['table' => \PDO::PARAM_STR], self::keys()],
            [self::IP_TABLES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_housekeeping_archive_page_hits', 'prefix_table_housekeeping_archive_email_stats_devices')],
            [self::IP_INDEXES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_housekeeping_archive_page_hits')],
            ['SELECT @@SESSION.lock_wait_timeout', [], [], '50'],
            ['SET SESSION lock_wait_timeout = 30', [], [], null],
            ['ALTER TABLE prefix_table_housekeeping_archive_email_stats_devices ADD INDEX housekeeping_ip_id (ip_id), ALGORITHM=INPLACE, LOCK=NONE', [], [], null],
            ['SET SESSION lock_wait_timeout = 50', [], [], null],
            [self::MAX_SQL, [], [], null],
        ], 0, 0, 0);

        $this->assertSame('0 ip_addresses rows have been deleted.'.self::CHECKED_NONE, $this->runIpCleanup($connection));
    }

    /**
     * Puts the lock wait timeout back and stops before any read of the IDs when an index cannot be added.
     */
    public function testRestoresTheLockWaitTimeoutWhenAnIndexCannotBeAdded(): void
    {
        $connection = $this->createIpConnection([
            [self::IP_KEYS_SQL, ['table' => 'prefix_table_ip_addresses'], ['table' => \PDO::PARAM_STR], self::keys()],
            [self::IP_TABLES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_housekeeping_archive_page_hits')],
            [self::IP_INDEXES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats')],
            ['SELECT @@SESSION.lock_wait_timeout', [], [], '50'],
            ['SET SESSION lock_wait_timeout = 30', [], [], null],
            ['ALTER TABLE prefix_table_housekeeping_archive_page_hits ADD INDEX housekeeping_ip_id (ip_id), ALGORITHM=INPLACE, LOCK=NONE', [], [], new \RuntimeException('Lock wait timeout exceeded')],
            ['SET SESSION lock_wait_timeout = 50', [], [], null],
        ], 0, 0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Lock wait timeout exceeded');

        $this->runIpCleanup($connection);
    }

    /**
     * Reads no IDs when every address is among the newest.
     *
     * @dataProvider noOldIdsProvider
     *
     * @param string|null $max the highest ID in the table
     */
    public function testChecksNoIdsWhenNoneIsOldEnough(?string $max): void
    {
        $connection = $this->createIpConnection(self::usersFound($max), 0, 0, 0);

        $this->assertSame('0 ip_addresses rows have been deleted.'.self::CHECKED_NONE, $this->runIpCleanup($connection));
    }

    /**
     * Lists a highest ID that leaves nothing old enough, and an empty table.
     *
     * @return \Generator<string, array{0: string|null}> the cases
     */
    public static function noOldIdsProvider(): \Generator
    {
        yield 'only the newest IDs' => ['1000000'];

        yield 'an empty table' => [null];
    }

    /**
     * Reads every Mth block of IDs from block N, in scan windows of 10000 IDs.
     *
     * @dataProvider sliceProvider
     *
     * @param int                         $slice   the slice to check
     * @param int                         $slices  how many slices share the blocks
     * @param bool                        $dryRun  true to count only
     * @param list<array{0: int, 1: int}> $windows the windows the run should read, as last ID before and last ID in
     * @param string                      $checked the sentence about the IDs it checked
     */
    public function testSliceTakesEveryMthBlockOfTheIds(int $slice, int $slices, bool $dryRun, array $windows, string $checked): void
    {
        $calls = self::usersFound('1250000');

        foreach ($windows as [$lastId, $toId]) {
            $calls[] = $dryRun ? [self::COUNT_SQL, ['lastId' => $lastId, 'toId' => $toId], self::WINDOW_TYPES, '0'] : self::select($lastId, $toId, []);
        }

        $eventLogCleanup = $this->createEventLogCleanup($this->createIpConnection($calls, 0, 0, 0));
        $eventLogCleanup->setIpSlice($slice, $slices);

        $this->assertSame(
            ($dryRun ? '0 ip_addresses rows would have been deleted. This is a dry run.' : '0 ip_addresses rows have been deleted.').$checked,
            $eventLogCleanup->deleteEventLogEntries(4, null, $dryRun, [EventLogCleanup::IP_ADDRESSES => true], $this->createStub(OutputInterface::class), null, 2)
        );
    }

    /**
     * Lists slices of the IDs up to 250000 with the windows each one reads.
     *
     * @return \Generator<string, array{0: int, 1: int, 2: bool, 3: list<array{0: int, 1: int}>, 4: string}> the cases
     */
    public static function sliceProvider(): \Generator
    {
        $checked = ' The ip_addresses cleanup checked slice %d of %d of the IDs up to 250000 and left out the newest 1000000 IDs.';

        yield 'every ID' => [1, 1, false, [...self::windowsOf(1, 100000), ...self::windowsOf(100001, 200000), ...self::windowsOf(200001, 250000)], ' The ip_addresses cleanup checked every ID up to 250000 and left out the newest 1000000 IDs.'];

        yield 'slice 1 of 3' => [1, 3, false, self::windowsOf(1, 100000), sprintf($checked, 1, 3)];

        yield 'slice 2 of 3' => [2, 3, false, self::windowsOf(100001, 200000), sprintf($checked, 2, 3)];

        yield 'slice 3 of 3' => [3, 3, false, self::windowsOf(200001, 250000), sprintf($checked, 3, 3)];

        yield 'slice 2 of 2' => [2, 2, false, self::windowsOf(100001, 200000), sprintf($checked, 2, 2)];

        yield 'dry run of slice 1 of 2' => [1, 2, true, [...self::windowsOf(1, 100000), ...self::windowsOf(200001, 250000)], sprintf($checked, 1, 2)];
    }

    /**
     * Runs the select outside any transaction, sets READ COMMITTED for the batch transaction just before it begins, and makes the lock its first query.
     */
    public function testLocksBeforeItsFirstPlainReadInTheBatchTransaction(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(),
            self::batch(self::DELETE_SQL, [10], 1),
            self::select(10000, 20000, []),
            self::select(20000, 25000, []),
        ], 1, 1, 0);

        $this->assertSame('1 ip_addresses rows have been deleted.'.self::CHECKED_ALL, $this->runIpCleanup($connection));
        $this->assertSame(
            [self::IP_KEYS_SQL, self::IP_TABLES_SQL, self::IP_INDEXES_SQL, self::MAX_SQL, self::selectSql(2), self::ISOLATION_SQL, 'begin', self::LOCK_SQL, self::CHECK_SQL, self::IP_TABLES_SQL, self::DELETE_SQL, 'commit', self::selectSql(2), self::selectSql(2)],
            $this->events
        );
    }

    /**
     * Keeps an address another transaction holds and one that came into use since the select, and says how many it kept.
     */
    public function testKeepsAddressesThatAreLockedOrCameIntoUse(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10', '20', '30'], 5),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10, 20, 30], ['10', '30']),
            self::batch(self::CHECK_SQL, [10, 30], ['30']),
            self::relist(),
            self::batch(self::DELETE_SQL, [30], 1),
            self::select(10000, 20000, [], 5),
            self::select(20000, 25000, [], 5),
        ], 1, 1, 0);

        $this->assertSame('1 ip_addresses rows have been deleted.'.self::CHECKED_ALL.' 2'.self::KEPT, $this->runIpCleanup($connection, 5));
    }

    /**
     * Commits a batch without a check or a delete when another transaction holds every address of it.
     */
    public function testCommitsWithoutWritingWhenEveryAddressIsLocked(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10', '20'], 5),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10, 20], []),
            self::select(10000, 20000, [], 5),
            self::select(20000, 25000, [], 5),
        ], 1, 1, 0);

        $this->assertSame('0 ip_addresses rows have been deleted.'.self::CHECKED_ALL.' 2'.self::KEPT, $this->runIpCleanup($connection, 5));
    }

    /**
     * Selects again from the last ID it found, up to the end of the same window, when a select fills the batch.
     */
    public function testMovesOnFromTheLastIdWhenASelectIsFull(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10', '20']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10, 20], ['10', '20']),
            self::batch(self::CHECK_SQL, [10, 20], ['10', '20']),
            self::relist(),
            self::batch(self::DELETE_SQL, [10, 20], 2),
            self::select(20, 10000, []),
            self::select(10000, 20000, []),
            self::select(20000, 25000, []),
        ], 1, 1, 0);

        $this->assertSame('2 ip_addresses rows have been deleted.'.self::CHECKED_ALL, $this->runIpCleanup($connection));
    }

    /**
     * Selects no more rows than the row limit has left, stops once it is reached, and says the ID it stopped at.
     */
    public function testSelectsNoMoreThanTheRowLimitHasLeft(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10', '20']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10, 20], ['10', '20']),
            self::batch(self::CHECK_SQL, [10, 20], ['10', '20']),
            self::relist(),
            self::batch(self::DELETE_SQL, [10, 20], 2),
            self::select(20, 10000, ['30'], 1),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [30], ['30']),
            self::batch(self::CHECK_SQL, [30], ['30']),
            self::relist(),
            self::batch(self::DELETE_SQL, [30], 1),
        ], 2, 2, 0);

        $lines = [];

        $this->assertSame(
            '3 ip_addresses rows have been deleted. Stopped at the limit of 3 rows for this run. Any rows left wait for the next run. The ip_addresses cleanup stopped at ID 30, before it checked every ID up to 25000.',
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true], $this->createVerboseOutput($lines), null, 2, 3)
        );
        $this->assertContains('IDs 1 to 30: 3 deleted, 0 kept for a later run.', $lines);
        $this->assertNotContains('IDs 1 to 25000: 3 deleted, 0 kept for a later run.', $lines);
    }

    /**
     * Says the ID it stopped at and the slice it did not finish when the row limit is reached inside a slice.
     */
    public function testSaysWhereItStoppedInASliceAtTheRowLimit(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound('1250000'),
            self::select(100000, 110000, ['100010'], 1),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [100010], ['100010']),
            self::batch(self::CHECK_SQL, [100010], ['100010']),
            self::relist(),
            self::batch(self::DELETE_SQL, [100010], 1),
        ], 1, 1, 0);

        $lines           = [];
        $eventLogCleanup = $this->createEventLogCleanup($connection);
        $eventLogCleanup->setIpSlice(2, 3);

        $this->assertSame(
            '1 ip_addresses rows have been deleted. Stopped at the limit of 1 rows for this run. Any rows left wait for the next run. The ip_addresses cleanup stopped at ID 100010, before it checked all of slice 2 of 3 of the IDs up to 250000.',
            $eventLogCleanup->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true], $this->createVerboseOutput($lines), null, 2, 1)
        );
        $this->assertContains('IDs 100001 to 100010: 1 deleted, 0 kept for a later run.', $lines);
    }

    /**
     * Says it checked every ID when the row limit is reached on the last ID of the run.
     */
    public function testSaysItCheckedEveryIdWhenTheRowLimitEndsOnTheLastId(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, [], 1),
            self::select(10000, 20000, [], 1),
            self::select(20000, 25000, ['25000'], 1),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [25000], ['25000']),
            self::batch(self::CHECK_SQL, [25000], ['25000']),
            self::relist(),
            self::batch(self::DELETE_SQL, [25000], 1),
        ], 1, 1, 0);

        $this->assertSame(
            '1 ip_addresses rows have been deleted. Stopped at the limit of 1 rows for this run. Any rows left wait for the next run.'.self::CHECKED_ALL,
            $this->runIpCleanup($connection, 2, 1)
        );
    }

    /**
     * Rolls a batch back after a deadlock and runs it once more.
     */
    public function testTriesABatchOnceMoreAfterADeadlock(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], self::deadlock()),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(),
            self::batch(self::DELETE_SQL, [10], 1),
            self::select(10000, 20000, []),
            self::select(20000, 25000, []),
        ], 2, 1, 1);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with($this->stringStartsWith('The ip_addresses batch from ID 10 was rolled back and runs once more: '));

        $this->assertSame(
            '1 ip_addresses rows have been deleted.'.self::CHECKED_ALL,
            $this->createEventLogCleanup($connection, $logger)->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true], $this->createStub(OutputInterface::class), null, 2)
        );
    }

    /**
     * Stops the run when the second try of a batch fails too.
     */
    public function testFailsWhenTheSecondTryFailsToo(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], self::deadlock()),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], self::deadlock()),
        ], 2, 0, 2);

        $this->expectException(DeadlockException::class);

        $this->runIpCleanup($connection);
    }

    /**
     * Rolls the batch back and stops when a table with the address column appears during the run.
     */
    public function testRollsBackWhenTheTablesThatHoldAnAddressChange(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(self::rows('prefix_table_page_hits', 'prefix_table_email_stats', 'prefix_table_housekeeping_archive_page_hits')),
        ], 1, 0, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\A'.preg_quote('Cannot clean prefix_table_ip_addresses, because the tables that can hold an address ID changed during the run. The batch was rolled back. Run it again.', '/').'\z/');

        $this->runIpCleanup($connection);
    }

    /**
     * Rolls the batch back and stops when the delete removes a different number of rows than were checked.
     */
    public function testRollsBackWhenTheDeleteCountDiffers(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(),
            self::batch(self::DELETE_SQL, [10], 0),
        ], 1, 0, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\A'.preg_quote('Batch check failed on prefix_table_ip_addresses: 0 rows written, 1 expected. The batch was rolled back.', '/').'\z/');

        $this->runIpCleanup($connection);
    }

    /**
     * Copies the addresses of a batch to the archive table in the batch transaction, just before it deletes them.
     */
    public function testArchiveCopiesTheDeletedAddressesInTheSameTransaction(): void
    {
        $connection = $this->createIpConnection([
            ...self::archivePrepared(),
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(),
            self::batch(self::COPY_SQL, [10], 1),
            self::batch(self::DELETE_SQL, [10], 1),
            self::select(10000, 20000, []),
            self::select(20000, 25000, []),
        ], 1, 1, 0);

        $this->assertSame(
            '1 ip_addresses rows have been deleted. Every deleted or changed row was copied to a housekeeping_archive_ table first.'.self::CHECKED_ALL,
            $this->runIpCleanup($connection, 2, null, true)
        );
        $this->assertSame([self::ISOLATION_SQL, 'begin', self::LOCK_SQL, self::CHECK_SQL, self::IP_TABLES_SQL, self::COPY_SQL, self::DELETE_SQL, 'commit'], array_slice($this->events, 9, 8));
    }

    /**
     * Rolls the batch back and stops when the archive copy holds a different number of rows than were deleted.
     */
    public function testRollsBackWhenTheArchiveCopyDiffers(): void
    {
        $connection = $this->createIpConnection([
            ...self::archivePrepared(),
            ...self::usersFound(),
            self::select(0, 10000, ['10']),
            self::READ_COMMITTED,
            self::batch(self::LOCK_SQL, [10], ['10']),
            self::batch(self::CHECK_SQL, [10], ['10']),
            self::relist(),
            self::batch(self::COPY_SQL, [10], 0),
            self::batch(self::DELETE_SQL, [10], 1),
        ], 1, 0, 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/\A'.preg_quote('Archive check failed on prefix_table_ip_addresses: 0 rows copied, 1 written, 1 expected. The batch was rolled back.', '/').'\z/');

        $this->runIpCleanup($connection, 2, null, true);
    }

    /**
     * Counts each scan window with its own query, with no transaction.
     */
    public function testDryRunCountsWindowByWindowOutsideATransaction(): void
    {
        $connection = $this->createIpConnection([
            ...self::usersFound(),
            [self::COUNT_SQL, ['lastId' => 0, 'toId' => 10000], self::WINDOW_TYPES, '2'],
            [self::COUNT_SQL, ['lastId' => 10000, 'toId' => 20000], self::WINDOW_TYPES, '0'],
            [self::COUNT_SQL, ['lastId' => 20000, 'toId' => 25000], self::WINDOW_TYPES, '1'],
        ], 0, 0, 0);

        $this->assertSame(
            '3 ip_addresses rows would have been deleted. This is a dry run.'.self::CHECKED_ALL,
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, true, [EventLogCleanup::IP_ADDRESSES => true], $this->createStub(OutputInterface::class))
        );
    }

    /**
     * Stops before any query when a real run has no batch size.
     */
    public function testNeedsABatchSizeOutsideADryRun(): void
    {
        $connection = $this->createIpConnection([], 0, 0, 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The ip_addresses cleanup needs a batch size.');

        $this->runIpCleanup($connection, null);
    }

    /**
     * Runs the ip_addresses cleanup after the other operations and adds its count last.
     */
    public function testRunsAfterTheOtherOperationsAndAddsItsCountLast(): void
    {
        $selectTypes = ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT];

        $connection = $this->createIpConnection([
            ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats_devices', [], [], [null, null]],
            ['SELECT prefix_table_email_stats_devices.id FROM prefix_table_email_stats_devices WHERE prefix_table_email_stats_devices.date_opened < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats_devices.id > :lastId ORDER BY prefix_table_email_stats_devices.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
            ['SELECT MIN(id), MAX(id) FROM prefix_table_email_stats', [], [], [null, null]],
            ['SELECT prefix_table_email_stats.id FROM prefix_table_email_stats LEFT JOIN prefix_table_emails ON prefix_table_email_stats.email_id = prefix_table_emails.id WHERE (prefix_table_emails.is_published = 0 OR prefix_table_emails.publish_down < DATE_SUB(NOW(),INTERVAL :daysOld DAY) OR prefix_table_email_stats.email_id IS NULL) AND prefix_table_email_stats.date_sent < DATE_SUB(NOW(),INTERVAL :daysOld DAY) AND prefix_table_email_stats.id > :lastId ORDER BY prefix_table_email_stats.id LIMIT 2', ['daysOld' => 4, 'lastId' => 0], $selectTypes, []],
            ...self::usersFound('1000000'),
        ], 0, 0, 0);

        $this->assertSame(
            '0 email_stats_devices, 0 email_stats and 0 ip_addresses rows have been deleted.'.self::CHECKED_NONE,
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true, EventLogCleanup::EMAIL_STATS => true], $this->createStub(OutputInterface::class), null, 2)
        );
    }

    /**
     * Leaves the ip_addresses cleanup out once an earlier operation used up the row limit.
     */
    public function testDoesNotStartOnceAnEarlierOperationReachedTheRowLimit(): void
    {
        $hitsWhere  = 'prefix_table_page_hits WHERE date_hit < DATE_SUB(NOW(),INTERVAL :daysOld DAY)';
        $writeTypes = ['daysOld' => \PDO::PARAM_INT, 'fromId' => \PDO::PARAM_INT, 'toId' => \PDO::PARAM_INT];

        $connection = $this->createIpConnection([
            ['SELECT MIN(id), MAX(id) FROM prefix_table_page_hits', [], [], [null, null]],
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id > :lastId ORDER BY prefix_table_page_hits.id LIMIT 1', ['daysOld' => 4, 'lastId' => 0], ['daysOld' => \PDO::PARAM_INT, 'lastId' => \PDO::PARAM_INT], ['10']],
            ['SELECT prefix_table_page_hits.id FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId ORDER BY prefix_table_page_hits.id FOR UPDATE', ['daysOld' => 4, 'fromId' => 10, 'toId' => 10], $writeTypes, ['10']],
            ['DELETE LOW_PRIORITY prefix_table_page_hits FROM '.$hitsWhere.' AND prefix_table_page_hits.id BETWEEN :fromId AND :toId', ['daysOld' => 4, 'fromId' => 10, 'toId' => 10], $writeTypes, 1],
        ], 1, 1, 0);

        $this->assertSame(
            '1 page_hits and 0 ip_addresses rows have been deleted. Stopped at the limit of 1 rows for this run. Any rows left wait for the next run.',
            $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::PAGE_HITS => true, EventLogCleanup::IP_ADDRESSES => true], $this->createStub(OutputInterface::class), null, 2, 1)
        );
    }

    /**
     * Stops before any query when a transaction is already open.
     */
    public function testCannotRunInsideAnOpenTransaction(): void
    {
        $connection = $this->createIpConnection([], 0, 0, 0, true);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The ip_addresses cleanup cannot run inside an open transaction.');

        $this->runIpCleanup($connection);
    }

    /**
     * Rejects a slice outside 1 to M, and a slice count below 1.
     *
     * @dataProvider invalidSliceProvider
     *
     * @param int $slice  the slice to check
     * @param int $slices how many slices share the blocks
     */
    public function testRejectsAnInvalidSlice(int $slice, int $slices): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An ip_addresses slice must be N of M, with N from 1 to M.');

        $this->createEventLogCleanup($this->createIpConnection([], 0, 0, 0))->setIpSlice($slice, $slices);
    }

    /**
     * Lists slices that are not N of M.
     *
     * @return \Generator<string, array{0: int, 1: int}> the cases
     */
    public static function invalidSliceProvider(): \Generator
    {
        yield 'slice 0' => [0, 1];

        yield 'slice past the count' => [2, 1];

        yield 'no slices' => [1, 0];
    }

    /**
     * Returns the keys to ip_addresses that most tests find, from page_hits and email_stats.
     *
     * @return list<array<string, string>> the rows of the keys query
     */
    private static function keys(): array
    {
        return [self::key('prefix_table_page_hits', 'ip_id'), self::key('prefix_table_email_stats', 'ip_id')];
    }

    /**
     * Returns one row of the keys query.
     *
     * @param string $table          the table the key comes from
     * @param string $column         the column of the key
     * @param string $referenced     the column of ip_addresses it points at
     * @param string $sameDatabase   1 when the key comes from this database, else 0
     * @param string $databaseSchema the database of the table the key comes from
     *
     * @return array<string, string> the row
     */
    private static function key(string $table, string $column, string $referenced = 'id', string $sameDatabase = '1', string $databaseSchema = 'mautic'): array
    {
        return ['same_database' => $sameDatabase, 'child_schema' => $databaseSchema, 'child_table' => $table, 'child_column' => $column, 'referenced_column' => $referenced];
    }

    /**
     * Returns rows of the tables query or the indexes query, one per table, each with the column ip_id.
     *
     * @param string ...$tables the tables to list
     *
     * @return list<array{table_name: string, column_name: string}> the rows
     */
    private static function rows(string ...$tables): array
    {
        return array_values(array_map(static fn (string $table): array => ['table_name' => $table, 'column_name' => 'ip_id'], $tables));
    }

    /**
     * Returns the queries that find the users on page_hits and email_stats, and then the highest ID.
     *
     * @param string|null $max the highest ID in the table
     *
     * @return list<list<mixed>> the queries with their answers
     */
    private static function usersFound(?string $max = '1025000'): array
    {
        return [
            [self::IP_KEYS_SQL, ['table' => 'prefix_table_ip_addresses'], ['table' => \PDO::PARAM_STR], self::keys()],
            [self::IP_TABLES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats')],
            [self::IP_INDEXES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], self::rows('prefix_table_page_hits', 'prefix_table_email_stats')],
            [self::MAX_SQL, [], [], $max],
        ];
    }

    /**
     * Returns the queries that make the archive table of ip_addresses, where every key is RESTRICT.
     *
     * @return list<list<mixed>> the queries with their answers
     */
    private static function archivePrepared(): array
    {
        $keysTypes = ['table' => \PDO::PARAM_STR];
        $columns   = ['id int(10) unsigned NO', 'ip_address varchar(45) NO utf8mb4 utf8mb4_unicode_ci', 'ip_details longtext YES utf8mb4 utf8mb4_unicode_ci'];

        return [
            [self::KEYS_SQL, ['table' => 'prefix_table_ip_addresses'], $keysTypes, [['child_table' => 'prefix_table_page_hits', 'child_column' => 'ip_id', 'referenced_column' => 'id', 'delete_rule' => 'RESTRICT']]],
            ['CREATE TABLE IF NOT EXISTS prefix_table_housekeeping_archive_ip_addresses SELECT * FROM prefix_table_ip_addresses LIMIT 0', [], [], null],
            [self::COLUMNS_SQL, ['table' => 'prefix_table_housekeeping_archive_ip_addresses'], $keysTypes, $columns],
            [self::COLUMNS_SQL, ['table' => 'prefix_table_ip_addresses'], $keysTypes, $columns],
        ];
    }

    /**
     * Returns the select of one scan window with its answer.
     *
     * @param int          $lastId the last ID before the window
     * @param int          $toId   the last ID in the window
     * @param list<string> $ids    the IDs the select finds
     * @param int          $limit  the row limit of the select
     * @param string       $unused the condition that no table uses an address
     *
     * @return list<mixed> the query with its answer
     */
    private static function select(int $lastId, int $toId, array $ids, int $limit = 2, string $unused = self::UNUSED): array
    {
        return [self::selectSql($limit, $unused), ['lastId' => $lastId, 'toId' => $toId], self::WINDOW_TYPES, $ids];
    }

    /**
     * Returns the select of a scan window.
     *
     * @param int    $limit  the row limit of the select
     * @param string $unused the condition that no table uses an address
     *
     * @return string the select statement
     */
    private static function selectSql(int $limit, string $unused = self::UNUSED): string
    {
        return 'SELECT a.id FROM prefix_table_ip_addresses a WHERE a.id > :lastId AND a.id <= :toId AND '.$unused.' ORDER BY a.id LIMIT '.$limit;
    }

    /**
     * Returns a batch query on a list of IDs with its answer.
     *
     * @param string    $sql    the statement
     * @param list<int> $ids    the IDs of the batch
     * @param mixed     $answer what the statement returns or throws
     *
     * @return list<mixed> the query with its answer
     */
    private static function batch(string $sql, array $ids, mixed $answer): array
    {
        return [$sql, ['batchIds' => $ids], ['batchIds' => ArrayParameterType::INTEGER], $answer];
    }

    /**
     * Returns the query that lists the tables with the address column again inside a batch.
     *
     * @param list<array{table_name: string, column_name: string}>|null $rows the tables it finds, by default the same as at the start
     *
     * @return list<mixed> the query with its answer
     */
    private static function relist(?array $rows = null): array
    {
        return [self::IP_TABLES_SQL, ['columns' => ['ip_id']], ['columns' => ArrayParameterType::STRING], $rows ?? self::rows('prefix_table_page_hits', 'prefix_table_email_stats')];
    }

    /**
     * Returns the windows of 10000 IDs that cover a range.
     *
     * @param int $from the first ID of the range
     * @param int $to   the last ID of the range
     *
     * @return list<array{0: int, 1: int}> each window as last ID before and last ID in
     */
    private static function windowsOf(int $from, int $to): array
    {
        $windows = [];

        for ($lastId = $from - 1; $lastId < $to; $lastId = min($lastId + 10000, $to)) {
            $windows[] = [$lastId, min($lastId + 10000, $to)];
        }

        return $windows;
    }

    /**
     * Returns the exception that tells a transaction it was chosen as a deadlock victim.
     *
     * @return DeadlockException the deadlock
     */
    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(PdoDriverException::new(new \PDOException('Deadlock found when trying to get lock')), null);
    }

    /**
     * Runs only the ip_addresses cleanup.
     *
     * @param Connection $connection the mocked connection
     * @param int|null   $batchSize  the batch size
     * @param int|null   $maxRows    the row limit
     * @param bool       $archive    true to copy each deleted row first
     *
     * @return string the result message
     */
    private function runIpCleanup(Connection $connection, ?int $batchSize = 2, ?int $maxRows = null, bool $archive = false): string
    {
        return $this->createEventLogCleanup($connection)->deleteEventLogEntries(4, null, false, [EventLogCleanup::IP_ADDRESSES => true], $this->createStub(OutputInterface::class), null, $batchSize, $maxRows, $archive);
    }

    /**
     * Returns a connection that expects these queries in this order, answers each with the value given for it, and records every query and transaction step in $events.
     *
     * @param list<list<mixed>> $calls         each query as SQL, parameters, types and answer
     * @param int               $begins        how many transactions begin
     * @param int               $commits       how many transactions commit
     * @param int               $rollBacks     how many transactions roll back
     * @param bool              $inTransaction true when a transaction is open before the run
     *
     * @return Connection the mocked connection
     */
    private function createIpConnection(array $calls, int $begins, int $commits, int $rollBacks, bool $inTransaction = false): Connection
    {
        $this->events = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')
            ->willReturn($inTransaction);
        $connection->expects($this->exactly($begins))
            ->method('beginTransaction')
            ->willReturnCallback(function (): bool {
                $this->events[] = 'begin';

                return true;
            });
        $connection->expects($this->exactly($commits))
            ->method('commit')
            ->willReturnCallback(function (): bool {
                $this->events[] = 'commit';

                return true;
            });
        $connection->expects($this->exactly($rollBacks))
            ->method('rollBack')
            ->willReturnCallback(function (): bool {
                $this->events[] = 'rollBack';

                return true;
            });
        $connection->expects($this->exactly(count($calls)))
            ->method('executeQuery')
            ->willReturnCallback(function (string $sql, array $params, array $types) use (&$calls): Result {
                $call = array_shift($calls);

                self::assertNotNull($call, 'Unexpected query: '.$sql);
                self::assertSame($call[0], $sql);
                self::assertSame($call[1], $params);
                self::assertSame($call[2], $types);

                $this->events[] = $sql;

                if ($call[3] instanceof \Throwable) {
                    throw $call[3];
                }

                $result = $this->createStub(Result::class);

                if (is_array($call[3])) {
                    $result->method('fetchFirstColumn')->willReturn($call[3]);
                    $result->method('fetchAllAssociative')->willReturn($call[3]);
                    $result->method('fetchNumeric')->willReturn($call[3]);
                } elseif (is_int($call[3])) {
                    $result->method('rowCount')->willReturn($call[3]);
                    $result->method('fetchOne')->willReturn($call[3]);
                } else {
                    $result->method('fetchOne')->willReturn($call[3]);
                }

                return $result;
            });

        return $connection;
    }

    /**
     * Returns a verbose output that adds every line it is given to $lines.
     *
     * @param list<string> $lines the lines written so far
     *
     * @return OutputInterface the output
     */
    private function createVerboseOutput(array &$lines): OutputInterface
    {
        $output = $this->createMock(OutputInterface::class);
        $output->method('isVerbose')
            ->willReturn(true);
        $output->method('writeln')
            ->willReturnCallback(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            });

        return $output;
    }

    /**
     * Returns the cleanup service on this connection, with the plugin published.
     *
     * @param Connection           $connection the mocked connection
     * @param LoggerInterface|null $logger     the logger, a stub when not given
     *
     * @return EventLogCleanup the service
     */
    private function createEventLogCleanup(Connection $connection, ?LoggerInterface $logger = null): EventLogCleanup
    {
        $config = $this->createMock(Config::class);
        $config->method('isPublished')
            ->willReturn(true);

        return new EventLogCleanup($connection, 'prefix_table_', $config, $logger ?? $this->createStub(LoggerInterface::class));
    }
}
