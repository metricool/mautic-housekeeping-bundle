<?php

declare(strict_types=1);

namespace MauticPlugin\HousekeepingBundle\Tests\Command;

use MauticPlugin\HousekeepingBundle\Command\EventLogCleanupCommand;
use MauticPlugin\HousekeepingBundle\Service\EventLogCleanup;
use PHPUnit\Framework\TestCase;

final class EventLogCleanupCommandNameTest extends TestCase
{
    /**
     * The command answers only to mautic:housekeeping.
     */
    public function testNamesTheCommandMauticHousekeeping(): void
    {
        $command = new EventLogCleanupCommand($this->createMock(EventLogCleanup::class));

        $this->assertSame('mautic:housekeeping', $command->getName());
        $this->assertSame([], $command->getAliases());
    }
}
