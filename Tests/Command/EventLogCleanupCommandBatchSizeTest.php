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
            ->with(30, null, false, $this->anything(), $this->isInstanceOf(OutputInterface::class), [1, 44], 5000, null, false)
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
            ->with(30, null, false, $this->anything(), $this->isInstanceOf(OutputInterface::class), null, null, null, false)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--days-old'  => 30,
            '--page-hits' => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testPassesTheRowLimitAndTheArchiveToTheCleanup(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->with(30, null, false, $this->anything(), $this->isInstanceOf(OutputInterface::class), null, 5000, 20000, true)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--days-old'   => 30,
            '--page-hits'  => true,
            '--batch-size' => '5000',
            '--max-rows'   => '20000',
            '--archive'    => true,
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * @dataProvider needsBatchSizeProvider
     *
     * @param array<string, string|bool> $options
     */
    public function testRejectsAnOptionThatNeedsABatchSizeWithoutOne(array $options, string $error): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('deleteEventLogEntries');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute(['--page-hits' => true] + $options);

        $this->assertStringContainsString($error, $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public static function needsBatchSizeProvider(): \Generator
    {
        yield 'row limit' => [['--max-rows' => '20000'], '--max-rows needs --batch-size.'];

        yield 'archive' => [['--archive' => true], '--archive needs --batch-size.'];
    }

    /**
     * @dataProvider invalidRowLimitProvider
     */
    public function testRejectsARowLimitThatIsNotAWholeNumberAboveZero(string $maxRows): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('deleteEventLogEntries');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--page-hits'  => true,
            '--batch-size' => '5000',
            '--max-rows'   => $maxRows,
        ]);

        $this->assertStringContainsString('--max-rows must be a whole number greater than 0.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    public static function invalidRowLimitProvider(): \Generator
    {
        yield 'zero' => ['0'];

        yield 'negative' => ['-5'];

        yield 'decimal' => ['1.5'];

        yield 'text' => ['all'];
    }
}
