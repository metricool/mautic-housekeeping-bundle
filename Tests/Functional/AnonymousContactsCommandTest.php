<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\HousekeepingBundle\Service\EventLogCleanup;
use MauticPlugin\HousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class AnonymousContactsCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
        $this->dropArchiveTables();
    }

    protected function beforeTearDown(): void
    {
        $this->dropArchiveTables();
        $this->em->getConnection()->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX."lead_fields SET is_unique_identifer = 0 WHERE alias = 'phone'");
    }

    public function testKeepsAContactWithAValueInACustomIdentifierField(): void
    {
        $this->fixtureHelper->enablePlugin();

        $connection = $this->em->getConnection();
        $connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX."lead_fields SET is_unique_identifer = 1 WHERE alias = 'phone'");

        $withPhone    = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $withoutPhone = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $withPhoneId  = (int) $withPhone->getId();

        $connection->executeStatement('UPDATE '.MAUTIC_TABLE_PREFIX.'leads SET phone = ? WHERE id = ?', ['555 0100', $withPhoneId]);

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old'           => 30,
            '--anonymous-contacts' => true,
            '--batch-size'         => 1,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Lead::class)->find($withPhoneId));
        $this->assertNull($this->em->getRepository(Lead::class)->find($withoutPhone->getId()));
        $this->assertStringContainsString('1 leads rows have been deleted.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testDeletesOnlyAnonymousContactsThatWereNeverActive(): void
    {
        $this->fixtureHelper->enablePlugin();

        $old        = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $recent     = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(10));
        $identified = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400), $this->fixtureHelper->daysAgo(300));
        $active     = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400), null, $this->fixtureHelper->daysAgo(300));
        $visitor    = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $withEmail  = $this->fixtureHelper->createContact('anonymous-email@test.com');
        $segment    = $this->fixtureHelper->createSegment('anonymous-old');

        $this->em->getConnection()->executeStatement(
            'UPDATE '.MAUTIC_TABLE_PREFIX.'leads SET date_identified = NULL, date_added = ? WHERE id = ?',
            [$this->fixtureHelper->daysAgo(400)->format('Y-m-d H:i:s'), (int) $withEmail->getId()]
        );

        $this->fixtureHelper->createPageHit($visitor, $this->fixtureHelper->daysAgo(10));
        $this->fixtureHelper->addContactToSegment($old, $segment);
        $this->fixtureHelper->createLeadEventLog($old, $this->fixtureHelper->daysAgo(5));

        $oldId = (int) $old->getId();

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old'           => 30,
            '--anonymous-contacts' => true,
            '--batch-size'         => 1,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(Lead::class)->find($oldId));

        foreach ([$recent, $identified, $active, $visitor, $withEmail] as $kept) {
            $this->assertNotNull($this->em->getRepository(Lead::class)->find($kept->getId()));
        }

        $this->assertSame(0, $this->countRows('lead_lists_leads', $oldId));
        $this->assertSame(0, $this->countRows('lead_event_log', $oldId));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 leads rows have been deleted.', $output);
        $this->assertStringNotContainsString('cascade removed', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testArchiveKeepsTheContactsAndTheirRowsSoTheyCanBePutBack(): void
    {
        $this->fixtureHelper->enablePlugin();

        $old     = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $segment = $this->fixtureHelper->createSegment('anonymous-archive');

        $this->fixtureHelper->addContactToSegment($old, $segment);
        $this->fixtureHelper->createLeadEventLog($old, $this->fixtureHelper->daysAgo(5));
        $this->fixtureHelper->addIpAddressToContact($old, $this->fixtureHelper->createIpAddress('192.0.2.7'));

        $oldId = (int) $old->getId();

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old'           => 30,
            '--anonymous-contacts' => true,
            '--batch-size'         => 1,
            '--archive'            => true,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(Lead::class)->find($oldId));
        $this->assertSame(1, $this->countRows(EventLogCleanup::ARCHIVE_PREFIX.'leads', $oldId, 'id'));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 leads rows have been deleted. With the contacts, the cascade removed 1 lead_event_log, 1 lead_ips_xref and 1 lead_lists_leads rows.', $output);
        $this->assertStringContainsString('Every deleted or changed row was copied to a '.EventLogCleanup::ARCHIVE_PREFIX.' table first.', $output);
        $this->assertSame(0, $commandTester->getStatusCode());

        $this->putBack();

        $this->assertNotNull($this->em->getRepository(Lead::class)->find($oldId));
        $this->assertSame(1, $this->countRows('lead_lists_leads', $oldId));
        $this->assertSame(1, $this->countRows('lead_event_log', $oldId));
        $this->assertSame(1, $this->countRows('lead_ips_xref', $oldId));
    }

    public function testDryRunCountsTheContactsAndTheirRowsAndChangesNothing(): void
    {
        $this->fixtureHelper->enablePlugin();

        $old     = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $segment = $this->fixtureHelper->createSegment('anonymous-dry');

        $this->fixtureHelper->addContactToSegment($old, $segment);
        $this->fixtureHelper->createLeadEventLog($old, $this->fixtureHelper->daysAgo(5));

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old'           => 30,
            '--anonymous-contacts' => true,
            '--batch-size'         => 1,
            '--dry-run'            => true,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Lead::class)->find($old->getId()));
        $this->assertFalse($this->archiveTableExists('leads'));
        $this->assertStringContainsString('1 leads rows would have been deleted. With the contacts, the cascade would remove 1 lead_event_log and 1 lead_lists_leads rows. This is a dry run.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testIsNotPartOfTheDefaultCleanup(): void
    {
        $this->fixtureHelper->enablePlugin();

        $old = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old' => 30,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Lead::class)->find($old->getId()));
        $this->assertStringNotContainsString(' leads rows', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testStopsWhenARowBelowACascadeTableWouldChangeWithoutACopy(): void
    {
        $this->fixtureHelper->enablePlugin();

        $old      = $this->fixtureHelper->createAnonymousContact($this->fixtureHelper->daysAgo(400));
        $campaign = $this->fixtureHelper->createCampaign('Anonymous Campaign');
        $event    = $this->fixtureHelper->createCampaignEvent($campaign);
        $log      = $this->fixtureHelper->createCampaignLeadEventLog($old, $campaign, $event, $this->fixtureHelper->daysAgo(5));

        $this->fixtureHelper->createFailedCampaignEvent($log);

        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--days-old'           => 30,
            '--anonymous-contacts' => true,
            '--batch-size'         => 1,
            '--archive'            => true,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Lead::class)->find($old->getId()));
        $this->assertStringContainsString('Archive check failed on '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_failed_log: 1 rows point at '.MAUTIC_TABLE_PREFIX.'campaign_lead_event_log rows of the batch and would change without a copy. The batch was rolled back.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testNeedsABatchSize(): void
    {
        $commandTester = $this->testSymfonyCommand('mautic:housekeeping', [
            '--anonymous-contacts' => true,
        ]);

        $this->assertStringContainsString('--anonymous-contacts needs --batch-size.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Counts the rows of a table that point at a contact.
     *
     * @param string $table     the table without the prefix
     * @param int    $contactId the contact's ID
     * @param string $column    the column that holds the contact's ID
     *
     * @return int the number of rows
     */
    private function countRows(string $table, int $contactId, string $column = 'lead_id'): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.$table.' WHERE '.$column.' = ?',
            [$contactId]
        );
    }

    /**
     * Puts the archived contacts back, then their rows, the way the README describes.
     */
    private function putBack(): void
    {
        $connection = $this->em->getConnection();
        $columns    = implode(', ', $connection->fetchFirstColumn(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND EXTRA NOT LIKE '%GENERATED%' ORDER BY ORDINAL_POSITION",
            [MAUTIC_TABLE_PREFIX.'leads']
        ));

        $connection->executeStatement('INSERT INTO '.MAUTIC_TABLE_PREFIX.'leads ('.$columns.') SELECT '.$columns.' FROM '.$this->archiveTable('leads'));

        foreach (['lead_event_log', 'lead_ips_xref', 'lead_lists_leads'] as $table) {
            $connection->executeStatement('INSERT INTO '.MAUTIC_TABLE_PREFIX.$table.' SELECT * FROM '.$this->archiveTable($table));
        }

        $this->em->clear();
    }

    /**
     * Tells whether the archive table of a table exists.
     *
     * @param string $table the archived table without the prefix
     *
     * @return bool true when the archive table exists
     */
    private function archiveTableExists(string $table): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->archiveTable($table)]
        );
    }

    /**
     * Returns the name of the archive table of a table, with the prefix.
     *
     * @param string $table the archived table without the prefix
     *
     * @return string the archive table's name
     */
    private function archiveTable(string $table): string
    {
        return MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.$table;
    }

    /**
     * Drops every archive table, so each test starts and ends without them.
     */
    private function dropArchiveTables(): void
    {
        $connection = $this->em->getConnection();
        $tables     = $connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?',
            [MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.'%']
        );

        foreach ($tables as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$table);
        }
    }
}
