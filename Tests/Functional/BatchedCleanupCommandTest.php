<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat as EmailStat;
use Mautic\EmailBundle\Entity\StatDevice;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Fixtures\FixtureHelper;

final class BatchedCleanupCommandTest extends MauticMysqlTestCase
{
    protected $useCleanupRollback = false;

    private FixtureHelper $fixtureHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixtureHelper = new FixtureHelper($this->em);
    }

    public function testPageHitsKeepsExcludedEmailsAcrossBatches(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('batched-hits@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $excludedEmail = $this->fixtureHelper->createEmail('Batched Excluded Email');
        $otherEmail    = $this->fixtureHelper->createEmail('Batched Other Email');

        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $excludedHit = $this->fixtureHelper->createPageHit($contact, $oldDate, $excludedEmail);
        $this->fixtureHelper->createPageHit($contact, $oldDate, $otherEmail);
        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $recentHit = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(10));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'       => 30,
            '--page-hits'      => true,
            '--exclude-emails' => (string) $excludedEmail->getId(),
            '--batch-size'     => 1,
        ]);

        $this->em->clear();

        $hits = $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $hits);

        $hitIds = array_map(static fn (Hit $hit): int => (int) $hit->getId(), $hits);
        $this->assertContains((int) $excludedHit->getId(), $hitIds);
        $this->assertContains((int) $recentHit->getId(), $hitIds);

        $this->assertStringContainsString('3 page_hits', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testStopsAtTheFirstNewerRowAndLeavesLaterOldRowsForALaterRun(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('batched-bound@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $recentHit   = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(10));
        $laterOldHit = $this->fixtureHelper->createPageHit($contact, $oldDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'   => 30,
            '--page-hits'  => true,
            '--batch-size' => 1,
        ]);

        $this->em->clear();

        $hits = $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $hits);

        $hitIds = array_map(static fn (Hit $hit): int => (int) $hit->getId(), $hits);
        $this->assertContains((int) $recentHit->getId(), $hitIds);
        $this->assertContains((int) $laterOldHit->getId(), $hitIds);

        $this->assertStringContainsString('1 page_hits', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testStopsAtTheRowLimitAndLeavesTheRestForTheNextRun(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('batched-limit@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $this->fixtureHelper->createPageHit($contact, $oldDate);
        $leftHit   = $this->fixtureHelper->createPageHit($contact, $oldDate);
        $recentHit = $this->fixtureHelper->createPageHit($contact, $this->fixtureHelper->daysAgo(10));

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'   => 30,
            '--page-hits'  => true,
            '--batch-size' => 5,
            '--max-rows'   => 2,
        ]);

        $this->em->clear();

        $hits = $this->em->getRepository(Hit::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $hits);

        $hitIds = array_map(static fn (Hit $hit): int => (int) $hit->getId(), $hits);
        $this->assertContains((int) $leftHit->getId(), $hitIds);
        $this->assertContains((int) $recentHit->getId(), $hitIds);

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('2 page_hits', $output);
        $this->assertStringContainsString('Stopped at the limit of 2 rows for this run.', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testLeadObjectKeepsOtherObjectsAcrossBatches(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('batched-objects@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $importLog = $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'import');
        $this->fixtureHelper->createLeadEventLog($contact, $oldDate, 'lead', 'segment');
        $recentLog = $this->fixtureHelper->createLeadEventLog($contact, $this->fixtureHelper->daysAgo(10), 'lead', 'segment');

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'    => 30,
            '--lead-object' => 'segment',
            '--batch-size'  => 1,
        ]);

        $this->em->clear();

        $logs = $this->em->getRepository(LeadEventLog::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $logs);

        $logIds = array_map(static fn (LeadEventLog $log): int => (int) $log->getId(), $logs);
        $this->assertContains((int) $importLog->getId(), $logIds);
        $this->assertContains((int) $recentLog->getId(), $logIds);

        $this->assertStringContainsString('2 lead_event_log', $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testEmailStatsKeepsExcludedAndOpenEmailsAcrossBatches(): void
    {
        $this->fixtureHelper->enablePlugin();

        $contact = $this->fixtureHelper->createContact('batched-stats@test.com');
        $oldDate = $this->fixtureHelper->daysAgo(400);

        $excludedEmail = $this->fixtureHelper->createEmail('Batched Excluded Unpublished Email', false);
        $closedEmail   = $this->fixtureHelper->createEmail('Batched Closed Email', false);
        $expiredEmail  = $this->fixtureHelper->createEmail('Batched Expired Email', true, $oldDate);
        $openEmail     = $this->fixtureHelper->createEmail('Batched Open Email');

        $excludedStat = $this->fixtureHelper->createEmailStat($contact, $excludedEmail, $oldDate);
        $closedStat   = $this->fixtureHelper->createEmailStat($contact, $closedEmail, $oldDate);
        $this->fixtureHelper->createEmailStat($contact, $expiredEmail, $oldDate);
        $openStat = $this->fixtureHelper->createEmailStat($contact, $openEmail, $oldDate);

        $this->fixtureHelper->createEmailStatDevice($excludedStat, $oldDate);
        $this->fixtureHelper->createEmailStatDevice($closedStat, $oldDate);

        $commandTester = $this->testSymfonyCommand('leuchtfeuer:housekeeping', [
            '--days-old'       => 30,
            '--email-stats'    => true,
            '--exclude-emails' => (string) $excludedEmail->getId(),
            '--batch-size'     => 1,
        ]);

        $this->em->clear();

        $stats = $this->em->getRepository(EmailStat::class)->findBy(['lead' => $contact->getId()]);
        $this->assertCount(2, $stats);

        $statIds = array_map(static fn (EmailStat $stat): int => (int) $stat->getId(), $stats);
        $this->assertContains((int) $excludedStat->getId(), $statIds);
        $this->assertContains((int) $openStat->getId(), $statIds);

        $this->assertCount(1, $this->em->getRepository(StatDevice::class)->findBy(['stat' => $excludedStat->getId()]));

        $output = $commandTester->getDisplay();
        $this->assertStringContainsString('1 email_stats_devices', $output);
        $this->assertStringContainsString('2 email_stats ', $output);
        $this->assertSame(0, $commandTester->getStatusCode());
    }
}
