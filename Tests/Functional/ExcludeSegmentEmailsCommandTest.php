<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class ExcludeSegmentEmailsCommandTest extends MauticMysqlTestCase
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

    public function testKeepsTheStatsOfAnEmailThatAnUnpublishedSegmentNames(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact   = $this->fixtureHelper->createContact('segment-named@test.com');
        $named     = $this->fixtureHelper->createEmail('Segment Named Email', false);
        $other     = $this->fixtureHelper->createEmail('Segment Other Email', false);
        $namedStat = $this->fixtureHelper->createEmailStat($contact, $named, $this->fixtureHelper->daysAgo(400));
        $otherStat = $this->fixtureHelper->createEmailStat($contact, $other, $this->fixtureHelper->daysAgo(400));
        $segment   = $this->fixtureHelper->createSegment('segment-named', [$this->filter('lead_email_received', [(string) $named->getId()])]);

        $segment->setIsPublished(false);
        $this->em->flush();

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($namedStat->getId()));
        $this->assertNull($this->em->getRepository(EmailStat::class)->find($otherStat->getId()));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Segment filters name 1 emails, which the run excludes too: '.$named->getId().'.', $output);
        $this->assertStringContainsString('1 email_stats rows have been deleted.', $output);
        $this->assertStringNotContainsString('Warning: segments', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testReadsTheOlderFilterKey(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('segment-legacy@test.com');
        $named   = $this->fixtureHelper->createEmail('Segment Legacy Email', false);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $named, $this->fixtureHelper->daysAgo(400));

        $this->fixtureHelper->createSegment('segment-legacy', [[
            'glue'     => 'and',
            'field'    => 'lead_email_sent',
            'object'   => 'behaviors',
            'type'     => 'lead_email_sent',
            'operator' => '!in',
            'filter'   => [(string) $named->getId()],
        ]]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertStringContainsString('Segment filters name 1 emails', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testReadsOnlyTheFieldsItIsGiven(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact  = $this->fixtureHelper->createContact('segment-fields@test.com');
        $listed   = $this->fixtureHelper->createEmail('Segment Listed Field Email', true);
        $unlisted = $this->fixtureHelper->createEmail('Segment Unlisted Field Email', true);
        $kept     = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(400), $listed);
        $deleted  = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(400), $unlisted);

        $this->fixtureHelper->createSegment('segment-fields', [
            $this->filter('plugin_email_clicked', [(string) $listed->getId()]),
            $this->filter('other_plugin_field', [(string) $unlisted->getId()]),
        ]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--page-hits'              => true,
            '--exclude-segment-emails' => 'plugin_email_clicked',
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(Hit::class)->find($kept->getId()));
        $this->assertNull($this->em->getRepository(Hit::class)->find($deleted->getId()));
        $this->assertStringContainsString('Segment filters name 1 emails, which the run excludes too: '.$listed->getId().'.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testAddsToTheExcludedEmails(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact     = $this->fixtureHelper->createContact('segment-merge@test.com');
        $excluded    = $this->fixtureHelper->createEmail('Segment Merge Excluded Email', false);
        $named       = $this->fixtureHelper->createEmail('Segment Merge Named Email', false);
        $other       = $this->fixtureHelper->createEmail('Segment Merge Other Email', false);
        $excludedRow = $this->fixtureHelper->createEmailStat($contact, $excluded, $this->fixtureHelper->daysAgo(400));
        $namedRow    = $this->fixtureHelper->createEmailStat($contact, $named, $this->fixtureHelper->daysAgo(400));
        $otherRow    = $this->fixtureHelper->createEmailStat($contact, $other, $this->fixtureHelper->daysAgo(400));

        $this->fixtureHelper->createSegment('segment-merge', [$this->filter('lead_email_sent', [(string) $named->getId()])]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-emails'         => (string) $excluded->getId(),
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($excludedRow->getId()));
        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($namedRow->getId()));
        $this->assertNull($this->em->getRepository(EmailStat::class)->find($otherRow->getId()));
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testKeepsTheWholeFamilyOfANamedEmailWithUnlinkSegments(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact        = $this->fixtureHelper->createContact('segment-family@test.com');
        $target         = $this->fixtureHelper->createSegment('segment-family-target');
        $parent         = $this->fixtureHelper->createSegmentEmail('Segment Family Parent', false, [$target]);
        $translation    = $this->fixtureHelper->createSegmentEmail('Segment Family Translation', false, [$target], null, $parent);
        $parentRow      = $this->fixtureHelper->createEmailStat($contact, $parent, $this->fixtureHelper->daysAgo(400));
        $translationRow = $this->fixtureHelper->createEmailStat($contact, $translation, $this->fixtureHelper->daysAgo(400));

        $this->fixtureHelper->createSegment('segment-family-filter', [$this->filter('lead_email_received', [(string) $parent->getId()])]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--unlink-segments'        => true,
            '--exclude-segment-emails' => null,
            '--batch-size'             => 1,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($parentRow->getId()));
        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($translationRow->getId()));
        $this->assertSame(2, $this->segmentLinkCount($target->getId()));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('0 email_stats rows have been deleted.', $output);
        $this->assertStringContainsString('0 segment links of 0 emails were removed first', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testWarnsAboutSegmentsThatNameNoEmail(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact         = $this->fixtureHelper->createContact('segment-no-email@test.com');
        $email           = $this->fixtureHelper->createEmail('Segment Stale Value Email', false);
        $stat            = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));
        $dateSegment     = $this->fixtureHelper->createSegment('segment-date', [$this->filter('lead_email_read_date', '2025-01-01', 'gt')]);
        $emptySegment    = $this->fixtureHelper->createSegment('segment-empty', [$this->filter('lead_email_received', ['first-email'], 'empty')]);
        $notEmptySegment = $this->fixtureHelper->createSegment('segment-not-empty', [$this->filter('lead_email_sent', [(string) $email->getId()], '!empty')]);

        $this->fixtureHelper->createSegment('segment-unrelated', [$this->filter('city', 'Berlin', '=')]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('Segment filters name 0 emails, which the run excludes too.', $output);
        $this->assertStringContainsString('Warning: segments '.$dateSegment->getId().', '.$emptySegment->getId().', '.$notEmptySegment->getId().' filter on email activity without naming an email', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testReadsTheFiltersHeldByACustomOperator(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('segment-custom-operator@test.com');
        $named   = $this->fixtureHelper->createEmail('Segment Custom Operator Email', false);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $named, $this->fixtureHelper->daysAgo(400));

        $this->fixtureHelper->createSegment('segment-custom-operator', [[
            'glue'       => 'and',
            'operator'   => 'custom_operator',
            'properties' => [
                ['glue' => 'or', 'field' => 'city', 'object' => 'lead', 'type' => 'text', 'operator' => '=', 'filter' => 'Berlin'],
                ['glue' => 'or', 'field' => 'lead_email_sent', 'object' => 'behaviors', 'type' => 'lead_email_sent', 'operator' => 'in', 'filter' => [(string) $named->getId()]],
            ],
        ]]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertStringContainsString('Segment filters name 1 emails, which the run excludes too: '.$named->getId().'.', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testStopsBeforeAnyWriteWhenAFilterHasNoField(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('segment-no-field@test.com');
        $email   = $this->fixtureHelper->createEmail('Segment No Field Email', false);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));
        $segment = $this->fixtureHelper->createSegment('segment-no-field', [[
            'glue'       => 'and',
            'operator'   => 'in',
            'properties' => ['filter' => [(string) $email->getId()]],
        ]]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertStringContainsString('Nothing was deleted, because the segment filters could not be read: Segment '.$segment->getId().' has a filter that cannot be read.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testStopsBeforeAnyWriteWhenAFilterValueIsNotAnEmailId(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('segment-bad-value@test.com');
        $email   = $this->fixtureHelper->createEmail('Segment Bad Value Email', false);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));
        $segment = $this->fixtureHelper->createSegment('segment-bad-value', [$this->filter('lead_email_received', ['first-email'])]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertStringContainsString('Nothing was deleted, because the segment filters could not be read: Segment '.$segment->getId().' has a value in its lead_email_received filter that is not an email ID.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testStopsBeforeAnyWriteWhenTheFiltersCannotBeRead(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('segment-unreadable@test.com');
        $email   = $this->fixtureHelper->createEmail('Segment Unreadable Email', false);
        $stat    = $this->fixtureHelper->createEmailStat($contact, $email, $this->fixtureHelper->daysAgo(400));
        $segment = $this->fixtureHelper->createSegment('segment-unreadable');

        $this->em->getConnection()->update(MAUTIC_TABLE_PREFIX.'lead_lists', ['filters' => 'not serialized'], ['id' => (int) $segment->getId()]);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'               => 30,
            '--email-stats'            => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->em->clear();

        $this->assertNotNull($this->em->getRepository(EmailStat::class)->find($stat->getId()));
        $this->assertStringContainsString('Nothing was deleted, because the segment filters could not be read: The filters of segment '.$segment->getId().' cannot be read.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testNeedsAnOperationThatTakesExcludedEmails(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--lead'                   => true,
            '--exclude-segment-emails' => null,
        ]);

        $this->assertStringContainsString('--exclude-segment-emails needs --email-stats, --email-stats-tokens or --page-hits.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public function testRejectsAFieldNameWithOtherCharacters(): void
    {
        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--email-stats'            => true,
            '--exclude-segment-emails' => 'plugin_email_clicked,email clicked',
        ]);

        $this->assertStringContainsString('--exclude-segment-emails takes filter field names made of letters, digits and underscores, separated by commas.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Builds a segment filter the way the segment form saves it, with its value in the properties.
     *
     * @param string $field    the filter field
     * @param mixed  $value    the filter value
     * @param string $operator the filter operator
     *
     * @return array<string, mixed> the filter
     */
    private function filter(string $field, mixed $value, string $operator = 'in'): array
    {
        return [
            'glue'       => 'and',
            'field'      => $field,
            'object'     => 'behaviors',
            'type'       => $field,
            'operator'   => $operator,
            'properties' => ['filter' => $value],
        ];
    }

    /**
     * Counts the emails sent to a segment.
     *
     * @param int|null $segmentId the segment to count
     *
     * @return int the number of segment links
     */
    private function segmentLinkCount(?int $segmentId): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM '.MAUTIC_TABLE_PREFIX.EventLogCleanup::EMAIL_LIST_XREF.' WHERE leadlist_id = ?',
            [(int) $segmentId]
        );
    }

    /**
     * Drops the archive table for segment links, so each test starts and ends without it.
     */
    private function dropArchiveTable(): void
    {
        $this->em->getConnection()->executeStatement('DROP TABLE IF EXISTS '.MAUTIC_TABLE_PREFIX.EventLogCleanup::ARCHIVE_PREFIX.EventLogCleanup::EMAIL_LIST_XREF);
    }
}
