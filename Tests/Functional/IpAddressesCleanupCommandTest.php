<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Doctrine\DBAL\DriverManager;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class IpAddressesCleanupCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    /**
     * Enables the plugin before each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
        $this->fixtureHelper->enablePlugin();
    }

    /**
     * Deletes the address no table uses and keeps the ones a page hit, a device row and a contact use.
     */
    public function testDeletesOnlyTheAddressesNoTableUses(): void
    {
        $contact = $this->fixtureHelper->createContact('ip-users@test.com');
        $recent  = $this->fixtureHelper->daysAgo(10);
        $hit     = $this->fixtureHelper->createPageHit($contact, $recent);
        $device  = $this->fixtureHelper->createEmailStatDevice($this->fixtureHelper->createEmailStat($contact, null, $recent), $recent);
        $linked  = $this->fixtureHelper->createIpAddress('192.0.2.10');
        $this->fixtureHelper->addIpAddressToContact($contact, $linked);

        $unused = $this->fixtureHelper->createIpAddress('192.0.2.11');
        $this->keepNewestPast();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--batch-size'   => 10,
        ]);

        $this->assertTrue($this->addressExists((int) $hit->getIpAddress()->getId()));
        $this->assertTrue($this->addressExists((int) $device->getIpAddress()->getId()));
        $this->assertTrue($this->addressExists((int) $linked->getId()));
        $this->assertFalse($this->addressExists((int) $unused->getId()));

        $this->assertStringContainsString('1 ip_addresses rows have been deleted.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Leaves the newest addresses alone, even when no table uses them.
     * The highest ID depends on the tests run before, because a rollback does not take back an auto-increment value, so the expected message is worked out from it.
     */
    public function testKeepsTheNewestAddresses(): void
    {
        $unusedId = (int) $this->fixtureHelper->createIpAddress('192.0.2.12')->getId();
        $upper    = $this->maxAddressId() - EventLogCleanup::IP_KEEP_NEWEST;

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--batch-size'   => 10,
        ]);

        $this->assertGreaterThan($upper, $unusedId);
        $this->assertTrue($this->addressExists($unusedId));
        $this->assertStringContainsString(
            $upper >= 1 ? 'checked every ID up to '.$upper.' and left out the newest '.EventLogCleanup::IP_KEEP_NEWEST.' IDs.' : 'checked no IDs',
            $commandTester->getDisplay()
        );
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Keeps an address that only an archived page hit uses, indexes the archive table, and lets the hit be put back.
     */
    public function testKeepsAnAddressOnlyAnArchivedRowUses(): void
    {
        $this->dropArchiveTables();

        try {
            $contact = $this->fixtureHelper->createContact('ip-archived@test.com');
            $hit     = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(400));
            $hitId   = (int) $hit->getId();
            $hitIp   = (int) $hit->getIpAddress()->getId();
            $unused  = $this->fixtureHelper->createIpAddress('192.0.2.13');

            $archiveRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--days-old'   => 30,
                '--page-hits'  => true,
                '--batch-size' => 1,
                '--archive'    => true,
            ]);

            $this->assertStringContainsString('1 page_hits rows have been deleted.', $archiveRun->getDisplay());

            $this->keepNewestPast();

            $ipRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--ip-addresses' => true,
                '--batch-size'   => 10,
            ]);

            $this->assertSame(0, $ipRun->getStatusCode());
            $this->assertTrue($this->addressExists($hitIp));
            $this->assertFalse($this->addressExists((int) $unused->getId()));

            $connection   = $this->em->getConnection();
            $archiveTable = MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.'page_hits';

            $this->assertSame(1, (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = 'housekeeping_ip_id'",
                ['table' => $archiveTable]
            ));
            $this->assertSame(1, (int) $connection->executeStatement(
                'INSERT INTO '.MAUTIC_TABLE_PREFIX.'page_hits SELECT * FROM '.$archiveTable.' WHERE id = :id',
                ['id' => $hitId]
            ));
        } finally {
            $this->dropArchiveTables();
        }
    }

    /**
     * Counts a table as a user through its foreign key, even when the key cascades on delete.
     */
    public function testCountsATableWithAnyKeyToIpAddresses(): void
    {
        $connection = $this->em->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'housekeeping_test_ip_user';
        $idType     = (string) $connection->fetchOne(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = 'id'",
            ['table' => MAUTIC_TABLE_PREFIX.'ip_addresses']
        );

        $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        $connection->executeStatement('CREATE TABLE '.$table.' (id INT UNSIGNED AUTO_INCREMENT NOT NULL PRIMARY KEY, ip_id '.$idType.' NOT NULL, CONSTRAINT '.$table.'_ip_id FOREIGN KEY (ip_id) REFERENCES '.MAUTIC_TABLE_PREFIX.'ip_addresses (id) ON DELETE CASCADE) ENGINE=InnoDB');

        try {
            $used   = $this->fixtureHelper->createIpAddress('192.0.2.14');
            $unused = $this->fixtureHelper->createIpAddress('192.0.2.15');
            $connection->insert($table, ['ip_id' => $used->getId()]);
            $this->keepNewestPast();

            $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--ip-addresses' => true,
                '--batch-size'   => 10,
            ]);

            $this->assertSame(0, $commandTester->getStatusCode());
            $this->assertTrue($this->addressExists((int) $used->getId()));
            $this->assertFalse($this->addressExists((int) $unused->getId()));
            $this->assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM '.$table));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /**
     * Stops before it deletes any address when a table that is not an archive has the address column with no foreign key, even with an index on it.
     */
    public function testRejectsATableWithAnAddressColumnButNoForeignKey(): void
    {
        $connection = $this->em->getConnection();
        $table      = MAUTIC_TABLE_PREFIX.'housekeeping_test_ip_copy';

        $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        $connection->executeStatement('CREATE TABLE '.$table.' (ip_id INT UNSIGNED NULL, KEY housekeeping_test_ip_id (ip_id)) ENGINE=InnoDB');

        try {
            $unused = $this->fixtureHelper->createIpAddress('192.0.2.16');
            $this->keepNewestPast();

            $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--ip-addresses' => true,
                '--batch-size'   => 10,
            ]);

            $this->assertSame(1, $commandTester->getStatusCode());
            $this->assertStringContainsString('has no foreign key to it, so a row added there would not wait for the delete', $commandTester->getDisplay());
            $this->assertTrue($this->addressExists((int) $unused->getId()));
        } finally {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
    }

    /**
     * Skips an address that an open transaction of another connection is linking to a contact, and deletes the rest.
     */
    public function testSkipsAnAddressThatALiveTransactionIsUsing(): void
    {
        $contact = $this->fixtureHelper->createContact('ip-live@test.com');
        $live    = $this->fixtureHelper->createIpAddress('192.0.2.17');
        $unused  = $this->fixtureHelper->createIpAddress('192.0.2.18');
        $this->keepNewestPast();

        $other = DriverManager::getConnection($this->em->getConnection()->getParams());

        try {
            $other->beginTransaction();
            $other->insert(MAUTIC_TABLE_PREFIX.'lead_ips_xref', ['lead_id' => $contact->getId(), 'ip_id' => $live->getId()]);

            $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--ip-addresses' => true,
                '--batch-size'   => 10,
            ]);

            $this->assertSame(0, $commandTester->getStatusCode());
            $this->assertTrue($this->addressExists((int) $live->getId()));
            $this->assertFalse($this->addressExists((int) $unused->getId()));
            $this->assertStringContainsString('1 ip_addresses rows were in use or locked', $commandTester->getDisplay());
        } finally {
            if ($other->isTransactionActive()) {
                $other->rollBack();
            }

            $other->close();
        }
    }

    /**
     * Deletes in each slice only the addresses of its own blocks, and names the slice.
     */
    public function testSliceOnlyTouchesItsOwnBlocks(): void
    {
        $block  = intdiv($this->maxAddressId(), EventLogCleanup::IP_SLICE_BLOCK) + 1;
        $first  = $block * EventLogCleanup::IP_SLICE_BLOCK + 5;
        $second = ($block + 1) * EventLogCleanup::IP_SLICE_BLOCK + 5;
        $slice  = $block % 2 + 1;

        $this->fixtureHelper->createIpAddressWithId($first);
        $this->fixtureHelper->createIpAddressWithId($second, '192.0.2.2');
        $this->keepNewestPast();

        $firstRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--ip-slice'     => $slice.'/2',
            '--batch-size'   => 10,
        ]);

        $this->assertFalse($this->addressExists($first));
        $this->assertTrue($this->addressExists($second));
        $this->assertStringContainsString('checked slice '.$slice.' of 2 of the IDs', $firstRun->getDisplay());

        $secondRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--ip-slice'     => (3 - $slice).'/2',
            '--batch-size'   => 10,
        ]);

        $this->assertFalse($this->addressExists($second));
        $this->assertStringContainsString('checked slice '.(3 - $slice).' of 2 of the IDs', $secondRun->getDisplay());
    }

    /**
     * Counts the unused addresses on a dry run and deletes none.
     */
    public function testDryRunCountsWithoutDeleting(): void
    {
        $unused = $this->fixtureHelper->createIpAddress('192.0.2.19');
        $this->keepNewestPast();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--dry-run'      => true,
            '--batch-size'   => 10,
        ]);

        $this->assertTrue($this->addressExists((int) $unused->getId()));
        $this->assertStringContainsString('1 ip_addresses rows would have been deleted. This is a dry run.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Copies every address it deletes to the archive table.
     */
    public function testArchiveKeepsACopyOfEveryDeletedAddress(): void
    {
        $this->dropArchiveTables();

        try {
            $first  = $this->fixtureHelper->createIpAddress('192.0.2.20');
            $second = $this->fixtureHelper->createIpAddress('192.0.2.21');
            $this->keepNewestPast();

            $before        = $this->addressIds();
            $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
                '--ip-addresses' => true,
                '--batch-size'   => 10,
                '--archive'      => true,
            ]);
            $deleted = array_values(array_diff($before, $this->addressIds()));

            $this->assertContains((int) $first->getId(), $deleted);
            $this->assertContains((int) $second->getId(), $deleted);
            $this->assertSame($deleted, $this->archivedAddressIds());
            $this->assertStringContainsString('Every deleted or changed row was copied to a housekeeping_archive_ table first.', $commandTester->getDisplay());
            $this->assertSame(0, $commandTester->getStatusCode());
        } finally {
            $this->dropArchiveTables();
        }
    }

    /**
     * Deletes the lowest unused addresses up to the row limit, leaves the rest, and says the ID it stopped at.
     */
    public function testStopsAtTheRowLimit(): void
    {
        $first  = $this->fixtureHelper->createIpAddress('192.0.2.22');
        $second = $this->fixtureHelper->createIpAddress('192.0.2.23');
        $third  = $this->fixtureHelper->createIpAddress('192.0.2.24');
        $this->keepNewestPast();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--ip-addresses' => true,
            '--batch-size'   => 5,
            '--max-rows'     => 2,
        ]);

        $this->assertFalse($this->addressExists((int) $first->getId()));
        $this->assertFalse($this->addressExists((int) $second->getId()));
        $this->assertTrue($this->addressExists((int) $third->getId()));
        $this->assertStringContainsString('Stopped at the limit of 2 rows for this run.', $commandTester->getDisplay());
        $this->assertStringContainsString('The ip_addresses cleanup stopped at ID '.$second->getId().', before it checked every ID up to', $commandTester->getDisplay());
        $this->assertStringNotContainsString('cleanup checked every ID', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Leaves unused addresses alone in the run with no flags.
     */
    public function testDefaultCleanupLeavesIpAddressesAlone(): void
    {
        $unused = $this->fixtureHelper->createIpAddress('192.0.2.25');
        $this->keepNewestPast();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping');

        $this->assertTrue($this->addressExists((int) $unused->getId()));
        $this->assertStringNotContainsString('ip_addresses', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Adds an address far above every other, so a run checks every address made before it.
     */
    private function keepNewestPast(): void
    {
        $this->fixtureHelper->createIpAddressWithId($this->maxAddressId() + EventLogCleanup::IP_KEEP_NEWEST + 1, '192.0.2.250');
    }

    /**
     * Returns the highest ID in ip_addresses.
     *
     * @return int the highest ID, 0 when the table is empty
     */
    private function maxAddressId(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT MAX(id) FROM '.MAUTIC_TABLE_PREFIX.'ip_addresses');
    }

    /**
     * Tells whether ip_addresses holds a row with this ID.
     *
     * @param int $id the ID to look for
     *
     * @return bool true when the row exists
     */
    private function addressExists(int $id): bool
    {
        return false !== $this->em->getConnection()->fetchOne('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'ip_addresses WHERE id = :id', ['id' => $id]);
    }

    /**
     * Lists every ID in ip_addresses.
     *
     * @return list<int> the IDs in order
     */
    private function addressIds(): array
    {
        return array_map(intval(...), $this->em->getConnection()->fetchFirstColumn('SELECT id FROM '.MAUTIC_TABLE_PREFIX.'ip_addresses ORDER BY id'));
    }

    /**
     * Lists every ID in the archive table of ip_addresses.
     *
     * @return list<int> the IDs in order
     */
    private function archivedAddressIds(): array
    {
        return array_map(intval(...), $this->em->getConnection()->fetchFirstColumn('SELECT id FROM '.MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.'ip_addresses ORDER BY id'));
    }

    /**
     * Drops the archive tables these tests can create.
     */
    private function dropArchiveTables(): void
    {
        foreach (['page_hits', 'email_stats', 'email_stats_devices', 'email_stat_replies', 'ip_addresses'] as $table) {
            $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.$table);
        }
    }
}
