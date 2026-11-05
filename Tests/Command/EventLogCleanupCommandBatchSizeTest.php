<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Command;

use MauticPlugin\LeuchtfeuerHousekeepingBundle\Command\EventLogCleanupCommand;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class EventLogCleanupCommandBatchSizeTest extends TestCase
{
    public function testPassesTheBatchSizeToTheCleanup(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->with(30, null, false, $this->anything(), $this->isInstanceOf(OutputInterface::class), [1, 44], 5000)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--days-old'       => 30,
            '--page-hits'      => true,
            '--exclude-emails' => '1,44',
            '--batch-size'     => '5000',
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testLeavesTheBatchSizeOffWhenNotGiven(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->with(30, null, false, $this->anything(), $this->isInstanceOf(OutputInterface::class), null, null)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--days-old'  => 30,
            '--page-hits' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }
}
