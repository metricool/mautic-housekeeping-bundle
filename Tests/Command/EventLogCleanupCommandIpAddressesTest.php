<?php

declare(strict_types=1);

namespace MauticPlugin\LeuchtfeuerHousekeepingBundle\Tests\Command;

use MauticPlugin\LeuchtfeuerHousekeepingBundle\Command\EventLogCleanupCommand;
use MauticPlugin\LeuchtfeuerHousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class EventLogCleanupCommandIpAddressesTest extends TestCase
{
    /**
     * Passes --ip-addresses as its own operation and --ip-slice as N and M.
     */
    public function testPassesIpAddressesAndTheSliceToTheCleanup(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->once())
            ->method('setIpSlice')
            ->with(5, 31);
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->with(365, null, false, $this->identicalTo(self::operations(false, true)), $this->isInstanceOf(OutputInterface::class), null, 1000, null, false)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--ip-addresses' => true,
            '--ip-slice'     => '05/31',
            '--batch-size'   => '1000',
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Checks every ID when no slice is given.
     */
    public function testChecksEveryIdWithoutASlice(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->once())
            ->method('setIpSlice')
            ->with(1, 1);
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--ip-addresses' => true,
            '--batch-size'   => '1000',
        ]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Stops without a cleanup when --ip-slice is not N/M with N from 1 to M and M up to 1000.
     *
     * @dataProvider invalidSliceProvider
     *
     * @param string $slice the value given to --ip-slice
     */
    public function testRejectsAnIpSliceThatIsNotNOfM(string $slice): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('setIpSlice');
        $eventLogCleanup->expects($this->never())
            ->method('deleteEventLogEntries');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--ip-addresses' => true,
            '--ip-slice'     => $slice,
            '--batch-size'   => '1000',
        ]);

        $this->assertStringContainsString('--ip-slice must be N/M, two whole numbers with N from 1 to M and M from 1 to 1000, for example 5/31.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Lists values of --ip-slice that are not N/M.
     *
     * @return \Generator<string, array{0: string}> the cases
     */
    public static function invalidSliceProvider(): \Generator
    {
        yield 'slice 0' => ['0/31'];

        yield 'slice past the count' => ['32/31'];

        yield 'no slice count' => ['5'];

        yield 'text' => ['a/b'];

        yield 'no slices' => ['5/0'];

        yield 'negative' => ['-1/3'];

        yield 'too many slices' => ['1/1001'];

        yield 'decimal' => ['1.5/31'];

        yield 'three parts' => ['5/31/2'];

        yield 'empty' => [''];
    }

    /**
     * Stops without a cleanup when --ip-slice comes without --ip-addresses.
     */
    public function testRejectsAnIpSliceWithoutIpAddresses(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('deleteEventLogEntries');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([
            '--page-hits'  => true,
            '--ip-slice'   => '5/31',
            '--batch-size' => '1000',
        ]);

        $this->assertStringContainsString('--ip-slice needs --ip-addresses.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Stops without a cleanup when --ip-addresses comes without --batch-size, on a dry run too.
     *
     * @dataProvider withoutBatchSizeProvider
     *
     * @param array<string, bool> $options the options given with --ip-addresses
     */
    public function testRejectsIpAddressesWithoutABatchSize(array $options): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('deleteEventLogEntries');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute(['--ip-addresses' => true] + $options);

        $this->assertStringContainsString('--ip-addresses needs --batch-size.', $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Lists a real run and a dry run, both without --batch-size.
     *
     * @return \Generator<string, array{0: array<string, bool>}> the cases
     */
    public static function withoutBatchSizeProvider(): \Generator
    {
        yield 'real run' => [[]];

        yield 'dry run' => [['--dry-run' => true]];
    }

    /**
     * Leaves the ip_addresses cleanup out of the run with no flags.
     */
    public function testLeavesIpAddressesOutOfTheDefaultCleanup(): void
    {
        $eventLogCleanup = $this->createMock(EventLogCleanup::class);
        $eventLogCleanup->expects($this->never())
            ->method('setIpSlice');
        $eventLogCleanup->expects($this->once())
            ->method('deleteEventLogEntries')
            ->with(365, null, false, $this->identicalTo(self::operations(true, false)), $this->isInstanceOf(OutputInterface::class), null, null, null, false)
            ->willReturn('done');

        $commandTester = new CommandTester(new EventLogCleanupCommand($eventLogCleanup));
        $commandTester->execute([]);

        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Returns the operations the command passes to the cleanup.
     *
     * @param bool $others      the value of every operation except the tokens and ip_addresses
     * @param bool $ipAddresses the value of the ip_addresses operation
     *
     * @return array<string, bool> the operations in the order the command builds them
     */
    private static function operations(bool $others, bool $ipAddresses): array
    {
        return [
            EventLogCleanup::CAMPAIGN_LEAD_EVENTS => $others,
            EventLogCleanup::LEAD_EVENTS          => $others,
            EventLogCleanup::EMAIL_STATS          => $others,
            EventLogCleanup::EMAIL_STATS_TOKENS   => false,
            EventLogCleanup::PAGE_HITS            => $others,
            EventLogCleanup::IP_ADDRESSES         => $ipAddresses,
        ];
    }
}
