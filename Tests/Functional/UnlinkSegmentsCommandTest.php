<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use MauticPlugin\HousekeepingBundle\Service\EventLogCleanup;
use MauticPlugin\HousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class UnlinkSegmentsCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
        $this->dropArchiveTable();
    }

    protected function beforeTearDown(): void
    {
        $this->dropArchiveTable();
    }

    public function testRemovesTheSegmentsOfAnEmailWhoseStatsAreDeleted(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-closed@test.com');
        $first   = $this->fixtureHelper->createSegment('unlink-closed-first');
        $second  = $this->fixtureHelper->createSegment('unlink-closed-second');
        $email   = $this->fixtureHelper->createSegmentEmail('Unlink Closed Email', false, [$first, $second]);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([], $this->segmentIdsOf($email));
        $this->assertSame([
            [(int) $email->getId(), (int) $first->getId()],
            [(int) $email->getId(), (int) $second->getId()],
        ], $this->archivedLinks());

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 email_stats rows have been deleted.', $output);
        $this->assertStringContainsString('2 segment links of 1 emails were removed first and kept in '.MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.EventLogCleanup::EMAIL_LIST_XREF.'.', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testKeepsTheSegmentsOfAClosedEmailThatLosesNoStats(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-recent@test.com');
        $segment = $this->fixtureHelper->createSegment('unlink-recent');
        $email   = $this->fixtureHelper->createSegmentEmail('Unlink Recent Email', false, [$segment]);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(10));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($email));
        $this->assertSame([], $this->archivedLinks());
        $this->assertStringContainsString('0 segment links of 0 emails were removed first', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testKeepsTheStatsOfAClosedVariantWhileItsParentIsOpen(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-variant@test.com');
        $segment = $this->fixtureHelper->createSegment('unlink-variant');
        $parent  = $this->fixtureHelper->createSegmentEmail('Unlink Open Parent', true, [$segment]);
        $variant = $this->fixtureHelper->createSegmentEmail('Unlink Closed Variant', false, [$segment], $parent);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $variant, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($parent));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($variant));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('0 email_stats rows have been deleted.', $output);
        $this->assertStringContainsString('1 closed emails keep their email_stats, because a variant or translation of each is still open or excluded.', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testKeepsTheWholeFamilyWhenOneOfItsEmailsIsExcluded(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact     = $this->fixtureHelper->createContact('unlink-excluded@test.com');
        $segment     = $this->fixtureHelper->createSegment('unlink-excluded');
        $parent      = $this->fixtureHelper->createSegmentEmail('Unlink Excluded Parent', false, [$segment]);
        $translation = $this->fixtureHelper->createSegmentEmail('Unlink Excluded Translation', false, [$segment], null, $parent);
        $parentStat  = $this->fixtureHelper->createEmailStat($contact, $parent, $this->fixtureHelper->daysAgo(400));
        $stat        = $this->fixtureHelper->createEmailStat($contact, $translation, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--exclude-emails'  => (string) $parent->getId(),
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($parentStat->getId()));
        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($parent));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($translation));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('0 email_stats rows have been deleted.', $output);
        $this->assertStringContainsString('0 segment links of 0 emails were removed first', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testARunDoesNotKeepTheConditionsOfAnEarlierRun(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-rerun@test.com');
        $segment = $this->fixtureHelper->createSegment('unlink-rerun');
        $parent  = $this->fixtureHelper->createSegmentEmail('Unlink Rerun Parent', true, [$segment]);
        $variant = $this->fixtureHelper->createSegmentEmail('Unlink Rerun Variant', false, [$segment], $parent);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $variant, $this->fixtureHelper->daysAgo(400));

        $firstRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
            '--dry-run'         => true,
        ]);

        $this->assertStringContainsString('1 closed emails keep their email_stats', $firstRun->getDisplay());

        $this->em->getConnection()->update(MAUTIC_TABLE_PREFIX.'emails', ['is_published' => 0], ['id' => (int) $parent->getId()]);

        $secondRun = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([], $this->segmentIdsOf($parent));
        $this->assertSame([], $this->segmentIdsOf($variant));
        $this->assertStringContainsString('2 segment links of 2 emails were removed first', $secondRun->getDisplay());
        $this->assertSame(0, $secondRun->getStatusCode());
    }

    public function testRemovesTheSegmentsOfEveryEmailInAClosedFamily(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact     = $this->fixtureHelper->createContact('unlink-family@test.com');
        $segment     = $this->fixtureHelper->createSegment('unlink-family');
        $parent      = $this->fixtureHelper->createSegmentEmail('Unlink Closed Parent', false, [$segment]);
        $translation = $this->fixtureHelper->createSegmentEmail('Unlink Closed Translation', false, [$segment], null, $parent);
        $stat        = $this->fixtureHelper->createEmailStat($contact, $translation, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([], $this->segmentIdsOf($parent));
        $this->assertSame([], $this->segmentIdsOf($translation));
        $this->assertStringContainsString('2 segment links of 2 emails were removed first', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testDryRunCountsTheLinksAndChangesNothing(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-dry@test.com');
        $segment = $this->fixtureHelper->createSegment('unlink-dry');
        $email   = $this->fixtureHelper->createSegmentEmail('Unlink Dry Email', false, [$segment]);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'        => 30,
            '--email-stats'     => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
            '--dry-run'         => true,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($email));
        $this->assertFalse($this->archiveTableExists());
        $this->assertStringContainsString('1 segment links of 1 emails would be removed.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testLeavesTheSegmentsAloneWithoutTheOption(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('unlink-off@test.com');
        $segment = $this->fixtureHelper->createSegment('unlink-off');
        $email   = $this->fixtureHelper->createSegmentEmail('Unlink Off Email', false, [$segment]);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--email-stats' => true,
            '--batch-size'  => 1,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertSame([(int) $segment->getId()], $this->segmentIdsOf($email));
        $this->assertStringNotContainsString('segment links', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testNeedsEmailStats(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--page-hits'       => true,
            '--unlink-segments' => true,
            '--batch-size'      => 1,
        ]);

        $this->assertStringContainsString('--unlink-segments needs --email-stats.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testNeedsABatchSize(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--email-stats'     => true,
            '--unlink-segments' => true,
        ]);

        $this->assertStringContainsString('--unlink-segments needs --batch-size.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Reads the segments an email is sent to.
     *
     * @param Email $email the email to read
     *
     * @return list<int> the segment IDs in ascending order
     */
    private function segmentIdsOf(Email $email): array
    {
        return array_map(intval(...), $this->em->getConnection()->fetchFirstColumn(
            'SELECT leadlist_id FROM '.MAUTIC_TABLE_PREFIX.EventLogCleanup::EMAIL_LIST_XREF.' WHERE email_id = ? ORDER BY leadlist_id',
            [(int) $email->getId()]
        ));
    }

    /**
     * Reads the segment links kept in the archive table, or none when the table does not exist.
     *
     * @return list<array{0: int, 1: int}> each link as its email ID and segment ID, in ascending order
     */
    private function archivedLinks(): array
    {
        if (!$this->archiveTableExists()) {
            return [];
        }

        return array_map(
            static fn (array $row): array => [(int) $row[0], (int) $row[1]],
            $this->em->getConnection()->fetchAllNumeric('SELECT email_id, leadlist_id FROM '.$this->archiveTable().' ORDER BY email_id, leadlist_id')
        );
    }

    /**
     * Tells whether the archive table for segment links exists.
     *
     * @return bool true when the table exists
     */
    private function archiveTableExists(): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->archiveTable()]
        );
    }

    /**
     * Returns the name of the archive table for segment links, with the table prefix.
     *
     * @return string the table name
     */
    private function archiveTable(): string
    {
        return MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.EventLogCleanup::EMAIL_LIST_XREF;
    }

    /**
     * Drops the archive table for segment links, so each test starts and ends without it.
     */
    private function dropArchiveTable(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS '.$this->archiveTable());
    }
}
